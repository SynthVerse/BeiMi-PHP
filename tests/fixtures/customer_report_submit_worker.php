<?php

declare(strict_types=1);

$_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
require dirname(__DIR__) . '/bootstrap.php';

use app\api\jxc\logic\CustomerReportLogic;

$inputPath = (string)($argv[1] ?? '');
$outputPath = (string)($argv[2] ?? '');
$startPath = (string)($argv[3] ?? '');
$payload = is_file($inputPath) ? json_decode((string)file_get_contents($inputPath), true) : null;

if (!is_array($payload) || $outputPath === '') {
    fwrite(STDERR, 'Invalid concurrent customer report worker payload.');
    exit(2);
}

request()->tenantId = (int)$payload['tenant_id'];
request()->adminId = (int)$payload['admin_id'];
request()->userId = (int)$payload['admin_id'];

for ($attempt = 0; $attempt < 200 && !is_file($startPath); $attempt++) {
    usleep(10_000);
}

$result = CustomerReportLogic::submit((array)$payload['report']);
file_put_contents($outputPath, json_encode([
    'result' => $result,
    'error' => CustomerReportLogic::getError(),
], JSON_UNESCAPED_UNICODE));
