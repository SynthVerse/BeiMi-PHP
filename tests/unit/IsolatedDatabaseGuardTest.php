<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use tests\support\IsolatedDatabaseGuard;

final class IsolatedDatabaseGuardTest extends TestCase
{
    public function testSameDatabaseCannotBeUsedByTwoTestRunnersAtOnce(): void
    {
        $directory = sys_get_temp_dir() . '/beimi-lease-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $lease = IsolatedDatabaseGuard::acquireLease('beimi_test_guard', $directory, null, $token);
        try {
            self::assertFalse(IsolatedDatabaseGuard::acquireLease('beimi_test_guard', $directory, $token), '同一套件的受控子进程共享父进程持锁');
            try {
                IsolatedDatabaseGuard::acquireLease('beimi_test_guard', $directory);
                self::fail('同一个隔离库的第二个测试执行者必须在初始化前被拒绝');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('already in use', $error->getMessage());
            }
        } finally {
            fclose($lease);
        }
        $next = IsolatedDatabaseGuard::acquireLease('beimi_test_guard', $directory);
        self::assertIsResource($next);
        fclose($next);
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }

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
