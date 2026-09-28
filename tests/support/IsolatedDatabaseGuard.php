<?php

declare(strict_types=1);

namespace tests\support;

final class IsolatedDatabaseGuard
{
    private const DATABASE_PATTERN = '/^beimi_test_[a-z0-9_]+$/i';

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
