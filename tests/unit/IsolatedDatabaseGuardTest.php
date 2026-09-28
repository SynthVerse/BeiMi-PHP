<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use tests\support\IsolatedDatabaseGuard;

final class IsolatedDatabaseGuardTest extends TestCase
{
    public function testEnvironmentAndRuntimeUseTheSameSafeDatabaseContract(): void
    {
        $environment = [
            'PHPUNIT' => ['ISOLATED_DATABASE' => 'true'],
            'DATABASE' => [
                'TYPE' => 'mysql',
                'HOSTNAME' => '127.0.0.1',
                'DATABASE' => 'beimi_test_inventory',
                'USERNAME' => 'root',
                'PASSWORD' => 'test-only-password',
                'HOSTPORT' => '3307',
                'PREFIX' => 'la_',
            ],
        ];
        $connection = [
            'type' => 'mysql',
            'hostname' => '127.0.0.1',
            'database' => 'beimi_test_inventory',
            'username' => 'root',
            'password' => 'test-only-password',
            'hostport' => 3307,
            'prefix' => 'la_',
        ];

        self::assertTrue(IsolatedDatabaseGuard::acceptsEnvironment($environment));
        self::assertTrue(IsolatedDatabaseGuard::acceptsConnection($connection));
    }

    /**
     * @dataProvider unsafeEnvironmentProvider
     */
    public function testUnsafeEnvironmentIsRejected(array $environment): void
    {
        self::assertFalse(IsolatedDatabaseGuard::acceptsEnvironment($environment));
    }

    public function unsafeEnvironmentProvider(): array
    {
        $safe = [
            'PHPUNIT' => ['ISOLATED_DATABASE' => 'yes'],
            'DATABASE' => [
                'TYPE' => 'mysql',
                'HOSTNAME' => '127.0.0.1',
                'DATABASE' => 'beimi_test_guard',
                'PASSWORD' => 'test-only-password',
                'HOSTPORT' => '3307',
                'PREFIX' => 'la_',
            ],
        ];

        $cases = [];
        foreach ([
            'marker' => ['PHPUNIT', 'ISOLATED_DATABASE', 'false'],
            'database' => ['DATABASE', 'DATABASE', 'beimi'],
            'hostname' => ['DATABASE', 'HOSTNAME', 'db.example.com'],
            'socket_hostname' => ['DATABASE', 'HOSTNAME', 'localhost'],
            'port' => ['DATABASE', 'HOSTPORT', '3306'],
            'prefix' => ['DATABASE', 'PREFIX', ''],
            'password' => ['DATABASE', 'PASSWORD', ''],
        ] as $label => [$section, $key, $value]) {
            $candidate = $safe;
            $candidate[$section][$key] = $value;
            $cases[$label] = [$candidate];
        }

        return $cases;
    }
}
