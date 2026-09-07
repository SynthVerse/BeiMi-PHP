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
        $this->clean();
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext(); $this->clean();
        Config::set(['activation_tenant_ids' => []], 'finance');
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

    private function deliveredSale(): array
    {
        $unit = $this->createCustomerReportUnit('斤');
        $warehouse = $this->createCustomerReportWarehouse('已交付核算仓');
        $goods = $this->createCustomerReportGoods('已交付商品', 'FINANCE-SALE', '斤');
        $sku = $this->customerReportSkuId($goods); $now = time();
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
        return ['order_id' => $order, 'line_id' => $line, 'unit_id' => $unit, 'event_id' => $event];
    }

    private function activate(string $accountType = 'cash'): void
    {
        $account = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '经营账户', 'account_type' => $accountType]);
        self::assertNotFalse($account); $this->accountId = (int)$account['id'];
        $this->customerId = $this->createCustomer('收款主客户');
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => date('Y-m-01'),
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
        foreach (['finance_statement_resolution', 'finance_statement_dispute', 'finance_statement_event', 'finance_statement'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
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
