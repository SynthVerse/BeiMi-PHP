<?php

declare(strict_types=1);

namespace tests\unit;

use app\tenantapi\logic\auth\MenuLogic;
use PHPUnit\Framework\TestCase;

final class TenantMenuTenantIsolationContractTest extends TestCase
{
    public function test_menu_lookup_contract_is_a_static_single_admin_entry_point(): void
    {
        $method = new \ReflectionMethod(MenuLogic::class, 'getMenuByAdminId');

        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
        self::assertCount(1, $method->getParameters());
        self::assertSame('adminId', $method->getParameters()[0]->getName());
    }

    public function test_non_root_roles_must_be_complete_and_tenant_local_before_their_menus_are_used(): void
    {
        $method = self::methodBody();

        self::assertStringContainsString("TenantAdminRole::where('admin_id', \$admin['id'])->column('role_id')", $method);
        self::assertStringContainsString("TenantSystemRole::where('tenant_id', \$tenantId)", $method);
        self::assertStringContainsString("->whereIn('id', \$roleIds)", $method);
        self::assertStringContainsString("sort(\$roleIds, SORT_NUMERIC)", $method);
        self::assertStringContainsString("sort(\$tenantRoleIds, SORT_NUMERIC)", $method);
        self::assertMatchesRegularExpression(
            '#if \(\$roleIds !== \$tenantRoleIds\) \{\s*return \[\];\s*\}#',
            $method
        );
        self::assertStringContainsString("TenantSystemRoleMenu::whereIn('role_id', \$roleIds)->column('menu_id')", $method);
        self::assertStringNotContainsString('$admin->roles', $method);
        self::assertStringNotContainsString('$admin->role', $method);
    }

    public function test_root_and_non_root_menu_queries_always_include_the_admin_tenant(): void
    {
        $method = self::methodBody();

        self::assertStringContainsString("TenantAdmin::field('id,tenant_id,root')->findOrEmpty(\$adminId)", $method);
        self::assertStringContainsString("\$where[] = ['tenant_id', '=', \$tenantId]", $method);
        self::assertMatchesRegularExpression(
            '#\$where\[\] = \[\'tenant_id\', \'=\', \$tenantId\];\s*if \(\$admin\[\'root\'\] != 1\)#',
            $method
        );
        self::assertStringContainsString('TenantSystemMenu::where($where)', $method);
    }

    public function test_same_tenant_non_root_path_remains_representable(): void
    {
        $method = self::methodBody();

        self::assertStringContainsString("if (\$roleIds === []) {\n                return [];", $method);
        self::assertStringContainsString("\$where[] = ['id', 'in', \$roleMenu]", $method);
        self::assertStringContainsString("->order(['sort' => 'desc', 'id' => 'asc'])", $method);
        self::assertStringContainsString('return linear_to_tree($menu, \'children\');', $method);
    }

    private static function methodBody(): string
    {
        $source = file_get_contents('app/tenantapi/logic/auth/MenuLogic.php');
        self::assertNotFalse($source);

        $start = strpos($source, 'function getMenuByAdminId');
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

        self::fail('MenuLogic::getMenuByAdminId is not closed.');
    }
}
