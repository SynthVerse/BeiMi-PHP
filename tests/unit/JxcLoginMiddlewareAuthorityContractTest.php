<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class JxcLoginMiddlewareAuthorityContractTest extends TestCase
{
    public function test_jxc_protected_requests_use_database_authority_and_bound_login_ip(): void
    {
        $middleware = self::source('app/api/jxc/middleware/JxcLoginMiddleware.php');

        self::assertStringContainsString('TenantSessionAuthorityService::resolve((string)$token)', $middleware);
        self::assertStringNotContainsString('TenantAdminTokenCache', $middleware);
        self::assertStringNotContainsString('UserTokenCache', $middleware);
        self::assertStringContainsString("(\$adminInfo['login_ip'] ?? '') !== (string)\$request->ip()", $middleware);
        self::assertStringContainsString("Config::get('project.tenant_token.be_expire_duration')", $middleware);
    }

    public function test_token_renewal_re_resolves_database_authority(): void
    {
        $middleware = self::source('app/api/jxc/middleware/JxcLoginMiddleware.php');
        $renewal = strpos($middleware, 'TenantTokenService::overtimeToken($token)');
        $reResolve = strpos($middleware, 'TenantSessionAuthorityService::resolve((string)$token)', $renewal + 1);

        self::assertNotFalse($renewal);
        self::assertNotFalse($reResolve);
        self::assertGreaterThan($renewal, $reResolve);
    }

    public function test_goods_delete_and_return_statistics_routes_remain_protected(): void
    {
        $routes = self::source('app/api/route/jxc.php');
        $middleware = '\\app\\api\\jxc\\middleware\\JxcLoginMiddleware::class';
        $goodsDeleteRoute = "Route::post('goods/del', 'jxc.Goods/delete')";
        $protectedGroupEnd = "})->middleware({$middleware});";

        self::assertSame(1, substr_count($routes, $goodsDeleteRoute));
        self::assertStringContainsString("Route::get('return/statistics', 'jxc.SalesReturnOrder/statistics')", $routes);
        self::assertNotFalse(strpos($routes, $protectedGroupEnd));
        self::assertLessThan(
            strpos($routes, $protectedGroupEnd),
            strpos($routes, $goodsDeleteRoute),
        );
        self::assertLessThan(
            strpos($routes, $protectedGroupEnd),
            strpos($routes, "Route::get('return/statistics', 'jxc.SalesReturnOrder/statistics')"),
        );
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);
        return $source;
    }
}
