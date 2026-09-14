<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 管理范围跨店报表只读汇总各自账套；组成明细随快照返回，但不提供跨店记账入口。 */
final class FinanceManagedReports
{
    public static function read(array $params): array
    {
        FinanceAccess::require('', true);
        $root = FinanceAccess::tenant(); $kind = FinanceReports::kind($params); $stores = [];
        foreach (FinanceManagedStoreScope::stores() as $store) {
            $tenant = (int)$store['tenant_id'];
            $stores[] = self::withinTenant($tenant, static function () use ($tenant, $store, $params, $kind): array {
                $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
                $active = Db::name('finance_opening_book')->where('tenant_id', $tenant)->value('status') === 'active';
                if (!$active) { return $store + ['activation_date' => $activation ?: null, 'available' => false, 'reason' => '该门店财务账套尚未启用']; }
                try {
                    $report = FinanceReportPeriods::read(new FinanceLedger($tenant), $params + ['report' => $kind], true);
                    return $store + ['activation_date' => $activation ?: null, 'available' => true, 'reason' => null] + self::storeReport($report);
                } catch (\DomainException $error) {
                    return $store + ['activation_date' => $activation ?: null, 'available' => false, 'reason' => $error->getMessage()];
                }
            });
        }
        $available = array_values(array_filter($stores, static fn(array $store): bool => $store['available']));
        $states = array_values(array_unique(array_column($available, 'closing_status')));
        $cutoffs = array_filter(array_column($available, 'cutoff'));
        $periodType = FinanceValue::text($params['period_type'] ?? 'month', 12);
        $period = FinanceValue::text($params['period'] ?? $params['month'] ?? date('Y-m'), 12);
        return ['tenant_id' => $root, 'root_tenant_id' => $root, 'scope' => 'managed', 'read_only' => true, 'report' => $kind,
            'period_type' => $periodType, 'period' => $period, 'cutoff' => $cutoffs ? min($cutoffs) : null,
            'stage' => (bool)array_filter($available, static fn(array $store): bool => $store['stage']),
            'closing_status' => count($states) === 1 ? $states[0] : 'mixed', 'verification' => self::verification($available),
            'salary_details_visible' => false, 'generated_at' => date('Y-m-d H:i:s'), 'store_count' => count($stores),
            'available_store_count' => count($available), 'stores' => $stores, 'data' => self::aggregate($kind, $available)];
    }

    private static function storeReport(array $report): array
    {
        return ['requested_start_month' => $report['requested_start_month'], 'requested_end_month' => $report['requested_end_month'],
            'start_month' => $report['start_month'], 'end_month' => $report['end_month'], 'cutoff' => $report['cutoff'], 'stage' => $report['stage'],
            'closing_status' => $report['closing_status'], 'verification' => $report['verification'], 'salary_details_visible' => $report['salary_details_visible'],
            'data' => $report['data'], 'months' => $report['months'],
            'current_followups' => array_map(static fn(array $item): array => array_intersect_key($item, array_flip(['month', 'original_mode', 'summary', 'all_resolved'])), $report['current_followups'])];
    }

    private static function aggregate(string $kind, array $stores): array
    {
        if (in_array($kind, ['customer', 'vendor'], true)) {
            $rows = [];
            foreach ($stores as $store) {
                foreach ($store['data']['categories'] ?? [] as $row) {
                    $category = $row['category'];
                    $rows[$category] ??= ['category' => $category, 'opening' => '0.00', 'new_sources' => '0.00', 'entries_change' => '0.00', 'closing' => '0.00'];
                    foreach (['opening', 'new_sources', 'entries_change', 'closing'] as $field) { $rows[$category][$field] = bcadd($rows[$category][$field], (string)$row[$field], 2); }
                }
            }
            return ['categories' => array_values($rows)];
        }
        $summary = [];
        foreach ($stores as $store) {
            foreach ($store['data']['summary'] ?? [] as $field => $value) {
                if (is_bool($value)) { $summary[$field] = ($summary[$field] ?? false) || $value; }
                elseif ($value === null || (array_key_exists($field, $summary) && $summary[$field] === null)) { $summary[$field] = null; }
                else { $summary[$field] = bcadd($summary[$field] ?? '0.00', (string)$value, 2); }
            }
        }
        return ['summary' => $summary];
    }

    private static function verification(array $stores): array
    {
        $unresolved = (bool)array_filter($stores, static fn(array $store): bool => (bool)($store['verification']['has_unresolved'] ?? false));
        $estimates = (bool)array_filter($stores, static fn(array $store): bool => (bool)($store['verification']['has_estimates'] ?? false));
        return ['has_unresolved' => $unresolved, 'has_estimates' => $estimates, 'status' => $unresolved || $estimates ? 'not_fully_verified' : 'checked'];
    }

    private static function withinTenant(int $tenant, callable $callback): mixed
    {
        $request = request(); $previous = (int)($request->tenantId ?? 0); $request->tenantId = $tenant;
        try { return $callback(); } finally { $request->tenantId = $previous; }
    }
}
