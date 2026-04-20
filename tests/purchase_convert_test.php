<?php
/**
 * BeiMi JXC 订货单转销售单端到端测试
 * 验证订货单状态流转及转销售单的完整链路
 * 使用方法：php tests/purchase_convert_test.php [BASE_URL]
 *
 * 状态常量（来自 PurchaseOrder 模型）：
 *   STATUS_DRAFT=1, STATUS_SENT=2, STATUS_RECEIVED=3,
 *   STATUS_DELIVERED=4, STATUS_COMPLETED=5, STATUS_CANCELLED=6
 */

$BASE_URL = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8787';
$RUN_ID = date('YmdHis') . '_' . random_int(1000, 9999);

// ─────────────────────────────────────────────
// 全局计数器
// ─────────────────────────────────────────────
$totalTests = 0;
$passTests  = 0;
$failTests  = 0;

// ─────────────────────────────────────────────
// 辅助函数
// ─────────────────────────────────────────────

function testName(string $name): string
{
    global $RUN_ID;
    return $name . '_' . $RUN_ID;
}

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
 * 精确字符串比较断言（金额/数值专用）
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
 * 字符串全等断言
 */
function assert_str($actual, $expected, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    $a = (string)$actual;
    $e = (string)$expected;
    if ($a === $e) {
        echo "[PASS] {$testName} (值={$a})\n";
        $passTests++;
        return true;
    }

    echo "[FAIL] {$testName}: 实际=\"{$a}\", 期望=\"{$e}\"\n";
    $failTests++;
    return false;
}

/**
 * 断言整型相等
 */
function assert_int($actual, $expected, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    if ((int)$actual === (int)$expected) {
        echo "[PASS] {$testName} (值=" . (int)$actual . ")\n";
        $passTests++;
        return true;
    }

    echo "[FAIL] {$testName}: 实际=" . (int)$actual . ", 期望=" . (int)$expected . "\n";
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
 * 从订货单详情中提取状态文本（status 字段为字符串如 "sent"）
 */
function extractPurchaseStatus(array $response): ?string
{
    return $response['data']['status'] ?? null;
}

/**
 * 格式化为2位小数字符串
 */
function bc2($value): string
{
    return number_format((float)$value, 2, '.', '');
}

// ─────────────────────────────────────────────
// 清理辅助函数
// ─────────────────────────────────────────────

function cleanup(array $ids, string $BASE_URL, string $token): void
{
    if (!empty($ids['salesOrderId'])) {
        httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $ids['salesOrderId']], $token);
    }
    if (!empty($ids['purchaseOrderId'])) {
        // 只能删除 draft 状态，其他状态先取消
        httpRequest('POST', "{$BASE_URL}/api/purchase/cancel", [
            'id' => $ids['purchaseOrderId'], 'cancel_reason' => 'PCT_清理',
        ], $token);
        httpRequest('DELETE', "{$BASE_URL}/api/purchase/remove", ['id' => $ids['purchaseOrderId']], $token);
    }
    if (!empty($ids['supplyId'])) {
        httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $ids['supplyId']], $token);
    }
    if (!empty($ids['goodsId'])) {
        httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $ids['goodsId']], $token);
    }
    if (!empty($ids['custId'])) {
        httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $ids['custId']], $token);
    }
    if (!empty($ids['supId'])) {
        httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $ids['supId']], $token);
    }
    if (!empty($ids['whId'])) {
        httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $ids['whId']], $token);
    }
}

// ══════════════════════════════════════════════
// 开始测试
// ══════════════════════════════════════════════
echo "=== BeiMi JXC 订货单转销售单测试 ===\n";
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
// 场景 1: 订货单正常转销售单
// ══════════════════════════════════════════════
echo "\n--- 场景1: 正常转销售 ---\n";

$s1 = [
    'whId' => null, 'custId' => null, 'supId' => null,
    'goodsId' => null, 'supplyId' => null,
    'purchaseOrderId' => null, 'salesOrderId' => null,
];

// Step 1: 初始化数据
echo "Step 1: 初始化数据 ... ";

$whRes = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", [
    'name' => testName('PCT_S1_仓库'), 'address' => 'PCT_S1_地址',
], $token);
$s1['whId'] = extractId($whRes);

$custRes = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => testName('PCT_S1_客户'), 'contact' => 'PCT_S1_联系人', 'phone' => '13810010001',
], $token);
$s1['custId'] = extractId($custRes);

$supRes = httpRequest('POST', "{$BASE_URL}/api/supplier/add", [
    'supplier_name' => testName('PCT_S1_供应商'), 'contact' => 'PCT_S1_供应联系人', 'phone' => '13810010002',
], $token);
$s1['supId'] = extractId($supRes);

$goodsRes = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name' => testName('PCT_S1_商品'), 'product_code' => testName('PCT_S1_001'), 'price' => 50, 'units' => '个',
], $token);
$s1['goodsId'] = extractId($goodsRes);

$s1_initOk = ($s1['whId'] && $s1['custId'] && $s1['supId'] && $s1['goodsId']);
echo $s1_initOk ? "OK\n" : "FAIL (whId={$s1['whId']}, custId={$s1['custId']}, supId={$s1['supId']}, goodsId={$s1['goodsId']})\n";

if ($s1_initOk) {
    // Step 2: 进货入库（使商品有库存：入库20件）
    echo "Step 2: 进货入库(20件) ... ";
    $supplyRes = httpRequest('POST', "{$BASE_URL}/api/supply/publish", [
        'supplier_id'  => $s1['supId'],
        'warehouse_id' => $s1['whId'],
        'goods' => [[
            'goods_id' => $s1['goodsId'], 'name' => 'PCT_S1_商品',
            'number' => 20, 'price' => 30, 'units' => '个',
        ]],
    ], $token);
    $s1['supplyId'] = extractId($supplyRes);
    $supplyOk = ($s1['supplyId'] !== null);
    echo $supplyOk ? "OK\n" : "FAIL\n";

    // 记录入库后库存
    $goodsD_before = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s1['goodsId']], $token);
    $stockBeforeSale = extractStock($goodsD_before);
    echo "  [INFO] 入库后库存: " . ($stockBeforeSale ?? 'N/A') . "\n";

    // 记录创建订货单前客户应收
    $custD_before = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s1['custId']], $token);
    $recvBefore = extractReceivable($custD_before);
    echo "  [INFO] 初始客户应收: " . ($recvBefore ?? 'N/A') . "\n";

    // Step 3: 创建订货单（数量=10，单价=50）
    echo "Step 3: 创建订货单(qty=10, price=50) ... ";
    $poRes = httpRequest('POST', "{$BASE_URL}/api/purchase/publish", [
        'customer_id'  => $s1['custId'],
        'warehouse_id' => $s1['whId'],
        'goods' => [[
            'goods_id' => $s1['goodsId'], 'name' => 'PCT_S1_商品',
            'number' => 10, 'price' => 50, 'units' => '个',
        ]],
    ], $token);
    $s1['purchaseOrderId'] = extractId($poRes);
    $poOk = ($s1['purchaseOrderId'] !== null);
    echo $poOk ? "OK\n" : "FAIL\n";

    if ($poOk) {
        // 验证初始状态为 draft
        $poD0 = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s1['purchaseOrderId']], $token);
        $status0 = extractPurchaseStatus($poD0);
        assert_str($status0, 'draft', '订货单初始状态=draft');

        // Step 4: draft → sent（调用 confirm，自动推进到下一状态）
        echo "Step 4: draft→sent ... ";
        $confirm1Res = httpRequest('POST', "{$BASE_URL}/api/purchase/confirm", [
            'id' => $s1['purchaseOrderId'],
        ], $token);
        $c1ok = assert_code($confirm1Res, 1, 'confirm draft→sent');

        if ($c1ok) {
            $poD1 = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s1['purchaseOrderId']], $token);
            $status1 = extractPurchaseStatus($poD1);
            assert_str($status1, 'sent', '订货单状态变为 sent');
        }

        // Step 5: sent → received（再次调用 confirm）
        echo "Step 5: sent→received ... ";
        $confirm2Res = httpRequest('POST', "{$BASE_URL}/api/purchase/confirm", [
            'id' => $s1['purchaseOrderId'],
        ], $token);
        $c2ok = assert_code($confirm2Res, 1, 'confirm sent→received');

        if ($c2ok) {
            $poD2 = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s1['purchaseOrderId']], $token);
            $status2 = extractPurchaseStatus($poD2);
            assert_str($status2, 'received', '订货单状态变为 received');
        }

        // Step 6: 转销售单
        echo "Step 6: 转销售单 ... ";
        $convertRes = httpRequest('POST', "{$BASE_URL}/api/purchase/convert-to-sales", [
            'id'           => $s1['purchaseOrderId'],
            'warehouse_id' => $s1['whId'],
        ], $token);
        $convertOk = assert_code($convertRes, 1, '转销售单成功');

        $s1['salesOrderId'] = $convertRes['data']['sales_order_id'] ?? null;
        $salesOrderSn       = $convertRes['data']['sales_order_sn'] ?? null;
        echo "  [INFO] 新销售单 ID={$s1['salesOrderId']}, SN={$salesOrderSn}\n";

        // 验证返回了销售单 ID
        $totalTests++;
        if ($s1['salesOrderId'] > 0) {
            echo "[PASS] 转单返回有效 sales_order_id={$s1['salesOrderId']}\n";
            $passTests++;
        } else {
            echo "[FAIL] 转单未返回有效 sales_order_id\n";
            $failTests++;
        }

        // Step 7: 验证销售单数据一致性
        echo "Step 7: 验证销售单数据一致 ... \n";
        if ($s1['salesOrderId']) {
            $salesDetail = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $s1['salesOrderId']], $token);
            assert_code($salesDetail, 1, '查询新销售单详情');

            // 验证客户一致
            $salesCustId = $salesDetail['data']['customer_id'] ?? null;
            assert_int($salesCustId, $s1['custId'], '销售单客户ID与订货单一致');

            // 验证销售单金额 = 10 × 50 = 500
            $salesMoney = $salesDetail['data']['order_money'] ?? null;
            assert_eq($salesMoney, '500.00', '销售单金额=500(10×50)');

            // 验证商品行：数量=10，单价=50
            $salesGoods = $salesDetail['data']['goods'] ?? [];
            $totalTests++;
            if (!empty($salesGoods)) {
                $gRow = $salesGoods[0];
                $gNum   = (float)($gRow['number'] ?? 0);
                $gPrice = (float)($gRow['price'] ?? $gRow['units_money'] ?? 0);
                $numOk   = abs($gNum - 10) < 0.01;
                $priceOk = abs($gPrice - 50) < 0.01;
                if ($numOk && $priceOk) {
                    echo "[PASS] 销售单商品行 数量=10, 单价=50\n";
                    $passTests++;
                } else {
                    echo "[FAIL] 销售单商品行 数量={$gNum}(期望10), 单价={$gPrice}(期望50)\n";
                    $failTests++;
                }
            } else {
                echo "[FAIL] 销售单商品行为空\n";
                $failTests++;
            }
        }

        // Step 7b: 验证应收和库存
        echo "Step 7: 验证应收和库存 ... \n";

        // 客户应收应增加 500（10×50）
        $custD_after = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s1['custId']], $token);
        $recvAfter = extractReceivable($custD_after);
        echo "  [INFO] 转单后客户应收: " . ($recvAfter ?? 'N/A') . "\n";
        if ($recvBefore !== null && $recvAfter !== null) {
            $recvDiff = bc2((float)$recvAfter - (float)$recvBefore);
            assert_eq($recvDiff, '500.00', '转单后客户应收增加500');
        } else {
            echo "[SKIP] 客户应收验证：无法获取数据\n";
        }

        // 库存应减少10（销售出库）
        $goodsD_after = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s1['goodsId']], $token);
        $stockAfterSale = extractStock($goodsD_after);
        echo "  [INFO] 转单后商品库存: " . ($stockAfterSale ?? 'N/A') . "\n";
        if ($stockBeforeSale !== null && $stockAfterSale !== null) {
            $stockDiff = bc2((float)$stockAfterSale - (float)$stockBeforeSale);
            assert_eq($stockDiff, '-10.00', '转单后库存减少10');
        } else {
            echo "[SKIP] 库存验证：无法获取数据\n";
        }

        // 验证订货单状态变为 completed
        $poD_final = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s1['purchaseOrderId']], $token);
        $statusFinal = extractPurchaseStatus($poD_final);
        assert_str($statusFinal, 'completed', '订货单转单后状态=completed');

        // Step 8: 重复转单应被拒绝，避免双击/并发重试生成第二张销售单
        echo "Step 8: 重复转销售单防护 ... ";
        $duplicateConvertRes = httpRequest('POST', "{$BASE_URL}/api/purchase/convert-to-sales", [
            'id'           => $s1['purchaseOrderId'],
            'warehouse_id' => $s1['whId'],
        ], $token);
        assert_code($duplicateConvertRes, 0, 'completed 订货单重复转销售单被拒绝');
    }
} else {
    echo "[SKIP] 场景1: 前置数据创建失败\n";
}

// 清理场景1
echo "清理场景1数据 ... ";
if ($s1['salesOrderId']) {
    httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $s1['salesOrderId']], $token);
}
// 已完成的订货单无法删除，保留（无害数据）
if ($s1['supplyId']) {
    httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $s1['supplyId']], $token);
}
if ($s1['goodsId']) {
    httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $s1['goodsId']], $token);
}
// 客户有销售单关联可能无法删除，尝试
if ($s1['custId']) {
    httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $s1['custId']], $token);
}
if ($s1['supId']) {
    httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $s1['supId']], $token);
}
if ($s1['whId']) {
    httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $s1['whId']], $token);
}
echo "OK\n";

// ══════════════════════════════════════════════
// 场景 2: 订货单取消
// ══════════════════════════════════════════════
echo "\n--- 场景2: 取消订货单 ---\n";

$s2 = [
    'whId' => null, 'custId' => null, 'supId' => null,
    'goodsId' => null, 'purchaseOrderId' => null,
];

// Step 1: 初始化数据
echo "Step 1: 初始化数据 ... ";

$whRes2 = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", [
    'name' => testName('PCT_S2_仓库'), 'address' => 'PCT_S2_地址',
], $token);
$s2['whId'] = extractId($whRes2);

$custRes2 = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => testName('PCT_S2_客户'), 'contact' => 'PCT_S2_联系人', 'phone' => '13810020001',
], $token);
$s2['custId'] = extractId($custRes2);

$goodsRes2 = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name' => testName('PCT_S2_商品'), 'product_code' => testName('PCT_S2_001'), 'price' => 30, 'units' => '件',
], $token);
$s2['goodsId'] = extractId($goodsRes2);

$s2_initOk = ($s2['whId'] && $s2['custId'] && $s2['goodsId']);
echo $s2_initOk ? "OK\n" : "FAIL\n";

if ($s2_initOk) {
    // 记录初始库存和应收
    $goodsD2_before = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s2['goodsId']], $token);
    $stock2_before  = extractStock($goodsD2_before);

    $custD2_before = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s2['custId']], $token);
    $recv2_before  = extractReceivable($custD2_before);

    echo "  [INFO] 初始库存={$stock2_before}, 初始应收={$recv2_before}\n";

    // Step 2: 创建订货单
    echo "Step 2: 创建订货单(draft) ... ";
    $po2Res = httpRequest('POST', "{$BASE_URL}/api/purchase/publish", [
        'customer_id'  => $s2['custId'],
        'warehouse_id' => $s2['whId'],
        'goods' => [[
            'goods_id' => $s2['goodsId'], 'name' => 'PCT_S2_商品',
            'number' => 5, 'price' => 30, 'units' => '件',
        ]],
    ], $token);
    $s2['purchaseOrderId'] = extractId($po2Res);
    $po2ok = ($s2['purchaseOrderId'] !== null);
    echo $po2ok ? "OK\n" : "FAIL\n";

    if ($po2ok) {
        // 验证初始状态 = draft
        $poD2_0 = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s2['purchaseOrderId']], $token);
        assert_str(extractPurchaseStatus($poD2_0), 'draft', '场景2-订货单初始状态=draft');

        // Step 3: 调用取消接口
        echo "Step 3: 取消订货单 ... ";
        $cancelRes = httpRequest('POST', "{$BASE_URL}/api/purchase/cancel", [
            'id'            => $s2['purchaseOrderId'],
            'cancel_reason' => 'PCT_场景2测试取消',
        ], $token);
        assert_code($cancelRes, 1, '取消订货单成功');

        // 验证状态变为 cancelled
        $poD2_cancelled = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s2['purchaseOrderId']], $token);
        assert_str(extractPurchaseStatus($poD2_cancelled), 'cancelled', '取消后状态=cancelled');

        // Step 4: 验证无库存变化
        echo "Step 4: 验证无库存变化 ... \n";
        $goodsD2_after = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $s2['goodsId']], $token);
        $stock2_after  = extractStock($goodsD2_after);
        echo "  [INFO] 取消后库存={$stock2_after}\n";
        if ($stock2_before !== null && $stock2_after !== null) {
            assert_eq($stock2_after, $stock2_before, '取消后库存无变化');
        } else {
            echo "[SKIP] 库存对比：无法获取数据\n";
        }

        // 验证无财务变化
        $custD2_after = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $s2['custId']], $token);
        $recv2_after  = extractReceivable($custD2_after);
        echo "  [INFO] 取消后应收={$recv2_after}\n";
        if ($recv2_before !== null && $recv2_after !== null) {
            assert_eq($recv2_after, $recv2_before, '取消后客户应收无变化');
        } else {
            echo "[SKIP] 应收对比：无法获取数据\n";
        }

        // Step 5: 验证非法转移被拒绝（对 cancelled 状态执行 confirm）
        echo "Step 5: 对 cancelled 状态执行 confirm（期望失败） ... ";
        $illegalConfirmRes = httpRequest('POST', "{$BASE_URL}/api/purchase/confirm", [
            'id' => $s2['purchaseOrderId'],
        ], $token);
        assert_code($illegalConfirmRes, 0, 'cancelled 状态 confirm 被拒绝（期望失败 code=0）');
    }
} else {
    echo "[SKIP] 场景2: 前置数据创建失败\n";
}

// 清理场景2
echo "清理场景2数据 ... ";
// 已取消的订货单无法再删除，尝试（会失败，忽略）
if ($s2['goodsId']) {
    httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $s2['goodsId']], $token);
}
if ($s2['custId']) {
    httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $s2['custId']], $token);
}
if ($s2['whId']) {
    httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $s2['whId']], $token);
}
echo "OK\n";

// ══════════════════════════════════════════════
// 场景 3: 非法状态转移
// ══════════════════════════════════════════════
echo "\n--- 场景3: 非法状态转移 ---\n";

$s3 = [
    'whId' => null, 'custId' => null,
    'goodsId' => null,
    'poId_a' => null, 'poId_b' => null,
];

// Step 1: 初始化数据
echo "Step 1: 初始化数据 ... ";

$whRes3 = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", [
    'name' => testName('PCT_S3_仓库'), 'address' => 'PCT_S3_地址',
], $token);
$s3['whId'] = extractId($whRes3);

$custRes3 = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => testName('PCT_S3_客户'), 'contact' => 'PCT_S3_联系人', 'phone' => '13810030001',
], $token);
$s3['custId'] = extractId($custRes3);

$goodsRes3 = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name' => testName('PCT_S3_商品'), 'product_code' => testName('PCT_S3_001'), 'price' => 20, 'units' => '套',
], $token);
$s3['goodsId'] = extractId($goodsRes3);

$s3_initOk = ($s3['whId'] && $s3['custId'] && $s3['goodsId']);
echo $s3_initOk ? "OK\n" : "FAIL\n";

if ($s3_initOk) {
    // Step 2: 创建订货单A（draft），直接尝试转销售单（期望失败）
    echo "Step 2: draft 状态直接转销售单（期望失败） ... ";
    $poARes = httpRequest('POST', "{$BASE_URL}/api/purchase/publish", [
        'customer_id'  => $s3['custId'],
        'warehouse_id' => $s3['whId'],
        'goods' => [[
            'goods_id' => $s3['goodsId'], 'name' => 'PCT_S3_商品',
            'number' => 3, 'price' => 20, 'units' => '套',
        ]],
    ], $token);
    $s3['poId_a'] = extractId($poARes);

    if ($s3['poId_a']) {
        // 验证状态为 draft
        $poAD0 = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s3['poId_a']], $token);
        $statusA = extractPurchaseStatus($poAD0);
        echo "  [INFO] 订货单A状态={$statusA}\n";

        // 直接转销售单（draft → 不允许）
        $convertARes = httpRequest('POST', "{$BASE_URL}/api/purchase/convert-to-sales", [
            'id'           => $s3['poId_a'],
            'warehouse_id' => $s3['whId'],
        ], $token);
        assert_code($convertARes, 0, 'draft 状态转销售单被拒绝（期望失败）');
    } else {
        echo "[SKIP] 场景3-步骤2: 订货单A创建失败\n";
    }

    // Step 3: 创建订货单B（draft → sent），尝试转销售单
    //   根据 PurchaseOrderLogic::convertToSalesOrder，只允许 SENT(2) 或 RECEIVED(3) 转单
    //   但本测试验证 sent 状态是否可以转（代码允许 sent 转），或者需 received
    //   实际代码：!in_array($currentStatus, [STATUS_SENT, STATUS_RECEIVED]) 才拒绝
    //   即 sent=2 和 received=3 都被允许，这里测试 draft 直接跳过到 sent 再转
    echo "Step 3: sent 状态可以转销售单验证（代码允许 sent/received 转单） ... ";
    $poBRes = httpRequest('POST', "{$BASE_URL}/api/purchase/publish", [
        'customer_id'  => $s3['custId'],
        'warehouse_id' => $s3['whId'],
        'goods' => [[
            'goods_id' => $s3['goodsId'], 'name' => 'PCT_S3_商品',
            'number' => 2, 'price' => 20, 'units' => '套',
        ]],
    ], $token);
    $s3['poId_b'] = extractId($poBRes);

    if ($s3['poId_b']) {
        // draft → sent
        $confirmB1Res = httpRequest('POST', "{$BASE_URL}/api/purchase/confirm", [
            'id' => $s3['poId_b'],
        ], $token);
        assert_code($confirmB1Res, 1, '场景3-订货单B draft→sent');

        $poBD1 = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s3['poId_b']], $token);
        $statusB = extractPurchaseStatus($poBD1);
        echo "  [INFO] 订货单B状态={$statusB}\n";
        assert_str($statusB, 'sent', '场景3-订货单B状态=sent');

        // sent 状态转销售单（代码允许 sent 状态转单，此处应成功）
        $convertBRes = httpRequest('POST', "{$BASE_URL}/api/purchase/convert-to-sales", [
            'id'           => $s3['poId_b'],
            'warehouse_id' => $s3['whId'],
        ], $token);
        // 根据 PurchaseOrderLogic 代码：允许 STATUS_SENT 转，应成功
        assert_code($convertBRes, 1, 'sent 状态可以转销售单（代码允许，期望成功）');

        // 如果 sent 转单成功，清理产生的销售单
        $sentConvertSalesId = $convertBRes['data']['sales_order_id'] ?? null;
        if ($sentConvertSalesId) {
            echo "  [INFO] sent 转单产生销售单 ID={$sentConvertSalesId}\n";
            httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $sentConvertSalesId], $token);
        }

        // 验证订货单B转单后变为 completed
        $poBD_final = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s3['poId_b']], $token);
        $statusBFinal = extractPurchaseStatus($poBD_final);
        if (($convertBRes['code'] ?? 0) == 1) {
            assert_str($statusBFinal, 'completed', '场景3-sent转单后状态=completed');
        }
    } else {
        echo "[SKIP] 场景3-步骤3: 订货单B创建失败\n";
    }

    // Step 4: 验证 completed 状态无法再转单
    echo "Step 4: completed 状态转销售单（期望失败） ... ";
    if ($s3['poId_b']) {
        $poBDCheck = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $s3['poId_b']], $token);
        $statusBCheck = extractPurchaseStatus($poBDCheck);
        echo "  [INFO] 当前状态={$statusBCheck}\n";

        if ($statusBCheck === 'completed') {
            $convertCompletedRes = httpRequest('POST', "{$BASE_URL}/api/purchase/convert-to-sales", [
                'id'           => $s3['poId_b'],
                'warehouse_id' => $s3['whId'],
            ], $token);
            assert_code($convertCompletedRes, 0, 'completed 状态转销售单被拒绝（期望失败）');
        } else {
            echo "  [SKIP] 订货单B状态不是 completed，跳过此步验证\n";
        }
    }
} else {
    echo "[SKIP] 场景3: 前置数据创建失败\n";
}

// 清理场景3
echo "清理场景3数据 ... ";
if ($s3['poId_a']) {
    httpRequest('DELETE', "{$BASE_URL}/api/purchase/remove", ['id' => $s3['poId_a']], $token);
}
// poId_b 已是 completed，无法删除（保留无害数据）
if ($s3['goodsId']) {
    httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $s3['goodsId']], $token);
}
if ($s3['custId']) {
    httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $s3['custId']], $token);
}
if ($s3['whId']) {
    httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $s3['whId']], $token);
}
echo "OK\n";

// ─────────────────────────────────────────────
// 汇总输出
// ─────────────────────────────────────────────
echo "\n=== 结果: {$passTests}/{$totalTests} 通过 ===\n";

exit($failTests > 0 ? 1 : 0);
