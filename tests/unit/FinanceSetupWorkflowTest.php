<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceSetupLogic;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class FinanceSetupWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->runStatements($this->migration());
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        $this->prepareCustomerReportRequestContext();
    }

    public function test_account_retry_creates_one_record_and_audit_and_replay_migration_preserves_it(): void
    {
        $payload = $this->account();
        $first = FinanceSetupLogic::saveAccount($payload);
        self::assertNotFalse($first, FinanceSetupLogic::getError());
        self::assertSame($first, FinanceSetupLogic::saveAccount($payload));
        $this->runStatements($this->migration());
        self::assertCount(1, FinanceSetupLogic::accounts()['lists']);
        self::assertSame(1, Db::name('finance_setup_action')->where('tenant_id', self::TENANT_ID)->count());
        self::assertArrayNotHasKey('balance', $first);
        $payload['name'] = '不同内容';
        self::assertFalse(FinanceSetupLogic::saveAccount($payload));
        self::assertStringContainsString('同一提交标识', FinanceSetupLogic::getError());
    }

    public function test_preparation_keeps_zero_formal_effect_and_reports_previous_day_and_version_conflicts(): void
    {
        $payload = $this->command('opening-initial-01') + [
            'activation_date' => '2026-09-01', 'inventory_cost_reviewed' => 1,
            'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1, 'notes' => '旧工资待付仍需逐项录入',
        ];
        $result = FinanceSetupLogic::savePreparation($payload);
        self::assertNotFalse($result, FinanceSetupLogic::getError());
        self::assertSame('2026-08-31', $result['cutoff_date']);
        self::assertSame('draft', $result['status']);
        self::assertSame(self::ADMIN_ID, $result['created_by']['id']);
        self::assertSame('tenant_admin', $result['last_modified_by']['type']);
        self::assertGreaterThan(0, $result['created_time']);
        self::assertSame($result, FinanceSetupLogic::savePreparation($payload));
        $payload['idempotency_key'] = 'opening-conflict-02';
        self::assertFalse(FinanceSetupLogic::savePreparation($payload));
        self::assertStringContainsString('已更新', FinanceSetupLogic::getError());
        self::assertSame('2026-09-01', FinanceSetupLogic::preparation()['activation_date']);
    }

    public function test_switching_store_or_using_foreign_account_is_rejected(): void
    {
        $account = FinanceSetupLogic::saveAccount($this->account());
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertSame([], FinanceSetupLogic::accounts()['lists']);
        self::assertFalse(FinanceSetupLogic::saveAccount($this->account()));
        self::assertStringContainsString('门店已变化', FinanceSetupLogic::getError());
        $payload = $this->account();
        $payload['expected_tenant_id'] = self::OTHER_TENANT_ID;
        $payload['id'] = $account['id'];
        $payload['expected_version'] = 1;
        self::assertFalse(FinanceSetupLogic::saveAccount($payload));
        self::assertStringContainsString('不属于', FinanceSetupLogic::getError());
        self::assertSame([], FinanceSetupLogic::accounts()['lists']);
    }

    public function test_account_cannot_change_type_and_stale_editor_cannot_overwrite_or_duplicate_name(): void
    {
        $first = FinanceSetupLogic::saveAccount($this->account());
        $edit = array_merge($this->account(), ['id' => $first['id'], 'expected_version' => 1, 'idempotency_key' => 'account-edit-00001', 'is_enabled' => 0]);
        self::assertNotFalse(FinanceSetupLogic::saveAccount($edit), FinanceSetupLogic::getError());
        self::assertSame(0, (int)FinanceSetupLogic::accounts()['lists'][0]['is_enabled']);
        $edit['idempotency_key'] = 'account-stale-0001';
        self::assertFalse(FinanceSetupLogic::saveAccount($edit));
        $edit['expected_version'] = 2;
        $edit['account_type'] = 'bank';
        self::assertFalse(FinanceSetupLogic::saveAccount($edit));
        self::assertStringContainsString('不能更换类型', FinanceSetupLogic::getError());
        $new = $this->account();
        $new['idempotency_key'] = 'account-duplicate-1';
        self::assertFalse(FinanceSetupLogic::saveAccount($new));
        self::assertStringContainsString('同名账户', FinanceSetupLogic::getError());
    }

    public function test_employee_can_only_prepare_with_explicit_permission_and_cannot_manage_accounts(): void
    {
        $employee = WorkforceLogic::saveEmployee([
            'name' => '财务准备测试员', 'mobile' => '13800009925', 'bind_user_id' => 996925,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.opening.prepare'],
        ]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->jxcFromUserToken = true;
        request()->userId = 996925;
        request()->adminId = 0;
        self::assertFalse(FinanceSetupLogic::accounts());
        self::assertFalse(FinanceSetupLogic::saveAccount($this->account()));
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command('employee-opening-1') + ['activation_date' => '2026-09-01']));
        self::assertFalse(FinanceSetupLogic::workbench()['capabilities']['manage_accounts']);
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->delete();
        self::assertFalse(FinanceSetupLogic::preparation());
        self::assertFalse(FinanceSetupLogic::savePreparation($this->command('employee-opening-1') + ['activation_date' => '2026-09-01']));
    }

    public function test_nonroot_backend_identity_cannot_inherit_a_same_number_employee_permission(): void
    {
        $employee = WorkforceLogic::saveEmployee([
            'name' => '同号身份测试员', 'mobile' => '13800009926', 'bind_user_id' => 996926,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.opening.prepare'],
        ]);
        self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->jxcFromUserToken = false;
        request()->userId = 996926;
        request()->adminId = 996926;
        self::assertFalse(FinanceSetupLogic::preparation());
        self::assertFalse(FinanceSetupLogic::workbench()['capabilities']['prepare_opening']);
    }

    /** @dataProvider invalidDates */
    public function test_invalid_date_is_rejected_without_saving(mixed $date): void
    {
        self::assertFalse(FinanceSetupLogic::savePreparation($this->command('invalid-date-0001') + ['activation_date' => $date]));
        self::assertSame(0, FinanceSetupLogic::preparation()['version']);
    }

    public static function invalidDates(): array { return [['2026-02-30'], ['2026-9-01'], ['0000-01-01'], [['2026-09-01']]]; }

    private function command(string $key): array
    {
        return ['expected_tenant_id' => self::TENANT_ID, 'expected_version' => 0, 'idempotency_key' => $key];
    }

    private function account(): array
    {
        return $this->command('account-create-001') + ['name' => '门店微信', 'account_type' => 'wechat', 'is_enabled' => 1];
    }

    private function migration(): string
    {
        return $this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000001_finance_preparation.sql'));
    }

    private function clean(): void
    {
        foreach (['finance_setup_action', 'finance_account', 'finance_preparation'] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        }
        $this->cleanCustomerReportData();
    }
}
