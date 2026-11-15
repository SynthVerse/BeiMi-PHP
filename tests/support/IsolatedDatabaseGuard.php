<?php

declare(strict_types=1);

namespace tests\support;

final class IsolatedDatabaseGuard
{
    private const DATABASE_PATTERN = '/^beimi_test_[a-z0-9_]+$/i';

    /** @return resource|false false 表示属于持锁执行者的受控子进程。 */
    public static function acquireLease(string $database, string $directory, ?string $inheritedToken = null, ?string &$leaseToken = null)
    {
        if (preg_match(self::DATABASE_PATTERN, $database) !== 1) {
            throw new \RuntimeException('Refusing test lease for an unsafe database');
        }
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create isolated test lease directory');
        }
        $path = $directory . '/database-' . hash('sha256', strtolower($database)) . '.lock';
        $lease = fopen($path, 'c+');
        if ($lease === false) { throw new \RuntimeException('Cannot open isolated test database lease'); }
        if (!flock($lease, LOCK_EX | LOCK_NB)) {
            fclose($lease);
            // Windows 的强制文件锁会阻止读取锁文件本身，令牌放在独立元数据文件。
            $ownerToken = is_file($path . '.owner') ? trim((string)file_get_contents($path . '.owner')) : '';
            if ($inheritedToken !== null && strlen($ownerToken) === 48 && hash_equals($ownerToken, $inheritedToken)) {
                return false;
            }
            throw new \RuntimeException('Isolated test database is already in use; run database suites sequentially');
        }
        $leaseToken = bin2hex(random_bytes(24));
        if (file_put_contents($path . '.owner', $leaseToken) === false) {
            fclose($lease);
            throw new \RuntimeException('Cannot record isolated test lease owner');
        }
        return $lease;
    }

    public static function acceptsEnvironment(array $environment): bool
    {
        $marker = strtolower(trim((string)($environment['PHPUNIT']['ISOLATED_DATABASE'] ?? '')));
        $database = $environment['DATABASE'] ?? [];

        return in_array($marker, ['1', 'true', 'yes'], true)
            && is_array($database)
            && self::acceptsConnection([
                'type' => $database['TYPE'] ?? null,
                'hostname' => $database['HOSTNAME'] ?? null,
                'database' => $database['DATABASE'] ?? null,
                'password' => $database['PASSWORD'] ?? null,
                'hostport' => $database['HOSTPORT'] ?? $database['PORT'] ?? null,
                'prefix' => $database['PREFIX'] ?? null,
            ]);
    }

    public static function acceptsConnection(array $connection): bool
    {
        $driver = strtolower(trim((string)($connection['type'] ?? '')));
        $hostname = strtolower(trim((string)($connection['hostname'] ?? '')));
        $database = trim((string)($connection['database'] ?? ''));
        $password = (string)($connection['password'] ?? '');
        $port = trim((string)($connection['hostport'] ?? ''));
        $prefix = (string)($connection['prefix'] ?? '');

        return $driver === 'mysql'
            // PDO MySQL treats "localhost" as a Unix socket on Unix-like hosts,
            // which can silently bypass the guarded TCP port.
            && $hostname === '127.0.0.1'
            && preg_match(self::DATABASE_PATTERN, $database) === 1
            && $password !== ''
            && $port === '3307'
            && $prefix === 'la_';
    }
}
