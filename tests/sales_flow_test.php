<?php
/**
 * BeiMi JXC 销售单闭环测试
 * 验证销售单创建-编辑-删除全流程中库存和应收的精确联动
 * 使用方法：php tests/sales_flow_test.php [BASE_URL]
 */

$BASE_URL = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8787';

// ─────────────────────────────────────────────
// 全局计数器
// ─────────────────────────────────────────────
$totalTests = 0;
$passTests  = 0;
$failTests  = 0;

// ─────────────────────────────────────────────
// 辅助函数
// ─────────────────────────────────────────────

/**
 * 发起 HTTP 请求
 */
function httpRequest(string $method, string $url, array $data = [], string $token = ''): array
{
    $ch = curl_init();

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'token: ' . $token;
    }

    if ($method === 'GET' && !empty($data)) {
        $url .= '?' . http_build_query($data);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    switch ($method) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            break;
        case 'GET':
        default:
            break;
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['code' => -1, 'msg' => 'cURL error: ' . $err, 'data' => []];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return ['code' => -1, 'msg' => 'JSON decode error: ' . $raw, 'data' => []];
    }

    return $decoded;
}

/**
 * 断言响应 code 符合预期
 */
function assert_code(array $response, int $expectedCode, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    $actual = $response['code'] ?? 'N/A';
    if ((int)$actual === $expectedCode) {
        echo "[PASS] {$testName}\n";
        $passTests++;
        return true;
    }

    $reason = $response['msg'] ?? json_encode($response, JSON_UNESCAPED_UNICODE);
    echo "[FAIL] {$testName}: 期望 code={$expectedCode}, 实际 code={$actual}, msg={$reason}\n";
    $failTests++;
    return false;
}

/**
 * 精确字符串比较断言（金额专用）
 * 将双方格式化为2位小数后做字符串全等比较
 */
function assert_eq($actual, $expected, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    $actualStr   = number_format((float)$actual, 2, '.', '');
    $expectedStr = number_format((float)$expected, 2, '.', '');
    if ($actualStr === $expectedStr) {
        echo "[PASS] {$testName} (实际={$actualStr})\n";
        $passTests++;
        return true;
    }

    echo "[FAIL] {$testName}: 实际={$actualStr}, 期望={$expectedStr}\n";
    $failTests++;
    return false;
}

/**
 * 从响应中安全提取 data.id
 */
function extractId(array $response, string $key = 'id'): ?int
{
    return isset($response['data'][$key]) ? (int)$response['data'][$key] : null;
}

/**
 * 从商品详情响应中提取库存值
 */
function extractStock(array $response): ?string
{
    $stock = $response['data']['stock'] ?? null;
    return $stock !== null ? (string)$stock : null;
}

/**
 * 从客户详情响应中提取应收金额
 */
function extractReceivable(array $response): ?string
{
    $receivable = $response['data']['order_receivable'] ?? null;
    return $receivable !== null ? (string)$receivable : null;
}

/**
 * 格式化为2位小数字符串
 */
function bc2($value): string
{
    return number_format((float)$value, 2, '.', '');
}

// ══════════════════════════════════════════════
// 开始测试
// ══════════════════════════════════════════════

echo "=== BeiMi JXC 销售单闭环测试 ===\n";
echo "BASE_URL: {$BASE_URL}\n\n";

// ─────────────────────────────────────────────
// 登录
// ─────────────────────────────────────────────
$loginRes = httpRequest('POST', "{$BASE_URL}/api/user/login", [
    'account'  => 'jxcadmin',
    'password' => '123456',
    'terminal' => 1,
]);
assert_code($loginRes, 1, '用户登录');
$token = $loginRes['data']['token'] ?? '';
if (empty($token)) {
    echo "[FATAL] 无法获取 token，终止测试。\n";
    exit(1);
}

// ══════════════════════════════════════════════
// 场景 1: 正常销售流程（创建→编辑→删除）
// ══════════════════════════════════════════════
echo "\n--- 场景1: 正常销售流程 ---\n";

$s1_unitId = null; $s1_whId = null; $s1_custId = null;
$s1_supId  = null; $s1_goodsId = null; $s1_supplyId = null;
$s1_orderId = null;

// Step 1: 登录+初始化数据
echo "Step 1: 登录+初始化数据 ... ";

$unitRes = httpRequest('POST', "{$BASE_URL}/api/units/add", ['name' => 'FLOW_S1_单位'], $token);
$s1_unitId = extractId($unitRes);

$whRes = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", [
    'name' => 'FLOW_S1_仓库', 'address' => 'FLOW_S1_地址',
], $token);
$s1_whId = extractId($whRes);

$custRes = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => 'FLOW_S1_客户', 'contact' => 'FLOW_S1_联系人', 'phone' => '13800010001',
], $token);
$s1_custId = extractId($custRes);

$supRes = httpRequest('POST', "{$BASE_URL}/api/supplier/add", [
    'supplier_name' => 'FLOW_S1_供应商', 'contact' => 'FLOW_S1_供应联系人', 'phone' => '13800010002',
], $token);
$s1_supId = extractId($supRes);

$goodsRes = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name' => 'FLOW_S1_商品', 'product_code' => 'FLOW_S1_001', 'price' => 50, 'units' => '个',
], $token);
$s1_goodsId = extractId($goodsRes);

$step1ok = ($s1_unitId && $s1_whId && $s1_custId && $s1_supId && $s1_goodsId);
echo $step1ok ? "OK\n" : "FAIL\n";

if ($step1ok) {
    // 验证初始应收=0
    $custD0 = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s1_custId], $token);
    $initRecv = extractReceivable($custD0);
    assert_eq($initRecv, '0.00', '初始客户应收=0');

    // Step 2: 进货入库(100件)
    echo "Step 2: 进货入库(100件) ... ";
    $supplyRes = httpRequest('POST', "{$BASE_URL}/api/supply/publish", [
        'supplier_id'  => $s1_supId,
        'warehouse_id' => $s1_whId,
        'goods' => [[
            'goods_id' => $s1_goodsId, 'name' => 'FLOW_S1_商品',
            'number' => 100, 'price' => 10, 'units' => '个',
        ]],
    ], $token);
    $s1_supplyId = extractId($supplyRes);
    echo $s1_supplyId ? "OK\n" : "FAIL\n";

    // 验证入库后库存
    $goodsD1 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s1_goodsId], $token);
    $stockAfterIn = extractStock($goodsD1);
    assert_eq($stockAfterIn, '100.00', '入库后库存=100');

    // Step 3: 发布销售单(10件×50元)
    echo "Step 3: 发布销售单(10件×50) ... ";
    $orderRes = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
        'customer_id'  => $s1_custId,
        'warehouse_id' => $s1_whId,
        'goods' => [[
            'goods_id' => $s1_goodsId, 'name' => 'FLOW_S1_商品',
            'number' => 10, 'price' => 50, 'units' => '个',
        ]],
    ], $token);
    $s1_orderId = extractId($orderRes);
    echo $s1_orderId ? "OK\n" : "FAIL\n";

    // Step 4: 验证库存和应收
    echo "Step 4: 验证库存和应收 ... \n";
    $orderD1 = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $s1_orderId], $token);
    $orderMoney1 = $orderD1['data']['order_money'] ?? null;

    $goodsD2 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s1_goodsId], $token);
    $stockAfterSale = extractStock($goodsD2);

    $custD1 = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s1_custId], $token);
    $recvAfterSale = extractReceivable($custD1);

    assert_eq($orderMoney1, '500.00', '销售单金额=500');
    assert_eq($recvAfterSale, '500.00', '客户应收增加500');
    assert_eq($stockAfterSale, '90.00', '商品库存减少到90');

    // Step 5: 编辑销售单(数量改为5)
    echo "Step 5: 编辑销售单(数量→5) ... ";
    $editRes = httpRequest('POST', "{$BASE_URL}/api/order/edit", [
        'id'           => $s1_orderId,
        'customer_id'  => $s1_custId,
        'warehouse_id' => $s1_whId,
        'goods' => [[
            'goods_id' => $s1_goodsId, 'name' => 'FLOW_S1_商品',
            'number' => 5, 'price' => 50, 'units' => '个',
        ]],
    ], $token);
    $editOk = ($editRes['code'] ?? 0) == 1;
    echo $editOk ? "OK\n" : "FAIL\n";

    // Step 6: 验证编辑后库存和应收
    echo "Step 6: 验证编辑后库存和应收 ... \n";
    $orderD2 = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $s1_orderId], $token);
    $orderMoney2 = $orderD2['data']['order_money'] ?? null;

    $goodsD3 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s1_goodsId], $token);
    $stockAfterEdit = extractStock($goodsD3);

    $custD2 = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s1_custId], $token);
    $recvAfterEdit = extractReceivable($custD2);

    assert_eq($orderMoney2, '250.00', '编辑后金额=250');
    assert_eq($recvAfterEdit, '250.00', '编辑后应收=250');
    assert_eq($stockAfterEdit, '95.00', '编辑后库存=95');

    // Step 7: 删除销售单
    echo "Step 7: 删除销售单 ... ";
    $delRes = httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $s1_orderId], $token);
    $delOk = ($delRes['code'] ?? 0) == 1;
    echo $delOk ? "OK\n" : "FAIL\n";

    // Step 8: 验证删除后库存和应收
    echo "Step 8: 验证删除后库存和应收 ... \n";
    $goodsD4 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s1_goodsId], $token);
    $stockAfterDel = extractStock($goodsD4);

    $custD3 = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s1_custId], $token);
    $recvAfterDel = extractReceivable($custD3);

    assert_eq($recvAfterDel, '0.00', '删除后应收=0');
    assert_eq($stockAfterDel, '100.00', '删除后库存=100');
} else {
    echo "[SKIP] 场景1: 前置数据创建失败\n";
}

// 清理场景1
echo "Step 9: 清理数据 ... ";
if ($s1_orderId)  httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $s1_orderId], $token);
if ($s1_supplyId) httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $s1_supplyId], $token);
if ($s1_goodsId)  httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $s1_goodsId], $token);
if ($s1_custId)   httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $s1_custId], $token);
if ($s1_supId)    httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $s1_supId], $token);
if ($s1_whId)     httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $s1_whId], $token);
if ($s1_unitId)   httpRequest('DELETE', "{$BASE_URL}/api/units/del", ['id' => $s1_unitId], $token);
echo "OK\n";

// ══════════════════════════════════════════════
// 场景 2: 多商品行金额汇总
// ══════════════════════════════════════════════
echo "\n--- 场景2: 多商品行金额汇总 ---\n";

$s2_whId = null; $s2_custId = null; $s2_supId = null;
$s2_goodsAId = null; $s2_goodsBId = null;
$s2_supplyId = null; $s2_orderId = null;

// Step 1: 初始化数据
echo "Step 1: 初始化数据 ... ";

$whRes2 = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", [
    'name' => 'FLOW_S2_仓库', 'address' => 'FLOW_S2_地址',
], $token);
$s2_whId = extractId($whRes2);

$custRes2 = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => 'FLOW_S2_客户', 'contact' => 'FLOW_S2_联系人', 'phone' => '13800020001',
], $token);
$s2_custId = extractId($custRes2);

$supRes2 = httpRequest('POST', "{$BASE_URL}/api/supplier/add", [
    'supplier_name' => 'FLOW_S2_供应商', 'contact' => 'FLOW_S2_供应联系人', 'phone' => '13800020002',
], $token);
$s2_supId = extractId($supRes2);

$goodsARes = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name' => 'FLOW_S2_商品A', 'product_code' => 'FLOW_S2_A', 'price' => 100, 'units' => '个',
], $token);
$s2_goodsAId = extractId($goodsARes);

$goodsBRes = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name' => 'FLOW_S2_商品B', 'product_code' => 'FLOW_S2_B', 'price' => 200, 'units' => '个',
], $token);
$s2_goodsBId = extractId($goodsBRes);

$s2_initOk = ($s2_whId && $s2_custId && $s2_supId && $s2_goodsAId && $s2_goodsBId);
echo $s2_initOk ? "OK\n" : "FAIL\n";

if ($s2_initOk) {
    // Step 2: 进货入库
    echo "Step 2: 进货入库 ... ";
    $supplyRes2 = httpRequest('POST', "{$BASE_URL}/api/supply/publish", [
        'supplier_id'  => $s2_supId,
        'warehouse_id' => $s2_whId,
        'goods' => [
            [
                'goods_id' => $s2_goodsAId, 'name' => 'FLOW_S2_商品A',
                'number' => 100, 'price' => 50, 'units' => '个',
            ],
            [
                'goods_id' => $s2_goodsBId, 'name' => 'FLOW_S2_商品B',
                'number' => 100, 'price' => 100, 'units' => '个',
            ],
        ],
    ], $token);
    $s2_supplyId = extractId($supplyRes2);
    echo $s2_supplyId ? "OK\n" : "FAIL\n";

    // 验证入库后库存
    $gAD1 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s2_goodsAId], $token);
    $stockA_in = extractStock($gAD1);
    assert_eq($stockA_in, '100.00', '商品A入库后库存=100');

    $gBD1 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s2_goodsBId], $token);
    $stockB_in = extractStock($gBD1);
    assert_eq($stockB_in, '100.00', '商品B入库后库存=100');

    // Step 3: 创建多商品行销售单 A(5×100) + B(3×200) = 500+600 = 1100
    echo "Step 3: 创建多商品行销售单 ... ";
    $orderRes2 = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
        'customer_id'  => $s2_custId,
        'warehouse_id' => $s2_whId,
        'goods' => [
            [
                'goods_id' => $s2_goodsAId, 'name' => 'FLOW_S2_商品A',
                'number' => 5, 'price' => 100, 'units' => '个',
            ],
            [
                'goods_id' => $s2_goodsBId, 'name' => 'FLOW_S2_商品B',
                'number' => 3, 'price' => 200, 'units' => '个',
            ],
        ],
    ], $token);
    $s2_orderId = extractId($orderRes2);
    echo $s2_orderId ? "OK\n" : "FAIL\n";

    // Step 4: 验证总金额和各商品库存
    echo "Step 4: 验证总金额和库存 ... \n";
    $orderD2 = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $s2_orderId], $token);
    $orderMoney2 = $orderD2['data']['order_money'] ?? null;
    assert_eq($orderMoney2, '1100.00', '多商品行总金额=1100');

    $gAD2 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s2_goodsAId], $token);
    $stockA_after = extractStock($gAD2);
    assert_eq($stockA_after, '95.00', '商品A库存=95(扣5)');

    $gBD2 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s2_goodsBId], $token);
    $stockB_after = extractStock($gBD2);
    assert_eq($stockB_after, '97.00', '商品B库存=97(扣3)');

    $custD2 = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s2_custId], $token);
    $recvS2 = extractReceivable($custD2);
    assert_eq($recvS2, '1100.00', '客户应收=1100');
} else {
    echo "[SKIP] 场景2: 前置数据创建失败\n";
}

// 清理场景2
echo "Step 5: 清理数据 ... ";
if ($s2_orderId)  httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $s2_orderId], $token);
if ($s2_supplyId) httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $s2_supplyId], $token);
if ($s2_goodsAId) httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $s2_goodsAId], $token);
if ($s2_goodsBId) httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $s2_goodsBId], $token);
if ($s2_custId)   httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $s2_custId], $token);
if ($s2_supId)    httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $s2_supId], $token);
if ($s2_whId)     httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $s2_whId], $token);
echo "OK\n";

// ══════════════════════════════════════════════
// 场景 3: 金额精度验证（bcmath 边界）
// ══════════════════════════════════════════════
echo "\n--- 场景3: 金额精度验证 ---\n";

$s3_whId = null; $s3_custId = null; $s3_supId = null;
$s3_goods1Id = null; $s3_goods2Id = null; $s3_goods3Id = null;
$s3_supplyId = null; $s3_order1Id = null; $s3_order2Id = null;

// Step 1: 初始化数据（3个不同单价商品）
echo "Step 1: 初始化数据 ... ";

$whRes3 = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", [
    'name' => 'FLOW_S3_仓库', 'address' => 'FLOW_S3_地址',
], $token);
$s3_whId = extractId($whRes3);

$custRes3 = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => 'FLOW_S3_客户', 'contact' => 'FLOW_S3_联系人', 'phone' => '13800030001',
], $token);
$s3_custId = extractId($custRes3);

$supRes3 = httpRequest('POST', "{$BASE_URL}/api/supplier/add", [
    'supplier_name' => 'FLOW_S3_供应商', 'contact' => 'FLOW_S3_供应联系人', 'phone' => '13800030002',
], $token);
$s3_supId = extractId($supRes3);

$g1Res = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name' => 'FLOW_S3_商品_0.1', 'product_code' => 'FLOW_S3_01', 'price' => 0.1, 'units' => '个',
], $token);
$s3_goods1Id = extractId($g1Res);

$g2Res = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name' => 'FLOW_S3_商品_0.2', 'product_code' => 'FLOW_S3_02', 'price' => 0.2, 'units' => '个',
], $token);
$s3_goods2Id = extractId($g2Res);

$g3Res = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name' => 'FLOW_S3_商品_0.3', 'product_code' => 'FLOW_S3_03', 'price' => 0.3, 'units' => '个',
], $token);
$s3_goods3Id = extractId($g3Res);

$s3_initOk = ($s3_whId && $s3_custId && $s3_supId && $s3_goods1Id && $s3_goods2Id && $s3_goods3Id);
echo $s3_initOk ? "OK\n" : "FAIL\n";

if ($s3_initOk) {
    // Step 2: 进货入库
    echo "Step 2: 进货入库 ... ";
    $supplyRes3 = httpRequest('POST', "{$BASE_URL}/api/supply/publish", [
        'supplier_id'  => $s3_supId,
        'warehouse_id' => $s3_whId,
        'goods' => [
            ['goods_id' => $s3_goods1Id, 'name' => 'FLOW_S3_商品_0.1', 'number' => 100, 'price' => 0.1, 'units' => '个'],
            ['goods_id' => $s3_goods2Id, 'name' => 'FLOW_S3_商品_0.2', 'number' => 100, 'price' => 0.1, 'units' => '个'],
            ['goods_id' => $s3_goods3Id, 'name' => 'FLOW_S3_商品_0.3', 'number' => 100, 'price' => 0.1, 'units' => '个'],
        ],
    ], $token);
    $s3_supplyId = extractId($supplyRes3);
    echo $s3_supplyId ? "OK\n" : "FAIL\n";

    // Step 3: 销售10件×0.1=1.00，验证不是0.999...
    echo "Step 3: 销售10件×0.1 ... \n";
    $order1Res = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
        'customer_id'  => $s3_custId,
        'warehouse_id' => $s3_whId,
        'goods' => [[
            'goods_id' => $s3_goods1Id, 'name' => 'FLOW_S3_商品_0.1',
            'number' => 10, 'price' => 0.1, 'units' => '个',
        ]],
    ], $token);
    $s3_order1Id = extractId($order1Res);

    if ($s3_order1Id) {
        $o1Detail = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $s3_order1Id], $token);
        $o1Money = $o1Detail['data']['order_money'] ?? null;
        // 精确验证：必须是 "1.00"，不是 "0.99"、"0.999..." 等浮点误差
        assert_eq($o1Money, '1.00', '单商品精度: 10×0.1=1.00(非0.999...)');

        // 验证库存扣减
        $g1D = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s3_goods1Id], $token);
        $stock1 = extractStock($g1D);
        assert_eq($stock1, '90.00', '商品_0.1库存=90');

        // 验证应收
        $custD3 = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s3_custId], $token);
        $recv3 = extractReceivable($custD3);
        assert_eq($recv3, '1.00', '客户应收=1.00');
    }

    // Step 4: 创建多商品行精度销售单 0.1×10 + 0.2×10 + 0.3×10 = 1+2+3 = 6.00
    echo "Step 4: 多商品行精度(0.1+0.2+0.3各10件) ... \n";
    $order2Res = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
        'customer_id'  => $s3_custId,
        'warehouse_id' => $s3_whId,
        'goods' => [
            ['goods_id' => $s3_goods1Id, 'name' => 'FLOW_S3_商品_0.1', 'number' => 10, 'price' => 0.1, 'units' => '个'],
            ['goods_id' => $s3_goods2Id, 'name' => 'FLOW_S3_商品_0.2', 'number' => 10, 'price' => 0.2, 'units' => '个'],
            ['goods_id' => $s3_goods3Id, 'name' => 'FLOW_S3_商品_0.3', 'number' => 10, 'price' => 0.3, 'units' => '个'],
        ],
    ], $token);
    $s3_order2Id = extractId($order2Res);

    if ($s3_order2Id) {
        $o2Detail = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $s3_order2Id], $token);
        $o2Money = $o2Detail['data']['order_money'] ?? null;
        // 精确验证：必须是 "6.00"，不是 "5.99" 等浮点误差
        assert_eq($o2Money, '6.00', '多行精度: 0.1×10+0.2×10+0.3×10=6.00');

        // 验证各商品库存
        $g1D2 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s3_goods1Id], $token);
        assert_eq(extractStock($g1D2), '80.00', '商品_0.1累计库存=80');

        $g2D2 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s3_goods2Id], $token);
        assert_eq(extractStock($g2D2), '90.00', '商品_0.2累计库存=90');

        $g3D2 = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s3_goods3Id], $token);
        assert_eq(extractStock($g3D2), '90.00', '商品_0.3累计库存=90');

        // 验证累计应收 (第一单1.00 + 第二单6.00 = 7.00)
        $custD4 = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s3_custId], $token);
        $recv4 = extractReceivable($custD4);
        assert_eq($recv4, '7.00', '累计客户应收=7.00');
    }
} else {
    echo "[SKIP] 场景3: 前置数据创建失败\n";
}

// 清理场景3
echo "Step 5: 清理数据 ... ";
if ($s3_order1Id)  httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $s3_order1Id], $token);
if ($s3_order2Id)  httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $s3_order2Id], $token);
if ($s3_supplyId)  httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $s3_supplyId], $token);
if ($s3_goods1Id)  httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $s3_goods1Id], $token);
if ($s3_goods2Id)  httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $s3_goods2Id], $token);
if ($s3_goods3Id)  httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $s3_goods3Id], $token);
if ($s3_custId)    httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $s3_custId], $token);
if ($s3_supId)     httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $s3_supId], $token);
if ($s3_whId)      httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $s3_whId], $token);
echo "OK\n";

// ─────────────────────────────────────────────
// 汇总输出
// ─────────────────────────────────────────────
echo "\n=== 结果: {$passTests}/{$totalTests} 通过 ===\n";

exit($failTests > 0 ? 1 : 0);
