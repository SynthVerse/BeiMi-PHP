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
            $row = Db::name('finance_salary_result')->where('tenant_id', FinanceAccess::tenant())->where('document_id', FinanceValue::id($params['original_expense_document_id']))->find();
            if (!$row) { throw new \DomainException('工资结果不存在或不属于本门店'); }
            $source = $row['source_ref'] ? (new FinanceLedger(FinanceAccess::tenant()))->source($row['source_ref']) : null;
            return ['current_sources' => $source && bccomp($source['balance'], '0', 2) > 0 ? [$source] : []];
        }
        $options = FinanceExpenseCategories::options();
        $options['expense_categories'] = array_values(array_filter($options['expense_categories'], static fn(array $row): bool => $row['parent'] === 'personnel'));
        $options['expense_parents'] = ['personnel' => '人员'];
        return $options;
    }
}
