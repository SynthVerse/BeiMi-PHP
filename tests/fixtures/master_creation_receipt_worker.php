<?php

declare(strict_types=1);

$_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
require dirname(__DIR__) . '/bootstrap.php';

use app\api\jxc\logic\MasterCreationReceipt;
use app\common\enum\AdminTerminalEnum;
use think\facade\Db;

$inputPath = (string)($argv[1] ?? '');
$outputPath = (string)($argv[2] ?? '');
$startPath = (string)($argv[3] ?? '');
$payload = is_file($inputPath) ? json_decode((string)file_get_contents($inputPath), true) : null;
if (!is_array($payload) || $outputPath === '' || $startPath === '') {
    fwrite(STDERR, 'Invalid master creation receipt worker input.');
    exit(2);
}

$tenantId = (int)$payload['tenant_id'];
$actorId = (int)$payload['actor_id'];
request()->tenantId = $tenantId;
request()->adminId = $actorId;
request()->userId = $actorId;
request()->jxcFromUserToken = false;
request()->source = AdminTerminalEnum::TENANT;
request()->adminInfo = ['admin_id' => $actorId, 'tenant_id' => $tenantId, 'root' => 1];
$connectionId = (int)Db::query('SELECT CONNECTION_ID() AS connection_id')[0]['connection_id'];
touch($outputPath . '.ready');
for ($attempt = 0; $attempt < 1000 && !is_file($startPath); $attempt++) {
    usleep(10_000);
}
if (!is_file($startPath)) {
    fwrite(STDERR, 'Master receipt worker start barrier timed out.');
    exit(3);
}

try {
    $result = MasterCreationReceipt::run('customer', (array)$payload['params'], static function () use ($tenantId): array {
        $id = (int)Db::name('master_receipt_test_effect')->insertGetId([
            'tenant_id' => $tenantId, 'label' => 'concurrent', 'payload' => '{}',
        ]);
        // Hold a real InnoDB transaction open while the second process contends.
        usleep(350_000);
        return ['id' => $id, 'created' => true];
    });
    $response = ['ok' => true, 'result' => $result];
} catch (Throwable $exception) {
    $response = ['ok' => false, 'error' => $exception->getMessage()];
}
$response['connection_id'] = $connectionId;
file_put_contents($outputPath, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
