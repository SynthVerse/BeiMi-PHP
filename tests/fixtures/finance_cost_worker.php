<?php

declare(strict_types=1);

$_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
require dirname(__DIR__) . '/bootstrap.php';

use app\api\jxc\logic\FinanceCostLedger;
use think\facade\Db;

$input = json_decode((string)file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
request()->tenantId = (int)$input['tenant_id']; request()->adminId = (int)$input['admin_id'];
request()->userId = (int)$input['admin_id']; request()->jxcFromUserToken = false;
request()->adminInfo = ['admin_id' => request()->adminId, 'tenant_id' => request()->tenantId, 'root' => 1];
$result = Db::transaction(fn(): array => (new FinanceCostLedger(request()->tenantId))->recordWithinTransaction($input['event']));
file_put_contents($argv[2], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
