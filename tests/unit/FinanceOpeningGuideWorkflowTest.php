<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceOpeningGuide;
use app\api\jxc\logic\FinanceSetupLogic;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

/** 所有写入限定在 bootstrap 验证过的 beimi_test_* 库及专用测试租户。 */
final class FinanceOpeningGuideWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        foreach (['20260907_000001_finance_preparation.sql', '20260907_000002_finance_opening.sql', '20260907_000003_finance_opening_details.sql', '20260907_000004_finance_business.sql', '20260907_000014_finance_cost.sql', '20261012_000002_finance_purchase_legacy_source.sql'] as $migration) {
            $this->runStatements($this->prepareMigration((string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration)));
        }
        Db::execute('CREATE TABLE IF NOT EXISTS la_vendor (id int unsigned AUTO_INCREMENT PRIMARY KEY, tenant_id int unsigned NOT NULL, supplier_name varchar(100) NOT NULL) ENGINE=InnoDB');
        $this->clean();
        Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->clean();
        Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');
    }

    public function test_zero_start_is_atomic_idempotent_and_does_not_fabricate_manual_reviews_or_cash(): void
    {
        $this->createCustomer('空白店基础客户');
        $this->createCustomerReportWarehouse('空白店基础仓库');
        $this->createCustomerReportGoods('基础商品', 'GUIDE-ZERO');
        self::assertNotFalse(FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '尚未发生收付的现金', 'account_type' => 'cash']));
        $guide = FinanceSetupLogic::openingGuide();
        self::assertSame('choose_mode', $guide['start_status']);
        $params = $this->command(0) + ['mode' => 'zero'];
        $active = FinanceSetupLogic::startOpening($params);
        self::assertNotFalse($active, FinanceSetupLogic::getError());
        self::assertSame('active', $active['start_status']);
        self::assertSame('zero', $active['mode']);
        self::assertSame(date('Y-m-d'), $active['opening']['activation_date']);
        self::assertSame([], $active['cards']);
        self::assertSame($active, FinanceSetupLogic::startOpening($params));
        $reviews = json_decode((string)Db::name('finance_opening_book')->where('tenant_id', self::TENANT_ID)->value('reviews'), true);
        self::assertSame(['_guide'], array_keys($reviews));
        self::assertSame('system_zero_initialization', $reviews['_guide']['origin']);
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertFalse(FinanceSetupLogic::startOpening($this->command(1) + ['mode' => 'zero']));
        self::assertSame('active', FinanceSetupLogic::openingGuide()['start_status']);
    }

    public function test_zero_start_rejects_business_drafts_and_does_not_erase_them(): void
    {
        $id = (int)Db::name('sales_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'order_sn' => 'GUIDE-EXISTING']);
        self::assertSame('legacy_transition', FinanceSetupLogic::openingGuide()['start_status']);
        self::assertFalse(FinanceSetupLogic::startOpening($this->command(0) + ['mode' => 'zero']));
        self::assertStringContainsString('不会清空', FinanceSetupLogic::getError());
        self::assertSame(1, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->where('id', $id)->count());
        self::assertNull(Db::name('finance_opening_book')->where('tenant_id', self::TENANT_ID)->find());
    }

    public function test_existing_version_zero_book_can_start_without_duplicate_primary_key(): void
    {
        Db::name('finance_opening_book')->insert(['tenant_id' => self::TENANT_ID, 'status' => 'draft', 'version' => 0, 'reviews' => '{}',
            'submitted_hash' => '', 'confirmed_snapshot' => '{}', 'created_by' => 'null', 'last_modified_by' => 'null', 'create_time' => 0, 'update_time' => 0]);
        $active = FinanceSetupLogic::startOpening($this->command(0) + ['mode' => 'zero']);
        self::assertNotFalse($active, FinanceSetupLogic::getError());
        self::assertSame('active', $active['start_status']);
        self::assertSame(1, Db::name('finance_opening_book')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_zero_start_rejects_a_legacy_balance_without_a_business_document(): void
    {
        $customer = $this->createCustomer('旧欠款客户');
        Db::name('customer')->where('id', $customer)->update(['order_receivable' => '125.30']);
        self::assertFalse(FinanceSetupLogic::startOpening($this->command(0) + ['mode' => 'zero']));
        self::assertSame('125.30', Db::name('customer')->where('id', $customer)->value('order_receivable'));
    }

    public function test_legacy_gate_uses_real_old_facts_and_does_not_expose_them_to_unprivileged_employee(): void
    {
        $this->start();
        $customer = $this->createCustomer('门店原欠款客户');
        $this->item('receivable', $customer, '91.00');
        Db::name('finance_document')->insert(['tenant_id' => self::TENANT_ID, 'type' => 'receipt', 'status' => 'draft',
            'payload' => '{}', 'confirmed_result' => '{}', 'confirmed_by' => '{}', 'created_by' => '{}', 'last_modified_by' => '{}', 'create_time' => time(), 'update_time' => time()]);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        $guide = FinanceSetupLogic::openingGuide();
        self::assertSame('waiting_owner', $guide['start_status']);
        self::assertFalse($guide['legacy_operating_allowed'], '财务草稿及期初明细不能打开旧经营旁路');
        self::assertSame([], $guide['cards']);
        Db::name('customer')->where('id', $customer)->update(['order_receivable' => '65.40']);
        $legacy = FinanceSetupLogic::openingGuide();
        self::assertTrue($legacy['legacy_operating_allowed']);
        self::assertArrayNotHasKey('items', $legacy['opening']);
        self::assertStringNotContainsString('65.40', json_encode($legacy, JSON_UNESCAPED_UNICODE));
        Db::name('customer')->where('id', $customer)->update(['order_receivable' => '0.00']);
        $order = (int)Db::name('sales_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'order_sn' => 'GUIDE-REAL-LEGACY', 'status' => 0]);
        self::assertFalse(FinanceSetupLogic::openingGuide()['legacy_operating_allowed'], '未确认旧单不能代替经营事实');
        Db::name('sales_order')->where('id', $order)->update(['status' => 1]);
        self::assertTrue(FinanceSetupLogic::openingGuide()['legacy_operating_allowed']);
    }

    public function test_start_respects_publication_permission_store_version_and_retry_fingerprint(): void
    {
        Config::set(['activation_mode' => 'allowlist', 'activation_tenant_ids' => []], 'finance');
        self::assertFalse(FinanceSetupLogic::startOpening($this->command(0) + ['mode' => 'zero']));
        Config::set(['activation_mode' => 'all'], 'finance');
        self::assertFalse(FinanceSetupLogic::startOpening(array_merge($this->command(0), ['expected_tenant_id' => self::OTHER_TENANT_ID, 'mode' => 'zero'])));
        $params = $this->command(0) + ['mode' => 'carryover', 'activation_date' => '2026-09-01'];
        self::assertNotFalse(FinanceSetupLogic::startOpening($params));
        self::assertFalse(FinanceSetupLogic::startOpening(array_merge($params, ['activation_date' => '2026-09-02'])));
        self::assertStringContainsString('同一提交标识', FinanceSetupLogic::getError());
        self::assertFalse(FinanceSetupLogic::saveGuideCard($this->command(0) + ['category' => 'account', 'choice' => 'none']));
        self::assertStringContainsString('已更新', FinanceSetupLogic::getError());
    }

    public function test_system_generates_all_21_cards_and_restores_unknown_after_other_cards(): void
    {
        $guide = $this->start();
        self::assertSame(array_keys(FinanceOpeningGuide::CARDS), array_column($guide['cards'], 'key'));
        self::assertSame('account', $guide['current_card']);
        $this->card('account', 'none');
        $unknown = $this->card('inventory', 'unknown', '盘存成本资料正在查找');
        self::assertSame('receivable', $unknown['current_card']);
        foreach (array_keys(FinanceOpeningGuide::CARDS) as $category) { if (!in_array($category, ['account', 'inventory'], true)) { $this->card($category, 'none'); } }
        $restored = FinanceSetupLogic::openingGuide();
        self::assertSame('inventory', $restored['current_card']);
        self::assertSame(20, $restored['progress']['completed']);
        self::assertFalse(FinanceSetupLogic::openingAction('submit', $this->command((int)$restored['opening']['version'])));
        self::assertStringContainsString('尚未核清', FinanceSetupLogic::getError());
    }

    public function test_no_choice_cannot_hide_existing_candidates_and_complete_requires_coverage(): void
    {
        $customer = $this->createCustomer('已有候选欠款');
        Db::name('customer')->where('id', $customer)->update(['order_receivable' => '60.00']);
        $guide = $this->start();
        $candidateCard = array_column($guide['cards'], null, 'key')['receivable'];
        self::assertSame('pending_review', $candidateCard['state']);
        self::assertSame($customer, $candidateCard['candidates'][0]['subject_id']);
        self::assertFalse(FinanceSetupLogic::saveGuideCard($this->command((int)$guide['opening']['version']) + ['category' => 'receivable', 'choice' => 'none']));
        $has = $this->card('receivable', 'has');
        self::assertSame('pending_input', array_column($has['cards'], null, 'key')['receivable']['state']);
        self::assertFalse(FinanceSetupLogic::saveGuideCard($this->command((int)$has['opening']['version']) + ['category' => 'receivable', 'choice' => 'complete']));
        $this->item('receivable', $customer, '60.00');
        self::assertSame('complete', array_column($this->card('receivable', 'complete')['cards'], null, 'key')['receivable']['state']);
    }

    public function test_complete_carryover_auto_preparation_can_confirm_without_21_repeated_evidence_inputs(): void
    {
        $this->start();
        foreach (array_keys(FinanceOpeningGuide::CARDS) as $category) { $this->card($category, 'none'); }
        $guide = FinanceSetupLogic::openingGuide();
        self::assertSame(21, $guide['progress']['completed']);
        self::assertSame([], $guide['opening']['blockers']);
        $pending = FinanceSetupLogic::openingAction('submit', $this->command((int)$guide['opening']['version']));
        self::assertNotFalse($pending, FinanceSetupLogic::getError());
        $active = FinanceSetupLogic::openingAction('confirm', $this->command((int)$pending['version']));
        self::assertNotFalse($active, FinanceSetupLogic::getError());
        self::assertSame('active', FinanceSetupLogic::openingGuide()['start_status']);
    }

    public function test_business_change_after_submission_blocks_confirmation_and_preserves_draft(): void
    {
        $this->start();
        foreach (array_keys(FinanceOpeningGuide::CARDS) as $category) { $this->card($category, 'none'); }
        $pending = FinanceSetupLogic::openingAction('submit', $this->command((int)FinanceSetupLogic::openingGuide()['opening']['version']));
        self::assertNotFalse($pending);
        Db::name('sales_order')->insert(['tenant_id' => self::TENANT_ID, 'order_sn' => 'GUIDE-PREPARE-CHANGED']);
        self::assertFalse(FinanceSetupLogic::openingAction('confirm', $this->command((int)$pending['version'])));
        self::assertStringContainsString('经营资料已变化', FinanceSetupLogic::getError());
        self::assertSame('pending', FinanceSetupLogic::opening()['status']);
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_change_of_cutoff_preserves_items_and_requires_recheck(): void
    {
        $customer = $this->createCustomer('改日期旧欠款');
        $this->start();
        $this->item('receivable', $customer, '30.00');
        $this->card('receivable', 'complete');
        $guide = FinanceSetupLogic::openingGuide();
        $changed = FinanceSetupLogic::startOpening($this->command((int)$guide['opening']['version']) + ['mode' => 'carryover', 'activation_date' => '2026-09-02']);
        self::assertNotFalse($changed, FinanceSetupLogic::getError());
        self::assertCount(1, $changed['opening']['items']);
        self::assertSame('pending_review', array_column($changed['cards'], null, 'key')['receivable']['state']);
    }

    public function test_employee_without_prepare_permission_sees_only_waiting_summary_and_cannot_start(): void
    {
        $customer = $this->createCustomer('权限私密欠款');
        $this->start();
        $this->item('receivable', $customer, '91.00');
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        $guide = FinanceSetupLogic::openingGuide();
        self::assertSame('waiting_owner', $guide['start_status']);
        self::assertSame([], $guide['cards']);
        self::assertArrayNotHasKey('items', $guide['opening']);
        self::assertNull($guide['preparation']);
        self::assertFalse(FinanceSetupLogic::startOpening($this->command((int)$guide['opening']['version']) + ['mode' => 'zero']));
        self::assertFalse(FinanceSetupLogic::saveGuideCard($this->command((int)$guide['opening']['version']) + ['category' => 'account', 'choice' => 'none']));
    }

    public function test_fund_openings_use_real_counterparty_without_adding_cash_or_fake_vendor(): void
    {
        $this->start();
        $vendorCount = Db::name('vendor')->where('tenant_id', self::TENANT_ID)->count();
        $this->item('capital', 0, '1000.00', ['counterparty' => '投入人甲', 'origin_reference' => '原投入和已取回核对记录']);
        $this->item('loan', 0, '500.00', ['counterparty' => '出借人乙', 'origin_reference' => '原借款和已还本金记录']);
        $guide = FinanceSetupLogic::openingGuide();
        self::assertSame('投入人甲', $guide['opening']['items'][0]['subject_name']);
        self::assertSame('出借人乙', $guide['opening']['items'][1]['subject_name']);
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame($vendorCount, Db::name('vendor')->where('tenant_id', self::TENANT_ID)->count());
        $this->card('capital', 'complete');
        $this->card('loan', 'complete');
    }

    public function test_prepare_employee_cannot_read_salary_candidates_or_mark_salary_as_none(): void
    {
        $employee = WorkforceLogic::saveEmployee(['name' => '期初协助员工', 'mobile' => '13800009941', 'bind_user_id' => 996941,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.opening.prepare']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        $this->start();
        $this->item('salary', (int)$employee['id'], '5678.91', ['benefit_month' => '2026-08', 'origin_reference' => '私密工资资料5678']);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->jxcFromUserToken = true; request()->userId = 996941; request()->adminId = 0;
        $guide = FinanceSetupLogic::openingGuide();
        self::assertNotFalse($guide, FinanceSetupLogic::getError());
        $card = array_column($guide['cards'], null, 'key')['salary'];
        self::assertSame('restricted', $card['state']);
        self::assertSame([], $card['candidates']);
        self::assertSame([], $card['blocker_details']);
        self::assertStringNotContainsString('5678.91', json_encode($guide, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('私密工资资料5678', json_encode($guide, JSON_UNESCAPED_UNICODE));
        self::assertFalse(FinanceSetupLogic::saveGuideCard($this->command((int)$guide['opening']['version']) + ['category' => 'salary', 'choice' => 'none']));
        self::assertStringContainsString('工资', FinanceSetupLogic::getError());
    }

    private function start(): array
    {
        $guide = FinanceSetupLogic::startOpening($this->command(0) + ['mode' => 'carryover', 'activation_date' => '2026-09-01']);
        self::assertNotFalse($guide, FinanceSetupLogic::getError());
        return $guide;
    }

    private function card(string $category, string $choice, string $evidence = ''): array
    {
        $guide = FinanceSetupLogic::openingGuide();
        $result = FinanceSetupLogic::saveGuideCard($this->command((int)$guide['opening']['version']) + compact('category', 'choice', 'evidence'));
        self::assertNotFalse($result, FinanceSetupLogic::getError());
        return $result;
    }

    private function item(string $category, int $subject, string $amount, array $details = []): void
    {
        $guide = FinanceSetupLogic::openingGuide();
        $result = FinanceSetupLogic::openingAction('item', $this->command((int)$guide['opening']['version']) + [
            'category' => $category, 'subject_id' => $subject, 'amount' => $amount, 'details' => $details,
            'historical_date' => null, 'due_date' => null, 'source_mode' => 'detail', 'source_reference' => 'GUIDE-' . $category,
            'evidence' => '已核实统一启用截点的余额和来源',
        ]);
        self::assertNotFalse($result, FinanceSetupLogic::getError());
    }

    private function command(int $version): array
    {
        return ['expected_tenant_id' => self::TENANT_ID, 'expected_version' => $version, 'idempotency_key' => 'finance-guide-test-' . ++$this->sequence];
    }

    private function clean(): void
    {
        $columns = Db::query("SELECT TABLE_NAME AS table_name FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='tenant_id'");
        foreach ($columns as $column) {
            $table = $column['table_name'];
            if (str_starts_with($table, 'la_finance_') || in_array($table, ['la_vendor', 'la_sales_order', 'la_purchase_arrival_detail', 'la_purchase_arrival', 'la_goods_batch'], true)) { Db::table($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        }
        $this->cleanCustomerReportData();
    }
}
