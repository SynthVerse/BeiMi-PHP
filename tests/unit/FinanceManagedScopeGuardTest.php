<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceManagedScopeGuard;
use PHPUnit\Framework\TestCase;

final class FinanceManagedScopeGuardTest extends TestCase
{
    public function test_existing_snapshot_remains_valid_when_scope_expands(): void
    {
        FinanceManagedScopeGuard::assertCurrent([['tenant_id' => 1], ['tenant_id' => 2], ['tenant_id' => 3]],
            ['root_tenant_id' => 1, 'stores' => [['tenant_id' => 1], ['tenant_id' => 2]]], 1);
        $this->addToAssertionCount(1);
    }

    public function test_removed_store_invalidates_existing_snapshot(): void
    {
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('管理范围已变化');
        FinanceManagedScopeGuard::assertCurrent([['tenant_id' => 1]],
            ['root_tenant_id' => 1, 'stores' => [['tenant_id' => 1], ['tenant_id' => 2]]], 1);
    }

    public function test_changed_root_or_duplicate_store_invalidates_snapshot(): void
    {
        foreach ([
            ['root_tenant_id' => 2, 'stores' => [['tenant_id' => 1]]],
            ['root_tenant_id' => 1, 'stores' => [['tenant_id' => 1], ['tenant_id' => 1]]],
        ] as $snapshot) {
            try { FinanceManagedScopeGuard::assertCurrent([['tenant_id' => 1]], $snapshot, 1); self::fail('无效管理范围快照不得通过'); }
            catch (\DomainException $error) { self::assertStringContainsString('管理范围已变化', $error->getMessage()); }
        }
    }
}
