<?php

declare(strict_types=1);

$_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
require dirname(__DIR__) . '/bootstrap.php';

use app\api\jxc\logic\GoodsLogic;

$inputPath = (string)($argv[1] ?? '');
$outputPath = (string)($argv[2] ?? '');
$startPath = (string)($argv[3] ?? '');
$payload = is_file($inputPath) ? json_decode((string)file_get_contents($inputPath), true) : null;

if (!is_array($payload) || $outputPath === '') {
    fwrite(STDERR, 'Invalid concurrent goods creation worker payload.');
    exit(2);
}

$tenantId = (int)($payload['tenant_id'] ?? 0);
$adminId = (int)($payload['admin_id'] ?? 0);
request()->tenantId = $tenantId;
request()->adminId = $adminId;
request()->userId = $adminId;
request()->jxcFromUserToken = false;
request()->adminInfo = [
    'admin_id' => $adminId,
    'tenant_id' => $tenantId,
    'root' => 1,
];

for ($attempt = 0; $attempt < 200 && !is_file($startPath); $attempt++) {
    usleep(10_000);
}

$result = GoodsLogic::add((array)($payload['params'] ?? []));
file_put_contents($outputPath, json_encode([
    'result' => $result,
    'error' => GoodsLogic::getError(),
], JSON_UNESCAPED_UNICODE));
