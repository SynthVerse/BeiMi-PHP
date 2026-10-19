<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FulfillmentPublicRouteTest extends TestCase
{
    /** @return array<string,array{string,string,string}> */
    public static function missingFulfillmentRoutes(): array
    {
        return [
            'pending goods list' => ['GET', 'pending_goods/lists', 'jxc.PendingGoods/lists'],
            'pending goods detail' => ['GET', 'pending_goods/detail', 'jxc.PendingGoods/detail'],
            'pending goods recovery preview' => ['GET', 'pending_goods/recovery_preview', 'jxc.PendingGoods/recoveryPreview'],
            'pending goods action' => ['POST', 'pending_goods/action', 'jxc.PendingGoods/action'],
            'pending goods recover' => ['POST', 'pending_goods/recover', 'jxc.PendingGoods/recover'],
            'purchase plan list' => ['GET', 'purchase_plan/lists', 'jxc.PurchasePlan/lists'],
            'purchase plan detail' => ['GET', 'purchase_plan/detail', 'jxc.PurchasePlan/detail'],
            'purchase arrival preview' => ['GET', 'purchase_plan/arrival_preview', 'jxc.PurchasePlan/arrivalPreview'],
            'purchase plan create' => ['POST', 'purchase_plan/create', 'jxc.PurchasePlan/create'],
            'purchase arrival attach' => ['POST', 'purchase_plan/attach_arrival', 'jxc.PurchasePlan/attachArrival'],
            'purchase plan terminate' => ['POST', 'purchase_plan/terminate', 'jxc.PurchasePlan/terminate'],
            'processing weights' => ['POST', 'customer_report/processing_weights', 'jxc.CustomerReport/processingWeights'],
            'pending print recovery' => ['GET', 'tasks/pending_print', 'jxc.FulfillmentTask/pendingPrint'],
        ];
    }

    /** @dataProvider missingFulfillmentRoutes */
    public function test_public_route_dispatches_to_authenticated_business_action(
        string $method,
        string $suffix,
        string $target
    ): void {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root . '/app/api/route/jxc.php');
        $declaration = sprintf("Route::%s('jxc/%s', '%s');", strtolower($method), $suffix, $target);
        self::assertTrue(str_contains($source, $declaration), '缺少路由: ' . $declaration);

        $response = self::dispatch($method, 'api/jxc/' . $suffix);
        self::assertSame(200, $response->getCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('请求参数缺token', $body['msg'] ?? null);
    }

    private static function dispatch(string $method, string $path): \think\Response
    {
        $container = \think\Container::getInstance();
        $modelDb = $container->make('db');
        $app = (new \think\App(dirname(__DIR__, 2)))->setEnvName('testing');
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
