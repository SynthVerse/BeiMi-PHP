<?php
/**
 * BeiMi JXC 供应商付款专项回归测试
 * 覆盖：
 * 1. 成功付款
 * 2. 超额付款被拒绝
 * 3. 停用供应商付款被拒绝
 * 4. 无应付金额付款被拒绝
 *
 * 使用方法：php tests/supplier_paymoney_test.php [BASE_URL]
 */

declare(strict_types=1);

require __DIR__ . '/support/api_test_helper.php';

function supplier_detail(string $baseUrl, string $token, int $supplierId): array
{
    return http_request('GET', $baseUrl . '/api/supplier/details', ['id' => $supplierId], $token);
}

function supplier_payment_flow_count(int $supplierId): int
{
    $value = db_value(
        'SELECT COUNT(*) FROM `' . db_table('payable_flow') . '` WHERE supplier_id = :supplier_id AND flow_type = :flow_type',
        ['supplier_id' => $supplierId, 'flow_type' => 2]
    );

    return (int)($value ?: 0);
}

function create_payable_fixture(string $baseUrl, string $token, string $prefix, float $orderAmount = 200.0): array
{
    $fixture = [
        'warehouseId' => null,
        'supplierId' => null,
        'goodsId' => null,
        'supplyOrderId' => null,
        'supplierName' => '',
    ];

    $warehouse = http_request('POST', $baseUrl . '/api/warehouse/add', [
        'name' => test_name($prefix . '_仓库'),
        'address' => test_name($prefix . '_地址'),
    ], $token);
    $fixture['warehouseId'] = extract_id($warehouse);

    $fixture['supplierName'] = test_name($prefix . '_供应商');
    $supplier = http_request('POST', $baseUrl . '/api/supplier/add', [
        'supplier_name' => $fixture['supplierName'],
        'contact' => $prefix . '_联系人',
        'phone' => '13830000001',
    ], $token);
    $fixture['supplierId'] = extract_id($supplier);

    $goods = http_request('POST', $baseUrl . '/api/goods/add', [
        'name' => test_name($prefix . '_商品'),
        'product_code' => short_test_name($prefix . 'G'),
        'price' => 10,
        'units' => '个',
    ], $token);
    $fixture['goodsId'] = extract_id($goods);

    if (!empty($fixture['warehouseId']) && !empty($fixture['supplierId']) && !empty($fixture['goodsId'])) {
        $supply = http_request('POST', $baseUrl . '/api/supply/publish', [
            'supplier_id' => $fixture['supplierId'],
            'warehouse_id' => $fixture['warehouseId'],
            'goods' => [[
                'goods_id' => $fixture['goodsId'],
                'name' => $prefix . '_商品',
                'number' => 20,
                'price' => $orderAmount / 20,
                'units' => '个',
            ]],
        ], $token);
        $fixture['supplyOrderId'] = extract_id($supply);
    }

    return $fixture;
}

function create_supplier_without_payable(string $baseUrl, string $token, string $prefix): array
{
    $supplierName = test_name($prefix . '_零应付供应商');
    $supplier = http_request('POST', $baseUrl . '/api/supplier/add', [
        'supplier_name' => $supplierName,
        'contact' => $prefix . '_联系人',
        'phone' => '13830000002',
    ], $token);

    return [
        'supplierId' => extract_id($supplier),
        'supplierName' => $supplierName,
    ];
}

function disable_supplier(string $baseUrl, string $token, int $supplierId, string $supplierName): array
{
    return http_request('POST', $baseUrl . '/api/supplier/edit', [
        'id' => $supplierId,
        'supplier_name' => $supplierName,
        'is_disabled' => 1,
    ], $token);
}

function cleanup_supplier_fixture(array $fixture): void
{
    if (!empty($fixture['supplyOrderId'])) {
        db_exec(
            'DELETE FROM `' . db_table('stock_flow') . '` WHERE order_id = :order_id AND order_type = :order_type',
            ['order_id' => $fixture['supplyOrderId'], 'order_type' => 'supply']
        );
        db_exec(
            'DELETE FROM `' . db_table('order_goods') . '` WHERE order_id = :order_id AND order_type = :order_type',
            ['order_id' => $fixture['supplyOrderId'], 'order_type' => 'supply']
        );
        db_exec(
            'DELETE FROM `' . db_table('audit_log') . '` WHERE module = :module AND target_id = :target_id',
            ['module' => 'supply_order', 'target_id' => $fixture['supplyOrderId']]
        );
        db_exec(
            'DELETE FROM `' . db_table('supply_order') . '` WHERE id = :id',
            ['id' => $fixture['supplyOrderId']]
        );
    }

    if (!empty($fixture['supplierId'])) {
        db_exec(
            'DELETE FROM `' . db_table('payable_flow') . '` WHERE supplier_id = :supplier_id',
            ['supplier_id' => $fixture['supplierId']]
        );
        db_exec(
            'DELETE FROM `' . db_table('vendor') . '` WHERE id = :id',
            ['id' => $fixture['supplierId']]
        );
    }

    if (!empty($fixture['goodsId'])) {
        db_exec(
            'DELETE FROM `' . db_table('goods') . '` WHERE id = :id',
            ['id' => $fixture['goodsId']]
        );
    }

    if (!empty($fixture['warehouseId'])) {
        db_exec(
            'DELETE FROM `' . db_table('warehouse') . '` WHERE id = :id',
            ['id' => $fixture['warehouseId']]
        );
    }
}

function cleanup_supplier_only(array $fixture): void
{
    if (!empty($fixture['supplierId'])) {
        db_exec(
            'DELETE FROM `' . db_table('payable_flow') . '` WHERE supplier_id = :supplier_id',
            ['supplier_id' => $fixture['supplierId']]
        );
        db_exec(
            'DELETE FROM `' . db_table('vendor') . '` WHERE id = :id',
            ['id' => $fixture['supplierId']]
        );
    }
}

$BASE_URL = test_base_url($argv);
$runtime = new TestRuntime();

echo "=== BeiMi JXC 供应商付款专项测试 ===\n";
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

echo "--- 场景1：成功付款 ---\n";
$successFixture = create_payable_fixture($BASE_URL, $token, 'SUPPAY_S1');
$successReady = !empty($successFixture['warehouseId']) && !empty($successFixture['supplierId']) && !empty($successFixture['goodsId']) && !empty($successFixture['supplyOrderId']);
$runtime->assertTrue($successReady, '场景1前置数据创建成功');

if ($successReady) {
    $before = supplier_detail($BASE_URL, $token, (int)$successFixture['supplierId']);
    $runtime->assertCode($before, 1, '场景1付款前可读取供应商详情');
    $runtime->assertMoney($before['data']['order_payable'] ?? 0, '200.00', '场景1付款前应付为 200.00');
    $runtime->assertMoney($before['data']['order_paid_money'] ?? 0, '0.00', '场景1付款前已付为 0.00');
    $beforeFlowCount = supplier_payment_flow_count((int)$successFixture['supplierId']);

    $pay = http_request('POST', $BASE_URL . '/api/supplier/paymoney', [
        'supplier_id' => $successFixture['supplierId'],
        'money' => 80,
        'remark' => 'SUPPAY_S1_付款',
    ], $token);
    $runtime->assertCode($pay, 1, '场景1付款成功');
    $runtime->assertMoney($pay['data']['order_payable'] ?? 0, '120.00', '场景1成功付款后应付减少');
    $runtime->assertMoney($pay['data']['order_paid_money'] ?? 0, '80.00', '场景1成功付款后已付增加');

    $after = supplier_detail($BASE_URL, $token, (int)$successFixture['supplierId']);
    $runtime->assertCode($after, 1, '场景1付款后可读取供应商详情');
    $runtime->assertMoney($after['data']['order_payable'] ?? 0, '120.00', '场景1详情中的应付余额正确');
    $runtime->assertMoney($after['data']['order_paid_money'] ?? 0, '80.00', '场景1详情中的已付金额正确');
    $runtime->assertInt(
        supplier_payment_flow_count((int)$successFixture['supplierId']) - $beforeFlowCount,
        1,
        '场景1成功付款新增一条付款流水'
    );
}

cleanup_supplier_fixture($successFixture);

echo "\n--- 场景2：超额付款被拒绝 ---\n";
$overpayFixture = create_payable_fixture($BASE_URL, $token, 'SUPPAY_S2');
$overpayReady = !empty($overpayFixture['supplierId']) && !empty($overpayFixture['supplyOrderId']);
$runtime->assertTrue($overpayReady, '场景2前置数据创建成功');

if ($overpayReady) {
    $before = supplier_detail($BASE_URL, $token, (int)$overpayFixture['supplierId']);
    $beforeFlowCount = supplier_payment_flow_count((int)$overpayFixture['supplierId']);

    $overpay = http_request('POST', $BASE_URL . '/api/supplier/paymoney', [
        'supplier_id' => $overpayFixture['supplierId'],
        'money' => 250,
        'remark' => 'SUPPAY_S2_超额付款',
    ], $token);
    $runtime->assertTrue((int)($overpay['code'] ?? 0) !== 1, '场景2超额付款被拒绝');
    $runtime->assertTrue(
        str_contains((string)($overpay['msg'] ?? ''), '不能超过当前欠额'),
        '场景2超额付款返回正确提示',
        (string)($overpay['msg'] ?? '')
    );

    $after = supplier_detail($BASE_URL, $token, (int)$overpayFixture['supplierId']);
    $runtime->assertMoney($after['data']['order_payable'] ?? 0, $before['data']['order_payable'] ?? 0, '场景2超额付款后应付不变');
    $runtime->assertMoney($after['data']['order_paid_money'] ?? 0, $before['data']['order_paid_money'] ?? 0, '场景2超额付款后已付不变');
    $runtime->assertInt(
        supplier_payment_flow_count((int)$overpayFixture['supplierId']) - $beforeFlowCount,
        0,
        '场景2超额付款不新增付款流水'
    );
}

cleanup_supplier_fixture($overpayFixture);

echo "\n--- 场景3：停用供应商付款被拒绝 ---\n";
$disabledFixture = create_payable_fixture($BASE_URL, $token, 'SUPPAY_S3');
$disabledReady = !empty($disabledFixture['supplierId']) && !empty($disabledFixture['supplyOrderId']);
$runtime->assertTrue($disabledReady, '场景3前置数据创建成功');

if ($disabledReady) {
    $disable = disable_supplier(
        $BASE_URL,
        $token,
        (int)$disabledFixture['supplierId'],
        (string)$disabledFixture['supplierName']
    );
    $runtime->assertCode($disable, 1, '场景3停用供应商成功');

    $before = supplier_detail($BASE_URL, $token, (int)$disabledFixture['supplierId']);
    $beforeFlowCount = supplier_payment_flow_count((int)$disabledFixture['supplierId']);

    $payDisabled = http_request('POST', $BASE_URL . '/api/supplier/paymoney', [
        'supplier_id' => $disabledFixture['supplierId'],
        'money' => 50,
        'remark' => 'SUPPAY_S3_停用付款',
    ], $token);
    $runtime->assertTrue((int)($payDisabled['code'] ?? 0) !== 1, '场景3停用供应商付款被拒绝');
    $runtime->assertTrue(
        str_contains((string)($payDisabled['msg'] ?? ''), '停用供应商不可付款'),
        '场景3停用供应商返回正确提示',
        (string)($payDisabled['msg'] ?? '')
    );

    $after = supplier_detail($BASE_URL, $token, (int)$disabledFixture['supplierId']);
    $runtime->assertMoney($after['data']['order_payable'] ?? 0, $before['data']['order_payable'] ?? 0, '场景3停用供应商付款后应付不变');
    $runtime->assertMoney($after['data']['order_paid_money'] ?? 0, $before['data']['order_paid_money'] ?? 0, '场景3停用供应商付款后已付不变');
    $runtime->assertInt(
        supplier_payment_flow_count((int)$disabledFixture['supplierId']) - $beforeFlowCount,
        0,
        '场景3停用供应商付款不新增付款流水'
    );
}

cleanup_supplier_fixture($disabledFixture);

echo "\n--- 场景4：无应付金额付款被拒绝 ---\n";
$zeroPayableFixture = create_supplier_without_payable($BASE_URL, $token, 'SUPPAY_S4');
$zeroReady = !empty($zeroPayableFixture['supplierId']);
$runtime->assertTrue($zeroReady, '场景4前置数据创建成功');

if ($zeroReady) {
    $before = supplier_detail($BASE_URL, $token, (int)$zeroPayableFixture['supplierId']);
    $beforeFlowCount = supplier_payment_flow_count((int)$zeroPayableFixture['supplierId']);

    $payEmpty = http_request('POST', $BASE_URL . '/api/supplier/paymoney', [
        'supplier_id' => $zeroPayableFixture['supplierId'],
        'money' => 10,
        'remark' => 'SUPPAY_S4_零应付付款',
    ], $token);
    $runtime->assertTrue((int)($payEmpty['code'] ?? 0) !== 1, '场景4无应付金额付款被拒绝');
    $runtime->assertTrue(
        str_contains((string)($payEmpty['msg'] ?? ''), '当前无可付款金额'),
        '场景4无应付金额返回正确提示',
        (string)($payEmpty['msg'] ?? '')
    );

    $after = supplier_detail($BASE_URL, $token, (int)$zeroPayableFixture['supplierId']);
    $runtime->assertMoney($after['data']['order_payable'] ?? 0, $before['data']['order_payable'] ?? 0, '场景4无应付付款后应付不变');
    $runtime->assertMoney($after['data']['order_paid_money'] ?? 0, $before['data']['order_paid_money'] ?? 0, '场景4无应付付款后已付不变');
    $runtime->assertInt(
        supplier_payment_flow_count((int)$zeroPayableFixture['supplierId']) - $beforeFlowCount,
        0,
        '场景4无应付付款不新增付款流水'
    );
}

cleanup_supplier_only($zeroPayableFixture);

http_request('POST', $BASE_URL . '/api/user/logout', [], $token);

echo "\n结束时间: " . date('Y-m-d H:i:s') . "\n";
exit($runtime->printSummary());
