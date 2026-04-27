<?php
/**
 * BeiMi JXC 多租户扩展隔离测试
 * 覆盖店铺、订货单、销售退货单、审计日志、聚合汇总与统计接口
 * 使用方法：php tests/isolation_extended_test.php [BASE_URL]
 */

declare(strict_types=1);

require __DIR__ . '/support/api_test_helper.php';

function ensure_second_tenant(): array
{
    $env = load_env_config();
    $prefix = (string)($env['DATABASE']['PREFIX'] ?? 'la_');
    $salt = (string)($env['PROJECT']['UNIQUE_IDENTIFICATION'] ?? '');
    $defaultPassword = (string)($env['PROJECT']['DEFAULT_PASSWORD'] ?? '123456');
    $pdo = test_pdo();
    $now = time();

    $tenantTable = $prefix . 'tenant';
    $adminTable = $prefix . 'tenant_admin';
    $sessionTable = $prefix . 'tenant_admin_session';
    $tenantId = 99;
    $account = 'iso_test_admin_b';

    $stmt = $pdo->prepare("SELECT id FROM `{$tenantTable}` WHERE id = :id");
    $stmt->execute(['id' => $tenantId]);
    if (!$stmt->fetchColumn()) {
        $insertTenant = $pdo->prepare(<<<SQL
INSERT INTO `{$tenantTable}`
(`id`, `sn`, `name`, `avatar`, `tel`, `disable`, `tactics`, `notes`, `domain_alias`, `domain_alias_enable`, `create_time`, `update_time`, `delete_time`)
VALUES
(:id, :sn, :name, '', '', 0, 0, :notes, '', 1, :create_time, :update_time, NULL)
SQL
        );
        $insertTenant->execute([
            'id' => $tenantId,
            'sn' => 'ISO-EXT-002',
            'name' => 'Isolation Extended Tenant B',
            'notes' => 'Auto-created for isolation extended test.',
            'create_time' => $now,
            'update_time' => $now,
        ]);
    } else {
        $pdo->prepare("UPDATE `{$tenantTable}` SET delete_time = NULL, update_time = :update_time WHERE id = :id")
            ->execute(['update_time' => $now, 'id' => $tenantId]);
    }

    $stmt = $pdo->prepare("SELECT id FROM `{$adminTable}` WHERE account = :account LIMIT 1");
    $stmt->execute(['account' => $account]);
    $adminId = $stmt->fetchColumn();
    if (!$adminId) {
        $hashedPassword = md5($salt . md5($defaultPassword . $salt));
        $insertAdmin = $pdo->prepare(<<<SQL
INSERT INTO `{$adminTable}`
(`tenant_id`, `root`, `name`, `avatar`, `account`, `password`, `login_time`, `login_ip`, `multipoint_login`, `disable`, `create_time`, `update_time`, `delete_time`)
VALUES
(:tenant_id, 1, :name, '', :account, :password, NULL, '', 1, 0, :create_time, :update_time, NULL)
SQL
        );
        $insertAdmin->execute([
            'tenant_id' => $tenantId,
            'name' => 'Isolation Extended Admin B',
            'account' => $account,
            'password' => $hashedPassword,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $adminId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE `{$adminTable}` SET tenant_id = :tenant_id, delete_time = NULL, update_time = :update_time WHERE id = :id")
            ->execute([
                'tenant_id' => $tenantId,
                'update_time' => $now,
                'id' => $adminId,
            ]);
    }

    $pdo->prepare("DELETE FROM `{$sessionTable}` WHERE admin_id = :admin_id")->execute(['admin_id' => $adminId]);

    return [
        'tenant_id' => $tenantId,
        'admin_id' => (int)$adminId,
        'account' => $account,
        'password' => $defaultPassword,
    ];
}

function cleanup_second_tenant_extended(): void
{
    $env = load_env_config();
    $prefix = (string)($env['DATABASE']['PREFIX'] ?? 'la_');
    $pdo = test_pdo();
    $now = time();

    $adminTable = $prefix . 'tenant_admin';
    $sessionTable = $prefix . 'tenant_admin_session';
    $tenantTable = $prefix . 'tenant';
    $testTenantId = 99;
    $testAccount = 'iso_test_admin_b';

    $pdo->prepare("DELETE FROM `{$sessionTable}` WHERE admin_id IN (SELECT id FROM `{$adminTable}` WHERE account = :account)")
        ->execute(['account' => $testAccount]);

    $businessTables = [
        'audit_log',
        'order_goods',
        'stock_flow',
        'receivable_flow',
        'payable_flow',
        'sales_return_order',
        'sales_order',
        'purchase_order',
        'supply_order',
        'goods',
        'customer',
        'vendor',
        'warehouse',
        'goods_unit',
        'customer_group',
    ];

    foreach ($businessTables as $table) {
        $fullTable = $prefix . $table;
        try {
            $pdo->exec("DELETE FROM `{$fullTable}` WHERE tenant_id = {$testTenantId}");
        } catch (Throwable $e) {
        }
    }

    $pdo->prepare("UPDATE `{$adminTable}` SET delete_time = :delete_time WHERE account = :account AND delete_time IS NULL")
        ->execute(['delete_time' => $now, 'account' => $testAccount]);
    $pdo->prepare("UPDATE `{$tenantTable}` SET delete_time = :delete_time WHERE id = :id")
        ->execute(['delete_time' => $now, 'id' => $testTenantId]);
}

function create_isolation_bundle(string $baseUrl, string $token, string $label, int $timestamp): array
{
    $bundle = [
        'customerId' => null,
        'warehouseId' => null,
        'supplierId' => null,
        'goodsId' => null,
        'salesOrderId' => null,
        'salesOrderSn' => '',
        'purchaseOrderId' => null,
        'returnOrderId' => null,
        'storeId' => null,
        'customerName' => test_name($label . '_客户'),
        'storeName' => test_name($label . '_门店'),
        'goodsName' => test_name($label . '_商品'),
    ];

    $customer = http_request('POST', $baseUrl . '/api/customer/add', [
        'customer_name' => $bundle['customerName'],
        'contact' => $label . '_联系人',
        'phone' => '13700000001',
    ], $token);
    $bundle['customerId'] = extract_id($customer);

    $warehouse = http_request('POST', $baseUrl . '/api/warehouse/add', [
        'name' => test_name($label . '_仓库'),
        'address' => test_name($label . '_仓库地址'),
    ], $token);
    $bundle['warehouseId'] = extract_id($warehouse);

    $supplier = http_request('POST', $baseUrl . '/api/supplier/add', [
        'supplier_name' => test_name($label . '_供应商'),
        'contact' => $label . '_供应联系人',
        'phone' => '13700000002',
    ], $token);
    $bundle['supplierId'] = extract_id($supplier);

    $goods = http_request('POST', $baseUrl . '/api/goods/add', [
        'name' => $bundle['goodsName'],
        'product_code' => short_test_name($label . 'G'),
        'price' => 10,
        'units' => '个',
    ], $token);
    $bundle['goodsId'] = extract_id($goods);

    $sales = http_request('POST', $baseUrl . '/api/order/publish', [
        'order_sn' => test_name($label . '_销售单'),
        'customer_id' => $bundle['customerId'],
        'warehouse_id' => $bundle['warehouseId'],
        'datetimesingle' => $timestamp,
        'goods' => [[
            'goods_id' => $bundle['goodsId'],
            'name' => $bundle['goodsName'],
            'number' => 2,
            'price' => 10,
            'units' => '个',
        ]],
    ], $token);
    $bundle['salesOrderId'] = extract_id($sales);
    $bundle['salesOrderSn'] = (string)($sales['data']['order_sn'] ?? '');

    $purchase = http_request('POST', $baseUrl . '/api/purchase/publish', [
        'customer_id' => $bundle['customerId'],
        'warehouse_id' => $bundle['warehouseId'],
        'datetimesingle' => $timestamp,
        'goods' => [[
            'goods_id' => $bundle['goodsId'],
            'name' => $bundle['goodsName'],
            'number' => 3,
            'price' => 10,
            'units' => '个',
        ]],
    ], $token);
    $bundle['purchaseOrderId'] = extract_id($purchase);

    $return = http_request('POST', $baseUrl . '/api/return/publish', [
        'customer_id' => $bundle['customerId'],
        'warehouse_id' => $bundle['warehouseId'],
        'original_order_id' => $bundle['salesOrderId'],
        'original_order_sn' => $bundle['salesOrderSn'],
        'datetimesingle' => $timestamp,
        'goods' => [[
            'goods_id' => $bundle['goodsId'],
            'name' => $bundle['goodsName'],
            'number' => 1,
            'price' => 10,
            'units' => '个',
        ]],
    ], $token);
    $bundle['returnOrderId'] = extract_id($return);

    $store = http_request('POST', $baseUrl . '/api/user/open', [
        'parent_id' => $bundle['customerId'],
        'name' => $bundle['storeName'],
        'contact' => $label . '_门店联系人',
        'phone' => '13700000003',
    ], $token);
    $bundle['storeId'] = extract_id($store);

    return $bundle;
}

function cleanup_isolation_bundle(string $baseUrl, string $token, array $bundle): void
{
    if (!empty($bundle['returnOrderId'])) {
        http_request('DELETE', $baseUrl . '/api/return/remove', ['id' => $bundle['returnOrderId']], $token);
    }
    if (!empty($bundle['salesOrderId'])) {
        http_request('DELETE', $baseUrl . '/api/order/remove', ['id' => $bundle['salesOrderId']], $token);
    }
    if (!empty($bundle['purchaseOrderId'])) {
        http_request('DELETE', $baseUrl . '/api/purchase/remove', ['id' => $bundle['purchaseOrderId']], $token);
    }
    if (!empty($bundle['storeId'])) {
        http_request('DELETE', $baseUrl . '/api/customer/del', ['id' => $bundle['storeId']], $token);
    }
    if (!empty($bundle['goodsId'])) {
        http_request('DELETE', $baseUrl . '/api/goods/del', ['id' => $bundle['goodsId']], $token);
    }
    if (!empty($bundle['customerId'])) {
        http_request('DELETE', $baseUrl . '/api/customer/del', ['id' => $bundle['customerId']], $token);
    }
    if (!empty($bundle['supplierId'])) {
        http_request('DELETE', $baseUrl . '/api/supplier/del', ['id' => $bundle['supplierId']], $token);
    }
    if (!empty($bundle['warehouseId'])) {
        http_request('POST', $baseUrl . '/api/warehouse/del', ['id' => $bundle['warehouseId']], $token);
    }
}

$BASE_URL = test_base_url($argv);
$runtime = new TestRuntime();
$eventBaseTs = time() + 600;
$windowStart = $eventBaseTs - 1;

echo "=== BeiMi JXC 多租户扩展隔离测试 ===\n";
echo "BASE_URL: {$BASE_URL}\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

$loginA = login_default_admin($BASE_URL);
if (!$runtime->assertCode($loginA, 1, '租户A登录')) {
    exit(1);
}
$tokenA = (string)($loginA['data']['token'] ?? '');
$tenantAId = (int)($loginA['data']['user_info']['tenant_id'] ?? 0);

$tenantBInfo = ensure_second_tenant();
$loginB = http_request('POST', $BASE_URL . '/api/user/login', [
    'account' => $tenantBInfo['account'],
    'password' => $tenantBInfo['password'],
    'terminal' => 1,
]);
if (!$runtime->assertCode($loginB, 1, '租户B登录')) {
    exit(1);
}
$tokenB = (string)($loginB['data']['token'] ?? '');
$tenantBId = (int)($loginB['data']['user_info']['tenant_id'] ?? 0);
$runtime->assertTrue($tenantAId !== 0 && $tenantAId !== $tenantBId, '租户A/B tenant_id 不同');

echo "Step 1: 创建扩展隔离测试数据 ... ";
$timestampA = $eventBaseTs;
$bundleA = create_isolation_bundle($BASE_URL, $tokenA, 'ISOX_A', $timestampA);
$timestampB = $eventBaseTs + 1;
$bundleB = create_isolation_bundle($BASE_URL, $tokenB, 'ISOX_B', $timestampB);
$readyA = !empty($bundleA['customerId']) && !empty($bundleA['purchaseOrderId']) && !empty($bundleA['returnOrderId']) && !empty($bundleA['storeId']);
$readyB = !empty($bundleB['customerId']) && !empty($bundleB['purchaseOrderId']) && !empty($bundleB['returnOrderId']) && !empty($bundleB['storeId']);
echo ($readyA && $readyB) ? "OK\n" : "FAIL\n";
$runtime->assertTrue($readyA && $readyB, '扩展隔离前置数据创建成功');

if ($readyA && $readyB) {
    $purchaseListA = http_request('GET', $BASE_URL . '/api/purchase/lists', [], $tokenA);
    $purchaseListB = http_request('GET', $BASE_URL . '/api/purchase/lists', [], $tokenB);
    $runtime->assertTrue(!list_contains_id(extract_list($purchaseListA), (int)$bundleB['purchaseOrderId']), '租户A订货单列表不含租户B数据');
    $runtime->assertTrue(!list_contains_id(extract_list($purchaseListB), (int)$bundleA['purchaseOrderId']), '租户B订货单列表不含租户A数据');

    $returnListA = http_request('GET', $BASE_URL . '/api/return/lists', [], $tokenA);
    $returnListB = http_request('GET', $BASE_URL . '/api/return/lists', [], $tokenB);
    $runtime->assertTrue(!list_contains_id(extract_list($returnListA), (int)$bundleB['returnOrderId']), '租户A退货单列表不含租户B数据');
    $runtime->assertTrue(!list_contains_id(extract_list($returnListB), (int)$bundleA['returnOrderId']), '租户B退货单列表不含租户A数据');

    $crossPurchaseDetail = http_request('GET', $BASE_URL . '/api/purchase/details', ['id' => $bundleA['purchaseOrderId']], $tokenB);
    $runtime->assertTrue(detail_hidden_from_tenant($crossPurchaseDetail, (int)$bundleA['purchaseOrderId']), '租户B无法查看租户A订货单详情');

    $crossReturnDetail = http_request('GET', $BASE_URL . '/api/return/details', ['id' => $bundleB['returnOrderId']], $tokenA);
    $runtime->assertTrue(detail_hidden_from_tenant($crossReturnDetail, (int)$bundleB['returnOrderId']), '租户A无法查看租户B退货单详情');

    $storeAOwn = http_request('GET', $BASE_URL . '/api/user/store', ['id' => $bundleA['storeId']], $tokenA);
    $runtime->assertCode($storeAOwn, 1, '租户A可查看自己的店铺详情');
    $crossStore = http_request('GET', $BASE_URL . '/api/user/store', ['id' => $bundleA['storeId']], $tokenB);
    $runtime->assertTrue(detail_hidden_from_tenant($crossStore, (int)$bundleA['storeId']), '租户B无法查看租户A店铺详情');

    $summaryA = http_request('GET', $BASE_URL . '/api/customer/summary', ['parent_id' => $bundleA['customerId']], $tokenA);
    $summaryB = http_request('GET', $BASE_URL . '/api/customer/summary', ['parent_id' => $bundleB['customerId']], $tokenB);
    $summaryCross = http_request('GET', $BASE_URL . '/api/customer/summary', ['parent_id' => $bundleA['customerId']], $tokenB);
    $runtime->assertTrue(list_contains($summaryA['data']['detail'] ?? [], 'customer_name', (string)$bundleA['storeName']), '租户A客户汇总含自己的门店');
    $runtime->assertTrue(list_contains($summaryB['data']['detail'] ?? [], 'customer_name', (string)$bundleB['storeName']), '租户B客户汇总含自己的门店');
    $runtime->assertTrue(!list_contains($summaryCross['data']['detail'] ?? [], 'customer_name', (string)$bundleA['storeName']), '租户B客户汇总不泄漏租户A门店');

    $receivableA = http_request('GET', $BASE_URL . '/api/customer/receivableSummary', ['pagesize' => 50], $tokenA);
    $receivableB = http_request('GET', $BASE_URL . '/api/customer/receivableSummary', ['pagesize' => 50], $tokenB);
    $runtime->assertTrue(list_contains(extract_list($receivableA), 'customer_name', (string)$bundleA['customerName']), '租户A应收汇总含自己的客户');
    $runtime->assertTrue(!list_contains(extract_list($receivableA), 'customer_name', (string)$bundleB['customerName']), '租户A应收汇总不含租户B客户');
    $runtime->assertTrue(list_contains(extract_list($receivableB), 'customer_name', (string)$bundleB['customerName']), '租户B应收汇总含自己的客户');
    $runtime->assertTrue(!list_contains(extract_list($receivableB), 'customer_name', (string)$bundleA['customerName']), '租户B应收汇总不含租户A客户');

    $auditA = http_request('GET', $BASE_URL . '/api/audit/lists', ['pagesize' => 100], $tokenA);
    $auditB = http_request('GET', $BASE_URL . '/api/audit/lists', ['pagesize' => 100], $tokenB);
    $auditItemsA = extract_list($auditA);
    $auditItemsB = extract_list($auditB);
    $runtime->assertTrue(!list_contains_id($auditItemsA, (int)$bundleB['purchaseOrderId'], 'target_id'), '租户A审计日志不含租户B订货单');
    $runtime->assertTrue(!list_contains_id($auditItemsA, (int)$bundleB['returnOrderId'], 'target_id'), '租户A审计日志不含租户B退货单');
    $runtime->assertTrue(!list_contains_id($auditItemsB, (int)$bundleA['storeId'], 'target_id'), '租户B审计日志不含租户A店铺');

    $statsEnd = $eventBaseTs + 5;
    $orderStatsA = http_request('GET', $BASE_URL . '/api/order/statistics', ['start_time' => $windowStart, 'end_time' => $statsEnd], $tokenA);
    $orderStatsB = http_request('GET', $BASE_URL . '/api/order/statistics', ['start_time' => $windowStart, 'end_time' => $statsEnd], $tokenB);
    $purchaseStatsA = http_request('GET', $BASE_URL . '/api/purchase/statistics', ['start_time' => $windowStart, 'end_time' => $statsEnd], $tokenA);
    $purchaseStatsB = http_request('GET', $BASE_URL . '/api/purchase/statistics', ['start_time' => $windowStart, 'end_time' => $statsEnd], $tokenB);
    $runtime->assertInt($orderStatsA['data']['number'] ?? 0, 1, '租户A销售统计仅统计自己的销售单');
    $runtime->assertInt($orderStatsB['data']['number'] ?? 0, 1, '租户B销售统计仅统计自己的销售单');
    $runtime->assertInt($purchaseStatsA['data']['total_orders'] ?? 0, 1, '租户A订货统计仅统计自己的订货单');
    $runtime->assertInt($purchaseStatsB['data']['total_orders'] ?? 0, 1, '租户B订货统计仅统计自己的订货单');
}

echo "\nStep 2: 清理测试数据 ... ";
cleanup_isolation_bundle($BASE_URL, $tokenA, $bundleA ?? []);
cleanup_isolation_bundle($BASE_URL, $tokenB, $bundleB ?? []);
cleanup_second_tenant_extended();
echo "OK\n";

http_request('POST', $BASE_URL . '/api/user/logout', [], $tokenA);
http_request('POST', $BASE_URL . '/api/user/logout', [], $tokenB);

echo "\n结束时间: " . date('Y-m-d H:i:s') . "\n";
exit($runtime->printSummary());
