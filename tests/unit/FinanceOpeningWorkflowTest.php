<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceOpeningService;
use app\api\jxc\logic\FinanceSetupLogic;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class FinanceOpeningWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        foreach (['20260907_000001_finance_preparation.sql', '20260907_000002_finance_opening.sql'] as $migration) {
            $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration)));
        }
        Db::execute('CREATE TABLE IF NOT EXISTS la_vendor (id int unsigned AUTO_INCREMENT PRIMARY KEY, tenant_id int unsigned NOT NULL, supplier_name varchar(100) NOT NULL) ENGINE=InnoDB');
        $this->clean();
        Config::set(['activation_tenant_ids' => []], 'finance');
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->clean();
        Config::set(['activation_tenant_ids' => []], 'finance');
    }

    public function test_complete_opening_confirms_once_and_never_creates_current_period_transactions(): void
    {
        $account = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '期初银行', 'account_type' => 'bank']);
        self::assertNotFalse($account);
        $customer = $this->createCustomer('期初客户');
        $supplier = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '期初供应商']);
        $this->action('item', $this->item('account', (int)$account['id'], '10000.50'));
        $this->action('item', $this->item('receivable', $customer, '800.20'));
        $this->action('item', $this->item('payable', $supplier, '600.10'));
        $pending = $this->ready();
        self::assertSame('pending', $pending['status']);
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->count());
        $key = $this->command((int)$pending['version']);
        self::assertFalse(FinanceSetupLogic::openingAction('confirm', $key));
        self::assertStringContainsString('迁移验收', FinanceSetupLogic::getError());
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        $beforeSales = Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count();
        $active = FinanceSetupLogic::openingAction('confirm', $key);
        self::assertNotFalse($active, FinanceSetupLogic::getError());
        self::assertSame('active', $active['status']);
        self::assertSame($active, FinanceSetupLogic::openingAction('confirm', $key));
        self::assertSame($active, FinanceSetupLogic::opening());
        self::assertSame(3, Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame($beforeSales, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('0.00', Db::name('customer')->where('id', $customer)->value('order_receivable'));
        self::assertNull($active['items'][1]['historical_date']);
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000002_finance_opening.sql')));
        self::assertSame($active, FinanceSetupLogic::opening());
        foreach (['item', 'remove', 'review', 'reopen', 'confirm'] as $action) {
            self::assertFalse(FinanceSetupLogic::openingAction($action, $this->command((int)$active['version'])));
        }
        self::assertFalse(FinanceSetupLogic::savePreparation($this->command(1) + ['activation_date' => '2026-10-01']));
        Db::name('customer')->where('id', $customer)->update(['customer_name' => '后续改名']);
        self::assertSame($active, FinanceSetupLogic::opening(), '确认快照不随档案改名覆盖');
    }

    public function test_unknown_amount_review_conflicts_and_new_account_block_activation(): void
    {
        $customer = $this->createCustomer('未知金额客户');
        $result = $this->action('item', $this->item('receivable', $customer, null));
        self::assertNull($result['items'][0]['amount']);
        self::assertNull($result['categories'][1]['total']);
        $this->action('review', ['category' => 'receivable', 'state' => 'none', 'evidence' => '测试冲突']);
        $result = FinanceSetupLogic::opening();
        self::assertStringContainsString('标记无余额', implode('；', $result['blockers']));
        self::assertStringContainsString('金额尚未核实', implode('；', $result['blockers']));
        FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '未核实现金', 'account_type' => 'cash']);
        self::assertStringContainsString('缺少核实余额', implode('；', FinanceSetupLogic::opening()['blockers']));
        self::assertFalse(FinanceSetupLogic::openingAction('submit', $this->command((int)$result['version'])));
    }

    public function test_edit_resets_category_review_and_stale_version_or_changed_retry_cannot_overwrite(): void
    {
        $customer = $this->createCustomer('并发客户');
        $data = $this->item('receivable', $customer, '20.10');
        $payload = $this->command(0) + $data;
        $first = FinanceSetupLogic::openingAction('item', $payload);
        self::assertNotFalse($first);
        self::assertSame($first, FinanceSetupLogic::openingAction('item', $payload));
        $payload['amount'] = '99.00';
        self::assertFalse(FinanceSetupLogic::openingAction('item', $payload));
        self::assertStringContainsString('同一提交标识', FinanceSetupLogic::getError());
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command(0) + $data));
        self::assertStringContainsString('已更新', FinanceSetupLogic::getError());
        $this->action('review', ['category' => 'receivable', 'state' => 'complete', 'evidence' => '已逐笔核对']);
        $result = $this->action('item', array_merge($data, ['id' => $first['items'][0]['id'], 'amount' => '30.15']));
        self::assertSame('unknown', $result['categories'][1]['review']['state']);
        self::assertSame('30.15', $result['categories'][1]['total']);
        $this->action('review', ['category' => 'receivable', 'state' => 'complete', 'evidence' => '重新核对']);
        $result = $this->action('remove', ['id' => $first['items'][0]['id']]);
        self::assertSame([], $result['items']);
        self::assertSame('unknown', $result['categories'][1]['review']['state']);
    }

    public function test_pending_date_changes_require_reopen_and_employee_cannot_confirm(): void
    {
        $pending = $this->ready();
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        $this->prepare('2026-09-02', 1);
        self::assertFalse(FinanceSetupLogic::openingAction('confirm', $this->command((int)$pending['version'])));
        self::assertStringContainsString('已变化', FinanceSetupLogic::getError());
        $this->action('reopen');
        $pending = $this->action('submit');
        $employee = WorkforceLogic::saveEmployee(['name' => '期初经办', 'mobile' => '13800009927', 'bind_user_id' => 996927,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.opening.prepare']]);
        self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->jxcFromUserToken = true; request()->userId = 996927; request()->adminId = 0;
        self::assertNotFalse(FinanceSetupLogic::opening());
        self::assertFalse(FinanceSetupLogic::openingAction('confirm', $this->command((int)$pending['version'])));
        self::assertStringContainsString('最高权限', FinanceSetupLogic::getError());
        $this->action('reopen');
        Db::name('employee_permission')->where('employee_id', $employee['id'])->where('tenant_id', self::TENANT_ID)->delete();
        self::assertFalse(FinanceSetupLogic::opening());
        self::assertFalse(FinanceSetupLogic::openingAction('submit', $this->command((int)$pending['version'] + 1)));
    }

    public function test_subjects_are_tenant_scoped_primary_customers_paginated_and_summary_cannot_mix(): void
    {
        $customer = $this->createCustomer('主客户');
        $child = $this->createCustomer('收货点', $customer);
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command(0) + $this->item('receivable', $child, '10')));
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command(0) + $this->item('receivable', $customer, '10')));
        self::assertSame([], FinanceSetupLogic::opening(['category' => 'receivable'], true)['lists']);
        $this->prepareCustomerReportRequestContext();
        $this->action('item', $this->item('receivable', $customer, '10'));
        $mixed = array_merge($this->item('receivable', $customer, '20'), ['source_reference' => '另一汇总', 'source_mode' => 'summary']);
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command(1) + $mixed));
        self::assertStringContainsString('不能混用', FinanceSetupLogic::getError());
        for ($i = 0; $i < 22; $i++) { $this->createCustomer('分页客户' . $i); }
        $first = FinanceSetupLogic::opening(['category' => 'receivable', 'keyword' => '分页客户', 'page' => 1], true);
        $second = FinanceSetupLogic::opening(['category' => 'receivable', 'keyword' => '分页客户', 'page' => 2], true);
        self::assertCount(20, $first['lists']); self::assertCount(2, $second['lists']);
    }

    /** @dataProvider invalidAmounts */
    public function test_invalid_amounts_are_rejected(mixed $amount): void
    {
        $customer = $this->createCustomer('金额格式');
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command(0) + $this->item('receivable', $customer, $amount)));
        self::assertSame([], FinanceSetupLogic::opening()['items']);
    }
    public static function invalidAmounts(): array { return [[-1], ['-1'], ['1e3'], ['0'], ['1.001'], [1.1], ['1000000000000'], [[]]]; }

    public function test_excluded_nonzero_balances_and_missing_dates_cannot_be_silently_accepted(): void
    {
        $result = $this->action('review', ['category' => 'excluded', 'state' => 'unresolved', 'evidence' => '有供应商预付款需要承接']);
        self::assertStringContainsString('范围外业务', implode('；', $result['blockers']));
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command(1) + $this->item('advance', 1, '10')));
        $customer = $this->createCustomer('日期客户');
        $this->prepare();
        $data = $this->item('receivable', $customer, '10');
        $data['historical_date'] = '2026-09-01';
        $result = $this->action('item', $data);
        self::assertStringContainsString('必须早于', implode('；', $result['blockers']));
        $data['historical_date'] = '2026-02-30';
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command((int)$result['version']) + $data));
    }

    private function ready(): array
    {
        $this->prepare();
        foreach (FinanceSetupLogic::opening()['categories'] as $category) {
            $this->action('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '按统一截点核对账本与真实流水']);
        }
        return $this->action('submit');
    }

    public function test_account_change_after_submit_requires_recheck_and_zero_balance_is_explicit(): void
    {
        $account = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '零余额现金', 'account_type' => 'cash']);
        $this->action('item', $this->item('account', (int)$account['id'], '0.00'));
        $pending = $this->ready();
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        self::assertNotFalse(FinanceSetupLogic::saveAccount($this->command(1) + ['id' => $account['id'], 'name' => '现金新名称', 'account_type' => 'cash']));
        self::assertFalse(FinanceSetupLogic::openingAction('confirm', $this->command((int)$pending['version'])));
        self::assertStringContainsString('已变化', FinanceSetupLogic::getError());
        $this->action('reopen');
        $pending = $this->action('submit');
        $active = $this->action('confirm');
        self::assertSame('0.00', $active['items'][0]['amount']);
        self::assertSame('现金新名称', $active['items'][0]['subject_name']);
    }

    public function test_source_insert_failure_rolls_back_all_new_sources_and_confirmation(): void
    {
        $firstCustomer = $this->createCustomer('回滚甲');
        $secondCustomer = $this->createCustomer('回滚乙');
        $this->action('item', $this->item('receivable', $firstCustomer, '10'));
        $this->action('item', $this->item('receivable', $secondCustomer, '20'));
        $pending = $this->ready();
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        // 故意制造第二笔唯一约束失败，证明第一笔新写入也会回滚。
        Db::name('finance_opening_source')->insert(['tenant_id' => self::TENANT_ID, 'opening_item_id' => $pending['items'][1]['id'],
            'category' => 'receivable', 'subject_id' => $secondCustomer, 'amount' => '20.00',
            'activation_date' => '2026-09-01', 'source_snapshot' => '{}', 'create_time' => time()]);
        $failed = false;
        try { FinanceSetupLogic::openingAction('confirm', $this->command((int)$pending['version'])); }
        catch (\Throwable $error) { $failed = true; }
        self::assertTrue($failed);
        self::assertSame(1, Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('pending', FinanceSetupLogic::opening()['status']);
        self::assertSame($pending['version'], FinanceSetupLogic::opening()['version']);
        self::assertSame(0, Db::name('finance_setup_action')->where('tenant_id', self::TENANT_ID)->where('action_type', 'opening.confirm')->count());
    }
    private function prepare(string $date = '2026-09-01', int $version = 0): void
    {
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command($version) + ['activation_date' => $date,
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]), FinanceSetupLogic::getError());
    }
    private function action(string $action, array $data = []): array
    {
        $version = (int)FinanceSetupLogic::opening()['version'];
        $result = FinanceSetupLogic::openingAction($action, $this->command($version) + $data);
        self::assertNotFalse($result, FinanceSetupLogic::getError());
        return $result;
    }
    private function item(string $category, int $subject, mixed $amount): array
    {
        return ['category' => $category, 'subject_id' => $subject, 'amount' => $amount,
            'historical_date' => null, 'due_date' => null, 'source_mode' => 'detail', 'source_reference' => '旧账期初凭据', 'evidence' => '已核实截至8月31日的未结余额'];
    }
    private function command(int $version): array
    {
        return ['expected_tenant_id' => self::TENANT_ID, 'expected_version' => $version, 'idempotency_key' => 'opening-workflow-' . ++$this->sequence];
    }
    private function clean(): void
    {
        foreach (['finance_opening_source', 'finance_opening_item', 'finance_opening_book', 'finance_setup_action', 'finance_account', 'finance_preparation', 'vendor'] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        }
        $this->cleanCustomerReportData();
    }
}
