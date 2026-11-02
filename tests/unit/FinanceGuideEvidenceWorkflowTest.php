<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceSetupLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

/** bootstrap 强制隔离 MySQL；仅清理本文件专属租户的夹具。 */
final class FinanceGuideEvidenceWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;

    private const EVIDENCE_TENANT = 882131;
    private const EVIDENCE_OTHER_TENANT = 882132;
    private const EVIDENCE_USER = 996131;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext(self::EVIDENCE_TENANT);
        $this->ensureCustomerReportTables();
        foreach (['20260907_000001_finance_preparation.sql', '20260907_000002_finance_opening.sql', '20260907_000003_finance_opening_details.sql', '20260907_000004_finance_business.sql', '20260907_000014_finance_cost.sql', '20261012_000002_finance_purchase_legacy_source.sql'] as $migration) {
            $this->runStatements($this->prepareMigration((string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration)));
        }
        Db::execute('CREATE TABLE IF NOT EXISTS la_vendor (id int unsigned AUTO_INCREMENT PRIMARY KEY, tenant_id int unsigned NOT NULL, supplier_name varchar(100) NOT NULL) ENGINE=InnoDB');
        $this->cleanEvidenceFixtures();
        Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext(self::EVIDENCE_TENANT);
        $this->cleanEvidenceFixtures();
        Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');
        $this->prepareCustomerReportRequestContext();
    }

    /** @dataProvider originalReviewStates */
    public function test_only_evidence_changes_and_all_conclusions_drafts_and_position_survive(string $state, string $choice): void
    {
        $this->start();
        $book = $this->book();
        $reviews = json_decode($book['reviews'], true, 512, JSON_THROW_ON_ERROR);
        $reviews['customer_refund'] = ['state' => $state, 'choice' => $choice, 'evidence' => '原说明',
            'confirmed_by' => ['id' => 910001, 'type' => 'user', 'name' => '原核对人'], 'confirmed_at' => 1790000000,
            'candidate_basis' => '原候选依据', 'declared_has' => true];
        $reviews['_guide']['current_card'] = 'inventory';
        $reviews['_guide']['updated_at'] = 1789999999;
        $reviews['_editors'] = [
            'tenant_admin:' . self::ADMIN_ID => ['customer_refund' => ['form' => ['amount' => '123.00'], 'updated_at' => 1789999997]],
            'user:910001' => ['customer_refund' => ['form' => ['amount' => '456.00'], 'updated_at' => 1789999998]],
        ];
        Db::name('finance_opening_book')->where('tenant_id', self::EVIDENCE_TENANT)->update(['reviews' => $this->json($reviews)]);
        $before = $this->book();
        $preparation = $this->preparation();
        $projection = FinanceSetupLogic::openingGuide();
        self::assertNotFalse($projection, FinanceSetupLogic::getError());

        $payload = $this->command((int)$before['version']) + ['category' => 'customer_refund', 'evidence' => '  新说明  ',
            'choice' => 'none', 'state' => 'none', 'clear_all' => true];
        $saved = FinanceSetupLogic::saveGuideEvidence($payload);
        self::assertNotFalse($saved, FinanceSetupLogic::getError());
        $reviews['customer_refund']['evidence'] = '新说明';
        $after = $this->book();
        self::assertSame($reviews, json_decode($after['reviews'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame((int)$before['version'] + 1, (int)$after['version']);
        self::assertSame($preparation, $this->preparation());
        self::assertSame($projection['current_card'], $saved['current_card']);
        self::assertSame($projection['progress'], $saved['progress']);
        foreach (['status', 'submitted_hash', 'confirmed_snapshot', 'confirmed_at', 'created_by', 'create_time'] as $field) {
            self::assertSame($before[$field], $after[$field], $field);
        }
        self::assertSame(self::ADMIN_ID, json_decode($after['last_modified_by'], true)['id']);
        $action = $this->evidenceActions()->find();
        self::assertSame((int)$before['version'], json_decode($action['before_data'], true)['version']);
        self::assertSame((int)$after['version'], json_decode($action['result_data'], true)['version']);
        self::assertSame(0, Db::name('finance_opening_item')->where('tenant_id', self::EVIDENCE_TENANT)->count());
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::EVIDENCE_TENANT)->count());
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::EVIDENCE_TENANT)->count());
    }

    public static function originalReviewStates(): array
    {
        return ['没有资料' => ['none', 'none'], '已核清' => ['complete', 'complete'], '已声明有' => ['unknown', 'has'], '待补充' => ['unresolved', 'unknown']];
    }

    public function test_first_note_does_not_declare_or_advance_and_explicit_empty_string_clears_it(): void
    {
        $guide = $this->start();
        $saved = FinanceSetupLogic::saveGuideEvidence($this->command((int)$guide['opening']['version']) + ['category' => 'customer_refund', 'evidence' => '尚待核实']);
        self::assertNotFalse($saved, FinanceSetupLogic::getError());
        self::assertSame(['state' => 'unknown', 'evidence' => '尚待核实'], $this->reviews()['customer_refund']);
        self::assertSame($guide['progress'], $saved['progress']);
        self::assertSame($guide['current_card'], $saved['current_card']);
        $cleared = FinanceSetupLogic::saveGuideEvidence($this->command((int)$saved['opening']['version']) + ['category' => 'customer_refund', 'evidence' => '']);
        self::assertNotFalse($cleared, FinanceSetupLogic::getError());
        self::assertSame(['state' => 'unknown', 'evidence' => ''], $this->reviews()['customer_refund']);
        self::assertSame(2, $this->evidenceActions()->count());
    }

    public function test_lost_response_replays_original_receipt_without_overwriting_newer_notes(): void
    {
        $guide = $this->start();
        $payload = $this->command((int)$guide['opening']['version']) + ['category' => 'customer_refund', 'evidence' => '第一次说明'];
        $first = FinanceSetupLogic::saveGuideEvidence($payload);
        self::assertNotFalse($first, FinanceSetupLogic::getError());
        $second = FinanceSetupLogic::saveGuideEvidence($this->command((int)$first['opening']['version']) + ['category' => 'customer_refund', 'evidence' => '后来更新说明']);
        self::assertNotFalse($second, FinanceSetupLogic::getError());
        self::assertSame($first, FinanceSetupLogic::saveGuideEvidence($payload));
        self::assertSame('后来更新说明', $this->reviews()['customer_refund']['evidence']);
        self::assertSame($second['opening']['version'], (int)$this->book()['version']);
        foreach ([['evidence' => '偷换内容'], ['category' => 'advance'], ['expected_version' => $second['opening']['version']]] as $change) {
            self::assertFalse(FinanceSetupLogic::saveGuideEvidence(array_replace($payload, $change)));
            self::assertStringContainsString('同一提交标识', FinanceSetupLogic::getError());
        }
        request()->userId = self::ADMIN_ID + 1;
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence($payload));
        self::assertStringContainsString('同一提交标识', FinanceSetupLogic::getError());
        self::assertSame(2, $this->evidenceActions()->count());
    }

    /** @dataProvider invalidEvidence */
    public function test_invalid_input_has_no_note_version_or_receipt_effect(array $change): void
    {
        $this->start();
        $before = $this->book();
        $payload = $this->command((int)$before['version']) + ['category' => 'customer_refund'];
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence(array_replace($payload, $change)));
        self::assertSame($before, $this->book());
        self::assertSame(0, $this->evidenceActions()->count());
    }

    public static function invalidEvidence(): array
    {
        return ['缺少说明' => [[]], '数组' => [['evidence' => []]], 'null' => [['evidence' => null]],
            '超长' => [['evidence' => str_repeat('字', 1001)]], '未知事项' => [['category' => '_guide', 'evidence' => 'x']],
            '数组事项' => [['category' => ['salary'], 'evidence' => 'x']]];
    }

    public function test_exact_length_boundary_is_accepted(): void
    {
        $this->start();
        $text = str_repeat('字', 1000);
        $saved = FinanceSetupLogic::saveGuideEvidence($this->command((int)$this->book()['version']) + ['category' => 'customer_refund', 'evidence' => $text]);
        self::assertNotFalse($saved, FinanceSetupLogic::getError());
        self::assertSame($text, $this->reviews()['customer_refund']['evidence']);
    }

    public function test_not_started_non_carryover_pending_and_active_books_are_not_mutated(): void
    {
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence($this->command(0) + ['category' => 'customer_refund', 'evidence' => 'x']));
        self::assertNull($this->book());
        self::assertSame(0, $this->evidenceActions()->count());
        $this->start();
        $original = $this->book();
        foreach (['pending', 'active'] as $status) {
            Db::name('finance_opening_book')->where('tenant_id', self::EVIDENCE_TENANT)->update(['status' => $status]);
            $before = $this->book();
            self::assertFalse(FinanceSetupLogic::saveGuideEvidence($this->command((int)$before['version']) + ['category' => 'customer_refund', 'evidence' => 'x']));
            self::assertSame($before, $this->book());
        }
        $reviews = json_decode($original['reviews'], true);
        $reviews['_guide']['mode'] = 'zero';
        Db::name('finance_opening_book')->where('tenant_id', self::EVIDENCE_TENANT)->update(['status' => 'draft', 'reviews' => $this->json($reviews)]);
        $before = $this->book();
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence($this->command((int)$before['version']) + ['category' => 'customer_refund', 'evidence' => 'x']));
        self::assertSame($before, $this->book());
        self::assertSame(0, $this->evidenceActions()->count());
    }

    public function test_stale_version_and_store_switch_reject_without_creating_foreign_book(): void
    {
        $guide = $this->start();
        $before = $this->book();
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence($this->command(0) + ['category' => 'customer_refund', 'evidence' => 'x']));
        self::assertStringContainsString('已更新', FinanceSetupLogic::getError());
        $payload = $this->command((int)$guide['opening']['version']) + ['category' => 'customer_refund', 'evidence' => 'x'];
        $this->prepareCustomerReportRequestContext(self::EVIDENCE_OTHER_TENANT);
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence($payload));
        self::assertStringContainsString('门店已变化', FinanceSetupLogic::getError());
        self::assertSame(0, Db::name('finance_opening_book')->where('tenant_id', self::EVIDENCE_OTHER_TENANT)->count());
        self::assertSame(0, Db::name('finance_setup_action')->where('tenant_id', self::EVIDENCE_OTHER_TENANT)->count());
        self::assertSame($before, $this->book());
    }

    public function test_prepare_and_salary_permissions_are_checked_before_new_writes_and_receipt_replays(): void
    {
        $this->start();
        $employee = $this->employee(['finance.opening.prepare']);
        $this->asEmployee();
        $payload = $this->command((int)$this->book()['version']) + ['category' => 'salary', 'evidence' => '工资私密说明'];
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence($payload));
        self::assertStringContainsString('工资', FinanceSetupLogic::getError());
        self::assertSame(0, $this->evidenceActions()->count());
        $this->grant($employee, 'finance.salary.view');
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence($payload), '仅工资查看权限不能保存工资说明');
        $this->grant($employee, 'finance.opening.salary.prepare');
        self::assertNotFalse(FinanceSetupLogic::saveGuideEvidence($payload), FinanceSetupLogic::getError());
        $before = $this->book();
        Db::name('employee_permission')->where('employee_id', $employee)->where('tenant_id', self::EVIDENCE_TENANT)->where('permission_key', 'finance.opening.salary.prepare')->delete();
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence($payload), '工资准备权限撤销后不能重放工资原回执');
        $other = $this->command((int)$before['version']) + ['category' => 'customer_refund', 'evidence' => '普通说明'];
        self::assertNotFalse(FinanceSetupLogic::saveGuideEvidence($other), FinanceSetupLogic::getError());
        $before = $this->book();
        Db::name('employee_permission')->where('employee_id', $employee)->where('tenant_id', self::EVIDENCE_TENANT)->where('permission_key', 'finance.opening.prepare')->delete();
        self::assertFalse(FinanceSetupLogic::saveGuideEvidence($other), '一般准备权限撤销后不能重放普通说明回执');
        self::assertSame($before, $this->book());
        self::assertSame(2, $this->evidenceActions()->count());
    }

    public function test_non_salary_replay_filters_salary_data_under_current_view_permissions(): void
    {
        $this->start();
        $employee = $this->employee(['finance.opening.prepare', 'finance.salary.view', 'finance.opening.salary.prepare']);
        $this->asEmployee();
        $saved = FinanceSetupLogic::saveGuideEvidence($this->command((int)$this->book()['version']) + ['category' => 'salary', 'evidence' => 'SALARY-SECRET-EVIDENCE']);
        self::assertNotFalse($saved, FinanceSetupLogic::getError());
        $payload = $this->command((int)$this->book()['version']) + ['category' => 'customer_refund', 'evidence' => '普通说明'];
        $full = FinanceSetupLogic::saveGuideEvidence($payload);
        self::assertNotFalse($full, FinanceSetupLogic::getError());
        self::assertStringContainsString('SALARY-SECRET-EVIDENCE', $this->json($full));
        Db::name('employee_permission')->where('employee_id', $employee)->where('tenant_id', self::EVIDENCE_TENANT)->where('permission_key', 'finance.salary.view')->delete();
        $filtered = FinanceSetupLogic::saveGuideEvidence($payload);
        self::assertNotFalse($filtered, FinanceSetupLogic::getError());
        self::assertStringNotContainsString('SALARY-SECRET-EVIDENCE', $this->json($filtered));
        self::assertSame('restricted', array_column($filtered['cards'], null, 'key')['salary']['state']);
        self::assertSame($full['opening']['version'], $filtered['opening']['version']);
        self::assertSame(2, $this->evidenceActions()->count());
    }

    public function test_receipt_insert_failure_rolls_back_note_and_version_then_original_payload_can_retry(): void
    {
        $this->start();
        $before = $this->book();
        $payload = $this->command((int)$before['version']) + ['category' => 'customer_refund', 'evidence' => '事务内说明'];
        // 临时约束仅拒绝本专属租户的本次操作，模拟写说明后、提交成功回执前的数据库失败。
        // 不建立需 SUPER/binlog 配置变更的触发器，不调整测试库安全设置。
        $constraint = 'chk_evidence_test_receipt_failure';
        if (Db::query('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?', ['la_finance_setup_action', $constraint])) {
            Db::execute('ALTER TABLE la_finance_setup_action DROP CHECK ' . $constraint);
        }
        Db::execute("ALTER TABLE la_finance_setup_action ADD CONSTRAINT " . $constraint . " CHECK (NOT (tenant_id = " . self::EVIDENCE_TENANT . " AND action_type = 'opening.guide_evidence'))");
        try {
            try {
                FinanceSetupLogic::saveGuideEvidence($payload);
                self::fail('预期回执插入失败');
            } catch (\think\db\exception\PDOException $error) {
                self::assertStringContainsString($constraint, $error->getMessage());
            }
            self::assertSame($before, $this->book());
            self::assertSame(0, $this->evidenceActions()->count());
        } finally {
            Db::execute('ALTER TABLE la_finance_setup_action DROP CHECK ' . $constraint);
        }
        self::assertNotFalse(FinanceSetupLogic::saveGuideEvidence($payload), FinanceSetupLogic::getError());
        self::assertSame((int)$before['version'] + 1, (int)$this->book()['version']);
        self::assertSame(1, $this->evidenceActions()->count());
    }

    private function start(): array
    {
        $result = FinanceSetupLogic::startOpening($this->command(0) + ['mode' => 'carryover', 'activation_date' => '2026-09-01']);
        self::assertNotFalse($result, FinanceSetupLogic::getError());
        return $result;
    }

    private function command(int $version): array
    {
        return ['expected_tenant_id' => self::EVIDENCE_TENANT, 'expected_version' => $version,
            'idempotency_key' => 'finance-evidence-test-' . ++$this->sequence];
    }

    private function employee(array $permissions): int
    {
        $id = (int)Db::name('employee')->insertGetId(['tenant_id' => self::EVIDENCE_TENANT, 'name' => '事项说明测试员',
            'mobile' => '13800006131', 'bind_user_id' => self::EVIDENCE_USER, 'is_enabled' => 1, 'create_time' => time(), 'update_time' => time()]);
        foreach ($permissions as $permission) { $this->grant($id, $permission); }
        return $id;
    }

    private function grant(int $employee, string $permission): void
    {
        Db::name('employee_permission')->insert(['tenant_id' => self::EVIDENCE_TENANT, 'employee_id' => $employee,
            'permission_key' => $permission, 'create_time' => time()]);
    }

    private function asEmployee(): void
    {
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::EVIDENCE_TENANT];
        request()->jxcFromUserToken = true;
        request()->userId = self::EVIDENCE_USER;
        request()->adminId = 0;
    }

    private function book(): ?array { return Db::name('finance_opening_book')->where('tenant_id', self::EVIDENCE_TENANT)->find(); }
    private function preparation(): ?array { return Db::name('finance_preparation')->where('tenant_id', self::EVIDENCE_TENANT)->find(); }
    private function reviews(): array { return json_decode($this->book()['reviews'], true, 512, JSON_THROW_ON_ERROR); }
    private function evidenceActions() { return Db::name('finance_setup_action')->where('tenant_id', self::EVIDENCE_TENANT)->where('action_type', 'opening.guide_evidence'); }
    private function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }

    private function cleanEvidenceFixtures(): void
    {
        $tables = Db::query("SELECT TABLE_NAME AS table_name FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='tenant_id'");
        foreach ($tables as $table) {
            Db::table($table['table_name'])->whereIn('tenant_id', [self::EVIDENCE_TENANT, self::EVIDENCE_OTHER_TENANT])->delete();
        }
    }
}
