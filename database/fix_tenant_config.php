<?php
/**
 * Fix missing la_tenant_config table
 */
$config = include __DIR__ . '/../.env';

// Read .env manually
$envFile = __DIR__ . '/../.env';
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$env = [];
$section = '';
foreach ($lines as $line) {
    $line = trim($line);
    if (preg_match('/^\[(.+)\]$/', $line, $m)) {
        $section = $m[1];
        continue;
    }
    if (strpos($line, '=') !== false) {
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        $val = trim($val, '"\'');
        $env[$section][$key] = $val;
    }
}

$host = $env['DATABASE']['HOSTNAME'] ?? 'localhost';
$port = $env['DATABASE']['HOSTPORT'] ?? '3306';
$db   = $env['DATABASE']['DATABASE'] ?? 'lantu';
$user = $env['DATABASE']['USERNAME'] ?? 'root';
$pass = $env['DATABASE']['PASSWORD'] ?? '';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS `la_tenant_config` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `tenant_id` int(11) NOT NULL COMMENT '租户ID',
    `type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '类型',
    `name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '名称',
    `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '值',
    `create_time` int(10) NULL DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10) NULL DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='配置表';
SQL;

$pdo->exec($sql);
echo "la_tenant_config table created successfully.\n";

// Also check if la_config table is needed
$sql2 = <<<SQL
CREATE TABLE IF NOT EXISTS `la_config` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '类型',
    `name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '名称',
    `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '值',
    `create_time` int(10) NULL DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10) NULL DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='平台配置表';
SQL;

$pdo->exec($sql2);
echo "la_config table created successfully.\n";

// Show all tables
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "\nAll tables in $db (" . count($tables) . "):\n";
foreach ($tables as $t) {
    echo "  - $t\n";
}
