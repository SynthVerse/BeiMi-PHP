<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\{FinanceBusinessException, FinanceBusinessLogic, FinancePreview};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

/** 仅使用隔离测试数据库，验证正式入口聚合错误、预览回滚和草稿语义。 */
final class FinanceBusinessValidationWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;
    private const VALIDATION_TENANT = 884260;
    private int $account;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext(); $this->ensureCustomerReportTables();
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*finance*.sql') as $file) {
            if (str_ends_with($file, '20261015_000002_finance_draft_continuation.sql') && Db::query("SHOW COLUMNS FROM la_finance_document LIKE 'resume_requested'")) { continue; }
            $this->runStatements($this->prepareMigration((string)file_get_contents($file)));
        }
        $this->cleanValidation(); $this->prepareCustomerReportRequestContext(self::VALIDATION_TENANT);
        Db::name('finance_preparation')->insert(['tenant_id' => self::VALIDATION_TENANT, 'activation_date' => date('Y-m-01')]);
        Db::name('finance_opening_book')->insert(['tenant_id' => self::VALIDATION_TENANT, 'status' => 'active', 'reviews' => '{}', 'confirmed_snapshot' => '{}', 'created_by' => '{}', 'last_modified_by' => '{}', 'create_time' => time(), 'update_time' => time()]);
        $this->account = (int)Db::name('finance_account')->insertGetId(['tenant_id' => self::VALIDATION_TENANT, 'name' => '隔离校验银行账户', 'account_type' => 'bank', 'is_enabled' => 1, 'create_time' => time(), 'update_time' => time()]);
    }

    protected function tearDown(): void { $this->cleanValidation(); $this->prepareCustomerReportRequestContext(); }

    public function test_record_and_preview_collect_missing_fields_and_leave_no_financial_writes(): void
    {
        $command = $this->command() + ['type' => 'capital_in', 'payload' => $this->payload()];
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
        $details = FinanceBusinessLogic::getReturnData();
        self::assertSame('FINANCE_VALIDATION_FAILED', $details['error_code']);
        self::assertSame(['reason', 'evidence_ids'], array_column($details['field_errors'], 'field'));
        try { FinancePreview::calculate($command + ['action' => 'record']); self::fail('preview must reject'); }
        catch (FinanceBusinessException $error) { self::assertSame($details, $error->details()); }
        foreach (['finance_document', 'finance_command', 'finance_entry', 'finance_money_transaction'] as $table) {
            self::assertSame(0, Db::name($table)->where('tenant_id', self::VALIDATION_TENANT)->count(), $table);
        }
    }

    public function test_save_and_prepare_still_accept_incomplete_facts_but_confirm_does_not(): void
    {
        $draft = FinanceBusinessLogic::action('save', $this->command() + ['type' => 'capital_in', 'payload' => $this->payload()]);
        self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        $prepared = FinanceBusinessLogic::action('prepare', $this->command((int)$draft['version']) + ['id' => $draft['id'], 'payload' => $this->payload()]);
        self::assertNotFalse($prepared, FinanceBusinessLogic::getError()); self::assertSame('pending', $prepared['status']);
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command((int)$prepared['version']) + ['id' => $prepared['id']]));
        self::assertSame(['reason', 'evidence_ids'], array_column(FinanceBusinessLogic::getReturnData()['field_errors'], 'field'));
        $stored = Db::name('finance_document')->where('tenant_id', self::VALIDATION_TENANT)->where('id', $prepared['id'])->find();
        self::assertSame('pending', $stored['status']); self::assertSame((int)$prepared['version'], (int)$stored['version']);
    }

    public function test_stale_draft_version_is_distinct_from_field_validation(): void
    {
        $draft = FinanceBusinessLogic::action('save', $this->command() + ['type' => 'capital_in', 'payload' => $this->payload()]);
        self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('save', $this->command(0) + ['id' => $draft['id'], 'payload' => $this->payload()]));
        self::assertSame('FINANCE_VERSION_CONFLICT', FinanceBusinessLogic::getReturnData()['error_code']);
        self::assertSame([], FinanceBusinessLogic::getReturnData()['field_errors']);
    }

    private function command(int $version = 0): array { return ['expected_tenant_id' => self::VALIDATION_TENANT, 'expected_version' => $version, 'idempotency_key' => 'validation-workflow-' . ++$this->sequence]; }
    private function payload(): array { return ['account_id' => $this->account, 'actual_date' => date('Y-m-d'), 'amount' => '500', 'counterparty' => '隔离投入人', 'reason' => '', 'evidence_ids' => []]; }
    private function cleanValidation(): void
    {
        foreach (['finance_transaction_identity', 'finance_entry', 'finance_source', 'finance_command', 'finance_document', 'finance_money_transaction', 'finance_period', 'finance_opening_source', 'finance_opening_book', 'finance_account', 'finance_preparation'] as $table) { Db::name($table)->where('tenant_id', self::VALIDATION_TENANT)->delete(); }
    }
}
