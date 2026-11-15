<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Run composer install before PHPUnit.\n");
    exit(1);
}

require $autoload;
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'IsolatedDatabaseGuard.php';

// PHPUnit must never inherit the root .env connection. The dedicated file is
// intentionally untracked and is checked before ThinkPHP initializes facades.
$rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
if (($_SERVER['JXC_PHPUNIT_ENV'] ?? '') !== 'testing') {
    fwrite(STDERR, "Refusing PHPUnit: testing environment marker is required.\n");
    exit(1);
}

$testingEnvFile = $rootPath . '.env.testing';
if (!is_file($testingEnvFile)) {
    fwrite(STDERR, "Refusing PHPUnit: create an untracked .env.testing from .example.env first.\n");
    exit(1);
}

$testingEnv = parse_ini_file($testingEnvFile, true, INI_SCANNER_RAW) ?: [];
if (!\tests\support\IsolatedDatabaseGuard::acceptsEnvironment($testingEnv)) {
    fwrite(STDERR, "Refusing PHPUnit: require TCP MySQL at 127.0.0.1:3307, a beimi_test_* database, the la_ prefix, a non-empty test password, and an explicit isolated marker.\n");
    exit(1);
}

// Select .env.testing before initialize; do not load the root .env fallback.
// Frontend protocol tests also launch PHPUnit. Share one workspace lock across
// Windows user/sandbox TEMP directories, before any fixture can be cleaned.
try {
    $databaseLease = \tests\support\IsolatedDatabaseGuard::acquireLease(
        (string)$testingEnv['DATABASE']['DATABASE'], $rootPath . '.scratch/phpunit-leases',
        getenv('BEIMI_PHPUNIT_LEASE_KEY') ?: null, $databaseLeaseToken
    );
    if (is_resource($databaseLease)) {
        putenv('BEIMI_PHPUNIT_LEASE_KEY=' . $databaseLeaseToken);
        $_SERVER['BEIMI_PHPUNIT_LEASE_KEY'] = $databaseLeaseToken;
    }
} catch (\RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
$app = (new \think\App($rootPath))->setEnvName('testing');
$app->initialize();
