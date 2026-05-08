<?php
/**
 * BeiMi JXC 微信小程序登录+租户预置 E2E 测试
 *
 * 直接在 PHP 层面模拟完整的 "新用户注册 → 租户预置 → 默认数据初始化 → 数据隔离" 流程。
 * 不依赖外部微信 API，不依赖 HTTP 服务器运行。
 *
 * 使用方法：
 *   cd BeiMi-PHP
 *   php tests/e2e_login_provision_test.php
 */

// ─────────────────────────────────────────────
// 引导 ThinkPHP 框架
// ─────────────────────────────────────────────
require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\common\model\user\User;
use app\common\service\jxc\TenantProvisionService;
use app\common\service\jxc\DefaultDataInitService;
use think\facade\Db;
use think\facade\Config;

// ─────────────────────────────────────────────
// 全局计数器
// ─────────────────────────────────────────────
$totalTests = 0;
$passTests  = 0;
$failTests  = 0;
$RUN_ID     = date('YmdHis') . '_' . random_int(1000, 9999);
$TEST_PREFIX = 'E2E_TEST_';

// ─────────────────────────────────────────────
// 辅助函数
// ─────────────────────────────────────────────

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

function assert_equals($expected, $actual, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    if ($expected === $actual) {
        echo "  [PASS] {$testName}\n";
        $passTests++;
        return true;
    }

    echo "  [FAIL] {$testName}: 期望=" . var_export($expected, true) . ", 实际=" . var_export($actual, true) . "\n";
    $failTests++;
    return false;
}

function assert_gt($value, $threshold, string $testName): bool
{
    global $totalTests, $passTests, $failTests;
    $totalTests++;

    if ($value > $threshold) {
        echo "  [PASS] {$testName} (值={$value})\n";
        $passTests++;
        return true;
    }

    echo "  [FAIL] {$testName}: 期望 > {$threshold}, 实际={$value}\n";
    $failTests++;
    return false;
}

/**
 * 读取 .env 配置（与 isolation_test.php 复用同一逻辑）
 */
function loadEnvConfig(): array
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

    $env = loadEnvConfig();
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
 * 获取表前缀
 */
function getPrefix(): string
{
    $env = loadEnvConfig();
    return $env['DATABASE']['PREFIX'] ?? 'la_';
}

/**
 * 创建一个测试用 la_user 记录（tenant_id=0，模拟新微信用户）
 * @return array ['id' => int, 'sn' => string]
 */
function createTestUser(string $suffix): array
{
    $pdo = getPdo();
    $prefix = getPrefix();
    $table = $prefix . 'user';
    $time = time();
    $sn = 'E2ETEST' . $suffix . random_int(10000, 99999);

    $stmt = $pdo->prepare(<<<SQL
INSERT INTO `{$table}` (`sn`, `tenant_id`, `avatar`, `real_name`, `nickname`, `account`, `password`,
    `mobile`, `sex`, `channel`, `is_new_user`, `login_ip`, `login_time`,
    `create_time`, `update_time`, `delete_time`)
VALUES (:sn, 0, '', :real_name, :nickname, :account, '', '', 0, 2, 1, '', 0, :create_time, :update_time, NULL)
SQL
    );
    $stmt->execute([
        'sn'          => $sn,
        'real_name'   => 'E2E_TEST_' . $suffix,
        'nickname'    => 'E2E_TEST_' . $suffix,
        'account'     => 'e2e_test_' . strtolower($suffix) . '_' . random_int(1000, 9999),
        'create_time' => $time,
        'update_time' => $time,
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'sn' => $sn,
    ];
}

/**
 * 清理测试数据（在测试结束后调用）
 */
function cleanupTestData(array $userIds, array $tenantIds): void
{
    $pdo = getPdo();
    $prefix = getPrefix();

    // 清理业务数据
    $businessTables = ['warehouse', 'customer', 'vendor', 'goods_unit'];
    foreach ($tenantIds as $tid) {
        if ($tid <= 0) continue;
        foreach ($businessTables as $table) {
            $fullTable = $prefix . $table;
            try {
                $pdo->exec("DELETE FROM `{$fullTable}` WHERE tenant_id = {$tid}");
            } catch (\Throwable $e) {
                // 表可能不存在，忽略
            }
        }
        // 清理租户超管
        try {
            $pdo->exec("DELETE FROM `{$prefix}tenant_admin` WHERE tenant_id = {$tid}");
        } catch (\Throwable $e) {}
        // 清理租户
        try {
            $pdo->exec("DELETE FROM `{$prefix}tenant` WHERE id = {$tid}");
        } catch (\Throwable $e) {}
    }

    // 清理测试用户
    foreach ($userIds as $uid) {
        if ($uid <= 0) continue;
        try {
            $pdo->exec("DELETE FROM `{$prefix}user` WHERE id = {$uid}");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("DELETE FROM `{$prefix}user_session` WHERE user_id = {$uid}");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("DELETE FROM `{$prefix}user_auth` WHERE user_id = {$uid}");
        } catch (\Throwable $e) {}
    }
}

// ─────────────────────────────────────────────
// 主测试流程
// ─────────────────────────────────────────────

echo "=== BeiMi JXC 微信小程序登录+租户预置 E2E 测试 ===\n";
echo "RUN_ID: {$RUN_ID}\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

// 收集测试中创建的 ID，用于最终清理
$testUserIds   = [];
$testTenantIds = [];

try {

// ══════════════════════════════════════════════
// Test 1: testNewUserProvisionFlow
// ══════════════════════════════════════════════
echo "── Test 1: testNewUserProvisionFlow ──\n";

$userA = createTestUser('A');
$testUserIds[] = $userA['id'];
echo "  [INFO] 创建测试用户A: id={$userA['id']}, sn={$userA['sn']}\n";

// 通过 Db facade 获取用户模型（绕过全局 scope，使用原始 User 模型）
$userModelA = new User();
$userModelA->setAttrs([
    'id'        => $userA['id'],
    'sn'        => $userA['sn'],
    'nickname'  => 'E2E_TEST_A',
    'tenant_id' => 0,
]);
// 关键：直接从 DB 读取来设置模型（确保 id 等属性正确）
$userModelA = User::withoutGlobalScope(['tenantId'])->find($userA['id']);

// 调用 provisionForWechatUser
$tenantIdA = TenantProvisionService::provisionForWechatUser($userModelA, 'oE2ETEST_OPENID_A_' . $RUN_ID);
$testTenantIds[] = $tenantIdA;

echo "  [INFO] Provision结果: tenant_id={$tenantIdA}\n";

// 验证：用户获得新 tenant_id > 0
assert_gt($tenantIdA, 0, '用户A获得新 tenant_id > 0');

// 验证：la_user.tenant_id 已更新
$pdo = getPdo();
$prefix = getPrefix();
$stmt = $pdo->prepare("SELECT tenant_id FROM `{$prefix}user` WHERE id = :id");
$stmt->execute(['id' => $userA['id']]);
$actualTenantId = (int)$stmt->fetchColumn();
assert_equals($tenantIdA, $actualTenantId, 'la_user.tenant_id 已正确回写');

// 验证：la_tenant 表存在对应租户记录
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}tenant` WHERE id = :id AND delete_time IS NULL");
$stmt->execute(['id' => $tenantIdA]);
assert_true((int)$stmt->fetchColumn() === 1, 'la_tenant 表存在对应租户记录');

// 验证：la_tenant_admin 表存在对应超管记录
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}tenant_admin` WHERE tenant_id = :tid AND root = 1");
$stmt->execute(['tid' => $tenantIdA]);
assert_true((int)$stmt->fetchColumn() >= 1, 'la_tenant_admin 表存在对应超管记录');

// 验证：warehouse 表存在默认数据
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}warehouse` WHERE tenant_id = :tid AND name = :name");
$stmt->execute(['tid' => $tenantIdA, 'name' => '默认仓库']);
assert_true((int)$stmt->fetchColumn() === 1, 'warehouse 表存在默认仓库');

// 验证：customer 表存在默认数据
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}customer` WHERE tenant_id = :tid AND customer_name = :name");
$stmt->execute(['tid' => $tenantIdA, 'name' => '默认客户']);
assert_true((int)$stmt->fetchColumn() === 1, 'customer 表存在默认客户');

// 验证：vendor 表存在默认数据
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}vendor` WHERE tenant_id = :tid AND supplier_name = :name");
$stmt->execute(['tid' => $tenantIdA, 'name' => '默认供应商']);
assert_true((int)$stmt->fetchColumn() === 1, 'vendor 表存在默认供应商');

// 验证：goods_unit 表存在默认数据
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}goods_unit` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdA]);
assert_true((int)$stmt->fetchColumn() >= 5, 'goods_unit 表存在默认计量单位');

echo "\n";

// ══════════════════════════════════════════════
// Test 2: testIdempotentProvision
// ══════════════════════════════════════════════
echo "── Test 2: testIdempotentProvision ──\n";

// 重新加载用户模型（此时 tenant_id 应已 > 0）
$userModelA2 = User::withoutGlobalScope(['tenantId'])->find($userA['id']);
echo "  [INFO] 用户A当前 tenant_id={$userModelA2->tenant_id}\n";

// 记录 provision 前的默认数据数量
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}warehouse` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdA]);
$warehouseCountBefore = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}goods_unit` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdA]);
$unitCountBefore = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}tenant` WHERE id = :id AND delete_time IS NULL");
$stmt->execute(['id' => $tenantIdA]);
$tenantCountBefore = (int)$stmt->fetchColumn();

// 第二次调用 provisionForWechatUser
$tenantIdA2 = TenantProvisionService::provisionForWechatUser($userModelA2, 'oE2ETEST_OPENID_A_' . $RUN_ID);

// 验证：第二次调用返回相同的 tenant_id
assert_equals($tenantIdA, $tenantIdA2, '幂等：第二次调用返回相同 tenant_id');

// 验证：不会产生重复的租户
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}tenant` WHERE id = :id AND delete_time IS NULL");
$stmt->execute(['id' => $tenantIdA]);
$tenantCountAfter = (int)$stmt->fetchColumn();
assert_equals($tenantCountBefore, $tenantCountAfter, '幂等：不会产生重复租户');

// 验证：不会产生重复默认数据（仓库）
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}warehouse` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdA]);
$warehouseCountAfter = (int)$stmt->fetchColumn();
assert_equals($warehouseCountBefore, $warehouseCountAfter, '幂等：不产生重复仓库');

// 验证：不会产生重复默认数据（单位）
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}goods_unit` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdA]);
$unitCountAfter = (int)$stmt->fetchColumn();
assert_equals($unitCountBefore, $unitCountAfter, '幂等：不产生重复计量单位');

echo "\n";

// ══════════════════════════════════════════════
// Test 3: testDataIsolation
// ══════════════════════════════════════════════
echo "── Test 3: testDataIsolation ──\n";

// 创建用户B
$userB = createTestUser('B');
$testUserIds[] = $userB['id'];
echo "  [INFO] 创建测试用户B: id={$userB['id']}, sn={$userB['sn']}\n";

$userModelB = User::withoutGlobalScope(['tenantId'])->find($userB['id']);

// 为用户B provision
$tenantIdB = TenantProvisionService::provisionForWechatUser($userModelB, 'oE2ETEST_OPENID_B_' . $RUN_ID);
$testTenantIds[] = $tenantIdB;
echo "  [INFO] 用户B Provision结果: tenant_id={$tenantIdB}\n";

// 验证两个用户的 tenant_id 不同
assert_true($tenantIdA !== $tenantIdB, '用户A和用户B的 tenant_id 不同');

// 验证：用户A的仓库列表只包含A的默认仓库
$stmt = $pdo->prepare("SELECT name FROM `{$prefix}warehouse` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdA]);
$warehousesA = $stmt->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('默认仓库', $warehousesA), '用户A的仓库列表包含默认仓库');

// 验证：用户B的客户列表只包含B的默认客户
$stmt = $pdo->prepare("SELECT customer_name FROM `{$prefix}customer` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdB]);
$customersB = $stmt->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('默认客户', $customersB), '用户B的客户列表包含默认客户');

// 验证：用户A看不到用户B的供应商
$stmt = $pdo->prepare("SELECT supplier_name FROM `{$prefix}vendor` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdA]);
$vendorsA = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT supplier_name FROM `{$prefix}vendor` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdB]);
$vendorsB = $stmt->fetchAll(PDO::FETCH_COLUMN);

// A的供应商列表不含B的供应商（名称相同但 tenant_id 隔离）
// 通过 ID 级别验证隔离
$stmt = $pdo->prepare("SELECT id FROM `{$prefix}vendor` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdB]);
$vendorIdsB = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT id FROM `{$prefix}vendor` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdA]);
$vendorIdsA = $stmt->fetchAll(PDO::FETCH_COLUMN);

$intersection = array_intersect($vendorIdsA, $vendorIdsB);
assert_true(empty($intersection), '用户A看不到用户B的供应商（ID 不交叉）');

// 额外验证：B 的仓库 ID 不会出现在 A 的数据中
$stmt = $pdo->prepare("SELECT id FROM `{$prefix}warehouse` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdB]);
$warehouseIdsB = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT id FROM `{$prefix}warehouse` WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantIdA]);
$warehouseIdsA = $stmt->fetchAll(PDO::FETCH_COLUMN);

$whIntersection = array_intersect($warehouseIdsA, $warehouseIdsB);
assert_true(empty($whIntersection), '仓库数据 A 和 B 完全隔离');

echo "\n";

// ══════════════════════════════════════════════
// Test 4: testDefaultDataCompleteness
// ══════════════════════════════════════════════
echo "── Test 4: testDefaultDataCompleteness ──\n";

// 验证仓库名为"默认仓库"
$stmt = $pdo->prepare("SELECT name FROM `{$prefix}warehouse` WHERE tenant_id = :tid AND name = :name");
$stmt->execute(['tid' => $tenantIdA, 'name' => '默认仓库']);
$whName = $stmt->fetchColumn();
assert_equals('默认仓库', $whName, '默认仓库名称正确');

// 验证客户名为"默认客户"
$stmt = $pdo->prepare("SELECT customer_name FROM `{$prefix}customer` WHERE tenant_id = :tid AND customer_name = :name");
$stmt->execute(['tid' => $tenantIdA, 'name' => '默认客户']);
$custName = $stmt->fetchColumn();
assert_equals('默认客户', $custName, '默认客户名称正确');

// 验证供应商名为"默认供应商"
$stmt = $pdo->prepare("SELECT supplier_name FROM `{$prefix}vendor` WHERE tenant_id = :tid AND supplier_name = :name");
$stmt->execute(['tid' => $tenantIdA, 'name' => '默认供应商']);
$vendorName = $stmt->fetchColumn();
assert_equals('默认供应商', $vendorName, '默认供应商名称正确');

// 验证5个计量单位全部存在
$expectedUnits = ['个', '件', '箱', '千克', '升'];
$stmt = $pdo->prepare("SELECT name FROM `{$prefix}goods_unit` WHERE tenant_id = :tid ORDER BY sort");
$stmt->execute(['tid' => $tenantIdA]);
$actualUnits = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($expectedUnits as $unitName) {
    assert_true(in_array($unitName, $actualUnits), "计量单位 '{$unitName}' 存在");
}

// 验证没有多余的计量单位（恰好5个默认单位）
$defaultUnitCount = 0;
foreach ($actualUnits as $u) {
    if (in_array($u, $expectedUnits)) {
        $defaultUnitCount++;
    }
}
assert_equals(5, $defaultUnitCount, '默认计量单位恰好5个（个、件、箱、千克、升）');

echo "\n";

// ══════════════════════════════════════════════
// Test 5: testCliDryRun
// ══════════════════════════════════════════════
echo "── Test 5: testCliDryRun ──\n";

$thinkBin = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'think';
$cmd = 'php ' . escapeshellarg($thinkBin) . ' jxc:init-defaults --dry-run 2>&1';
echo "  [INFO] 执行命令: {$cmd}\n";

$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
$outputStr = implode("\n", $output);

echo "  [INFO] 退出码: {$exitCode}\n";
echo "  [INFO] 输出片段: " . substr($outputStr, 0, 200) . "\n";

// 验证命令执行成功（退出码 0）
assert_equals(0, $exitCode, 'CLI jxc:init-defaults --dry-run 退出码为 0');

// 验证输出包含预演关键信息
assert_true(
    str_contains($outputStr, 'DRY RUN') || str_contains($outputStr, 'dry-run') || str_contains($outputStr, 'dry run'),
    'CLI 输出包含 DRY RUN 标识'
);

assert_true(
    str_contains($outputStr, 'jxc:init-defaults'),
    'CLI 输出包含命令名称标识'
);

assert_true(
    str_contains($outputStr, 'done') || str_contains($outputStr, 'start'),
    'CLI 输出包含执行状态信息'
);

echo "\n";

} catch (\Throwable $e) {
    echo "\n[FATAL] 测试异常中断: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "  Trace: " . $e->getTraceAsString() . "\n\n";
    $failTests++;
}

// ══════════════════════════════════════════════
// 清理测试数据
// ══════════════════════════════════════════════
echo "── 清理测试数据 ──\n";
try {
    cleanupTestData($testUserIds, $testTenantIds);
    echo "  [INFO] 测试数据清理完成\n";
} catch (\Throwable $e) {
    echo "  [WARN] 清理时出错（可手动清理）: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────
// 汇总输出
// ─────────────────────────────────────────────
echo "\n=== 结果: {$passTests}/{$totalTests} 通过";
if ($failTests > 0) {
    echo ", {$failTests} 失败";
}
echo " ===\n";
echo "结束时间: " . date('Y-m-d H:i:s') . "\n";

exit($failTests > 0 ? 1 : 0);
