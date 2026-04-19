<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__, 2);
$envPath = $rootPath . DIRECTORY_SEPARATOR . '.env';

$env = parse_ini_file($envPath, true, INI_SCANNER_TYPED);
if ($env === false) {
    fwrite(STDERR, "Failed to read .env at {$envPath}\n");
    exit(1);
}

$db = $env['DATABASE'] ?? [];
$project = $env['PROJECT'] ?? [];

$host = (string)($db['HOSTNAME'] ?? '127.0.0.1');
$port = (string)($db['HOSTPORT'] ?? '3306');
$database = (string)($db['DATABASE'] ?? '');
$username = (string)($db['USERNAME'] ?? '');
$password = (string)($db['PASSWORD'] ?? '');
$charset = (string)($db['CHARSET'] ?? 'utf8mb4');
$prefix = (string)($db['PREFIX'] ?? 'la_');

if ($database === '' || $username === '') {
    fwrite(STDERR, "DATABASE.DATABASE and DATABASE.USERNAME must be configured in .env\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);
$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tenantTable = $prefix . 'tenant';
$adminTable = $prefix . 'tenant_admin';
$sessionTable = $prefix . 'tenant_admin_session';

foreach ([$tenantTable, $adminTable, $sessionTable] as $table) {
    $statement = $pdo->prepare('SHOW TABLES LIKE :table');
    $statement->execute(['table' => $table]);
    if (!$statement->fetchColumn()) {
        fwrite(STDERR, "Missing required table: {$table}. Import the base schema first.\n");
        exit(1);
    }
}

$tenantId = 1;
$adminId = 1;
$account = 'jxcadmin';
$plainPassword = (string)($project['DEFAULT_PASSWORD'] ?? '123456');
$salt = (string)($project['UNIQUE_IDENTIFICATION'] ?? '');
$hashedPassword = md5($salt . md5($plainPassword . $salt));
$now = time();

$pdo->beginTransaction();
try {
    $tenantSql = <<<SQL
INSERT INTO `{$tenantTable}`
    (`id`, `sn`, `name`, `avatar`, `tel`, `disable`, `tactics`, `notes`, `domain_alias`, `domain_alias_enable`, `create_time`, `update_time`, `delete_time`)
VALUES
    (:id, :sn, :name, '', '', 0, 0, :notes, :domain_alias, 1, :create_time, :update_time, NULL)
ON DUPLICATE KEY UPDATE
    `sn` = VALUES(`sn`),
    `name` = VALUES(`name`),
    `disable` = 0,
    `tactics` = 0,
    `notes` = VALUES(`notes`),
    `domain_alias` = VALUES(`domain_alias`),
    `update_time` = VALUES(`update_time`),
    `delete_time` = NULL
SQL;
    $tenantStatement = $pdo->prepare($tenantSql);
    $tenantStatement->execute([
        'id' => $tenantId,
        'sn' => 'JXC-LOCAL-001',
        'name' => 'JXC Local Tenant',
        'notes' => 'Local seed tenant for JXC phase 1 smoke tests.',
        'domain_alias' => '127.0.0.1:9501,localhost:9501',
        'create_time' => $now,
        'update_time' => $now,
    ]);

    $adminSql = <<<SQL
INSERT INTO `{$adminTable}`
    (`id`, `tenant_id`, `root`, `name`, `avatar`, `account`, `password`, `login_time`, `login_ip`, `multipoint_login`, `disable`, `create_time`, `update_time`, `delete_time`)
VALUES
    (:id, :tenant_id, 1, :name, '', :account, :password, NULL, '', 1, 0, :create_time, :update_time, NULL)
ON DUPLICATE KEY UPDATE
    `tenant_id` = VALUES(`tenant_id`),
    `root` = 1,
    `name` = VALUES(`name`),
    `account` = VALUES(`account`),
    `password` = VALUES(`password`),
    `multipoint_login` = 1,
    `disable` = 0,
    `update_time` = VALUES(`update_time`),
    `delete_time` = NULL
SQL;
    $adminStatement = $pdo->prepare($adminSql);
    $adminStatement->execute([
        'id' => $adminId,
        'tenant_id' => $tenantId,
        'name' => 'JXC Local Admin',
        'account' => $account,
        'password' => $hashedPassword,
        'create_time' => $now,
        'update_time' => $now,
    ]);

    $sessionStatement = $pdo->prepare("DELETE FROM `{$sessionTable}` WHERE `admin_id` = :admin_id");
    $sessionStatement->execute(['admin_id' => $adminId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Seeded tenant {$tenantId} and admin {$adminId}\n";
echo "Login account: {$account}\n";
echo "Login password: {$plainPassword}\n";
