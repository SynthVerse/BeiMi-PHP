<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\controller\GoodsController;
use app\api\jxc\logic\GoodsLogic;
use app\api\jxc\validate\GoodsValidate;
use PHPUnit\Framework\TestCase;

final class GoodsRecommendationsContractTest extends TestCase
{
    public function testRecommendationsRouteIsRegistered(): void
    {
        $routeFile = dirname(__DIR__, 2) . '/app/api/route/jxc.php';
        $routes = file_get_contents($routeFile);

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('goods/recommendations', 'jxc.Goods/recommendations')", $routes);
    }

    public function testRecommendationsControllerValidateAndLogicContractExists(): void
    {
        self::assertTrue(method_exists(GoodsController::class, 'recommendations'));
        self::assertTrue(method_exists(GoodsValidate::class, 'sceneRecommendations'));
        self::assertTrue(method_exists(GoodsLogic::class, 'recommendations'));
    }

    public function testSelectedCustomerDoesNotFallBackToTenantHotRecommendations(): void
    {
        $logicFile = dirname(__DIR__, 2) . '/app/api/jxc/logic/GoodsLogic.php';
        $logic = file_get_contents($logicFile);

        self::assertIsString($logic);
        self::assertStringContainsString("'hot' => \$customerId > 0 ? [] : self::hotSalesRecommendations", $logic);
    }
}
