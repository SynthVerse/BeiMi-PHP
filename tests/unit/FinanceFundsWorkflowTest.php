<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceBusinessLogic;
use app\api\jxc\logic\FinanceLedger;
use app\api\jxc\logic\FinancePreview;
use app\api\jxc\logic\FinanceReports;
use app\api\jxc\logic\TodoFinanceSourceProvider;
use app\api\jxc\logic\TodoQueryBudget;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

/** 本金、资金事实、损益和幂等经真实业务入口验证；仅使用专用测试租户。 */
final class FinanceFundsWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;
    private const FUNDS_TENANT = 884240;
    private int $account;
    private int $vendor;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext(); $this->ensureCustomerReportTables();
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*finance*.sql') as $file) {
            if (str_ends_with($file, '20261015_000002_finance_draft_continuation.sql') && Db::query("SHOW COLUMNS FROM la_finance_document LIKE 'resume_requested'")) { continue; }
            $this->runStatements($this->prepareMigration((string)file_get_contents($file)));
        }
        Db::execute('CREATE TABLE IF NOT EXISTS la_vendor (id int unsigned AUTO_INCREMENT PRIMARY KEY, tenant_id int unsigned NOT NULL, supplier_name varchar(100) NOT NULL) ENGINE=InnoDB');
        $this->cleanFunds(); $this->prepareCustomerReportRequestContext(self::FUNDS_TENANT);
        Db::name('finance_preparation')->insert(['tenant_id' => self::FUNDS_TENANT, 'activation_date' => date('Y-m-01')]);
        Db::name('finance_opening_book')->insert(['tenant_id' => self::FUNDS_TENANT, 'status' => 'active', 'reviews' => '{}', 'confirmed_snapshot' => '{}', 'created_by' => '{}', 'last_modified_by' => '{}', 'create_time' => time(), 'update_time' => time()]);
        $this->account = (int)Db::name('finance_account')->insertGetId(['tenant_id' => self::FUNDS_TENANT, 'name' => '隔离资金账户', 'account_type' => 'cash', 'create_time' => time(), 'update_time' => time()]);
        $this->vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::FUNDS_TENANT, 'supplier_name' => '隔离预付款供应商']);
    }

    protected function tearDown(): void { $this->cleanFunds(); $this->prepareCustomerReportRequestContext(); }

    public function test_investment_withdrawal_preview_and_idempotency_do_not_become_income_or_expense(): void
    {
        $payload = $this->payload('1000') + ['counterparty' => '隔离老板'];
        $command = $this->command() + ['type' => 'capital_in', 'payload' => $payload];
        $preview = FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->count());
        self::assertCount(1, $preview['impacts']);
        $record = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($record, FinanceBusinessLogic::getError());
        self::assertSame($record['id'], FinanceBusinessLogic::action('record', $command)['id']);
        $source = $record['confirmed_result']['created_sources'][0];
        $this->record('capital_out', $this->payload('300') + ['counterparty' => '隔离老板', 'fund_source' => $source]);
        self::assertSame('700.00', (new FinanceLedger(self::FUNDS_TENANT))->source($source)['balance']);
        self::assertSame('700.00', (new FinanceLedger(self::FUNDS_TENANT))->account($this->account)['balance']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->whereIn('metric', ['revenue', 'expense'])->count());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command() + ['type' => 'capital_out', 'payload' => $this->payload('701') + ['counterparty' => '隔离老板', 'fund_source' => $source]]));
        self::assertSame('700.00', (new FinanceLedger(self::FUNDS_TENANT))->account($this->account)['balance']);
    }

    public function test_loan_principal_and_interest_are_separate_and_reported(): void
    {
        $loan = $this->record('loan_receipt', $this->payload('1000') + ['counterparty' => '隔离出借人', 'due_date' => date('Y-m-d')]);
        $source = $loan['confirmed_result']['created_sources'][0];
        $payment = $this->payload('420') + ['counterparty' => '隔离出借人', 'fund_source' => $source, 'principal_amount' => '400', 'interest_amount' => '20', 'interest_mode' => 'new_expense', 'interest_benefit_month' => date('Y-m'), 'interest_basis' => '本月约定利息已核实'];
        $this->record('loan_repayment', $payment);
        self::assertSame('600.00', (new FinanceLedger(self::FUNDS_TENANT))->source($source)['balance']);
        self::assertSame('580.00', (new FinanceLedger(self::FUNDS_TENANT))->account($this->account)['balance']);
        self::assertSame('20.00', bcadd((string)Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('metric', 'expense')->sum('amount'), '0', 2));
        $report = FinanceReports::monthly(new FinanceLedger(self::FUNDS_TENANT), ['report' => 'cash']);
        self::assertSame('1000.00', $report['data']['funding']['loan_receipt']);
        self::assertSame('420.00', $report['data']['funding']['loan_repayment']);
        self::assertContains('loan', array_column($report['data']['funding_balances']['categories'], 'category'));
        TodoQueryBudget::start(); $todos = TodoFinanceSourceProvider::query('S24', 10, null);
        self::assertSame($source, $todos['items'][0]['target']['params']['source']);
    }

    public function test_prepaid_allocation_and_refund_share_one_remaining_limit_without_second_payment(): void
    {
        $advance = $this->record('supplier_advance', $this->payload('500') + ['subject_id' => $this->vendor]);
        $source = $advance['confirmed_result']['created_sources'][0]; $ledger = new FinanceLedger(self::FUNDS_TENANT);
        $payable = $ledger->createSource(0, 'payable', $this->vendor, '300', date('Y-m-d'), null, ['subject_name' => '隔离预付款供应商']);
        $applied = $this->record('supplier_advance_allocate', ['subject_id' => $this->vendor, 'fund_source' => $source, 'actual_date' => date('Y-m-d'), 'allocations' => [['source' => $payable, 'amount' => '300']], 'reason' => '核实抵扣本次采购']);
        self::assertSame('0.00', $ledger->source($payable)['balance']); self::assertSame('200.00', $ledger->source($source)['balance']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('document_id', $applied['id'])->where('metric', 'cash')->count());
        $this->record('supplier_advance_refund', $this->payload('200') + ['subject_id' => $this->vendor, 'fund_source' => $source]);
        self::assertSame('0.00', $ledger->source($source)['balance']); self::assertSame('-300.00', $ledger->account($this->account)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command() + ['type' => 'supplier_advance_refund', 'payload' => $this->payload('1') + ['subject_id' => $this->vendor, 'fund_source' => $source]]));
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->whereIn('metric', ['revenue', 'expense', 'cost'])->count());
    }

    public function test_employee_and_other_store_cannot_confirm_funds_and_used_source_cannot_be_rewritten(): void
    {
        $original = $this->record('loan_receipt', $this->payload('100') + ['counterparty' => '隔离出借人']);
        $source = $original['confirmed_result']['created_sources'][0];
        $this->record('loan_repayment', $this->payload('40') + ['counterparty' => '隔离出借人', 'fund_source' => $source, 'principal_amount' => '40', 'interest_amount' => '0']);
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command((int)$original['version']) + ['id' => $original['id'], 'type' => 'loan_receipt', 'correction_reason' => '改已用于还本的来源', 'payload' => $this->payload('200') + ['counterparty' => '隔离出借人']]));
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::FUNDS_TENANT];
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'capital_in']));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command() + ['type' => 'capital_in', 'payload' => $this->payload('1') + ['counterparty' => '无权人']]));
        $this->prepareCustomerReportRequestContext(self::FUNDS_TENANT + 1);
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'loan_repayment', 'source' => $source]));
    }

    public function test_prepaid_use_matches_payable_effective_month_and_keeps_closed_snapshot(): void
    {
        $lastMonth = date('Y-m', strtotime('first day of last month')); $now = date('Y-m-d');
        Db::name('finance_preparation')->where('tenant_id', self::FUNDS_TENANT)->update(['activation_date' => $lastMonth . '-01']);
        $advance = $this->record('supplier_advance', array_replace($this->payload('500'), ['actual_date' => $lastMonth . '-02']) + ['subject_id' => $this->vendor]);
        $source = $advance['confirmed_result']['created_sources'][0]; $ledger = new FinanceLedger(self::FUNDS_TENANT);
        $payable = $ledger->createSource(0, 'payable', $this->vendor, '300', $now, null, ['subject_name' => '隔离预付款供应商']);
        $snapshot = '{"reports":{},"test":"immutable"}';
        Db::name('finance_period')->insert(['tenant_id' => self::FUNDS_TENANT, 'month' => $lastMonth, 'status' => 'closed', 'snapshot' => $snapshot, 'closed_by' => '{}', 'closed_at' => time()]);
        $record = $this->record('supplier_advance_allocate', ['subject_id' => $this->vendor, 'fund_source' => $source, 'actual_date' => $lastMonth . '-03', 'allocations' => [['source' => $payable, 'amount' => '300']], 'reason' => '跨月原来源核实抵扣']);
        $entries = Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('document_id', $record['id'])->order('id')->select()->toArray();
        self::assertCount(2, $entries);
        self::assertSame($entries[0]['posting_month'], $entries[1]['posting_month']); self::assertSame(substr($now, 0, 7), $entries[1]['posting_month']);
        self::assertSame($now, $entries[1]['effective_date']); self::assertSame($entries[0]['business_date'], $entries[1]['business_date']);
        self::assertSame($snapshot, Db::name('finance_period')->where('tenant_id', self::FUNDS_TENANT)->value('snapshot'));
    }

    public static function interestSources(): array { return [[false], [true]]; }

    public static function interestPeriods(): array { return [[false], [true]]; }

    /** @dataProvider interestPeriods */
    public function test_new_interest_uses_benefit_month_and_respects_closed_period(bool $closed): void
    {
        $lastMonth = date('Y-m', strtotime('first day of last month'));
        Db::name('finance_preparation')->where('tenant_id', self::FUNDS_TENANT)->update(['activation_date' => $lastMonth . '-01']);
        $loan = $this->record('loan_receipt', array_replace($this->payload('1000'), ['actual_date' => $lastMonth . '-02']) + ['counterparty' => '隔离出借人']);
        $snapshot = '{"reports":{},"test":"immutable-interest"}';
        if ($closed) { Db::name('finance_period')->insert(['tenant_id' => self::FUNDS_TENANT, 'month' => $lastMonth, 'status' => 'closed', 'snapshot' => $snapshot, 'closed_by' => '{}', 'closed_at' => time()]); }
        $paid = $this->record('loan_repayment', $this->payload('420') + ['counterparty' => '隔离出借人', 'fund_source' => $loan['confirmed_result']['created_sources'][0], 'principal_amount' => '400', 'interest_amount' => '20',
            'interest_mode' => 'new_expense', 'interest_benefit_month' => $lastMonth, 'interest_basis' => '上月已发生、此前未确认的真实利息']);
        $expense = Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('document_id', $paid['id'])->where('metric', 'expense')->find();
        self::assertSame($closed ? date('Y-m') : $lastMonth, $expense['posting_month']);
        self::assertSame(date('Y-m'), Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('document_id', $paid['id'])->where('metric', 'cash')->value('posting_month'));
        if ($closed) { self::assertSame($snapshot, Db::name('finance_period')->where('tenant_id', self::FUNDS_TENANT)->value('snapshot')); }
    }

    public function test_pre_activation_interest_cannot_be_recognized_as_new_expense(): void
    {
        $loan = $this->record('loan_receipt', $this->payload('1000') + ['counterparty' => '隔离出借人']);
        $fund = $loan['confirmed_result']['created_sources'][0]; $ledger = new FinanceLedger(self::FUNDS_TENANT);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command() + ['type' => 'loan_repayment', 'payload' => $this->payload('420') + ['counterparty' => '隔离出借人', 'fund_source' => $fund, 'principal_amount' => '400', 'interest_amount' => '20',
            'interest_mode' => 'new_expense', 'interest_benefit_month' => date('Y-m', strtotime('first day of last month')), 'interest_basis' => '启用前历史利息']]));
        self::assertStringContainsString('启用前利息', FinanceBusinessLogic::getError());
        self::assertSame('1000.00', $ledger->source($fund)['balance']); self::assertSame('1000.00', $ledger->account($this->account)['balance']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('metric', 'expense')->count());
    }

    /** @dataProvider interestSources */
    public function test_confirmed_or_opening_interest_is_paid_once_without_new_expense(bool $opening): void
    {
        $ledger = new FinanceLedger(self::FUNDS_TENANT); $date = date('Y-m-d');
        $loan = $this->record('loan_receipt', $this->payload('1000') + ['counterparty' => '隔离出借人']);
        $fund = $loan['confirmed_result']['created_sources'][0];
        if ($opening) {
            $id = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::FUNDS_TENANT, 'opening_item_id' => 1, 'activation_date' => date('Y-m-01'), 'category' => 'expense_payable', 'subject_id' => $this->vendor,
                'amount' => '25.00', 'source_snapshot' => json_encode(['subject_name' => '隔离出借人', 'historical_date' => null, 'reason' => '旧利息已核定未付']), 'create_time' => time()]);
            $source = 'o:' . $id;
        } else {
            $source = $ledger->createSource(0, 'expense_payable', $this->vendor, '25', $date, null, ['subject_name' => '隔离出借人']);
            $ledger->add(0, 'expense', $this->vendor, '25', $date, date('Y-m'), 'loan_interest', '', null, ['benefit_month' => date('Y-m')]);
        }
        $expenseBefore = (string)Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('metric', 'expense')->sum('amount');
        $payment = $this->payload('425') + ['counterparty' => '隔离出借人', 'fund_source' => $fund, 'principal_amount' => '400', 'interest_amount' => '25',
            'interest_mode' => 'existing_payable', 'interest_source' => $source, 'interest_subject_id' => $this->vendor, 'interest_payee_verified' => 1, 'interest_basis' => '核实为原借款已确认待付利息'];
        $paid = $this->record('loan_repayment', $payment);
        self::assertSame('600.00', $ledger->source($fund)['balance']); self::assertSame('0.00', $ledger->source($source)['balance']);
        self::assertSame($expenseBefore, (string)Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('metric', 'expense')->sum('amount'));
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('document_id', $paid['id'])->where('metric', 'expense')->count());
    }

    public function test_supplier_statement_freezes_prepaid_sources_and_money_before_later_allocation(): void
    {
        $advance = $this->record('supplier_advance', $this->payload('500') + ['subject_id' => $this->vendor]);
        $source = $advance['confirmed_result']['created_sources'][0]; $ledger = new FinanceLedger(self::FUNDS_TENANT);
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command() + ['subject_kind' => 'vendor', 'vendor_id' => $this->vendor, 'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')]);
        self::assertSame('500.00', $statement['snapshot']['balances']['supplier_advance']['closing']);
        self::assertSame('supplier_advance', $statement['snapshot']['actual_money'][0]['type']);
        $this->record('supplier_advance_refund', $this->payload('200') + ['subject_id' => $this->vendor, 'fund_source' => $source]);
        $historical = \app\api\jxc\logic\FinanceStatements::detail(['subject_kind' => 'vendor', 'id' => $statement['id']]);
        self::assertSame($statement['snapshot'], $historical['snapshot']);
        self::assertSame('300.00', $ledger->source($source)['balance']);
    }

    public function test_capital_earnings_withdrawal_does_not_consume_investment_or_create_expense(): void
    {
        $investment = $this->record('capital_in', $this->payload('1000') + ['counterparty' => '隔离老板']);
        $source = $investment['confirmed_result']['created_sources'][0];
        $this->record('capital_out', $this->payload('100') + ['counterparty' => '隔离老板', 'capital_return_amount' => '0', 'earnings_amount' => '100']);
        self::assertSame('1000.00', (new FinanceLedger(self::FUNDS_TENANT))->source($source)['balance']);
        self::assertSame('900.00', (new FinanceLedger(self::FUNDS_TENANT))->account($this->account)['balance']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::FUNDS_TENANT)->where('metric', 'expense')->count());
    }

    private function payload(string $amount): array { return ['account_id' => $this->account, 'actual_date' => date('Y-m-d'), 'amount' => $amount, 'reason' => '隔离业务事实']; }
    private function command(int $version = 0): array { return ['expected_tenant_id' => self::FUNDS_TENANT, 'expected_version' => $version, 'idempotency_key' => 'funds-workflow-' . ++$this->sequence]; }
    private function record(string $type, array $payload): array { $result = FinanceBusinessLogic::action('record', $this->command() + ['type' => $type, 'payload' => $payload]); self::assertNotFalse($result, FinanceBusinessLogic::getError()); return $result; }
    private function cleanFunds(): void
    {
        foreach (['finance_transaction_identity', 'finance_entry', 'finance_source', 'finance_command', 'finance_document', 'finance_money_transaction', 'finance_period', 'finance_opening_source', 'finance_opening_item_detail', 'finance_opening_item', 'finance_opening_book', 'finance_setup_action', 'finance_account', 'finance_preparation', 'vendor'] as $table) {
            Db::name($table)->where('tenant_id', self::FUNDS_TENANT)->delete();
        }
    }
}
