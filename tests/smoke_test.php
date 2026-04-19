<?php
/**
 * BeiMi JXC 冒烟测试脚本
 * 纯 PHP + cURL，不依赖任何外部库
 * 使用方法：php tests/smoke_test.php [BASE_URL]
 */

$BASE_URL = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8000';

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
 * @param string $method  GET / POST / DELETE
 * @param string $url     完整 URL
 * @param array  $data    请求体（POST/DELETE）或查询参数（GET）
 * @param string $token   认证 token
 * @return array          解码后的响应数组
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
            // 已在上面处理 query string
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
 * @param array  $response     响应数组
 * @param int    $expectedCode 期望的 code 值
 * @param string $testName     测试名称
 * @return bool
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

// ─────────────────────────────────────────────
// 清理函数（使用 SMOKE_ 前缀定位测试数据）
// ─────────────────────────────────────────────

/**
 * 安全删除：忽略删除失败（可能已被前面步骤删除）
 */
function safeDelete(string $url, array $data, string $token, string $label): void
{
    if (empty($data['id'])) return;
    $res = httpRequest('DELETE', $url, $data, $token);
    $code = $res['code'] ?? 0;
    if ($code != 1) {
        $method = 'DELETE';
        // 部分接口用 POST 删除
    }
}

function safePostDelete(string $url, array $data, string $token): void
{
    if (empty($data['id'])) return;
    httpRequest('POST', $url, $data, $token);
}

// ─────────────────────────────────────────────
// 主测试流程
// ─────────────────────────────────────────────

echo "============================================================\n";
echo " BeiMi JXC 冒烟测试\n";
echo " BASE_URL: {$BASE_URL}\n";
echo " 开始时间: " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n\n";

// ══════════════════════════════════════════════
// 步骤 1：登录获取 token
// ══════════════════════════════════════════════
echo "── 步骤 1：用户登录 ──\n";
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
// 步骤 2：获取用户信息
// ══════════════════════════════════════════════
echo "\n── 步骤 2：获取用户信息 ──\n";
$userInfoRes = httpRequest('GET', "{$BASE_URL}/api/user/info", [], $token);
assert_code($userInfoRes, 1, '获取用户信息 GET /api/user/info');

// ══════════════════════════════════════════════
// 步骤 3：单位 CRUD
// ══════════════════════════════════════════════
echo "\n── 步骤 3：单位 CRUD ──\n";
$unitName = 'SMOKE_测试单位-烟测';

// 3.1 新增
$unitAddRes = httpRequest('POST', "{$BASE_URL}/api/units/add", ['name' => $unitName], $token);
assert_code($unitAddRes, 1, '单位新增');
$unitId = extractId($unitAddRes);

// 3.2 列表
$unitListRes = httpRequest('GET', "{$BASE_URL}/api/units/index", [], $token);
assert_code($unitListRes, 1, '单位列表');
if ($unitId === null) {
    $unitId = findIdInList($unitListRes, 'name', $unitName);
}

// 3.3 详情
if ($unitId) {
    $unitDetailRes = httpRequest('GET', "{$BASE_URL}/api/units/detail", ['id' => $unitId], $token);
    assert_code($unitDetailRes, 1, '单位详情');
} else {
    echo "[SKIP] 单位详情：无法获取 ID\n";
}

// 3.4 编辑
if ($unitId) {
    $unitEditRes = httpRequest('POST', "{$BASE_URL}/api/units/edit", ['id' => $unitId, 'name' => 'SMOKE_测试单位-烟测-改'], $token);
    assert_code($unitEditRes, 1, '单位编辑');
}

// 3.5 删除
if ($unitId) {
    $unitDelRes = httpRequest('DELETE', "{$BASE_URL}/api/units/del", ['id' => $unitId], $token);
    assert_code($unitDelRes, 1, '单位删除');
    $unitId = null;
}

// ══════════════════════════════════════════════
// 步骤 4：仓库 CRUD + 启停
// ══════════════════════════════════════════════
echo "\n── 步骤 4：仓库 CRUD + 启停 ──\n";
$whName = 'SMOKE_测试仓库-烟测';

$whAddRes = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", ['name' => $whName, 'address' => 'SMOKE_测试地址'], $token);
assert_code($whAddRes, 1, '仓库新增');
$whId = extractId($whAddRes);

$whListRes = httpRequest('GET', "{$BASE_URL}/api/warehouse/index", [], $token);
assert_code($whListRes, 1, '仓库列表');
if ($whId === null) {
    $whId = findIdInList($whListRes, 'name', $whName);
}

if ($whId) {
    $whDetailRes = httpRequest('GET', "{$BASE_URL}/api/warehouse/detail", ['id' => $whId], $token);
    assert_code($whDetailRes, 1, '仓库详情');

    $whEditRes = httpRequest('POST', "{$BASE_URL}/api/warehouse/edit", ['id' => $whId, 'name' => 'SMOKE_测试仓库-改'], $token);
    assert_code($whEditRes, 1, '仓库编辑');

    $whDisableRes = httpRequest('POST', "{$BASE_URL}/api/warehouse/disable", ['id' => $whId], $token);
    assert_code($whDisableRes, 1, '仓库停用');

    $whEnableRes = httpRequest('POST', "{$BASE_URL}/api/warehouse/enable", ['id' => $whId], $token);
    assert_code($whEnableRes, 1, '仓库启用');

    $whDelRes = httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $whId], $token);
    assert_code($whDelRes, 1, '仓库删除');
    $whId = null;
} else {
    echo "[SKIP] 仓库详情/编辑/启停/删除：无法获取 ID\n";
}

// ══════════════════════════════════════════════
// 步骤 5：供应商 CRUD
// ══════════════════════════════════════════════
echo "\n── 步骤 5：供应商 CRUD ──\n";
$supName = 'SMOKE_测试供应商-烟测';

$supAddRes = httpRequest('POST', "{$BASE_URL}/api/supplier/add", [
    'supplier_name' => $supName,
    'contact'       => 'SMOKE_张三',
    'phone'         => '13800000001',
], $token);
assert_code($supAddRes, 1, '供应商新增');
$supId = extractId($supAddRes);

$supListRes = httpRequest('GET', "{$BASE_URL}/api/supplier/index", [], $token);
assert_code($supListRes, 1, '供应商列表');
if ($supId === null) {
    $supId = findIdInList($supListRes, 'supplier_name', $supName);
}

if ($supId) {
    $supDetailRes = httpRequest('GET', "{$BASE_URL}/api/supplier/details", ['id' => $supId], $token);
    assert_code($supDetailRes, 1, '供应商详情');

    $supEditRes = httpRequest('POST', "{$BASE_URL}/api/supplier/edit", ['id' => $supId, 'supplier_name' => 'SMOKE_测试供应商-改'], $token);
    assert_code($supEditRes, 1, '供应商编辑');

    $supDelRes = httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $supId], $token);
    assert_code($supDelRes, 1, '供应商删除');
    $supId = null;
} else {
    echo "[SKIP] 供应商详情/编辑/删除：无法获取 ID\n";
}

// ══════════════════════════════════════════════
// 步骤 6：商品 CRUD
// ══════════════════════════════════════════════
echo "\n── 步骤 6：商品 CRUD ──\n";
$goodsName = 'SMOKE_测试商品-烟测';

$goodsAddRes = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name'         => $goodsName,
    'product_code' => 'SMOKE001',
    'price'        => 10.50,
    'units'        => '个',
], $token);
assert_code($goodsAddRes, 1, '商品新增');
$goodsId = extractId($goodsAddRes);

$goodsListRes = httpRequest('GET', "{$BASE_URL}/api/goods/index", [], $token);
assert_code($goodsListRes, 1, '商品列表');
if ($goodsId === null) {
    $goodsId = findIdInList($goodsListRes, 'name', $goodsName);
}

if ($goodsId) {
    $goodsDetailRes = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token);
    assert_code($goodsDetailRes, 1, '商品详情');

    $goodsEditRes = httpRequest('POST', "{$BASE_URL}/api/goods/edit", ['id' => $goodsId, 'name' => 'SMOKE_测试商品-改', 'price' => 12.00], $token);
    assert_code($goodsEditRes, 1, '商品编辑');

    $goodsDelRes = httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $goodsId], $token);
    assert_code($goodsDelRes, 1, '商品删除');
    $goodsId = null;
} else {
    echo "[SKIP] 商品详情/编辑/删除：无法获取 ID\n";
}

// ══════════════════════════════════════════════
// 步骤 7：客户 CRUD + 分组 + 启停
// ══════════════════════════════════════════════
echo "\n── 步骤 7：客户 CRUD + 分组 + 启停 ──\n";
$custGroupName = 'SMOKE_测试分组-烟测';
$custName      = 'SMOKE_测试客户-烟测';

// 7.1 创建分组
$custGroupAddRes = httpRequest('POST', "{$BASE_URL}/api/customer/groups", ['group_name' => $custGroupName], $token);
assert_code($custGroupAddRes, 1, '客户分组新增');
$custGroupId = extractId($custGroupAddRes);
if ($custGroupId === null) {
    $custGroupId = $custGroupAddRes['data']['id'] ?? null;
}

// 7.2 新增客户
$custAddRes = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => $custName,
    'contact'       => 'SMOKE_李四',
    'phone'         => '13800000002',
], $token);
assert_code($custAddRes, 1, '客户新增');
$custId = extractId($custAddRes);

// 7.3 列表
$custListRes = httpRequest('GET', "{$BASE_URL}/api/customer/index", [], $token);
assert_code($custListRes, 1, '客户列表');
if ($custId === null) {
    $custId = findIdInList($custListRes, 'customer_name', $custName);
}

if ($custId) {
    // 7.4 详情
    $custDetailRes = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $custId], $token);
    assert_code($custDetailRes, 1, '客户详情');

    // 7.5 编辑
    $custEditRes = httpRequest('POST', "{$BASE_URL}/api/customer/edit", ['id' => $custId, 'customer_name' => 'SMOKE_测试客户-改'], $token);
    assert_code($custEditRes, 1, '客户编辑');

    // 7.6 停用
    $custDisableRes = httpRequest('POST', "{$BASE_URL}/api/customer/status", ['id' => $custId, 'is_disabled' => 1], $token);
    assert_code($custDisableRes, 1, '客户停用');

    // 7.7 启用
    $custEnableRes = httpRequest('POST', "{$BASE_URL}/api/customer/status", ['id' => $custId, 'is_disabled' => 0], $token);
    assert_code($custEnableRes, 1, '客户启用');

    // 7.8 删除客户
    $custDelRes = httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $custId], $token);
    assert_code($custDelRes, 1, '客户删除');
    $custId = null;
} else {
    echo "[SKIP] 客户详情/编辑/启停/删除：无法获取 ID\n";
}

// 7.9 删除分组
if ($custGroupId) {
    $custGroupDelRes = httpRequest('POST', "{$BASE_URL}/api/customer/groups/delete", ['id' => $custGroupId], $token);
    assert_code($custGroupDelRes, 1, '客户分组删除');
    $custGroupId = null;
}

// ══════════════════════════════════════════════
// 步骤 8：销售单 CRUD + 统计（重新创建前置数据）
// ══════════════════════════════════════════════
echo "\n── 步骤 8：销售单 CRUD + 统计 ──\n";

// 前置数据：创建客户
$orderCustRes = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => 'SMOKE_订单测试客户',
    'contact'       => 'SMOKE_王五',
    'phone'         => '13900000001',
], $token);
assert_code($orderCustRes, 1, '订单前置-创建客户');
$orderCustId = extractId($orderCustRes);
if ($orderCustId === null) {
    $tmpList = httpRequest('GET', "{$BASE_URL}/api/customer/index", [], $token);
    $orderCustId = findIdInList($tmpList, 'customer_name', 'SMOKE_订单测试客户');
}

// 前置数据：创建仓库
$orderWhRes = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", [
    'name'    => 'SMOKE_订单测试仓库',
    'address' => 'SMOKE_订单测试地址',
], $token);
assert_code($orderWhRes, 1, '订单前置-创建仓库');
$orderWhId = extractId($orderWhRes);
if ($orderWhId === null) {
    $tmpList = httpRequest('GET', "{$BASE_URL}/api/warehouse/index", [], $token);
    $orderWhId = findIdInList($tmpList, 'name', 'SMOKE_订单测试仓库');
}

// 前置数据：创建商品
$orderGoodsRes = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name'         => 'SMOKE_订单测试商品',
    'product_code' => 'SMOKE_ORDER001',
    'price'        => 10.50,
    'units'        => '个',
], $token);
assert_code($orderGoodsRes, 1, '订单前置-创建商品');
$orderGoodsId = extractId($orderGoodsRes);
if ($orderGoodsId === null) {
    $tmpList = httpRequest('GET', "{$BASE_URL}/api/goods/index", [], $token);
    $orderGoodsId = findIdInList($tmpList, 'name', 'SMOKE_订单测试商品');
}

$orderId = null;

if ($orderCustId && $orderWhId && $orderGoodsId) {
    // 8.1 创建销售单
    $orderAddRes = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
        'customer_id'  => $orderCustId,
        'warehouse_id' => $orderWhId,
        'goods'        => [[
            'goods_id' => $orderGoodsId,
            'name'     => 'SMOKE_订单测试商品',
            'number'   => 5,
            'price'    => 10.50,
            'units'    => '个',
        ]],
    ], $token);
    assert_code($orderAddRes, 1, '销售单创建');
    $orderId = extractId($orderAddRes);

    // 8.2 销售单列表
    $orderListRes = httpRequest('GET', "{$BASE_URL}/api/order/lists", [], $token);
    assert_code($orderListRes, 1, '销售单列表');
    if ($orderId === null) {
        $orderId = $orderListRes['data']['data'][0]['id'] ?? null;
        if ($orderId) $orderId = (int)$orderId;
    }

    // 8.3 销售单详情
    if ($orderId) {
        $orderDetailRes = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $orderId], $token);
        assert_code($orderDetailRes, 1, '销售单详情');

        // 8.4 编辑销售单
        $orderEditRes = httpRequest('POST', "{$BASE_URL}/api/order/edit", [
            'id'           => $orderId,
            'customer_id'  => $orderCustId,
            'warehouse_id' => $orderWhId,
            'goods'        => [[
                'goods_id' => $orderGoodsId,
                'name'     => 'SMOKE_订单测试商品',
                'number'   => 3,
                'price'    => 15.00,
                'units'    => '个',
            ]],
        ], $token);
        assert_code($orderEditRes, 1, '销售单编辑');
    }

    // 8.5 统计
    $orderStatsRes = httpRequest('GET', "{$BASE_URL}/api/order/statistics", [], $token);
    assert_code($orderStatsRes, 1, '销售单统计');

    // 8.6 删除销售单
    if ($orderId) {
        $orderDelRes = httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $orderId], $token);
        assert_code($orderDelRes, 1, '销售单删除');
        $orderId = null;
    }
} else {
    echo "[SKIP] 销售单测试：前置数据创建失败（客户ID={$orderCustId}, 仓库ID={$orderWhId}, 商品ID={$orderGoodsId}）\n";
}

// ══════════════════════════════════════════════
// 步骤 9：失败路径测试 + 清理前置数据
// ══════════════════════════════════════════════
echo "\n── 步骤 9：失败路径测试 ──\n";

if ($orderCustId && $orderWhId) {
    // 9.1 空商品列表创建订单，期望 code=0
    $emptyGoodsRes = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
        'customer_id'  => $orderCustId,
        'warehouse_id' => $orderWhId,
        'goods'        => [],
    ], $token);
    assert_code($emptyGoodsRes, 0, '销售单空商品（期望失败）');
} else {
    echo "[SKIP] 失败路径测试-空商品订单：前置数据不足\n";
}

// 9.2 访问不存在的资源（期望 code=0）
$notFoundRes = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => 999999], $token);
assert_code($notFoundRes, 0, '获取不存在商品（期望失败）');

// 9.3 清理前置数据
echo "\n── 步骤 9.x：清理前置数据 ──\n";

if ($orderGoodsId) {
    $cleanGoods = httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $orderGoodsId], $token);
    assert_code($cleanGoods, 1, '清理-删除订单测试商品');
}
if ($orderWhId) {
    $cleanWh = httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $orderWhId], $token);
    assert_code($cleanWh, 1, '清理-删除订单测试仓库');
}
if ($orderCustId) {
    $cleanCust = httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $orderCustId], $token);
    assert_code($cleanCust, 1, '清理-删除订单测试客户');
}

// ══════════════════════════════════════════════
// 步骤 10：退出登录
// ══════════════════════════════════════════════
echo "\n── 步骤 10：退出登录 ──\n";
$logoutRes = httpRequest('POST', "{$BASE_URL}/api/user/logout", [], $token);
assert_code($logoutRes, 1, '用户退出登录');

// ─────────────────────────────────────────────
// 汇总输出
// ─────────────────────────────────────────────
echo "\n============================================================\n";
echo " 测试汇总\n";
echo "============================================================\n";
echo " Total: {$totalTests}, Pass: {$passTests}, Fail: {$failTests}\n";
echo " 结束时间: " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n";

exit($failTests > 0 ? 1 : 0);
