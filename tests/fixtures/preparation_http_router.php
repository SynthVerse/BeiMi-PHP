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
require dirname(__DIR__) . '/bootstrap.php';
if (getenv('JXC_PREPARATION_HTTP_BRIDGE') === '1') {
    // 联合驱动只保留去敏协议结果，防止会话或请求资料进入应用日志。
    \think\facade\Log::close(array_keys(\think\facade\Config::get('log.channels', [])));
}
$app->setRuntimePath(sys_get_temp_dir() . '/beimi-preparation-http-' . getmypid() . '/');
\think\facade\Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');
define('ROOT_PATH', dirname(__DIR__, 2) . '/public');
$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);
