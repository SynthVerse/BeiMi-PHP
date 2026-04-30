#!/usr/bin/env php
<?php
/**
 * 数据库迁移执行器
 *
 * 用法：
 *   php scripts/migrate.php            -- 执行所有待迁移
 *   php scripts/migrate.php --dry-run  -- 显示待迁移但不执行
 *   php scripts/migrate.php --status   -- 显示所有迁移状态
 */

declare(strict_types=1);

// ── 解析命令行参数 ──
$args = array_slice($argv, 1);
$dryRun  = in_array('--dry-run', $args, true);
$status  = in_array('--status', $args, true);

if ($dryRun && $status) {
    fwrite(STDERR, "错误：--dry-run 和 --status 不能同时使用\n");
    exit(1);
}

// ── 读取 .env 配置 ──
$projectRoot = dirname(__DIR__);
$envPath     = $projectRoot . '/.env';

$env = parse_ini_file($envPath, true, INI_SCANNER_TYPED);
if ($env === false) {
    fwrite(STDERR, "错误：无法读取 .env 文件 ($envPath)\n");
    exit(1);
}

$db     = $env['DATABASE'] ?? [];
$host   = (string)($db['HOSTNAME'] ?? '127.0.0.1');
$port   = (string)($db['HOSTPORT'] ?? '3306');
$name   = (string)($db['DATABASE'] ?? '');
$user   = (string)($db['USERNAME'] ?? '');
$pass   = (string)($db['PASSWORD'] ?? '');
$charset = (string)($db['CHARSET'] ?? 'utf8mb4');
$prefix = (string)($db['PREFIX'] ?? 'la_');

// ── 连接数据库 ──
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "错误：数据库连接失败 - " . $e->getMessage() . "\n");
    exit(1);
}

// ── 确保 migration_history 表存在 ──
$migrationHistoryTable = $prefix . 'migration_history';
$migrationHistoryDDL = <<<'SQL'
CREATE TABLE IF NOT EXISTS `%s` (
    id int unsigned NOT NULL AUTO_INCREMENT,
    version varchar(128) NOT NULL COMMENT '迁移版本号（文件名）',
    applied_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '应用时间',
    PRIMARY KEY (id),
    UNIQUE KEY uk_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据库迁移历史'
SQL;

$pdo->exec(sprintf($migrationHistoryDDL, $migrationHistoryTable));

// ── 获取已执行的迁移 ──
$appliedRows = $pdo->query("SELECT version FROM `{$migrationHistoryTable}` ORDER BY version")->fetchAll();
$appliedVersions = array_column($appliedRows, 'version');

// ── 扫描迁移文件 ──
$migrationsDir = $projectRoot . '/database/migrations';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "错误：迁移目录不存在 ($migrationsDir)\n");
    exit(1);
}

$allFiles = glob($migrationsDir . '/*.sql');
if ($allFiles === false || empty($allFiles)) {
    echo "没有找到迁移文件。\n";
    exit(0);
}

// 按文件名排序
sort($allFiles);

// 提取文件名（不含路径）作为版本号
$migrations = [];
foreach ($allFiles as $file) {
    $basename = basename($file);
    $migrations[$basename] = $file;
}

// ── --status 模式 ──
if ($status) {
    echo "数据库迁移状态：\n";
    echo str_repeat('-', 70) . "\n";
    echo sprintf("  %-45s  %s\n", '版本', '状态');
    echo str_repeat('-', 70) . "\n";

    foreach ($migrations as $version => $path) {
        $isApplied = in_array($version, $appliedVersions, true);
        $statusText = $isApplied ? '✓ 已应用' : '○ 待执行';
        echo sprintf("  %-45s  %s\n", $version, $statusText);
    }

    echo str_repeat('-', 70) . "\n";
    $appliedCount = count(array_intersect(array_keys($migrations), $appliedVersions));
    $pendingCount = count($migrations) - $appliedCount;
    echo "总计：" . count($migrations) . " 个迁移，已应用 {$appliedCount} 个，待执行 {$pendingCount} 个\n";
    exit(0);
}

// ── 计算待执行迁移 ──
$pending = [];
foreach ($migrations as $version => $path) {
    if (!in_array($version, $appliedVersions, true)) {
        $pending[$version] = $path;
    }
}

if (empty($pending)) {
    echo "No pending migrations.\n";
    exit(0);
}

// ── --dry-run 模式 ──
if ($dryRun) {
    echo "待执行迁移（dry-run 模式，不会实际执行）：\n";
    foreach ($pending as $version => $path) {
        echo "  - {$version}\n";
    }
    exit(0);
}

// ── 执行迁移 ──
echo "开始执行数据库迁移...\n\n";

foreach ($pending as $version => $path) {
    echo "  执行迁移: {$version} ... ";

    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "\n    错误：无法读取文件 {$path}\n");
        exit(1);
    }

    // 拆分 SQL 语句（与 jxc_phase1_db_init.php 相同的方式）
    $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql) ?: []));

    try {
        foreach ($statements as $statement) {
            if ($statement !== '') {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // MySQL 不支持 ADD COLUMN IF NOT EXISTS 语法
                    // 在此对重复列(1060)和重复索引(1061)错误进行容错处理
                    $isDuplicate = false;
                    $msg = $e->getMessage();
                    // 通过错误消息中的关键字判断
                    if (preg_match('/Duplicate column name/i', $msg)
                        || preg_match('/Duplicate key name/i', $msg)
                    ) {
                        $isDuplicate = true;
                    }
                    // 通过 MySQL 原生错误码判断：1060=Duplicate column, 1061=Duplicate key
                    if (strpos($msg, '1060') !== false
                        || strpos($msg, '1061') !== false
                    ) {
                        $isDuplicate = true;
                    }

                    if (!$isDuplicate) {
                        throw $e; // 非重复错误，继续向上抛出
                    }
                    // 重复列/索引，跳过该语句
                }
            }
        }

        // 记录到迁移历史
        $stmt = $pdo->prepare("INSERT INTO `{$migrationHistoryTable}` (version) VALUES (?)");
        $stmt->execute([$version]);

        echo "✓ 成功\n";
    } catch (PDOException $e) {
        // 注意：MySQL DDL 语句会隐式提交，无法回滚
        // 如果迁移失败，已执行的 DDL 语句无法撤销
        fwrite(STDERR, "\n    错误：" . $e->getMessage() . "\n");
        fwrite(STDERR, "    迁移 {$version} 失败。后续迁移将不会执行。\n");
        exit(1);
    }
}

echo "\n所有迁移执行完成。\n";
exit(0);
