<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceBusinessLogic;
use app\api\jxc\logic\FinanceLedger;
use app\api\jxc\logic\FinanceSetupLogic;
use app\api\jxc\logic\WorkforceLogic;
use app\api\jxc\logic\SalesSettlementLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class FinanceBusinessWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;
    private int $sequence = 0;
    private int $accountId;
    private int $customerId;
    private string $receivable;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext(); $this->ensureCustomerReportTables();
        foreach (['20260907_000001_finance_preparation.sql', '20260907_000002_finance_opening.sql', '20260907_000003_finance_opening_details.sql', '20260907_000004_finance_business.sql', '20260907_000005_finance_sales.sql', '20260907_000006_finance_receivables.sql', '20260907_000007_finance_advance_revisions.sql'] as $file) {
            $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $file)));
        }
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000008_finance_statements.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000009_finance_overdue.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000011_finance_sales_precision.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000012_finance_sales_coverage.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000013_finance_sales_output.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000014_finance_cost.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000015_finance_purchase_arrival.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000016_finance_purchase_settlement.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000017_finance_purchase_rules.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000018_finance_purchase_cost_change.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000019_finance_purchase_cost_revision.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000020_finance_supplier_statements.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000021_finance_purchase_return.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000022_finance_purchase_return_resolution.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000023_finance_purchase_difference_review.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000024_finance_purchase_arrival_loss.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000025_finance_inventory_loss.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000026_finance_stock_transfer_pair.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000027_finance_expense.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000028_finance_expense_revision.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000029_finance_deferred_amortization.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000030_finance_expense_estimate_resolution.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000031_finance_recurring_expense.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000032_finance_recurring_month_revision.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000033_finance_employee_expense.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000034_finance_employee_expense_revision.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000035_finance_salary_result.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000036_finance_salary_revision.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000037_finance_equipment.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000038_finance_equipment_refund.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000039_finance_opening_equipment_revision.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000040_finance_account_transfer.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000041_finance_account_reconciliation.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000042_finance_transit_reconciliation.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260909_000043_finance_report_export.sql')));
        $this->clean();
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext(); $this->clean();
        Config::set(['activation_tenant_ids' => []], 'finance');
    }

    public static function deferredClosureCases(): array { return [[false], [true]]; }

    public function test_monthly_reports_keep_closed_snapshots_and_show_later_correction_without_new_external_receipt(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $payload = array_replace($this->receipt('100', '100'), ['actual_date' => $month . '-10']);
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]); self::assertNotFalse($original, FinanceBusinessLogic::getError());
        $reports = [];
        foreach (['cash', 'customer'] as $kind) { $reports[$kind] = FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => $kind]); self::assertNotFalse($reports[$kind], FinanceBusinessLogic::getError()); $reports[$kind]['closing_status'] = 'closed'; $reports[$kind]['stage'] = false; }
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => json_encode(['reports' => $reports]), 'closed_by' => '{}', 'closed_at' => time()]);
        $changed = FinanceBusinessLogic::action('correct', $this->command($original['version']) + ['id' => $original['id'], 'correction_reason' => '原到账为120，20明确留预收', 'payload' => array_replace($payload, ['amount' => '120', 'advance_amount' => '20'])]); self::assertNotFalse($changed, FinanceBusinessLogic::getError());
        foreach ($reports as $kind => $expected) { self::assertSame($expected, FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => $kind])); }
        $cash = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'cash']); self::assertNotFalse($cash, FinanceBusinessLogic::getError()); self::assertSame('0.00', $cash['data']['summary']['external_in']); self::assertSame('20.00', $cash['data']['summary']['cash_adjustment']); self::assertSame('5120.00', $cash['data']['summary']['closing_accounts']);
        $customer = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'customer']); self::assertSame('20.00', array_column($customer['data']['categories'], 'closing', 'category')['advance']);
    }

    public function test_monthly_report_permissions_are_independent_and_salary_details_require_an_additional_grant(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $employee = WorkforceLogic::saveEmployee(['name' => '月报工资私密员工', 'mobile' => '13800009951', 'bind_user_id' => 996951, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => []]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => ['category_id' => 0, 'expected_category_version' => 0, 'parent' => 'personnel', 'name' => '月报工资', 'is_enabled' => 1]]); self::assertNotFalse($category, FinanceBusinessLogic::getError());
        $salary = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_expense', 'payload' => ['subject_id' => $employee['id'], 'benefit_month' => $month, 'actual_date' => $month . '-20', 'amount' => '100', 'source_reference' => 'REPORT-SALARY-51', 'reason' => '工资私密依据', 'confirmation_basis' => '工资私密依据', 'salary_verified' => 1, 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '工资私密依据', 'lines' => [['category_id' => $category['confirmed_result']['category']['id'], 'expected_category_version' => 1, 'amount' => '100', 'reason' => '工资私密依据']]]]); self::assertNotFalse($salary, FinanceBusinessLogic::getError());
        foreach (['finance.report.profit.view', 'finance.report.expense.view'] as $permission) { Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => $permission]); }
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996951; request()->adminId = 0;
        $profit = FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'profit']); self::assertNotFalse($profit, FinanceBusinessLogic::getError()); self::assertSame('100.00', $profit['data']['summary']['expense']); self::assertSame([], $profit['data']['entries']);
        self::assertFalse(FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'cash']));
        $expense = FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'expense']); self::assertNotFalse($expense, FinanceBusinessLogic::getError()); self::assertSame('100.00', $expense['data']['summary']['amount']); self::assertSame([], $expense['data']['entries']); self::assertSame([], $expense['data']['obligations']['sources']); self::assertSame([], $expense['data']['obligations']['subjects']);
        self::assertStringNotContainsString('工资私密依据', json_encode($expense, JSON_UNESCAPED_UNICODE));
        $yearly = FinanceBusinessLogic::monthlyReport(['period_type' => 'year', 'period' => substr($month, 0, 4), 'report' => 'expense']); self::assertNotFalse($yearly, FinanceBusinessLogic::getError());
        self::assertSame('100.00', $yearly['data']['summary']['amount']); self::assertStringNotContainsString('工资私密依据', json_encode($yearly, JSON_UNESCAPED_UNICODE)); self::assertFalse($yearly['followups_visible']);
        self::assertFalse(FinanceBusinessLogic::monthlyReport(['period_type' => 'year', 'period' => substr($month, 0, 4), 'report' => 'cash']));
        Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => 'finance.salary.view']);
        $visible = FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'expense']); self::assertNotFalse($visible, FinanceBusinessLogic::getError()); self::assertCount(1, $visible['data']['entries']);
    }

    public function test_report_export_freezes_requested_version_retries_once_and_audits_private_download(): void
    {
        $this->activate(); $month = date('Y-m');
        $query = $this->command(0) + ['report' => 'cash', 'period_type' => 'month', 'period' => $month];
        $export = FinanceBusinessLogic::reportExport('prepare', $query); self::assertNotFalse($export, FinanceBusinessLogic::getError());
        self::assertSame($export, FinanceBusinessLogic::reportExport('prepare', $query)); self::assertSame(1, Db::name('finance_report_export')->where('tenant_id', self::TENANT_ID)->count());
        $snapshot = json_decode(Db::name('finance_report_export')->where('id', $export['id'])->value('snapshot'), true); self::assertSame('5000.00', $snapshot['data']['summary']['closing_accounts']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]), FinanceBusinessLogic::getError());
        $content = FinanceBusinessLogic::reportExport('content', ['id' => $export['id']]); self::assertNotFalse($content, FinanceBusinessLogic::getError());
        self::assertSame($snapshot, json_decode(Db::name('finance_report_export')->where('id', $export['id'])->value('snapshot'), true));
        self::assertSame('PK', substr(base64_decode($content['base64']), 0, 2)); self::assertSame(1, Db::name('finance_report_export_access')->where('tenant_id', self::TENANT_ID)->where('export_id', $export['id'])->count());
        $file = tempnam(sys_get_temp_dir(), 'finance-xlsx-');
        try {
            file_put_contents($file, base64_decode($content['base64'])); $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            self::assertStringContainsString('5000.00', json_encode($book->getSheetByName('报表汇总')->toArray(), JSON_UNESCAPED_UNICODE));
            self::assertGreaterThanOrEqual(3, $book->getSheetCount()); $book->disconnectWorksheets();
        } finally { unlink($file); }
        $new = FinanceBusinessLogic::reportExport('prepare', array_replace($query, ['idempotency_key' => $this->command(0)['idempotency_key']])); self::assertNotFalse($new); self::assertNotSame($export['snapshot_hash'], $new['snapshot_hash']);
        request()->adminInfo = ['root' => 1, 'tenant_id' => self::OTHER_TENANT_ID];
        self::assertFalse(FinanceBusinessLogic::reportExport('content', ['id' => $export['id']]));
    }

    public function test_report_export_rechecks_view_export_salary_and_actor_permissions(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '报表导出员工', 'mobile' => '13800009952', 'bind_user_id' => 996952, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => []]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $grant = static function (string $key) use ($employee): void { Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => $key]); };
        $revoke = static function (string $key) use ($employee): void { Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', $key)->delete(); };
        $grant('finance.report.expense.view');
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996952; request()->adminId = 0;
        $query = $this->command(0) + ['report' => 'expense', 'period_type' => 'month', 'period' => date('Y-m')];
        self::assertFalse(FinanceBusinessLogic::reportExport('prepare', $query));
        $grant('finance.report.expense.export'); $grant('finance.salary.view');
        $export = FinanceBusinessLogic::reportExport('prepare', $query); self::assertNotFalse($export, FinanceBusinessLogic::getError());
        foreach (['finance.report.expense.export', 'finance.report.expense.view', 'finance.salary.view'] as $permission) {
            $revoke($permission); self::assertFalse(FinanceBusinessLogic::reportExport('content', ['id' => $export['id']])); self::assertFalse(FinanceBusinessLogic::reportExport('prepare', $query)); $grant($permission);
        }
        self::assertNotFalse(FinanceBusinessLogic::reportExport('content', ['id' => $export['id']]), FinanceBusinessLogic::getError());
        $this->prepareCustomerReportRequestContext();
        self::assertFalse(FinanceBusinessLogic::reportExport('content', ['id' => $export['id']])); self::assertStringContainsString('操作人', FinanceBusinessLogic::getError());
    }

    public function test_report_export_preserves_long_frozen_evidence_and_never_executes_formula_text(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $report = FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'cash']); self::assertNotFalse($report);
        $text = '=SUM(1,2)' . str_repeat('原始依据', 9000) . '证据末尾';
        $report['data']['entries'][] = ['reason' => $text, 'amount' => '0.00']; $report['closing_status'] = 'closed'; $report['stage'] = false;
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => json_encode(['reports' => ['cash' => $report], 'unresolved' => []]), 'closed_by' => '{}', 'closed_at' => time()]);
        $export = FinanceBusinessLogic::reportExport('prepare', $this->command(0) + ['report' => 'cash', 'period' => $month]); self::assertNotFalse($export, FinanceBusinessLogic::getError());
        $content = FinanceBusinessLogic::reportExport('content', ['id' => $export['id']]); self::assertNotFalse($content, FinanceBusinessLogic::getError());
        $file = tempnam(sys_get_temp_dir(), 'finance-xlsx-');
        try {
            file_put_contents($file, base64_decode($content['base64'])); $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            $sheet = $book->getSheetByName('长文本续页'); self::assertNotNull($sheet, '超过 Excel 单元格容量的依据必须完整续写');
            $parts = []; foreach (array_slice($sheet->toArray(), 1) as $row) { if ($row[0] === '金额流水') { $parts[] = $row[3]; } }
            self::assertSame(hash('sha256', $text), hash('sha256', implode('', $parts)));
            foreach ($book->getAllSheets() as $part) { foreach ($part->getCoordinates() as $coordinate) { self::assertNotSame('f', $part->getCell($coordinate)->getDataType()); } }
            $book->disconnectWorksheets();
        } finally { unlink($file); }
    }

    public function test_year_reports_sum_flows_keep_last_balances_and_preserve_frozen_months(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $receipt = $this->receipt('100', '100'); $receipt['actual_date'] = $month . '-02';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]), FinanceBusinessLogic::getError());
        $preview = FinanceBusinessLogic::periodAction('preview', ['month' => $month]);
        $closed = FinanceBusinessLogic::periodAction('close', $this->command(0) + ['month' => $month, 'mode' => 'estimated', 'acknowledge_unresolved' => 1, 'expected_fingerprint' => $preview['fingerprint'], 'reason' => '年度报表保留原月核验状态']); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        $receipt['actual_date'] = date('Y-m-d');
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]), FinanceBusinessLogic::getError());
        $sameYear = substr($month, 0, 4) === date('Y');
        $query = ['period_type' => 'year', 'period' => substr($month, 0, 4)];
        $cash = FinanceBusinessLogic::monthlyReport($query + ['report' => 'cash']); self::assertNotFalse($cash, FinanceBusinessLogic::getError());
        self::assertSame('year', $cash['period_type']); self::assertSame($month, $cash['start_month']);
        self::assertSame('5000.00', $cash['data']['summary']['opening_accounts']); self::assertSame($sameYear ? '5200.00' : '5100.00', $cash['data']['summary']['closing_accounts']); self::assertSame($sameYear ? '200.00' : '100.00', $cash['data']['summary']['external_in']);
        self::assertSame($closed['snapshot']['reports']['cash'], $cash['months'][0]); self::assertTrue($cash['verification']['has_unresolved']);
        $customer = FinanceBusinessLogic::monthlyReport($query + ['report' => 'customer']); self::assertNotFalse($customer);
        $ar = array_values(array_filter($customer['data']['categories'], static fn(array $row): bool => $row['category'] === 'receivable'))[0];
        self::assertSame($sameYear ? '-200.00' : '-100.00', $ar['entries_change']); self::assertSame(bcsub($ar['opening'], $sameYear ? '200' : '100', 2), $ar['closing']);
        foreach (['profit', 'vendor', 'expense', 'inventory'] as $kind) { self::assertNotFalse(FinanceBusinessLogic::monthlyReport($query + ['report' => $kind]), FinanceBusinessLogic::getError()); }
        $quarter = date('Y') . '-Q' . (string)(int)ceil((int)date('n') / 3);
        self::assertNotFalse(FinanceBusinessLogic::monthlyReport(['period_type' => 'quarter', 'period' => $quarter, 'report' => 'cash']), FinanceBusinessLogic::getError());
        foreach ([['period_type' => 'quarter', 'period' => '2026-Q5'], ['period_type' => 'year', 'period' => '9999'], ['period_type' => 'week', 'period' => '2026']] as $invalid) { self::assertFalse(FinanceBusinessLogic::monthlyReport($invalid + ['report' => 'cash'])); }
    }

    public function test_closed_transit_followup_distinguishes_later_arrival_from_backdated_adjustment(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $cutoff = date('Y-m-t', strtotime($month . '-01')); $this->activate('cash', $month . '-01');
        $target = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '目标现金', 'account_type' => 'cash']); self::assertNotFalse($target);
        $out = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_out', 'payload' => ['account_id' => $this->accountId, 'target_account_id' => (int)$target['id'], 'actual_date' => $cutoff, 'amount' => '1000', 'source_reference' => '已结月在途55', 'transfer_verified' => 1, 'reason' => '月底实际转出']]); self::assertNotFalse($out, FinanceBusinessLogic::getError()); $source = $out['confirmed_result']['transfer_source'];
        $preview = FinanceBusinessLogic::periodAction('preview', ['month' => $month]);
        $closed = FinanceBusinessLogic::periodAction('close', $this->command(0) + ['month' => $month, 'mode' => 'estimated', 'acknowledge_unresolved' => 1, 'expected_fingerprint' => $preview['fingerprint'], 'reason' => '在途凭据待复核']); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        $options = FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => $source]); self::assertTrue($options['closed_followup_allowed']); $before = $options['selected_transfer'];
        $payload = ['month' => $month, 'transfer_source' => $source, 'actual_cutoff' => $cutoff . ' 23:59:59', 'expected_fingerprint' => $before['fingerprint'], 'expected_reconciliation_id' => 0, 'review_state' => 'normal', 'transfer_verified' => 1, 'reason' => '银行凭据查明原月末正常在途'];
        $count = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->count();
        $review = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => $payload]); self::assertNotFalse($review, FinanceBusinessLogic::getError());
        self::assertSame($count, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->count());
        $state = static function (string $month): array { return array_values(array_filter(FinanceBusinessLogic::periodAction('followups', ['month' => $month])['items'], static fn(array $item): bool => $item['original']['category'] === 'transit_reconciliation'))[0]['current']; };
        self::assertSame('resolved', $state($month)['status']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_arrival', 'payload' => ['source' => $source, 'account_id' => (int)$target['id'], 'actual_date' => date('Y-m-d'), 'amount' => '400', 'transfer_verified' => 1, 'reason' => '下月实际到账']]); self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        self::assertSame('resolved', $state($month)['status']);
        $returned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_return', 'payload' => ['source' => $source, 'account_id' => $this->accountId, 'actual_date' => $cutoff, 'amount' => '100', 'transfer_verified' => 1, 'reason' => '补录原月末返还']]); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        $current = FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => $source])['selected_transfer'];
        self::assertSame('900.00', $current['remaining_amount']); self::assertSame('1000.00', $current['frozen_remaining_amount']); self::assertSame('needs_review', $current['state']); self::assertSame('pending', $state($month)['status']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => array_replace($payload, ['expected_reconciliation_id' => $review['id']])]));
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => array_replace($payload, ['expected_reconciliation_id' => $review['id'], 'expected_fingerprint' => $current['fingerprint']])]), FinanceBusinessLogic::getError());
        self::assertSame('resolved', $state($month)['status']); self::assertNotEmpty($state($month)['adjustments']);
        self::assertSame($closed['snapshot'], FinanceBusinessLogic::periodAction('detail', ['month' => $month])['snapshot']);
    }

    public function test_closed_account_followup_verifies_zero_difference_without_rewriting_snapshot_or_creating_money(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $preview = FinanceBusinessLogic::periodAction('preview', ['month' => $month]);
        $closed = FinanceBusinessLogic::periodAction('close', $this->command(0) + ['month' => $month, 'mode' => 'estimated', 'acknowledge_unresolved' => 1, 'expected_fingerprint' => $preview['fingerprint'], 'reason' => '月末证明待取得']); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        $options = FinanceBusinessLogic::options(['type' => 'account_reconcile', 'month' => $month]); self::assertTrue($options['closed_followup_allowed']);
        $before = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->count();
        $confirmed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59', 'actual_balance' => '5000', 'expected_book_balance' => '5000', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '取得原月末现金盘点签字单']]); self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertTrue($confirmed['confirmed_result']['closed_period_followup']); self::assertSame('5000.00', $confirmed['confirmed_result']['frozen_book_balance']);
        self::assertSame($before, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->count());
        $followups = FinanceBusinessLogic::periodAction('followups', ['month' => $month]); self::assertTrue($followups['all_resolved']); self::assertSame('estimated', $followups['original_mode']);
        $receipt = $this->receipt('10', '10'); $receipt['actual_date'] = date('Y-m-d');
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]), FinanceBusinessLogic::getError());
        self::assertTrue(FinanceBusinessLogic::periodAction('followups', ['month' => $month])['all_resolved'], '当前月新收款不能混入原月末核对');
        self::assertSame($closed['snapshot'], FinanceBusinessLogic::periodAction('detail', ['month' => $month])['snapshot']);
    }

    public function test_closed_account_shortage_is_adjusted_in_current_month_and_requires_final_reverification(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01'); $cutoff = date('Y-m-t', strtotime($month . '-01'));
        $preview = FinanceBusinessLogic::periodAction('preview', ['month' => $month]);
        $closed = FinanceBusinessLogic::periodAction('close', $this->command(0) + ['month' => $month, 'mode' => 'estimated', 'acknowledge_unresolved' => 1, 'expected_fingerprint' => $preview['fingerprint'], 'reason' => '原月末现金事实待核实']); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        $payload = ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => $cutoff . ' 23:59:59', 'actual_balance' => '4900', 'expected_book_balance' => '5000', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '原月末签字盘点查明短款100'];
        $first = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => $payload]); self::assertNotFalse($first, FinanceBusinessLogic::getError());
        foreach (['70', '30'] as $amount) {
            $loss = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => ['reconciliation_document_id' => $first['id'], 'actual_date' => $cutoff, 'amount' => $amount, 'loss_verified' => 1, 'reason' => '分次查明现金遗失原因']]); self::assertNotFalse($loss, FinanceBusinessLogic::getError());
            self::assertSame(date('Y-m'), $loss['confirmed_result']['posting_month']);
        }
        self::assertFalse(FinanceBusinessLogic::periodAction('followups', ['month' => $month])['all_resolved'], '差额入账后必须复核原月末事实');
        $options = FinanceBusinessLogic::options(['type' => 'account_reconcile', 'month' => $month]); self::assertSame('4900.00', $options['checks'][0]['book_balance']); self::assertSame('5000.00', $options['checks'][0]['frozen_book_balance']);
        $payload['expected_book_balance'] = '4900'; $payload['expected_reconciliation_id'] = $first['id']; $payload['reason'] = '原月末余额与后续合法调整逐笔勾稽';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => $payload]), FinanceBusinessLogic::getError());
        self::assertTrue(FinanceBusinessLogic::periodAction('followups', ['month' => $month])['all_resolved']);
        $receipt = $this->receipt('10', '10'); $receipt['actual_date'] = $cutoff;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::periodAction('followups', ['month' => $month])['all_resolved'], '再次补录原月资金后核实进度须失效');
        self::assertSame($closed['snapshot'], FinanceBusinessLogic::periodAction('detail', ['month' => $month])['snapshot']);
    }

    /** @dataProvider expenseClosureCases */
    public function test_period_followups_keep_shortage_pending_until_all_replacement_origins_have_known_cost(bool $reclassified): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $warehouse = $this->createCustomerReportWarehouse('遗留负库存仓'); $goods = $this->createCustomerReportGoods('遗留负库存商品', 'FOLLOWUP-SHORT-53'); $sku = $this->customerReportSkuId($goods);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'followup-short-53', 'type' => 'issue', 'business_date' => $month . '-02', 'warehouse_id' => $warehouse, 'sku_id' => $sku, 'quantity' => '10', 'bucket' => 'pending', 'target_reference' => 'followup-short-53']));
        $preview = FinanceBusinessLogic::periodAction('preview', ['month' => $month]);
        $closed = FinanceBusinessLogic::periodAction('close', $this->command(0) + ['month' => $month, 'mode' => 'estimated', 'acknowledge_unresolved' => 1, 'expected_fingerprint' => $preview['fingerprint'], 'reason' => '负库存来源需继续核实']); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        if ($reclassified) {
            Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'followup-classify-53', 'type' => 'reclassify', 'business_date' => date('Y-m-d'), 'warehouse_id' => $warehouse, 'sku_id' => $sku, 'quantity' => '10', 'bucket' => 'pending', 'target_reference' => 'followup-short-53', 'to_bucket' => 'loss', 'to_reference' => 'followup-loss-53']));
            $tracking = FinanceBusinessLogic::periodAction('followups', ['month' => $month]);
            $item = array_values(array_filter($tracking['items'], static fn(array $row): bool => str_starts_with($row['original']['reference'], 'shortage:')))[0];
            self::assertSame('pending', $item['current']['status'], '责任已确定不等于成本来源已核实');
        }
        foreach ([['6', '60', 'partial'], ['4', '40', 'resolved']] as [$quantity, $amount, $state]) {
            Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'followup-fill-' . $quantity, 'origin' => 'followup-fill-' . $quantity, 'type' => 'receive', 'business_date' => date('Y-m-d'), 'warehouse_id' => $warehouse, 'sku_id' => $sku, 'quantity' => $quantity, 'amount' => $amount]));
            $tracking = FinanceBusinessLogic::periodAction('followups', ['month' => $month]);
            $item = array_values(array_filter($tracking['items'], static fn(array $row): bool => str_starts_with($row['original']['reference'], 'shortage:')))[0];
            self::assertSame($state, $item['current']['status']); self::assertNotEmpty($item['current']['cost_adjustments']);
        }
        self::assertSame($closed['snapshot'], FinanceBusinessLogic::periodAction('detail', ['month' => $month])['snapshot']);
    }

    public function test_period_followups_track_partial_purchase_confirmation_without_changing_closed_estimates(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $warehouse = $this->createCustomerReportWarehouse('遗留采购仓'); $goods = $this->createCustomerReportGoods('遗留采购商品', 'FOLLOWUP-PURCHASE-53'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '遗留采购方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => $month . '-02', 'source_reference' => 'FOLLOWUP-PURCHASE-53', 'reason' => '到货暂估待正式确认', 'lines' => [['sku_id' => $sku, 'actual_quantity' => '10', 'reported_quantity' => '10', 'agreed_price' => '2']]]]); self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $preview = FinanceBusinessLogic::periodAction('preview', ['month' => $month]);
        $closed = FinanceBusinessLogic::periodAction('close', $this->command(0) + ['month' => $month, 'mode' => 'estimated', 'acknowledge_unresolved' => 1, 'expected_fingerprint' => $preview['fingerprint'], 'reason' => '保留原到货暂估范围']); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        foreach ([['4', 'partial'], ['6', 'resolved']] as [$quantity, $state]) {
            $confirmed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor, 'supplier_confirmed' => 1, 'supplier_confirmation' => '双方核实本次数量单价', 'reason' => '分次核实遗留到货', 'lines' => [['arrival_line_id' => $arrival['confirmed_result']['lines'][0]['arrival_line_id'], 'covered_quantity' => $quantity, 'settlement_quantity' => $quantity, 'price' => '3']]]]); self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
            $tracking = FinanceBusinessLogic::periodAction('followups', ['month' => $month]); self::assertNotFalse($tracking, FinanceBusinessLogic::getError());
            $item = array_values(array_filter($tracking['items'], static fn(array $row): bool => $row['original']['category'] === 'purchase_cost'))[0];
            self::assertSame($state, $item['current']['status']); self::assertNotEmpty($item['current']['evidence']); self::assertNotEmpty($item['current']['cost_adjustments']);
            self::assertSame('20.00', $item['original']['details']['estimated_amount']);
        }
        self::assertSame($closed['snapshot'], FinanceBusinessLogic::periodAction('detail', ['month' => $month])['snapshot']);
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID); self::assertFalse(FinanceBusinessLogic::periodAction('followups', ['month' => $month]));
    }

    public function test_period_close_freezes_six_reports_and_carries_balances_without_new_revenue(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $cutoff = date('Y-m-t', strtotime($month . '-01'));
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => $cutoff . ' 23:59:59', 'actual_balance' => '5000', 'expected_book_balance' => '5000', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '实点月末资金']]), FinanceBusinessLogic::getError());
        $preview = FinanceBusinessLogic::periodAction('preview', ['month' => $month]); self::assertNotFalse($preview, FinanceBusinessLogic::getError());
        self::assertTrue($preview['checklist']['ordinary_ready']);
        $command = $this->command(0) + ['month' => $month, 'mode' => 'ordinary', 'expected_fingerprint' => $preview['fingerprint'], 'reason' => '逐项核对后正式月结'];
        $closed = FinanceBusinessLogic::periodAction('close', $command); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        self::assertSame($closed, FinanceBusinessLogic::periodAction('close', $command)); self::assertCount(6, $closed['snapshot']['reports']);
        self::assertSame('ordinary', $closed['snapshot']['mode']); self::assertSame(1, Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->count());
        $frozen = FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'cash']); self::assertFalse($frozen['stage']); self::assertSame('closed', $frozen['closing_status']);
        $receipt = $this->receipt('100', '100'); $receipt['actual_date'] = $month . '-02';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]), FinanceBusinessLogic::getError());
        self::assertSame($frozen, FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'cash']));
        $current = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'cash']); self::assertSame('5000.00', $current['data']['summary']['opening_accounts']); self::assertSame('5100.00', $current['data']['summary']['closing_accounts']);
        self::assertSame('0.00', FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'profit'])['data']['summary']['revenue']);
        self::assertFalse(FinanceBusinessLogic::periodAction('close', array_replace($command, ['idempotency_key' => $this->command(0)['idempotency_key']])));
    }

    public function test_period_estimated_close_requires_acknowledgement_and_preserves_unresolved_snapshot(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $preview = FinanceBusinessLogic::periodAction('preview', ['month' => $month]); self::assertNotFalse($preview, FinanceBusinessLogic::getError());
        self::assertFalse($preview['checklist']['ordinary_ready']); self::assertTrue($preview['checklist']['estimated_ready']);
        $command = $this->command(0) + ['month' => $month, 'mode' => 'ordinary', 'expected_fingerprint' => $preview['fingerprint'], 'reason' => '尚未取得最终月末核对依据'];
        self::assertFalse(FinanceBusinessLogic::periodAction('close', $command)); $command['mode'] = 'estimated';
        self::assertFalse(FinanceBusinessLogic::periodAction('close', $command)); $command['acknowledge_unresolved'] = 1;
        $closed = FinanceBusinessLogic::periodAction('close', $command); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        self::assertSame('estimated', $closed['snapshot']['mode']); self::assertNotEmpty($closed['snapshot']['unresolved']);
        self::assertSame('not_fully_verified', $closed['snapshot']['reports']['profit']['verification']['status']);
        self::assertSame('estimated', Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->where('month', $month)->value('status'));
    }

    public function test_period_close_rejects_stale_preview_future_month_skipped_month_and_non_owner(): void
    {
        $month = date('Y-m', strtotime('first day of -2 months')); $this->activate('cash', $month . '-01');
        $preview = FinanceBusinessLogic::periodAction('preview', ['month' => $month]); self::assertNotFalse($preview, FinanceBusinessLogic::getError());
        $receipt = $this->receipt('100', '100'); $receipt['actual_date'] = $month . '-02';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]), FinanceBusinessLogic::getError());
        $command = $this->command(0) + ['month' => $month, 'mode' => 'estimated', 'acknowledge_unresolved' => 1, 'expected_fingerprint' => $preview['fingerprint'], 'reason' => '预览后业务发生变化'];
        self::assertFalse(FinanceBusinessLogic::periodAction('close', $command)); self::assertStringContainsString('变化', FinanceBusinessLogic::getError());
        foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $invalid) {
            $next = FinanceBusinessLogic::periodAction('preview', ['month' => $invalid]); self::assertNotFalse($next, FinanceBusinessLogic::getError());
            self::assertFalse(FinanceBusinessLogic::periodAction('close', array_replace($command, ['month' => $invalid, 'expected_fingerprint' => $next['fingerprint']])));
        }
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996952; request()->adminId = 0;
        self::assertFalse(FinanceBusinessLogic::periodAction('preview', ['month' => $month])); self::assertFalse(FinanceBusinessLogic::periodAction('close', $command));
        self::assertSame(0, Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_monthly_inventory_report_preserves_unknown_historical_cost_after_later_pricing(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $warehouse = $this->createCustomerReportWarehouse('历史未知成本仓'); $goods = $this->createCustomerReportGoods('历史待核成本', 'REPORT-PENDING-51'); $sku = $this->customerReportSkuId($goods);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        foreach ([['reference' => 'report-unknown', 'type' => 'receive', 'business_date' => $month . '-02', 'quantity' => '10', 'amount' => null], ['reference' => 'report-priced', 'type' => 'adjust', 'business_date' => date('Y-m-d'), 'amount' => '100']] as $event) {
            Db::transaction(fn() => $cost->recordWithinTransaction($event + ['origin' => 'report-unknown', 'warehouse_id' => $warehouse, 'sku_id' => $sku]));
        }
        $old = FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'inventory']); self::assertNotFalse($old, FinanceBusinessLogic::getError());
        self::assertTrue($old['data']['positions'][0]['cost_pending']); self::assertNull($old['data']['positions'][0]['cost']);
        $current = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'inventory']); self::assertSame('100.00', $current['data']['positions'][0]['cost']);
    }

    public function test_monthly_expense_report_classifies_correction_reversal_using_original_expense(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '报表设备方']);
        $source = (new FinanceLedger(self::TENANT_ID))->createSource(1000, 'equipment', $vendor, '100', date('Y-m-d'), null, []);
        $payload = ['subject_id' => $vendor, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '100', 'reason' => '核实设备付款', 'allocations' => [['source' => $source, 'amount' => '100']]];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_payment', 'payload' => $payload]); self::assertNotFalse($original, FinanceBusinessLogic::getError());
        $payload['amount'] = '80'; $payload['allocations'][0]['amount'] = '80';
        $corrected = FinanceBusinessLogic::action('correct', $this->command($original['version']) + ['id' => $original['id'], 'correction_reason' => '原付款多记20元', 'payload' => $payload]); self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        $report = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'expense']); self::assertNotFalse($report, FinanceBusinessLogic::getError());
        self::assertCount(1, $report['data']['categories']); self::assertSame('设备实际支出', $report['data']['categories'][0]['name']); self::assertSame('80.00', $report['data']['categories'][0]['amount']);
    }

    public function test_monthly_inventory_report_uses_month_end_positions_instead_of_current_stock(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $warehouse = $this->createCustomerReportWarehouse('月报库存仓'); $goods = $this->createCustomerReportGoods('月报库存商品', 'REPORT-STOCK-51'); $sku = $this->customerReportSkuId($goods);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        foreach ([['reference' => 'report-stock-receive', 'type' => 'receive', 'business_date' => $month . '-02', 'quantity' => '10', 'origin' => 'report-stock-receive', 'amount' => '100'], ['reference' => 'report-stock-sale', 'type' => 'issue', 'business_date' => $month . '-03', 'quantity' => '3', 'bucket' => 'sale', 'target_reference' => 'report-stock-sale'], ['reference' => 'report-stock-next', 'type' => 'receive', 'business_date' => date('Y-m-d'), 'quantity' => '10', 'origin' => 'report-stock-next', 'amount' => '200']] as $event) {
            Db::transaction(fn() => $cost->recordWithinTransaction($event + ['warehouse_id' => $warehouse, 'sku_id' => $sku]));
        }
        $report = FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'inventory']); self::assertNotFalse($report, FinanceBusinessLogic::getError());
        self::assertCount(1, $report['data']['positions']); self::assertSame('7.0000', $report['data']['positions'][0]['quantity']); self::assertSame('70.00', $report['data']['positions'][0]['cost']);
        $current = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'inventory']); self::assertSame('17.0000', $current['data']['positions'][0]['quantity']); self::assertSame('270.00', $current['data']['positions'][0]['cost']);
    }

    public function test_monthly_customer_report_keeps_opening_debt_and_explicit_advance_separate_from_revenue(): void
    {
        $this->activate(); $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('120', '100', '20')]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $report = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'customer']); self::assertNotFalse($report, FinanceBusinessLogic::getError());
        $rows = array_column($report['data']['categories'], null, 'category');
        self::assertSame('1000.00', $rows['receivable']['opening']); self::assertSame('0.00', $rows['receivable']['new_sources']); self::assertSame('-100.00', $rows['receivable']['entries_change']); self::assertSame('900.00', $rows['receivable']['closing']);
        self::assertSame('20.00', $rows['advance']['closing']); self::assertCount(2, $report['data']['sources']);
        $cash = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'cash']); self::assertSame('120.00', $cash['data']['summary']['external_in']);
        $profit = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'profit']); self::assertSame('0.00', $profit['data']['summary']['revenue']);
    }

    public function test_monthly_profit_report_keeps_unknown_sales_cost_null_then_uses_confirmed_cost_without_counting_purchase_as_expense(): void
    {
        $this->activate(); $sale = $this->deliveredSale(); $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $confirmed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => ['subject_id' => $this->customerId, 'reason' => '月报正式收入', 'rounding_amount' => '0', 'precision_mode' => 'cents', 'lines' => [['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '10']]]]); self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        $report = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'profit']); self::assertNotFalse($report, FinanceBusinessLogic::getError());
        self::assertSame('20.00', $report['data']['summary']['revenue']); self::assertNull($report['data']['summary']['sales_cost']); self::assertNull($report['data']['summary']['profit']); self::assertSame('0.00', $report['data']['summary']['known_sales_cost']);
        $yearly = FinanceBusinessLogic::monthlyReport(['period_type' => 'year', 'period' => date('Y'), 'report' => 'profit']); self::assertNotFalse($yearly, FinanceBusinessLogic::getError()); self::assertNull($yearly['data']['summary']['sales_cost']); self::assertNull($yearly['data']['summary']['profit']); self::assertTrue($yearly['data']['summary']['cost_pending']);
        self::assertTrue($report['verification']['has_unresolved']);
        $item = Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->find(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '月报成本供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor, 'warehouse_id' => $item['warehouse_id'], 'actual_date' => date('Y-m-d'), 'source_reference' => 'REPORT-COST-51', 'reason' => '核实漏记的实际入库来源', 'lines' => [['sku_id' => $item['sku_id'], 'actual_quantity' => '2', 'reported_quantity' => '2', 'agreed_price' => '3']]]]); self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $supplier = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'vendor']); self::assertNotFalse($supplier, FinanceBusinessLogic::getError());
        self::assertCount(1, $supplier['data']['pending_arrivals']); self::assertSame('0.00', array_column($supplier['data']['categories'], 'closing', 'category')['payable']);
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor, 'supplier_confirmed' => 1, 'supplier_confirmation' => '全部价款正式确认', 'reason' => '月报正式采购成本', 'lines' => [['arrival_line_id' => $arrival['confirmed_result']['lines'][0]['arrival_line_id'], 'covered_quantity' => '2', 'settlement_quantity' => '2', 'price' => '3']]]]); self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        $supplier = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'vendor']); self::assertSame([], $supplier['data']['pending_arrivals']); self::assertSame('6.00', array_column($supplier['data']['categories'], 'closing', 'category')['payable']);
        $after = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'profit']); self::assertNotFalse($after, FinanceBusinessLogic::getError());
        self::assertSame('6.00', $after['data']['summary']['sales_cost']); self::assertSame('14.00', $after['data']['summary']['profit']); self::assertSame('0.00', $after['data']['summary']['expense']); self::assertNotEmpty($after['data']['entries']); self::assertNotEmpty($after['data']['cost_effects']);
    }

    public function test_period_checklist_accepts_formal_opening_unclaimed_acknowledgement_and_keeps_unknown_date_blocking(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        foreach ([995050 => date('Y-m-d', strtotime($month . '-01 -1 day')), 995051 => null] as $item => $date) {
            Db::name('finance_opening_source')->insert(['tenant_id' => self::TENANT_ID, 'activation_date' => $month . '-01', 'opening_item_id' => $item, 'category' => 'unclaimed', 'subject_id' => $this->accountId, 'amount' => '250', 'source_snapshot' => json_encode(['id' => $item, 'historical_date' => $date, 'subject_name' => '期初账户', 'source_reference' => '原到账-' . $item, 'details' => ['original_amount' => '400', 'claimed_amount' => '150', 'account_inclusion' => 'confirmed']]), 'create_time' => time()]);
        }
        $check = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotFalse($check, FinanceBusinessLogic::getError());
        $funds = array_values(array_filter($check['items'], static fn(array $item): bool => $item['category'] === 'unclaimed')); self::assertCount(2, $funds); self::assertSame('warning', $funds[0]['severity']); self::assertSame('blocking', $funds[1]['severity']);
    }

    public function test_period_checklist_keeps_month_end_unclaimed_money_after_later_claim_without_blocking_verified_funds(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_receipt', 'payload' => ['account_id' => $this->accountId, 'actual_date' => $month . '-10', 'amount' => '300', 'funds_verified' => 1, 'reason' => '到账已核实，用途尚未确认']]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $source = $receipt['confirmed_result']['unclaimed_source'];
        $check = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotFalse($check, FinanceBusinessLogic::getError());
        $funds = array_values(array_filter($check['items'], static fn(array $item): bool => $item['category'] === 'unclaimed')); self::assertCount(1, $funds); self::assertSame('warning', $funds[0]['severity']); self::assertSame('300.00', $funds[0]['details']['remaining_amount']);
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'estimated', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $claim = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_customer_claim', 'payload' => ['unclaimed_source' => $source, 'subject_id' => $this->customerId, 'amount' => '300', 'allocations' => [['source' => $this->receivable, 'amount' => '300']], 'claim_verified' => 1, 'reason' => '结账后查明付款客户']]); self::assertNotFalse($claim, FinanceBusinessLogic::getError());
        $after = FinanceBusinessLogic::closingChecklist(['month' => $month]); $funds = array_values(array_filter($after['items'], static fn(array $item): bool => $item['category'] === 'unclaimed')); self::assertCount(1, $funds); self::assertSame('300.00', $funds[0]['details']['remaining_amount']);
    }

    public function test_period_checklist_retains_unresolved_arrival_differences_physical_returns_and_inventory_losses(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $warehouse = $this->createCustomerReportWarehouse('月结争议仓'); $goods = $this->createCustomerReportGoods('月结争议商品', 'CLOSE-DIFF-50'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '月结争议供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => $month . '-02', 'source_reference' => 'CLOSE-DIFF-50', 'reason' => '报量实收差待双方核实', 'lines' => [['sku_id' => $sku, 'actual_quantity' => '10', 'reported_quantity' => '11', 'agreed_price' => '2']]]]); self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $returned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => $month . '-03', 'source_reference' => 'CLOSE-RETURN-50', 'reason' => '实物已退离，供方金额尚未认可', 'lines' => [['arrival_line_id' => $arrival['confirmed_result']['lines'][0]['arrival_line_id'], 'quantity' => '2']]]]); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        $loss = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss', 'payload' => ['warehouse_id' => $warehouse, 'sku_id' => $sku, 'actual_date' => $month . '-04', 'quantity' => '1', 'physical_confirmed' => 1, 'source_reference' => 'CLOSE-LOSS-50', 'responsibility' => '待核实', 'reason' => '实际减少，责任尚未核实']]); self::assertNotFalse($loss, FinanceBusinessLogic::getError());
        $check = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotFalse($check, FinanceBusinessLogic::getError());
        foreach (['purchase_difference', 'purchase_return', 'inventory_loss'] as $category) { self::assertContains($category, array_column($check['items'], 'category')); }
        self::assertTrue($check['estimated_ready']); self::assertFalse($check['ordinary_ready']);
    }

    public function test_period_checklist_only_blocks_statement_disputes_with_uncertain_ledger_facts(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        \app\api\jxc\logic\FinanceStatements::action('reply', $this->command(0) + ['id' => $statement['id'], 'response' => 'disputed', 'respondent' => '客户负责人', 'response_date' => date('Y-m-d'), 'evidence' => '逐笔核实期初应收', 'items' => [['source' => $this->receivable, 'amount' => '100', 'reason' => '原交付金额待核对', 'ledger_uncertain' => true]]]);
        $check = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotFalse($check, FinanceBusinessLogic::getError()); self::assertContains('statement_dispute', array_column($check['items'], 'category'));
        $dispute = \app\api\jxc\logic\FinanceStatements::openDisputes([$this->receivable])[0];
        \app\api\jxc\logic\FinanceStatements::action('resolve', $this->command(1) + ['id' => $statement['id'], 'dispute_id' => $dispute['id'], 'resolution' => 'ledger_verified', 'reason' => '账内金额与归属无误，保留客户外部争议']);
        $after = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotFalse($after, FinanceBusinessLogic::getError()); self::assertNotContains('statement_dispute', array_column($after['items'], 'category'));
    }

    public function test_period_checklist_keeps_priced_but_unsettled_purchase_cost_pending_until_formal_coverage(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $warehouse = $this->createCustomerReportWarehouse('月结采购仓'); $goods = $this->createCustomerReportGoods('月结采购商品', 'CLOSE-50'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '月结采购供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => $month . '-02', 'source_reference' => 'CLOSE-ARR-50', 'reason' => '本月已入库尚未正式结算', 'lines' => [['sku_id' => $sku, 'actual_quantity' => '10', 'reported_quantity' => '10', 'agreed_price' => '2']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivalId = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $check = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotFalse($check, FinanceBusinessLogic::getError()); self::assertContains('purchase_cost', array_column($check['items'], 'category'));
        $settlement = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor, 'supplier_confirmed' => 1, 'supplier_confirmation' => '双方确认全部到货价款', 'reason' => '本月成本正式核实', 'lines' => [['arrival_line_id' => $arrivalId, 'covered_quantity' => '10', 'settlement_quantity' => '10', 'price' => '2']]]]);
        self::assertNotFalse($settlement, FinanceBusinessLogic::getError());
        $after = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotContains('purchase_cost', array_column($after['items'], 'category'));
    }

    public function test_period_checklist_requires_recurring_month_outcome_but_keeps_reasonable_expense_estimates_as_tracking_items(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '月结周期服务方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => ['category_id' => 0, 'expected_category_version' => 0, 'parent' => 'utilities', 'name' => '月结水费', 'is_enabled' => 1]]); self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id'];
        $plan = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_plan', 'payload' => ['subject_id' => $vendor, 'category_id' => $categoryId, 'expected_category_version' => 1, 'source_reference' => 'CYCLE-CLOSE-50', 'service_start' => $month, 'service_end' => $month, 'interval_months' => 1, 'reason' => '本月应核对周期水费', 'plan_verified' => 1]]); self::assertNotFalse($plan, FinanceBusinessLogic::getError());
        $check = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertContains('recurring_expense', array_column($check['items'], 'category'));
        $expense = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => ['subject_id' => $vendor, 'recurring_plan_id' => $plan['confirmed_result']['plan_id'], 'expected_plan_version' => 1, 'expected_month_revision_id' => 0, 'benefit_month' => $month,
            'actual_date' => $month . '-05', 'amount' => '100', 'source_reference' => 'CYCLE-CLOSE-50/' . $month, 'due_mode' => 'unspecified', 'reason' => '依据本月表数合理暂估', 'amount_status' => 'estimated', 'estimate_basis_type' => 'measurement', 'estimate_basis' => '本月已使用表数与合同单价', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '账单尚未取得', 'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '100', 'reason' => '本月水费']]]]); self::assertNotFalse($expense, FinanceBusinessLogic::getError());
        $after = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotContains('recurring_expense', array_column($after['items'], 'category'));
        $estimates = array_values(array_filter($after['items'], static fn(array $item): bool => $item['category'] === 'expense_estimate')); self::assertCount(1, $estimates); self::assertSame('estimate', $estimates[0]['severity']); self::assertSame('100.00', $estimates[0]['details']['estimated_amount']);
        $report = FinanceBusinessLogic::monthlyReport(['month' => $month, 'report' => 'expense']); self::assertNotFalse($report, FinanceBusinessLogic::getError());
        self::assertSame('100.00', $report['data']['summary']['amount']); self::assertSame('100.00', $report['data']['categories'][0]['amount']);
        self::assertTrue($report['verification']['has_estimates']);
        self::assertSame('本月水费', $report['data']['entries'][0]['details']['reason']); self::assertSame('estimated', $report['data']['entries'][0]['document']['amount_status']);
        self::assertSame('100.00', array_column($report['data']['obligations']['categories'], 'closing', 'category')['expense_payable']);
    }

    public function test_period_checklist_delivered_unconfirmed_sales_block_estimated_close_until_formal_amount_is_confirmed(): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $this->activate('cash', $start); $sale = $this->deliveredSale($start);
        $check = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotFalse($check, FinanceBusinessLogic::getError()); self::assertFalse($check['estimated_ready']); self::assertContains('unconfirmed_delivery', array_column($check['items'], 'category'));
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $confirmed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => ['subject_id' => $this->customerId, 'reason' => '已交付金额正式核实', 'rounding_amount' => '0', 'precision_mode' => 'cents', 'lines' => [['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '10']]]]); self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        $after = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertTrue($after['estimated_ready']); self::assertNotContains('unconfirmed_delivery', array_column($after['items'], 'category'));
        self::assertContains('cost_pending', array_column($after['items'], 'category'), '负库存来源成本尚未核实，只能保留为暂估未决事项');
    }

    public function test_period_checklist_distinguishes_draft_reminders_pending_documents_and_normal_unpaid_balances(): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $cutoff = date('Y-m-t', strtotime($start)); $this->activate('cash', $start);
        $initial = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotFalse($initial, FinanceBusinessLogic::getError()); self::assertFalse($initial['ordinary_ready']); self::assertTrue($initial['estimated_ready']);
        self::assertContains('account_reconciliation', array_column($initial['items'], 'category'));
        $reconciliation = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => $cutoff . ' 23:59:59', 'actual_balance' => '5000', 'expected_book_balance' => '5000', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '月末现金实点已核对']]); self::assertNotFalse($reconciliation, FinanceBusinessLogic::getError());
        $draft = FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'receipt', 'payload' => array_replace($this->receipt('100', '100'), ['actual_date' => $cutoff])]); self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        $withDraft = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertNotFalse($withDraft, FinanceBusinessLogic::getError()); self::assertTrue($withDraft['ordinary_ready'], '未收期初应收与未提交草稿不阻止普通月结');
        self::assertSame('warning', array_values(array_filter($withDraft['items'], static fn(array $row): bool => $row['category'] === 'draft'))[0]['severity']);
        $submitted = FinanceBusinessLogic::action('submit', $this->command($draft['version']) + ['id' => $draft['id']]); self::assertNotFalse($submitted, FinanceBusinessLogic::getError());
        $withPending = FinanceBusinessLogic::closingChecklist(['month' => $month]); self::assertFalse($withPending['ordinary_ready']); self::assertTrue($withPending['estimated_ready']); self::assertContains('pending_document', array_column($withPending['items'], 'category'));
        $current = FinanceBusinessLogic::closingChecklist(['month' => date('Y-m')]); self::assertNotFalse($current, FinanceBusinessLogic::getError()); self::assertFalse($current['ordinary_ready']); self::assertFalse($current['estimated_ready']); self::assertContains('month_not_ended', array_column($current['items'], 'category'));
        self::assertSame(0, Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->count(), '检查不能自动结账');
    }

    public function test_transit_reconciliation_preserves_known_opening_extra_fee_and_zero_does_not_hide_unknown_facts(): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $this->activate('cash', $start);
        $target = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '原在途目标', 'account_type' => 'cash']); self::assertNotFalse($target);
        $snapshot = ['id' => 9051, 'subject_name' => '原现金', 'source_reference' => '额外手续费已核实', 'historical_date' => $start, 'evidence' => '原手续费5元已核实',
            'details' => ['target_account_id' => (string)$target['id'], 'principal' => '500', 'arrived_amount' => '100', 'returned_amount' => '50', 'withheld_fee' => '10', 'additional_fee' => '5']];
        $id = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'opening_item_id' => 9051, 'category' => 'transit', 'subject_id' => $this->accountId, 'amount' => '340.00', 'activation_date' => $start, 'source_snapshot' => json_encode($snapshot), 'create_time' => time()]);
        $options = FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => 'o:' . $id]); self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertSame('5.00', $options['selected_transfer']['extra_fee'], '已知期初手续费不能重新当作未知');
        $data = ['month' => $month, 'transfer_source' => 'o:' . $id, 'actual_cutoff' => $options['actual_cutoff'], 'expected_fingerprint' => $options['selected_transfer']['fingerprint'], 'expected_reconciliation_id' => 0, 'review_state' => 'normal', 'transfer_verified' => 1, 'verified_extra_fee' => '0', 'reason' => '沿用原已确认手续费'];
        $review = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => $data]); self::assertNotFalse($review, FinanceBusinessLogic::getError()); self::assertSame('5.00', $review['confirmed_result']['verified_extra_fee']);
        $unknown = ['id' => 9052, 'subject_name' => '原现金', 'source_reference' => '零余额但组成未知', 'historical_date' => $start, 'evidence' => '组成未核实', 'details' => []];
        $unknownId = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'opening_item_id' => 9052, 'category' => 'transit', 'subject_id' => $this->accountId, 'amount' => '0.00', 'activation_date' => $start, 'source_snapshot' => json_encode($unknown), 'create_time' => time()]);
        $state = FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => 'o:' . $unknownId]); self::assertNotFalse($state, FinanceBusinessLogic::getError()); self::assertFalse($state['selected_transfer']['ordinary_close_allowed']); self::assertSame('unreviewed', $state['selected_transfer']['state']);
    }

    public function test_transit_reconciliation_unknown_opening_composition_stays_unresolved_and_known_fees_are_not_charged_again(): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $cutoff = date('Y-m-t', strtotime($start)); $this->activate('cash', $start);
        $target = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '历史在途目标', 'account_type' => 'cash']); self::assertNotFalse($target);
        $snapshot = ['id' => 9049, 'subject_name' => '经营账户', 'source_reference' => '期初在途49', 'historical_date' => $start, 'evidence' => '原在途余额可核实，组成需另查', 'details' => []];
        $id = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'opening_item_id' => 9049, 'category' => 'transit', 'subject_id' => $this->accountId, 'amount' => '340.00', 'activation_date' => $start, 'source_snapshot' => json_encode($snapshot), 'create_time' => time()]);
        $source = 'o:' . $id; $options = FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => $source]); self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertNull($options['selected_transfer']['principal']); self::assertNull($options['selected_transfer']['extra_fee']); self::assertFalse($options['selected_transfer']['composition_known']);
        $data = ['month' => $month, 'transfer_source' => $source, 'actual_cutoff' => $cutoff . ' 23:59:59', 'expected_fingerprint' => $options['selected_transfer']['fingerprint'], 'expected_reconciliation_id' => 0, 'review_state' => 'normal', 'transfer_verified' => 1, 'reason' => '不能把未知组成核对为正常'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => $data]));
        $pending = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => array_replace($data, ['review_state' => 'unresolved', 'transfer_verified' => 0])]); self::assertNotFalse($pending, FinanceBusinessLogic::getError());
        $known = $snapshot; $known['id'] = 9050; $known['source_reference'] = '完整组成期初在途50'; $known['details'] = ['target_account_id' => (string)$target['id'], 'principal' => '500', 'arrived_amount' => '100', 'returned_amount' => '50', 'withheld_fee' => '10'];
        $knownId = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'opening_item_id' => 9050, 'category' => 'transit', 'subject_id' => $this->accountId, 'amount' => '340.00', 'activation_date' => $start, 'source_snapshot' => json_encode($known), 'create_time' => time()]);
        $options = FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => 'o:' . $knownId]); self::assertNotFalse($options, FinanceBusinessLogic::getError());
        $knownData = array_replace($data, ['transfer_source' => 'o:' . $knownId, 'expected_fingerprint' => $options['selected_transfer']['fingerprint']]);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => $knownData]), '历史额外手续费未知不能自动按零确认');
        $verified = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => $knownData + ['verified_extra_fee' => '5']]); self::assertNotFalse($verified, FinanceBusinessLogic::getError()); self::assertSame('5.00', $verified['confirmed_result']['verified_extra_fee']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $verified['id'])->count()); self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => $this->receivable]));
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'estimated', 'snapshot' => '{"frozen":true}', 'closed_by' => '{}', 'closed_at' => time()]);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => array_replace($knownData, ['expected_reconciliation_id' => $verified['id'], 'verified_extra_fee' => '5'])]));
        self::assertSame('normal', FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => 'o:' . $knownId])['selected_transfer']['state']);
        self::assertSame($snapshot, json_decode(Db::name('finance_opening_source')->where('id', $id)->value('source_snapshot'), true));
    }

    public function test_transit_reconciliation_retains_month_end_composition_after_later_arrival_and_rechecks_backdated_settlement(): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $cutoff = date('Y-m-t', strtotime($start)); $this->activate('cash', $start);
        $target = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '目标现金', 'account_type' => 'cash']); self::assertNotFalse($target);
        $out = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_out', 'payload' => ['account_id' => $this->accountId, 'target_account_id' => (int)$target['id'], 'actual_date' => $cutoff, 'amount' => '1000', 'source_reference' => '跨月在途核对49', 'transfer_verified' => 1, 'reason' => '月底实际转出']]); self::assertNotFalse($out, FinanceBusinessLogic::getError());
        $source = $out['confirmed_result']['transfer_source'];
        $options = FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => $source]); self::assertNotFalse($options, FinanceBusinessLogic::getError());
        $before = $options['selected_transfer']; self::assertSame('1000.00', $before['remaining_amount']);
        $payload = ['month' => $month, 'transfer_source' => $source, 'actual_cutoff' => $cutoff . ' 23:59:59', 'expected_fingerprint' => $before['fingerprint'], 'expected_reconciliation_id' => 0, 'review_state' => 'normal', 'transfer_verified' => 1, 'reason' => '转账凭据与对方月末未到账均已核对，正常跨月在途'];
        $review = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => $payload]); self::assertNotFalse($review, FinanceBusinessLogic::getError());
        self::assertTrue($review['confirmed_result']['ordinary_close_allowed']);
        // 模拟升级前已保存的核对，新增展示字段不能使无业务变化的历史结论失效。
        $legacy = $review['confirmed_result']; $legacyTransfer = $legacy['transfer'];
        unset($legacyTransfer['fingerprint'], $legacyTransfer['closed_period_followup'], $legacyTransfer['frozen_remaining_amount'], $legacyTransfer['post_close_adjustments']);
        $legacyTransfer['fingerprint'] = hash('sha256', \app\api\jxc\logic\FinanceValue::json($legacyTransfer)); $legacy['transfer'] = $legacyTransfer;
        Db::name('finance_transit_reconciliation')->where('tenant_id', self::TENANT_ID)->where('document_id', $review['id'])->update(['snapshot' => \app\api\jxc\logic\FinanceValue::json($legacy)]);
        self::assertSame('normal', FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => $source])['selected_transfer']['state']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_arrival', 'payload' => ['source' => $source, 'account_id' => (int)$target['id'], 'actual_date' => date('Y-m-d'), 'amount' => '400', 'transfer_verified' => 1, 'reason' => '下月实际到账']]); self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $options = FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => $source]); self::assertSame('normal', $options['selected_transfer']['state']); self::assertSame('1000.00', $options['selected_transfer']['remaining_amount']);
        $returned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_return', 'payload' => ['source' => $source, 'account_id' => $this->accountId, 'actual_date' => $cutoff, 'amount' => '100', 'transfer_verified' => 1, 'reason' => '补录月末已实际返还']]); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        $options = FinanceBusinessLogic::options(['type' => 'transit_reconcile', 'month' => $month, 'transfer_source' => $source]); self::assertSame('needs_review', $options['selected_transfer']['state']); self::assertSame('900.00', $options['selected_transfer']['remaining_amount']); self::assertFalse($options['selected_transfer']['ordinary_close_allowed']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => array_replace($payload, ['expected_reconciliation_id' => $review['id']])]));
        $unresolved = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'transit_reconcile', 'payload' => array_replace($payload, ['expected_fingerprint' => $options['selected_transfer']['fingerprint'], 'expected_reconciliation_id' => $review['id'], 'review_state' => 'unresolved', 'transfer_verified' => 0, 'reason' => '剩余在途证据还需补充'])]); self::assertNotFalse($unresolved, FinanceBusinessLogic::getError()); self::assertFalse($unresolved['confirmed_result']['ordinary_close_allowed']);
        self::assertSame($review['confirmed_result'], FinanceBusinessLogic::detail(['id' => $review['id']])['confirmed_result']);
    }

    public static function staleShortageCases(): array { return [['backdated'], ['later_reconciliation']]; }

    /** @dataProvider staleShortageCases */
    public function test_cash_shortage_cannot_reuse_stale_or_superseded_difference(string $case): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $cutoff = date('Y-m-t', strtotime($start)); $this->activate('cash', $start);
        $data = ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => $cutoff . ' 23:59:59', 'actual_balance' => '4900', 'expected_book_balance' => '5000', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '核实原短款100'];
        $record = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => $data]); self::assertNotFalse($record, FinanceBusinessLogic::getError());
        $lossData = ['reconciliation_document_id' => $record['id'], 'actual_date' => $cutoff, 'amount' => '100', 'loss_verified' => 1, 'reason' => '实际遗失已核实'];
        if ($case === 'backdated') {
            $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => array_replace($this->receipt('100', '100'), ['actual_date' => $cutoff])]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => $lossData]), '补录改变原核对账面后不能按旧差额登记损失');
            self::assertSame('5100.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        } else {
            $first = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => array_replace($lossData, ['amount' => '50'])]); self::assertNotFalse($first, FinanceBusinessLogic::getError());
            $next = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => array_replace($data, ['expected_book_balance' => '4950', 'expected_reconciliation_id' => $record['id']])]); self::assertNotFalse($next, FinanceBusinessLogic::getError());
            $second = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => array_replace($lossData, ['reconciliation_document_id' => $next['id'], 'amount' => '50'])]); self::assertNotFalse($second, FinanceBusinessLogic::getError());
            self::assertFalse(FinanceBusinessLogic::action('correct', $this->command($first['version']) + ['id' => $first['id'], 'payload' => $lossData, 'correction_reason' => '不能绕过后续核对增加原损失']), '同一短款已在新核对处理，不能调增旧记录再次消耗');
            self::assertSame('4900.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        }
    }

    public function test_cash_shortage_after_close_keeps_snapshot_and_limits_original_difference_across_corrections(): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $cutoff = date('Y-m-t', strtotime($start)); $this->activate('cash', $start);
        $data = ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => $cutoff . ' 23:59:59', 'actual_balance' => '4900', 'expected_book_balance' => '5000', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '保留原月末短款事实'];
        $record = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => $data]); self::assertNotFalse($record, FinanceBusinessLogic::getError());
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'estimated', 'snapshot' => '{"frozen":true}', 'closed_by' => '{}', 'closed_at' => time()]);
        $lossData = ['reconciliation_document_id' => $record['id'], 'actual_date' => $cutoff, 'amount' => '100', 'loss_verified' => 1, 'reason' => '结后查明现金遗失'];
        foreach ([['amount' => '101'], ['loss_verified' => 0], ['actual_date' => date('Y-m-d')], ['reconciliation_document_id' => 999999999]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => array_replace($lossData, $invalid)]));
        }
        $loss = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => $lossData]); self::assertNotFalse($loss, FinanceBusinessLogic::getError()); self::assertSame(date('Y-m'), $loss['confirmed_result']['posting_month']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => $lossData]));
        $options = FinanceBusinessLogic::options(['type' => 'cash_shortage', 'reconciliation_document_id' => $record['id']]); self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertTrue($options['closed']); self::assertSame('5000.00', $options['checks'][0]['book_balance']); self::assertSame('0.00', $options['selected_reconciliation']['remaining_shortage']);
        $reverse = FinanceBusinessLogic::action('reverse', $this->command($loss['version']) + ['id' => $loss['id'], 'correction_reason' => '错误遗失结论按关联反向恢复']); self::assertNotFalse($reverse, FinanceBusinessLogic::getError());
        self::assertSame('5000.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        $replacement = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => $lossData]); self::assertNotFalse($replacement, FinanceBusinessLogic::getError());
        self::assertSame('{"frozen":true}', Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->where('month', $month)->value('snapshot'));
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_account_reconciliation_rejects_unseen_balance_changes_and_shortage_requires_owner_and_real_cash(): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $cutoff = date('Y-m-t', strtotime($start)); $this->activate('bank', $start);
        $data = ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => $cutoff . ' 23:59:59', 'actual_balance' => '4900', 'expected_book_balance' => '4999', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '核对月末银行对账单'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => $data]), '不能把用户未看到的新账面余额自动确认');
        $data['expected_book_balance'] = '5000';
        $record = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => $data]); self::assertNotFalse($record, FinanceBusinessLogic::getError());
        $loss = ['reconciliation_document_id' => $record['id'], 'actual_date' => $cutoff, 'amount' => '100', 'loss_verified' => 1, 'reason' => '银行差额不能虚构现金遗失'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => $loss])); self::assertStringContainsString('现金账户', FinanceBusinessLogic::getError());
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{"frozen":true}', 'closed_by' => '{}', 'closed_at' => time()]);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => array_replace($data, ['expected_reconciliation_id' => $record['id']])]));
        self::assertStringContainsString('已结', FinanceBusinessLogic::getError());
        $employee = WorkforceLogic::saveEmployee(['name' => '仅准备账户核对', 'mobile' => '13800009948', 'bind_user_id' => 996948, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.reconcile.prepare']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996948; request()->adminId = 0;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => $loss])); self::assertStringContainsString('最高权限', FinanceBusinessLogic::getError());
        self::assertNotFalse(FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => 'account_reconcile', 'payload' => $data]), FinanceBusinessLogic::getError());
    }

    public function test_cash_shortage_uses_verified_difference_without_external_payment_and_requires_reconciliation_again(): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $cutoff = date('Y-m-t', strtotime($start)); $this->activate('cash', $start);
        $data = ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => $cutoff . ' 23:59:59', 'actual_balance' => '4900', 'expected_book_balance' => '5000', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '实盘发现短款100元'];
        $record = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => $data]); self::assertNotFalse($record, FinanceBusinessLogic::getError());
        $lossData = ['reconciliation_document_id' => $record['id'], 'actual_date' => $cutoff, 'amount' => '100', 'loss_verified' => 1, 'reason' => '查明为门店承担的现金遗失，清点记录已核对'];
        $command = $this->command(0) + ['type' => 'cash_shortage', 'payload' => $lossData];
        $loss = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($loss, FinanceBusinessLogic::getError());
        self::assertSame($loss, FinanceBusinessLogic::action('record', $command));
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('4900.00', $ledger->account($this->accountId)['balance']);
        self::assertEquals(100, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'loss')->sum('amount'));
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => $lossData]));
        $checks = FinanceBusinessLogic::options(['type' => 'account_reconcile', 'month' => $month])['checks']; self::assertSame('needs_review', $checks[0]['state']);
        $corrected = FinanceBusinessLogic::action('correct', $this->command($loss['version']) + ['id' => $loss['id'], 'payload' => array_replace($lossData, ['amount' => '80']), 'correction_reason' => '查明20元不是遗失，纠正原短款损失']); self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        self::assertSame('4920.00', $ledger->account($this->accountId)['balance']);
        $rest = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => array_replace($lossData, ['amount' => '20'])]); self::assertNotFalse($rest, FinanceBusinessLogic::getError());
        $newRecord = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => array_replace($data, ['expected_book_balance' => '4900', 'expected_reconciliation_id' => $record['id']])]); self::assertNotFalse($newRecord, FinanceBusinessLogic::getError());
        self::assertSame('matched', FinanceBusinessLogic::options(['type' => 'account_reconcile', 'month' => $month])['checks'][0]['state']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'cash_shortage', 'payload' => array_replace($lossData, ['reconciliation_document_id' => $newRecord['id'], 'amount' => '1'])]));
        self::assertFalse(FinanceBusinessLogic::action('reverse', $this->command($record['version']) + ['id' => $record['id'], 'correction_reason' => '不能删除原核对历史']));
    }

    public function test_account_reconciliation_preserves_cutoff_and_requires_review_only_for_changed_account(): void
    {
        $start = date('Y-m-01', strtotime('first day of last month')); $month = substr($start, 0, 7); $cutoff = date('Y-m-t', strtotime($start));
        $this->activate('cash', $start);
        $other = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '备用现金', 'account_type' => 'cash']); self::assertNotFalse($other);
        $data = ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => $cutoff . ' 23:59:59', 'actual_balance' => '5000', 'expected_book_balance' => '5000', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '按月末同一时点清点现金'];
        $record = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => $data]); self::assertNotFalse($record, FinanceBusinessLogic::getError());
        self::assertSame('5000.00', $record['confirmed_result']['book_balance']); self::assertSame('0.00', $record['confirmed_result']['difference']);
        $otherRecord = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => array_replace($data, ['account_id' => (int)$other['id'], 'actual_balance' => '0', 'expected_book_balance' => '0'])]); self::assertNotFalse($otherRecord, FinanceBusinessLogic::getError());
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $options = FinanceBusinessLogic::options(['type' => 'account_reconcile', 'month' => $month]); self::assertNotFalse($options, FinanceBusinessLogic::getError());
        $checks = array_column($options['checks'], null, 'account_id'); self::assertSame('matched', $checks[$this->accountId]['state']);
        $backdated = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => array_replace($this->receipt('200', '200'), ['actual_date' => $cutoff])]); self::assertNotFalse($backdated, FinanceBusinessLogic::getError());
        $options = FinanceBusinessLogic::options(['type' => 'account_reconcile', 'month' => $month]); $checks = array_column($options['checks'], null, 'account_id');
        self::assertSame('needs_review', $checks[$this->accountId]['state']); self::assertSame('5200.00', $checks[$this->accountId]['book_balance']); self::assertSame('matched', $checks[(int)$other['id']]['state']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => array_replace($data, ['actual_balance' => '5200'])]));
        $updated = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => array_replace($data, ['actual_balance' => '5200', 'expected_book_balance' => '5200', 'expected_reconciliation_id' => $record['id']])]); self::assertNotFalse($updated, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $updated['confirmed_result']['difference']); self::assertSame($record['confirmed_result'], FinanceBusinessLogic::detail(['id' => $record['id']])['confirmed_result']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => array_replace($data, ['actual_cutoff' => date('Y-m-d') . ' 23:59:59', 'expected_reconciliation_id' => $updated['id']])]));
    }

    public function test_unclaimed_customer_to_vendor_correction_records_reopened_customer_overdue_history(): void
    {
        $this->activate('cash', date('Y-m-01', strtotime('first day of last month'))); $ledger = new FinanceLedger(self::TENANT_ID);
        $due = date('Y-m-d', strtotime('-2 days'));
        $source = $ledger->createSource(0, 'receivable', $this->customerId, '300', $due, $due, ['subject_name' => '原客户欠款']);
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_receipt', 'payload' => ['account_id' => $this->accountId,
            'actual_date' => date('Y-m-d'), 'amount' => '300', 'funds_verified' => 1, 'reason' => '实际到账未知用途']]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $fund = $receipt['confirmed_result']['unclaimed_source'];
        $claim = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_customer_claim', 'payload' => ['unclaimed_source' => $fund, 'subject_id' => $this->customerId,
            'amount' => '300', 'allocations' => [['source' => $source, 'amount' => '300']], 'claim_verified' => 1, 'reason' => '原来按客户收款认领']]); self::assertNotFalse($claim, FinanceBusinessLogic::getError());
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '实际退款供应商']);
        $refund = $ledger->createSource(0, 'supplier_refund', $vendor, '300', $due, null, ['subject_name' => '实际退款供应商']);
        $corrected = FinanceBusinessLogic::action('correct', $this->command($claim['version']) + ['id' => $claim['id'], 'payload' => ['replacement_type' => 'unclaimed_supplier_refund_claim',
            'unclaimed_source' => $fund, 'subject_id' => $vendor, 'amount' => '300', 'allocations' => [['source' => $refund, 'amount' => '300']], 'claim_verified' => 1, 'reason' => '核实实际为供应商退款'], 'correction_reason' => '跨主体用途误认领']); self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        $event = Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->where('source_ref', $source)->where('document_id', $corrected['id'])->find();
        self::assertNotNull($event, '恢复原客户应收必须追加关联本次更正的逾期观察');
        self::assertSame($this->customerId, (int)$event['customer_id']); self::assertNotEmpty(json_decode($event['timing'], true));
        self::assertSame('300.00', $ledger->source($source)['balance']); self::assertSame('0.00', $ledger->source($fund)['balance']);
    }

    public function test_unclaimed_cross_purpose_correction_is_atomic_and_requires_both_original_and_new_permissions(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_receipt', 'payload' => ['account_id' => $this->accountId,
            'actual_date' => date('Y-m-d'), 'amount' => '300', 'funds_verified' => 1, 'reason' => '实际资金已核实，待认领']]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $fund = $receipt['confirmed_result']['unclaimed_source'];
        $data = ['unclaimed_source' => $fund, 'subject_id' => $this->customerId, 'amount' => '300', 'advance_amount' => '0', 'claim_verified' => 1, 'reason' => '核实认领用途', 'allocations' => [['source' => $this->receivable, 'amount' => '300']]];
        $claim = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_customer_claim', 'payload' => $data]); self::assertNotFalse($claim, FinanceBusinessLogic::getError());
        $recovery = $ledger->createSource(0, 'recovery', $this->customerId, '300', date('Y-m-d'), null, ['subject_name' => '坏账追偿']);
        $replacement = array_replace($data, ['replacement_type' => 'unclaimed_recovery_claim', 'allocations' => [['source' => $recovery, 'amount' => '300']]]);
        $command = $this->command($claim['version']) + ['id' => $claim['id'], 'payload' => $replacement, 'correction_reason' => '原到账实际属于坏账收回，认领用途录错'];
        $reclassified = FinanceBusinessLogic::action('correct', $command); self::assertNotFalse($reclassified, FinanceBusinessLogic::getError());
        self::assertSame('unclaimed_recovery_claim', $reclassified['type']); self::assertSame('1000.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('0.00', $ledger->source($recovery)['balance']); self::assertSame('0.00', $ledger->source($fund)['balance']);
        self::assertSame('5300.00', $ledger->account($this->accountId)['balance']); self::assertSame('300.00', Db::name('finance_entry')->where('document_id', $reclassified['id'])->where('metric', 'recovery_income')->value('amount'));
        $customerAgain = array_replace($data, ['replacement_type' => 'unclaimed_customer_claim']);
        $backCommand = $this->command($reclassified['version']) + ['id' => $reclassified['id'], 'payload' => $customerAgain, 'correction_reason' => '最终凭据更正回普通欠款'];
        $back = FinanceBusinessLogic::action('correct', $backCommand); self::assertNotFalse($back, FinanceBusinessLogic::getError());
        self::assertSame('unclaimed_customer_claim', $back['type']); self::assertSame('300.00', $ledger->source($recovery)['balance']); self::assertSame('700.00', $ledger->source($this->receivable)['balance']);
        self::assertEquals(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'recovery_income')->sum('amount'));
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame($back, FinanceBusinessLogic::action('correct', $backCommand));
        $invalid = array_replace($replacement, ['allocations' => [['source' => 'n:999999999', 'amount' => '300']]]);
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command($back['version']) + ['id' => $back['id'], 'payload' => $invalid, 'correction_reason' => '错误目标不应留下反向影响']));
        self::assertSame('700.00', $ledger->source($this->receivable)['balance']); self::assertSame('0.00', $ledger->source($fund)['balance']);
        self::assertSame(0, Db::name('finance_correction')->where('tenant_id', self::TENANT_ID)->where('original_document_id', $back['id'])->count());
        $employee = WorkforceLogic::saveEmployee(['name' => '仅客户收款权限', 'mobile' => '13800009950', 'bind_user_id' => 996950, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.receipt.prepare', 'finance.receipt.confirm']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996950; request()->adminId = 0;
        self::assertFalse(FinanceBusinessLogic::action('correct', $backCommand)); self::assertStringContainsString('最高权限', FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command($back['version']) + ['id' => $back['id'], 'payload' => $replacement, 'correction_reason' => '无高权限不能跨用途']));
        self::assertSame('700.00', $ledger->source($this->receivable)['balance']); self::assertSame('300.00', $ledger->source($recovery)['balance']);
        self::assertSame('0.00', $ledger->source($fund)['balance']);
    }

    public function test_unclaimed_receipts_are_partially_claimed_and_corrected_without_new_cash_or_revenue(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $openingId = (int)substr($this->receivable, 2);
        $snapshot = json_decode(Db::name('finance_opening_source')->where('id', $openingId)->value('source_snapshot'), true);
        $snapshot['historical_date'] = $month . '-01';
        Db::name('finance_opening_source')->where('id', $openingId)->update(['source_snapshot' => json_encode($snapshot)]);
        $data = ['account_id' => $this->accountId, 'actual_date' => $month . '-20', 'amount' => '1200', 'funds_verified' => 1, 'reason' => '已核实金额、日期、账户及门店，付款客户未知'];
        $request = $this->command(0) + ['type' => 'unclaimed_receipt', 'payload' => $data];
        $receipt = FinanceBusinessLogic::action('record', $request); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        self::assertSame($receipt, FinanceBusinessLogic::action('record', $request));
        $fund = $receipt['confirmed_result']['created_sources'][0]; $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('6200.00', $ledger->account($this->accountId)['balance']); self::assertSame('1200.00', $ledger->source($fund)['balance']);
        self::assertSame([], $ledger->sources('advance', $this->customerId));
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{"frozen":1200}', 'closed_by' => '{}', 'closed_at' => time()]);
        $claimData = ['unclaimed_source' => $fund, 'subject_id' => $this->customerId, 'amount' => '700', 'advance_amount' => '0', 'allocations' => [['source' => $this->receivable, 'amount' => '700']], 'claim_verified' => 1, 'reason' => '核实为客户偿还原欠款'];
        $claim = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_customer_claim', 'payload' => $claimData]); self::assertNotFalse($claim, FinanceBusinessLogic::getError());
        self::assertSame('500.00', $ledger->source($fund)['balance']); self::assertSame('300.00', $ledger->source($this->receivable)['balance']);
        self::assertSame($month . '-20', $claim['confirmed_result']['actual_date']); self::assertSame(date('Y-m'), $claim['confirmed_result']['posting_month']);
        self::assertSame($month . '-20', $claim['confirmed_result']['allocations'][0]['effective_date']);
        self::assertSame(date('Y-m'), $claim['confirmed_result']['allocations'][0]['posting_month']);
        self::assertSame($month . '-20', Db::name('finance_entry')->where('document_id', $claim['id'])->where('purpose', 'allocation')->value('effective_date'));
        $correctedData = array_replace($claimData, ['amount' => '600', 'allocations' => [['source' => $this->receivable, 'amount' => '600']]]);
        $corrected = FinanceBusinessLogic::action('correct', $this->command($claim['version']) + ['id' => $claim['id'], 'payload' => $correctedData, 'correction_reason' => '认领金额误录100，依据客户凭据关联改为600']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError()); self::assertSame('600.00', $ledger->source($fund)['balance']); self::assertSame('400.00', $ledger->source($this->receivable)['balance']);
        $rest = array_replace($claimData, ['amount' => '600', 'advance_amount' => '200', 'allocations' => [['source' => $this->receivable, 'amount' => '400']]]);
        $final = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_customer_claim', 'payload' => $rest]); self::assertNotFalse($final, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->source($fund)['balance']); self::assertSame('200.00', $ledger->categoryBalance('advance', $this->customerId));
        self::assertSame('6200.00', $ledger->account($this->accountId)['balance']); self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertEquals(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        self::assertSame('{"frozen":1200}', Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->where('month', $month)->value('snapshot'));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_customer_claim', 'payload' => $claimData]));
        self::assertSame('0.00', FinanceBusinessLogic::options(['type' => 'unclaimed_customer_claim', 'unclaimed_source' => $fund])['selected_fund']['balance']);
    }

    public function test_unclaimed_claims_enforce_destination_permissions_and_opening_money_is_never_received_twice(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        $data = ['account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '300', 'funds_verified' => 1, 'reason' => '已到账，客户待核实'];
        foreach ([['funds_verified' => 0], ['account_id' => 99999999], ['actual_date' => date('Y-m-d', strtotime('+1 day'))]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_receipt', 'payload' => array_replace($data, $invalid)]));
        }
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_receipt', 'payload' => $data]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $fund = $receipt['confirmed_result']['unclaimed_source'];
        $employee = WorkforceLogic::saveEmployee(['name' => '认领收款员', 'mobile' => '13800009949', 'bind_user_id' => 996949, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.receipt.prepare', 'finance.receipt.confirm']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $claim = ['unclaimed_source' => $fund, 'subject_id' => $this->customerId, 'amount' => '100', 'advance_amount' => '0', 'allocations' => [['source' => $this->receivable, 'amount' => '100']], 'claim_verified' => 1, 'reason' => '核实代付客户'];
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996949; request()->adminId = 0;
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'unclaimed_recovery_claim']));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_equipment_refund_claim', 'payload' => $claim]));
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_customer_claim', 'payload' => $claim]), FinanceBusinessLogic::getError());
        $this->prepareCustomerReportRequestContext();
        $recovery = $ledger->createSource(0, 'recovery', $this->customerId, '200', date('Y-m-d'), null, ['subject_name' => '原坏账追偿']);
        $claim['amount'] = '200'; $claim['allocations'] = [['source' => $recovery, 'amount' => '200']];
        $recovered = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_recovery_claim', 'payload' => $claim]); self::assertNotFalse($recovered, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->source($fund)['balance']); self::assertSame('5300.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('200.00', Db::name('finance_entry')->where('document_id', $recovered['id'])->where('metric', 'recovery_income')->value('amount'));
        $openingDate = date('Y-m-d', strtotime('last day of last month'));
        $openingId = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'activation_date' => date('Y-m-01'), 'opening_item_id' => 995046, 'category' => 'unclaimed', 'subject_id' => $this->accountId, 'amount' => '250',
            'source_snapshot' => json_encode(['id' => 995046, 'historical_date' => $openingDate, 'subject_name' => '原经营账户', 'source_reference' => '期初未认领凭据', 'evidence' => '已包含期初资金', 'details' => ['original_amount' => '400', 'claimed_amount' => '150', 'account_inclusion' => 1]]), 'create_time' => time()]);
        $claim = ['unclaimed_source' => 'o:' . $openingId, 'subject_id' => $this->customerId, 'amount' => '250', 'allocations' => [['source' => $this->receivable, 'amount' => '250']], 'claim_verified' => 1, 'reason' => '期初未认领核实客户'];
        $openingClaim = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_customer_claim', 'payload' => $claim]); self::assertNotFalse($openingClaim, FinanceBusinessLogic::getError());
        self::assertSame($openingDate, $openingClaim['confirmed_result']['actual_date']); self::assertSame('5300.00', $ledger->account($this->accountId)['balance']);
        self::assertNull($openingClaim['confirmed_result']['allocations'][0]['effective_date']);
        self::assertNull(Db::name('finance_entry')->where('document_id', $openingClaim['id'])->where('purpose', 'allocation')->value('effective_date'), '原应收日期未知不能伪造及时付款结论');
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'unclaimed_customer_claim', 'unclaimed_source' => $fund]));
    }

    public function test_unclaimed_refund_claims_keep_original_money_identity_and_only_equipment_reverses_expense(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('bank', $month . '-01');
        $ledger = new FinanceLedger(self::TENANT_ID); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '待认领退款对象']);
        $data = ['account_id' => $this->accountId, 'actual_date' => $month . '-20', 'amount' => '750', 'funds_verified' => 1, 'reason' => '银行到账已核实，用途未知'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_receipt', 'payload' => $data])); self::assertStringContainsString('截图', FinanceBusinessLogic::getError());
        $data += ['missing_evidence_reason' => '历史设备损坏', 'alternative_evidence' => '柜台回单与余额已核验', 'transaction_no' => 'UNCLAIMED-46', 'transaction_scope' => '本店经营银行'];
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_receipt', 'payload' => $data]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        self::assertTrue($receipt['confirmed_result']['money']['evidence']['missing_screenshot']);
        $fund = $receipt['confirmed_result']['unclaimed_source'];
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        foreach (['supplier_refund', 'expense_refund', 'equipment_refund'] as $category) {
            $refund = $ledger->createSource(0, $category, $vendor, '250', $month . '-10', null, ['subject_name' => '退款对象', 'reason' => '已核实退款义务']);
            $claim = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'unclaimed_' . $category . '_claim', 'payload' => ['unclaimed_source' => $fund, 'subject_id' => $vendor,
                'amount' => '250', 'allocations' => [['source' => $refund, 'amount' => '250']], 'claim_verified' => 1, 'reason' => '原到账核实为对应退款']]);
            self::assertNotFalse($claim, FinanceBusinessLogic::getError()); self::assertSame('0.00', $ledger->source($refund)['balance']);
            self::assertSame($category === 'equipment_refund' ? '-250.00' : '0.00', bcadd((string)Db::name('finance_entry')->where('document_id', $claim['id'])->where('metric', 'expense')->sum('amount'), '0', 2));
            self::assertSame(date('Y-m'), $claim['confirmed_result']['posting_month']);
        }
        self::assertSame('0.00', $ledger->source($fund)['balance']); self::assertSame('5750.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => array_replace($data, ['amount' => '700']), 'correction_reason' => '原到账误录']));
        self::assertStringContainsString('后续处理', FinanceBusinessLogic::getError()); self::assertSame('5750.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('{}', Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->where('month', $month)->value('snapshot'));
    }

    public function test_account_transfer_permissions_electronic_proof_and_opening_transit_settlement_are_explicit(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $target = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '目标经营银行账户', 'account_type' => 'bank']); self::assertNotFalse($target, FinanceSetupLogic::getError());
        $targetId = (int)$target['id'];
        $data = ['account_id' => $this->accountId, 'target_account_id' => $targetId, 'amount' => '100', 'actual_date' => date('Y-m-d'), 'source_reference' => 'TRANSFER-45-ROLE', 'transfer_verified' => 1, 'reason' => '核实已经转出'];
        foreach ([['target_account_id' => $this->accountId], ['target_account_id' => 99999999], ['transfer_verified' => 0], ['actual_date' => date('Y-m-d', strtotime('+1 day'))]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_out', 'payload' => array_replace($data, $invalid)]));
        }
        $employee = WorkforceLogic::saveEmployee(['name' => '互转经办', 'mobile' => '13800009948', 'bind_user_id' => 996948, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.transfer.prepare']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996948; request()->adminId = 0;
        $draft = FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => 'account_transfer_out', 'payload' => $data]); self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command($draft['version']) + ['id' => $draft['id']]));
        self::assertSame([], (new FinanceLedger(self::TENANT_ID))->sources('transit', $this->accountId)); $this->prepareCustomerReportRequestContext();
        $out = FinanceBusinessLogic::action('confirm', $this->command($draft['version']) + ['id' => $draft['id']]); self::assertNotFalse($out, FinanceBusinessLogic::getError());
        $source = $out['confirmed_result']['created_sources'][0]; $ledger = new FinanceLedger(self::TENANT_ID);
        $arrival = ['source' => $source, 'account_id' => $targetId, 'amount' => '100', 'actual_date' => date('Y-m-d'), 'transfer_verified' => 1, 'reason' => '目标银行已实际到账'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_arrival', 'payload' => $arrival]));
        self::assertStringContainsString('截图', FinanceBusinessLogic::getError()); self::assertSame('100.00', $ledger->source($source)['balance']);
        $arrival += ['missing_evidence_reason' => '旧设备无法取截图', 'alternative_evidence' => '柜台流水已核实两端'];
        $received = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_arrival', 'payload' => $arrival]); self::assertNotFalse($received, FinanceBusinessLogic::getError());
        self::assertTrue($received['confirmed_result']['money']['evidence']['missing_screenshot']);
        $openingSnapshot = ['id' => 9045, 'subject_name' => '经营账户', 'source_reference' => '启用前在途E45', 'historical_date' => $month . '-02', 'evidence' => '双方流水与未结本金已核实',
            'details' => ['target_account_id' => (string)$targetId, 'principal' => '500.00', 'arrived_amount' => '100.00', 'returned_amount' => '50.00', 'withheld_fee' => '10.00']];
        $openingId = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'opening_item_id' => 9045, 'category' => 'transit', 'subject_id' => $this->accountId, 'amount' => '340.00',
            'activation_date' => $month . '-01', 'source_snapshot' => json_encode($openingSnapshot), 'create_time' => time()]);
        $opening = 'o:' . $openingId;
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $arrival['source'] = $opening; $arrival['amount'] = '340'; $arrival['actual_date'] = $month . '-03';
        $closed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_arrival', 'payload' => $arrival]); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        self::assertSame(date('Y-m'), $closed['confirmed_result']['posting_month']); self::assertSame('0.00', $ledger->source($opening)['balance']);
        $state = FinanceBusinessLogic::options(['type' => 'account_transfer_arrival', 'transfer_source' => $opening]); self::assertNotFalse($state, FinanceBusinessLogic::getError());
        self::assertSame('440.00', $state['selected_transfer']['arrived_amount']); self::assertSame('50.00', $state['selected_transfer']['returned_amount']); self::assertSame('10.00', $state['selected_transfer']['withheld_fee']);
        self::assertSame($openingSnapshot, json_decode(Db::name('finance_opening_source')->where('id', $openingId)->value('source_snapshot'), true));
        self::assertSame('4900.00', $ledger->account($this->accountId)['balance']); self::assertSame('440.00', $ledger->account($targetId)['balance']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'expense')->count());
        self::assertNotFalse(FinanceBusinessLogic::subjects(['type' => 'account_transfer_out']), FinanceBusinessLogic::getError()); self::assertNotFalse(FinanceBusinessLogic::subjects(['type' => 'account_transfer_out', 'role' => 'fee']), FinanceBusinessLogic::getError());
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID); self::assertFalse(FinanceBusinessLogic::options(['type' => 'account_transfer_arrival', 'transfer_source' => $opening]));
    }

    public function test_account_transfers_track_principal_partial_arrival_return_and_fees_without_external_turnover(): void
    {
        $this->activate(); $target = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '互转目标现金账户', 'account_type' => 'cash']); self::assertNotFalse($target, FinanceSetupLogic::getError()); $target['id'] = (int)$target['id'];
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '转账服务方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => ['category_id' => 0, 'expected_category_version' => 0, 'parent' => 'office', 'name' => '资金结算手续费', 'is_enabled' => 1]]); self::assertNotFalse($category, FinanceBusinessLogic::getError());
        $fee = ['fee_amount' => '10', 'fee_verified' => 1, 'fee_category_id' => $category['confirmed_result']['category']['id'], 'expected_fee_category_version' => 1,
            'fee_vendor_id' => $vendor, 'fee_basis' => '服务方已核实收费明细', 'fee_material_status' => 'missing', 'fee_material_verified' => 1, 'fee_missing_material_reason' => '双方现金结算逐项核实'];
        $data = ['account_id' => $this->accountId, 'target_account_id' => $target['id'], 'amount' => '1000', 'actual_date' => date('Y-m-d'),
            'source_reference' => 'TRANSFER-45', 'transfer_verified' => 1, 'reason' => '已实际转出门店资金'] + $fee;
        $command = $this->command(0) + ['type' => 'account_transfer_out', 'payload' => $data];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertContains(['metric' => 'expense', 'posting_month' => date('Y-m'), 'amount' => '10.00'], $preview['impacts']);
        $out = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($out, FinanceBusinessLogic::getError()); self::assertSame($out, FinanceBusinessLogic::action('record', $command));
        $source = $out['confirmed_result']['created_sources'][0]; $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('3990.00', $ledger->account($this->accountId)['balance']); self::assertSame('0.00', $ledger->account($target['id'])['balance']); self::assertSame('1000.00', $ledger->source($source)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_out', 'payload' => $data]));
        $arrival = array_replace($fee, ['fee_amount' => '5', 'source' => $source, 'account_id' => $target['id'], 'amount' => '600', 'actual_date' => date('Y-m-d'), 'transfer_verified' => 1, 'reason' => '第一笔已到账，代扣5元手续费']);
        $received = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_arrival', 'payload' => $arrival]); self::assertNotFalse($received, FinanceBusinessLogic::getError());
        self::assertSame('395.00', $ledger->source($source)['balance']); self::assertSame('600.00', $ledger->account($target['id'])['balance']);
        $wrong = array_replace($arrival, ['account_id' => $this->accountId, 'fee_amount' => '0', 'amount' => '100']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_arrival', 'payload' => $wrong]));
        $return = ['source' => $source, 'account_id' => $this->accountId, 'amount' => '396', 'actual_date' => date('Y-m-d'), 'transfer_verified' => 1, 'reason' => '剩余本金实际退回'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_return', 'payload' => $return]));
        $return['amount'] = '395'; $returned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_return', 'payload' => $return]); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->source($source)['balance']); self::assertSame('4385.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('15.00', bcadd((string)Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'expense')->sum('amount'), '0', 2));
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->count());
        self::assertSame([], $ledger->sources('expense_payable', $vendor));
        $state = FinanceBusinessLogic::options(['type' => 'account_transfer_arrival', 'transfer_source' => $source]); self::assertNotFalse($state, FinanceBusinessLogic::getError());
        self::assertSame('600.00', $state['selected_transfer']['arrived_amount']); self::assertSame('395.00', $state['selected_transfer']['returned_amount']);
        self::assertSame('5.00', $state['selected_transfer']['withheld_fee']); self::assertSame('0.00', $state['selected_transfer']['remaining_amount']);
        $report = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'cash']); self::assertNotFalse($report, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $report['data']['summary']['external_in']); self::assertSame('15.00', $report['data']['summary']['external_out']);
        self::assertSame('4985.00', $report['data']['summary']['closing_accounts']); self::assertSame('0.00', $report['data']['summary']['closing_transit']);
        self::assertCount(3, $report['data']['internal_transfers']);
        $data['amount'] = '10'; $data['fee_amount'] = '0'; $data['source_reference'] = 'TRANSFER-FULL-FEE-51';
        $feeOnlyOut = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_out', 'payload' => $data]); self::assertNotFalse($feeOnlyOut, FinanceBusinessLogic::getError());
        $arrival['source'] = $feeOnlyOut['confirmed_result']['created_sources'][0]; $arrival['amount'] = '0'; $arrival['fee_amount'] = '10';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_transfer_arrival', 'payload' => $arrival]), FinanceBusinessLogic::getError());
        $report = FinanceBusinessLogic::monthlyReport(['month' => date('Y-m'), 'report' => 'cash']);
        self::assertSame('25.00', $report['data']['summary']['external_out']); self::assertCount(5, $report['data']['internal_transfers']);
    }

    public function test_opening_equipment_adjustments_preserve_the_original_proof_and_historical_paid_amount(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '期初设备方']);
        $snapshot = ['id' => 9044, 'subject_name' => '期初设备方', 'source_reference' => '旧设备E44', 'historical_date' => null, 'evidence' => '期初已核实原合同与历史付款',
            'details' => ['original_amount' => '12000.00', 'price_adjustment' => '-1000.00', 'paid_amount' => '2000.00', 'cancelled_amount' => '1000.00']];
        $id = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'opening_item_id' => 9044, 'category' => 'equipment', 'subject_id' => $vendor,
            'amount' => '8000.00', 'activation_date' => date('Y-m-01'), 'source_snapshot' => json_encode($snapshot), 'create_time' => time()]);
        $source = 'o:' . $id; $ledger = new FinanceLedger(self::TENANT_ID);
        $options = FinanceBusinessLogic::options(['type' => 'equipment_adjustment', 'equipment_source' => $source]); self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertNotNull($options['selected_equipment']); self::assertSame('2000.00', $options['selected_equipment']['paid_amount']);
        self::assertSame('11000.00', $options['selected_equipment']['equipment']['amount']); self::assertNull($options['selected_equipment']['equipment']['actual_date']);
        $adjust = ['subject_id' => $vendor, 'equipment_source' => $source, 'expected_revision_id' => 0, 'mode' => 'price', 'new_amount' => '10000',
            'adjustment_verified' => 1, 'reason' => '启用后重新核实剩余总价', 'confirmation_basis' => '双方补充价格约定'];
        $command = $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']); self::assertSame([], $preview['impacts']);
        $price = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($price, FinanceBusinessLogic::getError()); self::assertSame($price, FinanceBusinessLogic::action('record', $command));
        self::assertSame('7000.00', $ledger->source($source)['balance']); self::assertSame('2000.00', $price['confirmed_result']['paid_amount']);
        $pay = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_payment', 'payload' => ['subject_id' => $vendor, 'account_id' => $this->accountId,
            'actual_date' => date('Y-m-d'), 'amount' => '1000', 'reason' => '本月实际支付旧设备款', 'allocations' => [['source' => $source, 'amount' => '1000']]]]); self::assertNotFalse($pay, FinanceBusinessLogic::getError());
        $adjust['mode'] = 'cancel'; $adjust['cancel_amount'] = '6001'; $adjust['expected_revision_id'] = $price['confirmed_result']['revision_id'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust]));
        $adjust['cancel_amount'] = '6000'; $cancel = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust]); self::assertNotFalse($cancel, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->source($source)['balance']); self::assertSame('3000.00', $cancel['confirmed_result']['paid_amount']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust]));
        $list = FinanceBusinessLogic::options(['type' => 'equipment_adjustment', 'subject_id' => $vendor, 'equipment_origin' => 'opening']); self::assertNotFalse($list, FinanceBusinessLogic::getError());
        self::assertSame($source, $list['bills'][0]['equipment_source']); self::assertSame('0.00', $list['bills'][0]['remaining_amount']);
        self::assertSame($snapshot, json_decode(Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('id', $id)->value('source_snapshot'), true));
        self::assertSame(0, Db::name('finance_equipment_purchase')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('4000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('1000.00', bcadd((string)Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'expense')->sum('amount'), '0', 2));
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID); self::assertFalse(FinanceBusinessLogic::options(['type' => 'equipment_adjustment', 'equipment_source' => $source]));
    }

    public function test_equipment_refund_obligation_revisions_and_receipts_never_restore_payment_limit(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '设备退款供应商']);
        $purchase = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_purchase', 'payload' => [
            'subject_id' => $vendor, 'equipment_name' => '设备退款冰柜', 'source_reference' => 'EQUIP-43', 'amount' => '12000', 'actual_date' => date('Y-m-d'),
            'equipment_verified' => 1, 'reason' => '购置', 'confirmation_basis' => '核实合同', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '人工核实']]);
        self::assertNotFalse($purchase, FinanceBusinessLogic::getError()); $equipment = $purchase['confirmed_result']['created_sources'][0]; $ledger = new FinanceLedger(self::TENANT_ID);
        $pay = ['subject_id' => $vendor, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '2000', 'reason' => '实际付款', 'allocations' => [['source' => $equipment, 'amount' => '2000']]];
        $payment = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_payment', 'payload' => $pay]); self::assertNotFalse($payment, FinanceBusinessLogic::getError());
        $cancel = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => [
            'subject_id' => $vendor, 'original_equipment_document_id' => $purchase['id'], 'expected_revision_id' => 0, 'mode' => 'cancel', 'cancel_amount' => '10000',
            'adjustment_verified' => 1, 'reason' => '取消未付', 'confirmation_basis' => '双方取消协议']]); self::assertNotFalse($cancel, FinanceBusinessLogic::getError());
        $due = ['subject_id' => $vendor, 'original_equipment_document_id' => $purchase['id'], 'amount' => '2000', 'actual_date' => date('Y-m-d'),
            'refund_verified' => 1, 'reason' => '设备实际退货退款约定', 'confirmation_basis' => '供应商确认退回已付款'];
        $command = $this->command(0) + ['type' => 'equipment_refund_due', 'payload' => $due];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']); self::assertSame([], $preview['impacts']);
        $refund = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($refund, FinanceBusinessLogic::getError()); self::assertSame($refund, FinanceBusinessLogic::action('record', $command));
        $source = $refund['confirmed_result']['created_sources'][0]; self::assertSame('0.00', $ledger->source($equipment)['balance']); self::assertSame('3000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame($payment['id'], $refund['confirmed_result']['payment_composition'][0]['document_id']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_refund_due', 'payload' => $due]));
        $receive = $pay; $receive['amount'] = '500'; $receive['allocations'] = [['source' => $source, 'amount' => '500']]; $receive['reason'] = '第一笔实际退款到账';
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_refund', 'payload' => $receive]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->source($equipment)['balance']); self::assertSame('3500.00', $ledger->account($this->accountId)['balance']);
        $adjust = ['subject_id' => $vendor, 'original_refund_document_id' => $refund['id'], 'expected_revision_id' => 0, 'new_amount' => '400',
            'refund_verified' => 1, 'reason' => '核实退款总额', 'confirmation_basis' => '补充协议'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_refund_adjustment', 'payload' => $adjust]));
        $adjust['new_amount'] = '2500'; self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_refund_adjustment', 'payload' => $adjust]));
        $adjust['new_amount'] = '1500'; $revised = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_refund_adjustment', 'payload' => $adjust]); self::assertNotFalse($revised, FinanceBusinessLogic::getError());
        self::assertSame('1000.00', $ledger->source($source)['balance']); self::assertSame('500.00', $revised['confirmed_result']['received_amount']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_refund_adjustment', 'payload' => $adjust]));
        $wrongPay = array_replace($pay, ['amount' => '1400', 'allocations' => [['source' => $equipment, 'amount' => '1400']]]);
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command($payment['version']) + ['id' => $payment['id'], 'payload' => $wrongPay, 'correction_reason' => '付款金额误录']));
        self::assertStringContainsString('退款', FinanceBusinessLogic::getError()); self::assertSame('0.00', $ledger->source($equipment)['balance']);
        $receive['amount'] = '1000'; $receive['allocations'][0]['amount'] = '1000';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_refund', 'payload' => $receive]), FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->source($source)['balance']); self::assertSame('0.00', $ledger->source($equipment)['balance']);
        self::assertSame('4500.00', $ledger->account($this->accountId)['balance']); self::assertSame('500.00', bcadd((string)Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'expense')->sum('amount'), '0', 2));
        $current = FinanceBusinessLogic::options(['type' => 'equipment_refund_adjustment', 'original_refund_document_id' => $refund['id']]);
        self::assertSame('1500.00', $current['selected_refund']['received_amount']); self::assertSame([], $current['current_sources']);
        self::assertSame($refund['confirmed_result'], FinanceBusinessLogic::detail(['id' => $refund['id']])['confirmed_result']);
        $correctedPay = array_replace($pay, ['amount' => '1500', 'allocations' => [['source' => $equipment, 'amount' => '1500']]]);
        $corrected = FinanceBusinessLogic::action('correct', $this->command($payment['version']) + ['id' => $payment['id'], 'payload' => $correctedPay, 'correction_reason' => '原付款确实误录500']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError()); self::assertSame('500.00', $ledger->source($equipment)['balance']);
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']); self::assertSame($payment['confirmed_result']['money']['transaction_id'], $corrected['confirmed_result']['money']['transaction_id']);
    }

    public function test_equipment_refund_preparation_requires_owner_confirmation_and_preserves_closed_months(): void
    {
        $lastMonth = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $lastMonth . '-01');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '设备退回权限供应商']);
        $purchase = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_purchase', 'payload' => [
            'subject_id' => $vendor, 'equipment_name' => '设备退款秤', 'source_reference' => 'EQUIP-43-ROLE', 'amount' => '800', 'actual_date' => $lastMonth . '-01',
            'equipment_verified' => 1, 'reason' => '购置', 'confirmation_basis' => '核实合同', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '人工核实']]);
        self::assertNotFalse($purchase, FinanceBusinessLogic::getError()); $source = $purchase['confirmed_result']['created_sources'][0];
        $pay = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_payment', 'payload' => ['subject_id' => $vendor, 'account_id' => $this->accountId,
            'actual_date' => $lastMonth . '-02', 'amount' => '800', 'reason' => '实际设备付款', 'allocations' => [['source' => $source, 'amount' => '800']]]]); self::assertNotFalse($pay, FinanceBusinessLogic::getError());
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $lastMonth, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $data = ['subject_id' => $vendor, 'original_equipment_document_id' => $purchase['id'], 'amount' => '600', 'actual_date' => $lastMonth . '-03', 'refund_verified' => 1, 'reason' => '核实退款', 'confirmation_basis' => '双方退款约定'];
        foreach ([['subject_id' => 99999999], ['amount' => '801'], ['refund_verified' => 0], ['confirmation_basis' => ''], ['actual_date' => date('Y-m-d', strtotime('+1 day'))]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_refund_due', 'payload' => array_replace($data, $invalid)]));
        }
        $employee = WorkforceLogic::saveEmployee(['name' => '设备退款经办', 'mobile' => '13800009947', 'bind_user_id' => 996947, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.equipment.prepare']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996947; request()->adminId = 0;
        $draft = FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => 'equipment_refund_due', 'payload' => $data]); self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        self::assertSame([], (new FinanceLedger(self::TENANT_ID))->sources('equipment_refund', $vendor));
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command($draft['version']) + ['id' => $draft['id']])); $this->prepareCustomerReportRequestContext();
        $due = FinanceBusinessLogic::action('confirm', $this->command($draft['version']) + ['id' => $draft['id']]); self::assertNotFalse($due, FinanceBusinessLogic::getError()); self::assertSame(date('Y-m'), $due['confirmed_result']['posting_month']);
        $refund = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_refund', 'payload' => ['subject_id' => $vendor, 'account_id' => $this->accountId,
            'actual_date' => $lastMonth . '-04', 'amount' => '600', 'reason' => '实际设备退款补录', 'allocations' => [['source' => $due['confirmed_result']['created_sources'][0], 'amount' => '600']]]]); self::assertNotFalse($refund, FinanceBusinessLogic::getError());
        self::assertSame('-600.00', Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $refund['id'])->where('metric', 'expense')->value('amount'));
        self::assertSame(date('Y-m'), Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $refund['id'])->where('metric', 'expense')->value('posting_month'));
        self::assertSame('{}', Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->where('month', $lastMonth)->value('snapshot'));
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID); self::assertFalse(FinanceBusinessLogic::options(['type' => 'equipment_refund_adjustment', 'original_refund_document_id' => $due['id']]));
    }

    public function test_equipment_staff_prepare_but_owner_alone_confirms_and_cancellation_cannot_consume_paid_money(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '设备权限供应商']);
        $employee = WorkforceLogic::saveEmployee(['name' => '设备经办', 'mobile' => '13800009946', 'bind_user_id' => 996946,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.equipment.prepare']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $data = ['subject_id' => $vendor, 'equipment_name' => '门店电子秤', 'source_reference' => 'EQUIP-42-ROLE', 'amount' => '500', 'actual_date' => date('Y-m-d'),
            'equipment_verified' => 1, 'reason' => '购置电子秤', 'confirmation_basis' => '核实总价', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '核实合同'];
        foreach ([['equipment_verified' => 0], ['equipment_name' => ''], ['subject_id' => 999999999], ['actual_date' => date('Y-m-d', strtotime('+1 day'))]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_purchase', 'payload' => array_replace($data, $invalid)]));
        }
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996946; request()->adminId = 0;
        $draft = FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => 'equipment_purchase', 'payload' => $data]); self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command($draft['version']) + ['id' => $draft['id']]));
        self::assertSame([], (new FinanceLedger(self::TENANT_ID))->sources('equipment', $vendor));
        $this->prepareCustomerReportRequestContext();
        $purchase = FinanceBusinessLogic::action('confirm', $this->command($draft['version']) + ['id' => $draft['id']]); self::assertNotFalse($purchase, FinanceBusinessLogic::getError());
        $adjust = ['subject_id' => $vendor, 'original_equipment_document_id' => $purchase['id'], 'expected_revision_id' => 0, 'mode' => 'cancel', 'cancel_amount' => '501',
            'adjustment_verified' => 1, 'reason' => '取消未付采购', 'confirmation_basis' => '双方取消约定'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust]));
        $adjust['cancel_amount'] = '500';
        $cancelled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust]); self::assertNotFalse($cancelled, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $cancelled['confirmed_result']['remaining_amount']); self::assertSame('0.00', $cancelled['confirmed_result']['paid_amount']);
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(FinanceBusinessLogic::detail(['id' => $purchase['id']])); self::assertFalse(FinanceBusinessLogic::options(['type' => 'equipment_purchase', 'original_equipment_document_id' => $purchase['id']]));
    }

    public function test_equipment_purchase_sets_a_payment_limit_and_only_actual_payments_become_expense(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '设备购置供应商']);
        $data = ['subject_id' => $vendor, 'equipment_name' => '经营冰柜', 'source_reference' => 'EQUIP-42', 'amount' => '4000', 'actual_date' => date('Y-m-d'),
            'equipment_verified' => 1, 'reason' => '经营用冰柜购置', 'confirmation_basis' => '双方核实购置合同', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '核实合同金额与设备'];
        $command = $this->command(0) + ['type' => 'equipment_purchase', 'payload' => $data];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']); self::assertSame([], $preview['impacts']);
        $purchase = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($purchase, FinanceBusinessLogic::getError()); self::assertSame($purchase, FinanceBusinessLogic::action('record', $command));
        $source = $purchase['confirmed_result']['created_sources'][0]; $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('4000.00', $ledger->source($source)['balance']); self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame([], $ledger->sources('expense_payable', $vendor));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_purchase', 'payload' => $data]));
        $pay = ['subject_id' => $vendor, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '1000', 'reason' => '设备首笔实际付款', 'allocations' => [['source' => $source, 'amount' => '1000']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $pay]));
        $paymentCommand = $this->command(0) + ['type' => 'equipment_payment', 'payload' => $pay];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($paymentCommand + ['action' => 'record']);
        self::assertContains(['metric' => 'expense', 'posting_month' => date('Y-m'), 'amount' => '1000.00'], $preview['impacts']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $paymentCommand), FinanceBusinessLogic::getError());
        $adjust = ['subject_id' => $vendor, 'original_equipment_document_id' => $purchase['id'], 'expected_revision_id' => 0, 'mode' => 'price', 'new_amount' => '3500',
            'adjustment_verified' => 1, 'reason' => '未付范围价格下调', 'confirmation_basis' => '补充价格确认'];
        $adjustCommand = $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($adjustCommand + ['action' => 'record']); self::assertSame([], $preview['impacts']);
        $price = FinanceBusinessLogic::action('record', $adjustCommand); self::assertNotFalse($price, FinanceBusinessLogic::getError());
        self::assertSame('2500.00', $price['confirmed_result']['remaining_amount']); self::assertSame('1000.00', $price['confirmed_result']['paid_amount']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust]));
        $adjust['expected_revision_id'] = $price['confirmed_result']['revision_id']; $adjust['new_amount'] = '900';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust]));
        $adjust['mode'] = 'cancel'; $adjust['cancel_amount'] = '500';
        $cancelled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_adjustment', 'payload' => $adjust]); self::assertNotFalse($cancelled, FinanceBusinessLogic::getError());
        self::assertSame('2000.00', $cancelled['confirmed_result']['remaining_amount']); self::assertSame('500.00', $cancelled['confirmed_result']['equipment']['cancelled_amount']);
        $pay['amount'] = '2001'; $pay['allocations'][0]['amount'] = '2001';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_payment', 'payload' => $pay]));
        $pay['amount'] = '2000'; $pay['allocations'][0]['amount'] = '2000';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'equipment_payment', 'payload' => $pay]), FinanceBusinessLogic::getError());
        self::assertSame('2000.00', $ledger->account($this->accountId)['balance']);
        $current = FinanceBusinessLogic::options(['type' => 'equipment_purchase', 'original_equipment_document_id' => $purchase['id']]);
        self::assertSame([], $current['current_sources']); self::assertSame('3000.00', $current['selected_equipment']['paid_amount']);
        self::assertSame($purchase['confirmed_result'], FinanceBusinessLogic::detail(['id' => $purchase['id']])['confirmed_result']);
    }

    public function test_zero_salary_can_gain_an_obligation_once_and_adjustment_reads_remain_private(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '零工资复核员工', 'mobile' => '13800009945', 'bind_user_id' => 996945,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.salary.view']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'personnel', 'name' => '零工资复核类别', 'is_enabled' => 1]]); self::assertNotFalse($category, FinanceBusinessLogic::getError());
        $data = ['subject_id' => $employee['id'], 'benefit_month' => date('Y-m'), 'amount' => '0', 'source_reference' => 'SALARY-41-ZERO', 'salary_verified' => 1,
            'confirmation_basis' => '初次核定结果', 'reason' => '当月工资', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '已经核实', 'lines' => []];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_expense', 'payload' => $data]); self::assertNotFalse($original, FinanceBusinessLogic::getError());
        $adjust = ['subject_id' => $employee['id'], 'original_expense_document_id' => $original['id'], 'expected_revision_id' => 0, 'benefit_month' => date('Y-m'),
            'new_amount' => '500', 'adjustment_verified' => 1, 'reason' => '外部复核补增工资', 'confirmation_basis' => '最终核定修正表',
            'lines' => [['category_id' => $category['confirmed_result']['category']['id'], 'expected_category_version' => 1, 'amount' => '500', 'reason' => '人员工资']]];
        $command = $this->command(0) + ['type' => 'salary_adjustment', 'payload' => $adjust];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']); self::assertSame('500.00', $preview['balances'][0]['change']);
        self::assertSame([], (new FinanceLedger(self::TENANT_ID))->sources('salary', $employee['id']));
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError()); self::assertSame($result, FinanceBusinessLogic::action('record', $command));
        $source = $result['confirmed_result']['created_sources'][0]; self::assertSame('0.00', $result['confirmed_result']['paid_amount']);
        $adjust['expected_revision_id'] = $result['confirmed_result']['revision_id']; $adjust['new_amount'] = '0'; $adjust['lines'] = [];
        $zero = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_adjustment', 'payload' => $adjust]); self::assertNotFalse($zero, FinanceBusinessLogic::getError());
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'salary_expense', 'original_expense_document_id' => $original['id']])['current_sources']);
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->source($source)['balance']);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996945; request()->adminId = 0;
        self::assertNotFalse(FinanceBusinessLogic::detail(['id' => $zero['id']]), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'salary_adjustment'])['can_prepare']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_adjustment', 'payload' => $adjust]));
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.salary.view')->delete();
        self::assertFalse(FinanceBusinessLogic::detail(['id' => $zero['id']])); self::assertFalse(FinanceBusinessLogic::options(['type' => 'salary_adjustment']));
    }

    public function test_salary_adjustment_preserves_paid_wages_and_changes_only_remaining_obligation(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $employee = WorkforceLogic::saveEmployee(['name' => '工资调整员工', 'mobile' => '13800009944', 'bind_user_id' => 996944,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => []]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'personnel', 'name' => '工资调整类别', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id'];
        $data = ['subject_id' => $employee['id'], 'benefit_month' => $month, 'amount' => '2000', 'source_reference' => 'PAYROLL-41',
            'salary_verified' => 1, 'confirmation_basis' => '外部核定工资', 'reason' => '当月工资', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '核实结果',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '2000', 'reason' => '人员工资']]];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_expense', 'payload' => $data]); self::assertNotFalse($original, FinanceBusinessLogic::getError());
        $source = $original['confirmed_result']['created_sources'][0];
        $payment = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_payment', 'payload' => [
            'subject_id' => $employee['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '1200', 'reason' => '已实际发放', 'allocations' => [['source' => $source, 'amount' => '1200']]]]);
        self::assertNotFalse($payment, FinanceBusinessLogic::getError());
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $adjust = ['subject_id' => $employee['id'], 'new_subject_id' => $employee['id'], 'original_expense_document_id' => $original['id'], 'expected_revision_id' => 0,
            'benefit_month' => $month, 'new_amount' => '1500', 'adjustment_verified' => 1, 'reason' => '核定总额修正', 'confirmation_basis' => '外部复核最终结果',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '1500', 'reason' => '人员工资']]];
        $command = $this->command(0) + ['type' => 'salary_adjustment', 'payload' => $adjust];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame([['metric' => 'expense', 'posting_month' => date('Y-m'), 'amount' => '-500.00']], $preview['impacts']);
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError()); self::assertSame($result, FinanceBusinessLogic::action('record', $command));
        self::assertSame('1200.00', $result['confirmed_result']['paid_amount']); self::assertSame('300.00', $result['confirmed_result']['balance_changes'][0]['after']);
        self::assertSame('3800.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_adjustment', 'payload' => $adjust]));
        $adjust['expected_revision_id'] = $result['confirmed_result']['revision_id'];
        foreach ([['new_amount' => '1100'], ['new_subject_id' => 999999999], ['benefit_month' => date('Y-m')]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_adjustment', 'payload' => array_replace($adjust, $invalid)]));
        }
        $options = FinanceBusinessLogic::options(['type' => 'salary_adjustment', 'original_expense_document_id' => $original['id']]);
        self::assertSame('1500.00', $options['bills'][0]['expense']['amount']); self::assertSame('1200.00', $options['bills'][0]['paid_amount']);
        $adjust['new_amount'] = '1800'; $adjust['lines'][0]['amount'] = '1800';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_adjustment', 'payload' => $adjust]), FinanceBusinessLogic::getError());
        self::assertSame('600.00', FinanceBusinessLogic::options(['type' => 'salary_expense', 'original_expense_document_id' => $original['id']])['current_sources'][0]['balance']);
        self::assertSame($original['confirmed_result'], FinanceBusinessLogic::detail(['id' => $original['id']])['confirmed_result']);
        self::assertSame($payment['confirmed_result'], FinanceBusinessLogic::detail(['id' => $payment['id']])['confirmed_result']);
    }

    public function test_salary_view_only_can_read_results_payments_and_private_material_without_preparation_rights(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '工资仅查看员工', 'mobile' => '13800009943', 'bind_user_id' => 996943,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.salary.view']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $documents = []; $proofs = [];
        foreach (['salary_expense', 'salary_payment', 'salary_adjustment'] as $type) {
            $document = FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => $type, 'payload' => ['subject_id' => $employee['id']]]);
            self::assertNotFalse($document, FinanceBusinessLogic::getError()); $documents[] = $document;
            $path = tempnam(sys_get_temp_dir(), 'salary-read-');
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQ0AAAAASUVORK5CYII='));
            $proofs[] = \app\api\jxc\logic\FinanceEvidence::save($type, new \think\file\UploadedFile($path, '工资核验.png', 'image/png', null, true));
        }
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996943; request()->adminId = 0;
        foreach ($documents as $index => $document) {
            self::assertNotFalse(FinanceBusinessLogic::detail(['id' => $document['id']]), FinanceBusinessLogic::getError());
            self::assertFalse(FinanceBusinessLogic::options(['type' => $document['type']])['can_prepare']);
            self::assertSame('image/png', \app\api\jxc\logic\FinanceEvidence::content($proofs[$index]['id'])['mime']);
            self::assertFalse(FinanceBusinessLogic::action('reopen', $this->command($document['version']) + ['id' => $document['id']]));
            try { \app\api\jxc\logic\FinanceEvidence::save($document['type'], null); self::fail('查看权限不允许上传'); }
            catch (\DomainException $error) { self::assertStringContainsString('权限', $error->getMessage()); }
        }
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.salary.view')->delete();
        foreach ($proofs as $proof) {
            try { \app\api\jxc\logic\FinanceEvidence::content($proof['id']); self::fail('撤销工资查看后不能再读材料'); }
            catch (\DomainException $error) { self::assertStringContainsString('权限', $error->getMessage()); }
        }
    }

    public function test_salary_details_require_private_access_and_only_owner_confirms_external_final_results(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $employee = WorkforceLogic::saveEmployee(['name' => '工资权限员工', 'mobile' => '13800009942', 'bind_user_id' => 996942,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.salary.prepare', 'finance.salary.view']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $data = ['subject_id' => $employee['id'], 'benefit_month' => $month, 'amount' => '0', 'source_reference' => 'PAYROLL-ZERO',
            'salary_verified' => 1, 'confirmation_basis' => '系统外核定该月工资为零', 'reason' => '当月无应付工资', 'lines' => [],
            'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '店主核实当月最终结果'];
        foreach ([['salary_verified' => 0], ['amount_status' => 'estimated'], ['confirmation_basis' => ''], ['benefit_month' => date('Y-m', strtotime('first day of next month'))]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_expense', 'payload' => array_replace($data, $invalid)]));
        }
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996942; request()->adminId = 0;
        $prepared = FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => 'salary_expense', 'payload' => $data]); self::assertNotFalse($prepared, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command($prepared['version']) + ['id' => $prepared['id']]));
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.salary.prepare')->delete();
        self::assertNotFalse(FinanceBusinessLogic::detail(['id' => $prepared['id']]), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'salary_expense'])['can_prepare']);
        self::assertFalse(FinanceBusinessLogic::action('save', $this->command($prepared['version']) + ['id' => $prepared['id'], 'payload' => $data]));
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.salary.view')->delete();
        self::assertFalse(FinanceBusinessLogic::detail(['id' => $prepared['id']])); self::assertFalse(FinanceBusinessLogic::lists(['type' => 'salary_expense']));
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'salary_expense'])); self::assertFalse(FinanceBusinessLogic::subjects(['type' => 'salary_expense']));
        self::assertNotContains('salary_expense', array_column(FinanceBusinessLogic::catalog()['types'], 'type'));
        $this->prepareCustomerReportRequestContext();
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $command = $this->command($prepared['version']) + ['id' => $prepared['id']];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'confirm']); self::assertSame([], $preview['impacts']);
        $confirmed = FinanceBusinessLogic::action('confirm', $command); self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $confirmed['confirmed_result']['amount']); self::assertSame(date('Y-m'), $confirmed['confirmed_result']['posting_month']);
        self::assertSame([], $confirmed['confirmed_result']['created_sources']);
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'salary_expense', 'original_expense_document_id' => $confirmed['id']])['current_sources']);
    }

    public function test_final_salary_results_accrue_personnel_expense_once_and_pay_without_repeating_expense(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $employee = WorkforceLogic::saveEmployee(['name' => '工资核定员工', 'mobile' => '13800009941', 'bind_user_id' => 996941,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.salary.prepare', 'finance.salary.view']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'personnel', 'name' => '核定工资', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError());
        $data = ['subject_id' => $employee['id'], 'benefit_month' => $month, 'amount' => '2000', 'source_reference' => 'PAYROLL-40',
            'salary_verified' => 1, 'confirmation_basis' => '系统外已核定的最终工资结果', 'reason' => '当月工资',
            'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '店主核对最终工资清单',
            'lines' => [['category_id' => $category['confirmed_result']['category']['id'], 'expected_category_version' => 1, 'amount' => '2000', 'reason' => '当月人员费用']]];
        $command = $this->command(0) + ['type' => 'salary_expense', 'payload' => $data];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame([['metric' => 'expense', 'posting_month' => $month, 'amount' => '2000.00']], $preview['impacts']);
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame([], $ledger->sources('salary', $employee['id']));
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame($result, FinanceBusinessLogic::action('record', $command)); $source = $result['confirmed_result']['created_sources'][0];
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']); self::assertSame('2000.00', $ledger->source($source)['balance']);
        self::assertSame($month, $result['confirmed_result']['benefit_month']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_expense', 'payload' => array_replace($data, ['source_reference' => 'OTHER-REF'])]));
        $pay = ['subject_id' => $employee['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '1200', 'reason' => '首笔发放', 'allocations' => [['source' => $source, 'amount' => '1200']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'reimbursement_payment', 'payload' => $pay]));
        $paymentCommand = $this->command(0) + ['type' => 'salary_payment', 'payload' => $pay];
        $paymentPreview = \app\api\jxc\logic\FinancePreview::calculate($paymentCommand + ['action' => 'record']);
        self::assertNotContains('expense', array_column($paymentPreview['impacts'], 'metric'));
        self::assertNotFalse(FinanceBusinessLogic::action('record', $paymentCommand), FinanceBusinessLogic::getError());
        $current = FinanceBusinessLogic::options(['type' => 'salary_expense', 'original_expense_document_id' => $result['id']]);
        self::assertSame('800.00', $current['current_sources'][0]['balance']);
        $pay['amount'] = '801'; $pay['allocations'][0]['amount'] = '801';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_payment', 'payload' => $pay]));
        $pay['amount'] = '800'; $pay['allocations'][0]['amount'] = '800';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_payment', 'payload' => $pay]), FinanceBusinessLogic::getError());
        self::assertSame('3000.00', $ledger->account($this->accountId)['balance']); self::assertSame('0.00', $ledger->source($source)['balance']);
        self::assertSame($result['confirmed_result'], FinanceBusinessLogic::detail(['id' => $result['id']])['confirmed_result']);
    }

    public function test_employee_expense_adjustment_preserves_paid_amount_and_recomputes_remaining_reimbursement(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $employee = WorkforceLogic::saveEmployee(['name' => '垫付调整员工', 'mobile' => '13800009940', 'bind_user_id' => 996940,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => []]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'office', 'name' => '垫付调整耗材', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id'];
        $expense = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'employee_expense', 'payload' => [
            'subject_id' => $employee['id'], 'actual_date' => $month . '-05', 'benefit_month' => $month, 'amount' => '500', 'source_reference' => 'EMP-39',
            'advance_verified' => 1, 'reason' => '员工实际垫付', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '核实实购清单',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '500', 'reason' => '当月耗用']]]]);
        self::assertNotFalse($expense, FinanceBusinessLogic::getError()); $source = $expense['confirmed_result']['created_sources'][0];
        $pay = ['subject_id' => $employee['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '300', 'reason' => '先报销300', 'allocations' => [['source' => $source, 'amount' => '300']]];
        $payment = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'reimbursement_payment', 'payload' => $pay]); self::assertNotFalse($payment, FinanceBusinessLogic::getError());
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $data = ['subject_id' => $employee['id'], 'new_subject_id' => $employee['id'], 'original_expense_document_id' => $expense['id'], 'expected_revision_id' => 0,
            'new_amount' => '400', 'benefit_month' => $month, 'adjustment_verified' => 1, 'reason' => '核对后减少100', 'confirmation_basis' => '实际耗材核对表',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '400', 'reason' => '本月实际耗用']]];
        $command = $this->command(0) + ['type' => 'employee_expense_adjustment', 'payload' => $data];
        $adjusted = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($adjusted, FinanceBusinessLogic::getError()); self::assertSame($adjusted, FinanceBusinessLogic::action('record', $command));
        self::assertSame('-100.00', $adjusted['confirmed_result']['amount_change']); self::assertSame('300.00', $adjusted['confirmed_result']['reimbursed_amount']);
        self::assertSame([date('Y-m')], $adjusted['confirmed_result']['posting_months']);
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('100.00', $ledger->source($source)['balance']); self::assertSame('4700.00', $ledger->account($this->accountId)['balance']);
        $options = FinanceBusinessLogic::options(['type' => 'employee_expense_adjustment', 'original_expense_document_id' => $expense['id']]);
        self::assertSame('400.00', $options['bills'][0]['expense']['amount']); self::assertSame('300.00', $options['bills'][0]['reimbursed_amount']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'employee_expense_adjustment', 'payload' => $data]), '旧费用版本不得重复更正');
        $data['expected_revision_id'] = $options['bills'][0]['expected_revision_id']; $data['new_amount'] = '0'; $data['lines'] = [];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'employee_expense_adjustment', 'payload' => $data]), '不得把确认总额减到累计有效报销以下');
        $data['new_amount'] = '600'; $data['lines'] = [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '600', 'reason' => '补充遗漏耗用']];
        $command = $this->command(0) + ['type' => 'employee_expense_adjustment', 'payload' => $data];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']); self::assertSame('200.00', $preview['expense']['amount_change']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $command), FinanceBusinessLogic::getError()); self::assertSame('300.00', $ledger->source($source)['balance']);
        $pay['amount'] = '301'; $pay['allocations'][0]['amount'] = '301'; self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'reimbursement_payment', 'payload' => $pay]));
        $pay['amount'] = '300'; $pay['allocations'][0]['amount'] = '300'; $pay['duplicate_risk_reason'] = '核实为两次分别支付的300元报销'; self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'reimbursement_payment', 'payload' => $pay]), FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->source($source)['balance']); self::assertSame('4400.00', $ledger->account($this->accountId)['balance']);
        self::assertSame($expense['confirmed_result'], FinanceBusinessLogic::detail(['id' => $expense['id']])['confirmed_result']);
        self::assertSame($payment['confirmed_result'], FinanceBusinessLogic::detail(['id' => $payment['id']])['confirmed_result']);
    }

    public function test_employee_advance_requires_actual_verified_cost_and_owner_confirmation_without_borrowing(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $employee = WorkforceLogic::saveEmployee(['name' => '垫付经办员工', 'mobile' => '13800009939', 'bind_user_id' => 996939,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.reimbursement.prepare']]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'office', 'name' => '垫付归属验证', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError());
        $data = ['subject_id' => $employee['id'], 'actual_date' => $month . '-05', 'benefit_month' => $month, 'amount' => '100', 'source_reference' => 'EMP-38-VERIFY',
            'advance_verified' => 1, 'reason' => '实际垫付耗材', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '已人工核实',
            'lines' => [['category_id' => $category['confirmed_result']['category']['id'], 'expected_category_version' => 1, 'amount' => '100', 'reason' => '本月耗用']]];
        foreach ([['advance_verified' => 0], ['amount_status' => 'estimated'], ['subject_id' => 999999999], ['material_verified' => 0], ['benefit_month' => date('Y-m', strtotime('first day of next month'))]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'employee_expense', 'payload' => array_replace($data, $invalid)]));
        }
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996939; request()->adminId = 0;
        $prepared = FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => 'employee_expense', 'payload' => $data]); self::assertNotFalse($prepared, FinanceBusinessLogic::getError());
        self::assertSame([], (new FinanceLedger(self::TENANT_ID))->sources('reimbursement', $employee['id']));
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command($prepared['version']) + ['id' => $prepared['id']]));
        $this->prepareCustomerReportRequestContext();
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $command = $this->command($prepared['version']) + ['id' => $prepared['id']];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'confirm']);
        self::assertSame([['metric' => 'expense', 'posting_month' => date('Y-m'), 'amount' => '100.00']], $preview['impacts']);
        $confirmed = FinanceBusinessLogic::action('confirm', $command); self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertSame('100.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('reimbursement', $employee['id']));
        self::assertSame($month, $confirmed['confirmed_result']['benefit_month']);
    }

    public function test_employee_advances_create_separate_expenses_and_combine_partial_reimbursements_without_extra_expense(): void
    {
        $this->activate(); $employee = WorkforceLogic::saveEmployee(['name' => '垫付员工', 'mobile' => '13800009938', 'bind_user_id' => 996938,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => []]); self::assertNotFalse($employee, WorkforceLogic::getError());
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'office', 'name' => '垫付办公耗材', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id']; $records = [];
        foreach (['300', '500'] as $index => $amount) {
            $data = ['subject_id' => $employee['id'], 'actual_date' => date('Y-m-d'), 'benefit_month' => date('Y-m'), 'amount' => $amount,
                'source_reference' => 'EMP-38-' . $index, 'reason' => '员工已代门店支付实际耗材', 'advance_verified' => 1,
                'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '经手人与员工核对真实垫付',
                'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => $amount, 'reason' => '本月耗用']]];
            $command = $this->command(0) + ['type' => 'employee_expense', 'payload' => $data];
            $record = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($record, FinanceBusinessLogic::getError());
            self::assertSame($record, FinanceBusinessLogic::action('record', $command)); $records[] = $record;
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'employee_expense', 'payload' => $data]), '同员工同来源垫付不能重复');
        }
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('800.00', $ledger->categoryBalance('reimbursement', $employee['id']));
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        $first = $records[0]['confirmed_result']['created_sources'][0]; $second = $records[1]['confirmed_result']['created_sources'][0];
        $payment = ['subject_id' => $employee['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '600', 'reason' => '两笔垫付合并分次报销',
            'allocations' => [['source' => $first, 'amount' => '300'], ['source' => $second, 'amount' => '300']]];
        $command = $this->command(0) + ['type' => 'reimbursement_payment', 'payload' => $payment];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertNotContains('expense', array_column($preview['impacts'], 'metric'), '报销付款不重复计费');
        $paid = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($paid, FinanceBusinessLogic::getError()); self::assertSame($paid, FinanceBusinessLogic::action('record', $command));
        self::assertSame('0.00', $ledger->source($first)['balance']); self::assertSame('200.00', $ledger->source($second)['balance']);
        $payment['amount'] = '201'; $payment['allocations'] = [['source' => $second, 'amount' => '201']];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'reimbursement_payment', 'payload' => $payment]));
        $payment['amount'] = '200'; $payment['allocations'][0]['amount'] = '200';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'salary_payment', 'payload' => $payment]), '工资不得核销垫付');
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'reimbursement_payment', 'payload' => $payment]), FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->categoryBalance('reimbursement', $employee['id'])); self::assertSame('4200.00', $ledger->account($this->accountId)['balance']);
        self::assertSame($records[1]['confirmed_result'], FinanceBusinessLogic::detail(['id' => $records[1]['id']])['confirmed_result']);
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'employee_expense', 'original_expense_document_id' => $records[1]['id']])['current_sources']);
    }

    public function test_recurring_paid_expense_can_be_cancelled_and_restated_without_recreating_payment(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '周期重述服务方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'utilities', 'name' => '周期重述水费', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id'];
        $plan = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_plan', 'payload' => ['subject_id' => $vendor,
            'category_id' => $categoryId, 'expected_category_version' => 1, 'source_reference' => 'CYCLE-37', 'service_start' => $month, 'service_end' => $month,
            'interval_months' => 1, 'reason' => '每月核实', 'plan_verified' => 1]]);
        self::assertNotFalse($plan, FinanceBusinessLogic::getError()); $planId = $plan['confirmed_result']['plan_id'];
        $base = ['subject_id' => $vendor, 'recurring_plan_id' => $planId, 'expected_plan_version' => 1, 'expected_month_revision_id' => 0, 'benefit_month' => $month];
        $expense = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $base + [
            'actual_date' => $month . '-05', 'amount' => '1000', 'source_reference' => 'CYCLE-37/' . $month, 'due_mode' => 'unspecified',
            'reason' => '原核实费用', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '人工核实表数',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '1000', 'reason' => '当月费用']]]]);
        self::assertNotFalse($expense, FinanceBusinessLogic::getError());
        $payment = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => ['subject_id' => $vendor,
            'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '600', 'reason' => '部分付款',
            'allocations' => [['source' => $expense['confirmed_result']['created_sources'][0], 'amount' => '600']]]]);
        self::assertNotFalse($payment, FinanceBusinessLogic::getError());
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $data = $base + ['correction_mode' => 'cancel_expense', 'correction_verified' => 1, 'expected_expense_revision_id' => 0, 'reason' => '经核实本月并未发生，原录入有误'];
        $command = $this->command(0) + ['type' => 'expense_recurring_correct', 'payload' => $data];
        $cancel = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($cancel, FinanceBusinessLogic::getError());
        self::assertSame($cancel, FinanceBusinessLogic::action('record', $command));
        self::assertSame('-1000.00', $cancel['confirmed_result']['amount_change']);
        self::assertSame([date('Y-m')], $cancel['confirmed_result']['posting_months']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('0.00', $ledger->categoryBalance('expense_payable', $vendor));
        self::assertSame('600.00', $ledger->categoryBalance('expense_refund', $vendor));
        self::assertSame('4400.00', $ledger->account($this->accountId)['balance']);
        $row = FinanceBusinessLogic::options(['type' => 'expense_recurring_plan', 'recurring_plan_id' => $planId])['selected_plan']['months'][0];
        self::assertSame('none', $row['status']); self::assertSame($expense['id'], $row['expense_document_id']);
        $generic = ['subject_id' => $vendor, 'new_subject_id' => $vendor, 'original_expense_document_id' => $expense['id'],
            'expected_revision_id' => $row['expected_expense_revision_id'], 'new_amount' => '800', 'benefit_month' => $month,
            'reason' => '绕过周期状态恢复费用', 'confirmation_basis' => '重复来源', 'adjustment_verified' => 1,
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '800', 'reason' => '费用']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_adjustment', 'payload' => $generic]), '普通调整不能绕过本月不发生状态恢复费用');
        $data['expected_month_revision_id'] = $row['month_revision_id']; $data['correction_mode'] = 'reopen_none';
        $reopen = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_correct', 'payload' => $data]); self::assertNotFalse($reopen, FinanceBusinessLogic::getError());
        foreach ([[1, 0], [2, 1]] as [$version, $enabled]) {
            self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
                'category_id' => $categoryId, 'expected_category_version' => $version, 'parent' => 'utilities', 'name' => '周期重述水费', 'is_enabled' => $enabled]]), FinanceBusinessLogic::getError());
        }
        $row = FinanceBusinessLogic::options(['type' => 'expense_recurring_plan', 'recurring_plan_id' => $planId])['selected_plan']['months'][0];
        $data['correction_mode'] = 'restate_expense'; $data['new_amount'] = '800';
        $data['expected_month_revision_id'] = $row['month_revision_id'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_correct', 'payload' => $data]), '不能沿用取消前费用版本');
        $data['expected_expense_revision_id'] = $row['expected_expense_revision_id'];
        $command = $this->command(0) + ['type' => 'expense_recurring_correct', 'payload' => $data];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame('800.00', $preview['expense']['amount_change']);
        $restated = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($restated, FinanceBusinessLogic::getError());
        self::assertSame($restated, FinanceBusinessLogic::action('record', $command));
        self::assertSame('200.00', $ledger->categoryBalance('expense_payable', $vendor)); self::assertSame('0.00', $ledger->categoryBalance('expense_refund', $vendor));
        self::assertSame('4400.00', $ledger->account($this->accountId)['balance']);
        $row = FinanceBusinessLogic::options(['type' => 'expense_recurring_plan', 'recurring_plan_id' => $planId])['selected_plan']['months'][0];
        self::assertSame('expense', $row['status']); self::assertSame($expense['id'], $row['expense_document_id']); self::assertCount(4, $row['history']);
        self::assertSame($expense['confirmed_result'], FinanceBusinessLogic::detail(['id' => $expense['id']])['confirmed_result']);
        self::assertSame($payment['confirmed_result'], FinanceBusinessLogic::detail(['id' => $payment['id']])['confirmed_result']);
    }

    public function test_recurring_estimate_must_be_resolved_before_cancellation_even_when_final_is_zero(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '周期暂估取消方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'utilities', 'name' => '周期暂估核实', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id'];
        $plan = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_plan', 'payload' => ['subject_id' => $vendor,
            'category_id' => $categoryId, 'expected_category_version' => 1, 'source_reference' => 'CYCLE-37-ZERO', 'service_start' => $month, 'service_end' => $month,
            'interval_months' => 1, 'reason' => '按月核实', 'plan_verified' => 1]]);
        self::assertNotFalse($plan, FinanceBusinessLogic::getError()); $planId = $plan['confirmed_result']['plan_id'];
        $closing = FinanceBusinessLogic::periodAction('preview', ['month' => $month]);
        $closed = FinanceBusinessLogic::periodAction('close', $this->command(0) + ['month' => $month, 'mode' => 'estimated', 'acknowledge_unresolved' => 1, 'expected_fingerprint' => $closing['fingerprint'], 'reason' => '周期费用待最终核实']); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        $base = ['subject_id' => $vendor, 'recurring_plan_id' => $planId, 'expected_plan_version' => 1, 'expected_month_revision_id' => 0, 'benefit_month' => $month];
        $expense = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $base + [
            'actual_date' => date('Y-m-d'), 'amount' => '300', 'amount_status' => 'estimated', 'estimate_basis_type' => 'history', 'estimate_basis' => '上月实际用量',
            'source_reference' => 'CYCLE-37-ZERO/' . $month, 'due_mode' => 'unspecified', 'reason' => '暂估本月',
            'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '等正式账单',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '300', 'reason' => '暂估']]]]);
        self::assertNotFalse($expense, FinanceBusinessLogic::getError());
        $tracking = FinanceBusinessLogic::periodAction('followups', ['month' => $month]);
        $item = array_values(array_filter($tracking['items'], static fn(array $row): bool => $row['original']['category'] === 'recurring_expense'))[0]; self::assertSame('partial', $item['current']['status']);
        $data = $base + ['correction_mode' => 'cancel_expense', 'correction_verified' => 1, 'expected_expense_revision_id' => 0, 'reason' => '本月未发生'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_correct', 'payload' => $data]));
        $resolved = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_estimate_final', 'payload' => [
            'subject_id' => $vendor, 'original_expense_document_id' => $expense['id'], 'expected_revision_id' => 0, 'expected_resolution_id' => 0,
            'final_verified' => 1, 'reason' => '本月停业账单为零', 'resolutions' => [['category_id' => $categoryId, 'final_amount' => '0', 'confirmation_basis' => '最终账单']]]]);
        self::assertNotFalse($resolved, FinanceBusinessLogic::getError());
        $tracking = FinanceBusinessLogic::periodAction('followups', ['month' => $month]);
        $item = array_values(array_filter($tracking['items'], static fn(array $row): bool => $row['original']['category'] === 'recurring_expense'))[0]; self::assertSame('resolved', $item['current']['status']);
        self::assertCount(2, $item['current']['evidence']); self::assertSame($closed['snapshot'], FinanceBusinessLogic::periodAction('detail', ['month' => $month])['snapshot']);
        $row = FinanceBusinessLogic::options(['type' => 'expense_recurring_plan', 'recurring_plan_id' => $planId])['selected_plan']['months'][0];
        $data['expected_expense_revision_id'] = $row['expected_expense_revision_id'];
        $command = $this->command(0) + ['type' => 'expense_recurring_correct', 'payload' => $data];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame([], $preview['impacts']); self::assertSame([], $preview['balances']);
        $cancel = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($cancel, FinanceBusinessLogic::getError());
        $row = FinanceBusinessLogic::options(['type' => 'expense_recurring_plan', 'recurring_plan_id' => $planId])['selected_plan']['months'][0];
        self::assertSame('none', $row['status']); self::assertSame($data['expected_expense_revision_id'], $row['expected_expense_revision_id']);
    }

    public function test_recurring_none_can_be_reopened_with_history_then_expense_requires_latest_month_revision(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '周期更正服务方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'utilities', 'name' => '用水核实', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id'];
        $plan = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_plan', 'payload' => ['subject_id' => $vendor,
            'category_id' => $categoryId, 'expected_category_version' => 1, 'source_reference' => 'CYCLE-36-1', 'service_start' => $month, 'service_end' => $month,
            'interval_months' => 1, 'reason' => '按月核实水费', 'plan_verified' => 1]]);
        self::assertNotFalse($plan, FinanceBusinessLogic::getError()); $planId = $plan['confirmed_result']['plan_id'];
        $base = ['subject_id' => $vendor, 'recurring_plan_id' => $planId, 'expected_plan_version' => 1, 'expected_month_revision_id' => 0, 'benefit_month' => $month];
        $none = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_none', 'payload' => $base + ['reason' => '误核实本月没有用水', 'none_verified' => 1]]);
        self::assertNotFalse($none, FinanceBusinessLogic::getError());
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $command = $this->command(0) + ['type' => 'expense_recurring_correct', 'payload' => $base + ['correction_mode' => 'reopen_none', 'correction_verified' => 1, 'reason' => '取得原表数，原不发生判断错误，重新核实']];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']); self::assertSame([], $preview['impacts']); self::assertSame([], $preview['balances']);
        $reopened = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($reopened, FinanceBusinessLogic::getError());
        self::assertSame($reopened, FinanceBusinessLogic::action('record', $command));
        $row = FinanceBusinessLogic::options(['type' => 'expense_recurring_plan', 'recurring_plan_id' => $planId])['selected_plan']['months'][0];
        self::assertSame('pending', $row['status']); self::assertGreaterThan(0, $row['month_revision_id']);
        self::assertSame([$none['id'], $reopened['id']], array_column($row['history'], 'document_id'));
        $data = $base + ['actual_date' => date('Y-m-d'), 'amount' => '500', 'source_reference' => 'CYCLE-36-1/' . $month,
            'due_mode' => 'unspecified', 'reason' => '补充核实实际费用', 'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '依据双方核对读数',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '500', 'reason' => '实际用水费用']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $data]), '不得沿用更正前月份版本');
        $data['expected_month_revision_id'] = $row['month_revision_id'];
        $expense = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $data]); self::assertNotFalse($expense, FinanceBusinessLogic::getError());
        self::assertSame(date('Y-m'), $expense['confirmed_result']['posting_month']);
        $row = FinanceBusinessLogic::options(['type' => 'expense_recurring_plan', 'recurring_plan_id' => $planId])['selected_plan']['months'][0];
        self::assertSame('expense', $row['status']); self::assertCount(3, $row['history']);
        self::assertSame($none['confirmed_result'], FinanceBusinessLogic::detail(['id' => $none['id']])['confirmed_result']);
    }

    public function test_recurring_expense_plan_lists_due_months_without_posting_or_paying(): void
    {
        $this->activate(); $month = date('Y-m'); $next = date('Y-m', strtotime('first day of next month'));
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '周期服务方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'utilities', 'name' => '周期用水', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError());
        $command = $this->command(0) + ['type' => 'expense_recurring_plan', 'payload' => ['subject_id' => $vendor,
            'category_id' => $category['confirmed_result']['category']['id'], 'expected_category_version' => 1,
            'source_reference' => 'CYCLE-35-1', 'service_start' => $month, 'service_end' => $next, 'interval_months' => 1,
            'reason' => '每月核实实际水费', 'plan_verified' => 1]];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame([], $preview['impacts']); self::assertSame([], $preview['balances']);
        $plan = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($plan, FinanceBusinessLogic::getError());
        self::assertSame($plan, FinanceBusinessLogic::action('record', $command));
        $options = FinanceBusinessLogic::options(['type' => 'expense_recurring_plan', 'recurring_plan_id' => $plan['confirmed_result']['plan_id']]);
        self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertSame([$month, $next], array_column($options['selected_plan']['months'], 'month'));
        self::assertSame(['pending', 'future'], array_column($options['selected_plan']['months'], 'status'));
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('0.00', $ledger->categoryBalance('expense_payable', $vendor));
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
    }

    public static function estimateFinalAmounts(): array { return [['1000.00', '0.00', '200.00', '0.00'], ['1100.00', '100.00', '300.00', '0.00'], ['700.00', '-300.00', '0.00', '100.00']]; }

    public static function recurringAmountStates(): array { return [['final'], ['estimated']]; }

    /** @dataProvider recurringAmountStates */
    public function test_recurring_month_requires_expense_estimate_or_reasoned_none_and_cannot_be_consumed_twice(string $amountStatus): void
    {
        $previous = date('Y-m', strtotime('first day of last month')); $month = date('Y-m'); $next = date('Y-m', strtotime('first day of next month'));
        $this->activate('cash', $previous . '-01');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '周期费用核实方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'utilities', 'name' => '周期电费', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id'];
        $plan = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_plan', 'payload' => ['subject_id' => $vendor,
            'category_id' => $categoryId, 'expected_category_version' => 1, 'source_reference' => 'CYCLE-35-2',
            'service_start' => $previous, 'service_end' => $next, 'interval_months' => 1, 'reason' => '按月核实费用', 'plan_verified' => 1]]);
        self::assertNotFalse($plan, FinanceBusinessLogic::getError()); $planId = $plan['confirmed_result']['plan_id'];
        $none = ['subject_id' => $vendor, 'recurring_plan_id' => $planId, 'expected_plan_version' => 1, 'benefit_month' => $month,
            'reason' => '本月停业未用电，已核对表数', 'none_verified' => 1];
        $command = $this->command(0) + ['type' => 'expense_recurring_none', 'payload' => $none];
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame($result, FinanceBusinessLogic::action('record', $command));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_none', 'payload' => $none]));
        foreach ([array_replace($none, ['benefit_month' => $next]), array_replace($none, ['benefit_month' => $previous, 'reason' => '']), array_replace($none, ['benefit_month' => $previous, 'none_verified' => 0])] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_recurring_none', 'payload' => $invalid]));
        }
        $expense = ['subject_id' => $vendor, 'recurring_plan_id' => $planId, 'expected_plan_version' => 1, 'benefit_month' => $month,
            'actual_date' => date('Y-m-d'), 'amount' => '800', 'source_reference' => 'CYCLE-35-2/' . $month, 'reason' => '本期用电',
            'due_mode' => 'unspecified', 'material_status' => 'missing', 'missing_material_reason' => '按计量记录人工核实', 'material_verified' => 1,
            'amount_status' => $amountStatus, 'estimate_basis_type' => 'measurement', 'estimate_basis' => '表数及合同单价',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '800', 'reason' => '该月用电费用']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $expense]), '已确认不发生的月份不得再消费为费用');
        $expense['benefit_month'] = $previous; $expense['source_reference'] = 'CYCLE-35-2/' . $previous;
        $paid = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $expense]); self::assertNotFalse($paid, FinanceBusinessLogic::getError());
        $options = FinanceBusinessLogic::options(['type' => 'expense_recurring_plan', 'recurring_plan_id' => $planId]);
        self::assertSame([$amountStatus === 'estimated' ? 'estimated' : 'expense', 'none', 'future'], array_column($options['selected_plan']['months'], 'status'));
        self::assertSame($paid['id'], $options['selected_plan']['months'][0]['document_id']);
        self::assertSame('800.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('expense_payable', $vendor));
        if ($amountStatus === 'estimated') {
            $pending = FinanceBusinessLogic::options(['type' => 'expense_estimate_final', 'original_expense_document_id' => $paid['id']]);
            self::assertSame('pending', $pending['bills'][0]['estimate_items'][0]['status']);
        } else {
            $adjustment = ['subject_id' => $vendor, 'new_subject_id' => $vendor, 'original_expense_document_id' => $paid['id'], 'expected_revision_id' => 0,
                'new_amount' => '800', 'benefit_month' => $month, 'reason' => '把原计划费用移到别月', 'adjustment_verified' => 1, 'confirmation_basis' => '普通更正试图改变周期范围', 'lines' => $expense['lines']];
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_adjustment', 'payload' => $adjustment]), '不能保留已处理月份状态却移走其费用');
            $adjustment['benefit_month'] = $previous; $adjustment['new_amount'] = '0'; $adjustment['lines'] = [];
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_adjustment', 'payload' => $adjustment]), '取消须关联周期重新核实该月');
            $adjustment['new_amount'] = '900'; $adjustment['lines'] = [array_replace($expense['lines'][0], ['amount' => '900'])];
            self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_adjustment', 'payload' => $adjustment]), FinanceBusinessLogic::getError());
        }
    }

    /** @dataProvider estimateFinalAmounts */
    public function test_closed_expense_estimate_resolves_in_parts_and_posts_only_final_difference(string $final, string $delta, string $payable, string $refund): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '分项暂估服务方']); $lines = [];
        foreach (['水表费用' => '1000', '电表费用' => '500'] as $name => $amount) {
            $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
                'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'maintenance', 'name' => $name, 'is_enabled' => 1]]);
            self::assertNotFalse($category, FinanceBusinessLogic::getError());
            $lines[] = ['category_id' => $category['confirmed_result']['category']['id'], 'expected_category_version' => 1, 'amount' => $amount, 'reason' => '依据计量数据暂估'];
        }
        $data = ['subject_id' => $vendor, 'actual_date' => $month . '-05', 'benefit_month' => $month, 'amount' => '1500',
            'due_mode' => 'unspecified', 'source_reference' => 'EST-34-PART', 'reason' => '服务已发生，正式账单待取得',
            'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '原始读数已核对',
            'amount_status' => 'estimated', 'estimate_basis_type' => 'measurement', 'estimate_basis' => '两只仪表实际用量与合同单价', 'lines' => $lines];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $data]); self::assertNotFalse($original, FinanceBusinessLogic::getError());
        $payment = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => ['subject_id' => $vendor,
            'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '1300', 'reason' => '分次费用先付',
            'allocations' => [['source' => $original['confirmed_result']['created_sources'][0], 'amount' => '1300']]]]); self::assertNotFalse($payment, FinanceBusinessLogic::getError());
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'account_reconcile', 'payload' => ['account_id' => $this->accountId, 'month' => $month, 'actual_cutoff' => date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59', 'actual_balance' => '5000', 'expected_book_balance' => '5000', 'reconciliation_verified' => 1, 'expected_reconciliation_id' => 0, 'reason' => '月末余额已核实']]), FinanceBusinessLogic::getError());
        $closing = FinanceBusinessLogic::periodAction('preview', ['month' => $month]); self::assertNotFalse($closing, FinanceBusinessLogic::getError());
        $closed = FinanceBusinessLogic::periodAction('close', $this->command(0) + ['month' => $month, 'mode' => 'ordinary', 'expected_fingerprint' => $closing['fingerprint'], 'reason' => '合理费用暂估进入普通月结']); self::assertNotFalse($closed, FinanceBusinessLogic::getError());
        $frozen = $closed['snapshot'];
        $tracking = FinanceBusinessLogic::periodAction('followups', ['month' => $month]); self::assertNotFalse($tracking, FinanceBusinessLogic::getError());
        self::assertSame('ordinary', $tracking['original_mode']); self::assertSame(2, $tracking['summary']['pending']); self::assertFalse($tracking['all_resolved']);
        $base = ['subject_id' => $vendor, 'original_expense_document_id' => $original['id'], 'expected_revision_id' => 0, 'expected_resolution_id' => 0, 'final_verified' => 1,
            'reason' => '正式账单已取得', 'resolutions' => [['category_id' => $lines[1]['category_id'], 'final_amount' => '500', 'confirmation_basis' => '最终电费账单与原估计一致']]];
        $firstCommand = $this->command(0) + ['type' => 'expense_estimate_final', 'payload' => $base];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($firstCommand + ['action' => 'record']);
        self::assertSame([], $preview['impacts']); self::assertSame([], $preview['balances']);
        $first = FinanceBusinessLogic::action('record', $firstCommand); self::assertNotFalse($first, FinanceBusinessLogic::getError());
        self::assertSame($first, FinanceBusinessLogic::action('record', $firstCommand));
        $tracking = FinanceBusinessLogic::periodAction('followups', ['month' => $month]); self::assertSame(1, $tracking['summary']['resolved']); self::assertSame(1, $tracking['summary']['pending']);
        $verified = array_values(array_filter($tracking['items'], static fn(array $item): bool => $item['current']['status'] === 'resolved'))[0];
        self::assertSame('0.00', $verified['current']['evidence'][0]['amount_change']); self::assertSame([], $verified['current']['adjustments']);
        $options = FinanceBusinessLogic::options(['type' => 'expense_estimate_final', 'original_expense_document_id' => $original['id']]);
        self::assertSame(['pending', 'resolved'], array_column($options['bills'][0]['estimate_items'], 'status'));
        self::assertSame(0, $options['bills'][0]['expected_revision_id'], '零差额不生成费用调整版本');
        $generic = ['subject_id' => $vendor, 'new_subject_id' => $vendor, 'original_expense_document_id' => $original['id'], 'expected_revision_id' => 0,
            'new_amount' => '1501', 'benefit_month' => $month, 'reason' => '绕过暂估核实', 'adjustment_verified' => 1, 'confirmation_basis' => '任意依据',
            'lines' => [array_replace($lines[0], ['amount' => '1001']), $lines[1]]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_adjustment', 'payload' => $generic]));
        $base['resolutions'] = [['category_id' => $lines[0]['category_id'], 'final_amount' => $final, 'confirmation_basis' => '最终水费账单原件已核对']];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_estimate_final', 'payload' => $base]), '零差额核实后旧待办版本也必须失效');
        $base['expected_resolution_id'] = $options['bills'][0]['expected_resolution_id'];
        $command = $this->command(0) + ['type' => 'expense_estimate_final', 'payload' => $base];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame($delta === '0.00' ? [] : [['metric' => 'expense', 'posting_month' => date('Y-m'), 'amount' => $delta]], $preview['impacts']);
        $resolved = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($resolved, FinanceBusinessLogic::getError());
        self::assertSame($resolved, FinanceBusinessLogic::action('record', $command));
        self::assertSame($delta, $resolved['confirmed_result']['amount_change']);
        self::assertSame(0, $resolved['confirmed_result']['pending_count']);
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame($payable, $ledger->categoryBalance('expense_payable', $vendor));
        self::assertSame($refund, $ledger->categoryBalance('expense_refund', $vendor)); self::assertSame('3700.00', $ledger->account($this->accountId)['balance']);
        self::assertSame($original['confirmed_result'], FinanceBusinessLogic::detail(['id' => $original['id']])['confirmed_result']);
        $tracking = FinanceBusinessLogic::periodAction('followups', ['month' => $month]); self::assertTrue($tracking['all_resolved']); self::assertSame(2, $tracking['summary']['resolved']);
        self::assertSame('ordinary', $tracking['original_mode']); self::assertSame('not_fully_verified', $tracking['original_verification']['status']);
        $water = array_values(array_filter($tracking['items'], static fn(array $item): bool => $item['original']['details']['category_id'] === $lines[0]['category_id']))[0];
        self::assertSame($delta, $water['current']['evidence'][0]['amount_change']);
        self::assertSame($delta === '0.00' ? [] : [date('Y-m')], array_values(array_unique(array_column($water['current']['adjustments'], 'posting_month'))));
        self::assertSame($frozen, FinanceBusinessLogic::periodAction('detail', ['month' => $month])['snapshot']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_estimate_final', 'payload' => $base]), '同一项目不能重复核实');
    }

    public function test_expense_estimate_requires_reliable_basis_and_keeps_each_original_scope(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '费用暂估服务方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'maintenance', 'name' => '计量服务', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id'];
        $data = ['subject_id' => $vendor, 'actual_date' => date('Y-m-d'), 'benefit_month' => date('Y-m'), 'amount' => '1000',
            'due_mode' => 'unspecified', 'source_reference' => 'EST-34-1', 'reason' => '本月服务已发生',
            'material_status' => 'missing', 'material_verified' => 1, 'missing_material_reason' => '最终账单待取得',
            'amount_status' => 'estimated', 'estimate_basis_type' => 'measurement', 'estimate_basis' => '',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '1000', 'reason' => '本月仪表用量已核对']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $data]), '暂估必须有可靠依据');
        $data['estimate_basis'] = '已核对本月仪表用量和约定单价';
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $data]);
        self::assertNotFalse($original, FinanceBusinessLogic::getError());
        self::assertSame('estimated', $original['confirmed_result']['amount_status']);
        $options = FinanceBusinessLogic::options(['type' => 'expense_estimate_final', 'original_expense_document_id' => $original['id']]);
        self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertSame('pending', $options['bills'][0]['estimate_items'][0]['status']);
        self::assertSame('1000.00', $options['bills'][0]['estimate_items'][0]['estimated_amount']);
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('expense_refund', $vendor));
    }

    public function test_new_deferred_obligation_keeps_future_service_out_of_profit_and_payment_separate(): void
    {
        $this->activate(); $month = date('Y-m'); $next = date('Y-m', strtotime('first day of next month'));
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '新租赁服务方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => ['category_id' => 0,
            'expected_category_version' => 0, 'parent' => 'premises', 'name' => '场地服务', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError());
        $lines = [['category_id' => $category['confirmed_result']['category']['id'], 'expected_category_version' => 1, 'amount' => '3000.00', 'reason' => '两月合同租金']];
        $data = ['subject_id' => $vendor, 'actual_date' => date('Y-m-d'), 'amount' => '3000.00', 'source_reference' => 'DEFERRED-33-1',
            'due_mode' => 'unspecified', 'due_date' => null, 'reason' => '合同服务义务已确认', 'material_status' => 'missing', 'material_verified' => 1,
            'missing_material_reason' => '经办人核实合同，原件待补', 'plan_verified' => 1, 'service_start' => $month, 'service_end' => $next,
            'schedule' => [['month' => $month, 'amount' => '1500.00'], ['month' => $next, 'amount' => '1500.00']], 'lines' => $lines];
        $command = $this->command(0) + ['type' => 'deferred_expense', 'payload' => $data];
        foreach ([array_replace($data, ['plan_verified' => 0]), array_replace($data, ['schedule' => [['month' => $month, 'amount' => '3000.00']]]),
            array_replace($data, ['schedule' => [['month' => $month, 'amount' => '1500.00'], ['month' => $month, 'amount' => '1500.00']]]),
            array_replace($data, ['schedule' => [['month' => $month, 'amount' => '1500.00'], ['month' => $next, 'amount' => '1600.00']]])] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'deferred_expense', 'payload' => $invalid]));
        }
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame([], $preview['impacts']); self::assertEqualsCanonicalizing(['expense_payable', 'deferred'], array_column($preview['balances'], 'category'));
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame($result, FinanceBusinessLogic::action('record', $command));
        $duplicate = $data; $duplicate['benefit_month'] = $month;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $duplicate]));
        self::assertStringContainsString('来源已登记', FinanceBusinessLogic::getError());
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'expense_adjustment', 'subject_id' => $vendor])['bills']);
        $ledger = new FinanceLedger(self::TENANT_ID); $payable = $result['confirmed_result']['created_sources'][0]; $deferred = $result['confirmed_result']['deferred_source'];
        self::assertSame('3000.00', $ledger->source($deferred)['balance']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => ['subject_id' => $vendor,
            'account_id' => $this->accountId, 'amount' => '1000.00', 'actual_date' => date('Y-m-d'), 'reason' => '实际支付首期租金', 'allocations' => [['source' => $payable, 'amount' => '1000.00']]]]), FinanceBusinessLogic::getError());
        self::assertSame('3000.00', $ledger->source($deferred)['balance']); self::assertSame('2000.00', $ledger->source($payable)['balance']);
        $otherCategory = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => ['category_id' => 0,
            'expected_category_version' => 0, 'parent' => 'office', 'name' => '办公支出', 'is_enabled' => 1]]);
        self::assertNotFalse($otherCategory, FinanceBusinessLogic::getError());
        $wrong = $lines; $wrong[0]['category_id'] = $otherCategory['confirmed_result']['category']['id']; $wrong[0]['amount'] = '1500.00';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'deferred_amortization', 'payload' => ['source' => $deferred,
            'subject_id' => $vendor, 'benefit_month' => $month, 'amortization_verified' => 1, 'reason' => '错误类别不应改变原租金分类', 'lines' => $wrong]]));
        self::assertStringContainsString('原计划类别', FinanceBusinessLogic::getError());
        $split = $data; $split['source_reference'] = 'DEFERRED-33-2'; $split['lines'][0]['amount'] = '1000.00';
        $split['lines'][] = ['category_id' => $otherCategory['confirmed_result']['category']['id'], 'expected_category_version' => 1, 'amount' => '2000.00', 'reason' => '合同同时包含办公服务'];
        $splitPlan = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'deferred_expense', 'payload' => $split]);
        self::assertNotFalse($splitPlan, FinanceBusinessLogic::getError());
        $over = $lines; $over[0]['amount'] = '1500.00';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'deferred_amortization', 'payload' => ['source' => $splitPlan['confirmed_result']['deferred_source'],
            'subject_id' => $vendor, 'benefit_month' => $month, 'amortization_verified' => 1, 'reason' => '单类别不能超过原拆分', 'lines' => $over]]));
        self::assertStringContainsString('类别剩余', FinanceBusinessLogic::getError());
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => ['category_id' => $lines[0]['category_id'],
            'expected_category_version' => 1, 'parent' => 'premises', 'name' => '场地服务', 'is_enabled' => 0]]), FinanceBusinessLogic::getError());
        $lines[0]['amount'] = '1500.00';
        $amortized = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'deferred_amortization', 'payload' => ['source' => $deferred,
            'subject_id' => $vendor, 'benefit_month' => $month, 'amortization_verified' => 1, 'reason' => '本月服务已取得', 'lines' => $lines]]);
        self::assertNotFalse($amortized, FinanceBusinessLogic::getError());
        self::assertSame('1500.00', $ledger->source($deferred)['balance']); self::assertSame('2000.00', $ledger->source($payable)['balance']);
        self::assertSame('4000.00', $ledger->account($this->accountId)['balance']);
        $remaining = FinanceBusinessLogic::options(['type' => 'deferred_amortization', 'subject_id' => $vendor, 'source' => $deferred]);
        self::assertSame('1500.00', $remaining['selected_deferred']['category_remaining'][0]['remaining']);
        self::assertSame(1, $remaining['selected_deferred']['category_remaining'][0]['category_version']);
        self::assertSame($result['confirmed_result'], FinanceBusinessLogic::detail(['id' => $result['id']])['confirmed_result']);
    }

    /** @dataProvider deferredClosureCases */
    public function test_opening_deferred_schedule_recognizes_one_elapsed_month_without_repeating_payment(bool $closed): void
    {
        $month = $closed ? date('Y-m', strtotime('first day of last month')) : date('Y-m');
        $this->activate('cash', $month . '-01'); $next = date('Y-m', strtotime($month . '-01 +1 month'));
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '租赁服务方']);
        $sourceId = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'activation_date' => $month . '-01',
            'opening_item_id' => 9032, 'category' => 'deferred', 'subject_id' => $vendor, 'amount' => '2000.00', 'create_time' => time(),
            'source_snapshot' => json_encode(['subject_name' => '租赁服务方', 'source_reference' => '期初租金', 'historical_date' => null,
                'details' => ['original_amount' => '3000.00', 'amortized_amount' => '1000.00', 'paid_amount' => '3000.00', 'unpaid_amount' => '0.00',
                    'service_start' => $month, 'service_end' => $next, 'schedule' => [['month' => $month, 'amount' => '1000.00'], ['month' => $next, 'amount' => '1000.00']]]])]);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => ['category_id' => 0,
            'expected_category_version' => 0, 'parent' => 'premises', 'name' => '场地租金', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError());
        if ($closed) { Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]); }
        $command = $this->command(0) + ['type' => 'deferred_amortization', 'payload' => ['subject_id' => $vendor, 'source' => 'o:' . $sourceId,
            'benefit_month' => $month, 'amortization_verified' => 1, 'reason' => '核实本月场地服务已受益',
            'lines' => [['category_id' => $category['confirmed_result']['category']['id'], 'expected_category_version' => 1, 'amount' => '1000.00', 'reason' => '本月场地租金']]]];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame([['metric' => 'expense', 'posting_month' => date('Y-m'), 'amount' => '1000.00']], $preview['impacts']);
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('2000.00', $ledger->source('o:' . $sourceId)['balance']);
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame($result, FinanceBusinessLogic::action('record', $command));
        self::assertSame('1000.00', $ledger->source('o:' . $sourceId)['balance']); self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame([], $ledger->sources('expense_payable', $vendor));
        $options = FinanceBusinessLogic::options(['type' => 'deferred_amortization', 'subject_id' => $vendor]);
        self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertSame(['confirmed', $closed ? 'pending' : 'future'], array_column($options['sources'][0]['schedule'], 'status'));
        self::assertSame($result['id'], $options['sources'][0]['schedule'][0]['document_id']);
        $command['idempotency_key'] = $this->command(0)['idempotency_key']; self::assertFalse(FinanceBusinessLogic::action('record', $command));
        self::assertStringContainsString('已摊销', FinanceBusinessLogic::getError());
        $command['idempotency_key'] = $this->command(0)['idempotency_key']; $command['payload']['benefit_month'] = date('Y-m', strtotime('first day of next month'));
        self::assertFalse(FinanceBusinessLogic::action('record', $command)); self::assertStringContainsString('未来', FinanceBusinessLogic::getError());
        $command['idempotency_key'] = $this->command(0)['idempotency_key']; $command['payload']['subject_id'] = $vendor + 1;
        self::assertFalse(FinanceBusinessLogic::action('record', $command)); self::assertStringContainsString('本对象', FinanceBusinessLogic::getError());
        if ($closed) {
            $command['idempotency_key'] = $this->command(0)['idempotency_key']; $command['payload']['subject_id'] = $vendor; $command['payload']['benefit_month'] = $next;
            self::assertNotFalse(FinanceBusinessLogic::action('record', $command), FinanceBusinessLogic::getError());
            $completed = FinanceBusinessLogic::options(['type' => 'deferred_amortization', 'subject_id' => $vendor, 'source' => 'o:' . $sourceId]);
            self::assertNotFalse($completed, FinanceBusinessLogic::getError()); self::assertSame('0.00', $completed['selected_deferred']['balance']);
            self::assertSame(['confirmed', 'confirmed'], array_column($completed['selected_deferred']['schedule'], 'status'));
        }
    }

    public function test_cost_events_survive_reload_replay_once_and_roll_back_with_business_transaction(): void
    {
        $this->activate();
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $event = ['reference' => 'test-arrival:1', 'type' => 'receive', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'origin' => 'test-arrival:1', 'quantity' => '100', 'amount' => '1000.00', 'snapshot' => ['basis' => '双方约定']];
        $first = Db::transaction(fn() => $cost->recordWithinTransaction($event));
        self::assertSame($first, Db::transaction(fn() => $cost->recordWithinTransaction($event)));
        self::assertSame('1000.000000', $cost->balance(10, 20)['value']);
        $sale = ['reference' => 'test-delivery:1', 'type' => 'issue', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'quantity' => '25', 'bucket' => 'sale', 'target_reference' => 'test-delivery:1'];
        Db::transaction(fn() => $cost->recordWithinTransaction($sale));
        $reloaded = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('750.000000', $reloaded->balance(10, 20)['value']);
        $adjustment = ['reference' => 'test-cost-confirm:1', 'type' => 'adjust', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'origin' => 'test-arrival:1', 'amount' => '1200.00'];
        $adjusted = Db::transaction(fn() => $reloaded->recordWithinTransaction($adjustment));
        self::assertSame('50.000000', $adjusted['changes']['sale']);
        self::assertSame('900.000000', $cost->balance(10, 20)['value']);
        self::assertSame('300.000000', $cost->destination(10, 20, 'sale', 'test-delivery:1')['cost']);
        self::assertSame(3, count($cost->events(20)));
        try { Db::transaction(function () use ($cost, $sale): void { $sale['reference'] = 'rollback-sale'; $cost->recordWithinTransaction($sale); throw new \DomainException('后续业务失败'); }); }
        catch (\DomainException $e) { self::assertSame('后续业务失败', $e->getMessage()); }
        self::assertSame('900.000000', $cost->balance(10, 20)['value']); self::assertCount(3, $cost->events(20));
        $event['amount'] = '1001.00';
        $this->expectException(\DomainException::class);
        Db::transaction(fn() => $cost->recordWithinTransaction($event));
    }

    public static function bootstrapCorrections(): array { return [['none'], ['correction'], ['return'], ['transport'], ['old_transport']]; }

    /** @dataProvider bootstrapCorrections */
    public function test_activation_carries_cutoff_stock_then_replays_intervening_sales_cost_without_moving_stock_again(string $mode): void
    {
        $corrected = in_array($mode, ['correction', 'return'], true);
        $date = $mode === 'transport' ? date('Y-m-01', strtotime('first day of last month')) : date('Y-m-d');
        $bucket = 'sale'; $destination = 'sales_order:9827';
        $warehouse = $this->createCustomerReportWarehouse('启用衔接仓');
        $goods = $this->createCustomerReportGoods('启用衔接商品', 'FIN-BOOTSTRAP'); $sku = $this->customerReportSkuId($goods);
        $stockId = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID,
            'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => '100.0000', 'available_qty' => '100.0000']);
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => $date,
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        if (in_array($mode, ['transport', 'old_transport'], true)) {
            $delivery = (int)Db::name('fulfillment_delivery_event')->insertGetId(['tenant_id' => self::TENANT_ID,
                'idempotency_key' => 'BOOTSTRAP-TRANSPORT', 'actual_handoff_time' => $mode === 'old_transport' ? strtotime('yesterday') : strtotime($date . ' +10 days'), 'create_time' => time()]);
            self::assertNotFalse(Db::transaction(fn() => \app\api\jxc\logic\StockService::outboundTransportLossWithinTransaction($warehouse, $goods, $sku, '10', '0', $delivery)));
            $bucket = 'loss'; $destination = 'delivery_loss:' . $delivery;
        } else { self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '10', 9827, 'sales', 'BEFORE-ACTIVATION', '', $sku)); }
        if ($mode === 'correction') { self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '4', 9827, 'sales_delivery_correction', 'BEFORE-CORRECTION', '', $sku)); }
        if ($mode === 'return') {
            Db::name('sales_order')->insert(['id' => 9827, 'tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse, 'order_sn' => 'BOOTSTRAP-SALE']);
            $return = (int)Db::name('sales_return_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse, 'original_sales_order_id' => 9827, 'order_sn' => 'BOOTSTRAP-RETURN']);
            self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '4', $return, 'sales-return', 'BOOTSTRAP-RETURN', '', $sku));
        }
        $this->opening('item', ['category' => 'inventory', 'subject_id' => $stockId, 'amount' => $mode === 'old_transport' ? '180.00' : '200.00', 'historical_date' => null, 'due_date' => null,
            'source_mode' => 'detail', 'source_reference' => '启用日前一日盘存', 'evidence' => '统一截点数量一百、历史成本二百',
            'details' => ['quantity' => $mode === 'old_transport' ? '90.0000' : '100.0000', 'origin_reference' => '截点盘存凭据']]);
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $this->opening('submit'); $this->opening('confirm');
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame($corrected ? '94.0000' : '90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($corrected ? '94.000000000000' : '90.000000000000', $cost->balance($warehouse, $sku)['quantity']);
        self::assertSame($corrected ? '188.000000' : '180.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame($mode === 'old_transport' ? '0.000000' : ($corrected ? '12.000000' : '20.000000'), $cost->destination($warehouse, $sku, $bucket, $destination)['cost']);
        if ($mode === 'transport') { self::assertSame(date('Y-m-d', strtotime($date . ' +10 days')), $cost->events($sku)[0]['business_date']); }
        if ($mode === 'old_transport') { self::assertSame([], $cost->events($sku)); }
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '5', 9828, 'sales', 'AFTER-ACTIVATION', '', $sku));
        self::assertSame($corrected ? '89.0000' : '85.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($corrected ? '178.000000' : '170.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('10.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:9828')['cost']);
        self::assertSame($mode === 'old_transport' ? '0.000000' : ($corrected ? '12.000000' : '20.000000'), $cost->destination($warehouse, $sku, $bucket, $destination)['cost']);
    }

    public static function historicalSaleDates(): array { return [[true], [false]]; }

    /** @dataProvider historicalSaleDates */
    public function test_activation_keeps_return_from_pre_cutoff_sale_unpriced_without_borrowing_opening_unit_cost(bool $knownDate): void
    {
        $warehouse = $this->createCustomerReportWarehouse('旧售退回仓');
        $goods = $this->createCustomerReportGoods('旧售退回商品', 'FIN-OLD-RETURN'); $sku = $this->customerReportSkuId($goods);
        $stock = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID,
            'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => '100', 'available_qty' => '100']);
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => date('Y-m-d'),
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        $sale = (int)Db::name('sales_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse,
            'order_sn' => 'PRE-CUTOFF-SALE', 'datetimesingle' => $knownDate ? strtotime('yesterday') : 0]);
        $return = (int)Db::name('sales_return_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse,
            'original_sales_order_id' => $sale, 'order_sn' => 'OLD-SALE-RETURN']);
        self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '4', $return, 'sales-return', 'OLD-SALE-RETURN', '', $sku));
        $this->opening('item', ['category' => 'inventory', 'subject_id' => $stock, 'amount' => '200.00', 'historical_date' => null, 'due_date' => null,
            'source_mode' => 'detail', 'source_reference' => '旧售退回前截点盘存', 'evidence' => '截点库存一百；旧销售成本尚待核对',
            'details' => ['quantity' => '100', 'origin_reference' => '期初盘存']]);
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $this->opening('submit'); $active = $this->opening('confirm');
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID); $balance = $cost->balance($warehouse, $sku);
        self::assertSame('104.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('104.000000000000', $balance['quantity']); self::assertSame('200.000000', $balance['known_value']);
        self::assertNull($balance['value']); self::assertSame('4.000000000000', $balance['pending_quantity']);
        self::assertCount(1, $active['cost_bootstrap']['pending_flow_ids']);
        self::assertSame('0.000000000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:' . $sale)['quantity']);
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '104', 98628, 'sales', 'RESALE-AFTER-BOOTSTRAP', '', $sku));
        self::assertNull($cost->destination($warehouse, $sku, 'sale', 'sales_order:98628')['cost']);
        $options = FinanceBusinessLogic::options(['type' => 'legacy_return_cost']);
        self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertCount(1, $options['cost_sources']);
        $source = $options['cost_sources'][0];
        $check = FinanceBusinessLogic::closingChecklist(['month' => date('Y-m')]); self::assertNotFalse($check, FinanceBusinessLogic::getError());
        $pending = array_values(array_filter($check['items'], static fn(array $item): bool => $item['category'] === 'cost_pending' && $item['reference'] === 'stock:' . $source['stock_flow_id']));
        self::assertCount(1, $pending); self::assertSame('/sub-finance/inventory/cost?type=legacy_return_cost&stock_flow_id=' . $source['stock_flow_id'], $pending[0]['details']['route']);
        $payload = ['stock_flow_id' => $source['stock_flow_id'], 'expected_cost_event_id' => $source['expected_cost_event_id'],
            'amount' => '40.00', 'source_reference' => '旧销售原采购成本凭据', 'reason' => '核实这四单位退回商品原成本四十元', 'cost_verified' => 1];
        $request = $this->command(0) + ['type' => 'legacy_return_cost', 'payload' => $payload];
        foreach ([['cost_verified' => 0], ['amount' => '-1'], ['stock_flow_id' => 999999]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'legacy_return_cost', 'payload' => array_merge($payload, $invalid)]));
            self::assertNull($cost->destination($warehouse, $sku, 'sale', 'sales_order:98628')['cost']);
        }
        $preview = \app\api\jxc\logic\FinancePreview::calculate($request + ['action' => 'record']);
        self::assertNotFalse($preview, FinanceBusinessLogic::getError());
        self::assertNull($cost->destination($warehouse, $sku, 'sale', 'sales_order:98628')['cost']);
        $confirmed = FinanceBusinessLogic::action('record', $request);
        self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertSame($confirmed, FinanceBusinessLogic::action('record', $request));
        self::assertSame('240.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:98628')['cost']);
        self::assertSame('0.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame([], $confirmed['confirmed_result']['created_sources']);
        self::assertSame('40.00', $confirmed['confirmed_result']['amount']);
        $payload['amount'] = '44.00';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'legacy_return_cost', 'payload' => $payload]));
        self::assertStringContainsString('已更新', FinanceBusinessLogic::getError());
        $latest = FinanceBusinessLogic::options(['type' => 'legacy_return_cost'])['cost_sources'][0];
        $payload['expected_cost_event_id'] = $latest['expected_cost_event_id'];
        $revised = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'legacy_return_cost', 'payload' => $payload]);
        self::assertNotFalse($revised, FinanceBusinessLogic::getError());
        self::assertSame('244.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:98628')['cost']);
        self::assertSame('40.00', FinanceBusinessLogic::detail(['id' => $confirmed['id']])['confirmed_result']['amount']);
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'legacy_return_cost'])['cost_sources']);
    }

    public static function unresolvedBootstrapFlows(): array { return [['purchase', true], ['warehouse-transfer', false]]; }

    public function test_activation_carries_authoritative_transfer_pair_at_same_value_then_uses_target_average(): void
    {
        $from = $this->createCustomerReportWarehouse('调出承接仓'); $to = $this->createCustomerReportWarehouse('调入承接仓');
        $goods = $this->createCustomerReportGoods('调拨承接商品', 'FIN-TRANSFER-BOOT'); $sku = $this->customerReportSkuId($goods); $stocks = [];
        foreach ([$from, $to] as $warehouse) {
            $stocks[$warehouse] = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID,
                'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => '100', 'available_qty' => '100']);
        }
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => date('Y-m-d'),
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        self::assertFalse(\app\api\jxc\logic\StockService::transfer($from, 999999, $goods, '10', 98629, 'warehouse-transfer', 'INVALID-TRANSFER', '', $sku));
        self::assertSame('100.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($from, $sku));
        self::assertTrue(\app\api\jxc\logic\StockService::transfer($from, $to, $goods, '10', 98629, 'warehouse-transfer', 'BEFORE-TRANSFER', '', $sku));
        foreach ([$from => '200.00', $to => '400.00'] as $warehouse => $amount) {
            $this->opening('item', ['category' => 'inventory', 'subject_id' => $stocks[$warehouse], 'amount' => $amount, 'historical_date' => null, 'due_date' => null,
                'source_mode' => 'detail', 'source_reference' => '调拨前截点盘存-' . $warehouse, 'evidence' => '两仓截点各一百，分别核实成本',
                'details' => ['quantity' => '100', 'origin_reference' => '两仓盘存凭据']]);
        }
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $this->opening('submit'); $this->opening('confirm');
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('180.000000', $cost->balance($from, $sku)['value']); self::assertSame('420.000000', $cost->balance($to, $sku)['value']);
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($from, $sku));
        self::assertSame('110.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($to, $sku));
        self::assertCount(1, $cost->events($sku)); self::assertSame('transfer', $cost->events($sku)[0]['type']);
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($to, $goods, '55', 98630, 'sales', 'SALE-AFTER-TRANSFER', '', $sku));
        self::assertSame('210.000000', $cost->destination($to, $sku, 'sale', 'sales_order:98630')['cost']);
        self::assertSame('210.000000', $cost->balance($to, $sku)['value']); self::assertSame('180.000000', $cost->balance($from, $sku)['value']);
        self::assertFalse(\app\api\jxc\logic\StockService::transfer($to, 999999, $goods, '10', 98631, 'warehouse-transfer', 'INVALID-ACTIVE-TRANSFER', '', $sku));
        self::assertSame('55.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($to, $sku)); self::assertCount(2, $cost->events($sku));
    }

    /** @dataProvider unresolvedBootstrapFlows */
    public function test_activation_rejects_unverified_inbound_cost_or_unpaired_stock_destination_atomically(string $type, bool $inbound): void
    {
        $warehouse = $this->createCustomerReportWarehouse('未核实承接仓');
        $goods = $this->createCustomerReportGoods('未核实承接商品', 'FIN-BOOT-BLOCK'); $sku = $this->customerReportSkuId($goods);
        $stock = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID,
            'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => '100', 'available_qty' => '100']);
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => date('Y-m-d'),
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        $method = $inbound ? 'inbound' : 'outbound';
        self::assertTrue(\app\api\jxc\logic\StockService::$method($warehouse, $goods, '10', 9827, $type, 'UNVERIFIED-STOCK', '', $sku));
        $this->opening('item', ['category' => 'inventory', 'subject_id' => $stock, 'amount' => '200.00', 'historical_date' => null, 'due_date' => null,
            'source_mode' => 'detail', 'source_reference' => '截点库存凭据', 'evidence' => '截点库存一百',
            'details' => ['quantity' => '100', 'origin_reference' => '期初盘存']]);
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $pending = $this->opening('submit');
        self::assertFalse(FinanceSetupLogic::openingAction('confirm', $this->command((int)$pending['version'])));
        self::assertStringContainsString('尚未核实', FinanceSetupLogic::getError());
        self::assertSame('pending', FinanceSetupLogic::opening()['status']);
        self::assertSame($inbound ? '110.0000' : '90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame([], (new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID))->events($sku));
    }

    /** @dataProvider arrivalLossPrices */
    public function test_stock_loss_keeps_unresolved_cost_separate_then_confirms_partial_loss_without_second_outbound(?string $initialPrice): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('库内损耗仓');
        $goods = $this->createCustomerReportGoods('库内损耗商品', 'FIN-STOCK-LOSS'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '库内损耗供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'STOCK-LOSS-ARR', 'reason' => '原实收到货',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'reported_quantity' => '100'] + ($initialPrice === null ? [] : ['agreed_price' => $initialPrice])]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $payload = ['warehouse_id' => $warehouse, 'sku_id' => $sku, 'quantity' => '10', 'actual_date' => date('Y-m-d'),
            'source_reference' => 'INCIDENT-ONE', 'reason' => '冷库异常造成商品实物减少', 'responsibility' => '交接与温控记录待核实', 'physical_confirmed' => 1];
        $command = $this->command(0) + ['type' => 'inventory_loss', 'payload' => $payload];
        foreach ([['physical_confirmed' => 0], ['quantity' => '101'], ['actual_date' => date('Y-m-d', strtotime('+1 day'))]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss', 'payload' => array_merge($payload, $invalid)]));
            self::assertSame('100.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        }
        $incident = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($incident, FinanceBusinessLogic::getError());
        self::assertSame($incident, FinanceBusinessLogic::action('record', $command));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss', 'payload' => $payload]));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $reference = 'inventory-loss:' . $incident['id'];
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($initialPrice === null ? null : '180.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame($initialPrice === null ? null : '20.000000', $cost->destination($warehouse, $sku, 'pending', $reference)['cost']);
        self::assertSame('0.000000000000', $cost->destination($warehouse, $sku, 'loss', $reference)['quantity']);
        $candidates = FinanceBusinessLogic::options(['type' => 'inventory_loss_resolution', 'warehouse_id' => $warehouse]);
        self::assertNotFalse($candidates, FinanceBusinessLogic::getError()); self::assertCount(1, $candidates['incidents']);
        self::assertSame('10.0000', $candidates['incidents'][0]['remaining_quantity']);
        $resolve = ['incident_document_id' => $incident['id'], 'expected_resolution_id' => 0, 'quantity' => '4',
            'loss_confirmed' => 1, 'reason' => '核实四单位腐坏由门店承担', 'responsibility' => '门店承担，不登记责任应收', 'source_reference' => 'INCIDENT-CHECK-ONE'];
        foreach ([['loss_confirmed' => 0], ['quantity' => '11']] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss_resolution', 'payload' => array_merge($resolve, $invalid)]));
        }
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['type' => 'inventory_loss_resolution', 'action' => 'record', 'payload' => $resolve]);
        self::assertSame($initialPrice === null ? null : '20.000000', $cost->destination($warehouse, $sku, 'pending', $reference)['cost']);
        $resolved = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss_resolution', 'payload' => $resolve]);
        self::assertNotFalse($resolved, FinanceBusinessLogic::getError()); self::assertSame($initialPrice === null ? null : '8.000000', $resolved['confirmed_result']['loss_cost']);
        self::assertSame($initialPrice === null, $resolved['confirmed_result']['cost_pending']);
        self::assertSame($preview['cost_impacts'], $resolved['confirmed_result']['cost_impacts']);
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($initialPrice === null ? null : '12.000000', $cost->destination($warehouse, $sku, 'pending', $reference)['cost']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss_resolution', 'payload' => $resolve]));
        self::assertSame('6.0000', FinanceBusinessLogic::options(['type' => 'inventory_loss_resolution', 'warehouse_id' => $warehouse])['incidents'][0]['remaining_quantity']);
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'supplier_confirmed' => 1, 'supplier_confirmation' => '供方确认三元', 'reason' => '最终补价',
            'lines' => [['arrival_line_id' => $arrival['confirmed_result']['lines'][0]['arrival_line_id'], 'covered_quantity' => '100', 'settlement_quantity' => '100', 'price' => '3.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        self::assertSame('270.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('18.000000', $cost->destination($warehouse, $sku, 'pending', $reference)['cost']);
        self::assertSame('12.000000', $cost->destination($warehouse, $sku, 'loss', 'inventory-loss-resolution:' . $resolved['id'])['cost']);
        self::assertSame($initialPrice === null ? null : '8.000000', FinanceBusinessLogic::detail(['id' => $resolved['id']])['confirmed_result']['loss_cost']);
        $resolve['expected_resolution_id'] = $resolved['confirmed_result']['resolution_id']; $resolve['quantity'] = '6';
        $finished = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss_resolution', 'payload' => $resolve]);
        self::assertNotFalse($finished, FinanceBusinessLogic::getError()); self::assertSame('18.000000', $finished['confirmed_result']['loss_cost']);
        self::assertFalse($finished['confirmed_result']['resolution_pending']);
        self::assertCount(0, FinanceBusinessLogic::options(['type' => 'inventory_loss_resolution'])['incidents']);
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
    }

    public static function arrivalLossPrices(): array { return ['已知暂估' => ['2.00'], '未知成本' => [null]]; }

    /** @dataProvider arrivalLossPrices */
    public function test_confirmed_arrival_loss_excluded_from_stock_splits_cost_and_future_price_changes_without_second_stock_deduction(?string $initialPrice): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('异常到货损耗仓');
        $goods = $this->createCustomerReportGoods('实收已扣损失商品', 'FIN-ARRIVAL-LOSS'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '异常到货损耗供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'LOSS-ARR', 'reason' => '实收只含完好商品',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '499', 'reported_quantity' => '500'] + ($initialPrice === null ? [] : ['agreed_price' => $initialPrice])]]]); self::assertNotFalse($arrival);
        $arrivalId = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '100', 909, 'sales', 'SALE-BEFORE-LOSS', '', $sku));
        $review = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_difference', 'payload' => ['subject_id' => $vendor,
            'arrival_line_id' => $arrivalId, 'expected_review_id' => 0, 'classification' => 'loss', 'reason' => '装卸遗失需核实', 'responsibility' => '交接记录待确认', 'review_confirmed' => 1]]); self::assertNotFalse($review);
        $payload = ['subject_id' => $vendor, 'arrival_line_id' => $arrivalId, 'expected_review_id' => $review['confirmed_result']['review_id'],
            'quantity' => '1', 'excluded_from_received_confirmed' => 1, 'loss_confirmed' => 1, 'source_reference' => 'LOSS-HANDOFF',
            'reason' => '核实一单位装卸遗失，原实收量已排除', 'responsibility' => '最高权限确认门店承担，不登记责任应收'];
        $command = $this->command(0) + ['type' => 'purchase_arrival_loss', 'payload' => $payload];
        self::assertSame('异常到货损耗仓', FinanceBusinessLogic::options(['type' => 'purchase_arrival_loss', 'subject_id' => $vendor])['arrivals'][0]['warehouse_name']);
        $bad = $payload; $bad['excluded_from_received_confirmed'] = 0;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival_loss', 'payload' => $bad]));
        $bad = $payload; $bad['quantity'] = '2';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival_loss', 'payload' => $bad]));
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['type' => 'purchase_arrival_loss', 'action' => 'record', 'payload' => $payload]);
        self::assertSame($initialPrice === null, $preview['purchase']['cost_pending']);
        $loss = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($loss, FinanceBusinessLogic::getError());
        self::assertSame($loss, FinanceBusinessLogic::action('record', $command));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival_loss', 'payload' => $payload]));
        self::assertSame($initialPrice === null ? '0.00' : '2.00', $loss['confirmed_result']['loss_known_amount']);
        self::assertSame($initialPrice === null, $loss['confirmed_result']['cost_pending']);
        self::assertSame($warehouse, $loss['confirmed_result']['warehouse_id']); self::assertSame('异常到货损耗仓', $loss['confirmed_result']['warehouse_name']);
        self::assertSame($preview['cost_impacts'], $loss['confirmed_result']['cost_impacts']);
        self::assertSame($preview['posting_months'], $loss['confirmed_result']['posting_months']);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('399.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($initialPrice === null ? null : '798.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame($initialPrice === null ? null : '200.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:909')['cost']);
        self::assertSame($initialPrice === null ? null : '2.000000', $cost->destination($warehouse, $sku, 'loss', 'purchase-arrival-loss:' . $loss['id'])['cost']);
        self::assertCount(0, FinanceBusinessLogic::options(['type' => 'purchase_difference', 'subject_id' => $vendor])['arrivals']);
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'supplier_confirmed' => 1, 'supplier_confirmation' => '供方确认计费五百、单价三元', 'reason' => '异常损失已独立确认',
            'lines' => [['arrival_line_id' => $arrivalId, 'covered_quantity' => '499', 'settlement_quantity' => '500', 'price' => '3.00',
                'arrival_difference_confirmed' => 1, 'arrival_difference_class' => 'loss', 'arrival_difference_reason' => '关联已确认损失',
                'difference_confirmed' => 1, 'difference_class' => 'normal', 'difference_reason' => '按双方约定报量计费']]]]); self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        self::assertSame('1197.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('300.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:909')['cost']);
        self::assertSame('3.000000', $cost->destination($warehouse, $sku, 'loss', 'purchase-arrival-loss:' . $loss['id'])['cost']);
        self::assertSame('1500.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
        $freight = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => ['subject_id' => $vendor,
            'cost_kind' => 'freight', 'necessary_confirmed' => 1, 'amount' => '500.00', 'actual_date' => date('Y-m-d'), 'source_reference' => 'LOSS-FREIGHT',
            'reason' => '本批直接必要运输', 'attribution_basis' => '全部归属本次到货', 'lines' => [['arrival_line_id' => $arrivalId, 'amount' => '500.00']]]]);
        self::assertNotFalse($freight, FinanceBusinessLogic::getError());
        self::assertSame('1596.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('400.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:909')['cost']);
        self::assertSame('4.000000', $cost->destination($warehouse, $sku, 'loss', 'purchase-arrival-loss:' . $loss['id'])['cost']);
    }

    public function test_purchase_arrival_difference_queue_retains_dispute_and_original_rule_until_explicit_resolution(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('到货差复核仓');
        $goods = $this->createCustomerReportGoods('差异复核商品', 'FIN-DIFF-QUEUE'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '重量差复核供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'DIFF-ARR', 'reason' => '实收与报量分别保留',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '499', 'reported_quantity' => '500', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivalId = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $options = FinanceBusinessLogic::options(['type' => 'purchase_difference', 'subject_id' => $vendor]);
        self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertCount(1, $options['arrivals']);
        self::assertSame('-1.0000', $options['arrivals'][0]['assessment']['quantity']); self::assertTrue($options['arrivals'][0]['assessment']['requires_owner']);
        $rule = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => ['rule_kind' => 'difference', 'scope' => 'store',
            'expected_rule_version' => 0, 'absolute_limit' => '2', 'percent_limit' => '1', 'reason' => '后设规则不改变原到货门槛']]); self::assertNotFalse($rule);
        $employee = WorkforceLogic::saveEmployee(['name' => '到货差经办', 'mobile' => '13800009929', 'bind_user_id' => 996929,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.purchase.confirm']]); self::assertNotFalse($employee);
        $payload = ['subject_id' => $vendor, 'arrival_line_id' => $arrivalId, 'expected_review_id' => 0, 'classification' => 'dispute',
            'reason' => '报量与收货记录待双方逐项核对', 'responsibility' => '暂未查明责任，不认定门店损失', 'review_confirmed' => 1];
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996929; request()->adminId = 0;
        $normal = $payload; $normal['classification'] = 'normal';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_difference', 'payload' => $normal]));
        self::assertTrue(FinanceBusinessLogic::options(['type' => 'purchase_difference', 'subject_id' => $vendor])['arrivals'][0]['assessment']['requires_owner']);
        $this->prepareCustomerReportRequestContext();
        $command = $this->command(0) + ['type' => 'purchase_difference', 'payload' => $payload];
        $review = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($review, FinanceBusinessLogic::getError());
        self::assertSame($review, FinanceBusinessLogic::action('record', $command)); self::assertFalse($review['confirmed_result']['resolved']);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertNull($cost->balance($warehouse, $sku)['value']); self::assertSame('1000.000000', $cost->balance($warehouse, $sku)['known_value']);
        self::assertSame('499.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
        $settlement = ['subject_id' => $vendor, 'supplier_confirmed' => 1, 'supplier_confirmation' => '供方认可按报量计费', 'reason' => '核对正式应付',
            'lines' => [['arrival_line_id' => $arrivalId, 'covered_quantity' => '499', 'settlement_quantity' => '500', 'price' => '2.00',
                'arrival_difference_confirmed' => 1, 'arrival_difference_class' => 'normal', 'arrival_difference_reason' => '不能跳过独立争议复核',
                'difference_confirmed' => 1, 'difference_class' => 'normal', 'difference_reason' => '按报量计费', 'difference_rule_id' => $rule['confirmed_result']['rule']['id']]]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $settlement]));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_difference', 'payload' => $payload]));
        $payload['expected_review_id'] = $review['confirmed_result']['review_id']; $payload['classification'] = 'normal'; $payload['reason'] = '双方核实正常行业允差';
        $resolved = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_difference', 'payload' => $payload]);
        self::assertNotFalse($resolved, FinanceBusinessLogic::getError()); self::assertTrue($resolved['confirmed_result']['resolved']);
        self::assertSame('1000.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertCount(0, FinanceBusinessLogic::options(['type' => 'purchase_difference', 'subject_id' => $vendor])['arrivals']);
        $original = FinanceBusinessLogic::detail(['id' => $review['id']]); self::assertSame('dispute', $original['confirmed_result']['classification']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $settlement]), FinanceBusinessLogic::getError());
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
    }

    public static function disputeInitialPrices(): array { return ['已知暂估后补价' => ['2.00'], '未知成本后确认' => [null]]; }

    /** @dataProvider disputeInitialPrices */
    public function test_purchase_return_dispute_restores_original_cost_to_actual_warehouse_and_reclassifies_loss_without_stock_change(?string $initialPrice): void
    {
        $day = date('Y-m-01', strtotime('-1 month')); $this->activate('cash', $day);
        $warehouse = $this->createCustomerReportWarehouse('退货争议原仓'); $to = $this->createCustomerReportWarehouse('实际退回仓');
        $goods = $this->createCustomerReportGoods('退货争议商品', 'FIN-RETURN-DISPUTE'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '争议处置供应商']); $arrivals = [];
        foreach ([[$warehouse, $initialPrice], [$to, '4.00']] as [$store, $price]) {
            $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
                'warehouse_id' => $store, 'actual_date' => $store === $warehouse ? $day : date('Y-m-d'), 'source_reference' => 'DISPUTE-' . $price, 'reason' => '实际到货',
                'lines' => [['sku_id' => $sku, 'actual_quantity' => '100'] + ($price === null ? [] : ['agreed_price' => $price])]]]);
            self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivals[] = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        }
        $returned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => $day, 'source_reference' => 'DISPUTE-OUT', 'reason' => '实际退离待核实',
            'lines' => [['arrival_line_id' => $arrivals[0], 'quantity' => '10']]]]); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        $returnId = $returned['confirmed_result']['lines'][0]['return_line_id'];
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'dispute-final-price', 'type' => 'adjust', 'sku_id' => $sku,
            'warehouse_id' => $warehouse, 'business_date' => $day, 'origin' => 'purchase-arrival:' . $arrivals[0], 'amount' => '300.00']));
        $payload = ['subject_id' => $vendor, 'return_line_id' => $returnId, 'expected_resolution_id' => 0, 'kind' => 'returned',
            'quantity' => '4', 'warehouse_id' => $to, 'actual_date' => $day, 'source_reference' => 'BACK-HANDOFF', 'reason' => '供方不收、四单位已实际运回'];
        $command = $this->command(0) + ['type' => 'purchase_return_resolution', 'payload' => $payload];
        $back = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($back, FinanceBusinessLogic::getError()); self::assertSame($back, FinanceBusinessLogic::action('record', $command));
        self::assertSame('12.000000', $back['confirmed_result']['resolved_cost']); self::assertSame('6.0000', $back['confirmed_result']['remaining_quantity']);
        self::assertFalse($back['confirmed_result']['cost_pending']);
        self::assertSame('412.000000', $cost->balance($to, $sku)['value']); self::assertSame('104.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($to, $sku));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_return_actual', 'subject_id' => $vendor]);
        $original = array_values(array_filter($options['arrivals'], static fn(array $row): bool => $row['arrival_line_id'] === $arrivals[0]))[0];
        self::assertSame('94.0000', $original['returnable_quantity']);
        $payload['kind'] = 'loss'; $payload['quantity'] = '2'; $payload['expected_resolution_id'] = $back['confirmed_result']['resolution_id'];
        $payload['reason'] = '已核实无法收回，最高权限确认由门店承担';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_resolution', 'payload' => $payload]));
        $payload['loss_confirmed'] = 1;
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['type' => 'purchase_return_resolution', 'action' => 'record', 'payload' => $payload]);
        self::assertSame('6.000000', $preview['purchase']['resolved_cost']);
        $lost = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_resolution', 'payload' => $payload]); self::assertNotFalse($lost, FinanceBusinessLogic::getError());
        self::assertSame('4.0000', $lost['confirmed_result']['remaining_quantity']);
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('104.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($to, $sku));
        self::assertSame('6.000000', $lost['confirmed_result']['resolved_cost']);
        self::assertFalse($lost['confirmed_result']['cost_pending']);
        self::assertSame($preview['cost_impacts'], $lost['confirmed_result']['cost_impacts']);
        self::assertSame($preview['posting_months'], $lost['confirmed_result']['posting_months']);
        self::assertNotEmpty($lost['confirmed_result']['cost_impacts']);
        $stored = \app\api\jxc\logic\FinanceValue::decode(Db::name('finance_purchase_return_resolution')->where('id', $lost['confirmed_result']['resolution_id'])->value('snapshot'));
        $sortFields = static function (array $row): array { ksort($row); return $row; };
        self::assertSame(array_map($sortFields, $lost['confirmed_result']['cost_impacts']), array_map($sortFields, $stored['cost_impacts']));
        self::assertSame('6.000000', $cost->destination($warehouse, $sku, 'loss', 'purchase-return-loss:' . $lost['id'])['cost']);
        self::assertSame('12.000000', $cost->destination($warehouse, $sku, 'return', 'purchase-return:' . $returnId)['cost']);
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
    }

    public function test_return_back_heals_its_own_negative_source_before_an_earlier_unrelated_return(): void
    {
        $this->activate(); $from = $this->createCustomerReportWarehouse('原到货足量仓'); $warehouse = $this->createCustomerReportWarehouse('漏记调拨退离仓');
        $goods = $this->createCustomerReportGoods('负库存返回商品', 'FIN-NEG-BACK'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '返回原负量供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $from, 'actual_date' => date('Y-m-d'), 'source_reference' => 'NEG-BACK-ARR', 'reason' => '成本待确认的实际到货',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]); self::assertNotFalse($arrival);
        $returns = [];
        for ($i = 0; $i < 2; $i++) {
            $return = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor,
                'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'NEG-BACK-OUT-' . $i, 'reason' => '实际退离，漏记来源待查',
                'lines' => [['arrival_line_id' => $arrival['confirmed_result']['lines'][0]['arrival_line_id'], 'quantity' => '10']]]]); self::assertNotFalse($return);
            $returns[] = $return['confirmed_result']['lines'][0];
        }
        $back = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_resolution', 'payload' => ['subject_id' => $vendor,
            'return_line_id' => $returns[1]['return_line_id'], 'expected_resolution_id' => 0, 'kind' => 'returned', 'quantity' => '10', 'warehouse_id' => $warehouse,
            'actual_date' => date('Y-m-d'), 'source_reference' => 'NEG-BACK-IN', 'reason' => '第二次退离的十单位全部实际返回']]); self::assertNotFalse($back, FinanceBusinessLogic::getError());
        $sources = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->column('remaining_qty', 'id');
        self::assertSame('0.0000', $sources[$returns[1]['negative_attribution_id']]); self::assertSame('10.0000', $sources[$returns[0]['negative_attribution_id']]);
        self::assertCount(1, \app\api\jxc\logic\NegativeInventoryLogic::todos()['lists']);
        self::assertSame('-10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
    }

    public function test_supplier_return_acceptance_closes_unsettled_quantity_and_explicitly_credits_settled_remainder(): void
    {
        $day = date('Y-m-d', strtotime('-1 day')); $this->activate('cash', date('Y-m-01', strtotime('-1 month')));
        $warehouse = $this->createCustomerReportWarehouse('退货认可仓');
        $goods = $this->createCustomerReportGoods('供方部分认可商品', 'FIN-RETURN-ACCEPT'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '退货认可供应商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => $day, 'source_reference' => 'ACCEPT-ARRIVAL', 'reason' => '真实到货暂无价格',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivalId = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $return = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => $day, 'source_reference' => 'ACCEPT-RETURN', 'reason' => '已实际退离等待供方核实',
            'lines' => [['arrival_line_id' => $arrivalId, 'quantity' => '10']]]]);
        self::assertNotFalse($return, FinanceBusinessLogic::getError()); $returnId = $return['confirmed_result']['lines'][0]['return_line_id'];
        $payload = ['subject_id' => $vendor, 'return_line_id' => $returnId, 'expected_resolution_id' => 0,
            'accepted_quantity' => '8', 'unsettled_quantity' => '8', 'unsettled_amount' => '16.00', 'credit_amount' => '0.00',
            'actual_date' => date('Y-m-d'), 'supplier_confirmed' => 1, 'supplier_confirmation' => '供方回执确认八单位、余二待查',
            'credit_reviewed' => 1, 'credit_allocations' => [], 'reason' => '按供方实际认可登记'];
        $command = $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload];
        $accepted = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($accepted, FinanceBusinessLogic::getError());
        self::assertSame($accepted, FinanceBusinessLogic::action('record', $command));
        self::assertSame('2.0000', $accepted['confirmed_result']['disputed_quantity']);
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('0.00', $ledger->categoryBalance('payable', $vendor));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_settlement', 'subject_id' => $vendor]); self::assertSame('92.0000', $options['arrivals'][0]['pending_quantity']);
        $old = \app\api\jxc\logic\FinanceStatementSnapshot::pendingArrivals($vendor, $day); self::assertSame('100.0000', $old[0]['pending_quantity']);
        $current = \app\api\jxc\logic\FinanceStatementSnapshot::pendingArrivals($vendor, date('Y-m-d')); self::assertSame('92.0000', $current[0]['pending_quantity']);
        $bad = $payload; $bad['expected_resolution_id'] = $accepted['confirmed_result']['resolution_id']; $bad['accepted_quantity'] = '3'; $bad['unsettled_quantity'] = '3';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $bad]));
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '剩余到货结算', 'supplier_confirmation' => '供方确认剩余九十二单位', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $arrivalId, 'covered_quantity' => '92', 'settlement_quantity' => '92', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError()); self::assertSame('184.00', $ledger->categoryBalance('payable', $vendor));
        $payload['expected_resolution_id'] = $accepted['confirmed_result']['resolution_id']; $payload['accepted_quantity'] = '2';
        $payload['unsettled_quantity'] = '0'; $payload['unsettled_amount'] = '0.00'; $payload['credit_amount'] = '4.00';
        $payload['credit_allocations'] = [['source' => $settled['confirmed_result']['created_sources'][0], 'amount' => '4.00']];
        $last = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload]); self::assertNotFalse($last, FinanceBusinessLogic::getError());
        self::assertSame('0.0000', $last['confirmed_result']['disputed_quantity']);
        self::assertSame('180.00', $ledger->categoryBalance('payable', $vendor)); self::assertSame('0.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID); self::assertSame('180.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertFalse($cost->balance($warehouse, $sku)['pending']);
        self::assertSame('20.000000', $cost->destination($warehouse, $sku, 'return', 'purchase-return:' . $returnId)['cost']);
        $paid = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => ['subject_id' => $vendor,
            'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '180.00', 'reason' => '支付已确认未付采购',
            'allocations' => [['source' => $settled['confirmed_result']['created_sources'][0], 'amount' => '180.00']]]]);
        self::assertNotFalse($paid, FinanceBusinessLogic::getError());
        $extra = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ACCEPT-EXTRA', 'reason' => '另两单位已真实退离',
            'lines' => [['arrival_line_id' => $arrivalId, 'quantity' => '2']]]]); self::assertNotFalse($extra, FinanceBusinessLogic::getError());
        $payload['return_line_id'] = $extra['confirmed_result']['lines'][0]['return_line_id']; $payload['expected_resolution_id'] = 0;
        $payload['credit_allocations'] = []; $payload['accepted_quantity'] = '0'; $payload['credit_amount'] = '0.00';
        $rejected = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload]); self::assertNotFalse($rejected, FinanceBusinessLogic::getError());
        self::assertSame('2.0000', $rejected['confirmed_result']['disputed_quantity']); self::assertSame([], $rejected['confirmed_result']['created_sources']);
        $payload['accepted_quantity'] = '2'; $payload['credit_amount'] = '4.00'; $payload['expected_resolution_id'] = $rejected['confirmed_result']['resolution_id'];
        $foreign = $payload; $foreign['subject_id'] = $vendor + 1;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $foreign]));
        $refundCommand = $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($refundCommand + ['action' => 'record']); self::assertSame('4.00', $preview['purchase']['refund_remaining']);
        self::assertSame('0.00', $ledger->categoryBalance('supplier_refund', $vendor));
        $refund = FinanceBusinessLogic::action('record', $refundCommand); self::assertNotFalse($refund, FinanceBusinessLogic::getError());
        self::assertSame('4.00', $ledger->categoryBalance('supplier_refund', $vendor)); self::assertSame('0.00', $ledger->categoryBalance('payable', $vendor));
        self::assertSame('4820.00', $ledger->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload]));
        self::assertStringContainsString('后续处理', FinanceBusinessLogic::getError());
        $employee = WorkforceLogic::saveEmployee(['name' => '退货经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.purchase.prepare']]); self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        self::assertFalse(FinanceBusinessLogic::action('record', $refundCommand));
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'purchase_return_acceptance', 'subject_id' => $vendor])['can_confirm']);
    }

    public function test_physical_purchase_return_uses_warehouse_average_cost_without_reducing_payable_and_preview_rolls_back(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('退货仓');
        $goods = $this->createCustomerReportGoods('混批退货商品', 'FIN-PUR-RETURN'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '实物退货供应商']);
        $arrivals = [];
        foreach (['2.00', '4.00'] as $price) {
            $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
                'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'RET-ARR-' . $price, 'reason' => '实际验收',
                'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => $price]]]]);
            self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivals[] = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        }
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '第一批确认', 'supplier_confirmation' => '供方确认', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $arrivals[0], 'covered_quantity' => '100', 'settlement_quantity' => '100', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError()); $ledger = new FinanceLedger(self::TENANT_ID); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => '实物交接 RET-001',
            'reason' => '商品已实际退离门店，等待供应商认可', 'lines' => [['arrival_line_id' => $arrivals[0], 'quantity' => '10']]];
        $command = $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame('30.000000', $preview['purchase']['lines'][0]['return_cost']);
        self::assertSame('200.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('600.000000', $cost->balance($warehouse, $sku)['value']);
        $returned = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        self::assertSame($returned, FinanceBusinessLogic::action('record', $command));
        self::assertSame('30.000000', $returned['confirmed_result']['lines'][0]['return_cost']);
        self::assertSame('190.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('570.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('200.00', $ledger->categoryBalance('payable', $vendor)); self::assertSame('0.00', $ledger->categoryBalance('supplier_refund', $vendor));
        $bad = $payload; $bad['lines'][] = ['arrival_line_id' => 999999999, 'quantity' => '1'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $bad]));
        self::assertSame('190.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('570.000000', $cost->balance($warehouse, $sku)['value']);
    }

    public function test_backdated_purchase_return_replays_actual_cost_order_and_keeps_later_stock(): void
    {
        $firstDay = date('Y-m-01', strtotime('-1 month')); $returnDay = date('Y-m-d', strtotime($firstDay . ' +1 day')); $laterDay = date('Y-m-01');
        $this->activate('cash', date('Y-m-01', strtotime('-1 month')));
        $warehouse = $this->createCustomerReportWarehouse('历史退离仓');
        $goods = $this->createCustomerReportGoods('乱序到货商品', 'FIN-HISTORY'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '历史退离供应商']);
        $arrivals = [];
        foreach ([[$firstDay, '2.00'], [$laterDay, '4.00']] as [$date, $price]) {
            $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
                'warehouse_id' => $warehouse, 'actual_date' => $date, 'source_reference' => 'HISTORY-' . $price, 'reason' => '实际验收',
                'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => $price]]]]);
            self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivals[] = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        }
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $sold = Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'later-sale', 'type' => 'issue', 'sku_id' => $sku,
            'warehouse_id' => $warehouse, 'business_date' => date('Y-m-d'), 'quantity' => '95', 'bucket' => 'sale', 'target_reference' => 'later-sale']));
        self::assertSame('285.000000', $sold['cost']);
        $command = $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor, 'warehouse_id' => $warehouse,
            'actual_date' => $returnDay, 'source_reference' => '历史交接记录', 'reason' => '补录已实际退离', 'lines' => [['arrival_line_id' => $arrivals[0], 'quantity' => '10']]]];
        $return = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($return, FinanceBusinessLogic::getError());
        self::assertSame('20.000000', $return['confirmed_result']['lines'][0]['return_cost']);
        self::assertSame('290.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('290.000000', $cost->destination($warehouse, $sku, 'sale', 'later-sale')['cost']);
        $previousInventory = Db::name('finance_cost_effect')->where('tenant_id', self::TENANT_ID)->where('sku_id', $sku)->where('bucket', 'inventory')
            ->where('business_date', '<', $laterDay)->field('SUM(value_delta) AS amount')->find();
        self::assertSame('180.000000', $previousInventory['amount']);
        $effects = Db::name('finance_cost_effect')->where('tenant_id', self::TENANT_ID)->where('reference', 'later-sale')->select()->toArray();
        foreach ($effects as $effect) { self::assertSame(date('Y-m-d'), $effect['business_date']); }
        self::assertSame('190.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($return, FinanceBusinessLogic::action('record', $command));
        self::assertSame('290.000000', $cost->balance($warehouse, $sku)['value']);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'historical-price', 'type' => 'adjust', 'sku_id' => $sku,
            'warehouse_id' => $warehouse, 'business_date' => $firstDay, 'origin' => 'purchase-arrival:' . $arrivals[0], 'amount' => '240.00']));
        self::assertSame('308.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('308.000000', $cost->destination($warehouse, $sku, 'sale', 'later-sale')['cost']);
        self::assertSame('24.000000', $cost->destination($warehouse, $sku, 'return', 'purchase-return:' . $return['confirmed_result']['lines'][0]['return_line_id'])['cost']);
    }

    public function test_purchase_return_retains_unknown_cost_and_negative_source_and_rejects_duplicate_or_excess_quantity(): void
    {
        $this->activate(); $from = $this->createCustomerReportWarehouse('原到货仓'); $warehouse = $this->createCustomerReportWarehouse('实物退离但漏记调拨仓');
        $goods = $this->createCustomerReportGoods('退货成本待确认商品', 'FIN-PUR-UNKNOWN'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '退货待核实供应商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $from, 'actual_date' => date('Y-m-d'), 'source_reference' => 'RET-UNKNOWN', 'reason' => '已到货尚未取得价格',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivalId = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'RET-FACT',
            'reason' => '已核实商品实际从本仓退离，漏记调拨待补录', 'lines' => [['arrival_line_id' => $arrivalId, 'quantity' => '10']]];
        $return = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $payload]);
        self::assertNotFalse($return, FinanceBusinessLogic::getError()); $line = $return['confirmed_result']['lines'][0];
        self::assertTrue($line['cost_pending']); self::assertNull($line['return_cost']); self::assertSame('10.0000', $line['negative_stock_quantity']);
        self::assertSame('-10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('10.000000000000', $cost->destination($warehouse, $sku, 'return', 'purchase-return:' . $line['return_line_id'])['pending_quantity']);
        $todos = \app\api\jxc\logic\NegativeInventoryLogic::todos();
        self::assertNotFalse($todos); self::assertCount(1, $todos['lists']);
        self::assertSame('10.0000', $todos['lists'][0]['remaining_qty']);
        self::assertStringContainsString('采购实际退货', $todos['lists'][0]['reason']);
        $bad = $payload; $bad['lines'][0]['quantity'] = '95';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $bad]));
        $bad = $payload; $bad['lines'][] = $bad['lines'][0];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $bad]));
        self::assertSame('-10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_return_actual', 'subject_id' => $vendor]);
        self::assertNotFalse($options); self::assertSame('90.0000', $options['arrivals'][0]['returnable_quantity']);
        self::assertFalse(FinanceBusinessLogic::action('reverse', $this->command(1) + ['id' => $return['id'], 'correction_reason' => '误操作请求']));
        self::assertStringContainsString('实际退离', FinanceBusinessLogic::getError());
        $again = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $payload]);
        self::assertNotFalse($again, FinanceBusinessLogic::getError());
        $todos = \app\api\jxc\logic\NegativeInventoryLogic::todos(); self::assertCount(2, $todos['lists']);
        foreach ($todos['lists'] as $todo) { self::assertSame('10.0000', $todo['remaining_qty']); }
    }

    public function test_purchase_arrival_preview_rolls_back_stock_and_unknown_cost_and_rejects_invalid_later_line(): void
    {
        $this->activate();
        $warehouse = $this->createCustomerReportWarehouse('预览到货仓');
        $goods = $this->createCustomerReportGoods('预览到货商品', 'FIN-ARR-PREVIEW'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '待确认价格供应商']);
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'),
            'source_reference' => '验收记录 ARR-002', 'reason' => '实际已到货，未取得可靠报价', 'lines' => [['sku_id' => $sku, 'actual_quantity' => '10']]];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['action' => 'record', 'type' => 'purchase_arrival', 'payload' => $payload]);
        self::assertTrue($preview['purchase']['cost_pending']);
        self::assertNull($preview['purchase']['lines'][0]['estimated_amount']);
        self::assertTrue($preview['cost_impacts'][0]['cost_pending']);
        self::assertNull($preview['cost_impacts'][0]['value_delta']);
        self::assertSame('0.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertCount(0, (new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID))->events($sku));
        $payload['lines'][] = ['sku_id' => 999999999, 'actual_quantity' => '2'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => $payload]));
        self::assertSame('0.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame(0, Db::name('finance_purchase_arrival_line')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_purchase_arrival_options_page_real_skus_and_keep_supplier_last_price_separate(): void
    {
        $this->activate();
        $warehouse = $this->createCustomerReportWarehouse('采购选择仓');
        $goods = $this->createCustomerReportGoods('采购选择商品', 'FIN-ARR-OPTIONS'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '有历史结算供应商']);
        Db::name('finance_purchase_price')->insert(['tenant_id' => self::TENANT_ID, 'vendor_id' => $vendor, 'sku_id' => $sku,
            'document_id' => 901, 'price' => '3.00', 'business_date' => date('Y-m-d'), 'create_time' => time()]);
        $options = FinanceBusinessLogic::options(['type' => 'purchase_arrival', 'subject_id' => $vendor, 'keyword' => '采购选择商品']);
        self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertContains($warehouse, array_column($options['warehouses'], 'id'));
        self::assertSame($sku, $options['sku_choices'][0]['sku_id']);
        self::assertSame('3.00', $options['sku_choices'][0]['last_formal_price']['price']);
        self::assertSame([], $options['sources']);
        self::assertTrue($options['can_prepare']);
    }

    public function test_purchase_arrival_prepare_permission_cannot_receive_and_revocation_blocks_cached_draft(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '到货经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.purchase.prepare']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        $command = $this->command(0) + ['type' => 'purchase_arrival', 'payload' => []];
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
        self::assertNotFalse(FinanceBusinessLogic::action('prepare', $command), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'purchase_arrival'])['can_confirm']);
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->delete();
        self::assertFalse(FinanceBusinessLogic::action('prepare', $command));
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_purchase_arrival_preview_includes_cost_of_negative_sales_in_an_earlier_open_month(): void
    {
        $date = date('Y-m-01', strtotime('first day of last month')); $this->activate('cash', $date);
        $warehouse = $this->createCustomerReportWarehouse('补负库存仓');
        $goods = $this->createCustomerReportGoods('补负库存商品', 'FIN-ARR-NEGATIVE'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '补负库存供应商']);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'prior-open-negative-sale', 'type' => 'issue',
            'sku_id' => $sku, 'warehouse_id' => $warehouse, 'business_date' => $date, 'quantity' => '3', 'bucket' => 'sale', 'target_reference' => 'prior-sale']));
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-NEG',
            'reason' => '取得可靠到货报价并补缺', 'lines' => [['sku_id' => $sku, 'actual_quantity' => '10', 'agreed_price' => '2.00']]];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['action' => 'record', 'type' => 'purchase_arrival', 'payload' => $payload]);
        self::assertContains(substr($date, 0, 7), $preview['posting_months']);
        self::assertContains(date('Y-m'), $preview['posting_months']);
        $sales = array_values(array_filter($preview['cost_impacts'], static fn(array $row): bool => $row['bucket'] === 'sale'));
        self::assertCount(1, $sales); self::assertSame('6.000000', $sales[0]['value_delta']);
        self::assertSame(substr($date, 0, 7), $sales[0]['posting_month']);
        self::assertNull($cost->destination($warehouse, $sku, 'sale', 'prior-sale')['cost']);
    }

    public function test_multi_sku_arrival_preserves_document_order_and_stock_when_lock_order_differs(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('多商品到货仓');
        $firstGoods = $this->createCustomerReportGoods('先建商品', 'FIN-ARR-FIRST'); $firstSku = $this->customerReportSkuId($firstGoods);
        $secondGoods = $this->createCustomerReportGoods('后建商品', 'FIN-ARR-SECOND'); $secondSku = $this->customerReportSkuId($secondGoods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '多商品到货供应商']);
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-MULTI', 'reason' => '按送货单顺序核实',
            'lines' => [['sku_id' => $secondSku, 'actual_quantity' => '20', 'agreed_price' => '3.00'], ['sku_id' => $firstSku, 'actual_quantity' => '10', 'agreed_price' => '2.00']]];
        $result = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => $payload]);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame([$secondSku, $firstSku], array_column($result['confirmed_result']['lines'], 'sku_id'));
        self::assertSame('10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $firstSku));
        self::assertSame('20.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $secondSku));
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $result['id'], 'payload' => $payload, 'correction_reason' => '试图覆盖实物记录']));
        self::assertSame('20.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $secondSku));
    }

    public function test_supplier_partial_settlement_creates_payables_and_completes_actual_coverage_without_receiving_again(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('分次供应商结算仓');
        $goods = $this->createCustomerReportGoods('分次供应商结算商品', 'FIN-PURCHASE-BATCH'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '分次结算供应商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-PART', 'reason' => '尚无可靠报价',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '30', 901, 'sales', 'SALE-PART', '', $sku));
        $payload = ['subject_id' => $vendor, 'reason' => '已逐项与供应商核对', 'supplier_confirmation' => '供应商结算单 SET-PART', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'covered_quantity' => '40', 'settlement_quantity' => '41', 'price' => '2.00', 'terms_version' => 0,
                'difference_class' => 'normal', 'difference_reason' => '双方确认一斤行业允差', 'difference_confirmed' => 1]]];
        $command = $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload];
        $first = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($first, FinanceBusinessLogic::getError()); self::assertSame($first, FinanceBusinessLogic::action('record', $command));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertNull($cost->balance($warehouse, $sku)['value']); self::assertSame('57.400000', $cost->balance($warehouse, $sku)['known_value']);
        self::assertSame('82.00', (new FinanceLedger(self::TENANT_ID))->source($first['confirmed_result']['created_sources'][0])['balance']);
        $options = FinanceBusinessLogic::options(['type' => 'purchase_settlement', 'subject_id' => $vendor]);
        self::assertSame('60.0000', $options['arrivals'][0]['pending_quantity']);
        self::assertSame('100.0000', $options['arrivals'][0]['actual_quantity']);
        $payload['lines'][0]['covered_quantity'] = '60'; $payload['lines'][0]['settlement_quantity'] = '60';
        $last = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload]);
        self::assertNotFalse($last, FinanceBusinessLogic::getError());
        self::assertSame('0.0000', $last['confirmed_result']['lines'][0]['pending_quantity']);
        self::assertSame('70.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('141.400000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('60.600000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:901')['cost']);
        self::assertSame('202.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'purchase_settlement', 'subject_id' => $vendor])['arrivals']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload]));
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_necessary_purchase_freight_remains_in_cost_after_formal_settlement_without_extra_stock_or_cash(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('采购运费仓');
        $goods = $this->createCustomerReportGoods('采购运费商品', 'FIN-FREIGHT'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '商品供应商']);
        $carrier = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '运输服务商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-FREIGHT', 'reason' => '验收入库尚未取得商品报价',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '30', 902, 'sales', 'SALE-FREIGHT', '', $sku));
        $payload = ['subject_id' => $carrier, 'actual_date' => date('Y-m-d'), 'amount' => '20.00', 'cost_kind' => 'freight',
            'source_reference' => '运输账单 FR-001', 'reason' => '入库前直接必要运输', 'attribution_basis' => '本次运输仅此到货', 'necessary_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'amount' => '20.00']]];
        $command = $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame('20.00', $preview['purchase']['amount']); self::assertNotEmpty($preview['cost_impacts']);
        self::assertSame(0, Db::name('finance_purchase_cost_change')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('expense_payable', $carrier));
        $extra = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($extra, FinanceBusinessLogic::getError()); self::assertSame($extra, FinanceBusinessLogic::action('record', $command));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID); $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertNull($cost->balance($warehouse, $sku)['value']); self::assertSame('14.000000', $cost->balance($warehouse, $sku)['known_value']);
        self::assertSame('20.00', $ledger->categoryBalance('expense_payable', $carrier));
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => $payload]));
        self::assertStringContainsString('已登记', FinanceBusinessLogic::getError());
        $invalid = $payload; $invalid['source_reference'] = 'FR-BAD-TOTAL'; $invalid['amount'] = '21.00';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => $invalid]));
        self::assertSame('20.00', $ledger->categoryBalance('expense_payable', $carrier));
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '确认商品价', 'supplier_confirmation' => '供方确认', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'covered_quantity' => '100', 'settlement_quantity' => '100', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        self::assertSame('154.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('66.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:902')['cost']);
        self::assertSame('200.00', $ledger->categoryBalance('payable', $vendor));
        self::assertSame('70.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
        $payload['cost_kind'] = 'sales_delivery';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => $payload]));
        self::assertSame('154.000000', $cost->balance($warehouse, $sku)['value']);
    }

    public function test_purchase_rule_priority_and_arrival_snapshot_do_not_change_when_later_rules_are_saved(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('采购规则仓');
        $goods = $this->createCustomerReportGoods('采购规则商品', 'FIN-PUR-RULE'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '采购规则供应商']);
        $rule = ['rule_kind' => 'difference', 'scope' => 'store', 'expected_rule_version' => 0, 'absolute_limit' => '2', 'percent_limit' => '1', 'reason' => '门店复核基准'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => $rule]), FinanceBusinessLogic::getError());
        $rule['scope'] = 'sku'; $rule['sku_id'] = $sku; $rule['absolute_limit'] = '3';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => $rule]), FinanceBusinessLogic::getError());
        $before = \app\api\jxc\logic\FinancePurchaseRuleBook::threshold($vendor, $sku, 0);
        self::assertSame('sku', $before['scope']); self::assertSame('3.0000', $before['absolute_limit']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-RULE', 'reason' => '实际验收入库',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'reported_quantity' => '101', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $rule['scope'] = 'vendor_sku'; $rule['subject_id'] = $vendor; $rule['absolute_limit'] = '0.1';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => $rule]), FinanceBusinessLogic::getError());
        self::assertSame('vendor_sku', \app\api\jxc\logic\FinancePurchaseRuleBook::threshold($vendor, $sku, 0)['scope']);
        $stored = FinanceBusinessLogic::detail(['id' => $arrival['id']]);
        self::assertSame('sku', $stored['confirmed_result']['lines'][0]['difference_rule']['scope']);
        self::assertSame('3.0000', $stored['confirmed_result']['lines'][0]['difference_rule']['absolute_limit']);
    }

    public function test_purchase_amount_adjustment_keeps_coverage_and_uses_explicit_supplier_credit_with_refund_remainder(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('采购调价仓');
        $goods = $this->createCustomerReportGoods('采购调价商品', 'FIN-PUR-ADJUST'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '调价供应商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-ADJUST', 'reason' => '已验收',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '双方确认', 'supplier_confirmation' => '结算单确认', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'covered_quantity' => '100', 'settlement_quantity' => '100', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '30', 903, 'sales', 'SALE-ADJUST', '', $sku));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_adjustment', 'subject_id' => $vendor]);
        self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertSame('200.00', $options['settlements'][0]['current_amount']);
        self::assertSame(0, $options['settlements'][0]['expected_adjustment_id']);
        $original = $settled['confirmed_result']['created_sources'][0];
        $settlementLine = (int)Db::name('finance_purchase_settlement_line')->where('tenant_id', self::TENANT_ID)->where('document_id', $settled['id'])->value('id');
        $payload = ['subject_id' => $vendor, 'settlement_line_id' => $settlementLine, 'expected_adjustment_id' => 0, 'new_amount' => '150.00',
            'reason' => '双方另行约定降价，不变更实物与处理量', 'supplier_confirmed' => 1, 'supplier_confirmation' => '调价确认 ADJ-1',
            'credit_reviewed' => 1, 'credit_allocations' => [['source' => $original, 'amount' => '30.00']]];
        $command = $this->command(0) + ['type' => 'purchase_adjustment', 'payload' => $payload];
        $adjusted = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($adjusted, FinanceBusinessLogic::getError()); self::assertSame($adjusted, FinanceBusinessLogic::action('record', $command));
        $ledger = new FinanceLedger(self::TENANT_ID); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('170.00', $ledger->source($original)['balance']);
        self::assertSame('20.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame('105.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('45.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:903')['cost']);
        self::assertSame('100.0000', Db::name('finance_purchase_settlement_line')->where('id', $settlementLine)->value('covered_quantity'));
        self::assertSame('200.00', Db::name('finance_purchase_settlement_line')->where('id', $settlementLine)->value('amount'));
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_adjustment', 'payload' => $payload]));
        $payload['expected_adjustment_id'] = $adjusted['confirmed_result']['adjustment_id']; $payload['new_amount'] = '180.00'; $payload['credit_allocations'] = [];
        $increased = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_adjustment', 'payload' => $payload]);
        self::assertNotFalse($increased, FinanceBusinessLogic::getError());
        self::assertSame('126.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('200.00', $ledger->categoryBalance('payable', $vendor));
        self::assertSame('20.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_supplier_refund_credit_can_offset_later_payable_and_reverse_without_cash_or_second_cost(): void
    {
        $oldDate = date('Y-m-01', strtotime('first day of last month')); $this->activate('cash', $oldDate);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '后续抵扣供应商']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        $credit = $ledger->createSource(2001, 'supplier_refund', $vendor, '50', $oldDate, null, ['subject_name' => '后续抵扣供应商']);
        $payable = $ledger->createSource(2002, 'payable', $vendor, '100', date('Y-m-d'), null, ['subject_name' => '后续抵扣供应商']);
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => substr($oldDate, 0, 7), 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $payload = ['subject_id' => $vendor, 'credit_source' => $credit, 'reason' => '双方确认使用原应退款抵扣后续采购', 'allocations' => [['source' => $payable, 'amount' => '30']]];
        $command = $this->command(0) + ['type' => 'supplier_credit_allocate', 'payload' => $payload];
        $applied = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($applied, FinanceBusinessLogic::getError()); self::assertSame($applied, FinanceBusinessLogic::action('record', $command));
        self::assertSame('20.00', $ledger->source($credit)['balance']); self::assertSame('70.00', $ledger->source($payable)['balance']);
        $entries = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $applied['id'])->select()->toArray();
        self::assertCount(2, $entries); self::assertSame([date('Y-m')], array_values(array_unique(array_column($entries, 'posting_month'))));
        self::assertSame([date('Y-m-d')], array_values(array_unique(array_column($entries, 'effective_date'))));
        $reversed = FinanceBusinessLogic::action('reverse', $this->command($applied['version']) + ['id' => $applied['id'], 'correction_reason' => '双方取消本次抵扣，恢复原未结项']);
        self::assertNotFalse($reversed, FinanceBusinessLogic::getError());
        self::assertSame('50.00', $ledger->source($credit)['balance']); self::assertSame('100.00', $ledger->source($payable)['balance']);
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_unknown_date_opening_supplier_credit_uses_target_month_and_rejects_foreign_or_excess_allocation(): void
    {
        $oldDate = date('Y-m-01', strtotime('first day of last month')); $this->activate('cash', $oldDate);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '期初贷项供应商']);
        $creditId = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'opening_item_id' => 990010,
            'category' => 'supplier_refund', 'subject_id' => $vendor, 'amount' => '50.00', 'activation_date' => $oldDate,
            'source_snapshot' => json_encode(['historical_date' => null, 'subject_name' => '期初贷项供应商', 'source_reference' => '已核实期初应退款']), 'create_time' => time()]);
        $credit = 'o:' . $creditId; $ledger = new FinanceLedger(self::TENANT_ID);
        $payable = $ledger->createSource(2011, 'expense_payable', $vendor, '100', date('Y-m-d'), null, []);
        $foreign = $ledger->createSource(2012, 'payable', $vendor + 1, '100', date('Y-m-d'), null, []);
        $payload = ['subject_id' => $vendor, 'credit_source' => $credit, 'reason' => '明确使用核实的期初应退款', 'allocations' => [['source' => $foreign, 'amount' => '30']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_credit_allocate', 'payload' => $payload]));
        $payload['allocations'] = [['source' => $payable, 'amount' => '60']];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_credit_allocate', 'payload' => $payload]));
        self::assertSame('100.00', $ledger->source($payable)['balance']); self::assertSame('50.00', $ledger->source($credit)['balance']);
        $payload['allocations'][0]['amount'] = '30';
        $result = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_credit_allocate', 'payload' => $payload]);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        $entries = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $result['id'])->select()->toArray();
        self::assertCount(2, $entries);
        foreach ($entries as $entry) { self::assertSame(date('Y-m'), $entry['posting_month']); self::assertNull($entry['effective_date']); }
        $options = FinanceBusinessLogic::options(['type' => 'supplier_credit_allocate', 'subject_id' => $vendor, 'role' => 'fund']);
        self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertSame($credit, $options['sources'][0]['reference']); self::assertSame('20.00', $options['sources'][0]['balance']);
        $oldStatement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor,
            'date_from' => $oldDate, 'date_to' => date('Y-m-t', strtotime($oldDate))]);
        self::assertSame('50.00', $oldStatement['snapshot']['balances']['supplier_refund']['closing']);
        self::assertSame('0.00', $oldStatement['snapshot']['balances']['expense_payable']['closing']);
        $currentStatement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        self::assertSame('20.00', $currentStatement['snapshot']['balances']['supplier_refund']['closing']);
        self::assertSame('70.00', $currentStatement['snapshot']['balances']['expense_payable']['closing']);
        $reverse = FinanceBusinessLogic::action('reverse', $this->command($result['version']) + ['id' => $result['id'], 'correction_reason' => '撤销本次抵扣，恢复待退款']);
        self::assertNotFalse($reverse, FinanceBusinessLogic::getError());
        $reversals = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $reverse['id'])->select()->toArray();
        self::assertCount(2, $reversals);
        foreach ($reversals as $entry) { self::assertSame(date('Y-m'), $entry['posting_month']); self::assertNull($entry['effective_date']); }
        self::assertSame('50.00', $ledger->source($credit)['balance']); self::assertSame('100.00', $ledger->source($payable)['balance']);
    }

    public function test_paid_purchase_overhead_reduction_revalues_cost_and_creates_refund_without_reopening_paid_bill(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('附加成本更正仓');
        $goods = $this->createCustomerReportGoods('附加成本更正商品', 'FIN-EXTRA-REV'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '附加成本服务方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-EXTRA-REV', 'reason' => '实际入库',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $extra = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => ['subject_id' => $vendor,
            'actual_date' => date('Y-m-d'), 'amount' => '20.00', 'cost_kind' => 'loading', 'source_reference' => 'LOAD-REV-1',
            'reason' => '入库必要装卸', 'attribution_basis' => '全部归属该批', 'necessary_confirmed' => 1, 'lines' => [['arrival_line_id' => $line, 'amount' => '20.00']]]]);
        self::assertNotFalse($extra, FinanceBusinessLogic::getError()); $payable = $extra['confirmed_result']['created_sources'][0];
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '30', 904, 'sales', 'SALE-EXTRA-REV', '', $sku));
        $paid = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => ['subject_id' => $vendor,
            'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '20.00', 'reason' => '实际支付装卸费用', 'allocations' => [['source' => $payable, 'amount' => '20.00']]]]);
        self::assertNotFalse($paid, FinanceBusinessLogic::getError());
        $payload = ['subject_id' => $vendor, 'original_cost_document_id' => $extra['id'], 'expected_revision_id' => 0, 'new_amount' => '10.00',
            'reason' => '装卸数量核对后减费', 'supplier_confirmation' => '服务方明确退还差额', 'supplier_confirmed' => 1,
            'attribution_basis' => '仍归属同一批入库', 'lines' => [['arrival_line_id' => $line, 'amount' => '10.00']], 'credit_reviewed' => 1, 'credit_allocations' => []];
        $command = $this->command(0) + ['type' => 'purchase_extra_adjustment', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame('-10.00', $preview['purchase']['amount_change']); self::assertNotEmpty($preview['cost_impacts']);
        self::assertSame(0, Db::name('finance_purchase_cost_revision')->where('tenant_id', self::TENANT_ID)->count());
        $adjusted = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($adjusted, FinanceBusinessLogic::getError()); self::assertSame($adjusted, FinanceBusinessLogic::action('record', $command));
        $ledger = new FinanceLedger(self::TENANT_ID); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('0.00', $ledger->source($payable)['balance']); self::assertSame('10.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame('147.000000', $cost->balance($warehouse, $sku)['value']); self::assertSame('63.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:904')['cost']);
        self::assertSame('4980.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('20.00', Db::name('finance_purchase_cost_bill')->where('tenant_id', self::TENANT_ID)->where('document_id', $extra['id'])->value('amount'));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_adjustment', 'payload' => $payload]));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_extra_adjustment', 'subject_id' => $vendor]);
        self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertSame('10.00', $options['bills'][0]['current_amount']);
        self::assertSame($adjusted['confirmed_result']['revision_id'], $options['bills'][0]['expected_revision_id']);
        $payload['expected_revision_id'] = $adjusted['confirmed_result']['revision_id']; $payload['new_amount'] = '0.00'; $payload['lines'] = [];
        $cancelled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_adjustment', 'payload' => $payload]);
        self::assertNotFalse($cancelled, FinanceBusinessLogic::getError());
        self::assertSame('140.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('20.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'purchase_extra_adjustment', 'subject_id' => $vendor])['bills'][0]['lines']);
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_supplier_settlement_within_threshold_still_needs_confirmation_and_uses_versioned_supplier_terms(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('采购复核仓');
        $goods = $this->createCustomerReportGoods('采购复核商品', 'FIN-PUR-REVIEW'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '有付款期限供应商']);
        $rule = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => ['rule_kind' => 'difference', 'scope' => 'store',
            'expected_rule_version' => 0, 'absolute_limit' => '2', 'percent_limit' => '2', 'reason' => '双方业务复核基准']]);
        self::assertNotFalse($rule, FinanceBusinessLogic::getError());
        $terms = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => ['rule_kind' => 'terms', 'subject_id' => $vendor,
            'expected_rule_version' => 0, 'mode' => 'days_after', 'days' => 7, 'reason' => '约定到货七天后付款']]);
        self::assertNotFalse($terms, FinanceBusinessLogic::getError());
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-REVIEW', 'reason' => '实际验收',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'reported_quantity' => '101', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $employee = WorkforceLogic::saveEmployee(['name' => '采购结算经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.purchase.confirm']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        $payload = ['subject_id' => $vendor, 'supplier_confirmation' => '结算单 REVIEW-1', 'supplier_confirmed' => 1, 'reason' => '核实本次结算',
            'lines' => [['arrival_line_id' => $arrival['confirmed_result']['lines'][0]['arrival_line_id'], 'covered_quantity' => '50', 'settlement_quantity' => '50.5',
                'price' => '2.00', 'terms_version' => 1, 'difference_rule_id' => $rule['confirmed_result']['rule']['id'],
                'difference_class' => 'normal', 'difference_reason' => '已核对计费允差', 'arrival_difference_class' => 'normal', 'arrival_difference_reason' => '已核对到货允差']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload]));
        $payload['lines'][0]['difference_confirmed'] = 1; $payload['lines'][0]['arrival_difference_confirmed'] = 1;
        $command = $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload];
        $result = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertFalse($result['confirmed_result']['lines'][0]['settlement_review']['requires_owner']);
        self::assertSame(date('Y-m-d', strtotime('+7 days')), $result['confirmed_result']['lines'][0]['due_date']);
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->delete();
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
    }

    public function test_purchase_arrival_records_actual_stock_and_estimate_once_without_formal_payable(): void
    {
        $this->activate();
        $warehouse = $this->createCustomerReportWarehouse('采购到货仓');
        $goods = $this->createCustomerReportGoods('采购到货商品', 'FIN-ARRIVAL'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '采购到货供应商']);
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'),
            'source_reference' => '供应商送货单 ARR-001', 'reason' => '已过磅验收入库',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'reported_quantity' => '101', 'agreed_price' => '2.50']]];
        $command = $this->command(0) + ['type' => 'purchase_arrival', 'payload' => $payload];
        $first = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($first, FinanceBusinessLogic::getError());
        self::assertSame($first, FinanceBusinessLogic::action('record', $command));
        self::assertSame('100.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('252.500000', (new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID))->balance($warehouse, $sku)['value']);
        self::assertSame('101.0000', $first['confirmed_result']['lines'][0]['estimated_quantity']);
        self::assertSame('100.0000', $first['confirmed_result']['lines'][0]['pending_quantity']);
        self::assertSame([], (new FinanceLedger(self::TENANT_ID))->sources('payable', $vendor));
        self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_negative_sale_cost_confirmation_keeps_original_date_and_posts_only_to_current_open_month(): void
    {
        $originalDate = date('Y-m-01', strtotime('first day of last month'));
        $this->activate('cash', $originalDate);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'old-negative-sale', 'type' => 'issue', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => $originalDate, 'quantity' => '30', 'bucket' => 'sale', 'target_reference' => 'old-negative-sale']));
        self::assertNull($cost->destination(10, 20, 'sale', 'old-negative-sale')['cost']);
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => substr($originalDate, 0, 7), 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'new-arrival', 'type' => 'receive', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'quantity' => '100', 'origin' => 'new-arrival', 'amount' => '1000.00']));
        self::assertSame('300.000000', $cost->destination(10, 20, 'sale', 'old-negative-sale')['cost']);
        $posts = array_values(array_filter($cost->postings(20), static fn(array $row): bool => $row['bucket'] === 'sale' && $row['value_delta'] === '300.000000'));
        self::assertCount(1, $posts); self::assertSame($originalDate, $posts[0]['business_date']); self::assertSame(date('Y-m'), $posts[0]['posting_month']);
        self::assertSame('700.000000', $cost->balance(10, 20)['value']);
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertSame([], (new \app\api\jxc\logic\FinanceCostLedger(self::OTHER_TENANT_ID))->events(20));
    }

    public function test_cost_book_carries_confirmed_opening_once_including_zero_inventory_without_new_stock(): void
    {
        $warehouse = $this->createCustomerReportWarehouse('期初成本仓库');
        $skuIds = [];
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => date('Y-m-01'),
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        foreach ([['10', '100'], ['0', '0']] as [$quantity, $amount]) {
            $goods = $this->createCustomerReportGoods('期初成本商品' . $quantity, 'COST-OPEN-' . $quantity); $sku = $this->customerReportSkuId($goods); $skuIds[] = $sku;
            $stock = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse,
                'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => $quantity, 'available_qty' => $quantity]);
            $this->opening('item', ['category' => 'inventory', 'subject_id' => $stock, 'amount' => $amount, 'historical_date' => null, 'due_date' => null,
                'source_mode' => 'detail', 'source_reference' => '截点库存' . $quantity, 'evidence' => '盘点核实', 'details' => ['quantity' => $quantity, 'origin_reference' => '成本核对']]);
        }
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $this->opening('submit'); $this->opening('confirm');
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('100.000000', $cost->balance($warehouse, $skuIds[0])['value']);
        self::assertSame('0.000000', $cost->balance($warehouse, $skuIds[1])['value']);
        $result = Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'opening-sale', 'type' => 'issue', 'sku_id' => $skuIds[0], 'warehouse_id' => $warehouse,
            'business_date' => date('Y-m-d'), 'quantity' => '4', 'bucket' => 'sale', 'target_reference' => 'opening-sale']));
        self::assertSame('40.000000', $result['cost']); self::assertSame('60.000000', $cost->balance($warehouse, $skuIds[0])['value']);
        self::assertSame('10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $skuIds[0]));
    }

    public function test_real_stock_flows_carry_cost_through_sale_transfer_and_actual_delivery_correction(): void
    {
        $this->activate();
        $warehouse = $this->createCustomerReportWarehouse('成本实物仓库'); $target = $this->createCustomerReportWarehouse('成本调入仓库');
        $goods = $this->createCustomerReportGoods('成本实物商品', 'COST-PHYSICAL'); $sku = $this->customerReportSkuId($goods);
        self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '10', 71, 'supply', 'COST-P71', '', $sku));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertNull($cost->balance($warehouse, $sku)['value']);
        $events = $cost->events($sku); self::assertCount(1, $events);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'physical-price-confirm', 'type' => 'adjust', 'sku_id' => $sku, 'warehouse_id' => $warehouse,
            'business_date' => date('Y-m-d'), 'origin' => $events[0]['snapshot']['origin'], 'amount' => '200.00']));
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '4', 77, 'sales', 'COST-S77', '', $sku));
        self::assertSame('80.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:77')['cost']);
        self::assertTrue(\app\api\jxc\logic\StockService::transfer($warehouse, $target, $goods, '2', 88, 'warehouse_transfer', 'COST-T88', '', $sku));
        self::assertSame('40.000000', $cost->balance($target, $sku)['value']);
        self::assertNotFalse(Db::transaction(fn() => \app\api\jxc\logic\StockService::inboundDeliveryCorrectionWithinTransaction($warehouse, $goods, $sku, '1', 77, 'COST-S77', 777, 888)));
        self::assertSame('60.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:77')['cost']);
        self::assertSame('100.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('5.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('2.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($target, $sku));
    }

    public function test_cost_confirmation_reloads_committed_facts_after_an_outer_transaction_created_an_older_snapshot(): void
    {
        $this->activate(); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'snapshot-arrival', 'type' => 'receive', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'quantity' => '10', 'origin' => 'snapshot-arrival', 'amount' => '100.00']));
        $event = ['reference' => 'snapshot-worker-sale', 'type' => 'issue', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'quantity' => '2', 'bucket' => 'sale', 'target_reference' => 'worker-sale'];
        $input = tempnam(sys_get_temp_dir(), 'cost-in-'); $output = tempnam(sys_get_temp_dir(), 'cost-out-');
        file_put_contents($input, json_encode(['tenant_id' => self::TENANT_ID, 'admin_id' => self::ADMIN_ID, 'event' => $event], JSON_THROW_ON_ERROR));
        Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); Db::startTrans();
        try {
            Db::name('finance_cost_origin')->where('tenant_id', self::TENANT_ID)->select();
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/finance_cost_worker.php', $input, $output],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            self::assertIsResource($process); fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            self::assertSame('20.000000', json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR)['cost']);
            $event['reference'] = 'snapshot-parent-sale'; $event['quantity'] = '3'; $event['target_reference'] = 'parent-sale';
            $cost->recordWithinTransaction($event); Db::commit();
            self::assertSame('50.000000', $cost->balance(10, 20)['value']);
            self::assertSame('20.000000', $cost->destination(10, 20, 'sale', 'worker-sale')['cost']);
            self::assertSame('30.000000', $cost->destination(10, 20, 'sale', 'parent-sale')['cost']);
        } finally {
            if (Db::connect()->getPdo()->inTransaction()) { Db::rollback(); }
            unlink($input); unlink($output);
        }
    }

    public function test_unpriced_cross_warehouse_return_survives_reload_and_partial_cost_funding(): void
    {
        $this->activate(); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $record = fn(array $event): array => Db::transaction(fn() => $cost->recordWithinTransaction($event + ['sku_id' => 20, 'warehouse_id' => 10, 'business_date' => date('Y-m-d')]));
        $record(['reference' => 'cross-sale', 'type' => 'issue', 'quantity' => '10', 'bucket' => 'sale', 'target_reference' => 'cross-sale']);
        $record(['reference' => 'cross-return', 'type' => 'restore', 'to_warehouse_id' => 11, 'quantity' => '4', 'bucket' => 'sale', 'target_reference' => 'cross-sale']);
        $record(['reference' => 'cross-resale', 'type' => 'issue', 'warehouse_id' => 11, 'quantity' => '2', 'bucket' => 'sale', 'target_reference' => 'cross-resale']);
        $record(['reference' => 'cross-arrival-1', 'type' => 'receive', 'origin' => 'cross-arrival-1', 'quantity' => '8', 'amount' => '80.00']);
        self::assertTrue($cost->balance(11, 20)['pending']); self::assertSame('10.000000', $cost->balance(11, 20)['known_value']);
        self::assertNull($cost->destination(11, 20, 'sale', 'cross-resale')['cost']);
        $record(['reference' => 'cross-arrival-2', 'type' => 'receive', 'origin' => 'cross-arrival-2', 'quantity' => '2', 'amount' => '40.00']);
        self::assertSame('30.000000', $cost->balance(11, 20)['value']);
        self::assertSame('30.000000', $cost->destination(11, 20, 'sale', 'cross-resale')['cost']);
        self::assertSame('60.000000', $cost->destination(10, 20, 'sale', 'cross-sale')['cost']);
        self::assertSame('0.000000000000', $cost->balance(10, 20)['quantity']);
    }

    public function test_cost_confirmation_cannot_ignore_a_month_closed_after_outer_transaction_snapshot(): void
    {
        $this->activate(); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $config = config('database.connections.mysql');
        $other = new \PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); Db::startTrans();
        try {
            Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->select();
            $other->beginTransaction();
            $lock = $other->prepare('SELECT tenant_id FROM ' . Db::name('finance_preparation')->getTable() . ' WHERE tenant_id=? FOR UPDATE'); $lock->execute([self::TENANT_ID]);
            $close = $other->prepare('INSERT INTO ' . Db::name('finance_period')->getTable() . ' (tenant_id,month,status,snapshot,closed_by,closed_at) VALUES (?, ?, ?, ?, ?, ?)');
            $close->execute([self::TENANT_ID, date('Y-m'), 'closed', '{}', '{}', time()]); $other->commit();
            $this->expectException(\DomainException::class); $this->expectExceptionMessage('当前自然月已结账');
            $cost->recordWithinTransaction(['reference' => 'closed-snapshot-arrival', 'type' => 'receive', 'sku_id' => 20, 'warehouse_id' => 10,
                'business_date' => date('Y-m-d'), 'quantity' => '1', 'origin' => 'closed-snapshot-arrival', 'amount' => '10.00']);
        } finally { Db::rollback(); }
    }

    public function test_loss_only_event_uses_exception_time_and_missing_inbound_amount_supplies_its_cost(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('损耗成本仓');
        $goods = $this->createCustomerReportGoods('损耗成本商品', 'LOSS-COST'); $sku = $this->customerReportSkuId($goods);
        $event = (int)Db::name('fulfillment_delivery_event')->insertGetId(['tenant_id' => self::TENANT_ID, 'idempotency_key' => 'loss-only-cost-event',
            'event_type' => 'delivery_exception', 'delivered_time' => 0, 'actual_handoff_time' => 0, 'create_time' => time()]);
        self::assertNotFalse(Db::transaction(fn() => \app\api\jxc\logic\StockService::outboundTransportLossWithinTransaction($warehouse, $goods, $sku, '2', '0', $event)));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame(date('Y-m-d'), $cost->events($sku)[0]['business_date']);
        self::assertNull($cost->destination($warehouse, $sku, 'loss', 'delivery_loss:' . $event)['cost']);
        self::assertNotFalse(Db::transaction(fn() => \app\api\jxc\logic\StockService::adjustNegativeWithinTransaction($warehouse, $goods, $sku, '2', 17,
            'record_missing_inbound', '核实遗漏进货金额', '50.00')));
        self::assertSame('50.000000', $cost->destination($warehouse, $sku, 'loss', 'delivery_loss:' . $event)['cost']);
        self::assertSame('0.000000000000', $cost->balance($warehouse, $sku)['quantity']);
    }

    public function test_processed_reduction_records_internal_loss_cost_but_keeps_other_consumption_unclassified(): void
    {
        $this->activate();
        foreach (['internal_loss' => 'loss', 'other' => 'pending'] as $disposition => $bucket) {
            $warehouse = $this->createCustomerReportWarehouse('加工成本仓' . $disposition); $goods = $this->createCustomerReportGoods('加工成本商品' . $disposition, 'PROCESS-COST-' . $disposition);
            $sku = $this->customerReportSkuId($goods); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
            self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '6', 72, 'supply', 'PROCESS-COST', '', $sku));
            $origin = $cost->events($sku)[0]['snapshot']['origin'];
            Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'process-price-' . $disposition, 'type' => 'adjust', 'sku_id' => $sku,
                'warehouse_id' => $warehouse, 'business_date' => date('Y-m-d'), 'origin' => $origin, 'amount' => '120.00']));
            $report = \app\api\jxc\logic\CustomerReportLogic::submit(['main_customer_id' => $this->customerId, 'delivery_date' => date('Y-m-d'), 'is_supplement' => 0,
                'idempotency_key' => 'process-report-' . $disposition, 'items' => [['goods_id' => $goods, 'warehouse_id' => $warehouse, 'unit_id' => 0, 'unit_name' => '件',
                    'order_qty' => '5', 'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.00', 'piece_weight_max' => '1.00', 'price_status' => 'unpriced', 'processing_requirement' => '杀好']]]);
            self::assertNotFalse($report, \app\api\jxc\logic\CustomerReportLogic::getError()); $item = (int)$report['items'][0]['id'];
            $reduced = \app\api\jxc\logic\FulfillmentChangeLogic::reduceItem(['report_item_id' => $item, 'new_expected_base_qty' => '3', 'processed_reduction_qty' => '1',
                'processed_disposition' => $disposition, 'other_inventory_action' => 'consume', 'reason' => '记录已加工部分的真实去向', 'idempotency_key' => 'process-reduce-' . $disposition]);
            self::assertNotFalse($reduced, \app\api\jxc\logic\FulfillmentChangeLogic::getError());
            self::assertSame('100.000000', $cost->balance($warehouse, $sku)['value']);
            self::assertSame('20.000000', $cost->destination($warehouse, $sku, $bucket, 'fulfillment_loss:' . $item)['cost']);
            if ($bucket === 'pending') { self::assertSame('0.000000', $cost->destination($warehouse, $sku, 'loss', 'fulfillment_loss:' . $item)['cost']); }
        }
    }

    public function test_receipt_allocates_opening_debt_and_explicit_excess_once_without_new_revenue(): void
    {
        $this->activate();
        $command = $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1500', '1000', '500')];
        $result = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame('confirmed', $result['status']);
        self::assertSame($result, FinanceBusinessLogic::action('record', $command));
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('0.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('6500.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('500.00', $ledger->sources('advance', $this->customerId)[0]['balance']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->count());
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        $command['payload']['amount'] = '1600';
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
        self::assertStringContainsString('同一提交标识', FinanceBusinessLogic::getError());
    }

    public function test_shared_draft_and_pending_have_no_money_effect_and_stale_version_is_rejected(): void
    {
        $this->activate();
        $draft = FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]);
        self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        self::assertSame('draft', $draft['status']);
        $pending = FinanceBusinessLogic::action('submit', $this->command(1) + ['id' => $draft['id']]);
        self::assertNotFalse($pending, FinanceBusinessLogic::getError());
        self::assertSame('pending', $pending['status']);
        self::assertSame('5000.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command(1) + ['id' => $draft['id']]));
        $confirmed = FinanceBusinessLogic::action('confirm', $this->command(2) + ['id' => $draft['id']]);
        self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertSame('5100.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('save', $this->command(3) + ['id' => $draft['id'], 'payload' => []]));
    }

    public function test_invalid_composition_and_electronic_evidence_roll_back_every_effect(): void
    {
        $this->activate('bank');
        $payload = $this->receipt('100', '100');
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertStringContainsString('截图', FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('1000.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(0, Db::name('finance_document')->where('tenant_id', self::TENANT_ID)->count());
        $payload += ['missing_evidence_reason' => '历史渠道无法下载截图', 'alternative_evidence' => '已人工核对银行柜台流水'];
        $confirmed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]);
        self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertTrue($confirmed['confirmed_result']['money']['evidence']['missing_screenshot']);
        $bad = $this->receipt('50', '40');
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $bad]));
        self::assertSame('900.00', $ledger->source($this->receivable)['balance']);
    }

    public function test_remaining_balance_and_duplicate_risk_are_rechecked_at_confirmation(): void
    {
        $this->activate();
        $first = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('600', '600')]);
        self::assertNotFalse($first, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('600', '600')]));
        self::assertStringContainsString('超过当前未结余额', FinanceBusinessLogic::getError());
        $payload = $this->receipt('600', '400', '200');
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertStringContainsString('相近时间', FinanceBusinessLogic::getError());
        $payload['duplicate_risk_reason'] = '客户分两次分别实际交款，已经分别核对';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]), FinanceBusinessLogic::getError());
    }

    public function test_prepare_submits_latest_payload_atomically_without_posting(): void
    {
        $this->activate();
        $draft = FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]);
        $command = $this->command(1) + ['id' => $draft['id'], 'payload' => $this->receipt('200', '200')];
        $pending = FinanceBusinessLogic::action('prepare', $command);
        self::assertNotFalse($pending, FinanceBusinessLogic::getError());
        self::assertSame('200', $pending['payload']['amount']);
        self::assertSame('pending', $pending['status']);
        self::assertSame($pending, FinanceBusinessLogic::action('prepare', $command));
        self::assertSame('5000.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        self::assertNotFalse(FinanceBusinessLogic::action('confirm', $this->command(2) + ['id' => $draft['id']]));
        self::assertSame('5200.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
    }

    public function test_employee_permissions_guard_confirm_salary_and_cached_results(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '财务经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.receipt.prepare', 'finance.payment.prepare', 'finance.salary.prepare']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        $command = $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')];
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
        self::assertNotFalse(FinanceBusinessLogic::action('prepare', $command));
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'salary_payment']));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => []]));
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->delete();
        self::assertFalse(FinanceBusinessLogic::action('prepare', $command), '撤权后不能靠命令缓存读取旧记录');
        self::assertSame([], FinanceBusinessLogic::catalog()['types']);
    }

    public function test_foreign_source_and_wrong_business_evidence_never_post(): void
    {
        $this->activate('bank');
        $ledger = new FinanceLedger(self::OTHER_TENANT_ID);
        $foreign = $ledger->createSource(123, 'receivable', $this->customerId, '100', date('Y-m-d'), null, []);
        $payload = $this->receipt('100', '100'); $payload['allocations'][0]['source'] = $foreign;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        $payload = $this->receipt('100', '100');
        foreach ([[self::OTHER_TENANT_ID, 'receipt'], [self::TENANT_ID, 'salary_payment']] as [$tenant, $type]) {
            $id = Db::name('finance_evidence')->insertGetId(['tenant_id' => $tenant, 'document_type' => $type, 'file_id' => 42,
                'snapshot' => '{}', 'created_by' => '{}', 'create_time' => time()]);
            $payload['evidence_ids'] = [$id];
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
            self::assertStringContainsString('截图不存在', FinanceBusinessLogic::getError());
        }
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
    }

    public function test_external_transaction_duplicate_is_rejected_even_with_another_command(): void
    {
        $this->activate();
        $payload = $this->receipt('100', '100') + ['transaction_no' => 'REAL-20260907-1', 'transaction_scope' => '已核实账户A'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertStringContainsString('该渠道交易已登记', FinanceBusinessLogic::getError());
        self::assertSame('900.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
    }

    public function test_expense_confirmation_splits_service_month_and_payment_only_reduces_payable_and_cash(): void
    {
        $month = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $month . '-01');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '园区服务方']);
        $categories = [];
        foreach ([['premises', '物业服务'], ['utilities', '用水费']] as [$parent, $name]) {
            $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
                'category_id' => 0, 'expected_category_version' => 0, 'parent' => $parent, 'name' => $name, 'is_enabled' => 1]]);
            self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categories[] = $category['confirmed_result']['category'];
        }
        $payload = ['subject_id' => $vendor, 'actual_date' => date('Y-m-d'), 'benefit_month' => $month, 'amount' => '300',
            'due_mode' => 'unspecified', 'source_reference' => '园区费用单001', 'reason' => '上月物业及用水已核清',
            'material_status' => 'missing', 'missing_material_reason' => '对方只提供口头核对，已现场核实', 'material_verified' => 1,
            'lines' => [['category_id' => $categories[0]['id'], 'expected_category_version' => 1, 'amount' => '200', 'reason' => '上月物业清洁服务'],
                ['category_id' => $categories[1]['id'], 'expected_category_version' => 1, 'amount' => '100', 'reason' => '上月用水计量费用']]];
        $prepared = FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => 'expense', 'payload' => $payload]);
        self::assertNotFalse($prepared, FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame([], $ledger->sources('expense_payable', $vendor));
        $command = $this->command($prepared['version']) + ['id' => $prepared['id']];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'confirm']);
        self::assertSame([['metric' => 'expense', 'posting_month' => $month, 'amount' => '300.00']], $preview['impacts']);
        self::assertSame('园区服务方', $preview['expense']['subject_name']);
        self::assertSame([], $ledger->sources('expense_payable', $vendor));
        $confirmed = FinanceBusinessLogic::action('confirm', $command); self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertSame($confirmed, FinanceBusinessLogic::action('confirm', $command));
        $result = $confirmed['confirmed_result']; $source = $result['created_sources'][0];
        self::assertSame('物业服务', $result['lines'][0]['category_name']); self::assertSame('场地', $result['lines'][0]['parent_name']);
        self::assertSame('上月用水计量费用', $result['lines'][1]['reason']);
        self::assertSame('missing', $result['material_status']); self::assertSame('300.00', $ledger->source($source)['balance']);
        self::assertSame(null, $ledger->source($source)['due_date']); self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        foreach (['100', '200'] as $amount) {
            $payment = $this->command(0) + ['type' => 'supplier_payment', 'payload' => ['subject_id' => $vendor, 'account_id' => $this->accountId,
                'actual_date' => date('Y-m-d'), 'amount' => $amount, 'reason' => '分次支付园区费用', 'allocations' => [['source' => $source, 'amount' => $amount]]]];
            $impact = \app\api\jxc\logic\FinancePreview::calculate($payment + ['action' => 'record']);
            self::assertSame(['cash'], array_column($impact['impacts'], 'metric'));
            self::assertNotFalse(FinanceBusinessLogic::action('record', $payment), FinanceBusinessLogic::getError());
        }
        self::assertSame('0.00', $ledger->source($source)['balance']); self::assertSame('4700.00', $ledger->account($this->accountId)['balance']);
        self::assertSame($result, FinanceBusinessLogic::detail(['id' => $confirmed['id']])['confirmed_result']);
    }

    public function test_supplier_combined_payment_reduces_two_sources_without_second_expense(): void
    {
        $this->activate();
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '同一往来方']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        $purchase = $ledger->createSource(1001, 'payable', $vendor, '300', date('Y-m-d'), null, []);
        $expense = $ledger->createSource(1002, 'expense_payable', $vendor, '200', date('Y-m-d'), null, []);
        $payload = ['subject_id' => $vendor, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '400', 'reason' => '合并支付两笔真实欠款',
            'allocations' => [['source' => $purchase, 'amount' => '300'], ['source' => $expense, 'amount' => '100']]];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payload]), FinanceBusinessLogic::getError());
        self::assertSame('4600.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('0.00', $ledger->source($purchase)['balance']); self::assertSame('100.00', $ledger->source($expense)['balance']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'expense')->count());
    }

    public function test_expense_categories_and_evidence_remain_tenant_scoped_and_confirmations_preserve_history(): void
    {
        $this->activate();
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '维修服务方']);
        $categoryCommand = $this->command(0) + ['type' => 'expense_category', 'payload' => ['category_id' => 0, 'expected_category_version' => 0,
            'parent' => 'maintenance', 'name' => '设备修理', 'is_enabled' => 1]];
        $category = FinanceBusinessLogic::action('record', $categoryCommand); self::assertNotFalse($category, FinanceBusinessLogic::getError());
        $categoryId = $category['confirmed_result']['category']['id'];
        $payload = ['subject_id' => $vendor, 'actual_date' => date('Y-m-d'), 'benefit_month' => date('Y-m'), 'amount' => '300',
            'due_mode' => 'date', 'due_date' => date('Y-m-d'), 'source_reference' => '维修单001', 'reason' => '冷柜维修完成',
            'material_status' => 'missing', 'missing_material_reason' => '维修方遗失原收据，已人工核清', 'material_verified' => 1,
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '300', 'reason' => '更换冷柜压缩机配件']]];
        $invalid = [array_replace($payload, ['material_verified' => 0]), array_replace($payload, ['due_mode' => 'unspecified']),
            array_replace($payload, ['benefit_month' => date('Y-m', strtotime('first day of next month'))]),
            array_replace($payload, ['lines' => [array_replace($payload['lines'][0], ['amount' => '299'])]]),
            array_replace($payload, ['lines' => [array_replace($payload['lines'][0], ['expected_category_version' => 2])]]),
            array_replace($payload, ['lines' => [array_replace($payload['lines'][0], ['reason' => ''])]]),
            array_replace($payload, ['lines' => [['category_id' => 999999999, 'amount' => '300']]]),
            array_replace($payload, ['material_status' => 'provided', 'evidence_ids' => [999999999]])];
        foreach ($invalid as $input) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $input]));
            if ($input['lines'][0]['amount'] === '299') { self::assertStringContainsString('合计必须等于来源总额', FinanceBusinessLogic::getError()); }
        }
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame([], $ledger->sources('expense_payable', $vendor));
        $evidence = (int)Db::name('finance_evidence')->insertGetId(['tenant_id' => self::OTHER_TENANT_ID, 'document_type' => 'expense', 'file_id' => 0,
            'snapshot' => json_encode(['name' => '维修收据']), 'created_by' => '{}', 'create_time' => time()]);
        $provided = array_replace($payload, ['material_status' => 'provided', 'evidence_ids' => [$evidence]]);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $provided]));
        Db::name('finance_evidence')->where('id', $evidence)->update(['tenant_id' => self::TENANT_ID, 'document_type' => 'salary_payment']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $provided]));
        Db::name('finance_evidence')->where('id', $evidence)->update(['document_type' => 'expense']);
        $confirmed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $provided]);
        self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertSame('provided', $confirmed['confirmed_result']['material_status']);
        self::assertSame('维修收据', $confirmed['confirmed_result']['evidence'][0]['name']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $payload]), '同一来源换提交号不能重复入账');
        $update = ['category_id' => $categoryId, 'expected_category_version' => 1, 'parent' => 'office', 'name' => '设备修理', 'is_enabled' => 1];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => $update]));
        $update['parent'] = 'maintenance'; $update['is_enabled'] = 0;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => $update]), FinanceBusinessLogic::getError());
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'expense'])['expense_categories']);
        $payload['source_reference'] = '维修单002';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $payload]));
        self::assertSame($confirmed['confirmed_result'], FinanceBusinessLogic::detail(['id' => $confirmed['id']])['confirmed_result']);
        self::assertSame('300.00', $ledger->source($confirmed['confirmed_result']['created_sources'][0])['balance']);
    }

    public function test_expense_confirmation_permission_never_grants_payment_and_revocation_blocks_cached_draft(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '费用经办', 'mobile' => '13800009939', 'bind_user_id' => 996939,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.expense.prepare', 'finance.expense.confirm']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996939; request()->adminId = 0;
        self::assertNotFalse(FinanceBusinessLogic::options(['type' => 'expense']), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => []]));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => []]));
        $command = $this->command(0) + ['type' => 'expense', 'payload' => ['reason' => '待补齐费用草稿']];
        self::assertNotFalse(FinanceBusinessLogic::action('prepare', $command), FinanceBusinessLogic::getError());
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->whereIn('permission_key', ['finance.expense.prepare', 'finance.expense.confirm'])->delete();
        self::assertFalse(FinanceBusinessLogic::action('prepare', $command));
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'expense']));
    }

    /** @dataProvider expenseClosureCases */
    public function test_expense_reduction_after_partial_payment_creates_only_excess_refund_and_preserves_original_cost(bool $closed): void
    {
        $serviceMonth = date('Y-m', strtotime('first day of last month')); $this->activate('cash', $serviceMonth . '-01');
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '费用调整收款方']);
        $category = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => 0, 'expected_category_version' => 0, 'parent' => 'maintenance', 'name' => '修理服务', 'is_enabled' => 1]]);
        self::assertNotFalse($category, FinanceBusinessLogic::getError()); $categoryId = $category['confirmed_result']['category']['id'];
        $payload = ['subject_id' => $vendor, 'actual_date' => $closed ? $serviceMonth . '-05' : date('Y-m-d'), 'benefit_month' => $serviceMonth, 'amount' => '300',
            'due_mode' => 'unspecified', 'source_reference' => '服务账单001', 'reason' => '冷柜维修费用确认',
            'material_status' => 'missing', 'missing_material_reason' => '现场确认维修完成，未开具材料', 'material_verified' => 1,
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '300', 'reason' => '维修冷柜']]];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $payload]);
        self::assertNotFalse($original, FinanceBusinessLogic::getError()); $source = $original['confirmed_result']['created_sources'][0];
        $paid = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => [
            'subject_id' => $vendor, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '250', 'reason' => '先支付250元',
            'allocations' => [['source' => $source, 'amount' => '250']]]]); self::assertNotFalse($paid, FinanceBusinessLogic::getError());
        if ($closed) { Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => $serviceMonth, 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]); }
        $disabled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => $categoryId, 'expected_category_version' => 1, 'parent' => 'maintenance', 'name' => '修理服务', 'is_enabled' => 0]]);
        self::assertNotFalse($disabled, FinanceBusinessLogic::getError());
        $adjustment = $this->command(0) + ['type' => 'expense_adjustment', 'payload' => ['subject_id' => $vendor, 'new_subject_id' => $vendor,
            'original_expense_document_id' => $original['id'], 'expected_revision_id' => 0, 'new_amount' => '180', 'benefit_month' => $serviceMonth,
            'reason' => '现场复核实际服务项目后调减', 'adjustment_verified' => 1, 'confirmation_basis' => '双方核对维修项目及金额',
            'lines' => [['category_id' => $categoryId, 'expected_category_version' => 1, 'amount' => '180', 'reason' => '实际维修项目费用']]]];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($adjustment + ['action' => 'record']);
        self::assertSame([['metric' => 'expense', 'posting_month' => $closed ? date('Y-m') : $serviceMonth, 'amount' => '-120.00']], $preview['impacts']);
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('50.00', $ledger->source($source)['balance']);
        self::assertSame([], $ledger->sources('expense_refund', $vendor));
        $changed = FinanceBusinessLogic::action('record', $adjustment); self::assertNotFalse($changed, FinanceBusinessLogic::getError());
        self::assertSame($changed, FinanceBusinessLogic::action('record', $adjustment));
        self::assertSame('0.00', $ledger->source($source)['balance']);
        $refund = $ledger->sources('expense_refund', $vendor)[0]; self::assertSame('70.00', $refund['balance']);
        if ($closed) {
            $prior = \app\api\jxc\logic\FinanceStatementSnapshot::capture($vendor, $serviceMonth . '-01', date('Y-m-t', strtotime($serviceMonth . '-01')), true);
            self::assertSame('0.00', $prior['balances']['expense_refund']['closing'], '本月才形成的费用应退款不能倒灌已结上月对账');
            self::assertSame('300.00', $prior['balances']['expense_payable']['closing']);
        }
        self::assertSame('4750.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('180.00', $changed['confirmed_result']['expense']['amount']);
        self::assertSame(['-50.00', '70.00'], array_column($changed['confirmed_result']['balance_changes'], 'change'));
        self::assertSame(['0.00', '70.00'], array_column($changed['confirmed_result']['balance_changes'], 'after'));
        self::assertSame($original['confirmed_result'], FinanceBusinessLogic::detail(['id' => $original['id']])['confirmed_result']);
        $receipt = $this->command(0) + ['type' => 'expense_refund', 'payload' => ['subject_id' => $vendor, 'account_id' => $this->accountId,
            'actual_date' => date('Y-m-d'), 'amount' => '70', 'reason' => '收到调减费用退款', 'allocations' => [['source' => $refund['reference'], 'amount' => '70']]]];
        $impact = \app\api\jxc\logic\FinancePreview::calculate($receipt + ['action' => 'record']); self::assertSame(['cash'], array_column($impact['impacts'], 'metric'));
        self::assertNotFalse(FinanceBusinessLogic::action('record', $receipt), FinanceBusinessLogic::getError());
        self::assertSame('4820.00', $ledger->account($this->accountId)['balance']); self::assertSame('0.00', $ledger->source($refund['reference'])['balance']);
        $adjustment['idempotency_key'] = $this->command(0)['idempotency_key'];
        self::assertFalse(FinanceBusinessLogic::action('record', $adjustment)); self::assertStringContainsString('已有后续调整', FinanceBusinessLogic::getError());
        $newVendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '实际服务收款方']);
        $reassign = $adjustment['payload']; $reassign['expected_revision_id'] = $changed['confirmed_result']['revision_id'];
        $reassign['new_subject_id'] = $newVendor; $reassign['new_amount'] = '200'; $reassign['lines'][0]['amount'] = '200';
        $reassign['benefit_month'] = date('Y-m');
        $reassign['reason'] = '原收款对象登记错误，关联真实服务方'; $reassign['new_due_mode'] = 'unspecified';
        $reassigned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_adjustment', 'payload' => $reassign]);
        self::assertNotFalse($reassigned, FinanceBusinessLogic::getError());
        self::assertNotFalse(FinanceBusinessLogic::closingChecklist(['month' => $serviceMonth]), '更正收款对象后，整店月结清单仍须能读取：' . FinanceBusinessLogic::getError());
        $enabled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_category', 'payload' => [
            'category_id' => $categoryId, 'expected_category_version' => 2, 'parent' => 'maintenance', 'name' => '修理服务', 'is_enabled' => 1]]);
        self::assertNotFalse($enabled, FinanceBusinessLogic::getError());
        $duplicate = $payload; $duplicate['subject_id'] = $newVendor; $duplicate['lines'][0]['expected_category_version'] = 3;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $duplicate]), '更正对象后，原费用来源在新对象下也不能重复登记');
        self::assertStringContainsString('来源已登记', FinanceBusinessLogic::getError());
        self::assertSame('200.00', $ledger->categoryBalance('expense_payable', $newVendor));
        self::assertSame('180.00', $ledger->categoryBalance('expense_refund', $vendor));
        self::assertSame('0.00', $ledger->categoryBalance('expense_payable', $vendor));
        $choices = FinanceBusinessLogic::options(['type' => 'expense_adjustment', 'subject_id' => $newVendor]);
        self::assertNotFalse($choices, FinanceBusinessLogic::getError()); self::assertCount(1, $choices['bills']);
        self::assertSame('实际服务收款方', $choices['bills'][0]['expense']['subject_name']);
        $linked = FinanceBusinessLogic::options(['type' => 'expense_adjustment', 'original_expense_document_id' => $original['id']]);
        self::assertNotFalse($linked, FinanceBusinessLogic::getError()); self::assertCount(1, $linked['bills']);
        self::assertSame($newVendor, $linked['bills'][0]['expense']['subject_id']);
        $outstanding = FinanceBusinessLogic::options(['type' => 'expense', 'original_expense_document_id' => $original['id']]);
        self::assertNotFalse($outstanding, FinanceBusinessLogic::getError());
        self::assertSame(['180.00', '200.00'], array_column($outstanding['current_sources'], 'balance'));
        self::assertSame([$vendor, $newVendor], array_column($outstanding['current_sources'], 'subject_id'));
        self::assertSame(['expense_refund', 'expense_payable'], array_column($outstanding['current_sources'], 'category'));
        self::assertSame('4820.00', $ledger->account($this->accountId)['balance'], '对象更正保留真实收付，不凭空转账');
        $next = $reassign; $next['subject_id'] = $newVendor; $next['new_subject_id'] = $vendor; $next['new_amount'] = '100';
        $next['expected_revision_id'] = $reassigned['confirmed_result']['revision_id']; $next['lines'][0]['amount'] = '100';
        $next['reason'] = '核对服务实际归属，继续关联原费用修订';
        $returned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_adjustment', 'payload' => $next]);
        self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->categoryBalance('expense_payable', $newVendor));
        self::assertSame('80.00', $ledger->categoryBalance('expense_refund', $vendor), '增加同一原费用义务先减少本费用仍未收回退款，不影响其他账单');
        self::assertSame('0.00', $ledger->categoryBalance('expense_payable', $vendor));
        $next['subject_id'] = $vendor; $next['expected_revision_id'] = $returned['confirmed_result']['revision_id'];
        $next['new_amount'] = '0'; $next['lines'] = []; $next['reason'] = '确认整笔服务费用取消';
        $cancelled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_adjustment', 'payload' => $next]);
        self::assertNotFalse($cancelled, FinanceBusinessLogic::getError());
        self::assertSame('180.00', $ledger->categoryBalance('expense_refund', $vendor));
        self::assertSame('0.00', $cancelled['confirmed_result']['expense']['amount']);
        $thirdVendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '已有独立同号费用的对象']);
        $separate = $duplicate; $separate['subject_id'] = $thirdVendor;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense', 'payload' => $separate]), FinanceBusinessLogic::getError());
        $conflict = $next; $conflict['new_subject_id'] = $thirdVendor; $conflict['new_amount'] = '100';
        $conflict['expected_revision_id'] = $cancelled['confirmed_result']['revision_id']; $conflict['lines'] = $separate['lines']; $conflict['lines'][0]['amount'] = '100';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'expense_adjustment', 'payload' => $conflict]));
        self::assertStringContainsString('来源已登记', FinanceBusinessLogic::getError());
        self::assertSame('300.00', $ledger->categoryBalance('expense_payable', $thirdVendor));
        self::assertSame('180.00', $ledger->categoryBalance('expense_refund', $vendor));
    }

    public static function expenseClosureCases(): array { return [[false], [true]]; }

    public function test_equipment_and_recovery_have_explicit_profit_effects_and_advance_refund_does_not(): void
    {
        $this->activate();
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '设备往来方']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        foreach ([['equipment_payment', 'equipment', $vendor, '200'], ['equipment_refund', 'equipment_refund', $vendor, '30'],
            ['recovery_receipt', 'recovery', $this->customerId, '40'], ['advance_refund', 'advance', $this->customerId, '50']] as [$type, $category, $subject, $amount]) {
            $source = $ledger->createSource(1000, $category, $subject, $amount, date('Y-m-d'), null, []);
            $payload = ['subject_id' => $subject, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => $amount,
                'reason' => '核实真实业务来源', 'allocations' => [['source' => $source, 'amount' => $amount]]];
            self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => $type, 'payload' => $payload]), FinanceBusinessLogic::getError());
            self::assertSame('0.00', $ledger->source($source)['balance']);
        }
        self::assertSame('4820.00', $ledger->account($this->accountId)['balance']);
        self::assertEquals(170, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'expense')->sum('amount'));
        self::assertEquals(40, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'recovery_income')->sum('amount'));
    }

    public function test_closed_current_month_blocks_all_effects(): void
    {
        $this->activate();
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => date('Y-m'), 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]));
        self::assertStringContainsString('当前自然月已结账', FinanceBusinessLogic::getError());
        self::assertSame('5000.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
    }

    public function test_correction_preserves_transaction_identity_and_replaces_all_effects_atomically(): void
    {
        $this->activate();
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('3000', '1000', '2000') +
            ['transaction_no' => 'CORRECT-SAME-TRANSACTION', 'transaction_scope' => '客户现金交款凭据']]);
        self::assertNotFalse($original);
        $payload = $this->receipt('1000', '1000') + ['transaction_no' => 'CORRECT-SAME-TRANSACTION', 'transaction_scope' => '客户现金交款凭据'];
        $command = $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '实收1000误录3000，按原始交款凭证更正'];
        $corrected = FinanceBusinessLogic::action('correct', $command);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        self::assertSame($corrected, FinanceBusinessLogic::action('correct', $command));
        self::assertNotSame($original['id'], $corrected['id']);
        self::assertSame($original['confirmed_result']['money']['transaction_id'], $corrected['confirmed_result']['money']['transaction_id']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('6000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('0.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('0.00', $ledger->sources('advance', $this->customerId)[0]['balance']);
        self::assertSame('3000', FinanceBusinessLogic::detail(['id' => $original['id']])['payload']['amount']);
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '重复更正']));
    }

    public function test_failed_correction_rolls_back_reversal_and_does_not_consume_original(): void
    {
        $this->activate();
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('300', '300')]);
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $this->receipt('2000', '2000'), 'correction_reason' => '错误组成应整笔失败']));
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('5300.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('700.00', $ledger->source($this->receivable)['balance']);
        self::assertNotFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $this->receipt('200', '200'), 'correction_reason' => '已核对实际到账200']), FinanceBusinessLogic::getError());
    }

    public function test_advance_refund_and_allocation_share_one_balance_without_new_cash(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        $advance = $ledger->createSource(1000, 'advance', $this->customerId, '600', date('Y-m-d'), null, []);
        $payload = ['subject_id' => $this->customerId, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '200',
            'reason' => '预收退款', 'allocations' => [['source' => $advance, 'amount' => '200']]];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'advance_refund', 'payload' => $payload]));
        $allocation = ['subject_id' => $this->customerId, 'advance_source' => $advance, 'reason' => '明确用剩余预收结清指定欠款',
            'allocations' => [['source' => $this->receivable, 'amount' => '400']]];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'advance_allocate', 'payload' => $allocation]), FinanceBusinessLogic::getError());
        self::assertSame('4800.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('600.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('0.00', $ledger->source($advance)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'advance_refund', 'payload' => $payload]));
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_correction_registers_new_channel_identity_and_preserves_old_alias(): void
    {
        $this->activate();
        $payload = $this->receipt('100', '100') + ['transaction_no' => 'SAME-CHANNEL-FACT', 'transaction_scope' => '真实银行账户'];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]);
        $bank = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '真实到账银行', 'account_type' => 'bank']);
        self::assertNotFalse($bank); $payload['account_id'] = $bank['id'];
        $payload += ['missing_evidence_reason' => '历史渠道截图无法取得', 'alternative_evidence' => '已核对银行纸质流水'];
        self::assertNotFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '现金误选，实际为银行']), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertStringContainsString('该渠道交易已登记', FinanceBusinessLogic::getError());
        $payload['account_id'] = $this->accountId;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
    }

    public function test_advance_consumption_and_receivable_allocation_use_same_effective_period(): void
    {
        $this->activate();
        $previous = (new \DateTimeImmutable('first day of last month'))->format('Y-m-d');
        Db::name('finance_preparation')->where('tenant_id', self::TENANT_ID)->update(['activation_date' => $previous]);
        $ledger = new FinanceLedger(self::TENANT_ID);
        $advance = $ledger->createSource(1000, 'advance', $this->customerId, '100', $previous, null, []);
        $receivable = $ledger->createSource(1001, 'receivable', $this->customerId, '100', date('Y-m-01'), null, []);
        $result = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'advance_allocate', 'payload' => ['subject_id' => $this->customerId,
            'advance_source' => $advance, 'reason' => '前月预收抵本月销售', 'allocations' => [['source' => $receivable, 'amount' => '100']]]]);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        $entries = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $result['id'])->select()->toArray();
        self::assertCount(2, $entries);
        foreach ($entries as $entry) { self::assertSame(date('Y-m'), $entry['posting_month']); self::assertSame(date('Y-m-01'), $entry['effective_date']); }
    }

    public function test_duplicate_reversal_keeps_one_transaction_effect_and_does_not_create_refund(): void
    {
        $this->activate();
        $payload = $this->receipt('100', '100');
        $kept = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]);
        $payload['duplicate_risk_reason'] = '经办误判断为第二笔';
        $duplicate = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]);
        self::assertNotFalse($duplicate);
        $command = $this->command(1) + ['id' => $duplicate['id'], 'duplicate_of' => $kept['id'], 'correction_reason' => '核对原凭据确认同一笔误录两次'];
        $reversed = FinanceBusinessLogic::action('reverse_duplicate', $command);
        self::assertNotFalse($reversed, FinanceBusinessLogic::getError());
        self::assertSame($reversed, FinanceBusinessLogic::action('reverse_duplicate', $command));
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('5100.00', $ledger->account($this->accountId)['balance']); self::assertSame('900.00', $ledger->source($this->receivable)['balance']);
        self::assertSame(2, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count(), '两份原始登记保留但不产生真实退款');
    }

    public function test_salary_evidence_is_private_and_each_read_rechecks_identity_store_and_permission(): void
    {
        $this->activate();
        $path = tempnam(sys_get_temp_dir(), 'finance-proof-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQ0AAAAASUVORK5CYII='));
        $upload = new \think\file\UploadedFile($path, '工资核验.png', 'image/png', null, true);
        $proof = \app\api\jxc\logic\FinanceEvidence::save('salary_payment', $upload);
        self::assertArrayNotHasKey('uri', $proof['file']); self::assertArrayNotHasKey('url', $proof['file']);
        $directory = (new \ReflectionMethod(\app\api\jxc\logic\FinanceEvidence::class, 'directory'))->invoke(null, self::TENANT_ID);
        $resolved = str_replace('\\', '/', (string)realpath($directory));
        $root = str_replace('\\', '/', (string)realpath(root_path()));
        self::assertStringStartsWith($root . '/', $resolved);
        foreach (['runtime', 'public'] as $excluded) {
            self::assertFalse(str_starts_with($resolved . '/', $root . '/' . $excluded . '/'), '长期私密凭证不能落入公开目录或框架默认清理的runtime');
        }
        $content = \app\api\jxc\logic\FinanceEvidence::content($proof['id']);
        self::assertSame('image/png', $content['mime']); self::assertNotEmpty($content['base64']);
        $employee = WorkforceLogic::saveEmployee(['name' => '工资经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.salary.prepare', 'finance.salary.view']]);
        self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        self::assertSame($content['base64'], \app\api\jxc\logic\FinanceEvidence::content($proof['id'])['base64']);
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.salary.view')->delete();
        foreach (['revoked', 'anonymous', 'foreign'] as $case) {
            if ($case === 'anonymous') { request()->userId = 0; }
            if ($case === 'foreign') { $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID); }
            try { \app\api\jxc\logic\FinanceEvidence::content($proof['id']); self::fail('无权用户不能读取私密图片: ' . $case); }
            catch (\DomainException $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }

    public function test_source_picker_pages_only_current_open_balances_with_stable_identity(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        for ($i = 0; $i < 25; $i++) { $ledger->createSource(1000, 'receivable', $this->customerId, '10', date('Y-m-d'), null, []); }
        $first = FinanceBusinessLogic::options(['type' => 'receipt', 'subject_id' => $this->customerId]);
        self::assertNotFalse($first, FinanceBusinessLogic::getError()); self::assertCount(20, $first['sources']); self::assertTrue($first['has_more']);
        $second = FinanceBusinessLogic::options(['type' => 'receipt', 'subject_id' => $this->customerId, 'page' => 2]);
        self::assertNotFalse($second); self::assertCount(6, $second['sources']); self::assertFalse($second['has_more']);
        self::assertCount(26, array_unique(array_merge(array_column($first['sources'], 'reference'), array_column($second['sources'], 'reference'))));
        $ledger->add(1002, 'balance', $this->customerId, '-1000', date('Y-m-d'), date('Y-m'), 'allocation', $this->receivable);
        $last = FinanceBusinessLogic::options(['type' => 'receipt', 'subject_id' => $this->customerId, 'page' => 2]); self::assertCount(5, $last['sources']);
    }

    public function test_confirmed_sale_creates_receivable_and_revenue_once_and_receipt_uses_that_source(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-sales-first-confirm',
            'due_date' => date('Y-m-d'), 'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $sale = SalesSettlementLogic::submit($command);
        self::assertNotFalse($sale, SalesSettlementLogic::getError());
        self::assertSame($sale, SalesSettlementLogic::submit($command));
        $ledger = new FinanceLedger(self::TENANT_ID);
        $sources = array_values(array_filter($ledger->sources('receivable', $this->customerId), static fn(array $row): bool => str_starts_with($row['reference'], 'n:')));
        self::assertCount(1, $sources); self::assertSame('20.00', $sources[0]['balance']); self::assertSame(date('Y-m-d'), $sources[0]['due_date']);
        self::assertSame('1020.00', $sale['debt_after_order']);
        self::assertEquals(20, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        $payload = $this->receipt('20', '20'); $payload['allocations'][0]['source'] = $sources[0]['reference'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertSame('0.00', $ledger->source($sources[0]['reference'])['balance']);
        self::assertSame('5020.00', $ledger->account($this->accountId)['balance']);
        self::assertEquals(20, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count(), '金额确认不再次出库');
    }

    public function test_active_finance_blocks_legacy_manual_receipt_without_changing_balance(): void
    {
        $this->activate();
        Db::name('customer')->where('tenant_id', self::TENANT_ID)->where('id', $this->customerId)->update(['order_receivable' => '1000']);
        self::assertFalse(\app\api\jxc\logic\CustomerLogic::paymoney(['customer_id' => $this->customerId, 'money' => '100']));
        self::assertStringContainsString('财务收付款', \app\api\jxc\logic\CustomerLogic::getError());
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
        self::assertSame('1000.00', Db::name('customer')->where('id', $this->customerId)->value('order_receivable'));
    }

    public function test_sales_reduction_after_partial_collection_keeps_credit_refund_and_original_history(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = ['due_date_reviewed' => 1, 'due_override_reason' => '经核实本笔未约定付款日', 'order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-sales-credit-first',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $first = SalesSettlementLogic::submit($command); self::assertNotFalse($first, SalesSettlementLogic::getError());
        $source = $first['finance']['source_ref'];
        $payload = $this->receipt('12', '12'); $payload['allocations'][0]['source'] = $source;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        $command['expected_version'] = 1; $command['idempotency_key'] = 'finance-sales-credit-second';
        $command['lines'][0]['price'] = '5'; $command['edit_reason'] = '按真实成交价格更正，贷项先抵本单剩余';
        $command['credit_reviewed'] = 1; $command['credit_allocations'] = [['source' => $source, 'amount' => '8']];
        $second = SalesSettlementLogic::submit($command); self::assertNotFalse($second, SalesSettlementLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('0.00', $ledger->source($source)['balance']);
        self::assertSame('2.00', $ledger->source($second['finance']['customer_refund_ref'])['balance']);
        self::assertSame('5012.00', $ledger->account($this->accountId)['balance']);
        self::assertEquals(10, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        self::assertSame('20.00', Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $fixture['order_id'])->where('version', 1)->value('order_money'));
        self::assertSame($second, SalesSettlementLogic::submit($command));
    }

    public function test_overdue_acknowledgement_is_required_and_partial_receipt_keeps_original_due_date(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        $due = date('Y-m-d', strtotime('-1 day'));
        $overdueSource = $ledger->createSource(900, 'receivable', $this->customerId, '100', date('Y-m-01'), $due, []);
        $fixture = $this->deliveredSale();
        $command = ['due_date_reviewed' => 1, 'due_override_reason' => '经核实本笔未约定付款日', 'order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-overdue-new-sale',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        self::assertFalse(SalesSettlementLogic::submit($command));
        self::assertStringContainsString('逾期', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('finance_sales_version')->where('tenant_id', self::TENANT_ID)->count());
        $detail = SalesSettlementLogic::detail(['id' => $fixture['order_id']]);
        self::assertSame('100.00', $detail['finance']['overdue']['amount']);
        self::assertSame(1, $detail['finance']['overdue']['days']);
        $command['overdue_acknowledged'] = 1;
        self::assertNotFalse(SalesSettlementLogic::submit($command), SalesSettlementLogic::getError());
        $payload = $this->receipt('40', '40'); $payload['allocations'][0]['source'] = $overdueSource;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertSame('60.00', \app\api\jxc\logic\FinanceCustomers::overdue($this->customerId)['amount']);
        self::assertSame($due, $ledger->source($overdueSource)['due_date']);
    }

    public function test_historical_sale_adjustment_requires_explicit_opening_link_and_only_posts_delta(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        Db::name('sales_order')->where('id', $fixture['order_id'])->update(['settlement_version' => 1, 'settlement_status' => 'formal',
            'order_money' => '20', 'goods_amount' => '20', 'datetimesingle' => strtotime(date('Y-m-01') . ' -1 day')]);
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 1, 'idempotency_key' => 'finance-historical-sale-link', 'edit_reason' => '原销售已包含期初汇总，核对成交价后调增',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '15']]];
        self::assertFalse(SalesSettlementLogic::submit($command));
        self::assertStringContainsString('期初', SalesSettlementLogic::getError());
        $command += ['opening_link_reviewed' => 1, 'opening_source' => $this->receivable];
        $result = SalesSettlementLogic::submit($command); self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame('10.00', $result['finance']['amount_change']);
        self::assertSame('1010.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
        self::assertSame(0, Db::name('finance_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->count());
        self::assertEquals(10, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        self::assertSame($result, SalesSettlementLogic::submit($command));
    }

    public function test_sales_credit_over_allocation_rolls_back_order_version_sources_and_entries(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = ['due_date_reviewed' => 1, 'due_override_reason' => '经核实本笔未约定付款日', 'order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-credit-over-first',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $first = SalesSettlementLogic::submit($command); self::assertNotFalse($first, SalesSettlementLogic::getError());
        $command['expected_version'] = 1; $command['idempotency_key'] = 'finance-credit-over-second'; $command['edit_reason'] = '核实成交价更正';
        $command['lines'][0]['price'] = '5'; $command['credit_reviewed'] = 1;
        $command['credit_allocations'] = [['source' => $first['finance']['source_ref'], 'amount' => '11']];
        self::assertFalse(SalesSettlementLogic::submit($command));
        self::assertStringContainsString('超过', SalesSettlementLogic::getError());
        self::assertSame('20.00', (new FinanceLedger(self::TENANT_ID))->source($first['finance']['source_ref'])['balance']);
        self::assertSame(0, Db::name('finance_source')->where('tenant_id', self::TENANT_ID)->where('category', 'customer_refund')->count());
        self::assertSame(1, (int)Db::name('sales_order')->where('id', $fixture['order_id'])->value('settlement_version'));
    }

    public function test_customer_due_rules_are_versioned_and_single_sale_override_keeps_default_and_reason(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = $this->command(0) + ['customer_id' => $this->customerId, 'mode' => 'days_after', 'days' => '7', 'reason' => '客户约定交付后一周付款'];
        $rule = \app\api\jxc\logic\FinanceSalesRules::save($command);
        request()->adminInfo = array_merge((array)request()->adminInfo, ['name' => '更改后的显示名称']);
        self::assertSame($rule, \app\api\jxc\logic\FinanceSalesRules::save($command));
        $saleCommand = ['order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-rule-default-sale',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $sale = SalesSettlementLogic::submit($saleCommand); self::assertNotFalse($sale, SalesSettlementLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID); $source = $ledger->source($sale['finance']['source_ref']);
        self::assertSame(date('Y-m-d', strtotime('+7 days')), $source['due_date']);
        \app\api\jxc\logic\FinanceSalesRules::save($this->command(1) + ['customer_id' => $this->customerId, 'mode' => 'delivery', 'reason' => '以后现结']);
        self::assertSame($source['due_date'], $ledger->source($source['reference'])['due_date']);
        self::assertSame(2, Db::name('finance_customer_terms')->where('tenant_id', self::TENANT_ID)->count());
        $fixture2 = $this->deliveredSale(); $saleCommand['order_id'] = $fixture2['order_id']; $saleCommand['lines'][0]['order_goods_id'] = $fixture2['line_id'];
        $saleCommand['lines'][0]['pricing_unit_id'] = $fixture2['unit_id']; $saleCommand['idempotency_key'] = 'finance-rule-override-sale';
        $saleCommand['due_date_reviewed'] = 1; $saleCommand['due_date'] = null;
        self::assertFalse(SalesSettlementLogic::submit($saleCommand), '覆盖为未约定仍需依据');
        $saleCommand['due_override_reason'] = '本次待客户内部核对后再约定';
        $sale = SalesSettlementLogic::submit($saleCommand); self::assertNotFalse($sale, SalesSettlementLogic::getError());
        $source = $ledger->source($sale['finance']['source_ref']);
        self::assertNull($source['due_date']); self::assertSame(date('Y-m-d'), $source['snapshot']['due_terms']['default_due_date']);
        self::assertSame($saleCommand['due_override_reason'], $source['snapshot']['due_override_reason']);
    }

    public function test_weight_approval_requires_its_own_current_overdue_acknowledgement(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-weight-pending',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '1.5', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $pending = SalesSettlementLogic::submit($command); self::assertNotFalse($pending, SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('finance_sales_version')->where('tenant_id', self::TENANT_ID)->count());
        (new FinanceLedger(self::TENANT_ID))->createSource(900, 'receivable', $this->customerId, '100', date('Y-m-01'), date('Y-m-d', strtotime('-1 day')), []);
        $todo = Db::name('sales_weight_difference_todo')->where('tenant_id', self::TENANT_ID)->where('order_id', $fixture['order_id'])->value('id');
        $approve = ['id' => $todo, 'decision' => 'approve', 'reason' => '与客户核对实际按1.5斤计价', 'idempotency_key' => 'finance-weight-approve'];
        self::assertFalse(SalesSettlementLogic::resolveWeightDifference($approve)); self::assertStringContainsString('逾期', SalesSettlementLogic::getError());
        $approve['overdue_acknowledged'] = 1;
        $result = SalesSettlementLogic::resolveWeightDifference($approve); self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame($result, SalesSettlementLogic::resolveWeightDifference($approve));
        $snapshot = json_decode(Db::name('sales_settlement_action')->where('tenant_id', self::TENANT_ID)->where('idempotency_key', $approve['idempotency_key'])->value('snapshot_json'), true);
        self::assertTrue($snapshot['overdue_acknowledged']);
    }

    public function test_historical_sales_credit_posts_both_sides_in_confirmation_month_even_when_activation_month_open(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $activation = date('Y-m-01', strtotime('first day of previous month'));
        Db::name('finance_preparation')->where('tenant_id', self::TENANT_ID)->update(['activation_date' => $activation]);
        Db::name('sales_order')->where('id', $fixture['order_id'])->update(['settlement_version' => 1, 'settlement_status' => 'formal',
            'order_money' => '20', 'goods_amount' => '20', 'datetimesingle' => strtotime($activation . ' -1 day')]);
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 1, 'idempotency_key' => 'finance-historical-credit-month', 'edit_reason' => '按凭据调减已承接旧销售',
            'opening_link_reviewed' => 1, 'opening_source' => $this->receivable, 'credit_reviewed' => 1, 'credit_allocations' => [['source' => $this->receivable, 'amount' => '10']],
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '5']]];
        $result = SalesSettlementLogic::submit($command); self::assertNotFalse($result, SalesSettlementLogic::getError());
        $months = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $result['finance']['document_id'])->column('posting_month');
        self::assertCount(3, $months); self::assertSame([date('Y-m')], array_values(array_unique($months)));
    }

    public function test_sales_billing_permission_does_not_grant_due_date_override_or_customer_rule_changes(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $employee = WorkforceLogic::saveEmployee(['name' => '仅销售结算', 'mobile' => '13800009929', 'bind_user_id' => 996929,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['settlement.view', 'settlement.bill']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996929; request()->adminId = 0;
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-employee-due-override',
            'due_date' => date('Y-m-d', strtotime('+1 day')), 'due_override_reason' => '希望延后一天',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        self::assertFalse(SalesSettlementLogic::submit($command)); self::assertStringContainsString('权限', SalesSettlementLogic::getError());
        unset($command['due_date'], $command['due_override_reason']);
        self::assertNotFalse(SalesSettlementLogic::submit($command), SalesSettlementLogic::getError());
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('最高权限');
        \app\api\jxc\logic\FinanceSalesRules::save($this->command(0) + ['customer_id' => $this->customerId, 'mode' => 'unagreed', 'reason' => '试图修改规则']);
    }

    public function test_bad_debt_moves_only_verified_receivable_to_recovery_then_termination_does_not_repeat_loss(): void
    {
        $this->activate();
        $payload = ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => '300',
            'allocations' => [['source' => $this->receivable, 'amount' => '300']], 'debt_verified' => 1, 'undisputed' => 1,
            'reason' => '核实债务真实无争议，债务人已无清偿能力', 'basis' => '已核实原始债权和清偿情况'];
        $badDebt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'bad_debt', 'payload' => $payload]);
        self::assertNotFalse($badDebt, FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('700.00', $ledger->source($this->receivable)['balance']);
        $recovery = $badDebt['confirmed_result']['created_sources'][0]; self::assertSame('300.00', $ledger->source($recovery)['balance']);
        self::assertEquals(300, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'loss')->sum('amount'));
        $payload = ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => '100',
            'allocations' => [['source' => $recovery, 'amount' => '100']], 'reason' => '有明确依据终止部分追偿', 'basis' => '终止追偿依据已复核'];
        $termination = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'recovery_termination', 'payload' => $payload]);
        self::assertNotFalse($termination, FinanceBusinessLogic::getError());
        self::assertSame('200.00', $ledger->source($recovery)['balance']);
        $reverseBad = $this->command($badDebt['version']) + ['id' => $badDebt['id'], 'correction_reason' => '复核原坏账事实不成立'];
        self::assertFalse(FinanceBusinessLogic::action('reverse', $reverseBad));
        self::assertStringContainsString('后续处理', FinanceBusinessLogic::getError());
        $reverseTermination = $this->command($termination['version']) + ['id' => $termination['id'], 'correction_reason' => '复核仍应继续追偿'];
        $reversed = FinanceBusinessLogic::action('reverse', $reverseTermination);
        self::assertNotFalse($reversed, FinanceBusinessLogic::getError());
        self::assertSame($reversed, FinanceBusinessLogic::action('reverse', $reverseTermination));
        self::assertSame('300.00', $ledger->source($recovery)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('reverse', $this->command($reversed['version']) + ['id' => $reversed['id'], 'correction_reason' => '不得冲销反向凭据']));
        self::assertStringContainsString('反向凭据', FinanceBusinessLogic::getError());
        self::assertNotFalse(FinanceBusinessLogic::action('reverse', $reverseBad), FinanceBusinessLogic::getError());
        self::assertSame('1000.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('0.00', $ledger->source($recovery)['balance']);
        self::assertEquals(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'loss')->sum('amount'));
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]);
        self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('reverse', $this->command($receipt['version']) + ['id' => $receipt['id'], 'correction_reason' => '不能把真实资金当无资金撤销']));
        self::assertStringContainsString('实际收付款不能', FinanceBusinessLogic::getError());
        self::assertSame('900.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('5100.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_bad_debt_requires_verified_undisputed_debt_before_any_effect(): void
    {
        $this->activate();
        $payload = ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => '300', 'allocations' => [['source' => $this->receivable, 'amount' => '300']], 'reason' => '仅逾期尚未核实', 'basis' => '没有充分依据'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'bad_debt', 'payload' => $payload]));
        self::assertStringContainsString('无争议', FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('1000.00', $ledger->source($this->receivable)['balance']);
        self::assertEquals(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'loss')->sum('amount'));
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_impact_preview_rolls_back_documents_commands_sources_and_entries_even_for_reverse(): void
    {
        $this->activate();
        $payload = ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => '300', 'allocations' => [['source' => $this->receivable, 'amount' => '300']], 'debt_verified' => 1, 'undisputed' => 1, 'reason' => '债务真实无争议且无清偿能力', 'basis' => '核实债权及清偿事实'];
        $command = $this->command(0) + ['action' => 'record', 'type' => 'bad_debt', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command);
        self::assertSame(['700.00', '300.00'], array_column(array_reverse($preview['balances']), 'after'));
        self::assertSame('300.00', $preview['impacts'][0]['amount']);
        self::assertSame([date('Y-m')], $preview['posting_months']);
        foreach (['finance_document', 'finance_command', 'finance_source', 'finance_entry'] as $table) { self::assertSame(0, Db::name($table)->where('tenant_id', self::TENANT_ID)->count()); }
        $bad = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($bad, FinanceBusinessLogic::getError());
        $reverse = $this->command($bad['version']) + ['action' => 'reverse', 'id' => $bad['id'], 'correction_reason' => '原坏账事实复核不成立'];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($reverse);
        self::assertSame('-300.00', $preview['impacts'][0]['amount']);
        self::assertSame(0, Db::name('finance_correction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('700.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
        self::assertNotFalse(FinanceBusinessLogic::action('reverse', $reverse), FinanceBusinessLogic::getError());
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
    }

    public function test_recovery_preparer_can_read_customer_sources_but_cannot_confirm_and_revocation_blocks_reads(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '追偿经办', 'mobile' => '13800009928', 'bind_user_id' => 996928, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.recovery.prepare']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        self::assertCount(1, \app\api\jxc\logic\FinanceCustomerBalances::lists([])['lists']);
        $detail = \app\api\jxc\logic\FinanceCustomerBalances::detail(['customer_id' => $this->customerId, 'category' => 'recovery']);
        self::assertSame('0.00', $detail['balances']['recovery']);
        self::assertSame(['recovery_receipt', 'recovery_termination'], array_column($detail['actions'], 'type'));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'recovery_termination', 'payload' => []]));
        self::assertStringContainsString('最高权限', FinanceBusinessLogic::getError());
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->delete();
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('权限');
        \app\api\jxc\logic\FinanceCustomerBalances::lists([]);
    }

    public function test_due_draft_cannot_read_salary_source_through_current_date_projection(): void
    {
        $this->activate();
        $source = (new FinanceLedger(self::TENANT_ID))->createSource(0, 'salary', 98765, '5678', date('Y-m-d'), null, ['subject_name' => '敏感工资对象', 'salary_month' => date('Y-m')]);
        $employee = WorkforceLogic::saveEmployee(['name' => '应收经办', 'mobile' => '13800009928', 'bind_user_id' => 996928, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.receivable.prepare']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        $draft = FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'receivable_due', 'payload' => ['subject_id' => 98765, 'source' => $source]]);
        self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::detail(['id' => $draft['id']]));
        self::assertStringContainsString('来源业务类型或往来主体', FinanceBusinessLogic::getError());
    }

    public function test_partial_receipt_return_reopens_only_selected_debt_and_preserves_receipt_and_source_dates(): void
    {
        $this->activate();
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1000', '1000')]);
        self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $payload = ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId,
            'actual_date' => date('Y-m-d'), 'amount' => '200', 'allocations' => [['source' => $this->receivable, 'amount' => '200']], 'reason' => '银行已实际退回原到账的一部分'];
        $command = $this->command(0) + ['type' => 'receipt_return', 'payload' => $payload];
        $returned = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        self::assertSame($returned, FinanceBusinessLogic::action('record', $command));
        self::assertCount(1, FinanceBusinessLogic::lists(['type' => 'receipt_return', 'subject_id' => $this->customerId])['lists']);
        self::assertSame([], FinanceBusinessLogic::lists(['type' => 'receipt_return', 'subject_id' => $this->customerId + 100000])['lists']);
        $ledger = new FinanceLedger(self::TENANT_ID); $source = $ledger->source($this->receivable);
        self::assertSame('200.00', $source['balance']); self::assertNull($source['business_date']); self::assertNull($source['due_date']);
        self::assertSame('5800.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(2, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('finance_correction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertEquals(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        $payload['amount'] = '801'; $payload['allocations'][0]['amount'] = '801';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => $payload]));
        self::assertStringContainsString('可退回', FinanceBusinessLogic::getError());
        self::assertSame('200.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('5800.00', $ledger->account($this->accountId)['balance']);
    }

    public function test_receipt_return_and_its_correction_share_original_capacity_without_second_cash_transaction(): void
    {
        $this->activate();
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1200', '1000', '200')]);
        self::assertNotFalse($receipt, FinanceBusinessLogic::getError()); $advance = $receipt['confirmed_result']['created_sources'][0];
        $payload = ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'),
            'amount' => '300', 'allocations' => [['source' => $this->receivable, 'amount' => '200'], ['source' => $advance, 'amount' => '100']], 'reason' => '原到账部分失效'];
        $returned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => $payload]); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('100.00', $ledger->source($advance)['balance']); self::assertSame('200.00', $ledger->source($this->receivable)['balance']);
        $payload['amount'] = '350'; $payload['allocations'][0]['amount'] = '250';
        $corrected = FinanceBusinessLogic::action('correct', $this->command($returned['version']) + ['id' => $returned['id'], 'payload' => $payload, 'correction_reason' => '核实实际失效350，原登记少计50']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        self::assertSame('250.00', $ledger->source($this->receivable)['balance']); self::assertSame('100.00', $ledger->source($advance)['balance']);
        self::assertSame('5850.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(2, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        $options = (new \app\api\jxc\logic\FinanceReceiptReturns(self::TENANT_ID, $ledger))->options($receipt['id']);
        self::assertSame(['750.00', '100.00'], array_column($options['sources'], 'returnable'));
        $invalid = $this->receipt('200', '200');
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $invalid, 'correction_reason' => '不得缩减已真实退回的原核销']));
        self::assertStringContainsString('已使用或退回', FinanceBusinessLogic::getError());
    }

    public function test_receipt_correction_preserves_real_returns_and_options_follow_latest_receipt_version(): void
    {
        $this->activate();
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1000', '1000')]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $payload = ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '200', 'allocations' => [['source' => $this->receivable, 'amount' => '200']], 'reason' => '银行已退回200'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => $payload]), FinanceBusinessLogic::getError());
        $corrected = FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $this->receipt('900', '900'), 'correction_reason' => '原到账实际900，已核实退回200不变']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('300.00', $ledger->source($this->receivable)['balance']); self::assertSame('5700.00', $ledger->account($this->accountId)['balance']);
        $options = FinanceBusinessLogic::options(['type' => 'receipt_return', 'subject_id' => $this->customerId, 'receipt_id' => $receipt['id']]); self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertSame($corrected['id'], $options['receipt']['receipt_id']); self::assertSame('700.00', $options['sources'][0]['returnable']);
        $choices = FinanceBusinessLogic::options(['type' => 'receipt_return', 'subject_id' => $this->customerId]);
        self::assertSame([$corrected['id']], array_column($choices['receipt_choices'], 'id'));
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'receipt_return', 'subject_id' => $this->customerId + 1, 'receipt_id' => $receipt['id']]));
        self::assertStringContainsString('不属于', FinanceBusinessLogic::getError());
    }

    public function test_consumed_advance_keeps_its_reference_when_original_receipt_account_amount_or_date_is_corrected(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        $otherAccount = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '实际收款现金账户', 'account_type' => 'cash']); self::assertNotFalse($otherAccount);
        $payload = $this->receipt('100', '0', '100'); $payload['allocations'] = []; $payload['actual_date'] = date('Y-m-01');
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $advance = $receipt['confirmed_result']['created_sources'][0];
        $returnPayload = ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '20', 'allocations' => [['source' => $advance, 'amount' => '20']], 'reason' => '原到账确实退回20'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => $returnPayload]), FinanceBusinessLogic::getError());
        $payload['account_id'] = $otherAccount['id']; $payload['amount'] = '120'; $payload['advance_amount'] = '120'; $payload['actual_date'] = date('Y-m-d');
        $corrected = FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $payload, 'correction_reason' => '核实到账账户、日期及金额，真实退回20保留']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        self::assertSame([$advance], $corrected['confirmed_result']['created_sources']);
        self::assertSame('120.00', $ledger->source($advance)['confirmed_amount']); self::assertSame('100.00', $ledger->source($advance)['balance']);
        self::assertSame(date('Y-m-d'), $ledger->source($advance)['business_date']); self::assertSame(date('Y-m-01'), $ledger->source($advance)['original_business_date']);
        self::assertSame('4980.00', $ledger->account($this->accountId)['balance']); self::assertSame('120.00', $ledger->account((int)$otherAccount['id'])['balance']);
        $payload['amount'] = '110'; $payload['advance_amount'] = '110';
        $again = FinanceBusinessLogic::action('correct', $this->command($corrected['version']) + ['id' => $corrected['id'], 'payload' => $payload, 'correction_reason' => '再复核原金额110']); self::assertNotFalse($again, FinanceBusinessLogic::getError());
        self::assertSame('90.00', $ledger->source($advance)['balance']); self::assertSame('110.00', $ledger->source($advance)['confirmed_amount']);
        self::assertSame(2, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('100.00', Db::name('finance_source')->where('id', substr($advance, 2))->value('amount'));
        self::assertSame('90.00', FinanceBusinessLogic::options(['type' => 'receipt_return', 'subject_id' => $this->customerId, 'receipt_id' => $receipt['id']])['sources'][0]['returnable']);
        $history = \app\api\jxc\logic\FinanceCustomerBalances::source(['source' => $advance]);
        self::assertSame(['110.00', '120.00'], array_column($history['advance_history'], 'new_amount'));
        self::assertSame('110.00', $ledger->sourcePage(['advance'], $this->customerId, 1)['sources'][0]['confirmed_amount']);
    }

    public function test_receipt_correction_cannot_move_actual_receipt_after_a_real_return(): void
    {
        $this->activate(); $payload = $this->receipt('1000', '1000'); $payload['actual_date'] = date('Y-m-01');
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId,
            'actual_date' => date('Y-m-01'), 'amount' => '200', 'allocations' => [['source' => $this->receivable, 'amount' => '200']], 'reason' => '到账当日部分被退回']]), FinanceBusinessLogic::getError());
        $payload['actual_date'] = date('Y-m-02');
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $payload, 'correction_reason' => '不能破坏已有实际资金先后']));
        self::assertStringContainsString('不能晚于已存在的真实退回日期', FinanceBusinessLogic::getError());
        self::assertSame('5800.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
    }

    public function test_due_adjustment_updates_current_overdue_without_rewriting_original_source_or_amount(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID); $date = date('Y-m-01'); $oldDue = date('Y-m-d', strtotime('-1 day'));
        $source = $ledger->createSource(999, 'receivable', $this->customerId, '100', $date, $oldDue, []);
        self::assertSame('100.00', \app\api\jxc\logic\FinanceCustomers::overdue($this->customerId)['amount']);
        $command = $this->command(0) + ['type' => 'receivable_due', 'payload' => ['subject_id' => $this->customerId, 'source' => $source,
            'expected_due_revision' => 0, 'new_due_date' => date('Y-m-d', strtotime('+7 days')), 'reason' => '经双方约定延期一周']];
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame($result, FinanceBusinessLogic::action('record', $command));
        $current = $ledger->source($source); self::assertSame($command['payload']['new_due_date'], $current['due_date']);
        self::assertSame($oldDue, $current['original_due_date']); self::assertSame($date, $current['business_date']); self::assertSame('100.00', $current['balance']);
        self::assertSame('0.00', \app\api\jxc\logic\FinanceCustomers::overdue($this->customerId)['amount']);
        $newCommand = $this->command(0) + ['type' => 'receivable_due', 'payload' => array_merge($command['payload'], ['new_due_date' => null, 'reason' => '改为未约定'])];
        self::assertFalse(FinanceBusinessLogic::action('record', $newCommand)); self::assertStringContainsString('新调整', FinanceBusinessLogic::getError());
        $newCommand['payload']['expected_due_revision'] = $current['due_revision'];
        $second = FinanceBusinessLogic::action('record', $newCommand); self::assertNotFalse($second, FinanceBusinessLogic::getError());
        self::assertNull($ledger->source($source)['due_date']);
        self::assertSame(2, Db::name('finance_due_adjustment')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->count());
        $page = $ledger->sourcePage(['receivable'], $this->customerId, 1);
        $row = array_values(array_filter($page['sources'], static fn(array $row): bool => $row['reference'] === $source))[0];
        self::assertNull($row['due_date']); self::assertSame($oldDue, $row['original_due_date']);
    }

    public function test_customer_balances_read_ledger_instead_of_legacy_shadow_and_preserve_unknown_opening_age(): void
    {
        $this->activate();
        Db::name('customer')->where('id', $this->customerId)->update(['order_receivable' => '999999']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]));
        $result = \app\api\jxc\logic\FinanceCustomerBalances::lists(['filter' => 'debt']);
        self::assertCount(1, $result['lists']); self::assertSame('900.00', $result['lists'][0]['receivable']);
        $detail = \app\api\jxc\logic\FinanceCustomerBalances::detail(['customer_id' => $this->customerId]);
        self::assertSame('900.00', $detail['balances']['receivable']); self::assertNull($detail['sources'][0]['age_days']);
        $source = \app\api\jxc\logic\FinanceCustomerBalances::source(['source' => $this->receivable]);
        self::assertCount(1, $source['entries']); self::assertSame('-100.00', $source['entries'][0]['amount']);
        self::assertSame([], \app\api\jxc\logic\FinanceCustomerBalances::lists(['filter' => 'overdue'])['lists']);
    }

    public function test_supplier_statement_freezes_formal_payables_refunds_and_actual_payments_separately(): void
    {
        $this->activate();
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '对账供应商']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        $source = $ledger->createSource(0, 'payable', $vendor, '100.00', date('Y-m-d'), null, ['reason' => '已确认采购']);
        $ledger->createSource(0, 'supplier_refund', $vendor, '20.00', date('Y-m-d'), null, ['reason' => '已确认贷项']);
        $request = $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor];
        $first = \app\api\jxc\logic\FinanceStatements::action('generate', $request);
        self::assertSame('vendor', $first['subject_kind']); self::assertSame($vendor, $first['vendor_id']);
        self::assertSame('unanswered', $first['state']);
        self::assertSame('100.00', $first['snapshot']['balances']['payable']['closing']);
        self::assertSame('20.00', $first['snapshot']['balances']['supplier_refund']['closing']);
        self::assertSame('80.00', $first['snapshot']['net_reference']);
        self::assertArrayNotHasKey('receivable', $first['snapshot']['balances']);
        self::assertSame($first, \app\api\jxc\logic\FinanceStatements::action('generate', $request));
        $payment = $this->receipt('30', '30'); $payment['subject_id'] = $vendor; $payment['allocations'][0]['source'] = $source;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payment]), FinanceBusinessLogic::getError());
        $old = \app\api\jxc\logic\FinanceStatements::detail(['id' => $first['id'], 'subject_kind' => 'vendor']);
        self::assertSame($first['snapshot'], $old['snapshot']);
        $second = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor, 'previous_id' => $first['id']]);
        self::assertSame('70.00', $second['snapshot']['balances']['payable']['closing']);
        self::assertSame('50.00', $second['snapshot']['net_reference']);
        self::assertSame('supplier_payment', $second['snapshot']['actual_money'][0]['type']);
        self::assertSame('-30.00', $second['snapshot']['actual_money'][0]['amount']);
        self::assertSame($first['id'], $second['previous_id']);
        self::assertSame('unanswered', $second['state']);
    }

    public function test_supplier_dispute_keeps_ledger_intact_and_allows_only_undisputed_payment_until_resolved(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '异议供应商']);
        $ledger = new FinanceLedger(self::TENANT_ID); $source = $ledger->createSource(0, 'payable', $vendor, '100.00', date('Y-m-d'), null, ['reason' => '已确认采购']);
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        $reply = \app\api\jxc\logic\FinanceStatements::action('reply', $this->command(0) + ['subject_kind' => 'vendor', 'id' => $statement['id'],
            'response' => 'disputed', 'respondent' => '供应商负责人', 'response_date' => date('Y-m-d'), 'evidence' => '单价尚待核对',
            'items' => [['source' => $source, 'amount' => '20.00', 'reason' => '价格异议', 'ledger_uncertain' => true]]]);
        self::assertSame('disputed', $reply['state']); self::assertSame('100.00', $ledger->source($source)['balance']);
        self::assertSame([], \app\api\jxc\logic\FinanceStatements::openDisputes([$source]));
        $payment = $this->receipt('81', '81'); $payment['subject_id'] = $vendor; $payment['allocations'][0]['source'] = $source;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payment]));
        self::assertStringContainsString('争议', FinanceBusinessLogic::getError()); self::assertSame('100.00', $ledger->source($source)['balance']);
        $payment['amount'] = '80'; $payment['allocations'][0]['amount'] = '80';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payment]), FinanceBusinessLogic::getError());
        self::assertSame('20.00', $ledger->source($source)['balance']);
        $resolved = \app\api\jxc\logic\FinanceStatements::action('resolve', $this->command(1) + ['subject_kind' => 'vendor', 'id' => $statement['id'],
            'dispute_id' => $reply['disputes'][0]['id'], 'resolution' => 'ledger_verified', 'reason' => '账内价格正确，外部异议保留']);
        self::assertFalse($resolved['disputes'][0]['ledger_uncertain']);
        $resolved = \app\api\jxc\logic\FinanceStatements::action('resolve', $this->command(2) + ['subject_kind' => 'vendor', 'id' => $statement['id'],
            'dispute_id' => $reply['disputes'][0]['id'], 'resolution' => 'resolved', 'reason' => '供方核对后确认原单正确']);
        self::assertSame('awaiting_reconfirmation', $resolved['state']);
        $payment['amount'] = '20'; $payment['allocations'][0]['amount'] = '20';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payment]), FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->source($source)['balance']);
    }

    public function test_supplier_balances_and_statement_keep_unsettled_arrivals_out_of_formal_payables(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('对账仓');
        $goods = $this->createCustomerReportGoods('对账商品', 'FIN-SUP-STMT'); $sku = $this->customerReportSkuId($goods);
        $vendorName = '待结算供应商 ' . bin2hex(random_bytes(4));
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => $vendorName]);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-STMT', 'reason' => '实际到货',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $first = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        self::assertSame('0.00', $first['snapshot']['balances']['payable']['closing']);
        self::assertSame('100.0000', $first['snapshot']['pending_arrivals'][0]['pending_quantity']);
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '先确认四十斤', 'supplier_confirmation' => '双方确认', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'covered_quantity' => '40', 'settlement_quantity' => '40', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        $detail = \app\api\jxc\logic\FinanceSupplierBalances::detail(['vendor_id' => $vendor]);
        self::assertSame('80.00', $detail['balances']['payable']); self::assertSame('80.00', $detail['sources'][0]['available_payment']);
        self::assertSame('60.0000', $detail['pending_arrivals'][0]['pending_quantity']);
        self::assertArrayNotHasKey('overdue', $detail);
        $list = \app\api\jxc\logic\FinanceSupplierBalances::lists(['keyword' => $vendorName]);
        self::assertSame($vendor, $list['lists'][0]['id']); self::assertSame('80.00', $list['lists'][0]['payable']);
        $second = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        self::assertSame('60.0000', $second['snapshot']['pending_arrivals'][0]['pending_quantity']);
        self::assertSame('100.0000', \app\api\jxc\logic\FinanceStatements::detail(['subject_kind' => 'vendor', 'id' => $first['id']])['snapshot']['pending_arrivals'][0]['pending_quantity']);
    }

    public function test_supplier_statement_permissions_subjects_and_versions_cannot_cross_customer_or_store_boundaries(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '权限对账供应商']);
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        $employee = WorkforceLogic::saveEmployee(['name' => '客户对账经办', 'mobile' => '13800009937', 'bind_user_id' => 996937, 'is_enabled' => 1, 'process_ids' => [],
            'permission_keys' => ['finance.receivable.view', 'finance.receivable.prepare']]);
        self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996937; request()->adminId = 0;
        try { \app\api\jxc\logic\FinanceStatements::detail(['subject_kind' => 'vendor', 'id' => $statement['id']]); self::fail('客户权限不能读取供应商账'); }
        catch (\DomainException $error) { self::assertStringContainsString('权限', $error->getMessage()); }
        Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => 'finance.payable.view', 'create_time' => time()]);
        self::assertSame($statement['snapshot'], \app\api\jxc\logic\FinanceStatements::detail(['subject_kind' => 'vendor', 'id' => $statement['id']])['snapshot']);
        try { \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]); self::fail('只读不能生成'); }
        catch (\DomainException $error) { self::assertStringContainsString('权限', $error->getMessage()); }
        $this->prepareCustomerReportRequestContext();
        $wrong = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::OTHER_TENANT_ID, 'supplier_name' => '异店供应商']);
        try { \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $wrong]); self::fail('不允许异店对象'); }
        catch (\DomainException $error) { self::assertStringContainsString('本门店', $error->getMessage()); }
        $reply = $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor, 'id' => $statement['id'], 'response' => 'confirmed', 'respondent' => '供方', 'response_date' => date('Y-m-d'), 'evidence' => '供方明确全部确认'];
        $confirmed = \app\api\jxc\logic\FinanceStatements::action('reply', $reply); self::assertSame('confirmed', $confirmed['state']);
        self::assertSame($confirmed, \app\api\jxc\logic\FinanceStatements::action('reply', $reply));
        $stale = $reply; $stale['idempotency_key'] = $this->command(0)['idempotency_key'];
        try { \app\api\jxc\logic\FinanceStatements::action('reply', $stale); self::fail('过期版本不能覆盖回复'); }
        catch (\DomainException $error) { self::assertStringContainsString('已变化', $error->getMessage()); }
    }

    public function test_statement_is_immutable_and_regeneration_links_old_snapshot_without_confirming_customer(): void
    {
        $this->activate();
        $request = $this->command(0) + ['customer_id' => $this->customerId, 'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')];
        $first = \app\api\jxc\logic\FinanceStatements::action('generate', $request);
        self::assertSame('unanswered', $first['state']);
        self::assertSame('1000.00', $first['snapshot']['balances']['receivable']['opening']);
        self::assertSame($first, \app\api\jxc\logic\FinanceStatements::action('generate', $request));
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]));
        $old = \app\api\jxc\logic\FinanceStatements::detail(['id' => $first['id']]);
        self::assertSame('1000.00', $old['snapshot']['balances']['receivable']['closing']);
        $second = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId,
            'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d'), 'previous_id' => $first['id']]);
        self::assertSame($first['id'], $second['previous_id']);
        self::assertSame('900.00', $second['snapshot']['balances']['receivable']['closing']);
        self::assertSame('-100.00', $second['snapshot']['balances']['receivable']['change']);
        self::assertSame('100.00', $second['snapshot']['actual_money'][0]['amount']);
        self::assertSame('receipt', $second['snapshot']['actual_money'][0]['type']);
        self::assertSame('unanswered', $second['state']);
    }

    public function test_statement_dispute_does_not_reduce_debt_and_blocks_bad_debt_until_resolved(): void
    {
        $this->activate();
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        $reply = \app\api\jxc\logic\FinanceStatements::action('reply', $this->command(0) + ['id' => $statement['id'],
            'response' => 'disputed', 'respondent' => '客户负责人张先生', 'response_date' => date('Y-m-d'), 'evidence' => '当面对账，客户认为重量不符',
            'items' => [['source' => $this->receivable, 'amount' => '100', 'reason' => '重量需核实', 'ledger_uncertain' => true]]]);
        self::assertSame('disputed', $reply['state']);
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
        self::assertCount(1, \app\api\jxc\logic\FinanceStatements::openDisputes([$this->receivable]));
        $bad = $this->command(0) + ['type' => 'bad_debt', 'payload' => ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'),
            'amount' => '100', 'allocations' => [['source' => $this->receivable, 'amount' => '100']], 'reason' => '确认无法收回', 'basis' => '有核实依据', 'debt_verified' => 1, 'undisputed' => 1]];
        self::assertFalse(FinanceBusinessLogic::action('record', $bad));
        self::assertStringContainsString('争议', FinanceBusinessLogic::getError());
        $dispute = \app\api\jxc\logic\FinanceStatements::openDisputes([$this->receivable])[0];
        \app\api\jxc\logic\FinanceStatements::action('resolve', $this->command(1) + ['id' => $statement['id'], 'dispute_id' => $dispute['id'], 'resolution' => 'ledger_verified', 'reason' => '逐笔复核交付记录，账内无误，客户异议保留']);
        self::assertFalse(\app\api\jxc\logic\FinanceStatements::openDisputes([$this->receivable])[0]['ledger_uncertain']);
        self::assertFalse(FinanceBusinessLogic::action('record', $bad), '账内核实无误不等于客户争议消失');
        \app\api\jxc\logic\FinanceStatements::action('resolve', $this->command(2) + ['id' => $statement['id'], 'dispute_id' => $dispute['id'], 'resolution' => 'resolved', 'reason' => '客户已复核并撤回异议，留存对账回复']);
        self::assertSame([], \app\api\jxc\logic\FinanceStatements::openDisputes([$this->receivable]));
        self::assertNotFalse(FinanceBusinessLogic::action('record', $bad), FinanceBusinessLogic::getError());
    }

    public function test_statement_appendix_uses_actual_weight_and_only_formally_confirmed_coverage(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $first = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        self::assertCount(1, $first['snapshot']['pending_deliveries']);
        self::assertSame('2.0000', $first['snapshot']['pending_deliveries'][0]['pending_weight']);
        self::assertSame('1000.00', $first['snapshot']['balances']['receivable']['closing']);
        $confirmed = SalesSettlementLogic::submit($this->command(0) + ['order_id' => $sale['order_id'],
            'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '10']]]);
        self::assertNotFalse($confirmed, SalesSettlementLogic::getError());
        $second = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        self::assertSame([], $second['snapshot']['pending_deliveries']);
        self::assertSame('1020.00', $second['snapshot']['balances']['receivable']['closing']);
        self::assertCount(1, \app\api\jxc\logic\FinanceStatements::detail(['id' => $first['id']])['snapshot']['pending_deliveries']);
    }

    public function test_statement_keeps_each_partial_delivery_on_its_actual_day(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $firstDay = date('Y-m-01'); $secondDay = date('Y-m-02');
        Db::name('sales_order')->where('id', $sale['order_id'])->update(['datetimesingle' => strtotime($firstDay)]);
        Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->update(['delivered_time' => strtotime($firstDay)]);
        $delivery = Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->find();
        $event = Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->find(); unset($event['id']);
        $event['idempotency_key'] = 'second-' . uniqid(); $event['delivered_time'] = strtotime($secondDay);
        $eventId = Db::name('fulfillment_delivery_event')->insertGetId($event);
        unset($delivery['id']); $delivery['delivery_event_id'] = $eventId; $delivery['actual_delivery_weight'] = '3.0000';
        Db::name('fulfillment_delivery_item')->insert($delivery); Db::name('order_goods')->where('id', $sale['line_id'])->update(['base_quantity' => '5']);
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId, 'date_from' => $secondDay, 'date_to' => $secondDay]);
        self::assertCount(1, $statement['snapshot']['pending_deliveries']);
        self::assertSame($secondDay, $statement['snapshot']['pending_deliveries'][0]['date']);
        self::assertSame('3.0000', $statement['snapshot']['pending_deliveries'][0]['actual_weight']);
    }

    public function test_statement_allows_dispute_on_already_paid_source_and_rejects_cross_actor_replay(): void
    {
        $this->activate(); self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1000', '1000')]));
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        $command = $this->command(0) + ['id' => $statement['id'], 'response' => 'disputed', 'respondent' => '客户负责人', 'response_date' => date('Y-m-d'), 'evidence' => '客户复核已付款项目仍存在差异',
            'items' => [['source' => $this->receivable, 'amount' => '100', 'reason' => '已付款不等于认可重量', 'ledger_uncertain' => false]]];
        self::assertSame('disputed', \app\api\jxc\logic\FinanceStatements::action('reply', $command)['state']);
        request()->adminId = self::ADMIN_ID + 1;
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('同一提交标识');
        \app\api\jxc\logic\FinanceStatements::action('reply', $command);
    }

    public function test_statement_regeneration_projects_corrected_advance_date_without_duplicating_revision_amount(): void
    {
        $this->activate(); $payload = $this->receipt('100', '0', '100'); $payload['allocations'] = []; $payload['actual_date'] = date('Y-m-02');
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $advance = $receipt['confirmed_result']['created_sources'][0];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => ['subject_id' => $this->customerId,
            'receipt_id' => $receipt['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '20', 'allocations' => [['source' => $advance, 'amount' => '20']], 'reason' => '实际退回20']]), FinanceBusinessLogic::getError());
        $before = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId, 'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-01')]);
        self::assertSame('0.00', $before['snapshot']['balances']['advance']['closing']);
        $payload['actual_date'] = date('Y-m-01'); $payload['amount'] = '120'; $payload['advance_amount'] = '120';
        self::assertNotFalse(FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $payload, 'correction_reason' => '原款实际早一天到账且为120元']), FinanceBusinessLogic::getError());
        $after = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId, 'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-01'), 'previous_id' => $before['id']]);
        self::assertSame('120.00', $after['snapshot']['balances']['advance']['closing']);
        $current = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        self::assertSame('100.00', $current['snapshot']['balances']['advance']['closing']);
        self::assertSame(['120.00', '-20.00'], array_column($current['snapshot']['actual_money'], 'amount'));
        self::assertSame('0.00', \app\api\jxc\logic\FinanceStatements::detail(['id' => $before['id']])['snapshot']['balances']['advance']['closing']);
    }

    public function test_overdue_todo_updates_partial_balance_and_closes_without_erasing_observed_history(): void
    {
        $this->activate();
        Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->update(['source_snapshot' => json_encode(['subject_name' => '收款主客户', 'historical_date' => date('Y-m-01'), 'due_date' => date('Y-m-01')])]);
        $first = \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId]);
        self::assertCount(1, $first['lists']); self::assertSame('1000.00', $first['lists'][0]['balance']);
        $originalId = $first['lists'][0]['id'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('200', '200')]));
        $partial = \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId]);
        self::assertSame('800.00', $partial['lists'][0]['balance']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('800', '800')]));
        self::assertSame([], \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId])['lists']);
        $history = \app\api\jxc\logic\FinanceOverdue::history(['source' => $this->receivable]);
        self::assertCount(3, $history['lists']); self::assertSame('closed', $history['lists'][0]['state']);
        self::assertSame($originalId, $history['lists'][2]['id']); self::assertSame('1000.00', $history['lists'][2]['balance']);
        self::assertSame('receipt', $history['lists'][0]['document_type']);
    }

    public function test_overdue_todo_preserves_late_recording_explanation_and_due_change_closes_current_case(): void
    {
        $this->activate();
        Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->update(['source_snapshot' => json_encode(['subject_name' => '收款主客户', 'historical_date' => date('Y-m-01'), 'due_date' => date('Y-m-01')])]);
        \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId]);
        $receipt = $this->receipt('200', '200'); $receipt['actual_date'] = date('Y-m-01');
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]));
        $history = \app\api\jxc\logic\FinanceOverdue::history(['source' => $this->receivable]);
        self::assertSame(date('Y-m-01'), $history['lists'][0]['timing'][0]['effective_date']);
        self::assertSame('200.00', $history['lists'][0]['timing'][0]['amount']);
        $due = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receivable_due', 'payload' => ['subject_id' => $this->customerId, 'source' => $this->receivable,
            'new_due_date' => null, 'expected_due_revision' => 0, 'reason' => '双方重新商定，付款日暂未约定']]);
        self::assertNotFalse($due, FinanceBusinessLogic::getError());
        self::assertSame([], \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId])['lists']);
        $closed = \app\api\jxc\logic\FinanceOverdue::history(['source' => $this->receivable])['lists'][0];
        self::assertSame('closed', $closed['state']); self::assertSame('800.00', $closed['balance']); self::assertNull($closed['due_date']);
        $count = Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count();
        \app\api\jxc\logic\FinanceOverdue::capture(self::TENANT_ID);
        self::assertSame($count, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_overdue_preview_failure_and_permission_revocation_do_not_leave_or_expose_observations(): void
    {
        $this->activate();
        Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->update(['source_snapshot' => json_encode(['subject_name' => '收款主客户', 'historical_date' => date('Y-m-01'), 'due_date' => date('Y-m-01')])]);
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['action' => 'record', 'type' => 'receipt', 'payload' => $this->receipt('100', '100')]);
        self::assertNotEmpty($preview);
        self::assertSame(0, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1500', '1500')]));
        self::assertSame(0, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
        \app\api\jxc\logic\FinanceOverdue::capture(self::TENANT_ID);
        self::assertSame(1, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('权限');
        \app\api\jxc\logic\FinanceOverdue::history(['source' => $this->receivable]);
    }

    public function test_overdue_schedule_migration_replays_without_overriding_stopped_task(): void
    {
        $install = file_get_contents(dirname(__DIR__, 2) . '/public/install/db/like.sql');
        preg_match('/CREATE TABLE `\{\{prefix\}\}dev_crontab`[\s\S]*?;/u', $install, $match);
        $this->runStatements($this->prepareMigration(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $match[0])));
        $sql = $this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000010_finance_overdue_schedule.sql'));
        Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->delete();
        try {
            $this->runStatements($sql); Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->update(['status' => 2]); $this->runStatements($sql);
            self::assertSame(1, Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->count());
            self::assertSame(2, (int)Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->value('status'));
        } finally { Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->delete(); }
    }

    public function test_overdue_finance_write_paths_use_existing_transaction_primitive(): void
    {
        foreach (['FinanceBusinessLogic.php', 'FinanceSales.php'] as $file) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/logic/' . $file);
            self::assertStringNotContainsString('FinanceOverdue::capture(', $source);
            self::assertStringContainsString('FinanceOverdue::captureWithinTransaction(', $source);
        }
        $method = new \ReflectionMethod(\app\api\jxc\logic\FinanceOverdue::class, 'captureWithinTransaction');
        $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringNotContainsString('Db::transaction(', $source);
    }

    public function test_overdue_scheduler_isolates_bad_tenant_and_keeps_retryable_failure_visible(): void
    {
        $this->activate();
        Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->update(['source_snapshot' => json_encode(['subject_name' => '收款主客户', 'historical_date' => date('Y-m-01'), 'due_date' => date('Y-m-01')])]);
        $badBook = Db::name('finance_opening_book')->where('tenant_id', self::TENANT_ID)->find(); $badBook['tenant_id'] = self::OTHER_TENANT_ID; $badBook['confirmed_snapshot'] = '{invalid';
        Db::name('finance_opening_book')->insert($badBook);
        $command = new \app\common\command\FinanceRefreshOverdue();
        $output = new \think\console\Output('buffer');
        $method = new \ReflectionMethod($command, 'execute');
        self::assertSame(1, $method->invoke($command, new \think\console\Input([]), $output));
        self::assertSame(1, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
        Db::name('finance_opening_book')->where('tenant_id', self::OTHER_TENANT_ID)->delete();
        self::assertSame(0, $method->invoke($command, new \think\console\Input([]), $output));
        self::assertSame(1, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_sales_precision_rounds_each_line_and_preserves_automatic_difference_separately_from_manual_rounding(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        Db::name('order_goods')->where('id', $sale['line_id'])->update(['base_quantity' => '2.0050']);
        Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->update(['actual_delivery_weight' => '2.0050']);
        $confirmed = SalesSettlementLogic::submit($this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'],
            'customer_settlement_weight' => '2.0050', 'pricing_unit_id' => $sale['unit_id'], 'price' => '1.23']]]);
        self::assertNotFalse($confirmed, SalesSettlementLogic::getError());
        self::assertSame('2.47', $confirmed['order_money']);
        $snapshot = json_decode(Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->value('snapshot_json'), true);
        self::assertSame('0.003850', $snapshot['automatic_rounding_difference']); self::assertSame('0.00', $snapshot['rounding_amount']);
        self::assertSame('cents', $snapshot['precision']['actual_mode']);
    }

    public function test_sales_precision_defaults_override_and_history_are_explicit_and_idempotent(): void
    {
        $this->activate();
        $saved = \app\api\jxc\logic\FinanceSalesPrecision::save($this->command(0) + ['customer_id' => 0, 'mode' => 'integer', 'reason' => '门店逐行四舍五入到整元']);
        self::assertSame('integer', $saved['rule']['default_mode']);
        $sale = $this->deliveredSale();
        $command = $this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '1.26']]];
        $confirmed = SalesSettlementLogic::submit($command); self::assertNotFalse($confirmed, SalesSettlementLogic::getError()); self::assertSame('3.00', $confirmed['order_money']);
        self::assertSame($confirmed, SalesSettlementLogic::submit($command));
        $rule = \app\api\jxc\logic\FinanceSalesPrecision::save($this->command(0) + ['customer_id' => $this->customerId, 'mode' => 'cents', 'reason' => '该主客户采用两位小数']);
        self::assertSame('cents', $rule['rule']['default_mode']);
        $original = json_decode(Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->value('snapshot_json'), true);
        self::assertSame('integer', $original['precision']['actual_mode']);
        $new = $this->deliveredSale();
        $request = $this->command(0) + ['order_id' => $new['order_id'], 'precision_mode' => 'integer', 'lines' => [['order_goods_id' => $new['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $new['unit_id'], 'price' => '1.26']]];
        self::assertFalse(SalesSettlementLogic::submit($request), '单笔覆盖必须填写原因');
        $request['precision_override_reason'] = '客户本次要求按整元逐行核对';
        self::assertNotFalse(SalesSettlementLogic::submit($request), SalesSettlementLogic::getError());
    }

    public function test_sales_precision_and_manual_rounding_permissions_are_independent_and_revocation_blocks_replay(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $employee = WorkforceLogic::saveEmployee(['name' => '精度经办', 'mobile' => '13800009929', 'bind_user_id' => 996929,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['settlement.view', 'settlement.bill']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996929; request()->adminId = 0;
        $request = $this->command(0) + ['order_id' => $sale['order_id'], 'rounding_amount' => '1.00',
            'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '1.26']]];
        self::assertFalse(SalesSettlementLogic::submit($request)); self::assertStringContainsString('抹零权限', SalesSettlementLogic::getError());
        $request['rounding_amount'] = '0'; $request['precision_mode'] = 'integer'; $request['precision_override_reason'] = '本次客户按整元结算';
        self::assertFalse(SalesSettlementLogic::submit($request)); self::assertStringContainsString('权限', SalesSettlementLogic::getError());
        foreach (['finance.sales.precision_override', 'finance.sales.rounding'] as $permission) {
            Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => $permission, 'create_time' => time()]);
        }
        $request['rounding_amount'] = '2.00';
        $confirmed = SalesSettlementLogic::submit($request); self::assertNotFalse($confirmed, SalesSettlementLogic::getError());
        self::assertSame('1.00', $confirmed['order_money'], '有独立权限即可抹零，不采用旧阈值或二次确认开关');
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.sales.precision_override')->delete();
        self::assertFalse(SalesSettlementLogic::submit($request), '撤销精度覆盖权限后不得读取原覆盖请求缓存'); self::assertStringContainsString('权限', SalesSettlementLogic::getError());
        self::assertSame(1, Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->count());
    }

    public function test_sales_precision_rejects_stale_rules_and_keeps_customer_inheritance_explicit(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $request = $this->command(0) + ['order_id' => $sale['order_id'], 'precision_rules' => ['store_version' => 0, 'customer_version' => 0],
            'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '1.26']]];
        $ruleCommand = $this->command(0) + ['customer_id' => 0, 'mode' => 'integer', 'reason' => '门店默认整元'];
        $saved = \app\api\jxc\logic\FinanceSalesPrecision::save($ruleCommand);
        self::assertSame($saved, \app\api\jxc\logic\FinanceSalesPrecision::save($ruleCommand));
        self::assertSame('inherit', $saved['rule']['customer_mode']);
        self::assertFalse(SalesSettlementLogic::submit($request)); self::assertStringContainsString('默认规则已变化', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->count());
        $request['precision_rules']['store_version'] = 1;
        self::assertNotFalse(SalesSettlementLogic::submit($request), SalesSettlementLogic::getError());
    }

    public function test_sales_batch_partially_covers_multiple_deliveries_on_each_real_day_without_touching_stock(): void
    {
        $this->activate(); $first = $this->deliveredSale(); $second = $this->deliveredSale();
        $day = date('Y-m-01');
        Db::name('fulfillment_delivery_event')->where('id', $first['event_id'])->update(['delivered_time' => strtotime($day . ' 10:00:00')]);
        $firstItem = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $first['event_id'])->value('id');
        $secondItem = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $second['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '主客户两次交付合并部分确认', 'rounding_amount' => '0', 'precision_mode' => 'cents', 'lines' => [
            ['delivery_item_id' => $firstItem, 'covered_weight' => '1', 'settlement_weight' => '1', 'price' => '10'],
            ['delivery_item_id' => $secondItem, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '20'],
        ]];
        $command = $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame('50.00', $preview['impacts'][0]['amount']);
        $confirmed = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertSame($confirmed, FinanceBusinessLogic::action('record', $command));
        self::assertSame('50.00', $confirmed['confirmed_result']['amount']);
        $dates = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $confirmed['id'])->where('metric', 'revenue')->order('id')->column('business_date');
        self::assertSame([$day, date('Y-m-d')], $dates);
        self::assertSame('0.00', Db::name('sales_order')->where('id', $first['order_id'])->value('order_money'));
        $snapshot = \app\api\jxc\logic\FinanceStatementSnapshot::capture($this->customerId, $day, date('Y-m-d'));
        self::assertCount(1, $snapshot['pending_deliveries']); self::assertSame('1.0000', $snapshot['pending_deliveries'][0]['pending_weight']);
        $payload['lines'] = [$payload['lines'][0]]; $payload['overdue_acknowledged'] = 1;
        $secondCommand = $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $secondCommand), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]));
        self::assertStringContainsString('覆盖量', FinanceBusinessLogic::getError());
        self::assertSame('1060.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('receivable', $this->customerId));
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_sales_batch_correction_keeps_history_and_allocates_paid_reduction_as_credit_and_refund(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '核实客户结算', 'lines' => [
            ['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '50']]];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]);
        self::assertNotFalse($original, FinanceBusinessLogic::getError()); $source = $original['confirmed_result']['created_sources'][0];
        $receipt = $this->receipt('80', '80'); $receipt['allocations'][0]['source'] = $source;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]));
        $payload['lines'][0]['price'] = '20';
        $correction = $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '原单价录入错误'];
        self::assertFalse(FinanceBusinessLogic::action('correct', $correction)); self::assertStringContainsString('调减须明确', FinanceBusinessLogic::getError());
        self::assertSame(0, Db::name('finance_correction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(1, Db::name('finance_sales_coverage')->where('tenant_id', self::TENANT_ID)->count());
        $tooMuch = $correction;
        $tooMuch['payload'] += ['credit_reviewed' => 1, 'credit_allocations' => [['source' => $source, 'amount' => '60']]];
        self::assertFalse(FinanceBusinessLogic::action('correct', $tooMuch)); self::assertStringContainsString('超过当前未结余额', FinanceBusinessLogic::getError());
        self::assertSame('20.00', (new FinanceLedger(self::TENANT_ID))->source($source)['balance']);
        $correction['payload'] += ['credit_reviewed' => 1, 'credit_allocations' => [['source' => $source, 'amount' => '20']]];
        $corrected = FinanceBusinessLogic::action('correct', $correction); self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        self::assertSame('40.00', $corrected['confirmed_result']['amount']);
        self::assertSame($corrected, FinanceBusinessLogic::action('correct', $correction));
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('0.00', $ledger->source($source)['balance']);
        self::assertSame('40.00', $ledger->categoryBalance('customer_refund', $this->customerId));
        self::assertSame('100.00', FinanceBusinessLogic::detail(['id' => $original['id']])['confirmed_result']['amount']);
        self::assertSame('2.0000', (string)Db::name('finance_sales_coverage')->where('tenant_id', self::TENANT_ID)->field('SUM(covered_delta) AS covered')->find()['covered']);
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_sales_batch_pending_rechecks_coverage_and_cannot_overlap_legacy_full_order_confirmation(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '确认一半实际交付', 'lines' => [
            ['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '10']]];
        $draft = FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]);
        self::assertNotFalse($draft); self::assertSame(0, Db::name('finance_sales_coverage')->where('tenant_id', self::TENANT_ID)->count());
        $payload['lines'][0]['covered_weight'] = '1'; $payload['lines'][0]['settlement_weight'] = '1';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]));
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command(1) + ['id' => $draft['id']]));
        self::assertStringContainsString('覆盖量', FinanceBusinessLogic::getError());
        self::assertSame('pending', FinanceBusinessLogic::detail(['id' => $draft['id']])['status']);
        $full = $this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '10']]];
        self::assertFalse(SalesSettlementLogic::submit($full)); self::assertStringContainsString('分次结算', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->count());
    }

    public function test_sales_batch_rounding_preserves_each_delivery_share_and_requires_independent_permissions(): void
    {
        $this->activate(); $a = $this->deliveredSale(); $b = $this->deliveredSale();
        $lines = [];
        foreach ([$a, $b] as $sale) { $lines[] = ['delivery_item_id' => (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id'), 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '1.26']; }
        $employee = WorkforceLogic::saveEmployee(['name' => '销售经办', 'mobile' => '13800009929', 'bind_user_id' => 996929,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['settlement.bill', 'settlement.view']]);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996929; request()->adminId = 0;
        $payload = ['subject_id' => $this->customerId, 'reason' => '逐行整元且单独抹零', 'lines' => $lines, 'precision_mode' => 'integer', 'precision_override_reason' => '与客户约定', 'rounding_amount' => '0.01'];
        $command = $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload];
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
        foreach (['finance.sales.precision_override', 'finance.sales.rounding'] as $permission) { Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => $permission, 'create_time' => time()]); }
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame('5.99', $result['confirmed_result']['amount']); self::assertSame(['2.99', '3.00'], array_column($result['confirmed_result']['lines'], 'net_amount'));
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.sales.rounding')->delete();
        self::assertFalse(FinanceBusinessLogic::action('record', $command)); self::assertStringContainsString('权限', FinanceBusinessLogic::getError());
    }

    public function test_sales_batch_positive_correction_inherits_current_adjusted_due_date_and_preserves_old_snapshot(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '按实际交付结算', 'lines' => [
            ['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '50']]];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]);
        self::assertNotFalse($original); $source = $original['confirmed_result']['created_sources'][0]; $due = date('Y-m-t');
        $changed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receivable_due', 'payload' => ['subject_id' => $this->customerId, 'source' => $source, 'expected_due_revision' => 0, 'new_due_date' => $due, 'reason' => '客户重新约定月底付款']]);
        self::assertNotFalse($changed, FinanceBusinessLogic::getError());
        $payload['lines'][0]['price'] = '60'; $payload['lines'][0]['due_date'] = date('Y-m-d');
        $corrected = FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '仅纠正单价']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        $newSource = (new FinanceLedger(self::TENANT_ID))->source($corrected['confirmed_result']['created_sources'][0]);
        self::assertSame('20.00', $newSource['balance']); self::assertSame($due, $newSource['due_date']);
        self::assertSame(date('Y-m-d'), FinanceBusinessLogic::detail(['id' => $original['id']])['confirmed_result']['lines'][0]['due_date']);
        $payload['lines'] = $corrected['confirmed_result']['lines']; $payload['lines'][0]['price'] = '65';
        $again = FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $corrected['id'], 'payload' => $payload, 'correction_reason' => '复核后再次纠正单价']);
        self::assertNotFalse($again, FinanceBusinessLogic::getError());
        self::assertSame($due, (new FinanceLedger(self::TENANT_ID))->source($again['confirmed_result']['created_sources'][0])['due_date']);
    }

    public function test_sales_batch_output_keeps_original_amount_debt_snapshot_and_server_print_numbers_across_corrections(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'show_cumulative_debt' => 1, 'reason' => '输出确认凭证', 'lines' => [
            ['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '50']]];
        $record = $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload];
        $original = FinanceBusinessLogic::action('record', $record); self::assertNotFalse($original);
        $output = \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $original['id']]);
        self::assertSame('100.00', $output['order_money']); self::assertSame('1100.00', $output['debt_after_order']); self::assertTrue($output['show_cumulative_debt']);
        self::assertTrue($original['output']['offline_generation_allowed']); self::assertArrayNotHasKey('offline_generation_allowed', $output);
        $print = $this->command(1) + ['id' => $original['id']];
        $prepared = \app\api\jxc\logic\FinanceSalesOutput::prepare($print);
        self::assertSame(1, $prepared['copy_no']); self::assertSame($prepared, \app\api\jxc\logic\FinanceSalesOutput::prepare($print));
        $receipt = ['expected_tenant_id' => self::TENANT_ID, 'id' => $original['id'], 'print_log_id' => $prepared['print_log_id'], 'success' => 1];
        self::assertSame(1, \app\api\jxc\logic\FinanceSalesOutput::receipt($receipt)['successful_print_count']);
        self::assertSame(1, \app\api\jxc\logic\FinanceSalesOutput::receipt($receipt)['successful_print_count']);
        $payload['lines'][0]['price'] = '60';
        $corrected = FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '核实新单价']); self::assertNotFalse($corrected);
        $old = \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $original['id']]); $new = \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $corrected['id']]);
        self::assertSame('100.00', $old['order_money']); self::assertSame('1100.00', $old['debt_after_order']); self::assertStringContainsString('已被', $old['invalidation_notice']);
        self::assertSame($old['order_sn'], $new['order_sn']); self::assertSame(2, $new['version']); self::assertSame('120.00', $new['order_money']);
        $again = \app\api\jxc\logic\FinanceSalesOutput::prepare($this->command(1) + ['id' => $corrected['id']]); self::assertSame(2, $again['copy_no']);
        self::assertStringContainsString('已被', \app\api\jxc\logic\FinanceSalesOutput::prepare($print)['document']['invalidation_notice']);
        self::assertStringContainsString('已被', FinanceBusinessLogic::action('record', $record)['output']['invalidation_notice']);
        self::assertSame($again['print_log_id'], (int)\app\api\jxc\logic\FinanceSalesOutput::document(['id' => $original['id']])['pending_print']['id']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('10', '10')]));
        self::assertSame('1100.00', \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $original['id']])['debt_after_order']);
    }

    public function test_sales_batch_output_viewer_can_read_and_print_but_cannot_change_sales_or_other_actor_receipts(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '只读打印验收', 'lines' => [['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '50']]];
        $draft = FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]);
        try { \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $draft['id']]); self::fail('草稿不得输出'); } catch (\DomainException $error) { self::assertStringContainsString('已确认', $error->getMessage()); }
        $formal = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]); self::assertNotFalse($formal);
        $ownerPrint = $this->command(1) + ['id' => $formal['id']]; $prepared = \app\api\jxc\logic\FinanceSalesOutput::prepare($ownerPrint);
        $employee = WorkforceLogic::saveEmployee(['name' => '只读打印员', 'mobile' => '13800009939', 'bind_user_id' => 996939,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['settlement.view']]); self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996939; request()->adminId = 0;
        $detail = FinanceBusinessLogic::detail(['id' => $formal['id']]); self::assertNotFalse($detail);
        self::assertFalse($detail['output']['can_share']); self::assertFalse($detail['output']['pending_print']['can_resolve']);
        $options = FinanceBusinessLogic::options(['type' => 'sales_batch', 'subject_id' => $this->customerId]); self::assertNotFalse($options); self::assertFalse($options['can_confirm']); self::assertFalse($options['can_prepare']);
        self::assertFalse(FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]));
        try { \app\api\jxc\logic\FinanceSalesOutput::prepare($ownerPrint); self::fail('不可复用其他人员标识'); } catch (\DomainException $error) { self::assertStringContainsString('操作人', $error->getMessage()); }
        $receipt = ['expected_tenant_id' => self::TENANT_ID, 'id' => $formal['id'], 'print_log_id' => $prepared['print_log_id'], 'success' => 1];
        try { \app\api\jxc\logic\FinanceSalesOutput::receipt($receipt); self::fail('不可核实其他人员出纸'); } catch (\DomainException $error) { self::assertStringContainsString('原打印人员', $error->getMessage()); }
        $this->prepareCustomerReportRequestContext();
        \app\api\jxc\logic\FinanceSalesOutput::receipt($receipt);
        try { \app\api\jxc\logic\FinanceSalesOutput::receipt(array_replace($receipt, ['success' => 0])); self::fail('不可反向回执'); } catch (\DomainException $error) { self::assertStringContainsString('冲突', $error->getMessage()); }
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996939; request()->adminId = 0;
        $own = \app\api\jxc\logic\FinanceSalesOutput::prepare($this->command(1) + ['id' => $formal['id']]); self::assertSame(2, $own['copy_no']);
        self::assertSame(1, \app\api\jxc\logic\FinanceSalesOutput::receipt(['expected_tenant_id' => self::TENANT_ID, 'id' => $formal['id'], 'print_log_id' => $own['print_log_id'], 'success' => 0])['successful_print_count']);
        $status = \app\api\jxc\logic\FinanceSalesOutput::status(['expected_tenant_id' => self::TENANT_ID, 'id' => $formal['id'], 'print_log_id' => $own['print_log_id']]); self::assertSame('failed', $status['status']);
        $next = \app\api\jxc\logic\FinanceSalesOutput::prepare($this->command(1) + ['id' => $formal['id']]); self::assertSame(3, $next['copy_no']); self::assertSame(1, $next['reprint_count'], '失败尝试占流水号，但不增加重打次数');
    }

    public function test_legacy_sale_uses_real_delivery_day_for_first_revenue_and_default_due_date(): void
    {
        $this->activate(); $sale = $this->deliveredSale(); $day = date('Y-m-01');
        Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->update(['delivered_time' => strtotime($day)]);
        \app\api\jxc\logic\FinanceSalesRules::save($this->command(0) + ['customer_id' => $this->customerId, 'mode' => 'days_after', 'days' => 5, 'reason' => '交付后五天付款']);
        $detail = SalesSettlementLogic::detail(['id' => $sale['order_id']]); self::assertSame(date('Y-m-06'), $detail['finance']['terms']['default_due_date']);
        $result = SalesSettlementLogic::submit($this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '50']]]);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        $version = Db::name('finance_sales_version')->where('order_id', $sale['order_id'])->find();
        self::assertSame($day, $version['business_date']); self::assertSame(date('Y-m-06'), $version['due_date']);
    }

    public function test_legacy_first_confirmation_cannot_put_multiple_delivery_days_on_one_revenue_day(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->update(['delivered_time' => strtotime(date('Y-m-01'))]);
        $event = Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->find(); unset($event['id']); $event['idempotency_key'] = 'legacy-second-' . uniqid(); $event['delivered_time'] = strtotime(date('Y-m-02'));
        $eventId = (int)Db::name('fulfillment_delivery_event')->insertGetId($event);
        $item = Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->find(); unset($item['id']); $item['delivery_event_id'] = $eventId; $item['actual_delivery_weight'] = '3'; Db::name('fulfillment_delivery_item')->insert($item);
        Db::name('order_goods')->where('id', $sale['line_id'])->update(['base_quantity' => '5']);
        $result = SalesSettlementLogic::submit($this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '5', 'pricing_unit_id' => $sale['unit_id'], 'price' => '50']]]);
        self::assertFalse($result); self::assertStringContainsString('交付', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('finance_sales_version')->where('order_id', $sale['order_id'])->count());
        self::assertTrue(SalesSettlementLogic::detail(['id' => $sale['order_id']])['finance']['requires_delivery_batches']);
    }

    public function test_legacy_price_correction_after_new_delivery_batch_preserves_original_coverage_and_total_delivery(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $request = ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '50']]];
        self::assertNotFalse(SalesSettlementLogic::submit($this->command(0) + $request));
        $event = Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->find(); unset($event['id']); $event['idempotency_key'] = 'legacy-extra-' . uniqid();
        $eventId = (int)Db::name('fulfillment_delivery_event')->insertGetId($event);
        $item = Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->find(); unset($item['id']); $item['delivery_event_id'] = $eventId; $item['actual_delivery_weight'] = '3'; $delivery = (int)Db::name('fulfillment_delivery_item')->insertGetId($item);
        Db::name('order_goods')->where('id', $sale['line_id'])->update(['base_quantity' => '5']);
        $batch = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => ['subject_id' => $this->customerId, 'reason' => '新增交付单独结算', 'lines' => [['delivery_item_id' => $delivery, 'covered_weight' => '3', 'settlement_weight' => '3', 'price' => '40']]]]); self::assertNotFalse($batch, FinanceBusinessLogic::getError());
        $detail = SalesSettlementLogic::detail(['id' => $sale['order_id']]); self::assertSame('2.0000', $detail['lines'][0]['actual_delivery_weight']); self::assertTrue($detail['finance']['preserves_legacy_coverage']);
        $request['lines'][0]['price'] = '60'; $request['edit_reason'] = '原来两斤的单价更正';
        $corrected = SalesSettlementLogic::submit($this->command(1) + $request); self::assertNotFalse($corrected, SalesSettlementLogic::getError());
        self::assertSame('1240.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('receivable', $this->customerId));
        self::assertSame('5.0000', Db::name('order_goods')->where('id', $sale['line_id'])->value('base_quantity'));
        self::assertSame([], \app\api\jxc\logic\FinanceDeliveries::rows($this->customerId, date('Y-m-01'), date('Y-m-d')));
        self::assertSame(0, Db::name('sales_delivery_correction')->where('order_id', $sale['order_id'])->count());
        $request['lines'][0]['actual_delivery_weight'] = '3';
        self::assertFalse(SalesSettlementLogic::submit($this->command(2) + $request)); self::assertStringContainsString('原覆盖量', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('sales_weight_difference_todo')->where('order_id', $sale['order_id'])->count(), '禁止的覆盖量变更不能留下无法确认的待办');
    }

    public function test_legacy_actual_correction_without_later_delivery_remains_valid_and_updates_stock_only_once(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $request = ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '50']]];
        $initial = SalesSettlementLogic::submit($this->command(0) + $request); self::assertNotFalse($initial);
        $request['lines'][0]['actual_delivery_weight'] = '1.5'; $request['lines'][0]['customer_settlement_weight'] = '1.5'; $request['edit_reason'] = '原实际交付误录';
        $request['credit_reviewed'] = 1; $request['credit_allocations'] = [['source' => $initial['finance']['source_ref'], 'amount' => '25']];
        $command = $this->command(1) + $request; $corrected = SalesSettlementLogic::submit($command); self::assertNotFalse($corrected, SalesSettlementLogic::getError());
        self::assertSame($corrected, SalesSettlementLogic::submit($command)); self::assertSame('75.00', $corrected['order_money']);
        self::assertSame(1, Db::name('sales_delivery_correction')->where('order_id', $sale['order_id'])->count());
        self::assertFalse(SalesSettlementLogic::detail(['id' => $sale['order_id']])['finance']['preserves_legacy_coverage']);
    }

    private function deliveredSale(?string $businessDate = null): array
    {
        $unit = $this->createCustomerReportUnit('斤');
        $warehouse = $this->createCustomerReportWarehouse('已交付核算仓');
        $goods = $this->createCustomerReportGoods('已交付商品', 'FINANCE-SALE', '斤');
        $sku = $this->customerReportSkuId($goods); $now = $businessDate ? strtotime($businessDate . ' 12:00:00') : time();
        Db::name('goods')->where('id', $goods)->update(['unit_id' => $unit]);
        Db::name('goods_sku')->where('id', $sku)->update(['base_unit_id' => $unit, 'base_unit_name' => '斤']);
        Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $goods, 'unit_id' => $unit, 'unit_name' => '斤', 'is_base_unit' => 1, 'sort' => 0, 'status' => 1, 'create_time' => $now, 'update_time' => $now]);
        $order = (int)Db::name('sales_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'order_sn' => 'FINANCE-SALE-' . uniqid(), 'customer_id' => $this->customerId,
            'customer_name' => '收款主客户', 'warehouse_id' => $warehouse, 'order_money' => '0', 'order_pay_money' => '0', 'order_arrears_money' => '0',
            'datetimesingle' => $now, 'source_type' => 'customer_report', 'source_id' => random_int(100000, 900000), 'source_version' => 1,
            'settlement_status' => 'pending', 'cost_status' => 'pending', 'profit_status' => 'pending_settlement', 'status' => 1,
            'purpose_type' => 'sales', 'remarks' => '', 'admin_id' => self::ADMIN_ID, 'idempotent_key' => '', 'create_time' => $now, 'update_time' => $now]);
        $line = (int)Db::name('order_goods')->insertGetId(['tenant_id' => self::TENANT_ID, 'order_id' => $order, 'order_type' => 'sales', 'goods_id' => $goods,
            'sku_id' => $sku, 'sku_name' => '默认规格', 'supplier_relation_id' => 0, 'name' => '已交付商品', 'units' => '斤', 'number' => '2', 'base_quantity' => '2',
            'price' => '0', 'amount' => '0', 'pricing_unit_id' => 0, 'source_line_type' => 'customer_report_item', 'source_line_id' => random_int(100000, 900000),
            'remark' => '', 'sort' => 1, 'create_time' => $now, 'update_time' => $now]);
        $event = (int)Db::name('fulfillment_delivery_event')->insertGetId(['tenant_id' => self::TENANT_ID, 'idempotency_key' => 'finance-delivery-' . uniqid(), 'delivered_time' => $now]);
        Db::name('fulfillment_delivery_item')->insert(['tenant_id' => self::TENANT_ID, 'delivery_event_id' => $event, 'sales_order_id' => $order,
            'report_item_id' => Db::name('order_goods')->where('id', $line)->value('source_line_id'), 'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'actual_delivery_weight' => '2.0000']);
        Db::transaction(fn() => (new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID))->recordWithinTransaction([
            'reference' => 'fixture-delivery:' . $event, 'type' => 'issue', 'sku_id' => $sku, 'warehouse_id' => $warehouse,
            'business_date' => date('Y-m-d', $now), 'quantity' => '2', 'bucket' => 'sale', 'target_reference' => 'sales_order:' . $order]));
        return ['order_id' => $order, 'line_id' => $line, 'unit_id' => $unit, 'event_id' => $event];
    }

    private function activate(string $accountType = 'cash', ?string $activationDate = null): void
    {
        $account = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '经营账户', 'account_type' => $accountType]);
        self::assertNotFalse($account); $this->accountId = (int)$account['id'];
        $this->customerId = $this->createCustomer('收款主客户');
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => $activationDate ?? date('Y-m-01'),
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        foreach ([['account', $this->accountId, '5000'], ['receivable', $this->customerId, '1000']] as [$category, $subjectId, $amount]) {
            $this->opening('item', ['category' => $category, 'subject_id' => $subjectId, 'amount' => $amount, 'historical_date' => null, 'due_date' => null,
                'source_mode' => 'detail', 'source_reference' => '核实旧余额', 'evidence' => '按统一截点核实']);
        }
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $this->opening('submit'); $this->opening('confirm');
        $this->receivable = (new FinanceLedger(self::TENANT_ID))->sources('receivable', $this->customerId)[0]['reference'];
    }

    private function receipt(string $amount, string $allocated, string $advance = '0'): array
    {
        return ['account_id' => $this->accountId, 'subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => $amount,
            'allocations' => [['source' => $this->receivable, 'amount' => $allocated]], 'advance_amount' => $advance, 'reason' => '客户实际支付旧欠款'];
    }

    private function command(int $version): array { return ['expected_tenant_id' => self::TENANT_ID, 'expected_version' => $version, 'idempotency_key' => 'business-workflow-' . ++$this->sequence]; }
    private function opening(string $action, array $data = []): array
    {
        $result = FinanceSetupLogic::openingAction($action, $this->command((int)FinanceSetupLogic::opening()['version']) + $data);
        self::assertNotFalse($result, FinanceSetupLogic::getError()); return $result;
    }
    private function clean(): void
    {
        Db::name('finance_transit_reconciliation')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_report_export_access')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_report_export')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_cash_shortage_application')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_account_reconciliation')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        foreach (['finance_recurring_month_revision', 'finance_recurring_expense_month', 'finance_recurring_expense_plan'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        foreach (['finance_transfer_settlement', 'finance_account_transfer', 'finance_opening_equipment_revision', 'finance_equipment_refund_revision', 'finance_equipment_refund_due', 'finance_equipment_revision', 'finance_equipment_purchase', 'finance_salary_revision', 'finance_salary_result', 'finance_employee_expense_revision', 'finance_employee_expense', 'finance_expense_estimate_resolution', 'finance_deferred_amortization', 'finance_expense_identity', 'finance_expense_revision', 'finance_expense_bill', 'finance_expense_category'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        foreach (['finance_inventory_loss_resolution', 'finance_inventory_loss'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        Db::name('finance_purchase_arrival_loss')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_purchase_difference_review')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        foreach (['finance_purchase_cost_revision', 'finance_purchase_cost_change', 'finance_purchase_cost_bill'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        foreach (['finance_supplier_terms', 'finance_purchase_difference_rule', 'finance_purchase_settlement_line', 'finance_purchase_arrival_line', 'finance_purchase_price'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        foreach (['finance_stock_transfer_pair', 'finance_cost_effect', 'finance_cost_event', 'finance_cost_shortage', 'finance_cost_position', 'finance_cost_origin'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        Db::name('finance_sales_print')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_sales_coverage')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_sales_precision_rule')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_overdue_event')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        foreach (['finance_statement_resolution', 'finance_statement_dispute', 'finance_statement_event', 'finance_statement'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        foreach (['finance_supplier_statement_resolution', 'finance_supplier_statement_dispute', 'finance_supplier_statement_event', 'finance_supplier_statement'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        Db::name('finance_purchase_return_line')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_purchase_return_resolution')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_advance_revision')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_due_adjustment')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_customer_terms')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        foreach (Db::name('finance_evidence')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->select()->toArray() as $proof) {
            $snapshot = json_decode($proof['snapshot'], true); $key = $snapshot['storage_key'] ?? '';
            if (preg_match('/^[a-f0-9]{48}\.(png|jpg|webp)$/D', $key)) {
                $file = root_path() . 'storage/finance-evidence/' . (int)$proof['tenant_id'] . '/' . $key;
                if (is_file($file)) { unlink($file); }
            }
        }
        foreach (['finance_sales_version', 'finance_transaction_identity', 'finance_correction', 'finance_evidence', 'finance_period', 'finance_money_transaction', 'finance_entry', 'finance_source', 'finance_command', 'finance_document',
            'finance_opening_item_detail', 'finance_opening_source', 'finance_opening_item', 'finance_opening_book', 'finance_setup_action', 'finance_account', 'finance_preparation'] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        }
        $this->cleanCustomerReportData();
    }
}
