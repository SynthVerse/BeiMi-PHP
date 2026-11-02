<?php

declare(strict_types=1);

namespace tests\bridge;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/support/IsolatedDatabaseGuard.php';
require_once dirname(__DIR__) . '/support/PreparationHttpBridgeProcess.php';
require_once dirname(__DIR__) . '/support/ExpenseRefundHttpFixture.php';

/** 仅公开 CLI 准入检查；不加载数据库 bootstrap、不发 HTTP。 */
final class ExpenseRefundHttpBridgeAdmissionTest extends TestCase
{
    public function test_fixture_protocol_dates_and_run_id_match_the_frozen_client_contract_before_execution(): void
    {
        $path = dirname(__DIR__, 3) . '/BeiMi-uniapp/docs/ui-previews/expense-refund-preparation-20261102/implementation/expense-refund-http-contract.json';
        $contract = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $constants = (new \ReflectionClass(\tests\support\ExpenseRefundHttpFixture::class))->getConstants();
        self::assertSame($contract['runId'], $constants['RUN_ID']);
        self::assertSame($contract['cutoffDate'], $constants['CUTOFF_DATE'] ?? null);
        self::assertTrue(\tests\support\ExpenseRefundHttpFixture::acceptsContract($contract, $contract['coreCheckIds']));
        foreach (['protocol', 'runId', 'cutoffDate', 'category', 'client'] as $field) {
            $changed = array_replace($contract, [$field => 'unapproved']);
            self::assertFalse(\tests\support\ExpenseRefundHttpFixture::acceptsContract($changed, $contract['coreCheckIds']), $field);
        }
    }

    public function test_fixture_accepts_only_the_existing_inventory_database_and_contains_no_schema_rebuild_entry(): void
    {
        $connection = ['type' => 'mysql', 'hostname' => '127.0.0.1', 'database' => 'beimi_test_inventory', 'password' => 'isolated-only', 'hostport' => '3307', 'prefix' => 'la_'];
        self::assertTrue(\tests\support\ExpenseRefundHttpFixture::acceptsConnection($connection));
        self::assertFalse(\tests\support\ExpenseRefundHttpFixture::acceptsConnection(array_replace($connection, ['database' => 'beimi_test_other'])));
        self::assertFalse(\tests\support\ExpenseRefundHttpFixture::acceptsConnection(array_replace($connection, ['hostport' => '3306'])));
        self::assertSame([882641, 882642], \tests\support\ExpenseRefundHttpFixture::TENANTS);
        self::assertSame([992641, 992642, 992643], \tests\support\ExpenseRefundHttpFixture::ACTORS);
        $source = file_get_contents(dirname(__DIR__) . '/support/ExpenseRefundHttpFixture.php');
        foreach (['ensureCustomerReportTables', 'runStatements', 'prepareMigration', 'authoritativeCreateTable', 'setUp('] as $entry) {
            self::assertStringNotContainsString($entry, $source);
        }
        self::assertDoesNotMatchRegularExpression('/\b(?:CREATE|DROP|TRUNCATE|ALTER)\s+TABLE\b/i', $source);
    }

    public function test_expense_refund_is_known_but_missing_source_selection_fails_closed_before_bootstrap(): void
    {
        $root = dirname(__DIR__, 2);
        $environment = getenv();
        unset($environment['BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST'], $environment['BEIMI_PREPARATION_HTTP_SOURCE_SHA256']);
        $process = proc_open([PHP_BINARY, $root . '/tests/fixtures/preparation_http_bridge.php', '--target=expense_refund', '--preflight'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        self::assertSame(1, $exit, $diagnostic);
        $result = json_decode($output, true);
        self::assertIsArray($result);
        self::assertSame('expense_refund', $result['target']);
        self::assertFalse($result['passed']);
        self::assertFalse($result['jointExecuted']);
        self::assertArrayNotHasKey('jointExecutionAttempted', $result);
        self::assertFalse(array_column($result['checks'], 'passed', 'id')['expense-refund-frozen-sources']);
        self::assertCount(22, $result['expectedCheckIds']);
    }
}
