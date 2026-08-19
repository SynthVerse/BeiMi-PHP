<?php

declare(strict_types=1);

$_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
require dirname(__DIR__) . '/bootstrap.php';

use think\facade\Db;

$taskId = (int)($argv[1] ?? 0);
$readyPath = (string)($argv[2] ?? '');
$holdMicros = (int)($argv[3] ?? 0);
if ($taskId <= 0 || $readyPath === '' || $holdMicros <= 0) {
    fwrite(STDERR, 'Invalid fulfillment bill lock worker payload.');
    exit(2);
}

Db::startTrans();
try {
    $task = Db::name('fulfillment_task')->where('id', $taskId)->lock(true)->find();
    if (!$task) {
        throw new RuntimeException('Fulfillment task does not exist.');
    }
    file_put_contents($readyPath, sprintf('%.6F', microtime(true)));
    usleep($holdMicros);
    Db::commit();
} catch (Throwable $exception) {
    Db::rollback();
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
