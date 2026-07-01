<?php
namespace BeiMi\Tests\SalesReturn;

/**
 * BeiMi JXC 销售退货单专项回归测试
 *
 * 覆盖销售退货对库存和客户应收的联动影响：
 * 进货入库 -> 销售出库 -> 退货入库 -> 编辑退货 -> 删除退货 -> 清理原单。
 *
 * 使用方法：php tests/sales_return_flow_test.php [BASE_URL]
 */

$BASE_URL = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8787';
$RUN_ID = date('YmdHis') . '_' . random_int(1000, 9999);

$totalTests = 0;
$passTests = 0;
$failTests = 0;

function testName(string $name): string
{
    global $RUN_ID;
    return $name . '_' . $RUN_ID;
}

function shortTestName(string $prefix): string
{
    global $RUN_ID;
    return $prefix . substr(preg_replace('/\D/', '', $RUN_ID), -8);
}

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

function assert_code(array $response, int $expectedCode, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    $actual = (int)($response['code'] ?? -999);
    if ($actual === $expectedCode) {
        echo "[PASS] {$testName}\n";
        $passTests++;
        return true;
    }

    $reason = $response['msg'] ?? json_encode($response, JSON_UNESCAPED_UNICODE);
    echo "[FAIL] {$testName}: 期望 code={$expectedCode}, 实际 code={$actual}, msg={$reason}\n";
    $failTests++;
    return false;
}

function assert_eq($actual, $expected, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    $actualStr = number_format((float)$actual, 2, '.', '');
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

function assert_true(bool $condition, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    if ($condition) {
        echo "[PASS] {$testName}\n";
        $passTests++;
        return true;
    }

    echo "[FAIL] {$testName}\n";
    $failTests++;
    return false;
}

function assert_error_code(array $response, string $expectedErrorCode, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    $actual = (string)($response['data']['error_code'] ?? '');
    if ($actual === $expectedErrorCode) {
        echo "[PASS] {$testName}\n";
        $passTests++;
        return true;
    }

    echo "[FAIL] {$testName}: 期望 error_code={$expectedErrorCode}, 实际 error_code={$actual}\n";
    $failTests++;
    return false;
}

function extractId(array $response, string $key = 'id'): ?int
{
    return isset($response['data'][$key]) ? (int)$response['data'][$key] : null;
}

function findIdInList(array $response, string $field, string $value): ?int
{
    $items = $response['data']['data'] ?? $response['data'] ?? [];
    if (!is_array($items)) {
        return null;
    }
    foreach ($items as $item) {
        if (isset($item[$field]) && $item[$field] === $value) {
            return (int)($item['id'] ?? 0) ?: null;
        }
    }
    return null;
}

function extractStock(array $response): ?string
{
    $stock = $response['data']['stock'] ?? null;
    return $stock !== null ? (string)$stock : null;
}

function extractReceivable(array $response): ?string
{
    $receivable = $response['data']['order_receivable'] ?? null;
    return $receivable !== null ? (string)$receivable : null;
}

function createAndExtractId(string $label, string $method, string $url, array $data, string $token, ?array $fallback = null): ?int
{
    $response = httpRequest($method, $url, $data, $token);
    assert_code($response, 1, $label);
    $id = extractId($response);
    if ($id !== null || $fallback === null) {
        return $id;
    }

    $list = httpRequest('GET', $fallback['url'], [], $token);
    return findIdInList($list, $fallback['field'], $fallback['value']);
}

function goodsRow(int $goodsId, string $name, int|float $number, int|float $price, string $units): array
{
    return [
        'goods_id' => $goodsId,
        'name' => $name,
        'number' => $number,
        'price' => $price,
        'units' => $units,
    ];
}

echo "=== BeiMi JXC 销售退货单专项回归测试 ===\n";
echo "BASE_URL: {$BASE_URL}\n\n";

$loginRes = httpRequest('POST', "{$BASE_URL}/api/user/login", [
    'account' => 'jxcadmin',
    'password' => '123456',
    'terminal' => 1,
]);
assert_code($loginRes, 1, '用户登录');
$token = $loginRes['data']['token'] ?? '';
if ($token === '') {
    echo "[FATAL] 无法获取 token，终止测试。\n";
    exit(1);
}

$unitId = null;
$warehouseId = null;
$supplierId = null;
$customerId = null;
$otherCustomerId = null;
$goodsId = null;
$supplyId = null;
$salesId = null;
$salesSn = '';
$returnId = null;
$overReturnId = null;

echo "\n--- 场景1: 销售退货库存与应收闭环 ---\n";

$unitName = shortTestName('SR');
$warehouseName = testName('RETURN_仓库');
$supplierName = testName('RETURN_供应商');
$customerName = testName('RETURN_客户');
$otherCustomerName = testName('RETURN_错客');
$goodsName = testName('RETURN_商品');
$goodsCode = testName('RETURN_CODE');

$unitId = createAndExtractId(
    '创建单位',
    'POST',
    "{$BASE_URL}/api/units/add",
    ['name' => $unitName],
    $token,
    ['url' => "{$BASE_URL}/api/units/index", 'field' => 'name', 'value' => $unitName]
);
$warehouseId = createAndExtractId(
    '创建仓库',
    'POST',
    "{$BASE_URL}/api/warehouse/add",
    ['name' => $warehouseName, 'address' => testName('RETURN_地址')],
    $token,
    ['url' => "{$BASE_URL}/api/warehouse/index", 'field' => 'name', 'value' => $warehouseName]
);
$supplierId = createAndExtractId(
    '创建供应商',
    'POST',
    "{$BASE_URL}/api/supplier/add",
    ['supplier_name' => $supplierName, 'contact' => testName('RETURN_供货人'), 'phone' => '13800000021'],
    $token,
    ['url' => "{$BASE_URL}/api/supplier/index", 'field' => 'supplier_name', 'value' => $supplierName]
);
$customerId = createAndExtractId(
    '创建客户',
    'POST',
    "{$BASE_URL}/api/customer/add",
    ['customer_name' => $customerName, 'contact' => testName('RETURN_客户联系人'), 'phone' => '13800000022'],
    $token,
    ['url' => "{$BASE_URL}/api/customer/index", 'field' => 'customer_name', 'value' => $customerName]
);
$otherCustomerId = createAndExtractId(
    '创建错配客户',
    'POST',
    "{$BASE_URL}/api/customer/add",
    ['customer_name' => $otherCustomerName, 'contact' => testName('RETURN_错配联系人'), 'phone' => '13800000023'],
    $token,
    ['url' => "{$BASE_URL}/api/customer/index", 'field' => 'customer_name', 'value' => $otherCustomerName]
);
$goodsId = createAndExtractId(
    '创建商品',
    'POST',
    "{$BASE_URL}/api/goods/add",
    ['name' => $goodsName, 'product_code' => $goodsCode, 'price' => 20, 'units' => '件', 'unit_id' => $unitId],
    $token,
    ['url' => "{$BASE_URL}/api/goods/index", 'field' => 'name', 'value' => $goodsName]
);

assert_true($unitId !== null && $warehouseId !== null && $supplierId !== null && $customerId !== null && $otherCustomerId !== null && $goodsId !== null, '前置数据创建成功');
if (!$unitId || !$warehouseId || !$supplierId || !$customerId || !$otherCustomerId || !$goodsId) {
    echo "[FATAL] 前置数据不足，终止测试。\n";
    exit(1);
}

$initialStock = extractStock(httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token)) ?? '0.00';
$initialReceivable = extractReceivable(httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $customerId], $token)) ?? '0.00';

$supplyRes = httpRequest('POST', "{$BASE_URL}/api/supply/publish", [
    'supplier_id' => $supplierId,
    'warehouse_id' => $warehouseId,
    'goods' => [goodsRow($goodsId, $goodsName, 50, 10, '件')],
], $token);
assert_code($supplyRes, 1, '发布进货单入库50件');
$supplyId = extractId($supplyRes);
$stockAfterSupply = extractStock(httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
assert_eq((float)$stockAfterSupply - (float)$initialStock, 50, '进货后库存增加50');

$salesRes = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
    'customer_id' => $customerId,
    'warehouse_id' => $warehouseId,
    'order_pay_money' => 0,
    'goods' => [goodsRow($goodsId, $goodsName, 10, 20, '件')],
], $token);
assert_code($salesRes, 1, '发布销售单10件×20');
$salesId = extractId($salesRes);
$salesSn = (string)($salesRes['data']['order_sn'] ?? '');
$stockAfterSales = extractStock(httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
$receivableAfterSales = extractReceivable(httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $customerId], $token));
assert_eq((float)$stockAfterSales - (float)$initialStock, 40, '销售后库存=初始+40');
assert_eq((float)$receivableAfterSales - (float)$initialReceivable, 200, '销售后客户应收增加200');

$mismatchReturnRes = httpRequest('POST', "{$BASE_URL}/api/return/publish", [
    'original_order_id' => $salesId,
    'original_order_sn' => $salesSn,
    'customer_id' => $otherCustomerId,
    'warehouse_id' => $warehouseId,
    'goods' => [goodsRow($goodsId, $goodsName, 1, 20, '件')],
], $token);
assert_code($mismatchReturnRes, 0, '错配客户不可关联原销售单退货');

$returnRes = httpRequest('POST', "{$BASE_URL}/api/return/publish", [
    'original_order_id' => $salesId,
    'original_order_sn' => $salesSn,
    'customer_id' => $customerId,
    'warehouse_id' => $warehouseId,
    'goods' => [goodsRow($goodsId, $goodsName, 4, 20, '件')],
    'return_reason' => '专项回归退货',
], $token);
assert_code($returnRes, 1, '发布退货单4件×20');
$returnId = extractId($returnRes);
$stockAfterReturn = extractStock(httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
$receivableAfterReturn = extractReceivable(httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $customerId], $token));
assert_eq((float)$stockAfterReturn - (float)$initialStock, 44, '退货后库存=初始+44');
assert_eq((float)$receivableAfterReturn - (float)$initialReceivable, 120, '退货后客户应收=初始+120');

$overReturnRes = httpRequest('POST', "{$BASE_URL}/api/return/publish", [
    'original_order_id' => $salesId,
    'original_order_sn' => $salesSn,
    'customer_id' => $customerId,
    'warehouse_id' => $warehouseId,
    'goods' => [goodsRow($goodsId, $goodsName, 7, 20, '件')],
    'return_reason' => '专项回归超退',
], $token);
assert_code($overReturnRes, 0, '累计退货超过原销售数量必须失败');
assert_error_code($overReturnRes, 'RETURN_QTY_EXCEEDS_AVAILABLE', '超退失败返回稳定 error_code');
$overReturnId = extractId($overReturnRes);
if ($overReturnId) {
    httpRequest('DELETE', "{$BASE_URL}/api/return/remove", ['id' => $overReturnId], $token);
    $overReturnId = null;
}

$salesDetailAfterPartialReturn = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $salesId], $token);
assert_code($salesDetailAfterPartialReturn, 1, '原销售单详情-部分退货后');
assert_true((int)($salesDetailAfterPartialReturn['data']['status'] ?? 0) === 2, '部分退货后原销售单状态=2');
assert_true((string)($salesDetailAfterPartialReturn['data']['status_label'] ?? '') === '部分退货', '部分退货后原销售单返回 status_label');

$returnDetail = httpRequest('GET', "{$BASE_URL}/api/return/details", ['id' => $returnId], $token);
assert_code($returnDetail, 1, '退货单详情');
assert_true((int)($returnDetail['data']['original_sales_order_id'] ?? 0) === $salesId, '退货详情保留原销售单ID');
assert_eq($returnDetail['data']['order_money'] ?? 0, 80, '退货详情金额=80');

$returnFullEditRes = httpRequest('POST', "{$BASE_URL}/api/return/edit", [
    'id' => $returnId,
    'original_order_id' => $salesId,
    'original_order_sn' => $salesSn,
    'customer_id' => $customerId,
    'warehouse_id' => $warehouseId,
    'goods' => [goodsRow($goodsId, $goodsName, 10, 20, '件')],
    'return_reason' => '专项回归全量退货',
], $token);
assert_code($returnFullEditRes, 1, '编辑退货单为全量10件×20');
$salesDetailAfterFullReturn = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $salesId], $token);
assert_code($salesDetailAfterFullReturn, 1, '原销售单详情-全量退货后');
assert_true((int)($salesDetailAfterFullReturn['data']['status'] ?? 0) === 3, '全量退货后原销售单状态=3');
assert_true((string)($salesDetailAfterFullReturn['data']['status_label'] ?? '') === '已退货', '全量退货后原销售单返回 status_label');

$returnEditRes = httpRequest('POST', "{$BASE_URL}/api/return/edit", [
    'id' => $returnId,
    'original_order_id' => $salesId,
    'original_order_sn' => $salesSn,
    'customer_id' => $customerId,
    'warehouse_id' => $warehouseId,
    'goods' => [goodsRow($goodsId, $goodsName, 2, 20, '件')],
    'return_reason' => '专项回归退货编辑',
], $token);
assert_code($returnEditRes, 1, '编辑退货单为2件×20');
$stockAfterEdit = extractStock(httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
$receivableAfterEdit = extractReceivable(httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $customerId], $token));
assert_eq((float)$stockAfterEdit - (float)$initialStock, 42, '编辑退货后库存=初始+42');
assert_eq((float)$receivableAfterEdit - (float)$initialReceivable, 160, '编辑退货后客户应收=初始+160');

$returnDeleteRes = httpRequest('DELETE', "{$BASE_URL}/api/return/remove", ['id' => $returnId], $token);
assert_code($returnDeleteRes, 1, '删除退货单');
$returnId = null;
$stockAfterReturnDelete = extractStock(httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
$receivableAfterReturnDelete = extractReceivable(httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $customerId], $token));
assert_eq((float)$stockAfterReturnDelete - (float)$initialStock, 40, '删除退货后库存恢复销售后状态');
assert_eq((float)$receivableAfterReturnDelete - (float)$initialReceivable, 200, '删除退货后应收恢复销售后状态');

echo "\n--- 清理测试数据 ---\n";
if ($overReturnId) {
    assert_code(httpRequest('DELETE', "{$BASE_URL}/api/return/remove", ['id' => $overReturnId], $token), 1, '清理-删除误创建超退单');
}
if ($returnId) {
    assert_code(httpRequest('DELETE', "{$BASE_URL}/api/return/remove", ['id' => $returnId], $token), 1, '清理-删除退货单');
}
if ($salesId) {
    assert_code(httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $salesId], $token), 1, '清理-删除销售单');
}
if ($supplyId) {
    assert_code(httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $supplyId], $token), 1, '清理-删除进货单');
}
if ($goodsId) {
    assert_code(httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $goodsId], $token), 1, '清理-删除商品');
}
if ($otherCustomerId) {
    assert_code(httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $otherCustomerId], $token), 1, '清理-删除错配客户');
}
if ($customerId) {
    assert_code(httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $customerId], $token), 1, '清理-删除客户');
}
if ($supplierId) {
    assert_code(httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $supplierId], $token), 1, '清理-删除供应商');
}
if ($warehouseId) {
    assert_code(httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $warehouseId], $token), 1, '清理-删除仓库');
}
if ($unitId) {
    assert_code(httpRequest('DELETE', "{$BASE_URL}/api/units/del", ['id' => $unitId], $token), 1, '清理-删除单位');
}

echo "\n=== 结果: {$passTests}/{$totalTests} 通过 ===\n";
if ($failTests > 0) {
    exit(1);
}
