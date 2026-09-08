<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 员工真实垫付独立确认费用；门店报销只核销该员工的垫付应付。 */
final class FinanceEmployeeExpenses
{
    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $tenant = FinanceAccess::tenant(); $employee = FinanceValue::id($data['subject_id'] ?? null);
        $name = Db::name('employee')->where('tenant_id', $tenant)->where('id', $employee)->value('name');
        if ($name === null) { throw new \DomainException('请选择本门店实际垫付员工'); }
        if (($data['advance_verified'] ?? null) !== 1) { throw new \DomainException('请核实员工已经实际垫付，不能登记借支或备用金'); }
        if (!empty($data['recurring_plan_id']) || ($data['amount_status'] ?? 'final') !== 'final') { throw new \DomainException('员工垫付须核实实际金额，不能登记周期计划或暂估'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $ledger->postingMonth($date);
        $benefit = FinanceValue::text($data['benefit_month'] ?? null, 7); FinanceValue::date($benefit . '-01');
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        if ($benefit < substr($activation, 0, 7) || $benefit > date('Y-m')) { throw new \DomainException('垫付费用归属须为启用后已受益月份'); }
        $month = $ledger->postingMonth(max($benefit . '-01', $activation));
        $amount = FinanceValue::money($data['amount'] ?? null); $reference = FinanceValue::text($data['source_reference'] ?? null, 160);
        if (Db::name('finance_employee_expense')->where('tenant_id', $tenant)->where('employee_id', $employee)->where('source_reference', $reference)->lock(true)->find()) { throw new \DomainException('该员工此来源垫付已经登记，请读取原记录'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $lines = FinanceExpenseCategories::lines($data['lines'] ?? null, $amount);
        $material = FinanceExpenses::material($data, 'employee_expense');
        $snapshot = ['type' => 'employee_expense', 'subject_id' => $employee, 'subject_name' => $name, 'source_reference' => $reference,
            'actual_date' => $date, 'benefit_month' => $benefit, 'posting_month' => $month, 'amount' => $amount, 'amount_status' => 'final',
            'reason' => $reason, 'lines' => $lines, 'advance_verified' => 1, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()] + $material;
        $source = $ledger->createSource((int)$document['id'], 'reimbursement', $employee, $amount, $date, null, $snapshot);
        foreach ($lines as $line) {
            $ledger->add((int)$document['id'], 'expense', $employee, $line['amount'], $date, $month, 'employee_expense', $source, null,
                $line + ['subject_type' => 'employee', 'benefit_month' => $benefit, 'material_status' => $material['material_status']]);
            Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $line['category_id'])->update(['used_at' => time()]);
        }
        $id = (int)Db::name('finance_employee_expense')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'], 'employee_id' => $employee,
            'source_reference' => $reference, 'source_ref' => $source, 'amount' => $amount, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot + ['employee_expense_id' => $id, 'created_sources' => [$source]];
    }

    public static function outstanding(int $documentId): array
    {
        $bill = Db::name('finance_employee_expense')->where('tenant_id', FinanceAccess::tenant())->where('document_id', $documentId)->find();
        if (!$bill) { throw new \DomainException('员工垫付不存在或不属于本门店'); }
        $source = (new FinanceLedger(FinanceAccess::tenant()))->source($bill['source_ref']);
        return ['current_sources' => bccomp($source['balance'], '0', 2) > 0 ? [$source] : []];
    }
}
