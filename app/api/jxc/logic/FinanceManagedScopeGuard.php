<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 已生成跨店快照只能在其全部门店仍属于当前管理范围时读取。 */
final class FinanceManagedScopeGuard
{
    public static function assertCurrent(array $currentStores, array $snapshot, int $rootTenant): void
    {
        $current = array_fill_keys(array_map(static fn(array $store): int => (int)($store['tenant_id'] ?? 0), $currentStores), true);
        $snapshotStores = array_map(static fn(array $store): int => (int)($store['tenant_id'] ?? 0), $snapshot['stores'] ?? []);
        if ((int)($snapshot['root_tenant_id'] ?? 0) !== $rootTenant || !$snapshotStores
            || count($snapshotStores) !== count(array_unique($snapshotStores))
            || array_filter($snapshotStores, static fn(int $tenant): bool => $tenant <= 0 || !isset($current[$tenant]))) {
            throw new \DomainException('管理范围已变化，请重新生成报表');
        }
    }
}
