<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class LegacySupplierOrderRetirementContractTest extends TestCase
{
    public function test_exact_legacy_supplier_actions_are_retired_before_controller_invocation(): void
    {
        $middleware = self::source('app/tenantapi/http/middleware/InitMiddleware.php');
        $guard = 'in_array(strtolower($request->controller() . \'/\' . $request->action()), $retiredSupplierActions, true)';
        $expectedActions = [
            'supplier.usersupplier/lists',
            'supplier.usersupplier/add',
            'supplier.usersupplier/edit',
            'supplier.usersupplier/delete',
            'supplier.usersupplier/detail',
            'supplier.usersupplier/pay',
            'supplier.usersupplier/search',
            'supplier.usersupplierorder/lists',
            'supplier.usersupplierorder/add',
            'supplier.usersupplierorder/edit',
            'supplier.usersupplierorder/delete',
            'supplier.usersupplierorder/detail',
            'supplier.usersupplierorder/pay',
            'supplier.usersuppliermoney/lists',
            'supplier.usersuppliermoney/delete',
            'supplier.usersuppliermoney/detail',
        ];

        self::assertStringContainsString($guard, $middleware);
        self::assertSame($expectedActions, self::retiredSupplierActions($middleware));

        $guardPosition = strpos($middleware, $guard);
        self::assertNotFalse($guardPosition);
        foreach (["try {", 'invoke($controller)', '$request->controllerObject = invoke($controller);'] as $laterStep) {
            $laterPosition = strpos($middleware, $laterStep);
            self::assertNotFalse($laterPosition);
            self::assertLessThan($laterPosition, $guardPosition);
        }
    }

    public function test_retirement_response_is_http_410_with_the_frozen_payload(): void
    {
        $middleware = self::source('app/tenantapi/http/middleware/InitMiddleware.php');

        self::assertStringContainsString('return json([', $middleware);
        self::assertStringContainsString("'code' => 0", $middleware);
        self::assertStringContainsString("'show' => 1", $middleware);
        self::assertStringContainsString("'msg' => '历史供应商采购与付款功能已下线'", $middleware);
        self::assertStringContainsString("'retirement_marker' => 'LEGACY_SUPPLIER_ORDER_RETIRED'", $middleware);
        self::assertStringContainsString('], 410);', $middleware);
    }

    public function test_neighboring_jxc_action_is_not_retired(): void
    {
        $middleware = self::source('app/tenantapi/http/middleware/InitMiddleware.php');

        self::assertStringNotContainsString('jxc.supply_order/lists', $middleware);
    }

    public function test_all_sixteen_historical_method_and_url_pairs_dispatch_to_410(): void
    {
        $cases = [
            ['GET', 'supplier.user_supplier/lists'],
            ['POST', 'supplier.user_supplier/add'],
            ['POST', 'supplier.user_supplier/edit'],
            ['POST', 'supplier.user_supplier/delete'],
            ['GET', 'supplier.user_supplier/detail'],
            ['POST', 'supplier.user_supplier/pay'],
            ['GET', 'supplier.user_supplier/search'],
            ['GET', 'supplier.user_supplier_order/lists'],
            ['POST', 'supplier.user_supplier_order/add'],
            ['POST', 'supplier.user_supplier_order/edit'],
            ['POST', 'supplier.user_supplier_order/delete'],
            ['GET', 'supplier.user_supplier_order/detail'],
            ['POST', 'supplier.user_supplier_order/pay'],
            ['GET', 'supplier.user_supplier_money/lists'],
            ['POST', 'supplier.user_supplier_money/delete'],
            ['GET', 'supplier.user_supplier_money/detail'],
        ];

        foreach ($cases as [$method, $path]) {
            $response = self::dispatch($method, 'api/' . $path);
            self::assertSame(410, $response->getCode(), $method . ' /api/' . $path);
            self::assertStringContainsString('LEGACY_SUPPLIER_ORDER_RETIRED', $response->getContent());
        }
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

    /**
     * @return list<string>
     */
    private static function retiredSupplierActions(string $middleware): array
    {
        self::assertMatchesRegularExpression('/\\$retiredSupplierActions = \\[(.*?)\\];/s', $middleware);
        preg_match('/\\$retiredSupplierActions = \\[(.*?)\\];/s', $middleware, $matches);
        preg_match_all("/'([^']+)'/", $matches[1], $actions);

        return $actions[1];
    }
}
