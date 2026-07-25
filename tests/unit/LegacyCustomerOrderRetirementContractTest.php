<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class LegacyCustomerOrderRetirementContractTest extends TestCase
{
    public function test_exact_legacy_customer_actions_are_retired_before_controller_invocation(): void
    {
        $middleware = self::source('app/tenantapi/http/middleware/InitMiddleware.php');
        $guard = 'in_array(strtolower($request->controller() . \'/\' . $request->action()), $retiredActions, true)';
        $expectedActions = [
            'user.userorder/lists',
            'user.userorder/add',
            'user.userorder/pay',
            'user.userorder/delete',
            'user.userorder/detail',
            'user.userordergoods/lists',
            'user.userordergoods/add',
            'user.userordergoods/edit',
            'user.userordergoods/delete',
            'user.userordergoods/detail',
            'user.usermoney/lists',
            'user.usermoney/delete',
            'user.usermoney/detail',
            'user.user/pay',
        ];

        self::assertStringContainsString($guard, $middleware);
        self::assertSame($expectedActions, self::retiredActions($middleware));

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
        self::assertStringContainsString("'msg' => '客户赊销订单与客户收款功能已下线'", $middleware);
        self::assertStringContainsString("'retirement_marker' => 'LEGACY_CUSTOMER_ORDER_RETIRED'", $middleware);
        self::assertStringContainsString('], 410);', $middleware);
        self::assertStringNotContainsString('JsonService::fail', $middleware);
    }

    public function test_non_retired_customer_and_jxc_paths_are_not_added_to_the_retirement_list(): void
    {
        $middleware = self::source('app/tenantapi/http/middleware/InitMiddleware.php');

        self::assertStringNotContainsString('user.user/adjustmoney', $middleware);
        self::assertStringNotContainsString('user.user_money/recharge', $middleware);
        self::assertStringNotContainsString('jxc/paymoney', $middleware);
        self::assertStringNotContainsString('tenantunits', strtolower($middleware));
    }

    public function test_all_fourteen_historical_method_and_url_pairs_dispatch_to_410(): void
    {
        $cases = [
            ['GET', 'user.user_order/lists'],
            ['POST', 'user.user_order/add'],
            ['POST', 'user.user_order/pay'],
            ['POST', 'user.user_order/delete'],
            ['GET', 'user.user_order/detail'],
            ['GET', 'user.user_order_goods/lists'],
            ['POST', 'user.user_order_goods/add'],
            ['POST', 'user.user_order_goods/edit'],
            ['POST', 'user.user_order_goods/delete'],
            ['GET', 'user.user_order_goods/detail'],
            ['GET', 'user.user_money/lists'],
            ['POST', 'user.user_money/delete'],
            ['GET', 'user.user_money/detail'],
            ['POST', 'user.user/pay'],
        ];

        foreach ($cases as [$method, $path]) {
            $response = self::dispatch($method, 'api/' . $path);
            self::assertSame(410, $response->getCode(), $method . ' /api/' . $path);
            self::assertStringContainsString('LEGACY_CUSTOMER_ORDER_RETIRED', $response->getContent());
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
        $app = new \think\App(dirname(__DIR__, 2));
        $app->initialize();
        $container = \think\Container::getInstance();
        \think\Container::setInstance($app);
        $request = $app->make('request', [], true);
        $request->setMethod($method)->setPathinfo($path)->setUrl('/' . $path)->setHost('localhost');
        $app->instance('request', $request);
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = $app->getRootPath() . 'public/index.php';
        try {
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
    private static function retiredActions(string $middleware): array
    {
        self::assertMatchesRegularExpression('/\\$retiredActions = \\[(.*?)\\];/s', $middleware);
        preg_match('/\\$retiredActions = \\[(.*?)\\];/s', $middleware, $matches);
        preg_match_all("/'([^']+)'/", $matches[1], $actions);

        return $actions[1];
    }
}
