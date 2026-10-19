<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceSetupLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

/** 通过账户管理和期初公开入口验证生命周期，仅使用专用测试门店。 */
final class FinanceAccountLifecycleTest extends TestCase
{
    use CustomerReportTestSupport;
    private const ACCOUNT_TENANT = 884260;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        foreach (['20260907_000001_finance_preparation.sql', '20260907_000002_finance_opening.sql', '20260907_000003_finance_opening_details.sql', '20260907_000004_finance_business.sql', '20260908_000040_finance_account_transfer.sql', '20260908_000041_finance_account_reconciliation.sql'] as $migration) {
            $this->runStatements($this->prepareMigration((string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration)));
        }
        $this->cleanAccounts();
        $this->prepareCustomerReportRequestContext(self::ACCOUNT_TENANT);
    }

    protected function tearDown(): void { $this->cleanAccounts(); $this->prepareCustomerReportRequestContext(); }

    public function test_unused_account_is_deleted_once_and_stale_clients_receive_tombstone(): void
    {
        $account = $this->createAccount();
        $accounts = FinanceSetupLogic::accounts();
        self::assertTrue($accounts['lists'][0]['can_delete']);
        $command = $this->command((int)$account['version']) + ['id' => $account['id']];
        $removed = FinanceSetupLogic::removeAccount($command);
        self::assertNotFalse($removed, FinanceSetupLogic::getError());
        self::assertSame([(int)$account['id']], $removed['removed_account_ids']);
        self::assertSame($removed, FinanceSetupLogic::removeAccount($command));
        self::assertSame([], FinanceSetupLogic::accounts()['lists']);
        self::assertContains((int)$account['id'], FinanceSetupLogic::openingGuide()['removed_account_ids']);
    }

    public function test_unused_disabled_account_can_still_be_deleted(): void
    {
        $account = $this->createAccount();
        $disabled = FinanceSetupLogic::saveAccount($this->command((int)$account['version']) + [
            'id' => $account['id'], 'name' => $account['name'], 'account_type' => 'cash', 'is_enabled' => 0,
        ]);
        self::assertNotFalse($disabled, FinanceSetupLogic::getError());
        $row = FinanceSetupLogic::accounts()['lists'][0];
        self::assertSame(0, $row['is_enabled']);
        self::assertTrue($row['can_delete']);
        self::assertFalse($row['can_disable']);
        self::assertSame('', $row['deletion_blocked_reason']);

        $removed = FinanceSetupLogic::removeAccount($this->command((int)$row['version']) + ['id' => $row['id']]);
        self::assertNotFalse($removed, FinanceSetupLogic::getError());
        self::assertSame([(int)$account['id']], $removed['removed_account_ids']);
        self::assertSame([], FinanceSetupLogic::accounts()['lists']);
        self::assertContains((int)$account['id'], FinanceSetupLogic::openingGuide()['removed_account_ids']);
    }

    public function test_account_opening_draft_requires_explicit_atomic_removal_and_current_version(): void
    {
        $account = $this->createAccount();
        $opening = FinanceSetupLogic::openingAction('item', $this->command(0) + ['category' => 'account', 'subject_id' => $account['id'], 'amount' => '0', 'historical_date' => null, 'due_date' => null, 'source_mode' => 'summary', 'source_reference' => '旧现金核实', 'evidence' => '已查现金']);
        self::assertNotFalse($opening, FinanceSetupLogic::getError());
        $editor = FinanceSetupLogic::openingAction('editor', $this->command((int)$opening['version']) + ['category' => 'account', 'form' => ['id' => $opening['items'][0]['id'], 'subject_id' => $account['id'], 'amount' => '20']]);
        self::assertNotFalse($editor, FinanceSetupLogic::getError());
        $row = FinanceSetupLogic::accounts()['lists'][0];
        self::assertTrue($row['can_delete']); self::assertTrue($row['has_opening_draft']);
        self::assertFalse(FinanceSetupLogic::removeAccount($this->command(1) + ['id' => $account['id']]));
        self::assertStringContainsString('明确确认', FinanceSetupLogic::getError());
        self::assertFalse(FinanceSetupLogic::removeAccount($this->command(1) + ['id' => $account['id'], 'remove_opening_draft' => true, 'expected_opening_version' => 0]));
        self::assertCount(1, FinanceSetupLogic::opening()['items']);
        self::assertCount(1, FinanceSetupLogic::accounts()['lists']);
        $removed = FinanceSetupLogic::removeAccount($this->command(1) + ['id' => $account['id'], 'remove_opening_draft' => true, 'expected_opening_version' => $editor['version']]);
        self::assertNotFalse($removed, FinanceSetupLogic::getError());
        self::assertTrue($removed['opening_draft_removed']);
        self::assertSame((int)$editor['version'] + 1, $removed['opening_version']);
        self::assertSame([], FinanceSetupLogic::opening()['items']);
        self::assertSame([], FinanceSetupLogic::opening()['editor_drafts']);
        self::assertSame([], FinanceSetupLogic::accounts()['lists']);
    }

    public function test_business_draft_blocks_deletion_but_disable_and_restore_preserve_history(): void
    {
        $account = $this->createAccount();
        $draft = \app\api\jxc\logic\FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'capital_in', 'payload' => ['account_id' => $account['id'], 'amount' => '0']]);
        self::assertNotFalse($draft, \app\api\jxc\logic\FinanceBusinessLogic::getError());
        $row = FinanceSetupLogic::accounts()['lists'][0];
        self::assertFalse($row['can_delete']); self::assertTrue($row['can_disable']);
        self::assertStringContainsString('业务草稿', $row['deletion_blocked_reason']);
        self::assertFalse(FinanceSetupLogic::removeAccount($this->command(1) + ['id' => $account['id']]));
        $disabled = FinanceSetupLogic::saveAccount($this->command(1) + ['id' => $account['id'], 'name' => $account['name'], 'account_type' => 'cash', 'is_enabled' => 0]);
        self::assertNotFalse($disabled, FinanceSetupLogic::getError());
        self::assertSame(0, $disabled['is_enabled']);
        self::assertSame($draft['payload'], \app\api\jxc\logic\FinanceBusinessLogic::detail(['id' => $draft['id']])['payload']);
        $restored = FinanceSetupLogic::saveAccount($this->command((int)$disabled['version']) + ['id' => $account['id'], 'name' => $account['name'], 'account_type' => 'cash', 'is_enabled' => 1]);
        self::assertNotFalse($restored, FinanceSetupLogic::getError()); self::assertSame(1, $restored['is_enabled']);
    }

    public function test_version_tenant_and_owner_checks_apply_before_mutation_and_idempotent_replay(): void
    {
        $account = $this->createAccount();
        self::assertFalse(FinanceSetupLogic::removeAccount($this->command(0) + ['id' => $account['id']]));
        $wrongTenant = array_replace($this->command(1), ['expected_tenant_id' => self::ACCOUNT_TENANT + 1, 'id' => $account['id']]);
        self::assertFalse(FinanceSetupLogic::removeAccount($wrongTenant));
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::ACCOUNT_TENANT];
        self::assertFalse(FinanceSetupLogic::removeAccount($this->command(1) + ['id' => $account['id']]));
        $this->prepareCustomerReportRequestContext(self::ACCOUNT_TENANT + 1);
        self::assertFalse(FinanceSetupLogic::removeAccount(array_replace($this->command(1), ['expected_tenant_id' => self::ACCOUNT_TENANT + 1, 'id' => $account['id']])));
        $this->prepareCustomerReportRequestContext(self::ACCOUNT_TENANT);
        self::assertCount(1, FinanceSetupLogic::accounts()['lists']);
        $command = $this->command(1) + ['id' => $account['id']];
        self::assertNotFalse(FinanceSetupLogic::removeAccount($command));
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::ACCOUNT_TENANT];
        self::assertFalse(FinanceSetupLogic::removeAccount($command));
    }

    public function test_confirmed_zero_opening_and_transfer_target_cannot_be_deleted(): void
    {
        $account = $this->createAccount();
        Db::name('finance_opening_source')->insert(['tenant_id' => self::ACCOUNT_TENANT, 'opening_item_id' => 999, 'category' => 'account', 'subject_id' => $account['id'], 'amount' => '0.00', 'activation_date' => date('Y-m-01'), 'source_snapshot' => '{}', 'create_time' => time()]);
        self::assertFalse(FinanceSetupLogic::accounts()['lists'][0]['can_delete']);
        self::assertFalse(FinanceSetupLogic::removeAccount($this->command(1) + ['id' => $account['id']]));
        $target = $this->createAccount('被互转引用的目标');
        Db::name('finance_account_transfer')->insert(['tenant_id' => self::ACCOUNT_TENANT, 'document_id' => 999, 'source_account_id' => $account['id'], 'target_account_id' => $target['id'], 'source_reference' => 'test-ref', 'source_ref' => 'n:999', 'snapshot' => '{}', 'create_time' => time()]);
        self::assertFalse(FinanceSetupLogic::removeAccount($this->command(1) + ['id' => $target['id']]));
        self::assertCount(2, FinanceSetupLogic::accounts()['lists']);
        $openingTarget = $this->createAccount('历史在途目标账户');
        Db::name('finance_opening_source')->insert(['tenant_id' => self::ACCOUNT_TENANT, 'opening_item_id' => 1000, 'category' => 'transit', 'subject_id' => $account['id'], 'amount' => '10.00', 'activation_date' => date('Y-m-01'), 'source_snapshot' => json_encode(['details' => ['target_account_id' => $openingTarget['id']]]), 'create_time' => time()]);
        self::assertFalse(FinanceSetupLogic::removeAccount($this->command(1) + ['id' => $openingTarget['id']]));
    }

    public function test_stale_business_editor_cannot_reference_an_account_after_deletion(): void
    {
        $account = $this->createAccount();
        self::assertNotFalse(FinanceSetupLogic::removeAccount($this->command(1) + ['id' => $account['id']]));
        $draft = \app\api\jxc\logic\FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'capital_in', 'payload' => ['account_id' => $account['id'], 'amount' => '30']]);
        self::assertFalse($draft);
        self::assertStringContainsString('账户', \app\api\jxc\logic\FinanceBusinessLogic::getError());
        self::assertFalse(FinanceSetupLogic::openingAction('editor', $this->command(0) + ['category' => 'account', 'form' => ['subject_id' => $account['id'], 'amount' => '30']]));
        self::assertStringContainsString('账户', FinanceSetupLogic::getError());
    }

    private function createAccount(string $name = '可纠正误建账户'): array
    {
        $account = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => $name, 'account_type' => 'cash']);
        self::assertNotFalse($account, FinanceSetupLogic::getError());
        return $account;
    }

    private function command(int $version): array
    {
        return ['expected_tenant_id' => self::ACCOUNT_TENANT, 'expected_version' => $version, 'idempotency_key' => 'account-lifecycle-test-' . ++$this->sequence];
    }

    private function cleanAccounts(): void
    {
        foreach (['finance_account_reconciliation', 'finance_account_transfer', 'finance_money_transaction', 'finance_entry', 'finance_source', 'finance_document', 'finance_command', 'finance_opening_item_detail', 'finance_opening_source', 'finance_opening_item', 'finance_opening_book', 'finance_setup_action', 'finance_account', 'finance_preparation'] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::ACCOUNT_TENANT, self::ACCOUNT_TENANT + 1])->delete();
        }
    }
}
