<?php

declare(strict_types=1);

ini_set('display_errors', '0'); ini_set('log_errors', '0');
require_once dirname(__DIR__) . '/support/PreparationNativeRuntimeGate.php';
$directory = dirname(__DIR__, 2) . '/.local/final-runtime-backend-20261026/service-attempt-03';
$read = static function (string $file): array { $value = json_decode((string)@file_get_contents($file), true); return is_array($value) ? $value : []; };
if (getenv('JXC_PREPARATION_HTTP_TEST') !== '1'
    || !\tests\support\PreparationNativeRuntimeGate::allows($_SERVER, $read($directory . '/state.json'), $read($directory . '/control.json'))) {
    http_response_code(403); header('Content-Type: application/json'); echo '{"code":0,"msg":"runtime_gate_closed"}'; exit;
}
$_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2) . '/public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['PATH_INFO'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/support/PreparationNativeRuntimePrivacy.php';
\tests\support\PreparationNativeRuntimePrivacy::install($app);
$app->setRuntimePath(sys_get_temp_dir() . '/beimi-preparation-native-' . getmypid() . '/');
\think\facade\Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');
header('X-Beimi-Runtime-Cache: memory-only');
define('ROOT_PATH', dirname(__DIR__, 2) . '/public');
$http = $app->http; $response = $http->run(); $response->send(); $http->end($response);
