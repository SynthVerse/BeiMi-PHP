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

// ══════════════════════════════════════════════
// 步骤 10：进货单 CRUD + 库存入库验证
// ══════════════════════════════════════════════
echo "\n── 步骤 10：进货单 CRUD + 库存入库验证 ──\n";

// 前置：创建供应商
$supplySupRes = httpRequest('POST', "{$BASE_URL}/api/supplier/add", [
    'supplier_name' => 'SMOKE_进货单测试供应商',
    'contact'       => 'SMOKE_进货联系人',
    'phone'         => '13700000001',
], $token);
assert_code($supplySupRes, 1, '进货单前置-创建供应商');
$supplySupId = extractId($supplySupRes);
if ($supplySupId === null) {
    $tmpList = httpRequest('GET', "{$BASE_URL}/api/supplier/index", [], $token);
    $supplySupId = findIdInList($tmpList, 'supplier_name', 'SMOKE_进货单测试供应商');
}

$supplyOrderId = null;

if ($supplySupId && $orderWhId && $orderGoodsId) {
    // 10.1 创建进货单
    $supplyAddRes = httpRequest('POST', "{$BASE_URL}/api/supply/publish", [
        'supplier_id'  => $supplySupId,
        'warehouse_id' => $orderWhId,
        'goods'        => [[
            'goods_id' => $orderGoodsId,
            'name'     => 'SMOKE_订单测试商品',
            'number'   => 10,
            'price'    => 10.50,
            'units'    => '个',
        ]],
    ], $token);
    assert_code($supplyAddRes, 1, '进货单创建');
    $supplyOrderId = extractId($supplyAddRes);

    // 10.2 进货单列表
    $supplyListRes = httpRequest('GET', "{$BASE_URL}/api/supply/lists", [], $token);
    assert_code($supplyListRes, 1, '进货单列表');
    if ($supplyOrderId === null) {
        $supplyOrderId = $supplyListRes['data']['data'][0]['id'] ?? null;
        if ($supplyOrderId) $supplyOrderId = (int)$supplyOrderId;
    }

    // 10.3 进货单详情
    if ($supplyOrderId) {
        $supplyDetailRes = httpRequest('GET', "{$BASE_URL}/api/supply/details", ['id' => $supplyOrderId], $token);
        assert_code($supplyDetailRes, 1, '进货单详情');

        // 10.4 编辑进货单
        $supplyEditRes = httpRequest('POST', "{$BASE_URL}/api/supply/edit", [
            'id'           => $supplyOrderId,
            'supplier_id'  => $supplySupId,
            'warehouse_id' => $orderWhId,
            'goods'        => [[
                'goods_id' => $orderGoodsId,
                'name'     => 'SMOKE_订单测试商品',
                'number'   => 8,
                'price'    => 12.00,
                'units'    => '个',
            ]],
        ], $token);
        assert_code($supplyEditRes, 1, '进货单编辑');
    }

    // 10.5 进货单统计
    $supplyStatsRes = httpRequest('GET', "{$BASE_URL}/api/supply/statistics", [], $token);
    assert_code($supplyStatsRes, 1, '进货单统计');

    // 10.6 删除进货单（回滚库存）
    if ($supplyOrderId) {
        $supplyDelRes = httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $supplyOrderId], $token);
        assert_code($supplyDelRes, 1, '进货单删除');
        $supplyOrderId = null;
    }
} else {
    echo "[SKIP] 进货单测试：前置数据不足（供应商ID={$supplySupId}, 仓库ID={$orderWhId}, 商品ID={$orderGoodsId}）\n";
}

// 清理供应商
if ($supplySupId) {
    $cleanSupplySup = httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $supplySupId], $token);
    assert_code($cleanSupplySup, 1, '清理-删除进货单测试供应商');
}

// ══════════════════════════════════════════════
// 步骤 11：销售退货单 CRUD
// ══════════════════════════════════════════════
echo "\n── 步骤 11：销售退货单 CRUD ──\n";

// 前置：创建销售单作为原单
$returnSaleOrderId = null;
$returnOrderId     = null;

if ($orderCustId && $orderWhId && $orderGoodsId) {
    $returnSaleRes = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
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
    assert_code($returnSaleRes, 1, '退货前置-创建销售单');
    $returnSaleOrderId = extractId($returnSaleRes);

    // 11.1 创建退货单
    if ($returnSaleOrderId) {
        $returnAddRes = httpRequest('POST', "{$BASE_URL}/api/return/publish", [
            'original_order_id' => $returnSaleOrderId,
            'customer_id'       => $orderCustId,
            'warehouse_id'      => $orderWhId,
            'goods'             => [[
                'goods_id' => $orderGoodsId,
                'name'     => 'SMOKE_订单测试商品',
                'number'   => 2,
                'price'    => 10.50,
                'units'    => '个',
            ]],
        ], $token);
        assert_code($returnAddRes, 1, '退货单创建');
        $returnOrderId = extractId($returnAddRes);
    }

    // 11.2 退货单列表
    $returnListRes = httpRequest('GET', "{$BASE_URL}/api/return/lists", [], $token);
    assert_code($returnListRes, 1, '退货单列表');
    if ($returnOrderId === null && $returnSaleOrderId) {
        $returnOrderId = $returnListRes['data']['data'][0]['id'] ?? null;
        if ($returnOrderId) $returnOrderId = (int)$returnOrderId;
    }

    // 11.3 退货单详情
    if ($returnOrderId) {
        $returnDetailRes = httpRequest('GET', "{$BASE_URL}/api/return/details", ['id' => $returnOrderId], $token);
        assert_code($returnDetailRes, 1, '退货单详情');

        // 11.4 编辑退货单
        $returnEditRes = httpRequest('POST', "{$BASE_URL}/api/return/edit", [
            'id'                => $returnOrderId,
            'original_order_id' => $returnSaleOrderId,
            'customer_id'       => $orderCustId,
            'warehouse_id'      => $orderWhId,
            'goods'             => [[
                'goods_id' => $orderGoodsId,
                'name'     => 'SMOKE_订单测试商品',
                'number'   => 1,
                'price'    => 10.50,
                'units'    => '个',
            ]],
        ], $token);
        assert_code($returnEditRes, 1, '退货单编辑');
    }

    // 11.5 删除退货单
    if ($returnOrderId) {
        $returnDelRes = httpRequest('DELETE', "{$BASE_URL}/api/return/remove", ['id' => $returnOrderId], $token);
        assert_code($returnDelRes, 1, '退货单删除');
        $returnOrderId = null;
    }

    // 清理：删除原销售单
    if ($returnSaleOrderId) {
        $returnSaleDelRes = httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $returnSaleOrderId], $token);
        assert_code($returnSaleDelRes, 1, '退货清理-删除销售单');
    }
} else {
    echo "[SKIP] 退货单测试：前置数据不足\n";
}

// ══════════════════════════════════════════════
// 步骤 12：订货单 CRUD + 状态推进
// ══════════════════════════════════════════════
echo "\n── 步骤 12：订货单 CRUD + 状态推进 ──\n";

// 前置：创建专用客户和商品（避免阻塞其他测试的清理）
$purchaseCustRes = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => 'SMOKE_订货单测试客户',
    'contact'       => 'SMOKE_订货联系人',
    'phone'         => '13800000003',
], $token);
assert_code($purchaseCustRes, 1, '订货单前置-创建客户');
$purchaseCustId = extractId($purchaseCustRes);
if ($purchaseCustId === null) {
    $tmpList = httpRequest('GET', "{$BASE_URL}/api/customer/index", [], $token);
    $purchaseCustId = findIdInList($tmpList, 'customer_name', 'SMOKE_订货单测试客户');
}

$purchaseGoodsRes = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name'         => 'SMOKE_订货单测试商品',
    'product_code' => 'SMOKE_PUR001',
    'price'        => 20.00,
    'units'        => '件',
], $token);
assert_code($purchaseGoodsRes, 1, '订货单前置-创建商品');
$purchaseGoodsId = extractId($purchaseGoodsRes);
if ($purchaseGoodsId === null) {
    $tmpList = httpRequest('GET', "{$BASE_URL}/api/goods/index", [], $token);
    $purchaseGoodsId = findIdInList($tmpList, 'name', 'SMOKE_订货单测试商品');
}

$purchaseOrder1Id = null;
$purchaseOrder2Id = null;
$purchaseOrder3Id = null;

if ($purchaseCustId && $purchaseGoodsId) {
    // 12.1 创建订货单 PO1（draft 状态）
    $purchaseAdd1Res = httpRequest('POST', "{$BASE_URL}/api/purchase/publish", [
        'customer_id' => $purchaseCustId,
        'warehouse_id' => $orderWhId,
        'goods'       => [[
            'goods_id' => $purchaseGoodsId,
            'name'     => 'SMOKE_订货单测试商品',
            'number'   => 5,
            'price'    => 20.00,
            'units'    => '件',
        ]],
    ], $token);
    assert_code($purchaseAdd1Res, 1, '订货单创建-PO1');
    $purchaseOrder1Id = extractId($purchaseAdd1Res);

    // 12.2 订货单列表
    $purchaseListRes = httpRequest('GET', "{$BASE_URL}/api/purchase/lists", [], $token);
    assert_code($purchaseListRes, 1, '订货单列表');
    if ($purchaseOrder1Id === null) {
        $purchaseOrder1Id = $purchaseListRes['data']['data'][0]['id'] ?? null;
        if ($purchaseOrder1Id) $purchaseOrder1Id = (int)$purchaseOrder1Id;
    }

    // 12.3 订货单详情
    if ($purchaseOrder1Id) {
        $purchaseDetailRes = httpRequest('GET', "{$BASE_URL}/api/purchase/details", ['id' => $purchaseOrder1Id], $token);
        assert_code($purchaseDetailRes, 1, '订货单详情-PO1');

        // 12.4 推进状态 draft → sent
        $purchaseConfirm1Res = httpRequest('POST', "{$BASE_URL}/api/purchase/confirm", ['id' => $purchaseOrder1Id], $token);
        assert_code($purchaseConfirm1Res, 1, '订货单确认-draft→sent');

        // 12.5 推进状态 sent → received
        $purchaseConfirm2Res = httpRequest('POST', "{$BASE_URL}/api/purchase/confirm", ['id' => $purchaseOrder1Id], $token);
        assert_code($purchaseConfirm2Res, 1, '订货单确认-sent→received');
    }

    // 12.6 新建 PO2 并取消
    $purchaseAdd2Res = httpRequest('POST', "{$BASE_URL}/api/purchase/publish", [
        'customer_id' => $purchaseCustId,
        'warehouse_id' => $orderWhId,
        'goods'       => [[
            'goods_id' => $purchaseGoodsId,
            'name'     => 'SMOKE_订货单测试商品',
            'number'   => 3,
            'price'    => 20.00,
            'units'    => '件',
        ]],
    ], $token);
    assert_code($purchaseAdd2Res, 1, '订货单创建-PO2');
    $purchaseOrder2Id = extractId($purchaseAdd2Res);

    if ($purchaseOrder2Id) {
        $purchaseCancelRes = httpRequest('POST', "{$BASE_URL}/api/purchase/cancel", [
            'id'            => $purchaseOrder2Id,
            'cancel_reason' => 'SMOKE_测试取消',
        ], $token);
        assert_code($purchaseCancelRes, 1, '订货单取消-PO2');
    }

    // 12.7 新建 PO3 → 编辑 → 删除
    $purchaseAdd3Res = httpRequest('POST', "{$BASE_URL}/api/purchase/publish", [
        'customer_id' => $purchaseCustId,
        'warehouse_id' => $orderWhId,
        'goods'       => [[
            'goods_id' => $purchaseGoodsId,
            'name'     => 'SMOKE_订货单测试商品',
            'number'   => 2,
            'price'    => 20.00,
            'units'    => '件',
        ]],
    ], $token);
    assert_code($purchaseAdd3Res, 1, '订货单创建-PO3');
    $purchaseOrder3Id = extractId($purchaseAdd3Res);

    if ($purchaseOrder3Id) {
        $purchaseEditRes = httpRequest('POST', "{$BASE_URL}/api/purchase/edit", [
            'id'          => $purchaseOrder3Id,
            'customer_id' => $purchaseCustId,
            'warehouse_id' => $orderWhId,
            'goods'       => [[
                'goods_id' => $purchaseGoodsId,
                'name'     => 'SMOKE_订货单测试商品',
                'number'   => 4,
                'price'    => 25.00,
                'units'    => '件',
            ]],
        ], $token);
        assert_code($purchaseEditRes, 1, '订货单编辑-PO3');
    }

    // 12.8 订货单统计
    $purchaseStatsRes = httpRequest('GET', "{$BASE_URL}/api/purchase/statistics", [], $token);
    assert_code($purchaseStatsRes, 1, '订货单统计');

    // 12.9 删除 PO3（draft 状态可删除）
    if ($purchaseOrder3Id) {
        $purchaseDelRes = httpRequest('DELETE', "{$BASE_URL}/api/purchase/remove", ['id' => $purchaseOrder3Id], $token);
        assert_code($purchaseDelRes, 1, '订货单删除-PO3');
        $purchaseOrder3Id = null;
    }

    // 清理：取消 PO1（received→cancelled，已取消的订货单无法通过 API 删除）
    if ($purchaseOrder1Id) {
        $purchaseCancel1Res = httpRequest('POST', "{$BASE_URL}/api/purchase/cancel", [
            'id'            => $purchaseOrder1Id,
            'cancel_reason' => 'SMOKE_测试清理',
        ], $token);
        assert_code($purchaseCancel1Res, 1, '清理-取消订货单PO1');
    }
    // PO2 已取消，无需再处理
    echo "[INFO] 已取消的订货单 PO1/PO2 无法通过 API 删除，其关联的测试客户和商品将保留\n";
} else {
    echo "[SKIP] 订货单测试：前置数据不足（客户ID={$purchaseCustId}, 商品ID={$purchaseGoodsId}）\n";
}

// ══════════════════════════════════════════════
// 步骤 13：店铺模块
// ══════════════════════════════════════════════
echo "\n── 步骤 13：店铺模块 ──\n";

// 13.1 获取店铺信息（可能为空）
$storeInfoRes = httpRequest('GET', "{$BASE_URL}/api/user/store", [], $token);
assert_code($storeInfoRes, 1, '获取店铺信息');

// 13.2 创建店铺
$storeOpenRes = httpRequest('POST', "{$BASE_URL}/api/user/open", [
    'name'    => 'SMOKE_测试店铺',
    'contact' => 'SMOKE_店主',
    'phone'   => '13600000001',
    'address' => 'SMOKE_店铺地址',
], $token);
assert_code($storeOpenRes, 1, '创建店铺');
$storeId = extractId($storeOpenRes);

// 13.3 设置店铺
if ($storeId) {
    $storeSetRes = httpRequest('POST', "{$BASE_URL}/api/user/storeset", [
        'id'      => $storeId,
        'name'    => 'SMOKE_测试店铺-改',
        'contact' => 'SMOKE_店主-改',
    ], $token);
    assert_code($storeSetRes, 1, '设置店铺');

    // 再次获取店铺信息验证更新
    $storeInfoRes2 = httpRequest('GET', "{$BASE_URL}/api/user/store", ['id' => $storeId], $token);
    assert_code($storeInfoRes2, 1, '获取店铺信息(更新后)');

    // 清理：删除测试店铺（店铺本质是 Customer，使用客户删除接口）
    $storeDelRes = httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $storeId], $token);
    assert_code($storeDelRes, 1, '清理-删除测试店铺');
} else {
    echo "[SKIP] 设置店铺：无法获取店铺ID\n";
}

// ══════════════════════════════════════════════
// 步骤 14：删除占用校验
// ══════════════════════════════════════════════
echo "\n── 步骤 14：删除占用校验 ──\n";

$deletionTestOrderId = null;

if ($orderCustId && $orderWhId && $orderGoodsId) {
    // 14.1 创建销售单（占用商品、仓库、客户）
    $deletionTestOrderRes = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
        'customer_id'  => $orderCustId,
        'warehouse_id' => $orderWhId,
        'goods'        => [[
            'goods_id' => $orderGoodsId,
            'name'     => 'SMOKE_订单测试商品',
            'number'   => 3,
            'price'    => 10.50,
            'units'    => '个',
        ]],
    ], $token);
    assert_code($deletionTestOrderRes, 1, '删除校验前置-创建销售单');
    $deletionTestOrderId = extractId($deletionTestOrderRes);

    if ($deletionTestOrderId) {
        // 14.2 尝试删除被引用的商品（期望失败）
        $delGoodsRes = httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $orderGoodsId], $token);
        assert_code($delGoodsRes, 0, '删除被引用商品（期望失败）');

        // 14.3 尝试删除被引用的仓库（期望失败）
        $delWhRes = httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $orderWhId], $token);
        assert_code($delWhRes, 0, '删除被引用仓库（期望失败）');

        // 14.4 尝试删除被引用的客户（期望失败）
        $delCustRes = httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $orderCustId], $token);
        assert_code($delCustRes, 0, '删除被引用客户（期望失败）');

        // 14.5 清理：删除销售单以释放引用
        $delTestOrderRes = httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $deletionTestOrderId], $token);
        assert_code($delTestOrderRes, 1, '删除校验清理-删除销售单');
    }
} else {
    echo "[SKIP] 删除占用校验：前置数据不足\n";
}

// 15. 清理前置数据
echo "\n── 步骤 15：清理前置数据 ──\n";

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
// 步骤 16：退出登录
// ══════════════════════════════════════════════
echo "\n── 步骤 16：退出登录 ──\n";
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
