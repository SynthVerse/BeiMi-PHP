<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinanceOpeningRouteContractTest extends TestCase
{
    public function test_opening_subjects_route_precedes_the_non_exact_opening_route(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string)file_get_contents($root . '/app/api/route/jxc.php');
        $routeConfig = require $root . '/config/route.php';
        $subjects = strpos($routes, "Route::get('finance/opening/subjects'");
        $opening = strpos($routes, "Route::get('finance/opening',");

        self::assertNotFalse($subjects, '缺少财务期初对象列表路由');
        self::assertNotFalse($opening, '缺少财务期初快照路由');

        if (($routeConfig['route_complete_match'] ?? false) === true) {
            self::assertTrue(true);
            return;
        }

        self::assertLessThan(
            $opening,
            $subjects,
            'ThinkPHP 当前未开启完全匹配，较短的期初路由会抢先匹配 /finance/opening/subjects'
        );
    }
}
