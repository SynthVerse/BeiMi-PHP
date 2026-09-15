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
        foreach (['20260907_000001_finance_preparation.sql', '20260907_000002_finance_opening.sql', '20260907_000003_finance_opening_details.sql',
            '20260907_000004_finance_business.sql', '20260907_000014_finance_cost.sql'] as $migration) {
            $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration)));
        }
        Db::execute('CREATE TABLE IF NOT EXISTS la_vendor (id int unsigned AUTO_INCREMENT PRIMARY KEY, tenant_id int unsigned NOT NULL, supplier_name varchar(100) NOT NULL) ENGINE=InnoDB');
        $this->clean();
        Config::set(['activation_mode' => 'allowlist', 'activation_tenant_ids' => []], 'finance');
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->clean();
        Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');
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

    public function test_all_activation_mode_confirms_an_opening_without_a_tenant_allowlist_entry(): void
    {
        $pending = $this->ready();
        Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');

        $active = FinanceSetupLogic::openingAction('confirm', $this->command((int)$pending['version']));

        self::assertNotFalse($active, FinanceSetupLogic::getError());
        self::assertSame('active', $active['status']);
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
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command(1) + $this->item('excluded', 1, '10')));
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

    public function test_nonzero_legacy_balances_keep_their_source_type_and_do_not_recreate_income_or_expenses(): void
    {
        $customer = $this->createCustomer('往来客户');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '费用与采购往来方']);
        $employee = $this->openingEmployee();
        $entries = [
            ['advance', $customer, '600.00'], ['customer_refund', $customer, '100.00'], ['recovery', $customer, '1000.00'],
            ['supplier_refund', $vendor, '200.00'], ['expense_refund', $vendor, '300.00'], ['expense_payable', $vendor, '400.00'],
            ['salary', (int)$employee['id'], '5000.00'], ['reimbursement', (int)$employee['id'], '800.00'],
        ];
        foreach ($entries as [$category, $subject, $amount]) {
            $this->action('item', $this->extendedItem($category, $subject, $amount));
        }
        $this->ready();
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        $active = $this->action('confirm');
        self::assertCount(8, $active['items']);
        self::assertSame(0, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('0.00', Db::name('customer')->where('id', $customer)->value('order_receivable'));
        foreach ($entries as [$category, $subject, $amount]) {
            $source = Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', $category)->find();
            self::assertSame($amount, $source['amount']);
            $snapshot = json_decode($source['source_snapshot'], true);
            self::assertSame('原合法来源-' . $category, $snapshot['details']['origin_reference']);
            if (in_array($category, ['salary', 'reimbursement'], true)) { self::assertSame('2026-08', $snapshot['details']['benefit_month']); }
        }
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'account')->count());
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000003_finance_opening_details.sql')));
        self::assertSame($active, FinanceSetupLogic::opening());
    }

    public function test_missing_or_future_benefit_month_and_missing_legal_sources_block_confirmation(): void
    {
        $employee = $this->openingEmployee();
        $data = $this->extendedItem('salary', (int)$employee['id'], '5000.00');
        $data['details'] = ['benefit_month' => '', 'origin_reference' => ''];
        $first = $this->action('item', $data);
        self::assertStringContainsString('原受益月份尚未核实', implode('；', $first['blockers']));
        self::assertStringContainsString('原工资确认或工资表尚未核实', implode('；', $first['blockers']));
        $this->prepare();
        $data['id'] = $first['items'][0]['id']; $data['details'] = ['benefit_month' => '2026-09', 'origin_reference' => '原工资表'];
        $result = $this->action('item', $data);
        self::assertStringContainsString('不能晚于期初截点', implode('；', $result['blockers']));
        $data['details']['benefit_month'] = '2026-13';
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command((int)$result['version']) + $data));
        $data['details']['benefit_month'] = '2026-08';
        $result = $this->action('item', $data);
        $this->action('remove', ['id' => $data['id']]);
        self::assertSame(0, Db::name('finance_opening_item_detail')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_employee_months_can_be_separate_summaries_but_same_month_cannot_mix_with_details(): void
    {
        $employee = $this->openingEmployee();
        $data = $this->extendedItem('salary', (int)$employee['id'], '5000.00');
        $data['source_mode'] = 'summary'; $data['source_reference'] = '8月工资汇总';
        $this->action('item', $data);
        $data['source_reference'] = '7月工资汇总'; $data['details']['benefit_month'] = '2026-07';
        $result = $this->action('item', $data);
        self::assertCount(2, $result['items']);
        $data['source_reference'] = '7月补录明细'; $data['source_mode'] = 'detail';
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command((int)$result['version']) + $data));
        self::assertStringContainsString('不能混用', FinanceSetupLogic::getError());
    }

    public function test_customer_refund_and_recovery_require_primary_customer_and_detail_sources(): void
    {
        $customer = $this->createCustomer('往来主客户');
        $child = $this->createCustomer('收货点', $customer);
        foreach (['advance', 'customer_refund', 'recovery'] as $category) {
            self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command(0) + $this->extendedItem($category, $child, '10')));
            $data = $this->extendedItem($category, $customer, '10'); $data['source_mode'] = 'summary';
            self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command(0) + $data));
            self::assertStringContainsString('逐笔合法来源', FinanceSetupLogic::getError());
            $subjects = FinanceSetupLogic::opening(['category' => $category], true)['lists'];
            self::assertCount(1, $subjects); self::assertSame($customer, (int)$subjects[0]['id']);
        }
        $data = $this->extendedItem('recovery', $customer, '1000'); $data['details']['receivable_reference'] = '';
        self::assertStringContainsString('原应收凭据尚未核实', implode('；', $this->action('item', $data)['blockers']));
    }

    public function test_general_preparer_cannot_read_write_review_or_spoof_salary_rows(): void
    {
        $employee = $this->openingEmployee(['finance.opening.prepare']);
        $salary = $this->action('item', $this->extendedItem('salary', (int)$employee['id'], '5678.91'));
        $id = $salary['items'][0]['id'];
        $this->action('review', ['category' => 'salary', 'state' => 'complete', 'evidence' => '工资私密依据5678.91']);
        $this->asOpeningEmployee();
        $view = FinanceSetupLogic::opening();
        self::assertSame([], $view['items']);
        self::assertStringNotContainsString('5678.91', json_encode($view));
        $category = array_values(array_filter($view['categories'], static fn(array $row): bool => $row['key'] === 'salary'))[0];
        self::assertFalse($category['can_view']); self::assertNull($category['count']); self::assertNull($category['total']);
        self::assertSame('restricted', $category['review']['state']);
        self::assertFalse(FinanceSetupLogic::opening(['category' => 'salary'], true));
        foreach ([['review', ['category' => 'salary', 'state' => 'none', 'evidence' => '伪造核对']],
            ['item', $this->extendedItem('salary', (int)$employee['id'], '100')], ['remove', ['id' => $id]],
            ['item', ['id' => $id, 'category' => 'reimbursement']]] as [$action, $data]) {
            self::assertFalse(FinanceSetupLogic::openingAction($action, $this->command((int)$view['version']) + $data));
            self::assertStringContainsString('工资', FinanceSetupLogic::getError());
        }
        $this->action('item', $this->extendedItem('reimbursement', (int)$employee['id'], '800'));
        $result = FinanceSetupLogic::opening();
        self::assertCount(1, $result['items']); self::assertSame('reimbursement', $result['items'][0]['category']);
    }

    public function test_salary_permissions_are_independent_and_revocation_filters_old_replays_and_active_snapshots(): void
    {
        $employee = $this->openingEmployee(['finance.opening.prepare', 'finance.salary.view', 'finance.opening.salary.prepare']);
        $this->asOpeningEmployee();
        $data = $this->extendedItem('salary', (int)$employee['id'], '5678.91');
        $salaryCommand = $this->command(0) + $data;
        $salary = FinanceSetupLogic::openingAction('item', $salaryCommand);
        self::assertNotFalse($salary);
        $normalCommand = $this->command((int)$salary['version']) + $this->extendedItem('reimbursement', (int)$employee['id'], '800');
        $normal = FinanceSetupLogic::openingAction('item', $normalCommand);
        self::assertCount(2, $normal['items']);
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.salary.view')->delete();
        self::assertFalse(FinanceSetupLogic::openingAction('item', $salaryCommand));
        $replay = FinanceSetupLogic::openingAction('item', $normalCommand);
        self::assertCount(1, $replay['items']); self::assertStringNotContainsString('5678.91', json_encode($replay));
        $this->prepareCustomerReportRequestContext(); $this->ready();
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance'); $this->action('confirm');
        $this->asOpeningEmployee();
        $active = FinanceSetupLogic::opening();
        self::assertSame('active', $active['status']); self::assertCount(1, $active['items']);
        self::assertStringNotContainsString('5678.91', json_encode($active));
        Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => 'finance.salary.view', 'create_time' => time()]);
        self::assertCount(2, FinanceSetupLogic::opening()['items']);
    }

    public function test_view_only_salary_permission_does_not_allow_preparation_or_deleted_row_replay(): void
    {
        $employee = $this->openingEmployee(['finance.opening.prepare', 'finance.salary.view', 'finance.opening.salary.prepare']);
        $this->asOpeningEmployee();
        $salary = $this->action('item', $this->extendedItem('salary', (int)$employee['id'], '5000'));
        $command = $this->command((int)$salary['version']) + ['id' => $salary['items'][0]['id']];
        self::assertNotFalse(FinanceSetupLogic::openingAction('remove', $command));
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.opening.salary.prepare')->delete();
        self::assertFalse(FinanceSetupLogic::openingAction('remove', $command), '工资记录已消失，重放仍需依据原审计判断类别');
        self::assertFalse(FinanceSetupLogic::openingAction('review', $this->command((int)$salary['version'] + 1) + ['category' => 'salary', 'state' => 'none', 'evidence' => '只读者不应修改']));
        self::assertNotFalse(FinanceSetupLogic::opening(['category' => 'salary'], true));
        self::assertFalse(FinanceSetupLogic::workbench()['capabilities']['prepare_opening_salary']);
    }

    private function openingEmployee(array $permissions = []): array
    {
        $employee = WorkforceLogic::saveEmployee(['name' => '期初员工', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => $permissions]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        return $employee;
    }
    private function asOpeningEmployee(): void
    {
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
    }
    private function extendedItem(string $category, int $subject, string $amount): array
    {
        return $this->item($category, $subject, $amount) + ['details' => ['benefit_month' => '2026-08',
            'origin_reference' => '原合法来源-' . $category, 'receivable_reference' => '原已核销应收']];
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
    public function test_equipment_opening_retains_payment_limit_composition_without_creating_payable(): void
    {
        $supplier = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '设备商']);
        $data = $this->item('equipment', $supplier, '8000.00') + ['details' => [
            'origin_reference' => '冰柜购置合同', 'original_amount' => '12000', 'price_adjustment' => '-1000',
            'paid_amount' => '2000', 'cancelled_amount' => '1000',
        ]];
        $saved = $this->action('item', $data);
        self::assertSame('-1000.00', $saved['items'][0]['details']['price_adjustment']);
        $pending = $this->ready();
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        $active = $this->action('confirm');
        self::assertSame('equipment', $active['items'][0]['category']);
        self::assertSame('8000.00', $active['items'][0]['amount']);
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'expense_payable')->count());
    }

    public function test_transit_and_unclaimed_openings_validate_composition_and_do_not_duplicate_cash(): void
    {
        $source = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '转出银行', 'account_type' => 'bank']);
        $target = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '目标银行', 'account_type' => 'bank']);
        $this->action('item', $this->item('account', (int)$source['id'], '1000'));
        $this->action('item', $this->item('account', (int)$target['id'], '9000'));
        $transit = array_merge($this->item('transit', (int)$source['id'], '690'), ['historical_date' => '2026-08-30', 'details' => [
            'origin_reference' => '银行转账记录', 'target_account_id' => (string)$target['id'], 'principal' => '1000',
            'arrived_amount' => '200', 'returned_amount' => '100', 'withheld_fee' => '10', 'additional_fee' => '3',
        ]]);
        $this->action('item', $transit);
        $unclaimed = array_merge($this->item('unclaimed', (int)$target['id'], '600'), ['historical_date' => '2026-08-31', 'details' => [
            'origin_reference' => '银行核实到账', 'original_amount' => '800', 'claimed_amount' => '200', 'account_inclusion' => 'confirmed',
        ]]);
        $this->action('item', $unclaimed);
        $this->ready(); Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        $active = $this->action('confirm');
        self::assertSame('10000.00', $active['categories'][0]['total']);
        self::assertSame('690.00', $active['items'][2]['amount']);
        self::assertSame('600.00', $active['items'][3]['amount']);
        self::assertSame('3.00', $active['items'][2]['details']['additional_fee']);
    }

    public function test_complex_incomplete_or_inconsistent_openings_remain_drafts_and_block_activation(): void
    {
        $source = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '核对银行', 'account_type' => 'bank']);
        $this->prepare();
        $this->action('item', $this->item('unclaimed', (int)$source['id'], '600') + ['details' => [
            'origin_reference' => '旧到账', 'original_amount' => '800', 'claimed_amount' => '300', 'account_inclusion' => '',
        ]]);
        $errors = implode('；', FinanceSetupLogic::opening()['blockers']);
        self::assertStringContainsString('实际资金日期尚未核实', $errors);
        self::assertStringContainsString('已包含在期初账户余额中尚未核实', $errors);
        $item = FinanceSetupLogic::opening()['items'][0];
        $this->action('item', array_merge($item, ['historical_date' => '2026-08-31', 'details' => array_merge($item['details'], ['account_inclusion' => 'confirmed'])]));
        self::assertStringContainsString('待认领余额与原到账', implode('；', FinanceSetupLogic::opening()['blockers']));
        $this->action('item', $this->item('transit', (int)$source['id'], '10') + ['details' => ['target_account_id' => (string)$source['id']]]);
        self::assertStringContainsString('另一个资金账户', implode('；', FinanceSetupLogic::opening()['blockers']));
    }

    public function test_deferred_opening_retains_exact_monthly_plan_and_rejects_missing_months(): void
    {
        $supplier = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '房东']);
        $this->prepare();
        $data = $this->item('deferred', $supplier, '600') + ['details' => [
            'origin_reference' => '原租赁费用', 'original_amount' => '1200', 'amortized_amount' => '600',
            'paid_amount' => '900', 'unpaid_amount' => '300', 'payable_reference' => '原租金未付',
            'original_service_start' => '2026-06', 'benefited_until' => '2026-08',
            'service_start' => '2026-09', 'service_end' => '2026-11',
            'schedule' => [['month' => '2026-09', 'amount' => '200'], ['month' => '2026-11', 'amount' => '400']],
        ]];
        $saved = $this->action('item', $data);
        self::assertStringContainsString('缺少摊销计划', implode('；', $saved['blockers']));
        $data['id'] = $saved['items'][0]['id'];
        $data['details']['schedule'] = [['month' => '2026-09', 'amount' => '200.01'], ['month' => '2026-10', 'amount' => '200.00'], ['month' => '2026-11', 'amount' => '199.99']];
        $this->action('item', $data);
        self::assertStringContainsString('足额费用应付', implode('；', FinanceSetupLogic::opening()['blockers']));
        $this->action('item', array_merge($this->item('expense_payable', $supplier, '300'), ['source_reference' => '原租金未付', 'details' => ['origin_reference' => '原租赁费用']]));
        foreach (FinanceOpeningService::CATEGORIES as $key => $title) { $this->action('review', ['category' => $key, 'state' => in_array($key, ['deferred', 'expense_payable'], true) ? 'complete' : 'none', 'evidence' => '全部核实']); }
        $this->action('submit'); Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        $active = $this->action('confirm');
        self::assertSame($data['details']['schedule'], $active['items'][0]['details']['schedule']);
        self::assertSame('600.00', $active['items'][0]['amount']);
    }

    public function test_inventory_opening_carries_quantity_and_value_without_adding_physical_stock(): void
    {
        $warehouse = $this->createCustomerReportWarehouse('期初仓库');
        $goods = $this->createCustomerReportGoods('期初商品', 'OPEN-STOCK');
        $sku = $this->customerReportSkuId($goods);
        $stockId = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID,
            'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => '12.3456', 'available_qty' => '12.3456']);
        $subjects = FinanceSetupLogic::opening(['category' => 'inventory'], true);
        self::assertNotFalse($subjects);
        self::assertSame($stockId, (int)$subjects['lists'][0]['id']);
        self::assertStringContainsString('期初仓库', $subjects['lists'][0]['name']);
        $data = $this->item('inventory', $stockId, '246.91') + ['details' => ['quantity' => '12.3456', 'origin_reference' => '盘点及成本核对']];
        $saved = $this->action('item', $data);
        self::assertFalse(FinanceSetupLogic::openingAction('item', $this->command((int)$saved['version']) + array_merge($data, ['source_reference' => '另一份重复盘点'])));
        $this->ready(); Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        $active = $this->action('confirm');
        self::assertSame('12.3456', $active['items'][0]['details']['quantity']);
        self::assertSame('12.3456', Db::name('warehouse_sku_balance')->where('id', $stockId)->value('on_hand_qty'));
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_inventory_cutoff_reverses_later_movements_and_does_not_omit_sold_out_sku(): void
    {
        $this->prepare();
        $warehouse = $this->createCustomerReportWarehouse('截点仓库');
        foreach (['8.0000', '0.0000'] as $current) {
            $goods = $this->createCustomerReportGoods('截点商品' . $current, 'CUT');
            $sku = $this->customerReportSkuId($goods);
            $id = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID,
                'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => $current, 'available_qty' => $current]);
            Db::name('stock_flow')->insert(['tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse, 'goods_id' => $goods,
                'sku_id' => $sku, 'before_stock' => '10.0000', 'after_stock' => $current, 'quantity' => bcsub('10', $current, 4),
                'flow_type' => 2, 'order_type' => 'sales', 'order_id' => 98527, 'create_time' => strtotime('2026-09-02 12:00:00')]);
            self::assertStringContainsString('截点实物库存 #' . $id, implode('；', FinanceSetupLogic::opening()['blockers']));
            $saved = $this->action('item', $this->item('inventory', $id, '200') + ['details' => ['quantity' => '10', 'origin_reference' => '8月31日盘存']]);
            self::assertStringNotContainsString('期初数量与启用截点库存不一致', implode('；', $saved['blockers']));
            $item = $saved['items'][count($saved['items']) - 1];
            self::assertSame('10.0000', $item['subject_snapshot']['cutoff_qty']);
            if ($current !== '0.0000') {
                $wrong = $this->action('item', array_merge($item, ['details' => array_merge($item['details'], ['quantity' => $current])]));
                self::assertStringContainsString('期初数量与启用截点库存不一致', implode('；', $wrong['blockers']));
                $this->action('item', $item);
            }
        }
        foreach (FinanceOpeningService::CATEGORIES as $key => $title) { $this->action('review', ['category' => $key, 'state' => $key === 'inventory' ? 'complete' : 'none', 'evidence' => '截点核实']); }
        $this->action('submit'); Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
        $active = $this->action('confirm');
        self::assertCount(2, $active['items']);
        self::assertSame('400.00', array_values(array_filter($active['categories'], static fn(array $row): bool => $row['key'] === 'inventory'))[0]['total']);
    }

    public function test_opening_stock_lock_blocks_the_shared_sku_lock_even_before_a_balance_exists(): void
    {
        $goods = $this->createCustomerReportGoods('确认锁商品', 'OPEN-LOCK');
        $sku = $this->customerReportSkuId($goods);
        $config = config('database.connections.mysql');
        $connection = new \PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'], $config['username'], $config['password']);
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $connection->exec('SET SESSION innodb_lock_wait_timeout=1');
        Db::startTrans();
        try {
            \app\api\jxc\logic\FinanceOpeningAssets::lockInventory(self::TENANT_ID);
            $blocked = false;
            try { $connection->query('SELECT id FROM la_goods_sku WHERE id=' . $sku . ' FOR UPDATE'); }
            catch (\PDOException $exception) { $blocked = ($exception->errorInfo[1] ?? 0) === 1205; }
            self::assertTrue($blocked, '权威库存写入口所需SKU锁须等待期初确认结束，未创建余额也不能绕过');
        } finally { Db::rollback(); }
        self::assertSame((string)$sku, (string)$connection->query('SELECT id FROM la_goods_sku WHERE id=' . $sku . ' FOR UPDATE')->fetchColumn());
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
        foreach (['finance_cost_effect', 'finance_cost_event', 'finance_cost_shortage', 'finance_cost_position', 'finance_cost_origin', 'finance_period'] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        }
        foreach (['finance_opening_item_detail', 'finance_opening_source', 'finance_opening_item', 'finance_opening_book', 'finance_setup_action', 'finance_account', 'finance_preparation', 'vendor'] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        }
        $this->cleanCustomerReportData();
    }
}
