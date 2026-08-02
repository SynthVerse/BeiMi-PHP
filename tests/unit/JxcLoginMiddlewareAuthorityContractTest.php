<?php

declare(strict_types=1);

namespace tests\unit;

use app\common\model\jxc\Goods;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

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

    public function test_automatic_jxc_wrappers_fail_closed_and_explicit_neighbors_stay_routed(): void
    {
        $init = self::source('app/api/http/middleware/InitMiddleware.php');
        self::assertStringContainsString('$rule instanceof RuleItem', $init);
        self::assertStringContainsString('!$rule->isMiss()', $init);
        self::assertStringContainsString("\$rule->getName() !== '__think_auto_route__'", $init);
        self::assertStringContainsString("trim((string)\$rule->getRule()) !== ''", $init);

        $automatic = [
            'jxc.audit/lists',
            'jxc.auth/info',
            'jxc.cloud_goods/lists',
            'jxc.customer/lists',
            'jxc.customer_group/lists',
            'jxc.goods/lists',
            'jxc.goods_unit/lists',
            'jxc.purchase_order/lists',
            'jxc.purchase_return_order/lists',
            'jxc.sales_order/lists',
            'jxc.sales_reservation/lists',
            'jxc.sales_return_order/lists',
            'jxc.store/detail',
            'jxc.supplier/lists',
            'jxc.supply_order/lists',
            'jxc.task/dashboard',
            'jxc.warehouse/lists',
        ];
        foreach ($automatic as $path) {
            self::assertSame(404, self::dispatch('GET', 'api/' . $path)->getCode(), '/api/' . $path);
        }

        $explicit = [
            'audit/lists',
            'user/info',
            'goods/cloud/index',
            'customer/index',
            'jxc/customer_report/lists',
            'customer/groups',
            'goods/index',
            'units/index',
            'purchase-return/lists',
            'order/lists',
            'return/lists',
            'user/store/current',
            'supplier/index',
            'supply/lists',
            'warehouse/index',
        ];
        foreach ($explicit as $path) {
            self::assertNotSame(404, self::dispatch('GET', 'api/' . $path)->getCode(), '/api/' . $path);
        }
    }

    public function test_temporary_dispatch_keeps_orm_bound_to_the_test_connection(): void
    {
        $expected = Db::name('goods')->where('id', 0)->count();

        self::dispatch('GET', 'api/jxc/customer_report/lists');

        self::assertSame($expected, Goods::where('id', 0)->count());
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);
        return $source;
    }

    private static function dispatch(string $method, string $path): \think\Response
    {
        $container = \think\Container::getInstance();
        $modelDb = $container->make('db');
        $app = new \think\App(dirname(__DIR__, 2));
        \think\Container::setInstance($app);
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = $app->getRootPath() . 'public/index.php';
        try {
            $app->initialize();
            $request = $app->make('request', [], true);
            $request->setMethod($method)->setPathinfo($path)->setUrl('/' . $path)->setHost('localhost');
            $app->instance('request', $request);
            return (new \think\app\MultiApp($app))->handle($request, function ($request) use ($app) {
                $loadRoutes = function () use ($app): void {
                    foreach (glob($app->http->getRoutePath() . '*.php') as $file) {
                        include $file;
                    }
                };
                return $app->route->dispatch($request, $loadRoutes);
            });
        } finally {
            \think\Container::setInstance($container);
            \think\Model::setDb($modelDb);
            \think\Model::setInvoker([$container, 'invoke']);
            if ($scriptFilename === null) {
                unset($_SERVER['SCRIPT_FILENAME']);
            } else {
                $_SERVER['SCRIPT_FILENAME'] = $scriptFilename;
            }
        }
    }
}
