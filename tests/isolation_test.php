<?php
/**
 * BeiMi JXC 多租户隔离集成测试
 * 验证两个租户账号交叉操作数据不泄漏
 * 使用方法：php tests/isolation_test.php [BASE_URL]
 */

$BASE_URL = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8787';

// ─────────────────────────────────────────────
// 全局计数器
// ─────────────────────────────────────────────
$totalTests = 0;
$passTests  = 0;
$failTests  = 0;

// ─────────────────────────────────────────────
// 辅助函数（复用 smoke_test.php 模式）
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
 * 断言条件为真
 */
function assert_true(bool $condition, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $passTests++;
        return true;
    }

    echo "  [FAIL] {$testName}\n";
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
 * 从列表响应中提取所有记录
 */
function extractList(array $response): array
{
    $items = $response['data']['data'] ?? $response['data'] ?? [];
    return is_array($items) ? $items : [];
}

/**
 * 检查列表中是否包含指定字段值的记录
 */
function listContains(array $items, string $field, string $value): bool
{
    foreach ($items as $item) {
        if (isset($item[$field]) && $item[$field] === $value) {
            return true;
        }
    }
    return false;
}

/**
 * 检查列表中是否包含指定 id 的记录
 */
function listContainsId(array $items, int $targetId): bool
{
    foreach ($items as $item) {
        if (isset($item['id']) && (int)$item['id'] === $targetId) {
            return true;
        }
    }
    return false;
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
// 数据库辅助：直接操作 DB 创建/清理第二租户
// ─────────────────────────────────────────────

/**
 * 读取 .env 配置
 */
function loadEnv(): array
{
    $envPath = dirname(__DIR__) . '/.env';
    $env = parse_ini_file($envPath, true, INI_SCANNER_TYPED);
    if ($env === false) {
        fwrite(STDERR, "Failed to read .env at {$envPath}\n");
        exit(1);
    }
    return $env;
}

/**
 * 获取 PDO 连接
 */
function getPdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $env = loadEnv();
    $db = $env['DATABASE'] ?? [];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['HOSTNAME'] ?? '127.0.0.1',
        $db['HOSTPORT'] ?? '3306',
        $db['DATABASE'] ?? '',
        $db['CHARSET'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $db['USERNAME'] ?? '', $db['PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

/**
 * 创建第二租户和第二管理员（如果不存在）
 * @return array ['tenant_id' => int, 'admin_id' => int, 'account' => string]
 */
function ensureSecondTenant(): array
{
    $env = loadEnv();
    $prefix = $env['DATABASE']['PREFIX'] ?? 'la_';
    $salt = $env['PROJECT']['UNIQUE_IDENTIFICATION'] ?? '';
    $defaultPassword = $env['PROJECT']['DEFAULT_PASSWORD'] ?? '123456';

    $tenantTable = $prefix . 'tenant';
    $adminTable  = $prefix . 'tenant_admin';
    $sessionTable = $prefix . 'tenant_admin_session';

    $pdo = getPdo();
    $now = time();

    $testTenantId = 9999;
    $testAccount  = 'iso_test_admin_b';

    // 1. 确保第二租户存在
    $stmt = $pdo->prepare("SELECT id FROM `{$tenantTable}` WHERE id = :id");
    $stmt->execute(['id' => $testTenantId]);
    if (!$stmt->fetchColumn()) {
        $insertTenant = $pdo->prepare(<<<SQL
INSERT INTO `{$tenantTable}`
    (`id`, `sn`, `name`, `avatar`, `tel`, `disable`, `tactics`, `notes`, `domain_alias`, `domain_alias_enable`, `create_time`, `update_time`, `delete_time`)
VALUES
    (:id, :sn, :name, '', '', 0, 0, :notes, '', 1, :create_time, :update_time, NULL)
SQL
        );
        $insertTenant->execute([
            'id' => $testTenantId,
            'sn' => 'ISO-TEST-002',
            'name' => 'Isolation Test Tenant B',
            'notes' => 'Auto-created for isolation test. Can be deleted safely.',
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    // 2. 确保第二管理员存在
    $stmt = $pdo->prepare("SELECT id FROM `{$adminTable}` WHERE account = :account AND delete_time IS NULL");
    $stmt->execute(['account' => $testAccount]);
    $existingAdminId = $stmt->fetchColumn();

    if (!$existingAdminId) {
        $hashedPassword = md5($salt . md5($defaultPassword . $salt));
        $insertAdmin = $pdo->prepare(<<<SQL
INSERT INTO `{$adminTable}`
    (`tenant_id`, `root`, `name`, `avatar`, `account`, `password`, `login_time`, `login_ip`, `multipoint_login`, `disable`, `create_time`, `update_time`, `delete_time`)
VALUES
    (:tenant_id, 1, :name, '', :account, :password, NULL, '', 1, 0, :create_time, :update_time, NULL)
SQL
        );
        $insertAdmin->execute([
            'tenant_id' => $testTenantId,
            'name' => 'Isolation Test Admin B',
            'account' => $testAccount,
            'password' => $hashedPassword,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $existingAdminId = $pdo->lastInsertId();
    }

    // 3. 清除该管理员的旧 session（避免 token 冲突）
    $clearSession = $pdo->prepare("DELETE FROM `{$sessionTable}` WHERE admin_id = :admin_id");
    $clearSession->execute(['admin_id' => $existingAdminId]);

    return [
        'tenant_id' => $testTenantId,
        'admin_id'  => (int)$existingAdminId,
        'account'   => $testAccount,
        'password'  => $defaultPassword,
    ];
}

/**
 * 清理第二租户的测试数据和管理员
 */
function cleanupSecondTenant(): void
{
    $env = loadEnv();
    $prefix = $env['DATABASE']['PREFIX'] ?? 'la_';
    $pdo = getPdo();

    $adminTable  = $prefix . 'tenant_admin';
    $sessionTable = $prefix . 'tenant_admin_session';
    $tenantTable = $prefix . 'tenant';

    $testAccount  = 'iso_test_admin_b';
    $testTenantId = 9999;

    // 清理管理员 session
    $stmt = $pdo->prepare("DELETE FROM `{$sessionTable}` WHERE admin_id IN (SELECT id FROM `{$adminTable}` WHERE account = :account)");
    $stmt->execute(['account' => $testAccount]);

    // 软删除管理员
    $now = time();
    $stmt = $pdo->prepare("UPDATE `{$adminTable}` SET delete_time = :now WHERE account = :account AND delete_time IS NULL");
    $stmt->execute(['now' => $now, 'account' => $testAccount]);

    // 清理租户下所有业务数据（按表逐一清理）
    $businessTables = [
        'jxc_sales_order_item',
        'jxc_sales_order',
        'jxc_supply_order_item',
        'jxc_supply_order',
        'jxc_sales_return_order_item',
        'jxc_sales_return_order',
        'jxc_purchase_order_item',
        'jxc_purchase_order',
        'jxc_goods_stock',
        'jxc_goods',
        'jxc_goods_unit',
        'jxc_warehouse',
        'jxc_customer',
        'jxc_customer_group',
        'jxc_supplier',
        'jxc_audit_log',
    ];
    foreach ($businessTables as $table) {
        $fullTable = $prefix . $table;
        try {
            $pdo->exec("DELETE FROM `{$fullTable}` WHERE tenant_id = {$testTenantId}");
        } catch (\Throwable $e) {
            // 表可能不存在，忽略
        }
    }

    // 软删除租户
    $stmt = $pdo->prepare("UPDATE `{$tenantTable}` SET delete_time = :now WHERE id = :id AND delete_time IS NULL");
    $stmt->execute(['now' => $now, 'id' => $testTenantId]);
}

// ─────────────────────────────────────────────
// 主测试流程
// ─────────────────────────────────────────────

echo "=== BeiMi JXC 多租户隔离测试 ===\n";
echo "BASE_URL: {$BASE_URL}\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

// ══════════════════════════════════════════════
// Step 1：租户A登录
// ══════════════════════════════════════════════
echo "Step 1: 租户A登录 ... ";
$loginA = httpRequest('POST', "{$BASE_URL}/api/user/login", [
    'account'  => 'jxcadmin',
    'password' => '123456',
    'terminal' => 1,
]);
if (assert_code($loginA, 1, '租户A登录')) {
    echo "OK\n";
} else {
    echo "FAIL\n";
    echo "[FATAL] 租户A登录失败，终止测试。\n";
    exit(1);
}
$tokenA = $loginA['data']['token'] ?? '';
$tenantAId = (int)($loginA['data']['user_info']['tenant_id'] ?? 0);
echo "  [INFO] 租户A tenant_id={$tenantAId}\n";

// ══════════════════════════════════════════════
// Step 2：准备并登录租户B
// ══════════════════════════════════════════════
echo "Step 2: 租户B登录 ... ";
$tenantBInfo = ensureSecondTenant();

$loginB = httpRequest('POST', "{$BASE_URL}/api/user/login", [
    'account'  => $tenantBInfo['account'],
    'password' => $tenantBInfo['password'],
    'terminal' => 1,
]);
if (assert_code($loginB, 1, '租户B登录')) {
    echo "OK\n";
} else {
    echo "FAIL\n";
    echo "[FATAL] 租户B登录失败，终止测试。\n";
    exit(1);
}
$tokenB = $loginB['data']['token'] ?? '';
$tenantBId = (int)($loginB['data']['user_info']['tenant_id'] ?? 0);
echo "  [INFO] 租户B tenant_id={$tenantBId}\n";

// 确认两个租户 ID 不同
assert_true($tenantAId !== $tenantBId, '租户A和租户B的tenant_id不同');

// ─────────────────────────────────────────────
// 数据 ID 收集（用于清理和交叉验证）
// ─────────────────────────────────────────────
$idsA = [
    'unit'     => null,
    'warehouse'=> null,
    'customer' => null,
    'supplier' => null,
    'goods'    => null,
    'sales_order'   => null,
    'supply_order'  => null,
];
$idsB = [
    'unit'     => null,
    'warehouse'=> null,
    'customer' => null,
    'supplier' => null,
    'goods'    => null,
    'sales_order'   => null,
    'supply_order'  => null,
];

// ══════════════════════════════════════════════
// Step 3：租户A创建测试数据
// ══════════════════════════════════════════════
echo "Step 3: 租户A创建测试数据 ... ";

// 单位
$unitA = httpRequest('POST', "{$BASE_URL}/api/units/add", [
    'name' => 'ISO_A_隔离测试单位',
], $tokenA);
assert_code($unitA, 1, '租户A创建单位');
$idsA['unit'] = extractId($unitA);

// 仓库
$whA = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", [
    'name' => 'ISO_A_隔离测试仓库',
    'address' => 'ISO_A_仓库地址',
], $tokenA);
assert_code($whA, 1, '租户A创建仓库');
$idsA['warehouse'] = extractId($whA);

// 客户
$custA = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => 'ISO_A_隔离测试客户',
    'contact' => 'ISO_A_联系人',
    'phone'   => '13100000001',
], $tokenA);
assert_code($custA, 1, '租户A创建客户');
$idsA['customer'] = extractId($custA);

// 供应商
$supA = httpRequest('POST', "{$BASE_URL}/api/supplier/add", [
    'supplier_name' => 'ISO_A_隔离测试供应商',
    'contact' => 'ISO_A_供应商联系人',
    'phone'   => '13200000001',
], $tokenA);
assert_code($supA, 1, '租户A创建供应商');
$idsA['supplier'] = extractId($supA);

// 商品
$goodsA = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name'         => 'ISO_A_隔离测试商品',
    'product_code' => 'ISO_A_001',
    'price'        => 10.00,
    'units'        => '个',
], $tokenA);
assert_code($goodsA, 1, '租户A创建商品');
$idsA['goods'] = extractId($goodsA);

// 销售单（需前置数据）
if ($idsA['customer'] && $idsA['warehouse'] && $idsA['goods']) {
    $orderA = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
        'customer_id'  => $idsA['customer'],
        'warehouse_id' => $idsA['warehouse'],
        'goods'        => [[
            'goods_id' => $idsA['goods'],
            'name'     => 'ISO_A_隔离测试商品',
            'number'   => 3,
            'price'    => 10.00,
            'units'    => '个',
        ]],
    ], $tokenA);
    assert_code($orderA, 1, '租户A创建销售单');
    $idsA['sales_order'] = extractId($orderA);
}

// 进货单（需前置数据）
if ($idsA['supplier'] && $idsA['warehouse'] && $idsA['goods']) {
    $supplyA = httpRequest('POST', "{$BASE_URL}/api/supply/publish", [
        'supplier_id'  => $idsA['supplier'],
        'warehouse_id' => $idsA['warehouse'],
        'goods'        => [[
            'goods_id' => $idsA['goods'],
            'name'     => 'ISO_A_隔离测试商品',
            'number'   => 5,
            'price'    => 10.00,
            'units'    => '个',
        ]],
    ], $tokenA);
    assert_code($supplyA, 1, '租户A创建进货单');
    $idsA['supply_order'] = extractId($supplyA);
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 4：租户B创建测试数据
// ══════════════════════════════════════════════
echo "Step 4: 租户B创建测试数据 ... ";

// 单位
$unitB = httpRequest('POST', "{$BASE_URL}/api/units/add", [
    'name' => 'ISO_B_隔离测试单位',
], $tokenB);
assert_code($unitB, 1, '租户B创建单位');
$idsB['unit'] = extractId($unitB);

// 仓库
$whB = httpRequest('POST', "{$BASE_URL}/api/warehouse/add", [
    'name' => 'ISO_B_隔离测试仓库',
    'address' => 'ISO_B_仓库地址',
], $tokenB);
assert_code($whB, 1, '租户B创建仓库');
$idsB['warehouse'] = extractId($whB);

// 客户
$custB = httpRequest('POST', "{$BASE_URL}/api/customer/add", [
    'customer_name' => 'ISO_B_隔离测试客户',
    'contact' => 'ISO_B_联系人',
    'phone'   => '14100000001',
], $tokenB);
assert_code($custB, 1, '租户B创建客户');
$idsB['customer'] = extractId($custB);

// 供应商
$supB = httpRequest('POST', "{$BASE_URL}/api/supplier/add", [
    'supplier_name' => 'ISO_B_隔离测试供应商',
    'contact' => 'ISO_B_供应商联系人',
    'phone'   => '14200000001',
], $tokenB);
assert_code($supB, 1, '租户B创建供应商');
$idsB['supplier'] = extractId($supB);

// 商品
$goodsB = httpRequest('POST', "{$BASE_URL}/api/goods/add", [
    'name'         => 'ISO_B_隔离测试商品',
    'product_code' => 'ISO_B_001',
    'price'        => 20.00,
    'units'        => '件',
], $tokenB);
assert_code($goodsB, 1, '租户B创建商品');
$idsB['goods'] = extractId($goodsB);

// 销售单
if ($idsB['customer'] && $idsB['warehouse'] && $idsB['goods']) {
    $orderB = httpRequest('POST', "{$BASE_URL}/api/order/publish", [
        'customer_id'  => $idsB['customer'],
        'warehouse_id' => $idsB['warehouse'],
        'goods'        => [[
            'goods_id' => $idsB['goods'],
            'name'     => 'ISO_B_隔离测试商品',
            'number'   => 2,
            'price'    => 20.00,
            'units'    => '件',
        ]],
    ], $tokenB);
    assert_code($orderB, 1, '租户B创建销售单');
    $idsB['sales_order'] = extractId($orderB);
}

// 进货单
if ($idsB['supplier'] && $idsB['warehouse'] && $idsB['goods']) {
    $supplyB = httpRequest('POST', "{$BASE_URL}/api/supply/publish", [
        'supplier_id'  => $idsB['supplier'],
        'warehouse_id' => $idsB['warehouse'],
        'goods'        => [[
            'goods_id' => $idsB['goods'],
            'name'     => 'ISO_B_隔离测试商品',
            'number'   => 4,
            'price'    => 20.00,
            'units'    => '件',
        ]],
    ], $tokenB);
    assert_code($supplyB, 1, '租户B创建进货单');
    $idsB['supply_order'] = extractId($supplyB);
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 5：交叉验证 - 客户隔离
// ══════════════════════════════════════════════
echo "Step 5: 交叉验证-客户隔离 ... ";

// 用租户A的 token 查询客户列表
$custListA = httpRequest('GET', "{$BASE_URL}/api/customer/index", [], $tokenA);
$itemsA = extractList($custListA);

$leakA = listContains($itemsA, 'customer_name', 'ISO_B_隔离测试客户');
assert_true(!$leakA, '租户A客户列表不含租户B的客户');

// 用租户B的 token 查询客户列表
$custListB = httpRequest('GET', "{$BASE_URL}/api/customer/index", [], $tokenB);
$itemsB = extractList($custListB);

$leakB = listContains($itemsB, 'customer_name', 'ISO_A_隔离测试客户');
assert_true(!$leakB, '租户B客户列表不含租户A的客户');

echo "OK\n";

// ══════════════════════════════════════════════
// Step 6：交叉验证 - 商品隔离
// ══════════════════════════════════════════════
echo "Step 6: 交叉验证-商品隔离 ... ";

$goodsListA = httpRequest('GET', "{$BASE_URL}/api/goods/index", [], $tokenA);
$goodsItemsA = extractList($goodsListA);
$leakGoodsA = listContains($goodsItemsA, 'name', 'ISO_B_隔离测试商品');
assert_true(!$leakGoodsA, '租户A商品列表不含租户B的商品');

$goodsListB = httpRequest('GET', "{$BASE_URL}/api/goods/index", [], $tokenB);
$goodsItemsB = extractList($goodsListB);
$leakGoodsB = listContains($goodsItemsB, 'name', 'ISO_A_隔离测试商品');
assert_true(!$leakGoodsB, '租户B商品列表不含租户A的商品');

echo "OK\n";

// ══════════════════════════════════════════════
// Step 7：交叉验证 - 仓库隔离
// ══════════════════════════════════════════════
echo "Step 7: 交叉验证-仓库隔离 ... ";

$whListA = httpRequest('GET', "{$BASE_URL}/api/warehouse/index", [], $tokenA);
$whItemsA = extractList($whListA);
$leakWhA = listContains($whItemsA, 'name', 'ISO_B_隔离测试仓库');
assert_true(!$leakWhA, '租户A仓库列表不含租户B的仓库');

$whListB = httpRequest('GET', "{$BASE_URL}/api/warehouse/index", [], $tokenB);
$whItemsB = extractList($whListB);
$leakWhB = listContains($whItemsB, 'name', 'ISO_A_隔离测试仓库');
assert_true(!$leakWhB, '租户B仓库列表不含租户A的仓库');

echo "OK\n";

// ══════════════════════════════════════════════
// Step 8：交叉验证 - 单位隔离
// ══════════════════════════════════════════════
echo "Step 8: 交叉验证-单位隔离 ... ";

$unitListA = httpRequest('GET', "{$BASE_URL}/api/units/index", [], $tokenA);
$unitItemsA = extractList($unitListA);
$leakUnitA = listContains($unitItemsA, 'name', 'ISO_B_隔离测试单位');
assert_true(!$leakUnitA, '租户A单位列表不含租户B的单位');

$unitListB = httpRequest('GET', "{$BASE_URL}/api/units/index", [], $tokenB);
$unitItemsB = extractList($unitListB);
$leakUnitB = listContains($unitItemsB, 'name', 'ISO_A_隔离测试单位');
assert_true(!$leakUnitB, '租户B单位列表不含租户A的单位');

echo "OK\n";

// ══════════════════════════════════════════════
// Step 9：交叉验证 - 供应商隔离
// ══════════════════════════════════════════════
echo "Step 9: 交叉验证-供应商隔离 ... ";

$supListA = httpRequest('GET', "{$BASE_URL}/api/supplier/index", [], $tokenA);
$supItemsA = extractList($supListA);
$leakSupA = listContains($supItemsA, 'supplier_name', 'ISO_B_隔离测试供应商');
assert_true(!$leakSupA, '租户A供应商列表不含租户B的供应商');

$supListB = httpRequest('GET', "{$BASE_URL}/api/supplier/index", [], $tokenB);
$supItemsB = extractList($supListB);
$leakSupB = listContains($supItemsB, 'supplier_name', 'ISO_A_隔离测试供应商');
assert_true(!$leakSupB, '租户B供应商列表不含租户A的供应商');

echo "OK\n";

// ══════════════════════════════════════════════
// Step 10：交叉验证 - 销售单隔离
// ══════════════════════════════════════════════
echo "Step 10: 交叉验证-销售单隔离 ... ";

$orderListA = httpRequest('GET', "{$BASE_URL}/api/order/lists", [], $tokenA);
$orderItemsA = extractList($orderListA);
// 用租户A的token查询销售单列表，确认不含租户B的销售单ID
if ($idsB['sales_order']) {
    $leakOrderA = listContainsId($orderItemsA, $idsB['sales_order']);
    assert_true(!$leakOrderA, '租户A销售单列表不含租户B的销售单ID');
} else {
    echo "  [SKIP] 租户B销售单ID不存在，跳过销售单ID隔离验证\n";
}

$orderListB = httpRequest('GET', "{$BASE_URL}/api/order/lists", [], $tokenB);
$orderItemsB = extractList($orderListB);
if ($idsA['sales_order']) {
    $leakOrderB = listContainsId($orderItemsB, $idsA['sales_order']);
    assert_true(!$leakOrderB, '租户B销售单列表不含租户A的销售单ID');
} else {
    echo "  [SKIP] 租户A销售单ID不存在，跳过销售单ID隔离验证\n";
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 11：交叉验证 - 进货单隔离
// ══════════════════════════════════════════════
echo "Step 11: 交叉验证-进货单隔离 ... ";

$supplyListA = httpRequest('GET', "{$BASE_URL}/api/supply/lists", [], $tokenA);
$supplyItemsA = extractList($supplyListA);
if ($idsB['supply_order']) {
    $leakSupplyA = listContainsId($supplyItemsA, $idsB['supply_order']);
    assert_true(!$leakSupplyA, '租户A进货单列表不含租户B的进货单ID');
} else {
    echo "  [SKIP] 租户B进货单ID不存在，跳过进货单ID隔离验证\n";
}

$supplyListB = httpRequest('GET', "{$BASE_URL}/api/supply/lists", [], $tokenB);
$supplyItemsB = extractList($supplyListB);
if ($idsA['supply_order']) {
    $leakSupplyB = listContainsId($supplyItemsB, $idsA['supply_order']);
    assert_true(!$leakSupplyB, '租户B进货单列表不含租户A的进货单ID');
} else {
    echo "  [SKIP] 租户A进货单ID不存在，跳过进货单ID隔离验证\n";
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 12：交叉验证 - 详情越权访问
// ══════════════════════════════════════════════
echo "Step 12: 交叉验证-详情越权访问 ... ";

// 用租户B的token查看租户A的客户详情（期望失败：code=0 或 找不到）
if ($idsA['customer']) {
    $crossCustDetail = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $idsA['customer']], $tokenB);
    $crossCode = (int)($crossCustDetail['code'] ?? 0);
    assert_true($crossCode !== 1, '租户B无法查看租户A的客户详情');
}

// 用租户A的token查看租户B的商品详情（期望失败）
if ($idsB['goods']) {
    $crossGoodsDetail = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $idsB['goods']], $tokenA);
    $crossCode = (int)($crossGoodsDetail['code'] ?? 0);
    assert_true($crossCode !== 1, '租户A无法查看租户B的商品详情');
}

// 用租户B的token查看租户A的销售单详情（期望失败）
if ($idsA['sales_order']) {
    $crossOrderDetail = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $idsA['sales_order']], $tokenB);
    $crossCode = (int)($crossOrderDetail['code'] ?? 0);
    assert_true($crossCode !== 1, '租户B无法查看租户A的销售单详情');
}

// 用租户A的token查看租户B的进货单详情（期望失败）
if ($idsB['supply_order']) {
    $crossSupplyDetail = httpRequest('GET', "{$BASE_URL}/api/supply/details", ['id' => $idsB['supply_order']], $tokenA);
    $crossCode = (int)($crossSupplyDetail['code'] ?? 0);
    assert_true($crossCode !== 1, '租户A无法查看租户B的进货单详情');
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 13：交叉验证 - 编辑越权
// ══════════════════════════════════════════════
echo "Step 13: 交叉验证-编辑越权 ... ";

// 用租户B的token编辑租户A的客户（期望失败）
if ($idsA['customer']) {
    $crossCustEdit = httpRequest('POST', "{$BASE_URL}/api/customer/edit", [
        'id'            => $idsA['customer'],
        'customer_name' => 'ISO_B_HACKED_NAME',
    ], $tokenB);
    $crossCode = (int)($crossCustEdit['code'] ?? 0);
    assert_true($crossCode !== 1, '租户B无法编辑租户A的客户');

    // 验证租户A的客户名未被修改
    $custADetail = httpRequest('GET', "{$BASE_URL}/api/customer/detail", ['id' => $idsA['customer']], $tokenA);
    $custAName = $custADetail['data']['customer_name'] ?? '';
    assert_true($custAName !== 'ISO_B_HACKED_NAME', '租户A客户名未被篡改');
}

// 用租户A的token编辑租户B的商品（期望失败）
if ($idsB['goods']) {
    $crossGoodsEdit = httpRequest('POST', "{$BASE_URL}/api/goods/edit", [
        'id'     => $idsB['goods'],
        'name'   => 'ISO_A_HACKED_GOODS',
        'price'  => 0.01,
    ], $tokenA);
    $crossCode = (int)($crossGoodsEdit['code'] ?? 0);
    assert_true($crossCode !== 1, '租户A无法编辑租户B的商品');

    // 验证租户B的商品名未被修改
    $goodsBDetail = httpRequest('GET', "{$BASE_URL}/api/goods/detail", ['id' => $idsB['goods']], $tokenB);
    $goodsBName = $goodsBDetail['data']['name'] ?? '';
    assert_true($goodsBName !== 'ISO_A_HACKED_GOODS', '租户B商品名未被篡改');
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 14：交叉验证 - 删除越权
// ══════════════════════════════════════════════
echo "Step 14: 交叉验证-删除越权 ... ";

// 用租户B的token删除租户A的仓库（期望失败）
if ($idsA['warehouse']) {
    $crossWhDel = httpRequest('POST', "{$BASE_URL}/api/warehouse/del", [
        'id' => $idsA['warehouse'],
    ], $tokenB);
    $crossCode = (int)($crossWhDel['code'] ?? 0);
    assert_true($crossCode !== 1, '租户B无法删除租户A的仓库');

    // 验证租户A的仓库仍存在
    $whADetail = httpRequest('GET', "{$BASE_URL}/api/warehouse/detail", ['id' => $idsA['warehouse']], $tokenA);
    $whACode = (int)($whADetail['code'] ?? 0);
    assert_true($whACode === 1, '租户A的仓库仍存在');
}

// 用租户A的token删除租户B的单位（期望失败）
if ($idsB['unit']) {
    $crossUnitDel = httpRequest('DELETE', "{$BASE_URL}/api/units/del", [
        'id' => $idsB['unit'],
    ], $tokenA);
    $crossCode = (int)($crossUnitDel['code'] ?? 0);
    assert_true($crossCode !== 1, '租户A无法删除租户B的单位');

    // 验证租户B的单位仍存在
    $unitBDetail = httpRequest('GET', "{$BASE_URL}/api/units/detail", ['id' => $idsB['unit']], $tokenB);
    $unitBCode = (int)($unitBDetail['code'] ?? 0);
    assert_true($unitBCode === 1, '租户B的单位仍存在');
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 15：交叉验证 - 销售单越权删除
// ══════════════════════════════════════════════
echo "Step 15: 交叉验证-销售单越权删除 ... ";

// 先删除租户A的销售单所关联的进货单（释放库存占用），然后用租户B尝试删除租户A的销售单
if ($idsA['sales_order']) {
    $crossOrderDel = httpRequest('DELETE', "{$BASE_URL}/api/order/remove", [
        'id' => $idsA['sales_order'],
    ], $tokenB);
    $crossCode = (int)($crossOrderDel['code'] ?? 0);
    assert_true($crossCode !== 1, '租户B无法删除租户A的销售单');

    // 验证租户A的销售单仍存在
    $orderADetail = httpRequest('GET', "{$BASE_URL}/api/order/details", ['id' => $idsA['sales_order']], $tokenA);
    $orderACode = (int)($orderADetail['code'] ?? 0);
    assert_true($orderACode === 1, '租户A的销售单仍存在');
}

// 用租户A的token尝试删除租户B的进货单（期望失败）
if ($idsB['supply_order']) {
    $crossSupplyDel = httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", [
        'id' => $idsB['supply_order'],
    ], $tokenA);
    $crossCode = (int)($crossSupplyDel['code'] ?? 0);
    assert_true($crossCode !== 1, '租户A无法删除租户B的进货单');

    // 验证租户B的进货单仍存在
    $supplyBDetail = httpRequest('GET', "{$BASE_URL}/api/supply/details", ['id' => $idsB['supply_order']], $tokenB);
    $supplyBCode = (int)($supplyBDetail['code'] ?? 0);
    assert_true($supplyBCode === 1, '租户B的进货单仍存在');
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 16：清理 - 租户A测试数据
// ══════════════════════════════════════════════
echo "Step 16: 清理租户A测试数据 ... ";

// 删除销售单
if ($idsA['sales_order']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $idsA['sales_order']], $tokenA);
    assert_code($del, 1, '清理-租户A删除销售单');
}

// 删除进货单
if ($idsA['supply_order']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $idsA['supply_order']], $tokenA);
    assert_code($del, 1, '清理-租户A删除进货单');
}

// 删除商品（销售单已删除应可删除）
if ($idsA['goods']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $idsA['goods']], $tokenA);
    assert_code($del, 1, '清理-租户A删除商品');
}

// 删除客户（销售单已删除应可删除）
if ($idsA['customer']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $idsA['customer']], $tokenA);
    assert_code($del, 1, '清理-租户A删除客户');
}

// 删除供应商
if ($idsA['supplier']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $idsA['supplier']], $tokenA);
    assert_code($del, 1, '清理-租户A删除供应商');
}

// 删除仓库（订单已删除应可删除）
if ($idsA['warehouse']) {
    $del = httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $idsA['warehouse']], $tokenA);
    assert_code($del, 1, '清理-租户A删除仓库');
}

// 删除单位
if ($idsA['unit']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/units/del", ['id' => $idsA['unit']], $tokenA);
    assert_code($del, 1, '清理-租户A删除单位');
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 17：清理 - 租户B测试数据
// ══════════════════════════════════════════════
echo "Step 17: 清理租户B测试数据 ... ";

// 删除销售单
if ($idsB['sales_order']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/order/remove", ['id' => $idsB['sales_order']], $tokenB);
    assert_code($del, 1, '清理-租户B删除销售单');
}

// 删除进货单
if ($idsB['supply_order']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/supply/remove", ['id' => $idsB['supply_order']], $tokenB);
    assert_code($del, 1, '清理-租户B删除进货单');
}

// 删除商品
if ($idsB['goods']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/goods/del", ['id' => $idsB['goods']], $tokenB);
    assert_code($del, 1, '清理-租户B删除商品');
}

// 删除客户
if ($idsB['customer']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/customer/del", ['id' => $idsB['customer']], $tokenB);
    assert_code($del, 1, '清理-租户B删除客户');
}

// 删除供应商
if ($idsB['supplier']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/supplier/del", ['id' => $idsB['supplier']], $tokenB);
    assert_code($del, 1, '清理-租户B删除供应商');
}

// 删除仓库
if ($idsB['warehouse']) {
    $del = httpRequest('POST', "{$BASE_URL}/api/warehouse/del", ['id' => $idsB['warehouse']], $tokenB);
    assert_code($del, 1, '清理-租户B删除仓库');
}

// 删除单位
if ($idsB['unit']) {
    $del = httpRequest('DELETE', "{$BASE_URL}/api/units/del", ['id' => $idsB['unit']], $tokenB);
    assert_code($del, 1, '清理-租户B删除单位');
}

echo "OK\n";

// ══════════════════════════════════════════════
// Step 18：清理第二租户（DB 级别）
// ══════════════════════════════════════════════
echo "Step 18: 清理第二租户DB数据 ... ";
cleanupSecondTenant();
echo "OK\n";

// ══════════════════════════════════════════════
// Step 19：退出登录
// ══════════════════════════════════════════════
echo "Step 19: 退出登录 ... ";
httpRequest('POST', "{$BASE_URL}/api/user/logout", [], $tokenA);
httpRequest('POST', "{$BASE_URL}/api/user/logout", [], $tokenB);
echo "OK\n";

// ─────────────────────────────────────────────
// 汇总输出
// ─────────────────────────────────────────────
echo "\n=== 结果: {$passTests}/{$totalTests} 通过 ===\n";
echo "结束时间: " . date('Y-m-d H:i:s') . "\n";

exit($failTests > 0 ? 1 : 0);
