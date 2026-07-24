<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class TenantSessionAuthorityContractTest extends TestCase
{
    public function test_protected_requests_use_database_session_admin_and_tenant_authority(): void
    {
        $service = self::source('app/common/service/auth/TenantSessionAuthorityService.php');
        $login = self::source('app/tenantapi/http/middleware/LoginMiddleware.php');

        self::assertStringContainsString("TenantAdminSession::where('token', \$token)", $service);
        self::assertStringContainsString("->where('expire_time', '>', time())", $service);
        self::assertStringContainsString("TenantAdmin::where('id', (int)\$session['admin_id'])", $service);
        self::assertStringContainsString("->where('disable', 0)", $service);
        self::assertStringContainsString("Tenant::where('id', (int)\$admin['tenant_id'])", $service);
        self::assertStringContainsString("->where('expired_time', '>', time())", $service);
        self::assertStringContainsString("'login_ip' => (string)\$admin['login_ip']", $service);
        self::assertStringNotContainsString("'login_ip' => request()->ip()", $service);
        self::assertStringContainsString('TenantSessionAuthorityService::resolve((string)$token)', $login);
        self::assertStringNotContainsString('getAdminInfo($token)', $login);
    }

    public function test_session_expiry_is_database_first_and_cache_cleanup_is_best_effort(): void
    {
        $service = self::source('app/common/service/auth/TenantSessionAuthorityService.php');

        self::assertStringContainsString('TenantAdminSession::whereIn(\'admin_id\', $adminIds)->update', $service);
        self::assertStringContainsString("'expire_time' => \$time", $service);
        self::assertStringContainsString('public static function clearTokenCaches(array $tokens): void', $service);
        self::assertStringContainsString('$cache = new TenantAdminTokenCache();', $service);
        self::assertStringContainsString('catch (\\Throwable)', $service);
        self::assertStringContainsString('Post-commit cleanup is intentionally best effort.', $service);
        self::assertLessThan(
            strpos($service, '$cache = new TenantAdminTokenCache();'),
            strpos($service, 'try {', strpos($service, 'public static function clearTokenCaches')),
        );
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);
        return $source;
    }
}
