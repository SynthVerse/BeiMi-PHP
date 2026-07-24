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
            'user.user_supplier/lists',
            'user.user_supplier/add',
            'user.user_supplier/edit',
            'user.user_supplier/delete',
            'user.user_supplier/detail',
            'user.user_supplier/pay',
            'user.user_supplier/search',
            'user.user_supplier_order/lists',
            'user.user_supplier_order/add',
            'user.user_supplier_order/edit',
            'user.user_supplier_order/delete',
            'user.user_supplier_order/detail',
            'user.user_supplier_order/pay',
            'user.user_supplier_money/lists',
            'user.user_supplier_money/delete',
            'user.user_supplier_money/detail',
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

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);

        return $source;
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
