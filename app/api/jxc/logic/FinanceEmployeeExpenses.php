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
        $current = self::current($documentId); $source = $current['source'];
        return ['current_sources' => bccomp($source['balance'], '0', 2) > 0 ? [$source] : []];
    }

    public static function current(int $documentId): array
    {
        $bill = Db::name('finance_employee_expense')->where('tenant_id', FinanceAccess::tenant())->where('document_id', $documentId)->lock(true)->find();
        if (!$bill) { throw new \DomainException('员工垫付不存在或不属于本门店'); }
        $revision = Db::name('finance_employee_expense_revision')->where('tenant_id', FinanceAccess::tenant())->where('bill_id', $bill['id'])->order('id', 'desc')->lock(true)->find();
        $expense = $revision ? FinanceValue::decode($revision['snapshot'])['expense'] : FinanceValue::decode($bill['snapshot']);
        $source = (new FinanceLedger(FinanceAccess::tenant()))->source($bill['source_ref']);
        return ['bill' => $bill, 'expense' => $expense, 'source' => $source, 'expected_revision_id' => (int)($revision['id'] ?? 0), 'reimbursed_amount' => bcsub($expense['amount'], $source['balance'], 2)];
    }

    public static function options(int $employee, array $params): array
    {
        $page = FinanceValue::id($params['page'] ?? 1); $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        $query = Db::name('finance_employee_expense')->where('tenant_id', FinanceAccess::tenant())->whereLike('source_reference', '%' . $keyword . '%');
        if (!empty($params['original_expense_document_id'])) { $query->where('document_id', FinanceValue::id($params['original_expense_document_id'])); }
        else { $query->where('employee_id', $employee); }
        $rows = $query->order('id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray(); $choices = [];
        foreach (array_slice($rows, 0, 20) as $bill) {
            $current = self::current((int)$bill['document_id']);
            $choices[] = ['original_expense_document_id' => (int)$bill['document_id'], 'expected_revision_id' => $current['expected_revision_id'],
                'expense' => $current['expense'], 'reimbursed_amount' => $current['reimbursed_amount'], 'remaining_amount' => $current['source']['balance']];
        }
        return FinanceExpenseCategories::options() + ['bills' => $choices, 'bill_has_more' => count($rows) > 20];
    }

    public static function adjust(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $original = FinanceValue::id($data['original_expense_document_id'] ?? null); $current = self::current($original); $before = $current['expense'];
        $employee = FinanceValue::id($data['subject_id'] ?? null);
        if ($employee !== $before['subject_id'] || FinanceValue::id($data['new_subject_id'] ?? $employee) !== $employee) { throw new \DomainException('员工垫付调整须保留原实际垫付员工，请核对原记录'); }
        if (FinanceValue::id($data['expected_revision_id'] ?? null, true) !== $current['expected_revision_id']) { throw new \DomainException('该垫付已有后续调整，请重新读取'); }
        if (($data['adjustment_verified'] ?? null) !== 1) { throw new \DomainException('请核实原垫付、本次调整和已报销组成'); }
        $amount = FinanceValue::money($data['new_amount'] ?? null, true); $delta = bcsub($amount, $before['amount'], 2);
        if (bccomp($amount, $current['reimbursed_amount'], 2) < 0) { throw new \DomainException('调整后垫付总额不能低于累计有效报销，请先核对原报销记录'); }
        $benefit = FinanceValue::text($data['benefit_month'] ?? null, 7); FinanceValue::date($benefit . '-01');
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        if ($benefit < substr($activation, 0, 7) || $benefit > date('Y-m')) { throw new \DomainException('调整后受益月须为启用后已发生月份'); }
        $month = $ledger->postingMonth(max($benefit . '-01', $activation)); $reverseMonth = $ledger->postingMonth(max($before['posting_month'] . '-01', $activation));
        $lines = FinanceExpenseAdjustments::lines($data['lines'] ?? null, $amount, $before['lines']);
        if (bccomp($delta, '0', 2) === 0 && $benefit === $before['benefit_month'] && $lines === $before['lines']) { throw new \DomainException('费用金额、归属与明细均未变化'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $basis = FinanceValue::text($data['confirmation_basis'] ?? null, 1000);
        $expense = array_replace($before, ['amount' => $amount, 'benefit_month' => $benefit, 'posting_month' => $month, 'lines' => $lines]);
        foreach ([[$before['lines'], $reverseMonth, true], [$lines, $month, false]] as [$items, $posting, $reverse]) {
            foreach ($items as $line) {
                $ledger->add($id, 'expense', $employee, ($reverse ? '-' : '') . $line['amount'], $before['actual_date'], $posting, 'employee_expense_adjustment', $current['bill']['source_ref'], null,
                    $line + ['subject_type' => 'employee', 'original_expense_document_id' => $original, 'benefit_month' => $reverse ? $before['benefit_month'] : $benefit, 'reason' => $reason]);
                if (!$reverse) { Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $line['category_id'])->update(['used_at' => time()]); }
            }
        }
        $date = date('Y-m-d'); $changes = [];
        if (bccomp($delta, '0', 2) !== 0) {
            $ledger->add($id, 'balance', $employee, $delta, $before['actual_date'], $ledger->postingMonth($date), 'employee_obligation_adjustment', $current['bill']['source_ref'], $date,
                ['original_expense_document_id' => $original, 'reason' => $reason]);
            $changes[] = ['reference' => $current['bill']['source_ref'], 'category' => 'reimbursement', 'subject_id' => $employee, 'subject_name' => $before['subject_name'],
                'before' => $current['source']['balance'], 'change' => $delta, 'after' => bcadd($current['source']['balance'], $delta, 2)];
        }
        $result = ['type' => 'employee_expense_adjustment', 'subject_id' => $employee, 'subject_name' => $before['subject_name'], 'original_expense_document_id' => $original,
            'before_expense' => $before, 'expense' => $expense, 'amount_change' => $delta, 'reimbursed_amount' => $current['reimbursed_amount'], 'balance_changes' => $changes,
            'reason' => $reason, 'confirmation_basis' => $basis, 'obligation_date' => $date, 'posting_months' => array_values(array_unique([$reverseMonth, $month])),
            'previous_revision_id' => $current['expected_revision_id'], 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(), 'created_sources' => []];
        $revision = (int)Db::name('finance_employee_expense_revision')->insertGetId(['tenant_id' => $tenant, 'bill_id' => $current['bill']['id'], 'document_id' => $id,
            'previous_revision_id' => $current['expected_revision_id'], 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result + ['revision_id' => $revision];
    }
}
