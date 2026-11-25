<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 普通费用可以同时记入已发生的付款，待付费用仍由原来源核销。 */
final class FinanceExpenses
{
    public static function confirm(FinanceLedger $ledger, array $document, array $data, ?array $originalTransaction = null): array
    {
        $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $deferred = $document['type'] === 'deferred_expense';
        $paymentState = $data['payment_state'] ?? 'unpaid';
        if (!in_array($paymentState, ['paid', 'unpaid'], true) || ($deferred && $paymentState === 'paid')) {
            throw new \DomainException('请选择适用的费用付款状态');
        }
        $paid = $paymentState === 'paid';
        if ($paid) {
            FinanceAccess::require('', true);
            if (!empty($data['recurring_plan_id']) || ($data['amount_status'] ?? 'final') !== 'final') {
                throw new \DomainException('周期计划或暂估费用请按原费用来源办理');
            }
        }
        if ($originalTransaction && !$paid) { throw new \DomainException('已支付费用的关联更正须保留付款事实'); }
        $vendor = $paid ? 0 : FinanceValue::id($data['subject_id'] ?? null);
        $name = $paid ? FinanceValue::text($data['payee_name'] ?? '', 100, false)
            : Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name');
        if ($name === null) { throw new \DomainException('请选择本门店费用收款对象'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $paymentMonth = $ledger->postingMonth($date);
        $amount = FinanceValue::money($data['amount'] ?? null);
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        $details = $deferred ? FinanceDeferredPlans::details($data, $amount, $activation) : null; $benefit = null;
        if (!$deferred) {
            $benefit = FinanceValue::text($data['benefit_month'] ?? null, 7); FinanceValue::date($benefit . '-01');
            if ($benefit < substr($activation, 0, 7) || $benefit > date('Y-m')) { throw new \DomainException('普通费用归属须为启用后已发生的服务月份，未来受益请使用待摊费用'); }
        }
        $month = $ledger->postingMonth($deferred ? $date : max($benefit . '-01', $activation));
        $reference = FinanceValue::text($data['source_reference'] ?? '', 160, !$paid);
        if (!$paid && Db::name('finance_expense_bill')->where('tenant_id', $tenant)->where('vendor_id', $vendor)->where('source_reference', $reference)->lock(true)->find()) {
            throw new \DomainException('该收款对象的费用来源已登记，请打开原费用关联调整');
        }
        $dueMode = $paid ? null : ($data['due_mode'] ?? null);
        $due = $paid ? null : FinanceValue::date($data['due_date'] ?? null, true);
        if (!$paid && (!in_array($dueMode, ['date', 'unspecified'], true) || ($dueMode === 'date') !== ($due !== null) || ($due && $due < $date))) {
            throw new \DomainException('请填写不早于发生日的付款日，或明确标记未约定');
        }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $material = self::material($data);
        $lines = FinanceExpenseCategories::lines($data['lines'] ?? null, $amount);
        if ($deferred && !empty($data['recurring_plan_id'])) { throw new \DomainException('周期月份须确认为已受益费用或有依据暂估，不能改为未来待摊'); }
        $recurring = !$deferred ? FinanceRecurringExpenses::expenseContext($data, $lines) : null;
        $snapshot = ['type' => $document['type'], 'subject_id' => $vendor, 'subject_name' => $name, 'amount' => $amount,
            'actual_date' => $date, 'benefit_month' => $benefit, 'posting_month' => $month, 'due_mode' => $dueMode, 'due_date' => $due,
            'source_reference' => $reference, 'reason' => $reason, 'lines' => $lines, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()] + $material;
        if ($deferred) { $snapshot += ['details' => $details, 'plan_verified' => 1]; }
        else { $snapshot += FinanceExpenseEstimates::declaration($data); }
        if ($recurring) { $snapshot += ['recurring_plan_id' => $recurring['plan_id'], 'recurring_plan_document_id' => $recurring['document_id'], 'recurring_plan_reference' => $recurring['source_reference'], 'recurring_plan_version' => $recurring['version']]; }
        $source = $paid ? '' : $ledger->createSource($id, 'expense_payable', $vendor, $amount, $date, $due, $snapshot);
        foreach ($lines as $line) {
            if (!$deferred) { $ledger->add($id, 'expense', $vendor, $line['amount'], $date, $month, 'ordinary_expense', $source, null,
                $line + ['benefit_month' => $benefit, 'material_status' => $material['material_status']]); }
            Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $line['category_id'])->update(['used_at' => time()]);
        }
        if ($paid) {
            $money = (new FinanceMoney($tenant, $ledger))->record($id, 'expense', $data, 'out', $date, $amount, $paymentMonth, $originalTransaction);
            return $snapshot + ['payment_state' => 'paid', 'created_sources' => [], 'money' => $money];
        }
        $bill = (int)Db::name('finance_expense_bill')->insertGetId(['tenant_id' => $tenant, 'document_id' => $id, 'vendor_id' => $vendor,
            'source_reference' => $reference, 'source_ref' => $source, 'amount' => $amount, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        FinanceExpenseIdentities::claim($vendor, $reference, $bill, $id);
        if ($recurring) { FinanceRecurringExpenses::complete($document, $recurring, $snapshot['amount_status'] === 'estimated' ? 'estimated' : 'expense', $snapshot); }
        $result = $snapshot + ['bill_id' => $bill, 'created_sources' => [$source]];
        if ($deferred) {
            $result['deferred_source'] = $ledger->createSource($id, 'deferred', $vendor, $amount, $date, null, $snapshot + ['payable_source' => $source]);
            $result['created_sources'][] = $result['deferred_source'];
        }
        return $result;
    }

    public static function material(array $data, string $documentType = 'expense'): array
    {
        $status = $data['material_status'] ?? null;
        if ($status === 'missing') {
            if (($data['material_verified'] ?? null) !== 1 || !empty($data['evidence_ids'])) { throw new \DomainException('无凭证费用须明确人工核实，不能同时登记已有材料'); }
            return ['material_status' => $status, 'missing_material_reason' => FinanceValue::text($data['missing_material_reason'] ?? null, 1000),
                'material_verified' => 1, 'evidence' => []];
        }
        $input = $data['evidence_ids'] ?? null;
        if ($status !== 'provided' || !is_array($input) || !array_is_list($input) || !$input || count($input) > 10) { throw new \DomainException('请选择一至十份内部核验材料，或明确无凭证原因'); }
        $evidence = []; $seen = [];
        foreach ($input as $value) {
            $id = FinanceValue::id($value); $row = Db::name('finance_evidence')->where('tenant_id', FinanceAccess::tenant())->where('id', $id)->where('document_type', $documentType)->find();
            if (!$row || isset($seen[$id])) { throw new \DomainException('核验材料不存在、重复或不属于本门店本类费用'); }
            $seen[$id] = true; $evidence[] = FinanceEvidence::metadata($row);
        }
        return ['material_status' => $status, 'missing_material_reason' => '', 'material_verified' => 0, 'evidence' => $evidence];
    }
}
