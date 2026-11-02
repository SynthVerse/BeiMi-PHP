<?php

declare(strict_types=1);

namespace tests\bridge;

use PHPUnit\Framework\TestCase;
use tests\support\PreparationRemainingHttpFixture as Fixture;

require_once dirname(__DIR__) . '/support/IsolatedDatabaseGuard.php';
require_once dirname(__DIR__) . '/support/PreparationHttpBridgeProcess.php';
require_once dirname(__DIR__) . '/support/PreparationRemainingHttpFixture.php';

/** 无应用 bootstrap、数据库或 HTTP 的固定范围准入测试。 */
final class PreparationRemainingHttpAdmissionTest extends TestCase
{
    public function test_all_nine_contracts_pin_their_own_ordered_checks_and_fixed_client(): void
    {
        $root = dirname(__DIR__, 3) . '/BeiMi-uniapp';
        $contract = json_decode(file_get_contents($root . '/' . Fixture::CONTRACT), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(Fixture::TARGETS, array_keys($contract['targets']));
        foreach (Fixture::TARGETS as $target) {
            self::assertTrue(Fixture::acceptsContract($contract, $target), $target);
            foreach (['protocol', 'runId', 'cutoffDate', 'client'] as $field) {
                self::assertFalse(Fixture::acceptsContract(array_replace($contract, [$field => 'unapproved']), $target), $target . ':' . $field);
            }
            $changed = $contract; $changed['targets'][$target]['coreCheckIds'] = array_reverse(Fixture::expectedIds($target));
            self::assertFalse(Fixture::acceptsContract($changed, $target), $target . ':order');
        }
        self::assertCount(22, Fixture::expectedIds('equipment'));
        self::assertCount(8, Fixture::expectedIds('excluded'));
        self::assertFalse(Fixture::acceptsContract($contract, 'expense_refund'));
    }

    public function test_database_and_scope_are_fixed_and_shared_fixture_has_no_schema_mutation(): void
    {
        $connection = ['type' => 'mysql', 'hostname' => '127.0.0.1', 'database' => 'beimi_test_inventory', 'password' => 'isolated-only', 'hostport' => '3307', 'prefix' => 'la_'];
        self::assertTrue(Fixture::acceptsConnection($connection));
        foreach (['database' => 'beimi_test_other', 'hostport' => '3306', 'hostname' => 'localhost', 'prefix' => 'other_'] as $field => $value) {
            self::assertFalse(Fixture::acceptsConnection(array_replace($connection, [$field => $value])));
        }
        self::assertSame([882641, 882642], Fixture::TENANTS);
        self::assertSame([992641, 992642, 992643], Fixture::ACTORS);
        $source = file_get_contents(dirname(__DIR__) . '/support/PreparationIsolatedHttpFixture.php');
        self::assertDoesNotMatchRegularExpression('/\b(?:CREATE|DROP|TRUNCATE|ALTER)\s+TABLE\b/i', $source);
        self::assertStringNotContainsString('PreparationHttpWorkflowTest::', $source);
    }

    public function test_execute_without_current_source_fails_before_application_bootstrap(): void
    {
        $root = dirname(__DIR__, 2); $environment = getenv();
        unset($environment['BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST'], $environment['BEIMI_PREPARATION_HTTP_SOURCE_SHA256']);
        $process = proc_open([PHP_BINARY, $root . '/tests/fixtures/preparation_remaining_http_bridge.php', '--target=equipment', '--execute'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
        self::assertIsResource($process); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        self::assertSame(1, $exit, $diagnostic); $result = json_decode($output, true);
        self::assertFalse($result['passed']); self::assertFalse($result['jointExecuted']);
        self::assertArrayNotHasKey('jointExecutionAttempted', $result);
        self::assertFalse(array_column($result['checks'], 'passed', 'id')['remaining-frozen-sources']);
        self::assertSame(Fixture::expectedIds('equipment'), $result['expectedCheckIds']);
    }
}
