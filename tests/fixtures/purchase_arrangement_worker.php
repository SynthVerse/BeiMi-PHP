<?php
declare(strict_types=1);
$_SERVER['JXC_PHPUNIT_ENV']='testing';
require dirname(__DIR__).'/bootstrap.php';
use app\api\jxc\logic\PurchasePlanLogic;
$input=json_decode(file_get_contents($argv[1]),true);
request()->tenantId=$input['tenant_id'];request()->adminId=$input['admin_id'];request()->userId=$input['admin_id'];request()->jxcFromUserToken=false;
request()->adminInfo=['root'=>1,'tenant_id'=>$input['tenant_id']];
for($n=0;$n<500&&!is_file($argv[3]);$n++){usleep(10000);}
$r=PurchasePlanLogic::create($input['request']);file_put_contents($argv[2],json_encode(['result'=>$r,'error'=>PurchasePlanLogic::getError()],JSON_UNESCAPED_UNICODE));
