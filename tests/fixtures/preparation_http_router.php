<?php

declare(strict_types=1);

// 仅由本机隔离测试进程启动；真实路由、校验器及鉴权中间件保持启用。
if (getenv('JXC_PREPARATION_HTTP_TEST') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit;
}
$_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2) . '/public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['PATH_INFO'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$bridge = getenv('JXC_PREPARATION_HTTP_BRIDGE') === '1';
$goodsTrace = null;
$responseCode = null;
if ($bridge && ($tracePath = getenv('BEIMI_GOODS_HTTP_TRACE'))) {
    require_once dirname(__DIR__) . '/support/GoodsHttpTrace.php';
    $goodsTrace = \tests\support\GoodsHttpTrace::start(
        (string)$_SERVER['PATH_INFO'],
        $_SERVER['REQUEST_METHOD'],
        $_SERVER['HTTP_X_BEIMI_GOODS_ENTRY'] ?? '',
        $_GET,
        $tracePath
    );
    if ($goodsTrace !== null) {
        ini_set('display_errors', '0');
        header('X-BeiMi-Goods-Trace-Id: ' . $goodsTrace->id());
        register_shutdown_function(static function () use ($goodsTrace, &$responseCode): void {
            if ($goodsTrace->captureFatal(error_get_last()) && !headers_sent()) {
                http_response_code(500);
                $responseCode = null;
            }
            $goodsTrace->finish(http_response_code() ?: 500, $responseCode);
        });
    }
}
try {
    require dirname(__DIR__) . '/bootstrap.php';
} catch (Throwable $error) {
    if ($goodsTrace === null) {
        throw $error;
    }
    $goodsTrace->capture($error);
    http_response_code(500);
    exit;
}
if ($bridge) {
    // 联合驱动只保留去敏协议结果，防止会话或请求资料进入应用日志。
    \think\facade\Log::close(array_keys(\think\facade\Config::get('log.channels', [])));
}
$app->setRuntimePath(sys_get_temp_dir() . '/beimi-preparation-http-' . getmypid() . '/');
\think\facade\Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');
define('ROOT_PATH', dirname(__DIR__, 2) . '/public');
if ($goodsTrace !== null) {
    require_once dirname(__DIR__) . '/support/GoodsHttpTraceExceptionHandler.php';
    $goodsTrace->enterApplication();
    $goodsTraceHandler = new \tests\support\GoodsHttpTraceExceptionHandler($app, $goodsTrace);
    $app->instance(\think\exception\Handle::class, $goodsTraceHandler);
}
$http = $app->http;
$response = $http->run();
if ($goodsTrace !== null) {
    $response = $goodsTraceHandler->prepareResponse($response);
    $data = $response->getData();
    $responseCode = is_array($data) && is_int($data['code'] ?? null) ? $data['code'] : null;
}
$response->send();
$http->end($response);
