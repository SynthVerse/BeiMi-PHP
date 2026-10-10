<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CustomerReportOverviewPublicRouteTest extends TestCase
{
    public function test_both_public_overview_routes_require_login(): void
    {
        foreach (['GET' => 'overview', 'POST' => 'export_word'] as $method => $action) {
            $container = \think\Container::getInstance();
            $modelDb = $container->make('db');
            $app = (new \think\App(dirname(__DIR__, 2)))->setEnvName('testing');
            \think\Container::setInstance($app);
            $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
            $_SERVER['SCRIPT_FILENAME'] = $app->getRootPath() . 'public/index.php';
            try {
                $app->initialize();
                $request = $app->make('request', [], true);
                $path = 'api/jxc/customer_report/' . $action;
                $request->setMethod($method)->setPathinfo($path)->setUrl('/' . $path)->setHost('localhost');
                $app->instance('request', $request);
                $response = (new \think\app\MultiApp($app))->handle($request, function ($request) use ($app) {
                    return $app->route->dispatch($request, function () use ($app): void {
                        foreach (glob($app->http->getRoutePath() . '*.php') as $file) { include $file; }
                    });
                });
                self::assertSame(200, $response->getCode());
                $body = json_decode((string)$response->getContent(), true);
                self::assertSame('请求参数缺token', $body['msg'] ?? null);
            } finally {
                \think\Container::setInstance($container);
                \think\Model::setDb($modelDb);
                \think\Model::setInvoker([$container, 'invoke']);
                if ($scriptFilename === null) { unset($_SERVER['SCRIPT_FILENAME']); } else { $_SERVER['SCRIPT_FILENAME'] = $scriptFilename; }
            }
        }
    }
}
