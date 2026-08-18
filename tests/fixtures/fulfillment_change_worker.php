<?php

declare(strict_types=1);

$_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
require dirname(__DIR__) . '/bootstrap.php';

use app\api\jxc\logic\FulfillmentChangeLogic;

$inputPath = (string)($argv[1] ?? '');
$outputPath = (string)($argv[2] ?? '');
$startPath = (string)($argv[3] ?? '');
$payload = is_file($inputPath) ? json_decode((string)file_get_contents($inputPath), true) : null;

if (!is_array($payload) || $outputPath === '') {
    fwrite(STDERR, 'Invalid concurrent fulfillment change worker payload.');
    exit(2);
}

request()->tenantId = (int)$payload['tenant_id'];
request()->adminId = (int)$payload['admin_id'];
request()->userId = (int)$payload['admin_id'];
request()->jxcFromUserToken = false;
request()->adminInfo = [
    'admin_id' => (int)$payload['admin_id'],
    'tenant_id' => (int)$payload['tenant_id'],
    'root' => 1,
];

for ($attempt = 0; $attempt < 200 && !is_file($startPath); $attempt++) {
    usleep(10_000);
}

$result = FulfillmentChangeLogic::reduceItem((array)$payload['change']);
file_put_contents($outputPath, json_encode([
    'result' => $result,
    'error' => FulfillmentChangeLogic::getError(),
], JSON_UNESCAPED_UNICODE));
