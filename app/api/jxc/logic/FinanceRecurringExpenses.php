<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 周期计划只列出应处理月份；逐月人工确认费用、暂估或不发生。 */
final class FinanceRecurringExpenses
{
    public static function month(array $data, array $allowed = ['pending']): array
    {
        $plan = self::plan(FinanceValue::id($data['recurring_plan_id'] ?? null));
        if (FinanceValue::id($data['subject_id'] ?? null) !== $plan['subject_id'] || FinanceValue::id($data['expected_plan_version'] ?? null) !== $plan['version']) { throw new \DomainException('周期计划对象或版本不匹配，请重新读取'); }
        $month = FinanceValue::text($data['benefit_month'] ?? null, 7); $selected = null;
        foreach ($plan['months'] as $row) { if ($row['month'] === $month) { $selected = $row; break; } }
        if (!$selected || !in_array($selected['status'], $allowed, true)) { throw new \DomainException('该月不在计划周期内、尚未发生或已有处理结果，请重新读取'); }
        if (FinanceValue::id($data['expected_month_revision_id'] ?? 0, true) !== $selected['month_revision_id']) { throw new \DomainException('本月已有后续处理，请重新读取月份最新状态'); }
        return $plan + ['benefit_month' => $month, 'month_revision_id' => $selected['month_revision_id'], 'previous_month_document_id' => $selected['document_id']];
    }

    public static function expenseContext(array $data, array $lines): ?array
    {
        if (empty($data['recurring_plan_id'])) { return null; }
        $plan = self::month($data);
        if (count($lines) !== 1 || $lines[0]['category_id'] !== $plan['category_id']) { throw new \DomainException('本期费用须使用原周期计划类别；其他费用请单独登记'); }
        if (($data['source_reference'] ?? null) !== $plan['source_reference'] . '/' . $plan['benefit_month']) { throw new \DomainException('周期费用须使用原计划及月份生成的来源编号'); }
        return $plan;
    }

    public static function complete(array $document, array $plan, string $outcome, array $snapshot): void
    {
        $row = ['tenant_id' => FinanceAccess::tenant(), 'plan_id' => $plan['plan_id'],
            'benefit_month' => $plan['benefit_month'], 'document_id' => $document['id'],
            'snapshot' => FinanceValue::json(['outcome' => $outcome, 'plan_reference' => $plan['source_reference'], 'result' => $snapshot]), 'create_time' => time()];
        $original = Db::name('finance_recurring_expense_month')->where('tenant_id', FinanceAccess::tenant())->where('plan_id', $plan['plan_id'])->where('benefit_month', $plan['benefit_month'])->lock(true)->find();
        if ($original) { Db::name('finance_recurring_month_revision')->insert($row + ['previous_revision_id' => $plan['month_revision_id']]); }
        else { Db::name('finance_recurring_expense_month')->insert($row); }
    }

    public static function correct(array $document, array $data): array
    {
        FinanceAccess::require('', true);
        if (($data['correction_mode'] ?? null) !== 'reopen_none' || ($data['correction_verified'] ?? null) !== 1) { throw new \DomainException('请明确核实原不发生记录确有错误并恢复待核实'); }
        $plan = self::month($data, ['none']); $snapshot = $plan; unset($snapshot['months']);
        $result = ['type' => 'expense_recurring_correct', 'correction_mode' => 'reopen_none', 'subject_id' => $plan['subject_id'], 'subject_name' => $plan['subject_name'],
            'recurring_plan_id' => $plan['plan_id'], 'source_reference' => $plan['source_reference'], 'benefit_month' => $plan['benefit_month'],
            'category_name' => $plan['category_name'], 'plan_snapshot' => $snapshot, 'previous_document_id' => $plan['previous_month_document_id'],
            'previous_month_revision_id' => $plan['month_revision_id'], 'reason' => FinanceValue::text($data['reason'] ?? null, 1000),
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(), 'created_sources' => []];
        self::complete($document, $plan, 'pending', $result);
        return $result;
    }

    public static function confirmNone(array $document, array $data): array
    {
        $plan = self::month($data);
        if (($data['none_verified'] ?? null) !== 1) { throw new \DomainException('请明确核实本期费用确实不发生'); }
        $planSnapshot = $plan; unset($planSnapshot['months']);
        $result = ['type' => 'expense_recurring_none', 'subject_id' => $plan['subject_id'], 'subject_name' => $plan['subject_name'],
            'recurring_plan_id' => $plan['plan_id'], 'source_reference' => $plan['source_reference'], 'benefit_month' => $plan['benefit_month'],
            'category_name' => $plan['category_name'], 'plan_snapshot' => $planSnapshot, 'reason' => FinanceValue::text($data['reason'] ?? null, 1000),
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(), 'created_sources' => []];
        self::complete($document, $plan, 'none', $result);
        return $result;
    }

    public static function confirmPlan(array $document, array $data): array
    {
        $tenant = FinanceAccess::tenant(); $vendor = FinanceValue::id($data['subject_id'] ?? null);
        $name = Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name');
        if ($name === null) { throw new \DomainException('请选择本门店周期费用对象'); }
        $category = Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', FinanceValue::id($data['category_id'] ?? null))->lock(true)->find();
        if (!$category || !(int)$category['is_enabled'] || FinanceValue::id($data['expected_category_version'] ?? null) !== (int)$category['version']) { throw new \DomainException('周期费用类别不存在、已停用或版本变化，请重新选择'); }
        if (($data['plan_verified'] ?? null) !== 1) { throw new \DomainException('请核实周期费用对象、类别、周期及有效期'); }
        $start = FinanceValue::text($data['service_start'] ?? null, 7); $end = FinanceValue::text($data['service_end'] ?? null, 7);
        FinanceValue::date($start . '-01'); FinanceValue::date($end . '-01'); $interval = FinanceValue::id($data['interval_months'] ?? null);
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        $distance = ((int)substr($end, 0, 4) - (int)substr($start, 0, 4)) * 12 + (int)substr($end, 5, 2) - (int)substr($start, 5, 2);
        if ($start < substr($activation, 0, 7) || $distance < 0 || $distance >= 120 || $interval > 120) { throw new \DomainException('周期计划须在财务启用后，有效期最多120个月，间隔一至120个月'); }
        $reference = FinanceValue::text($data['source_reference'] ?? null, 120);
        if (Db::name('finance_recurring_expense_plan')->where('tenant_id', $tenant)->where('source_reference', $reference)->lock(true)->find()) { throw new \DomainException('该周期计划来源编号已登记，请读取原计划'); }
        $snapshot = ['type' => 'expense_recurring_plan', 'subject_id' => $vendor, 'subject_name' => $name, 'source_reference' => $reference,
            'category_id' => (int)$category['id'], 'category_version' => (int)$category['version'], 'category_name' => $category['name'],
            'parent' => $category['parent'], 'parent_name' => FinanceExpenseCategories::PARENTS[$category['parent']],
            'service_start' => $start, 'service_end' => $end, 'interval_months' => $interval, 'version' => 1,
            'reason' => FinanceValue::text($data['reason'] ?? null, 1000), 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        $id = (int)Db::name('finance_recurring_expense_plan')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'], 'vendor_id' => $vendor,
            'source_reference' => $reference, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $category['id'])->update(['used_at' => time()]);
        return $snapshot + ['plan_id' => $id, 'created_sources' => []];
    }

    public static function plan(int $id): array
    {
        $row = Db::name('finance_recurring_expense_plan')->where('tenant_id', FinanceAccess::tenant())->where('id', $id)->lock(true)->find();
        if (!$row) { throw new \DomainException('周期计划不存在或不属于本门店'); }
        $plan = FinanceValue::decode($row['snapshot']); $months = [];
        $done = Db::name('finance_recurring_expense_month')->where('tenant_id', FinanceAccess::tenant())->where('plan_id', $id)->select()->toArray();
        $revisions = Db::name('finance_recurring_month_revision')->where('tenant_id', FinanceAccess::tenant())->where('plan_id', $id)->order('id')->select()->toArray();
        $byRevisionMonth = []; foreach ($revisions as $revision) { $byRevisionMonth[$revision['benefit_month']][] = $revision; }
        $byMonth = array_column($done, null, 'benefit_month'); $cursor = new \DateTimeImmutable($plan['service_start'] . '-01');
        while (($month = $cursor->format('Y-m')) <= $plan['service_end']) {
            $result = isset($byMonth[$month]) ? FinanceValue::decode($byMonth[$month]['snapshot']) : null;
            $currentDocument = (int)($byMonth[$month]['document_id'] ?? 0); $revisionId = 0; $history = [];
            if ($result) { $history[] = ['document_id' => $currentDocument, 'type' => $result['result']['type'], 'outcome' => $result['outcome']]; }
            foreach ($byRevisionMonth[$month] ?? [] as $revision) {
                $result = FinanceValue::decode($revision['snapshot']); $currentDocument = (int)$revision['document_id']; $revisionId = (int)$revision['id'];
                $history[] = ['document_id' => $currentDocument, 'type' => $result['result']['type'], 'outcome' => $result['outcome']];
            }
            $months[] = ['month' => $month, 'status' => $result ? $result['outcome'] : ($month > date('Y-m') ? 'future' : 'pending'),
                'result' => $result, 'document_id' => $currentDocument, 'month_revision_id' => $revisionId, 'history' => $history];
            $cursor = $cursor->modify('+' . $plan['interval_months'] . ' months');
        }
        return $plan + ['plan_id' => $id, 'document_id' => (int)$row['document_id'], 'months' => $months];
    }

    public static function options(int $vendor, array $params): array
    {
        $options = FinanceExpenseCategories::options();
        if (!empty($params['recurring_plan_id'])) { return $options + ['selected_plan' => self::plan(FinanceValue::id($params['recurring_plan_id']))]; }
        $page = FinanceValue::id($params['page'] ?? 1); $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        $query = Db::name('finance_recurring_expense_plan')->where('tenant_id', FinanceAccess::tenant())->whereLike('source_reference', '%' . $keyword . '%');
        if ($vendor) { $query->where('vendor_id', $vendor); }
        $rows = $query->order('id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray();
        return $options + ['plans' => array_map(static fn(array $row): array => self::plan((int)$row['id']), array_slice($rows, 0, 20)), 'plan_has_more' => count($rows) > 20];
    }
}
