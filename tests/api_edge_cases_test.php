<?php
/**
 * BeiMi JXC API 边界 / 异常场景测试
 * 使用方法：php tests/api_edge_cases_test.php [BASE_URL]
 */

declare(strict_types=1);

require __DIR__ . '/support/api_test_helper.php';

function cleanup_edge_fixtures(string $baseUrl, string $token, array $ids): void
{
    if (!empty($ids['goods'])) {
        http_request('DELETE', $baseUrl . '/api/goods/del', ['id' => $ids['goods']], $token);
    }
    if (!empty($ids['customer'])) {
        http_request('DELETE', $baseUrl . '/api/customer/del', ['id' => $ids['customer']], $token);
    }
    if (!empty($ids['supplier'])) {
        http_request('DELETE', $baseUrl . '/api/supplier/del', ['id' => $ids['supplier']], $token);
    }
    if (!empty($ids['warehouse'])) {
        http_request('POST', $baseUrl . '/api/warehouse/del', ['id' => $ids['warehouse']], $token);
    }
}

$BASE_URL = test_base_url($argv);
$runtime = new TestRuntime();
$ids = [
    'warehouse' => null,
    'supplier' => null,
    'customer' => null,
    'goods' => null,
];

echo "=== BeiMi JXC API 边界 / 异常场景测试 ===\n";
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

echo "Step 1: 创建最小前置数据 ... ";
$warehouseRes = http_request('POST', $BASE_URL . '/api/warehouse/add', [
    'name' => test_name('EDGE_仓库'),
    'address' => test_name('EDGE_地址'),
], $token);
$ids['warehouse'] = extract_id($warehouseRes);

$supplierRes = http_request('POST', $BASE_URL . '/api/supplier/add', [
    'supplier_name' => test_name('EDGE_供应商'),
    'contact' => 'EDGE_联系人',
    'phone' => '13900000001',
], $token);
$ids['supplier'] = extract_id($supplierRes);

$customerRes = http_request('POST', $BASE_URL . '/api/customer/add', [
    'customer_name' => test_name('EDGE_客户'),
    'contact' => 'EDGE_客户联系人',
    'phone' => '13900000002',
], $token);
$ids['customer'] = extract_id($customerRes);

$goodsRes = http_request('POST', $BASE_URL . '/api/goods/add', [
    'name' => test_name('EDGE_商品'),
    'product_code' => test_name('EDGE_001'),
    'price' => 10,
    'units' => '个',
], $token);
$ids['goods'] = extract_id($goodsRes);

$fixtureReady = !empty($ids['warehouse']) && !empty($ids['supplier']) && !empty($ids['customer']) && !empty($ids['goods']);
echo $fixtureReady ? "OK\n" : "FAIL\n";
$runtime->assertTrue($fixtureReady, '边界测试前置数据创建成功');

if ($fixtureReady) {
    $customerDetailBefore = http_request('GET', $BASE_URL . '/api/customer/detail', ['id' => $ids['customer']], $token);
    $receivableBefore = customer_receivable($customerDetailBefore);

    $noTokenList = http_request('GET', $BASE_URL . '/api/customer/index');
    $runtime->assertTrue((int)($noTokenList['code'] ?? 0) !== 1, '未登录无法访问客户列表');

    $badTokenInfo = http_request('GET', $BASE_URL . '/api/user/info', [], 'bad-token-edge');
    $runtime->assertTrue((int)($badTokenInfo['code'] ?? 0) !== 1, '错误 token 无法获取用户信息');

    $missingName = http_request('POST', $BASE_URL . '/api/customer/add', [
        'contact' => '无名客户',
    ], $token);
    $runtime->assertTrue((int)($missingName['code'] ?? 0) !== 1, '客户新增缺少 customer_name 被拒绝');

    $summaryMissingParent = http_request('GET', $BASE_URL . '/api/customer/summary', [], $token);
    $runtime->assertTrue((int)($summaryMissingParent['code'] ?? 0) !== 1, '客户汇总缺少 parent_id 被拒绝');

    $zeroPay = http_request('POST', $BASE_URL . '/api/customer/paymoney', [
        'customer_id' => $ids['customer'],
        'money' => 0,
        'pay_type' => 'cash',
    ], $token);
    $runtime->assertTrue((int)($zeroPay['code'] ?? 0) !== 1, '客户付款金额为 0 被拒绝');

    $negativePay = http_request('POST', $BASE_URL . '/api/customer/paymoney', [
        'customer_id' => $ids['customer'],
        'money' => -10,
        'pay_type' => 'cash',
    ], $token);
    $runtime->assertTrue((int)($negativePay['code'] ?? 0) !== 1, '客户付款金额为负数被拒绝');

    $invalidSalesOrderSn = test_name('EDGE_INVALID_SALES');
    $invalidWarehouseSale = http_request('POST', $BASE_URL . '/api/order/publish', [
        'order_sn' => $invalidSalesOrderSn,
        'customer_id' => $ids['customer'],
        'warehouse_id' => 99999999,
        'goods' => [[
            'goods_id' => $ids['goods'],
            'name' => 'EDGE_商品',
            'number' => 1,
            'price' => 10,
            'units' => '个',
        ]],
    ], $token);
    $runtime->assertTrue((int)($invalidWarehouseSale['code'] ?? 0) !== 1, '销售单使用非法仓库被拒绝');

    $salesOrderCount = (int)db_value(
        'SELECT COUNT(*) FROM `' . db_table('sales_order') . '` WHERE order_sn = :order_sn',
        ['order_sn' => $invalidSalesOrderSn]
    );
    $runtime->assertInt($salesOrderCount, 0, '非法仓库失败后未落库销售单');

    $emptyGoodsSale = http_request('POST', $BASE_URL . '/api/order/publish', [
        'customer_id' => $ids['customer'],
        'warehouse_id' => $ids['warehouse'],
        'goods' => [],
    ], $token);
    $runtime->assertTrue((int)($emptyGoodsSale['code'] ?? 0) !== 1, '销售单缺少商品明细被拒绝');

    $invalidSupply = http_request('POST', $BASE_URL . '/api/supply/publish', [
        'supplier_id' => 99999999,
        'warehouse_id' => $ids['warehouse'],
        'goods' => [[
            'goods_id' => $ids['goods'],
            'name' => 'EDGE_商品',
            'number' => 1,
            'price' => 5,
            'units' => '个',
        ]],
    ], $token);
    $runtime->assertTrue((int)($invalidSupply['code'] ?? 0) !== 1, '进货单使用非法供应商被拒绝');

    $invalidPurchase = http_request('POST', $BASE_URL . '/api/purchase/publish', [
        'customer_id' => 99999999,
        'warehouse_id' => $ids['warehouse'],
        'goods' => [[
            'goods_id' => $ids['goods'],
            'name' => 'EDGE_商品',
            'number' => 1,
            'price' => 5,
            'units' => '个',
        ]],
    ], $token);
    $runtime->assertTrue((int)($invalidPurchase['code'] ?? 0) !== 1, '订货单使用非法客户被拒绝');

    $emptyParse = http_request('POST', $BASE_URL . '/api/purchase/parse-text', [], $token);
    $runtime->assertTrue((int)($emptyParse['code'] ?? 0) !== 1, 'AI 文本解析缺少 pastedText 被拒绝');

    $badConfirm = http_request('POST', $BASE_URL . '/api/purchase/confirm', [
        'id' => 99999999,
    ], $token);
    $runtime->assertTrue((int)($badConfirm['code'] ?? 0) !== 1, '订货单确认非法 ID 被拒绝');

    $badReturn = http_request('POST', $BASE_URL . '/api/return/publish', [
        'customer_id' => $ids['customer'],
        'warehouse_id' => $ids['warehouse'],
        'original_order_id' => 99999999,
        'original_order_sn' => 'NOT-EXIST',
        'goods' => [[
            'goods_id' => $ids['goods'],
            'name' => 'EDGE_商品',
            'number' => 1,
            'price' => 10,
            'units' => '个',
        ]],
    ], $token);
    $runtime->assertTrue((int)($badReturn['code'] ?? 0) !== 1, '退货单使用非法原销售单被拒绝');

    $customerDetailAfter = http_request('GET', $BASE_URL . '/api/customer/detail', ['id' => $ids['customer']], $token);
    $runtime->assertMoney(customer_receivable($customerDetailAfter), $receivableBefore, '失败请求后客户应收未被污染');
}

echo "\nStep 2: 清理测试数据 ... ";
cleanup_edge_fixtures($BASE_URL, $token, $ids);
echo "OK\n";

http_request('POST', $BASE_URL . '/api/user/logout', [], $token);

echo "结束时间: " . date('Y-m-d H:i:s') . "\n";
exit($runtime->printSummary());
