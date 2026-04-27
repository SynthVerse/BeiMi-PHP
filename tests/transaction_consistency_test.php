<?php
/**
 * BeiMi JXC 并发 / 事务一致性专项测试
 * 使用方法：php tests/transaction_consistency_test.php [BASE_URL]
 */

declare(strict_types=1);

require __DIR__ . '/support/api_test_helper.php';

function create_basic_fixtures(string $baseUrl, string $token, string $prefix): array
{
    $fixtures = [
        'warehouseId' => null,
        'supplierId' => null,
        'customerId' => null,
        'goodsId' => null,
        'supplyId' => null,
        'salesOrderId' => null,
        'purchaseOrderId' => null,
    ];

    $warehouse = http_request('POST', $baseUrl . '/api/warehouse/add', [
        'name' => test_name($prefix . '_仓库'),
        'address' => test_name($prefix . '_地址'),
    ], $token);
    $fixtures['warehouseId'] = extract_id($warehouse);

    $supplier = http_request('POST', $baseUrl . '/api/supplier/add', [
        'supplier_name' => test_name($prefix . '_供应商'),
        'contact' => $prefix . '_供应商联系人',
        'phone' => '13820000001',
    ], $token);
    $fixtures['supplierId'] = extract_id($supplier);

    $customer = http_request('POST', $baseUrl . '/api/customer/add', [
        'customer_name' => test_name($prefix . '_客户'),
        'contact' => $prefix . '_客户联系人',
        'phone' => '13820000002',
    ], $token);
    $fixtures['customerId'] = extract_id($customer);

    $goods = http_request('POST', $baseUrl . '/api/goods/add', [
        'name' => test_name($prefix . '_商品'),
        'product_code' => short_test_name($prefix . 'G'),
        'price' => 10,
        'units' => '个',
    ], $token);
    $fixtures['goodsId'] = extract_id($goods);

    return $fixtures;
}

function cleanup_purchase_order_direct(int $purchaseOrderId): void
{
    if ($purchaseOrderId <= 0) {
        return;
    }

    db_exec(
        'DELETE FROM `' . db_table('order_goods') . '` WHERE order_id = :order_id AND order_type = :order_type',
        ['order_id' => $purchaseOrderId, 'order_type' => 'purchase']
    );
    db_exec(
        'DELETE FROM `' . db_table('purchase_order') . '` WHERE id = :id',
        ['id' => $purchaseOrderId]
    );
    db_exec(
        'DELETE FROM `' . db_table('audit_log') . '` WHERE module = :module AND target_id = :target_id',
        ['module' => 'purchase_order', 'target_id' => $purchaseOrderId]
    );
}

function cleanup_fixtures(string $baseUrl, string $token, array $fixtures): void
{
    if (!empty($fixtures['salesOrderId'])) {
        http_request('DELETE', $baseUrl . '/api/order/remove', ['id' => $fixtures['salesOrderId']], $token);
    }
    if (!empty($fixtures['purchaseOrderId'])) {
        cleanup_purchase_order_direct((int)$fixtures['purchaseOrderId']);
    }
    if (!empty($fixtures['supplyId'])) {
        http_request('DELETE', $baseUrl . '/api/supply/remove', ['id' => $fixtures['supplyId']], $token);
    }
    if (!empty($fixtures['goodsId'])) {
        http_request('DELETE', $baseUrl . '/api/goods/del', ['id' => $fixtures['goodsId']], $token);
    }
    if (!empty($fixtures['customerId'])) {
        http_request('DELETE', $baseUrl . '/api/customer/del', ['id' => $fixtures['customerId']], $token);
    }
    if (!empty($fixtures['supplierId'])) {
        http_request('DELETE', $baseUrl . '/api/supplier/del', ['id' => $fixtures['supplierId']], $token);
    }
    if (!empty($fixtures['warehouseId'])) {
        http_request('POST', $baseUrl . '/api/warehouse/del', ['id' => $fixtures['warehouseId']], $token);
    }
}

function latest_sales_order_id_from_purchase(int $purchaseOrderId): ?int
{
    $value = db_value(
        'SELECT id FROM `' . db_table('sales_order') . '` WHERE from_purchase_order_id = :purchase_order_id ORDER BY id DESC LIMIT 1',
        ['purchase_order_id' => $purchaseOrderId]
    );

    return $value === false || $value === null ? null : (int)$value;
}

$BASE_URL = test_base_url($argv);
$runtime = new TestRuntime();

echo "=== BeiMi JXC 并发 / 事务一致性专项测试 ===\n";
echo "BASE_URL: {$BASE_URL}\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

$login = login_default_admin($BASE_URL);
if (!$runtime->assertCode($login, 1, '用户登录')) {
    exit(1);
}

$token = (string)($login['data']['token'] ?? '');
if ($token === '') {
    echo "[FATAL] 无法获取 token，终止测试。\n";
    exit(1);
}

echo "--- 场景1：并发收款一致性 ---\n";
$s1 = create_basic_fixtures($BASE_URL, $token, 'TXN_S1');
$s1Ready = !empty($s1['warehouseId']) && !empty($s1['supplierId']) && !empty($s1['customerId']) && !empty($s1['goodsId']);
$runtime->assertTrue($s1Ready, '场景1前置数据创建成功');

if ($s1Ready) {
    $supply = http_request('POST', $BASE_URL . '/api/supply/publish', [
        'supplier_id' => $s1['supplierId'],
        'warehouse_id' => $s1['warehouseId'],
        'goods' => [[
            'goods_id' => $s1['goodsId'],
            'name' => 'TXN_S1_商品',
            'number' => 20,
            'price' => 6,
            'units' => '个',
        ]],
    ], $token);
    $s1['supplyId'] = extract_id($supply);
    $runtime->assertCode($supply, 1, '场景1进货入库成功');

    $sale = http_request('POST', $BASE_URL . '/api/order/publish', [
        'order_sn' => test_name('TXN_S1_SALE'),
        'customer_id' => $s1['customerId'],
        'warehouse_id' => $s1['warehouseId'],
        'goods' => [[
            'goods_id' => $s1['goodsId'],
            'name' => 'TXN_S1_商品',
            'number' => 10,
            'price' => 10,
            'units' => '个',
        ]],
    ], $token);
    $s1['salesOrderId'] = extract_id($sale);
    $runtime->assertCode($sale, 1, '场景1销售单创建成功');

    $customerBefore = http_request('GET', $BASE_URL . '/api/customer/detail', ['id' => $s1['customerId']], $token);
    $runtime->assertMoney(customer_receivable($customerBefore), '100.00', '场景1初始应收为 100.00');
    $runtime->assertMoney(customer_paid($customerBefore), '0.00', '场景1初始已收为 0.00');

    $payRequests = [];
    for ($i = 0; $i < 5; $i++) {
        $payRequests[] = [
            'url' => $BASE_URL . '/api/customer/paymoney',
            'data' => [
                'customer_id' => $s1['customerId'],
                'money' => 10,
                'pay_type' => 'cash',
                'remark' => 'TXN_S1_CONCURRENT_' . $i,
            ],
        ];
    }

    $payResponses = multi_json_post($payRequests, $token);
    $successCount = 0;
    foreach ($payResponses as $index => $response) {
        if ($runtime->assertCode($response, 1, '场景1并发收款请求 #' . ($index + 1) . ' 成功')) {
            $successCount++;
        }
    }
    $runtime->assertInt($successCount, 5, '场景1 5 次并发收款全部成功');

    $customerAfter = http_request('GET', $BASE_URL . '/api/customer/detail', ['id' => $s1['customerId']], $token);
    $runtime->assertMoney(customer_receivable($customerAfter), '50.00', '场景1并发收款后应收准确扣减');
    $runtime->assertMoney(customer_paid($customerAfter), '50.00', '场景1并发收款后已收准确累加');
}

cleanup_fixtures($BASE_URL, $token, $s1);

echo "\n--- 场景2：失败转销售回滚 ---\n";
$s2 = create_basic_fixtures($BASE_URL, $token, 'TXN_S2');
$s2Ready = !empty($s2['warehouseId']) && !empty($s2['supplierId']) && !empty($s2['customerId']) && !empty($s2['goodsId']);
$runtime->assertTrue($s2Ready, '场景2前置数据创建成功');

if ($s2Ready) {
    $s2Supply = http_request('POST', $BASE_URL . '/api/supply/publish', [
        'supplier_id' => $s2['supplierId'],
        'warehouse_id' => $s2['warehouseId'],
        'goods' => [[
            'goods_id' => $s2['goodsId'],
            'name' => 'TXN_S2_商品',
            'number' => 12,
            'price' => 6,
            'units' => '个',
        ]],
    ], $token);
    $s2['supplyId'] = extract_id($s2Supply);
    $runtime->assertCode($s2Supply, 1, '场景2进货入库成功');

    $purchase = http_request('POST', $BASE_URL . '/api/purchase/publish', [
        'customer_id' => $s2['customerId'],
        'warehouse_id' => $s2['warehouseId'],
        'goods' => [[
            'goods_id' => $s2['goodsId'],
            'name' => 'TXN_S2_商品',
            'number' => 5,
            'price' => 10,
            'units' => '个',
        ]],
    ], $token);
    $s2['purchaseOrderId'] = extract_id($purchase);
    $runtime->assertCode($purchase, 1, '场景2订货单创建成功');

    $confirm = http_request('POST', $BASE_URL . '/api/purchase/confirm', ['id' => $s2['purchaseOrderId']], $token);
    $runtime->assertCode($confirm, 1, '场景2订货单推进到 sent');

    $beforeConvert = http_request('GET', $BASE_URL . '/api/purchase/details', ['id' => $s2['purchaseOrderId']], $token);
    $runtime->assertTrue(purchase_status($beforeConvert) === 'sent', '场景2转单前状态为 sent');

    $invalidConvert = http_request('POST', $BASE_URL . '/api/purchase/convert-to-sales', [
        'id' => $s2['purchaseOrderId'],
        'warehouse_id' => 99999999,
    ], $token);
    $runtime->assertTrue((int)($invalidConvert['code'] ?? 0) !== 1, '场景2非法仓库转销售被拒绝');

    $afterConvert = http_request('GET', $BASE_URL . '/api/purchase/details', ['id' => $s2['purchaseOrderId']], $token);
    $runtime->assertTrue(purchase_status($afterConvert) === 'sent', '场景2失败转单后状态保持 sent');

    $salesCount = (int)db_value(
        'SELECT COUNT(*) FROM `' . db_table('sales_order') . '` WHERE from_purchase_order_id = :purchase_order_id',
        ['purchase_order_id' => $s2['purchaseOrderId']]
    );
    $runtime->assertInt($salesCount, 0, '场景2失败转单后未产生销售单');
}

cleanup_fixtures($BASE_URL, $token, $s2);

echo "\n--- 场景3：同一订货单并发转销售只成功一次 ---\n";
$s3 = create_basic_fixtures($BASE_URL, $token, 'TXN_S3');
$s3Ready = !empty($s3['warehouseId']) && !empty($s3['supplierId']) && !empty($s3['customerId']) && !empty($s3['goodsId']);
$runtime->assertTrue($s3Ready, '场景3前置数据创建成功');

if ($s3Ready) {
    $s3Supply = http_request('POST', $BASE_URL . '/api/supply/publish', [
        'supplier_id' => $s3['supplierId'],
        'warehouse_id' => $s3['warehouseId'],
        'goods' => [[
            'goods_id' => $s3['goodsId'],
            'name' => 'TXN_S3_商品',
            'number' => 20,
            'price' => 6,
            'units' => '个',
        ]],
    ], $token);
    $s3['supplyId'] = extract_id($s3Supply);
    $runtime->assertCode($s3Supply, 1, '场景3进货入库成功');

    $purchase3 = http_request('POST', $BASE_URL . '/api/purchase/publish', [
        'customer_id' => $s3['customerId'],
        'warehouse_id' => $s3['warehouseId'],
        'goods' => [[
            'goods_id' => $s3['goodsId'],
            'name' => 'TXN_S3_商品',
            'number' => 4,
            'price' => 10,
            'units' => '个',
        ]],
    ], $token);
    $s3['purchaseOrderId'] = extract_id($purchase3);
    $runtime->assertCode($purchase3, 1, '场景3订货单创建成功');

    $confirm3 = http_request('POST', $BASE_URL . '/api/purchase/confirm', ['id' => $s3['purchaseOrderId']], $token);
    $runtime->assertCode($confirm3, 1, '场景3订货单推进到 sent');

    $convertRequests = [
        ['url' => $BASE_URL . '/api/purchase/convert-to-sales', 'data' => ['id' => $s3['purchaseOrderId'], 'warehouse_id' => $s3['warehouseId']]],
        ['url' => $BASE_URL . '/api/purchase/convert-to-sales', 'data' => ['id' => $s3['purchaseOrderId'], 'warehouse_id' => $s3['warehouseId']]],
    ];
    $convertResponses = multi_json_post($convertRequests, $token);

    $convertSuccess = 0;
    $convertFail = 0;
    foreach ($convertResponses as $index => $response) {
        if ((int)($response['code'] ?? 0) === 1) {
            $convertSuccess++;
            $runtime->assertCode($response, 1, '场景3并发转单请求 #' . ($index + 1) . ' 成功');
        } else {
            $convertFail++;
            $runtime->assertTrue(true, '场景3并发转单请求 #' . ($index + 1) . ' 被正确拒绝');
        }
    }

    $runtime->assertInt($convertSuccess, 1, '场景3并发转单仅 1 次成功');
    $runtime->assertInt($convertFail, 1, '场景3并发转单仅 1 次失败');

    $afterConcurrentConvert = http_request('GET', $BASE_URL . '/api/purchase/details', ['id' => $s3['purchaseOrderId']], $token);
    $runtime->assertTrue(purchase_status($afterConcurrentConvert) === 'completed', '场景3并发转单后订货单状态为 completed');

    $linkedSalesCount = (int)db_value(
        'SELECT COUNT(*) FROM `' . db_table('sales_order') . '` WHERE from_purchase_order_id = :purchase_order_id',
        ['purchase_order_id' => $s3['purchaseOrderId']]
    );
    $runtime->assertInt($linkedSalesCount, 1, '场景3并发转单仅生成 1 张销售单');

    $linkedSalesId = latest_sales_order_id_from_purchase((int)$s3['purchaseOrderId']);
    if ($linkedSalesId !== null) {
        $s3['salesOrderId'] = $linkedSalesId;
    }

    $goodsAfterConvert = http_request('GET', $BASE_URL . '/api/goods/detail', ['id' => $s3['goodsId']], $token);
    $customerAfterConvert = http_request('GET', $BASE_URL . '/api/customer/detail', ['id' => $s3['customerId']], $token);
    $runtime->assertMoney(goods_stock($goodsAfterConvert), '16.00', '场景3并发转单后库存仅扣减一次');
    $runtime->assertMoney(customer_receivable($customerAfterConvert), '40.00', '场景3并发转单后应收仅增加一次');
}

cleanup_fixtures($BASE_URL, $token, $s3);

http_request('POST', $BASE_URL . '/api/user/logout', [], $token);

echo "\n结束时间: " . date('Y-m-d H:i:s') . "\n";
exit($runtime->printSummary());
