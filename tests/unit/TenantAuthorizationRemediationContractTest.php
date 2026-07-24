<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

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
