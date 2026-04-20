<?php
/**
 * BeiMi JXC 进货单库存联动测试
 * 验证进货单全流程中库存入库和应付联动
 * 使用方法：php tests/supply_flow_test.php [BASE_URL]
 */

$BASE_URL = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8787';

// ─────────────────────────────────────────────
// 全局计数器
// ─────────────────────────────────────────────
$totalTests = 0;
$passTests  = 0;
$failTests  = 0;

// ─────────────────────────────────────────────
// 辅助函数（复用 smoke_test.php 风格）
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

    if (($method === 'GET' || $method === 'DELETE') && !empty($data)) {
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
 * 简写：POST 请求
 */
function http_post(string $url, array $data = [], string $token = ''): array
{
    return httpRequest('POST', $url, $data, $token);
}

/**
 * 简写：GET 请求
 */
function http_get(string $url, array $data = [], string $token = ''): array
{
    return httpRequest('GET', $url, $data, $token);
}

/**
 * 简写：DELETE 请求
 */
function http_del(string $url, array $data = [], string $token = ''): array
{
    return httpRequest('DELETE', $url, $data, $token);
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
        echo "  [PASS] {$testName}\n";
        $passTests++;
        return true;
    }

    $reason = $response['msg'] ?? json_encode($response, JSON_UNESCAPED_UNICODE);
    echo "  [FAIL] {$testName}: 期望 code={$expectedCode}, 实际 code={$actual}, msg={$reason}\n";
    $failTests++;
    return false;
}

/**
 * 断言两个值精确相等（字符串比较）
 */
function assert_eq($actual, $expected, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    $actualStr   = (string)$actual;
    $expectedStr = (string)$expected;
    if ($actualStr === $expectedStr) {
        echo "  [PASS] {$testName} (实际={$actualStr})\n";
        $passTests++;
        return true;
    }

    echo "  [FAIL] {$testName}: 期望={$expectedStr}, 实际={$actualStr}\n";
    $failTests++;
    return false;
}

/**
 * 断言两个数值在容差范围内相等
 */
function assert_near($actual, $expected, string $testName, float $tolerance = 0.02): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    $diff = abs((float)$actual - (float)$expected);
    if ($diff <= $tolerance) {
        echo "  [PASS] {$testName} (实际={$actual}, 期望={$expected})\n";
        $passTests++;
        return true;
    }

    echo "  [FAIL] {$testName}: 实际={$actual}, 期望={$expected}, 差值=" . number_format($diff, 4) . "\n";
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
 * 从列表响应中找到第一条包含特定字段值的记录 id
 */
function findIdInList(array $response, string $field, string $value): ?int
{
    $items = $response['data']['data'] ?? $response['data'] ?? [];
    if (!is_array($items)) return null;
    foreach ($items as $item) {
        if (isset($item[$field]) && $item[$field] === $value) {
            return (int)($item['id'] ?? 0) ?: null;
        }
    }
    return null;
}

/**
 * 从供应商详情响应中提取应付金额
 */
function extractPayable(array $response): ?string
{
    // FinanceService 写入 order_payable 字段
    $payable = $response['data']['order_payable'] ?? null;
    return $payable !== null ? (string)$payable : null;
}

/**
 * 从商品详情响应中提取库存值
 */
function extractStock(array $response): ?string
{
    $stock = $response['data']['stock'] ?? null;
    return $stock !== null ? (string)$stock : null;
}

// ─────────────────────────────────────────────
// 测试输出头
// ─────────────────────────────────────────────
echo "=== BeiMi JXC 进货单联动测试 ===\n";
echo "BASE_URL: {$BASE_URL}\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

// ══════════════════════════════════════════════
// 登录获取 token
// ══════════════════════════════════════════════
$loginRes = http_post("{$BASE_URL}/api/user/login", [
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
// 初始化基础数据：单位、仓库、供应商、商品
// ══════════════════════════════════════════════
$ts = time();

// 创建单位
$unitName = 'SF' . substr((string)$ts, -8);
$unitAddRes = http_post("{$BASE_URL}/api/units/add", ['name' => $unitName], $token);
assert_code($unitAddRes, 1, '创建单位');
$unitId = extractId($unitAddRes);
if ($unitId === null) {
    $unitListRes = http_get("{$BASE_URL}/api/units/index", [], $token);
    $unitId = findIdInList($unitListRes, 'name', $unitName);
}

// 创建仓库
$whName = "FLOW_测试仓库_{$ts}";
$whAddRes = http_post("{$BASE_URL}/api/warehouse/add", ['name' => $whName, 'address' => 'FLOW_测试地址'], $token);
assert_code($whAddRes, 1, '创建仓库');
$whId = extractId($whAddRes);
if ($whId === null) {
    $whListRes = http_get("{$BASE_URL}/api/warehouse/index", [], $token);
    $whId = findIdInList($whListRes, 'name', $whName);
}

// 创建供应商
$supName = "FLOW_测试供应商_{$ts}";
$supAddRes = http_post("{$BASE_URL}/api/supplier/add", [
    'supplier_name' => $supName,
    'contact'       => 'FLOW_张三',
    'phone'         => '13800000001',
], $token);
assert_code($supAddRes, 1, '创建供应商');
$supId = extractId($supAddRes);
if ($supId === null) {
    $supListRes = http_get("{$BASE_URL}/api/supplier/index", [], $token);
    $supId = findIdInList($supListRes, 'supplier_name', $supName);
}

// 创建商品
$goodsName = "FLOW_测试商品_{$ts}";
$goodsAddRes = http_post("{$BASE_URL}/api/goods/add", [
    'name'         => $goodsName,
    'product_code' => "FLOW_{$ts}",
    'price'        => 10.00,
    'units'        => '个',
], $token);
assert_code($goodsAddRes, 1, '创建商品');
$goodsId = extractId($goodsAddRes);
if ($goodsId === null) {
    $goodsListRes = http_get("{$BASE_URL}/api/goods/index", [], $token);
    $goodsId = findIdInList($goodsListRes, 'name', $goodsName);
}

// 检查前置数据
if (!$unitId || !$whId || !$supId || !$goodsId) {
    echo "\n[FATAL] 前置数据创建失败，终止测试。\n";
    echo "  单位ID={$unitId}, 仓库ID={$whId}, 供应商ID={$supId}, 商品ID={$goodsId}\n";
    exit(1);
}

echo "Step 1: 登录+初始化数据 ... OK\n";
echo "  单位ID={$unitId}, 仓库ID={$whId}, 供应商ID={$supId}, 商品ID={$goodsId}\n\n";

// ══════════════════════════════════════════════
// 场景 1: 正常进货流程（创建→编辑→删除）
// ══════════════════════════════════════════════
echo "--- 场景1: 正常进货流程 ---\n";

// 记录初始应付和库存
$supDetailInit = http_get("{$BASE_URL}/api/supplier/details", ['id' => $supId], $token);
$payableInit = extractPayable($supDetailInit);
$goodsDetailInit = http_get("{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token);
$stockInit = extractStock($goodsDetailInit);
echo "  [INFO] 初始应付: " . ($payableInit ?? 'N/A') . ", 初始库存: " . ($stockInit ?? 'N/A') . "\n";

// Step 2: 发布进货单：数量=100，单价=5 → 金额=500
$supply1Res = http_post("{$BASE_URL}/api/supply/publish", [
    'supplier_id'  => $supId,
    'warehouse_id' => $whId,
    'goods'        => [[
        'goods_id' => $goodsId,
        'name'     => $goodsName,
        'number'   => 100,
        'price'    => 5,
        'units'    => '个',
    ]],
], $token);
assert_code($supply1Res, 1, '发布进货单(数量=100,单价=5)');
$supply1Id = extractId($supply1Res);

// Step 3: 验证进货单金额=500，供应商应付+500，商品库存+100
$supply1Detail = http_get("{$BASE_URL}/api/supply/details", ['id' => $supply1Id], $token);
$orderMoney1 = $supply1Detail['data']['order_money'] ?? null;
assert_eq($orderMoney1, '500.00', '进货单金额=500.00');

$supDetailAfter1 = http_get("{$BASE_URL}/api/supplier/details", ['id' => $supId], $token);
$payableAfter1 = extractPayable($supDetailAfter1);
$expectedPayable1 = number_format((float)$payableInit + 500, 2, '.', '');
assert_eq($payableAfter1, $expectedPayable1, '供应商应付增加500');

$goodsDetailAfter1 = http_get("{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token);
$stockAfter1 = extractStock($goodsDetailAfter1);
$expectedStock1 = number_format((float)$stockInit + 100, 2, '.', '');
assert_eq($stockAfter1, $expectedStock1, '商品库存增加100');

echo "Step 2: 发布进货单 ... OK\n";
echo "  进货单金额={$orderMoney1}, 供应商应付={$payableAfter1}, 商品库存={$stockAfter1}\n\n";

// Step 4: 编辑进货单：数量改为 80 → 金额=400
$supply1EditRes = http_post("{$BASE_URL}/api/supply/edit", [
    'id'           => $supply1Id,
    'supplier_id'  => $supId,
    'warehouse_id' => $whId,
    'goods'        => [[
        'goods_id' => $goodsId,
        'name'     => $goodsName,
        'number'   => 80,
        'price'    => 5,
        'units'    => '个',
    ]],
], $token);
assert_code($supply1EditRes, 1, '编辑进货单(数量→80)');

// Step 5: 验证进货单金额=400，应付=400，库存=80
$supply1DetailAfterEdit = http_get("{$BASE_URL}/api/supply/details", ['id' => $supply1Id], $token);
$orderMoneyAfterEdit = $supply1DetailAfterEdit['data']['order_money'] ?? null;
assert_eq($orderMoneyAfterEdit, '400.00', '编辑后进货单金额=400.00');

$supDetailAfterEdit = http_get("{$BASE_URL}/api/supplier/details", ['id' => $supId], $token);
$payableAfterEdit = extractPayable($supDetailAfterEdit);
// 编辑：先回滚旧应付500，再增加新应付400 → 应付从初始+400
$expectedPayableEdit = number_format((float)$payableInit + 400, 2, '.', '');
assert_eq($payableAfterEdit, $expectedPayableEdit, '编辑后供应商应付=400');

$goodsDetailAfterEdit = http_get("{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token);
$stockAfterEdit = extractStock($goodsDetailAfterEdit);
$expectedStockEdit = number_format((float)$stockInit + 80, 2, '.', '');
assert_eq($stockAfterEdit, $expectedStockEdit, '编辑后商品库存=80');

echo "Step 3: 编辑进货单 ... OK\n";
echo "  进货单金额={$orderMoneyAfterEdit}, 供应商应付={$payableAfterEdit}, 商品库存={$stockAfterEdit}\n\n";

// Step 6: 删除进货单
$supply1DelRes = http_del("{$BASE_URL}/api/supply/remove", ['id' => $supply1Id], $token);
assert_code($supply1DelRes, 1, '删除进货单');

// Step 7: 验证供应商应付回到初始值，商品库存回到初始值
$supDetailAfterDel = http_get("{$BASE_URL}/api/supplier/details", ['id' => $supId], $token);
$payableAfterDel = extractPayable($supDetailAfterDel);
assert_eq($payableAfterDel, number_format((float)$payableInit, 2, '.', ''), '删除后供应商应收回初始值');

$goodsDetailAfterDel = http_get("{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token);
$stockAfterDel = extractStock($goodsDetailAfterDel);
assert_eq($stockAfterDel, number_format((float)$stockInit, 2, '.', ''), '删除后商品库存回初始值');

echo "Step 4: 删除进货单 ... OK\n";
echo "  供应商应付={$payableAfterDel}, 商品库存={$stockAfterDel}\n\n";

// ══════════════════════════════════════════════
// 场景 2: 同一供应商多次进货累计应付
// ══════════════════════════════════════════════
echo "--- 场景2: 多次进货累计 ---\n";

// 重新获取当前应付和库存（可能受场景1残留影响，用实际值）
$supDetailS2 = http_get("{$BASE_URL}/api/supplier/details", ['id' => $supId], $token);
$payableS2Base = extractPayable($supDetailS2);
$goodsDetailS2 = http_get("{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token);
$stockS2Base = extractStock($goodsDetailS2);
echo "  [INFO] 场景2初始应付: {$payableS2Base}, 初始库存: {$stockS2Base}\n";

// Step 1: 发布进货单1：数量=100，单价=5（金额=500）
$supply2Res = http_post("{$BASE_URL}/api/supply/publish", [
    'supplier_id'  => $supId,
    'warehouse_id' => $whId,
    'goods'        => [[
        'goods_id' => $goodsId,
        'name'     => $goodsName,
        'number'   => 100,
        'price'    => 5,
        'units'    => '个',
    ]],
], $token);
assert_code($supply2Res, 1, '进货单1(数量=100,单价=5)');
$supply2Id = extractId($supply2Res);

// Step 2: 发布进货单2：数量=50，单价=5（金额=250）
$supply3Res = http_post("{$BASE_URL}/api/supply/publish", [
    'supplier_id'  => $supId,
    'warehouse_id' => $whId,
    'goods'        => [[
        'goods_id' => $goodsId,
        'name'     => $goodsName,
        'number'   => 50,
        'price'    => 5,
        'units'    => '个',
    ]],
], $token);
assert_code($supply3Res, 1, '进货单2(数量=50,单价=5)');
$supply3Id = extractId($supply3Res);

// Step 3: 验证供应商总应付=750，商品总库存=150
$supDetailAfter2 = http_get("{$BASE_URL}/api/supplier/details", ['id' => $supId], $token);
$payableAfter2 = extractPayable($supDetailAfter2);
$expectedPayable2 = number_format((float)$payableS2Base + 750, 2, '.', '');
assert_eq($payableAfter2, $expectedPayable2, '两次进货后供应商应付=初始+750');

$goodsDetailAfter2 = http_get("{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token);
$stockAfter2 = extractStock($goodsDetailAfter2);
$expectedStock2 = number_format((float)$stockS2Base + 150, 2, '.', '');
assert_eq($stockAfter2, $expectedStock2, '两次进货后商品库存=初始+150');

echo "Step 1: 发布两笔进货单 ... OK\n";
echo "  供应商应付={$payableAfter2}, 商品库存={$stockAfter2}\n\n";

// Step 4: 尝试付款（如果供应商付款接口存在）
// 当前路由没有 supplier/paymoney，跳过付款测试
echo "Step 2: 供应商付款接口 ... SKIP（当前无 supplier/paymoney 路由）\n\n";

// ══════════════════════════════════════════════
// 清理：删除进货单和基础数据
// ══════════════════════════════════════════════
echo "--- 清理测试数据 ---\n";

// 删除进货单2
if ($supply3Id) {
    $del3Res = http_del("{$BASE_URL}/api/supply/remove", ['id' => $supply3Id], $token);
    assert_code($del3Res, 1, '清理-删除进货单2');
}

// 删除进货单1
if ($supply2Id) {
    $del2Res = http_del("{$BASE_URL}/api/supply/remove", ['id' => $supply2Id], $token);
    assert_code($del2Res, 1, '清理-删除进货单1');
}

// 验证清理后应付和库存回到场景2初始值
$supDetailClean = http_get("{$BASE_URL}/api/supplier/details", ['id' => $supId], $token);
$payableClean = extractPayable($supDetailClean);
assert_eq($payableClean, number_format((float)$payableS2Base, 2, '.', ''), '清理后供应商应回升');

$goodsDetailClean = http_get("{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token);
$stockClean = extractStock($goodsDetailClean);
assert_eq($stockClean, number_format((float)$stockS2Base, 2, '.', ''), '清理后商品库存回升');

// 删除基础数据（先删供应商关联的进货单已删，可以安全删除）
if ($goodsId) {
    http_del("{$BASE_URL}/api/goods/del", ['id' => $goodsId], $token);
    echo "  [INFO] 清理商品 ID={$goodsId}\n";
}
if ($supId) {
    http_del("{$BASE_URL}/api/supplier/del", ['id' => $supId], $token);
    echo "  [INFO] 清理供应商 ID={$supId}\n";
}
if ($whId) {
    http_post("{$BASE_URL}/api/warehouse/del", ['id' => $whId], $token);
    echo "  [INFO] 清理仓库 ID={$whId}\n";
}
if ($unitId) {
    http_del("{$BASE_URL}/api/units/del", ['id' => $unitId], $token);
    echo "  [INFO] 清理单位 ID={$unitId}\n";
}

// 退出登录
http_post("{$BASE_URL}/api/user/logout", [], $token);

// ─────────────────────────────────────────────
// 汇总输出
// ─────────────────────────────────────────────
echo "\n=== 结果: {$passTests}/{$totalTests} 通过 ===\n";
echo "结束时间: " . date('Y-m-d H:i:s') . "\n";

exit($failTests > 0 ? 1 : 0);
