<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 未来规则追加版本；历史月份及其费用、付款不随计划调整改变。 */
final class FinanceRecurringPlanChanges
{
    public static function project(array $plan, int $id): array
    {
        $months = self::months($plan['service_start'], $plan['service_end'], (int)$plan['interval_months']); $history = [];
        foreach (Db::name('finance_recurring_plan_change')->where('tenant_id', FinanceAccess::tenant())->where('plan_id', $id)->order('version')->select()->toArray() as $row) {
            $change = FinanceValue::decode($row['snapshot']);
            $months = array_values(array_filter($months, static fn(string $month): bool => $month < $row['effective_month']));
            if ($change['change_mode'] === 'revise') { $months = array_merge($months, self::months($row['effective_month'], $change['service_end'], $change['interval_months'])); }
            $plan['version'] = (int)$row['version'];
            $plan['future_rule'] = array_intersect_key($change, array_flip(['change_mode', 'effective_month', 'service_end', 'interval_months']));
            $history[] = ['document_id' => (int)$row['document_id'], 'version' => (int)$row['version'], 'effective_month' => $row['effective_month'], 'change_mode' => $change['change_mode'], 'reason' => $change['reason']];
        }
        return $plan + ['scheduled_months' => $months, 'plan_changes' => $history];
    }

    private static function months(string $start, string $end, int $interval): array
    {
        $months = []; $cursor = new \DateTimeImmutable($start . '-01');
        while (($month = $cursor->format('Y-m')) <= $end) { $months[] = $month; $cursor = $cursor->modify('+' . $interval . ' months'); }
        return $months;
    }

    public static function confirm(array $document, array $input): array
    {
        FinanceAccess::require('', true);
        $plan = FinanceRecurringExpenses::plan(FinanceValue::id($input['recurring_plan_id'] ?? null));
        if (FinanceValue::id($input['subject_id'] ?? null) !== $plan['subject_id'] || FinanceValue::id($input['expected_plan_version'] ?? null) !== $plan['version']) { throw new \DomainException('计划对象或版本已变化，请读取最新计划后核对'); }
        $mode = $input['change_mode'] ?? null;
        if (!in_array($mode, ['revise', 'stop'], true) || ($input['plan_verified'] ?? null) !== 1) { throw new \DomainException('请明确核实未来计划修改或终止'); }
        $effective = FinanceValue::text($input['effective_month'] ?? null, 7); FinanceValue::date($effective . '-01');
        if ($effective <= date('Y-m')) { throw new \DomainException('计划规则只能从未来月份改变；已发生月份须逐月核实或关联更正'); }
        $end = $mode === 'revise' ? FinanceValue::text($input['service_end'] ?? null, 7) : $effective;
        $interval = $mode === 'revise' ? FinanceValue::id($input['interval_months'] ?? null) : 1;
        FinanceValue::date($end . '-01');
        $distance = ((int)substr($end, 0, 4) - (int)substr($effective, 0, 4)) * 12 + (int)substr($end, 5, 2) - (int)substr($effective, 5, 2);
        if ($distance < 0 || $distance >= 120 || $interval > 120) { throw new \DomainException('新规则有效期最多120个月，间隔一至120个月'); }
        $result = ['type' => 'expense_recurring_plan_change', 'subject_id' => $plan['subject_id'], 'subject_name' => $plan['subject_name'], 'recurring_plan_id' => $plan['plan_id'],
            'source_reference' => $plan['source_reference'], 'category_name' => $plan['category_name'], 'change_mode' => $mode, 'effective_month' => $effective,
            'service_end' => $end, 'interval_months' => $interval, 'previous_version' => $plan['version'], 'version' => $plan['version'] + 1,
            'reason' => FinanceValue::text($input['reason'] ?? null, 1000), 'previous_scheduled_months' => $plan['scheduled_months'],
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(), 'created_sources' => []];
        Db::name('finance_recurring_plan_change')->insert(['tenant_id' => FinanceAccess::tenant(), 'plan_id' => $plan['plan_id'], 'document_id' => $document['id'],
            'version' => $result['version'], 'effective_month' => $effective, 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result + ['scheduled_months' => FinanceRecurringExpenses::plan($plan['plan_id'])['scheduled_months']];
    }
}
