<?php
namespace BeiMi\Tests\PurchaseReturn;

/**
 * BeiMi JXC 采购退货 P0 回归测试
 *
 * 覆盖：原进货单必选、采购退货创建、累计超退、库存出库、应付冲减流水类型、
 * 原进货单 return_status、详情 goods 字段、删除回滚。
 *
 * 使用方法：php tests/purchase_return_flow_test.php [BASE_URL]
 */

require __DIR__ . '/support/api_test_helper.php';

$runtime = new \TestRuntime();
$BASE_URL = \test_base_url($argv);

function pr_goods_row(int $goodsId, string $name, int|float $number, int|float $price, string $units): array
{
    return [
        'goods_id' => $goodsId,
        'name' => $name,
        'number' => $number,
        'price' => $price,
        'units' => $units,
    ];
}

function pr_assert_error_code(\TestRuntime $runtime, array $response, string $expectedErrorCode, string $testName): bool
{
    return $runtime->assertTrue(
        (string)($response['data']['error_code'] ?? '') === $expectedErrorCode,
        $testName,
        'actual=' . (string)($response['data']['error_code'] ?? '')
    );
}

function pr_find_id_in_paginated_list(array $response, string $field, string $value): ?int
{
    $items = $response['data']['lists'] ?? $response['data']['data'] ?? $response['data'] ?? [];
    if (!is_array($items)) {
        return null;
    }
    foreach ($items as $item) {
        if (isset($item[$field]) && (string)$item[$field] === $value) {
            return isset($item['id']) ? (int)$item['id'] : null;
        }
    }

    return null;
}

function pr_create_and_extract_id(
    \TestRuntime $runtime,
    string $label,
    string $method,
    string $url,
    array $data,
    string $token,
    ?array $fallback = null
): ?int {
    $response = \http_request($method, $url, $data, $token);
    $runtime->assertCode($response, 1, $label);
    $id = \extract_id($response);
    if ($id !== null || $fallback === null) {
        return $id;
    }

    $list = \http_request('GET', $fallback['url'], [], $token);
    return pr_find_id_in_paginated_list($list, $fallback['field'], $fallback['value']);
}

function pr_db_value_or_null(string $sql, array $params = [])
{
    try {
        return \db_value($sql, $params);
    } catch (\Throwable $e) {
        echo "[DB-CHECK-ERROR] " . $e->getMessage() . "\n";
        return null;
    }
}

function pr_find_goods_row(array $response, int $goodsId): ?array
{
    $goods = $response['data']['goods'] ?? [];
    if (!is_array($goods)) {
        return null;
    }

    foreach ($goods as $row) {
        if ((int)($row['goods_id'] ?? 0) === $goodsId) {
            return $row;
        }
    }

    return null;
}

echo "=== BeiMi JXC 采购退货 P0 回归测试 ===\n";
echo "BASE_URL: {$BASE_URL}\n\n";

$loginRes = \login_default_admin($BASE_URL);
$runtime->assertCode($loginRes, 1, '用户登录');
$token = (string)($loginRes['data']['token'] ?? '');
if ($token === '') {
    echo "[FATAL] 无法获取 token，终止测试。\n";
    exit(1);
}

$unitId = null;
$warehouseId = null;
$supplierId = null;
$goodsId = null;
$supplyId = null;
$supplyId2 = null;
$returnId = null;
$overReturnId = null;

$unitName = \short_test_name('PR');
$warehouseName = \test_name('PURCHASE_RETURN_仓库');
$supplierName = \test_name('PURCHASE_RETURN_供应商');
$goodsName = \test_name('PURCHASE_RETURN_商品');
$goodsCode = \test_name('PURCHASE_RETURN_CODE');

echo "\n--- 场景1: 采购退货主路径与边界 ---\n";

$unitId = pr_create_and_extract_id(
    $runtime,
    '创建单位',
    'POST',
    "{$BASE_URL}/api/units/add",
    ['name' => $unitName],
    $token,
    ['url' => "{$BASE_URL}/api/units/index", 'field' => 'name', 'value' => $unitName]
);
$warehouseId = pr_create_and_extract_id(
    $runtime,
    '创建仓库',
    'POST',
    "{$BASE_URL}/api/warehouse/add",
    ['name' => $warehouseName, 'address' => \test_name('PURCHASE_RETURN_地址')],
    $token,
    ['url' => "{$BASE_URL}/api/warehouse/index", 'field' => 'name', 'value' => $warehouseName]
);
$supplierId = pr_create_and_extract_id(
    $runtime,
    '创建供应商',
    'POST',
    "{$BASE_URL}/api/supplier/add",
    ['supplier_name' => $supplierName, 'contact' => \test_name('PURCHASE_RETURN_供货人'), 'phone' => '13800000121'],
    $token,
    ['url' => "{$BASE_URL}/api/supplier/index", 'field' => 'supplier_name', 'value' => $supplierName]
);
$goodsId = pr_create_and_extract_id(
    $runtime,
    '创建商品',
    'POST',
    "{$BASE_URL}/api/goods/add",
    ['name' => $goodsName, 'product_code' => $goodsCode, 'price' => 20, 'cost' => 10, 'units' => '件', 'unit_id' => $unitId],
    $token,
    ['url' => "{$BASE_URL}/api/goods/index", 'field' => 'name', 'value' => $goodsName]
);

$runtime->assertTrue($unitId !== null && $warehouseId !== null && $supplierId !== null && $goodsId !== null, '前置数据创建成功');
if (!$unitId || !$warehouseId || !$supplierId || !$goodsId) {
    echo "[FATAL] 前置数据不足，终止测试。\n";
    exit(1);
}

$initialStock = \goods_stock(\http_request('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
$initialPayable = number_format((float)pr_db_value_or_null('SELECT order_payable FROM ' . \db_table('vendor') . ' WHERE id = ?', [$supplierId]), 2, '.', '');

$missingOriginalRes = \http_request('POST', "{$BASE_URL}/api/purchase-return/publish", [
    'supplier_id' => $supplierId,
    'warehouse_id' => $warehouseId,
    'goods' => [pr_goods_row($goodsId, $goodsName, 1, 10, '件')],
], $token);
$runtime->assertCode($missingOriginalRes, 0, '采购退货未选择原进货单必须失败');
pr_assert_error_code($runtime, $missingOriginalRes, 'RETURN_ORIGINAL_REQUIRED', '缺少原进货单返回稳定 error_code');

$missingDetailRes = \http_request('GET', "{$BASE_URL}/api/purchase-return/details", ['id' => 2147483647], $token);
$runtime->assertCode($missingDetailRes, 0, '采购退货详情不存在必须失败');
pr_assert_error_code($runtime, $missingDetailRes, 'RETURN_ORDER_NOT_FOUND', '采购退货详情不存在返回稳定 error_code');

$supplyRes = \http_request('POST', "{$BASE_URL}/api/supply/publish", [
    'supplier_id' => $supplierId,
    'warehouse_id' => $warehouseId,
    'order_pay_money' => 0,
    'goods' => [pr_goods_row($goodsId, $goodsName, 10, 10, '件')],
], $token);
$runtime->assertCode($supplyRes, 1, '发布进货单10件×10');
$supplyId = \extract_id($supplyRes);
$supplySn = (string)($supplyRes['data']['order_sn'] ?? '');
$runtime->assertTrue($supplyId !== null && $supplySn !== '', '进货单创建返回 data.id/order_sn');
$stockAfterSupply = \goods_stock(\http_request('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
$runtime->assertMoney((float)$stockAfterSupply - (float)$initialStock, 10, '进货后库存增加10');

$supplyDetailBeforeReturn = \http_request('GET', "{$BASE_URL}/api/supply/details", ['id' => $supplyId], $token);
$runtime->assertCode($supplyDetailBeforeReturn, 1, '进货详情-退货前');
$supplyGoodsBeforeReturn = pr_find_goods_row($supplyDetailBeforeReturn, $goodsId);
$runtime->assertTrue($supplyGoodsBeforeReturn !== null, '进货详情退货前存在商品行');
$runtime->assertMoney((float)($supplyGoodsBeforeReturn['returned_number'] ?? -1), 0, '进货详情退货前 returned_number=0');
$runtime->assertMoney((float)($supplyGoodsBeforeReturn['returnable_number'] ?? -1), 10, '进货详情退货前 returnable_number=10');
$runtime->assertMoney((float)($supplyGoodsBeforeReturn['max_return_number'] ?? -1), 10, '进货详情退货前 max_return_number=10');

$returnRes = \http_request('POST', "{$BASE_URL}/api/purchase-return/publish", [
    'original_order_id' => $supplyId,
    'original_order_sn' => $supplySn,
    'supplier_id' => $supplierId,
    'warehouse_id' => $warehouseId,
    'goods' => [pr_goods_row($goodsId, $goodsName, 4, 10, '件')],
    'return_reason' => '专项回归采购退货',
], $token);
$runtime->assertCode($returnRes, 1, '创建采购退货4件×10');
$returnId = \extract_id($returnRes);
$runtime->assertTrue($returnId !== null && (string)($returnRes['data']['order_sn'] ?? '') !== '', '采购退货创建返回 data.id/order_sn');

$stockAfterReturn = \goods_stock(\http_request('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
$runtime->assertMoney((float)$stockAfterReturn - (float)$initialStock, 6, '采购退货后库存=初始+6');
$payableAfterReturn = number_format((float)pr_db_value_or_null('SELECT order_payable FROM ' . \db_table('vendor') . ' WHERE id = ?', [$supplierId]), 2, '.', '');
$runtime->assertMoney((float)$payableAfterReturn - (float)$initialPayable, 60, '采购退货后应付=初始+60');

$flowType = pr_db_value_or_null(
    'SELECT flow_type FROM ' . \db_table('payable_flow') . ' WHERE order_id = ? AND order_type = ? ORDER BY id DESC LIMIT 1',
    [$returnId, 'purchase-return']
);
$runtime->assertInt((int)$flowType, 3, '采购退货应付冲减流水 type=3');

$returnStatus = pr_db_value_or_null('SELECT return_status FROM ' . \db_table('supply_order') . ' WHERE id = ?', [$supplyId]);
$runtime->assertInt((int)$returnStatus, 1, '部分采购退货后原进货单 return_status=1');

$detailRes = \http_request('GET', "{$BASE_URL}/api/purchase-return/details", ['id' => $returnId], $token);
$runtime->assertCode($detailRes, 1, '采购退货详情');
$runtime->assertTrue((string)($detailRes['data']['warehouse_name'] ?? '') === $warehouseName, '采购退货详情返回 warehouse_name');
$runtime->assertTrue((string)($detailRes['data']['warehouse'] ?? '') === $warehouseName, '采购退货详情返回 warehouse');
$runtime->assertTrue(isset($detailRes['data']['goods']) && is_array($detailRes['data']['goods']), '采购退货详情返回 data.goods');
$detailGoods = pr_find_goods_row($detailRes, $goodsId);
$runtime->assertTrue($detailGoods !== null, '采购退货详情返回目标商品行');
$runtime->assertMoney((float)($detailGoods['returned_number'] ?? -1), 4, '采购退货详情 returned_number=4');
$runtime->assertMoney((float)($detailGoods['returnable_number'] ?? -1), 6, '采购退货详情 returnable_number=6');
$runtime->assertMoney((float)($detailGoods['max_return_number'] ?? -1), 10, '采购退货详情 max_return_number=10');

$supplyDetailAfterReturn = \http_request('GET', "{$BASE_URL}/api/supply/details", ['id' => $supplyId], $token);
$runtime->assertCode($supplyDetailAfterReturn, 1, '进货详情-退货后');
$supplyGoodsAfterReturn = pr_find_goods_row($supplyDetailAfterReturn, $goodsId);
$runtime->assertTrue($supplyGoodsAfterReturn !== null, '进货详情退货后存在商品行');
$runtime->assertMoney((float)($supplyGoodsAfterReturn['returned_number'] ?? -1), 4, '进货详情退货后 returned_number=4');
$runtime->assertMoney((float)($supplyGoodsAfterReturn['returnable_number'] ?? -1), 6, '进货详情退货后 returnable_number=6');
$runtime->assertMoney((float)($supplyGoodsAfterReturn['max_return_number'] ?? -1), 6, '进货详情退货后 max_return_number=6');

$editRes = \http_request('POST', "{$BASE_URL}/api/purchase-return/edit", [
    'id' => $returnId,
    'original_order_id' => $supplyId,
    'original_supply_order_id' => $supplyId,
    'supplier_id' => $supplierId,
    'warehouse_id' => $warehouseId,
    'goods' => [pr_goods_row($goodsId, $goodsName, 2, 10, '件')],
    'return_reason' => '专项回归采购退货-编辑减量',
], $token);
$runtime->assertCode($editRes, 1, '编辑采购退货4件改2件');
$editGoods = pr_find_goods_row($editRes, $goodsId);
$runtime->assertTrue($editGoods !== null, '编辑采购退货返回详情商品行');
$runtime->assertMoney((float)($editGoods['returned_number'] ?? -1), 2, '编辑采购退货返回详情 returned_number=2');
$runtime->assertMoney((float)($editGoods['returnable_number'] ?? -1), 8, '编辑采购退货返回详情 returnable_number=8');
$runtime->assertMoney((float)($editGoods['max_return_number'] ?? -1), 10, '编辑采购退货返回详情 max_return_number=10');
$runtime->assertTrue(
    (float)($editGoods['max_return_number'] ?? 0) >= (float)($editGoods['return_num'] ?? $editGoods['number'] ?? 0),
    '编辑采购退货返回详情 max_return_number 不小于当前退货数量'
);
$stockAfterEdit = \goods_stock(\http_request('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
$runtime->assertMoney((float)$stockAfterEdit - (float)$initialStock, 8, '编辑采购退货后库存=初始+8');
$payableAfterEdit = number_format((float)pr_db_value_or_null('SELECT order_payable FROM ' . \db_table('vendor') . ' WHERE id = ?', [$supplierId]), 2, '.', '');
$runtime->assertMoney((float)$payableAfterEdit - (float)$initialPayable, 80, '编辑采购退货后应付=初始+80');
$returnStatusAfterEdit = pr_db_value_or_null('SELECT return_status FROM ' . \db_table('supply_order') . ' WHERE id = ?', [$supplyId]);
$runtime->assertInt((int)$returnStatusAfterEdit, 1, '编辑采购退货后原进货单 return_status 仍为1');

$supplyDetailAfterEdit = \http_request('GET', "{$BASE_URL}/api/supply/details", ['id' => $supplyId], $token);
$runtime->assertCode($supplyDetailAfterEdit, 1, '进货详情-编辑采购退货后');
$supplyGoodsAfterEdit = pr_find_goods_row($supplyDetailAfterEdit, $goodsId);
$runtime->assertTrue($supplyGoodsAfterEdit !== null, '进货详情编辑后存在商品行');
$runtime->assertMoney((float)($supplyGoodsAfterEdit['returned_number'] ?? -1), 2, '进货详情编辑后 returned_number=2');
$runtime->assertMoney((float)($supplyGoodsAfterEdit['returnable_number'] ?? -1), 8, '进货详情编辑后 returnable_number=8');
$runtime->assertMoney((float)($supplyGoodsAfterEdit['max_return_number'] ?? -1), 8, '进货详情编辑后 max_return_number=8');

$supplyRes2 = \http_request('POST', "{$BASE_URL}/api/supply/publish", [
    'supplier_id' => $supplierId,
    'warehouse_id' => $warehouseId,
    'order_pay_money' => 0,
    'goods' => [pr_goods_row($goodsId, $goodsName, 3, 10, '件')],
], $token);
$runtime->assertCode($supplyRes2, 1, '发布第二张原进货单3件×10');
$supplyId2 = \extract_id($supplyRes2);
$runtime->assertTrue($supplyId2 !== null, '第二张原进货单返回 data.id');

$switchOriginalRes = \http_request('POST', "{$BASE_URL}/api/purchase-return/edit", [
    'id' => $returnId,
    'original_order_id' => $supplyId2,
    'original_supply_order_id' => $supplyId2,
    'supplier_id' => $supplierId,
    'warehouse_id' => $warehouseId,
    'goods' => [pr_goods_row($goodsId, $goodsName, 2, 10, '件')],
    'return_reason' => '专项回归采购退货-尝试切换原单',
], $token);
$runtime->assertCode($switchOriginalRes, 0, '编辑采购退货时切换原进货单必须失败');
pr_assert_error_code($runtime, $switchOriginalRes, 'RETURN_ORIGINAL_LOCKED', '切换原进货单失败返回稳定 error_code');

$detailAfterSwitchFail = \http_request('GET', "{$BASE_URL}/api/purchase-return/details", ['id' => $returnId], $token);
$runtime->assertCode($detailAfterSwitchFail, 1, '切换原单失败后采购退货详情仍可读取');
$runtime->assertInt((int)($detailAfterSwitchFail['data']['original_supply_order_id'] ?? 0), $supplyId, '切换原单失败后 original_supply_order_id 保持不变');

$overReturnRes = \http_request('POST', "{$BASE_URL}/api/purchase-return/publish", [
    'original_order_id' => $supplyId,
    'original_order_sn' => $supplySn,
    'supplier_id' => $supplierId,
    'warehouse_id' => $warehouseId,
    'goods' => [pr_goods_row($goodsId, $goodsName, 9, 10, '件')],
    'return_reason' => '专项回归采购超退',
], $token);
$runtime->assertCode($overReturnRes, 0, '累计采购退货超过原进货数量必须失败');
pr_assert_error_code($runtime, $overReturnRes, 'RETURN_QTY_EXCEEDS_AVAILABLE', '采购超退失败返回稳定 error_code');
$overReturnId = \extract_id($overReturnRes);
if ($overReturnId) {
    \http_request('DELETE', "{$BASE_URL}/api/purchase-return/remove", ['id' => $overReturnId], $token);
    $overReturnId = null;
}

$deleteRes = \http_request('DELETE', "{$BASE_URL}/api/purchase-return/remove", ['id' => $returnId], $token);
$runtime->assertCode($deleteRes, 1, '删除采购退货单');
$returnId = null;
$stockAfterDelete = \goods_stock(\http_request('GET', "{$BASE_URL}/api/goods/detail", ['id' => $goodsId], $token));
$runtime->assertMoney((float)$stockAfterDelete - (float)$initialStock, 10, '删除采购退货后库存恢复进货后状态');
$returnStatusAfterDelete = pr_db_value_or_null('SELECT return_status FROM ' . \db_table('supply_order') . ' WHERE id = ?', [$supplyId]);
$runtime->assertInt((int)$returnStatusAfterDelete, 0, '删除采购退货后原进货单 return_status=0');

echo "\n--- 清理测试数据 ---\n";
if ($overReturnId) {
    $runtime->assertCode(\http_request('DELETE', "{$BASE_URL}/api/purchase-return/remove", ['id' => $overReturnId], $token), 1, '清理-删除误创建采购超退单');
}
if ($returnId) {
    $runtime->assertCode(\http_request('DELETE', "{$BASE_URL}/api/purchase-return/remove", ['id' => $returnId], $token), 1, '清理-删除采购退货单');
}
if ($supplyId2) {
    $runtime->assertCode(\http_request('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $supplyId2], $token), 1, '清理-删除第二张进货单');
}
if ($supplyId) {
    $runtime->assertCode(\http_request('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $supplyId], $token), 1, '清理-删除进货单');
}
if ($goodsId) {
    $runtime->assertCode(\http_request('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $goodsId], $token), 1, '清理-删除商品');
}
if ($supplierId) {
    $runtime->assertCode(\http_request('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $supplierId], $token), 1, '清理-删除供应商');
}
if ($warehouseId) {
    $runtime->assertCode(\http_request('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $warehouseId], $token), 1, '清理-删除仓库');
}
if ($unitId) {
    $runtime->assertCode(\http_request('DELETE', "{$BASE_URL}/api/units/del", ['id' => $unitId], $token), 1, '清理-删除单位');
}

exit($runtime->printSummary());
