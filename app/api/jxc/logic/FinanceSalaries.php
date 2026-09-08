<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 接收系统外最终工资结果，按受益月计费用；发放独立核销工资待付。 */
final class FinanceSalaries
{
    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('finance.salary.view'); FinanceAccess::require('', true);
        $tenant = FinanceAccess::tenant(); $employee = FinanceValue::id($data['subject_id'] ?? null);
        $name = Db::name('employee')->where('tenant_id', $tenant)->where('id', $employee)->value('name');
        if ($name === null) { throw new \DomainException('请选择本门店工资所属员工'); }
        if (($data['salary_verified'] ?? null) !== 1 || ($data['amount_status'] ?? 'final') !== 'final') { throw new \DomainException('请核实系统外已确定的最终工资结果，不支持暂估工资'); }
        $benefit = FinanceValue::text($data['benefit_month'] ?? null, 7); FinanceValue::date($benefit . '-01');
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        if ($benefit < substr($activation, 0, 7) || $benefit > date('Y-m')) { throw new \DomainException('工资受益月须为启用后已发生月份，启用前待付请使用期初工资'); }
        $date = max($benefit . '-01', $activation); $month = $ledger->postingMonth($date);
        if (Db::name('finance_salary_result')->where('tenant_id', $tenant)->where('employee_id', $employee)->where('benefit_month', $benefit)->lock(true)->find()) { throw new \DomainException('该员工本月工资已经确认，请读取原工资记录'); }
        $amount = FinanceValue::money($data['amount'] ?? null, true);
        $lines = bccomp($amount, '0', 2) === 0 ? [] : FinanceExpenseCategories::lines($data['lines'] ?? null, $amount);
        if (!$lines && !empty($data['lines'])) { throw new \DomainException('零金额工资不应填写费用金额明细'); }
        foreach ($lines as $line) { if ($line['parent'] !== 'personnel') { throw new \DomainException('工资费用只能归入人员类别'); } }
        $snapshot = ['type' => 'salary_expense', 'subject_id' => $employee, 'subject_name' => $name, 'benefit_month' => $benefit,
            'actual_date' => $date, 'posting_month' => $month, 'amount' => $amount, 'amount_status' => 'final', 'lines' => $lines,
            'source_reference' => FinanceValue::text($data['source_reference'] ?? null, 160), 'reason' => FinanceValue::text($data['reason'] ?? null, 1000),
            'confirmation_basis' => FinanceValue::text($data['confirmation_basis'] ?? null, 1000), 'salary_verified' => 1,
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()] + FinanceExpenses::material($data, 'salary_expense');
        $source = bccomp($amount, '0', 2) > 0 ? $ledger->createSource((int)$document['id'], 'salary', $employee, $amount, $date, null, $snapshot) : null;
        foreach ($lines as $line) {
            $ledger->add((int)$document['id'], 'expense', $employee, $line['amount'], $date, $month, 'salary_expense', $source, null,
                $line + ['subject_type' => 'employee', 'benefit_month' => $benefit, 'salary_private' => true]);
            Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $line['category_id'])->update(['used_at' => time()]);
        }
        $id = (int)Db::name('finance_salary_result')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'], 'employee_id' => $employee,
            'benefit_month' => $benefit, 'source_ref' => $source, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot + ['salary_result_id' => $id, 'created_sources' => $source ? [$source] : []];
    }

    public static function options(array $params): array
    {
        FinanceAccess::require('finance.salary.view');
        if (!empty($params['original_expense_document_id'])) {
            $source = self::current(FinanceValue::id($params['original_expense_document_id']))['source'];
            return ['current_sources' => $source && bccomp($source['balance'], '0', 2) > 0 ? [$source] : []];
        }
        $options = FinanceExpenseCategories::options();
        $options['expense_categories'] = array_values(array_filter($options['expense_categories'], static fn(array $row): bool => $row['parent'] === 'personnel'));
        $options['expense_parents'] = ['personnel' => '人员'];
        return $options;
    }

    private static function current(int $documentId): array
    {
        FinanceAccess::require('finance.salary.view');
        $row = Db::name('finance_salary_result')->where('tenant_id', FinanceAccess::tenant())->where('document_id', $documentId)->lock(true)->find();
        if (!$row) { throw new \DomainException('工资结果不存在或不属于本门店'); }
        $revision = Db::name('finance_salary_revision')->where('tenant_id', FinanceAccess::tenant())->where('salary_result_id', $row['id'])->order('id', 'desc')->lock(true)->find();
        $snapshot = $revision ? FinanceValue::decode($revision['snapshot']) : null;
        $expense = $snapshot ? $snapshot['expense'] : FinanceValue::decode($row['snapshot']);
        $reference = $snapshot ? $snapshot['source_ref'] : $row['source_ref'];
        $source = $reference ? (new FinanceLedger(FinanceAccess::tenant()))->source($reference) : null;
        return ['row' => $row, 'expense' => $expense, 'source' => $source, 'expected_revision_id' => (int)($revision['id'] ?? 0),
            'paid_amount' => bcsub($expense['amount'], $source['balance'] ?? '0', 2)];
    }

    public static function adjustmentOptions(int $employee, array $params): array
    {
        FinanceAccess::require('finance.salary.view'); $page = FinanceValue::id($params['page'] ?? 1);
        $query = Db::name('finance_salary_result')->where('tenant_id', FinanceAccess::tenant());
        if (!empty($params['original_expense_document_id'])) { $query->where('document_id', FinanceValue::id($params['original_expense_document_id'])); }
        else { $query->where('employee_id', $employee)->whereLike('benefit_month', '%' . FinanceValue::text($params['keyword'] ?? '', 7, false) . '%'); }
        $rows = $query->order('id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray(); $bills = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $current = self::current((int)$row['document_id']);
            $bills[] = ['original_expense_document_id' => (int)$row['document_id'], 'expense' => $current['expense'], 'paid_amount' => $current['paid_amount'],
                'expected_revision_id' => $current['expected_revision_id'], 'remaining_amount' => $current['source']['balance'] ?? '0.00'];
        }
        return self::options([]) + ['bills' => $bills, 'bill_has_more' => count($rows) > 20];
    }

    public static function adjust(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('finance.salary.view'); FinanceAccess::require('', true);
        $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $original = FinanceValue::id($data['original_expense_document_id'] ?? null); $current = self::current($original); $before = $current['expense'];
        $employee = FinanceValue::id($data['subject_id'] ?? null);
        if ($employee !== $before['subject_id'] || FinanceValue::id($data['new_subject_id'] ?? $employee) !== $employee
            || ($data['benefit_month'] ?? null) !== $before['benefit_month']) { throw new \DomainException('本次工资金额调整须保留原员工和工资受益月份'); }
        if (FinanceValue::id($data['expected_revision_id'] ?? null, true) !== $current['expected_revision_id']) { throw new \DomainException('工资已有后续调整，请重新读取最新结果'); }
        if (($data['adjustment_verified'] ?? null) !== 1) { throw new \DomainException('请核实原工资、本次最终金额与已发放组成'); }
        $amount = FinanceValue::money($data['new_amount'] ?? null, true);
        if (bccomp($amount, $current['paid_amount'], 2) < 0) { throw new \DomainException('调整后工资不能低于累计有效发放，请先核对原发放记录'); }
        $lines = FinanceExpenseAdjustments::lines($data['lines'] ?? null, $amount, $before['lines'] ?: FinanceValue::decode($current['row']['snapshot'])['lines']);
        foreach ($lines as $line) { if ($line['parent'] !== 'personnel') { throw new \DomainException('工资费用只能归入人员类别'); } }
        $delta = bcsub($amount, $before['amount'], 2);
        if (bccomp($delta, '0', 2) === 0 && $lines === $before['lines']) { throw new \DomainException('工资金额和明细均未变化'); }
        $date = date('Y-m-d'); $posting = $ledger->postingMonth($date);
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        $expenseMonth = $ledger->postingMonth(max($before['benefit_month'] . '-01', $activation));
        $reverseMonth = $ledger->postingMonth(max($before['posting_month'] . '-01', $activation));
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $basis = FinanceValue::text($data['confirmation_basis'] ?? null, 1000);
        $expense = array_replace($before, ['amount' => $amount, 'lines' => $lines, 'posting_month' => $expenseMonth]);
        $source = $current['source']['reference'] ?? null; $created = []; $changes = [];
        if (bccomp($delta, '0', 2) !== 0) {
            if ($source) { $ledger->add($id, 'balance', $employee, $delta, $before['actual_date'], $posting, 'salary_obligation_adjustment', $source, $date, ['original_expense_document_id' => $original, 'reason' => $reason]); }
            else { $source = $ledger->createSource($id, 'salary', $employee, $amount, $date, null, $expense); $created[] = $source; }
            $balance = $current['source']['balance'] ?? '0.00';
            $changes[] = ['reference' => $source, 'category' => 'salary', 'subject_id' => $employee, 'subject_name' => $before['subject_name'],
                'before' => $balance, 'change' => $delta, 'after' => bcadd($balance, $delta, 2)];
        }
        foreach ([[$before['lines'], $reverseMonth, true], [$lines, $expenseMonth, false]] as [$items, $month, $reverse]) {
            foreach ($items as $line) {
                $ledger->add($id, 'expense', $employee, ($reverse ? '-' : '') . $line['amount'], $before['actual_date'], $month, 'salary_adjustment', $source, null,
                    $line + ['subject_type' => 'employee', 'salary_private' => true, 'benefit_month' => $before['benefit_month'], 'original_expense_document_id' => $original, 'reason' => $reason]);
                if (!$reverse) { Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $line['category_id'])->update(['used_at' => time()]); }
            }
        }
        $result = ['type' => 'salary_adjustment', 'subject_id' => $employee, 'subject_name' => $before['subject_name'], 'original_expense_document_id' => $original,
            'before_expense' => $before, 'expense' => $expense, 'paid_amount' => $current['paid_amount'], 'amount_change' => $delta, 'balance_changes' => $changes,
            'reason' => $reason, 'confirmation_basis' => $basis, 'source_ref' => $source, 'obligation_date' => $date,
            'posting_months' => array_values(array_unique([$reverseMonth, $expenseMonth])), 'created_sources' => $created,
            'previous_revision_id' => $current['expected_revision_id'], 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        $revision = (int)Db::name('finance_salary_revision')->insertGetId(['tenant_id' => $tenant, 'salary_result_id' => $current['row']['id'], 'document_id' => $id,
            'previous_revision_id' => $current['expected_revision_id'], 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result + ['revision_id' => $revision];
    }
}
