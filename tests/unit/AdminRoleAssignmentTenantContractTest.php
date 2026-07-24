<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class AdminRoleAssignmentTenantContractTest extends TestCase
{
    public function test_add_uses_authoritative_tenant_and_validates_roles_before_creating_admin(): void
    {
        $add = self::methodBody(self::source('app/tenantapi/logic/auth/AdminLogic.php'), 'add');

        self::assertStringContainsString('$tenantId = self::currentTenantId()', $add);
        self::assertStringContainsString('$roleIds = self::tenantRoleIds($params[\'role_id\'] ?? [], $tenantId)', $add);
        self::assertStringContainsString("'tenant_id' => \$tenantId", $add);
        self::assertLessThan(strpos($add, 'TenantAdmin::create(['), strpos($add, '$roleIds = self::tenantRoleIds'));
    }

    public function test_edit_scopes_target_and_validates_roles_before_replacing_associations_then_completes_after_commit(): void
    {
        $edit = self::methodBody(self::source('app/tenantapi/logic/auth/AdminLogic.php'), 'edit');

        self::assertStringContainsString("->where('tenant_id', \$tenantId)", $edit);
        self::assertStringContainsString('->lock(true)', $edit);
        self::assertLessThan(strpos($edit, 'TenantAdminRole::delByUserId'), strpos($edit, '$roleIds = self::tenantRoleIds'));
        self::assertLessThan(
            strpos($edit, 'TenantSessionAuthorityService::clearAuthorizationCache'),
            strpos($edit, 'Db::commit()'),
        );
        self::assertStringContainsString('TenantSessionAuthorityService::clearTokenCaches($expiredTokens)', $edit);
        self::assertStringNotContainsString("self::setError('权限缓存失效失败，请重试')", $edit);
    }

    public function test_validator_requires_unique_positive_tenant_local_role_ids(): void
    {
        $validate = self::source('app/tenantapi/validate/auth/AdminValidate.php');

        self::assertStringContainsString("'role_id' => 'require|checkRole'", $validate);
        self::assertStringContainsString('!is_int($roleId) || $roleId <= 0 || isset($roleIds[$roleId])', $validate);
        self::assertStringContainsString("TenantSystemRole::where('tenant_id', \$tenantId)", $validate);
        self::assertStringContainsString("->where('tenant_id', \$this->currentTenantId())", $validate);
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
