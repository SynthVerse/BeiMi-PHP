<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 季年报逐月读取权威切片；发生额累计，余额只取窗口首尾。 */
final class FinanceReportPeriods
{
    public static function read(FinanceLedger $ledger, array $params, bool $managedRead = false): array
    {
        $kind = $managedRead ? FinanceReports::kind($params) : FinanceReports::authorize($params); $type = FinanceValue::text($params['period_type'] ?? 'month', 12);
        if (!array_key_exists('period_type', $params)) { return FinanceReports::monthly($ledger, $params, $managedRead); }
        $period = FinanceValue::text($params['period'] ?? $params['month'] ?? date('Y-m'), 12);
        if ($type === 'month' && preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period)) { $first = $last = $period; }
        elseif ($type === 'year' && preg_match('/^[0-9]{4}$/D', $period)) { $first = $period . '-01'; $last = $period . '-12'; }
        elseif ($type === 'quarter' && preg_match('/^([0-9]{4})-Q([1-4])$/D', $period, $match)) {
            $first = $match[1] . '-' . sprintf('%02d', ((int)$match[2] - 1) * 3 + 1); $last = $match[1] . '-' . sprintf('%02d', (int)$match[2] * 3);
        } else { throw new \DomainException('请选择有效的月、季度或年度'); }
        FinanceValue::date($first . '-01');
        $activation = substr((string)Db::name('finance_preparation')->where('tenant_id', FinanceAccess::tenant())->value('activation_date'), 0, 7);
        if (!$activation || $last < $activation || $first > date('Y-m')) { throw new \DomainException('报表范围须与启用后至当前月份重叠'); }
        $start = max($first, $activation); $end = min($last, date('Y-m')); $months = [];
        for ($month = $start; $month <= $end; $month = date('Y-m', strtotime($month . '-01 +1 month'))) {
            $months[] = FinanceReports::monthly($ledger, ['report' => $kind, 'month' => $month], $managedRead);
        }
        $verification = ['has_unresolved' => false, 'has_estimates' => false]; $stage = $last > $end; $states = []; $followups = [];
        foreach ($months as $slice) {
            foreach (array_keys($verification) as $key) { $verification[$key] = $verification[$key] || $slice['verification'][$key]; }
            $stage = $stage || $slice['stage']; $states[$slice['closing_status']] = true;
            if (!$slice['stage'] && ($managedRead || FinanceAccess::owner())) {
                $progress = FinancePeriodFollowups::read($slice['month'], $managedRead);
                $followups[] = ['month' => $slice['month'], 'original_mode' => $progress['original_mode'], 'summary' => $progress['summary'], 'all_resolved' => $progress['all_resolved']];
            }
        }
        $verification['status'] = $verification['has_unresolved'] || $verification['has_estimates'] ? 'not_fully_verified' : 'checked';
        return ['tenant_id' => FinanceAccess::tenant(), 'report' => $kind, 'period_type' => $type, 'period' => $period,
            'requested_start_month' => $first, 'requested_end_month' => $last, 'start_month' => $start, 'end_month' => $end, 'cutoff' => end($months)['cutoff'],
            'stage' => $stage, 'closing_status' => count($states) === 1 ? array_key_first($states) : 'mixed', 'verification' => $verification,
            'salary_details_visible' => $months[0]['salary_details_visible'], 'generated_at' => date('Y-m-d H:i:s'),
            'current_followups' => $followups, 'followups_visible' => FinanceAccess::owner(), 'months' => $months, 'data' => self::aggregate($kind, array_column($months, 'data'), $start, $end)];
    }

    private static function sumSummary(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (is_bool($value)) { $result[$key] = ($result[$key] ?? false) || $value; }
                elseif ($value === null || (array_key_exists($key, $result) && $result[$key] === null)) { $result[$key] = null; }
                else { $result[$key] = bcadd($result[$key] ?? '0', $value, 2); }
            }
        }
        return $result;
    }

    private static function concatenate(array $months, string $key): array
    {
        return array_merge(...array_map(static fn(array $month): array => $month[$key] ?? [], $months));
    }

    private static function balanceRows(array $months, string $key, array $identity, array $flows): array
    {
        $rows = [];
        foreach ($months as $index => $month) {
            foreach ($month[$key] ?? [] as $row) {
                $id = FinanceValue::json(array_map(static fn(string $field): mixed => $row[$field], $identity));
                $old = $rows[$id] ?? null; $rows[$id] = $row;
                $rows[$id]['opening'] = $old['opening'] ?? ($index === 0 ? $row['opening'] : '0.00');
                foreach ($flows as $field) { $rows[$id][$field] = bcadd($old[$field] ?? '0', $row[$field], 2); }
            }
        }
        return array_values($rows);
    }

    private static function balances(array $months): array
    {
        return ['categories' => self::balanceRows($months, 'categories', ['category'], ['new_sources', 'entries_change']),
            'subjects' => self::balanceRows($months, 'subjects', ['subject_id', 'category'], ['new_sources', 'entries_change']),
            'sources' => self::balanceRows($months, 'sources', ['reference'], ['new_sources', 'entries_change']), 'entries' => self::concatenate($months, 'entries'),
            'advance_movements' => self::concatenate($months, 'advance_movements')];
    }

    private static function aggregate(string $kind, array $months, string $start, string $end): array
    {
        $first = $months[0]; $last = end($months);
        if (in_array($kind, ['customer', 'vendor'], true)) {
            $result = self::balances($months);
            if ($kind === 'vendor') { $result['pending_arrivals'] = $last['pending_arrivals']; }
            return $result;
        }
        $summary = self::sumSummary(array_column($months, 'summary'));
        if ($kind === 'cash') {
            foreach (['opening_accounts', 'opening_transit'] as $field) { $summary[$field] = $first['summary'][$field]; }
            foreach (['closing_accounts', 'closing_transit'] as $field) { $summary[$field] = $last['summary'][$field]; }
            $result = ['summary' => $summary, 'accounts' => self::balanceRows($months, 'accounts', ['account_id'], ['change']),
                'transit' => ['opening' => $first['transit']['opening'], 'change' => self::sumSummary(array_column($months, 'transit'))['change'], 'closing' => $last['transit']['closing']], 'opening_sources' => $first['opening_sources']];
            foreach (['entries', 'external_money', 'internal_transfers', 'adjustments'] as $key) { $result[$key] = self::concatenate($months, $key); }
            return $result;
        }
        if ($kind === 'expense') {
            $categories = [];
            foreach (self::concatenate($months, 'categories') as $row) {
                $amount = bcadd($categories[$row['key']]['amount'] ?? '0', $row['amount'], 2); $categories[$row['key']] = $row; $categories[$row['key']]['amount'] = $amount;
            }
            return ['summary' => $summary, 'categories' => array_values($categories), 'entries' => self::concatenate($months, 'entries'), 'obligations' => self::balances(array_column($months, 'obligations'))];
        }
        if ($kind === 'inventory') {
            return ['summary' => $summary, 'positions' => $last['positions'], 'shares' => $last['shares'], 'opening_sources' => $first['opening_sources'],
                'cost_effects' => array_values(array_filter($last['cost_effects'], static fn(array $row): bool => $row['posting_month'] >= $start && $row['posting_month'] <= $end)), 'loss_effects' => self::concatenate($months, 'loss_effects')];
        }
        $result = ['summary' => $summary];
        foreach (['entries', 'cost_effects', 'prior_period_entries', 'prior_period_cost_effects'] as $key) { $result[$key] = self::concatenate($months, $key); }
        return $result;
    }
}
