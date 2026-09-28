<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\TodoSourceRegistry;
use app\api\jxc\logic\TodoQueryBudget;
use PHPUnit\Framework\TestCase;

final class TodoRouteContractTest extends TestCase
{
    public function test_unified_todo_routes_are_exact_and_source_registry_is_complete(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/route/jxc.php');

        self::assertStringContainsString("Route::get('jxc/todos/summary', 'jxc.Todo/summary');", $routes);
        self::assertStringContainsString("Route::get('jxc/todos/lists', 'jxc.Todo/lists');", $routes);
        self::assertSame(
            array_map(static fn(int $number): string => sprintf('S%02d', $number), range(1, 23)),
            array_keys(TodoSourceRegistry::coverage())
        );
        foreach (TodoSourceRegistry::coverage() as $source => $definition) {
            foreach (['inclusion', 'completion', 'permission', 'identity', 'date_rule', 'target'] as $field) {
                self::assertNotSame('', $definition[$field] ?? '', $source . ' 缺少 ' . $field);
            }
        }
    }

    public function test_unified_todo_routes_are_actually_dispatched_and_fail_closed_without_login(): void
    {
        foreach (['api/jxc/todos/summary', 'api/jxc/todos/lists'] as $path) {
            $response = self::dispatch('GET', $path);
            self::assertNotSame(404, $response->getCode(), $path);
            self::assertStringNotContainsString('"items":[]', (string)$response->getContent(), $path);
        }
        self::assertSame(404, self::dispatch('GET', 'api/jxc.todo/summary')->getCode());
        self::assertSame(404, self::dispatch('GET', 'api/jxc.todo/lists')->getCode());
    }

    public function test_all_todo_provider_candidate_loops_use_the_request_budget_iterator(): void
    {
        foreach (['TodoTaskSourceProvider.php', 'TodoFinanceSourceProvider.php'] as $file) {
            $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/logic/' . $file);
            self::assertDoesNotMatchRegularExpression('/foreach\s*\(\$rows\s+as\s+\$row\)/', $source, $file);
        }
        $finance = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/logic/TodoFinanceSourceProvider.php');
        self::assertStringContainsString("foreach (TodoQueryBudget::candidates(\$options['transfers']) as \$row)", $finance);

        request()->todoQueryBudget = ['deadline' => microtime(true) + 1, 'rows' => 0];
        self::assertSame([10, 20, 30], array_values(iterator_to_array(TodoQueryBudget::candidates([10, 20, 30]))));
        self::assertSame(3, request()->todoQueryBudget['rows']);

        $transit = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/logic/FinanceTransitReviews.php');
        self::assertStringContainsString('self::presentMany($refs, $month, $closed)', $transit);
        self::assertStringNotContainsString('array_map(static fn(string $ref): array => self::present(', $transit);
        $transfers = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/logic/FinanceAccountTransfers.php');
        self::assertStringContainsString('return self::settlementsMany([$reference], $correctingDocument)', $transfers);
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
                    foreach (glob($app->http->getRoutePath() . '*.php') as $file) { include $file; }
                };
                return $app->route->dispatch($request, $loadRoutes);
            });
        } finally {
            \think\Container::setInstance($container);
            \think\Model::setDb($modelDb);
            \think\Model::setInvoker([$container, 'invoke']);
            if ($scriptFilename === null) { unset($_SERVER['SCRIPT_FILENAME']); }
            else { $_SERVER['SCRIPT_FILENAME'] = $scriptFilename; }
        }
    }
}
