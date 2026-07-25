<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class TenantAuthorizationRemediationContractTest extends TestCase
{
    public function test_admin_detail_and_delete_scope_the_target_to_the_current_tenant(): void
    {
        $source = self::source('app/tenantapi/logic/auth/AdminLogic.php');
        $detail = self::methodBody($source, 'detail');
        $delete = self::methodBody($source, 'delete');

        self::assertStringContainsString('$tenantId = self::currentTenantId()', $detail);
        self::assertStringContainsString("->where('tenant_id', \$tenantId)", $detail);
        self::assertStringContainsString('$tenantId = self::currentTenantId()', $delete);
        self::assertStringContainsString("->where('tenant_id', \$tenantId)", $delete);
        self::assertStringContainsString('->lock(true)', $delete);
        self::assertStringNotContainsString('TenantAdmin::findOrEmpty($params[\'id\'])', $delete);
    }

    public function test_cache_cleanup_is_post_commit_best_effort_without_business_failure(): void
    {
        $admin = self::source('app/tenantapi/logic/auth/AdminLogic.php');
        $role = self::source('app/tenantapi/logic/auth/RoleLogic.php');
        foreach ([self::methodBody($admin, 'edit'), self::methodBody($admin, 'delete')] as $method) {
            self::assertLessThan(
                strpos($method, 'TenantSessionAuthorityService::clearAuthorizationCache'),
                strpos($method, 'Db::commit()')
            );
            self::assertStringContainsString('TenantSessionAuthorityService::expireAdminSessions', $method);
            self::assertStringContainsString('TenantSessionAuthorityService::clearTokenCaches', $method);
        }
        self::assertSame(2, substr_count($role, 'TenantSessionAuthorityService::clearTenantAuthorizationCache'));
    }

    public function test_historical_cross_tenant_role_rows_are_rejected_when_resolving_permissions(): void
    {
        $source = self::source('app/tenantapi/logic/auth/AuthLogic.php');

        self::assertStringContainsString('self::tenantRoleIdsByAdminId', $source);
        self::assertStringContainsString("TenantSystemRole::where('tenant_id', \$tenantId)", $source);
        self::assertStringContainsString('return $roleIds === $tenantRoleIds ? $roleIds : [];', $source);
        self::assertStringContainsString("->where('tenant_id', \$tenantId)", $source);
    }

    public function test_customer_receivable_hierarchy_and_root_delete_are_tenant_isolated(): void
    {
        self::requireIsolatedDatabase();

        $suffix = bin2hex(random_bytes(8));
        $now = time();
        $request = request();
        $previousTenantId = $request->tenantId ?? null;
        $tenantA = 0;
        $tenantB = 0;
        $groupA = 0;
        $groupB = 0;
        $rootA = 0;
        $rootB = 0;
        $childA = 0;
        $childB = 0;

        try {
            $tenantA = (int)Db::name('tenant')->insertGetId([
                'sn' => 'ta_' . $suffix,
                'name' => 'Tenant A ' . $suffix,
                'disable' => 0,
                'create_time' => $now,
            ]);
            $tenantB = (int)Db::name('tenant')->insertGetId([
                'sn' => 'tb_' . $suffix,
                'name' => 'Tenant B ' . $suffix,
                'disable' => 0,
                'create_time' => $now,
            ]);
            $groupA = (int)Db::name('customer_group')->insertGetId([
                'tenant_id' => $tenantA,
                'group_name' => 'Group A ' . $suffix,
                'customer_count' => 1,
                'sort' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $groupB = (int)Db::name('customer_group')->insertGetId([
                'tenant_id' => $tenantB,
                'group_name' => 'Group B ' . $suffix,
                'customer_count' => 1,
                'sort' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $rootA = (int)Db::name('customer')->insertGetId(self::customerFixture(
                $tenantA,
                $groupA,
                0,
                'Root A ' . $suffix,
                '10.00',
                0,
                $now
            ));
            $rootB = (int)Db::name('customer')->insertGetId(self::customerFixture(
                $tenantB,
                $groupB,
                0,
                'Root B ' . $suffix,
                '30.00',
                0,
                $now
            ));
            $childA = (int)Db::name('customer')->insertGetId(self::customerFixture(
                $tenantA,
                $groupA,
                $rootA,
                'Child A ' . $suffix,
                '5.00',
                1,
                $now
            ));
            $childB = (int)Db::name('customer')->insertGetId(self::customerFixture(
                $tenantB,
                $groupB,
                $rootA,
                'Child B ' . $suffix,
                '70.00',
                1,
                $now
            ));

            $request->tenantId = $tenantA;
            $summary = CustomerLogic::receivableSummary(['page' => 1, 'pagesize' => 20]);
            self::assertSame(1, $summary['total']);
            self::assertSame(1, $summary['customer_count']);
            self::assertSame(1, $summary['store_count']);
            self::assertSame('15.00', $summary['total_amount']);
            self::assertCount(1, $summary['customers']);
            self::assertSame($rootA, $summary['customers'][0]['id']);
            self::assertSame([$childA], array_column($summary['customers'][0]['children'], 'id'));

            $groups = CustomerLogic::groupedCustomers([
                ['id' => $groupA, 'group_name' => 'Group A ' . $suffix],
                ['id' => $groupB, 'group_name' => 'Group B ' . $suffix],
            ], []);
            self::assertCount(1, $groups);
            self::assertSame($groupA, (int)$groups[0]['id']);
            self::assertSame([$rootA], array_column($groups[0]['customers'], 'id'));
            self::assertSame([$childA], array_column($groups[0]['customers'][0]['children'], 'id'));

            $deleted = CustomerLogic::delete(['id' => $rootA]);
            self::assertIsArray($deleted);
            self::assertSame(1, $deleted['affected_store_count']);
            $childAAfter = Db::name('customer')->where('tenant_id', $tenantA)->where('id', $childA)->find();
            $childBAfter = Db::name('customer')->where('tenant_id', $tenantB)->where('id', $childB)->find();
            self::assertSame(0, (int)$childAAfter['parent_id']);
            self::assertSame(0, (int)$childAAfter['is_store']);
            self::assertSame($rootA, (int)$childBAfter['parent_id']);
            self::assertSame(1, (int)$childBAfter['is_store']);

            $request->tenantId = 0;
            $before = Db::name('customer')
                ->whereIn('id', [$rootB, $childB])
                ->order('id')
                ->select()
                ->toArray();
            $emptySummary = CustomerLogic::receivableSummary(['page' => 2, 'pagesize' => 7]);
            self::assertSame([], $emptySummary['customers']);
            self::assertSame(0, $emptySummary['total']);
            self::assertSame('0.00', $emptySummary['total_amount']);
            self::assertSame([], CustomerLogic::groupedCustomers([
                ['id' => $groupB, 'group_name' => 'Group B ' . $suffix],
            ], []));
            self::assertFalse(CustomerLogic::delete(['id' => $rootB]));
            $after = Db::name('customer')
                ->whereIn('id', [$rootB, $childB])
                ->order('id')
                ->select()
                ->toArray();
            self::assertSame($before, $after);
        } finally {
            $request->tenantId = $previousTenantId;
            if ($tenantA > 0) {
                Db::name('customer')->where('tenant_id', $tenantA)->whereIn('id', array_filter([$rootA, $childA]))->delete();
                Db::name('customer_group')->where('tenant_id', $tenantA)->where('id', $groupA)->delete();
                Db::name('tenant')->where('id', $tenantA)->delete();
            }
            if ($tenantB > 0) {
                Db::name('customer')->where('tenant_id', $tenantB)->whereIn('id', array_filter([$rootB, $childB]))->delete();
                Db::name('customer_group')->where('tenant_id', $tenantB)->where('id', $groupB)->delete();
                Db::name('tenant')->where('id', $tenantB)->delete();
            }
        }
    }

    private static function requireIsolatedDatabase(): void
    {
        $default = config('database.default');
        $mysql = config('database.connections.mysql');
        $isolated = $default === 'mysql'
            && is_array($mysql)
            && ($mysql['type'] ?? null) === 'mysql'
            && ($mysql['hostname'] ?? null) === '127.0.0.1'
            && preg_match('/^beimi_full_suite_[a-f0-9]{16,32}$/', (string)($mysql['database'] ?? '')) === 1
            && (int)($mysql['hostport'] ?? 3306) > 0
            && (int)($mysql['hostport'] ?? 3306) !== 3306
            && ($mysql['prefix'] ?? null) === 'la_'
            && is_string($mysql['password'] ?? null)
            && $mysql['password'] !== '';
        if (!$isolated) {
            self::markTestSkipped('Requires the isolated beimi_full_suite database.');
        }
        if (!extension_loaded('pdo_mysql')) {
            self::fail('The isolated behavior gate requires pdo_mysql.');
        }
        if (!function_exists('proc_open')) {
            self::fail('The isolated behavior gate requires proc_open.');
        }
    }

    private static function customerFixture(
        int $tenantId,
        int $groupId,
        int $parentId,
        string $name,
        string $receivable,
        int $isStore,
        int $now
    ): array {
        return [
            'tenant_id' => $tenantId,
            'customer_name' => $name,
            'contact' => '',
            'phone' => '',
            'address' => '',
            'remark' => '',
            'group_id' => $groupId,
            'parent_id' => $parentId,
            'is_store' => $isStore,
            'children_count' => 0,
            'is_disabled' => 0,
            'order_receivable' => $receivable,
            'order_money' => $receivable,
            'order_pay_money' => '0.00',
            'create_time' => $now,
            'update_time' => $now,
        ];
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);
        return $source;
    }

    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method);
        self::assertNotFalse($start);
        $open = strpos($source, '{', $start);
        self::assertNotFalse($open);
        $depth = 0;
        for ($index = $open, $length = strlen($source); $index < $length; $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}' && --$depth === 0) {
                return substr($source, $open, $index - $open + 1);
            }
        }
        self::fail('Method is not closed.');
    }
}
