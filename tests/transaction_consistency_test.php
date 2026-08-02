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
        'customerReportId' => null,
        'customerReportVersion' => null,
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

function customer_report_payload(array $fixtures, string $key, string $quantity, bool $priced): array
{
    $item = [
        'goods_id' => $fixtures['goodsId'],
        'warehouse_id' => $fixtures['warehouseId'],
        'unit_id' => 0,
        'unit_name' => '个',
        'order_qty' => $quantity,
        'piece_weight_confirmed' => 1,
        'piece_weight_min' => '1.00',
        'piece_weight_max' => '1.00',
        'price_status' => $priced ? 'priced' : 'unpriced',
    ];
    if ($priced) {
        $item['price'] = '10.00';
        $item['pricing_unit_id'] = 0;
        $item['pricing_unit_name'] = '个';
    }

    return [
        'main_customer_id' => $fixtures['customerId'],
        'idempotency_key' => $key,
        'items' => [$item],
    ];
}

function cleanup_customer_report_direct(int $reportId): void
{
    if ($reportId <= 0) {
        return;
    }
    db_exec(
        'DELETE FROM `' . db_table('customer_report_reservation') . '` WHERE report_id = :report_id',
        ['report_id' => $reportId]
    );
    db_exec(
        'DELETE FROM `' . db_table('customer_report_item') . '` WHERE report_id = :report_id',
        ['report_id' => $reportId]
    );
    db_exec(
        'DELETE FROM `' . db_table('customer_report') . '` WHERE id = :id',
        ['id' => $reportId]
    );
}

function cleanup_fixtures(string $baseUrl, string $token, array $fixtures): void
{
    if (!empty($fixtures['customerReportId']) && !empty($fixtures['customerReportVersion'])) {
        http_request('POST', $baseUrl . '/api/jxc/customer_report/cancel', [
            'id' => $fixtures['customerReportId'],
            'version' => $fixtures['customerReportVersion'],
        ], $token);
    }
    if (!empty($fixtures['salesOrderId'])) {
        http_request('DELETE', $baseUrl . '/api/order/remove', ['id' => $fixtures['salesOrderId']], $token);
    }
    if (!empty($fixtures['customerReportId'])) {
        cleanup_customer_report_direct((int)$fixtures['customerReportId']);
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

function publish_supply(string $baseUrl, string $token, array $fixtures, string $label, int $quantity): array
{
    return http_request('POST', $baseUrl . '/api/supply/publish', [
        'supplier_id' => $fixtures['supplierId'],
        'warehouse_id' => $fixtures['warehouseId'],
        'goods' => [[
            'goods_id' => $fixtures['goodsId'],
            'name' => $label . '_商品',
            'number' => $quantity,
            'price' => 6,
            'units' => '个',
        ]],
    ], $token);
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
    $supply = publish_supply($BASE_URL, $token, $s1, 'TXN_S1', 20);
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

echo "\n--- 场景2：未定价报货拒绝转换且零副作用 ---\n";
$s2 = create_basic_fixtures($BASE_URL, $token, 'TXN_S2');
$s2Ready = !empty($s2['warehouseId']) && !empty($s2['supplierId']) && !empty($s2['customerId']) && !empty($s2['goodsId']);
$runtime->assertTrue($s2Ready, '场景2前置数据创建成功');

if ($s2Ready) {
    $supply = publish_supply($BASE_URL, $token, $s2, 'TXN_S2', 12);
    $s2['supplyId'] = extract_id($supply);
    $runtime->assertCode($supply, 1, '场景2进货入库成功');

    $report = http_request('POST', $BASE_URL . '/api/jxc/customer_report/submit', customer_report_payload(
        $s2,
        'txn-unpriced-' . uniqid(),
        '5',
        false
    ), $token);
    $s2['customerReportId'] = extract_id($report);
    $s2['customerReportVersion'] = (int)($report['data']['version'] ?? 0);
    $runtime->assertCode($report, 1, '场景2未定价报货提交成功');

    $convert = http_request('POST', $BASE_URL . '/api/jxc/customer_report/convert', [
        'id' => $s2['customerReportId'],
        'version' => $s2['customerReportVersion'],
    ], $token);
    $runtime->assertTrue((int)($convert['code'] ?? 0) !== 1, '场景2未定价报货转换被拒绝');

    $salesCount = (int)db_value(
        'SELECT COUNT(*) FROM `' . db_table('sales_order') . '` WHERE source_type = :source_type AND source_id = :source_id',
        ['source_type' => 'customer_report', 'source_id' => $s2['customerReportId']]
    );
    $reserved = (string)db_value(
        'SELECT reserved_qty FROM `' . db_table('warehouse_goods_balance') . '` WHERE warehouse_id = :warehouse_id AND goods_id = :goods_id',
        ['warehouse_id' => $s2['warehouseId'], 'goods_id' => $s2['goodsId']]
    );
    $runtime->assertInt($salesCount, 0, '场景2未生成标准销售单');
    $runtime->assertMoney($reserved, '5.00', '场景2预留库存保持不变');
}
cleanup_fixtures($BASE_URL, $token, $s2);

echo "\n--- 场景3：已定价报货并发转换保持幂等 ---\n";
$s3 = create_basic_fixtures($BASE_URL, $token, 'TXN_S3');
$s3Ready = !empty($s3['warehouseId']) && !empty($s3['supplierId']) && !empty($s3['customerId']) && !empty($s3['goodsId']);
$runtime->assertTrue($s3Ready, '场景3前置数据创建成功');

if ($s3Ready) {
    $supply = publish_supply($BASE_URL, $token, $s3, 'TXN_S3', 20);
    $s3['supplyId'] = extract_id($supply);
    $runtime->assertCode($supply, 1, '场景3进货入库成功');

    $report = http_request('POST', $BASE_URL . '/api/jxc/customer_report/submit', customer_report_payload(
        $s3,
        'txn-priced-' . uniqid(),
        '4',
        true
    ), $token);
    $s3['customerReportId'] = extract_id($report);
    $s3['customerReportVersion'] = (int)($report['data']['version'] ?? 0);
    $runtime->assertCode($report, 1, '场景3已定价报货提交成功');

    $convertRequests = [
        ['url' => $BASE_URL . '/api/jxc/customer_report/convert', 'data' => ['id' => $s3['customerReportId'], 'version' => $s3['customerReportVersion']]],
        ['url' => $BASE_URL . '/api/jxc/customer_report/convert', 'data' => ['id' => $s3['customerReportId'], 'version' => $s3['customerReportVersion']]],
    ];
    $convertResponses = multi_json_post($convertRequests, $token);
    $convertSuccess = count(array_filter(
        $convertResponses,
        static fn(array $response): bool => (int)($response['code'] ?? 0) === 1
    ));
    $runtime->assertTrue($convertSuccess >= 1, '场景3至少一个并发转换请求成功');

    $linkedSalesCount = (int)db_value(
        'SELECT COUNT(*) FROM `' . db_table('sales_order') . '` WHERE source_type = :source_type AND source_id = :source_id',
        ['source_type' => 'customer_report', 'source_id' => $s3['customerReportId']]
    );
    $runtime->assertInt($linkedSalesCount, 1, '场景3并发转换仅生成 1 张标准销售单');

    $linkedSalesId = db_value(
        'SELECT id FROM `' . db_table('sales_order') . '` WHERE source_type = :source_type AND source_id = :source_id LIMIT 1',
        ['source_type' => 'customer_report', 'source_id' => $s3['customerReportId']]
    );
    if ($linkedSalesId !== false && $linkedSalesId !== null) {
        $s3['salesOrderId'] = (int)$linkedSalesId;
    }

    $stockFlowCount = (int)db_value(
        'SELECT COUNT(*) FROM `' . db_table('stock_flow') . '` WHERE order_type = :order_type AND order_id = :order_id',
        ['order_type' => 'sales', 'order_id' => $s3['salesOrderId']]
    );
    $receivableFlowCount = (int)db_value(
        'SELECT COUNT(*) FROM `' . db_table('receivable_flow') . '` WHERE order_type = :order_type AND order_id = :order_id',
        ['order_type' => 'sales', 'order_id' => $s3['salesOrderId']]
    );
    $runtime->assertInt($stockFlowCount, 1, '场景3库存流水仅生成一次');
    $runtime->assertInt($receivableFlowCount, 1, '场景3应收流水仅生成一次');

    $goodsAfterConvert = http_request('GET', $BASE_URL . '/api/goods/detail', ['id' => $s3['goodsId']], $token);
    $customerAfterConvert = http_request('GET', $BASE_URL . '/api/customer/detail', ['id' => $s3['customerId']], $token);
    $runtime->assertMoney(goods_stock($goodsAfterConvert), '16.00', '场景3并发转换后库存仅扣减一次');
    $runtime->assertMoney(customer_receivable($customerAfterConvert), '40.00', '场景3并发转换后应收仅增加一次');
}
cleanup_fixtures($BASE_URL, $token, $s3);

http_request('POST', $BASE_URL . '/api/user/logout', [], $token);

echo "\n结束时间: " . date('Y-m-d H:i:s') . "\n";
exit($runtime->printSummary());
