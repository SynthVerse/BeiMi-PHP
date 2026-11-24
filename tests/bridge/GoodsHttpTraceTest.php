<?php

declare(strict_types=1);

namespace tests\bridge;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use tests\support\GoodsHttpTrace;
use tests\support\GoodsHttpTraceExceptionHandler;
use think\App;
use think\Request;
use think\Response;
use think\exception\HttpResponseException;

final class GoodsHttpTraceTest extends TestCase
{
    public function test_catalog_trace_records_only_safe_request_and_exception_fields(): void
    {
        require_once dirname(__DIR__) . '/support/GoodsHttpTrace.php';
        $path = tempnam(sys_get_temp_dir(), 'beimi-goods-trace-');
        self::assertIsString($path);

        try {
            $trace = GoodsHttpTrace::start('/api/goods/index', 'GET', 'purchase-arrival', [
                'page_type' => '0',
                'page_size' => '1000',
                'supplier_id' => ['supplier-token-secret'],
                'name' => 'fixture-private-product',
                'token' => 'request-token-secret',
            ], $path);
            self::assertNotNull($trace);
            $trace->capture(new RuntimeException('exception-secret'));
            $trace->finish(500);

            $line = file_get_contents($path);
            self::assertIsString($line);
            self::assertStringNotContainsString('secret', $line);
            self::assertStringNotContainsString('fixture-private-product', $line);
            self::assertStringNotContainsString(__DIR__, $line);
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $record['request_id']);
            self::assertSame($trace->id(), $record['request_id']);
            self::assertSame('/api/goods/index', $record['route']);
            self::assertSame('GET', $record['method']);
            self::assertSame('purchase-arrival', $record['entry']);
            self::assertEquals(['page_type' => '0', 'page_size' => '1000', 'supplier_id' => 'invalid'], $record['query']);
            self::assertSame(500, $record['status']);
            self::assertArrayHasKey('code', $record);
            self::assertNull($record['code']);
            self::assertGreaterThanOrEqual(0, $record['elapsed_ms']);
            self::assertSame('bootstrap', $record['stage']);
            self::assertSame(RuntimeException::class, $record['exception']['type']);
            self::assertSame(basename(__FILE__), $record['exception']['file']);
            self::assertGreaterThan(0, $record['exception']['line']);
        } finally {
            unlink($path);
        }
    }

    public function test_detail_trace_records_business_code_and_only_accepts_catalog_reads(): void
    {
        require_once dirname(__DIR__) . '/support/GoodsHttpTrace.php';
        $path = tempnam(sys_get_temp_dir(), 'beimi-goods-trace-');
        self::assertIsString($path);

        try {
            self::assertNull(GoodsHttpTrace::start('/api/goods/edit', 'POST', '', [], $path));
            self::assertNull(GoodsHttpTrace::start('/api/warehouse/index', 'GET', '', [], $path));
            self::assertNull(GoodsHttpTrace::start('/api/goods/detail', 'GET', '', ['id' => '70412'], ''));
            self::assertSame('', file_get_contents($path));

            $trace = GoodsHttpTrace::start('/api/goods/detail', 'GET', 'unknown-entry', ['id' => '70412'], $path);
            self::assertNotNull($trace);
            $trace->enterApplication();
            $trace->sendingResponse();
            $trace->finish(200, 1);

            $line = file_get_contents($path);
            self::assertIsString($line);
            self::assertStringNotContainsString('unknown-entry', $line);
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('/api/goods/detail', $record['route']);
            self::assertSame('unspecified', $record['entry']);
            self::assertSame(['id' => '70412'], $record['query']);
            self::assertSame(200, $record['status']);
            self::assertSame(1, $record['code']);
            self::assertSame('response', $record['stage']);
            self::assertArrayNotHasKey('exception', $record);
        } finally {
            unlink($path);
        }
    }

    public function test_traced_server_failure_does_not_return_exception_content_or_anonymous_class_path(): void
    {
        require_once dirname(__DIR__) . '/support/GoodsHttpTrace.php';
        require_once dirname(__DIR__) . '/support/GoodsHttpTraceExceptionHandler.php';
        $path = tempnam(sys_get_temp_dir(), 'beimi-goods-trace-');
        self::assertIsString($path);

        try {
            $trace = GoodsHttpTrace::start('/api/goods/detail', 'GET', 'direct-probe', [], $path);
            self::assertNotNull($trace);
            $app = new App(dirname(__DIR__, 2));
            $app->config->set(['show_error_msg' => true], 'app');
            $handler = new GoodsHttpTraceExceptionHandler($app, $trace);
            $request = new Request();

            $error = new class('database-password-secret') extends RuntimeException {};
            $response = $handler->render($request, $error);
            self::assertSame(500, $response->getCode());
            self::assertSame(['code' => 0, 'msg' => '商品读取失败'], $response->getData());
            $frameworkResponse = Response::create(['code' => 0, 'msg' => 'protected-secret'], 'json', 503);
            self::assertSame(['code' => 0, 'msg' => '商品读取失败'], $handler->render(
                $request, new HttpResponseException($frameworkResponse)
            )->getData());
            $validationResponse = Response::create(['code' => 0, 'msg' => '请填写商品编号'], 'json', 400);
            self::assertSame($validationResponse, $handler->render($request, new HttpResponseException($validationResponse)));

            $trace->finish(500);
            $line = file_get_contents($path);
            self::assertIsString($line);
            self::assertStringNotContainsString('secret', $line);
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(RuntimeException::class, $record['exception']['type']);
            self::assertSame(basename(__FILE__), $record['exception']['file']);
        } finally {
            unlink($path);
        }
    }

    public function test_content_generation_failure_records_response_stage(): void
    {
        require_once dirname(__DIR__) . '/support/GoodsHttpTrace.php';
        require_once dirname(__DIR__) . '/support/GoodsHttpTraceExceptionHandler.php';
        $path = tempnam(sys_get_temp_dir(), 'beimi-goods-trace-');
        self::assertIsString($path);

        try {
            $trace = GoodsHttpTrace::start('/api/goods/index', 'GET', 'direct-probe', [], $path);
            self::assertNotNull($trace);
            $handler = new GoodsHttpTraceExceptionHandler(new App(dirname(__DIR__, 2)), $trace);
            $trace->enterApplication();
            $malformedJson = Response::create(['code' => 1, 'goods' => chr(0xFF)], 'json');
            $result = $handler->prepareResponse($malformedJson);

            self::assertSame(500, $result->getCode());
            self::assertSame(['code' => 0, 'msg' => '商品读取失败'], $result->getData());
            $trace->finish($result->getCode(), $result->getData()['code']);
            $line = file_get_contents($path);
            self::assertIsString($line);
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(500, $record['status']);
            self::assertSame(0, $record['code']);
            self::assertSame('response', $record['stage']);
            self::assertSame(\InvalidArgumentException::class, $record['exception']['type']);
        } finally {
            unlink($path);
        }
    }
}
