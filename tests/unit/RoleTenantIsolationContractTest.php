<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class RoleTenantIsolationContractTest extends TestCase
{
    public function test_role_mutations_and_reads_are_scoped_to_authoritative_current_tenant(): void
    {
        $logic = self::source('app/tenantapi/logic/auth/RoleLogic.php');

        foreach ([
            "TenantSystemRole::where('id', \$roleId)\n                    ->where('tenant_id', \$tenantId)",
            "TenantSystemRole::where('id', \$id)\n            ->where('tenant_id', \$tenantId)",
            "TenantSystemRole::where('tenant_id', \$tenantId)->order",
            "TenantSystemMenu::where('tenant_id', \$tenantId)",
            "request()->adminInfo",
        ] as $required) {
            self::assertStringContainsString($required, $logic);
        }
        self::assertMatchesRegularExpression(
            '#TenantSystemRole::where\\(\'id\', \\$id\\)\\s*->where\\(\'tenant_id\', \\$tenantId\\)\\s*->lock\\(true\\)\\s*->findOrEmpty\\(\\);[\\s\\S]*?\\$role->delete\\(\\);#',
            $logic
        );
        self::assertStringContainsString("TenantSystemRoleMenu::where(['role_id' => \$roleId])->delete()", $logic);
        self::assertStringContainsString("if (\$scopedIds !== \$menuIds)", $logic);
    }

    public function test_validator_and_list_do_not_trust_cross_tenant_role_or_menu_ids(): void
    {
        $validate = self::source('app/tenantapi/validate/auth/RoleValidate.php');
        $lists = self::source('app/tenantapi/lists/auth/RoleLists.php');
        $controller = self::source('app/tenantapi/controller/auth/RoleController.php');

        self::assertStringContainsString("TenantSystemRole::where('id', \$value)->where('tenant_id', \$this->currentTenantId())", $validate);
        self::assertStringContainsString("TenantSystemMenu::where('tenant_id', \$this->currentTenantId())", $validate);
        self::assertStringContainsString("TenantSystemRole::where('tenant_id', \$tenantId)->with", $lists);
        self::assertStringContainsString("TenantSystemRole::where('tenant_id', (int)(\$this->adminInfo['tenant_id'] ?? 0))->count()", $lists);
        self::assertStringContainsString('if (RoleLogic::delete($params[\'id\']))', $controller);
    }

    public function test_same_tenant_nonempty_menu_flow_remains_representable_without_cross_tenant_input(): void
    {
        $logic = self::source('app/tenantapi/logic/auth/RoleLogic.php');
        self::assertStringContainsString("'tenant_id' => \$tenantId", $logic);
        self::assertStringContainsString('self::tenantMenuIds($tenantId, $params[\'menu_id\'] ?? [])', $logic);
        self::assertStringContainsString('self::roleMenuRows($roleId, $scopedMenuIds)', $logic);
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);
        return $source;
    }
}
