<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceBusinessLogic;
use app\api\jxc\logic\FinanceLedger;
use app\api\jxc\logic\FinanceSetupLogic;
use app\api\jxc\logic\WorkforceLogic;
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
        foreach (['20260907_000001_finance_preparation.sql', '20260907_000002_finance_opening.sql', '20260907_000003_finance_opening_details.sql', '20260907_000004_finance_business.sql'] as $file) {
            $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $file)));
        }
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
        foreach (Db::name('finance_evidence')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->select()->toArray() as $proof) {
            $snapshot = json_decode($proof['snapshot'], true); $key = $snapshot['storage_key'] ?? '';
            if (preg_match('/^[a-f0-9]{48}\.(png|jpg|webp)$/D', $key)) {
                $file = root_path() . 'storage/finance-evidence/' . (int)$proof['tenant_id'] . '/' . $key;
                if (is_file($file)) { unlink($file); }
            }
        }
        foreach (['finance_transaction_identity', 'finance_correction', 'finance_evidence', 'finance_period', 'finance_money_transaction', 'finance_entry', 'finance_source', 'finance_command', 'finance_document',
            'finance_opening_item_detail', 'finance_opening_source', 'finance_opening_item', 'finance_opening_book', 'finance_setup_action', 'finance_account', 'finance_preparation'] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        }
        $this->cleanCustomerReportData();
    }
}
