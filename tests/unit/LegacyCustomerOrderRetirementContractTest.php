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
            'user.user_order/lists',
            'user.user_order/add',
            'user.user_order/pay',
            'user.user_order/delete',
            'user.user_order/detail',
            'user.user_order_goods/lists',
            'user.user_order_goods/add',
            'user.user_order_goods/edit',
            'user.user_order_goods/delete',
            'user.user_order_goods/detail',
            'user.user_money/lists',
            'user.user_money/delete',
            'user.user_money/detail',
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

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);

        return $source;
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
