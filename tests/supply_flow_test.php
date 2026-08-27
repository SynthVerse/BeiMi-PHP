<?php

declare(strict_types=1);

/**
 * 采购批次 + 供应商子进货单无数据库契约测试。
 *
 * 这个入口替代旧的 HTTP 进货单测试：它不会登录、不会请求 API、不会读取
 * 环境凭据，也不会连接任何数据库。持久化事务、库存和应付的运行时行为由
 * 受隔离 .env.testing 保护的 PHPUnit 集成测试另行覆盖。
 *
 * 使用方式：php tests/supply_flow_test.php
 */

$root = dirname(__DIR__);
$checks = [
    ['app/api/route/jxc.php', "Route::post('purchase-batch/publish'", '采购批次提交路由'],
    ['app/api/jxc/logic/PurchaseBatchLogic.php', 'Db::transaction(', '父批次外层原子事务'],
    ['app/api/jxc/logic/PurchaseBatchLogic.php', 'GoodsSupplierMatrixLogic::assertCanSupply($supplierId, $goodsId, $skuId)', '供应商-SKU 矩阵校验'],
    ['app/api/jxc/logic/PurchaseBatchLogic.php', 'SupplyOrderLogic::publishWithinTransaction([', '按供应商创建子进货单'],
    ['app/api/jxc/logic/PurchaseBatchLogic.php', "'order_pay_money' => 0", '批次创建不记录跨供应商付款'],
    ['app/api/jxc/logic/PurchaseBatchLogic.php', 'ksort($groups, SORT_NUMERIC)', '供应商分组稳定顺序'],
    ['app/api/jxc/logic/PurchaseBatchLogic.php', 'replayAfterConcurrentCommit', '幂等冲突重放'],
    ['app/api/jxc/logic/SupplyOrderLogic.php', 'PurchaseArrivalService::rebuildForSupplyOrder(', '到货仍归属子进货单'],
    ['app/api/jxc/logic/SupplyOrderLogic.php', 'FinanceService::addPayable(', '应付仍归属子进货单'],
    ['database/migrations/20260827_000001_create_purchase_batch.sql', 'CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_batch`', '采购批次表'],
    ['database/migrations/20260827_000001_create_purchase_batch.sql', 'CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_batch_supply_order`', '父子单关联表'],
];

$failed = [];
foreach ($checks as [$relativePath, $needle, $label]) {
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . $relativePath);
    if ($content === false || !str_contains($content, $needle)) {
        $failed[] = $label;
        fwrite(STDERR, "[FAIL] {$label}\n");
        continue;
    }
    fwrite(STDOUT, "[PASS] {$label}\n");
}

if ($failed !== []) {
    fwrite(STDERR, sprintf("采购批次契约测试失败：%d 项\n", count($failed)));
    exit(1);
}

fwrite(STDOUT, "采购批次契约测试通过：不需要网络或数据库。\n");
