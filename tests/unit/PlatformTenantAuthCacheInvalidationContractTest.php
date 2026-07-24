<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class PlatformTenantAuthCacheInvalidationContractTest extends TestCase
{
    public function test_tenant_admin_mutations_clear_the_target_tenant_cache(): void
    {
        $source = self::source('app/platformapi/logic/tenant/TenantAdminLogic.php');
        $edit = self::methodBody($source, 'edit');
        $delete = self::methodBody($source, 'delete');

        self::assertStringContainsString(
            "\$tenantId = (int)TenantAdmin::where('id', \$params['id'])->value('tenant_id');",
            $edit
        );
        self::assertStringContainsString(
            'TenantSessionAuthorityService::clearAuthorizationCache((int)$params[\'id\'], $tenantId)',
            $edit
        );
        self::assertStringContainsString(
            "TenantSessionAuthorityService::clearAuthorizationCache((int)\$params['id'], (int)\$admin['tenant_id'])",
            $delete
        );
        self::assertStringContainsString('TenantSessionAuthorityService::expireAdminSessions', $edit . $delete);
    }

    public function test_tenant_lifecycle_mutations_clear_each_admin_cache_in_that_tenant(): void
    {
        $source = self::source('app/platformapi/logic/tenant/TenantLogic.php');

        foreach ([self::methodBody($source, 'delete'), self::methodBody($source, 'restore')] as $method) {
            self::assertStringContainsString(
                'TenantSessionAuthorityService::clearAuthorizationCache((int)$adminId, (int)$params[\'id\'])',
                $method
            );
            self::assertStringContainsString('TenantSessionAuthorityService::clearTokenCaches($tokens)', $method);
        }
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
