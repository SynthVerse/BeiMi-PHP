<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportBatchLogic;
use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\logic\GoodsDimensionLogic;
use app\api\jxc\logic\SalesOrderLogic;
use app\api\jxc\logic\WorkforceLogic;
use tests\unit\WarehouseSkuBalanceForGoodsTestAdapter as WarehouseGoodsBalanceService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class FulfillmentWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        $this->createCustomerReportUnit('件');
    }

    protected function tearDown(): void
    {
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_report_submission_generates_idempotent_shortage_and_process_tasks(): void
    {
        $customerId = $this->createCustomer('海鲜酒楼');
        $goodsId = $this->createCustomerReportGoods('桂花鱼', 'TASK-GUIYU');
        $warehouseId = $this->createCustomerReportWarehouse('鲜活仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '1.00'));

        $batch = CustomerReportBatchLogic::start(['delivery_date' => '2026-08-10', 'idempotency_key' => 'task-batch-1']);
        self::assertNotFalse($batch, CustomerReportBatchLogic::getError());
        $primer = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-batch-primer-1', '1', '');
        $primer['batch_id'] = (int)$batch['id'];
        $primerReport = CustomerReportLogic::submit($primer);
        self::assertNotFalse($primerReport, CustomerReportLogic::getError());
        $processing = CustomerReportBatchLogic::process(['id' => $batch['id'], 'version' => $batch['version']]);
        self::assertNotFalse($processing, CustomerReportBatchLogic::getError());
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-idem-1', '2', '杀好、活鱼打包');
        $payload['batch_id'] = (int)$processing['id'];
        $payload['is_supplement'] = 1;
        $payload['supplement_for_report_id'] = (int)$primerReport['id'];
        $first = CustomerReportLogic::submit($payload);
        self::assertNotFalse($first, CustomerReportLogic::getError());
        self::assertSame('2026-08-10', $first['delivery_date']);
        self::assertSame(1, (int)$first['is_supplement']);

        $tasks = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$first['id'])->order('id')->select()->toArray();
        self::assertCount(5, $tasks, '采购、杀鱼、活鱼打包、送货、记账各一项');
        self::assertSame(1, count(array_filter($tasks, static fn(array $task): bool => $task['source_key'] === 'item:' . $first['items'][0]['id'] . ':shortage')));
        self::assertSame(
            2,
            count(array_filter(
                $tasks,
                static fn(array $task): bool => (int)$task['report_item_id'] === (int)$first['items'][0]['id']
                    && str_contains((string)$task['source_key'], ':process:')
            )),
            json_encode($tasks, JSON_UNESCAPED_UNICODE)
        );

        $again = CustomerReportLogic::submit($payload);
        self::assertNotFalse($again, CustomerReportLogic::getError());
        self::assertSame(5, Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)->where('report_id', (int)$first['id'])->count());
    }

    public function test_report_batch_enforces_processing_supplement_date_tenant_and_manual_end_boundaries(): void
    {
        $customerId = $this->createCustomer('批次边界客户');
        $goodsId = $this->createCustomerReportGoods('批次桂鱼', 'TASK-BATCH');
        $warehouseId = $this->createCustomerReportWarehouse('批次仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '10.00'));

        $batchRequest = ['delivery_date' => '2026-08-10', 'idempotency_key' => 'batch-boundary-1'];
        $batch = CustomerReportBatchLogic::start($batchRequest);
        self::assertNotFalse($batch, CustomerReportBatchLogic::getError());
        self::assertSame('open', $batch['status']);
        self::assertSame((int)$batch['id'], (int)CustomerReportBatchLogic::start($batchRequest)['id']);
        $differentBatchReplay = $batchRequest;
        $differentBatchReplay['delivery_date'] = '2026-08-11';
        self::assertFalse(CustomerReportBatchLogic::start($differentBatchReplay));
        self::assertSame('幂等键已用于不同的报货批次', CustomerReportBatchLogic::getError());
        self::assertFalse(CustomerReportBatchLogic::end(['id' => $batch['id'], 'version' => $batch['version']]));
        self::assertSame('只有处理中的报货批次可以结束', CustomerReportBatchLogic::getError());

        $normal = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'batch-normal-1', '1', '');
        $normal['batch_id'] = (int)$batch['id'];
        $normalReport = CustomerReportLogic::submit($normal);
        self::assertNotFalse($normalReport, CustomerReportLogic::getError());
        self::assertSame(0, (int)$normalReport['is_supplement']);
        self::assertSame((int)$batch['id'], (int)$normalReport['batch_id']);

        $processRequest = ['id' => $batch['id'], 'version' => $batch['version']];
        $processing = CustomerReportBatchLogic::process($processRequest);
        self::assertNotFalse($processing, CustomerReportBatchLogic::getError());
        self::assertSame('processing', $processing['status']);
        self::assertSame((int)$processing['version'], (int)CustomerReportBatchLogic::process($processRequest)['version']);

        $ordinaryAfterProcessing = $normal;
        $ordinaryAfterProcessing['idempotency_key'] = 'batch-normal-too-late';
        self::assertFalse(CustomerReportLogic::submit($ordinaryAfterProcessing));
        self::assertSame('报货批次已开始处理，新增需求必须作为补报提交', CustomerReportLogic::getError());

        $supplement = $normal;
        $supplement['idempotency_key'] = 'batch-supplement-1';
        $supplement['is_supplement'] = 1;
        $supplement['supplement_for_report_id'] = (int)$normalReport['id'];
        $supplementReport = CustomerReportLogic::submit($supplement);
        self::assertNotFalse($supplementReport, CustomerReportLogic::getError());
        self::assertSame(1, (int)$supplementReport['is_supplement']);
        self::assertSame((int)$batch['id'], (int)$supplementReport['batch_id']);
        self::assertSame((int)$normalReport['id'], (int)$supplementReport['supplement_for_report_id']);
        self::assertSame(1, Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'customer_report')->where('action', 'supplement')
            ->where('target_id', (int)$supplementReport['id'])->count());
        self::assertSame((int)$supplementReport['id'], (int)CustomerReportLogic::submit($supplement)['id']);

        $identityEdit = $supplement;
        unset($identityEdit['idempotency_key']);
        $identityEdit['id'] = (int)$supplementReport['id'];
        $identityEdit['version'] = (int)$supplementReport['version'];
        $identityEdit['is_supplement'] = 0;
        $identityEdit['delivery_date'] = '2026-08-11';
        $identityEdit['items'][0]['client_line_id'] = (int)$supplementReport['items'][0]['id'];
        $editedSupplement = CustomerReportLogic::edit($identityEdit);
        self::assertNotFalse($editedSupplement, CustomerReportLogic::getError());
        self::assertSame(1, (int)$editedSupplement['is_supplement']);
        self::assertSame('2026-08-10', $editedSupplement['delivery_date']);
        self::assertSame((int)$batch['id'], (int)$editedSupplement['batch_id']);

        $otherBatch = CustomerReportBatchLogic::start([
            'delivery_date' => '2026-08-10', 'idempotency_key' => 'batch-boundary-other',
        ]);
        self::assertNotFalse($otherBatch, CustomerReportBatchLogic::getError());
        $otherNormal = $normal;
        $otherNormal['batch_id'] = (int)$otherBatch['id'];
        $otherNormal['idempotency_key'] = 'batch-normal-other';
        self::assertNotFalse(CustomerReportLogic::submit($otherNormal), CustomerReportLogic::getError());
        $otherProcessing = CustomerReportBatchLogic::process([
            'id' => $otherBatch['id'], 'version' => $otherBatch['version'],
        ]);
        self::assertNotFalse($otherProcessing, CustomerReportBatchLogic::getError());
        $wrongOriginalBatch = $supplement;
        $wrongOriginalBatch['batch_id'] = (int)$otherProcessing['id'];
        $wrongOriginalBatch['idempotency_key'] = 'batch-supplement-wrong-original-batch';
        self::assertFalse(CustomerReportLogic::submit($wrongOriginalBatch));
        self::assertSame('补报必须进入原报货单所属批次', CustomerReportLogic::getError());

        $otherCustomerId = $this->createCustomer('批次另一收货客户');
        $wrongCustomer = $supplement;
        $wrongCustomer['idempotency_key'] = 'batch-supplement-wrong-customer';
        $wrongCustomer['main_customer_id'] = $otherCustomerId;
        $wrongCustomer['items'][0]['delivery_customer_id'] = $otherCustomerId;
        self::assertFalse(CustomerReportLogic::submit($wrongCustomer));
        self::assertSame('补报必须沿用原报货单的主客户和实际收货客户', CustomerReportLogic::getError());

        $wrongDate = $supplement;
        $wrongDate['idempotency_key'] = 'batch-supplement-wrong-date';
        $wrongDate['delivery_date'] = '2026-08-11';
        self::assertFalse(CustomerReportLogic::submit($wrongDate));
        self::assertSame('报货单送货日期必须与报货批次一致', CustomerReportLogic::getError());

        $missingBatch = $supplement;
        $missingBatch['idempotency_key'] = 'batch-supplement-missing-batch';
        unset($missingBatch['batch_id']);
        self::assertFalse(CustomerReportLogic::submit($missingBatch));
        self::assertSame('补报必须进入原报货批次', CustomerReportLogic::getError());

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(CustomerReportBatchLogic::detail(['id' => (int)$batch['id']]));
        self::assertFalse(CustomerReportBatchLogic::process([
            'id' => (int)$batch['id'], 'version' => (int)$processing['version'],
        ]));
        $guessedBatch = $supplement;
        $guessedBatch['idempotency_key'] = 'batch-supplement-cross-tenant';
        self::assertFalse(CustomerReportLogic::submit($guessedBatch));
        self::assertSame('主客户不存在或已停用', CustomerReportLogic::getError());
        $this->prepareCustomerReportRequestContext();

        $endRequest = ['id' => $processing['id'], 'version' => $processing['version']];
        $ended = CustomerReportBatchLogic::end($endRequest);
        self::assertNotFalse($ended, CustomerReportBatchLogic::getError());
        self::assertSame('ended', $ended['status']);
        self::assertSame((int)$ended['version'], (int)CustomerReportBatchLogic::end($endRequest)['version']);

        $afterEnd = $supplement;
        $afterEnd['idempotency_key'] = 'batch-supplement-after-end';
        self::assertFalse(CustomerReportLogic::submit($afterEnd));
        self::assertSame('报货批次已结束，不能继续报货', CustomerReportLogic::getError());
    }

    public function test_customer_report_batch_migration_is_safe_to_replay(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = $this->prepareMigration((string)file_get_contents(
            $root . '/database/migrations/20260818_000001_customer_report_batch_and_cancellation.sql'
        ));

        $this->runStatements($migration);
        $this->runStatements($migration);

        self::assertSame(1, Db::query("SHOW COLUMNS FROM `la_customer_report` LIKE 'batch_id'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW COLUMNS FROM `la_customer_report` LIKE 'supplement_for_report_id'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW COLUMNS FROM `la_customer_report` LIKE 'cancellation_reason'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW INDEX FROM `la_customer_report` WHERE Key_name = 'idx_tenant_customer_report_batch'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW INDEX FROM `la_customer_report` WHERE Key_name = 'idx_tenant_customer_report_supplement_source'") !== [] ? 1 : 0);
    }

    public function test_blank_or_unrecognized_requirement_becomes_explicit_exception(): void
    {
        $customerId = $this->createCustomer('码头饭店');
        $goodsId = $this->createCustomerReportGoods('鲍鱼', 'TASK-ABALONE');
        $warehouseId = $this->createCustomerReportWarehouse('冰鲜仓');

        $blank = CustomerReportLogic::submit($this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-blank', '1', ''));
        self::assertNotFalse($blank, CustomerReportLogic::getError());
        self::assertSame('missing_remark', Db::name('fulfillment_task')->where('report_id', (int)$blank['id'])->where('task_type', 'exception')->value('exception_code'));

        $unknown = CustomerReportLogic::submit($this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-unknown', '1', '按老板习惯处理'));
        self::assertNotFalse($unknown, CustomerReportLogic::getError());
        self::assertSame('unrecognized_remark', Db::name('fulfillment_task')->where('report_id', (int)$unknown['id'])->where('task_type', 'exception')->value('exception_code'));
    }

    public function test_employee_capability_and_electronic_permissions_are_only_saved_when_checked(): void
    {
        $processes = WorkforceLogic::processes([])['lists'];
        $killFish = current(array_filter($processes, static fn(array $process): bool => $process['code'] === 'kill_fish'));
        self::assertIsArray($killFish);

        $employee = WorkforceLogic::saveEmployee([
            'name' => '阿强',
            'mobile' => '13800000001',
            'bind_user_id' => 0,
            'is_enabled' => 1,
            'process_ids' => [(int)$killFish['id']],
            'permission_keys' => ['task.view', 'task.recover'],
        ]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        self::assertSame([(int)$killFish['id']], $employee['process_ids']);
        self::assertSame(['task.recover', 'task.view'], $employee['permission_keys']);
        self::assertNotContains('task.assign', $employee['permission_keys']);
        self::assertNotContains('employee.manage', $employee['permission_keys']);
    }

    public function test_repeated_initial_process_upsert_preserves_operator_configuration(): void
    {
        WorkforceLogic::ensureInitialProcesses();
        $killFish = Db::name('work_process')->where('tenant_id', self::TENANT_ID)->where('code', 'kill_fish')->find();
        $purchase = Db::name('work_process')->where('tenant_id', self::TENANT_ID)->where('code', 'purchase')->find();
        self::assertIsArray($killFish);
        self::assertIsArray($purchase);

        Db::name('work_process')->where('id', (int)$killFish['id'])->update([
            'name' => '水产精加工',
            'trigger_keywords' => '["精加工"]',
            'sort' => 88,
            'is_enabled' => 0,
        ]);
        Db::name('work_process')->where('id', (int)$purchase['id'])->update([
            'name' => '紧急采购',
            'trigger_keywords' => '["紧急"]',
            'sort' => 99,
            'trigger_type' => 'remark',
            'is_enabled' => 0,
        ]);

        WorkforceLogic::ensureInitialProcesses();

        $killFish = Db::name('work_process')->where('id', (int)$killFish['id'])->find();
        self::assertSame('水产精加工', $killFish['name']);
        self::assertSame('["精加工"]', $killFish['trigger_keywords']);
        self::assertSame(88, (int)$killFish['sort']);
        self::assertSame(0, (int)$killFish['is_enabled']);

        $purchase = Db::name('work_process')->where('id', (int)$purchase['id'])->find();
        self::assertSame('紧急采购', $purchase['name']);
        self::assertSame('["紧急"]', $purchase['trigger_keywords']);
        self::assertSame(99, (int)$purchase['sort']);
        self::assertSame('shortage', $purchase['trigger_type']);
        self::assertSame(1, (int)$purchase['is_enabled']);
    }

    public function test_assignment_print_failure_retry_and_ticket_recovery_follow_state_machine(): void
    {
        $processes = WorkforceLogic::processes([])['lists'];
        $killFish = current(array_filter($processes, static fn(array $process): bool => $process['code'] === 'kill_fish'));
        $packing = current(array_filter($processes, static fn(array $process): bool => $process['code'] === 'live_pack'));
        $employee = WorkforceLogic::saveEmployee([
            'name' => '阿强', 'mobile' => '13800000002', 'bind_user_id' => 0, 'is_enabled' => 1,
            'process_ids' => [(int)$killFish['id']], 'permission_keys' => [],
        ]);
        self::assertNotFalse($employee, WorkforceLogic::getError());

        $taskId = (int)Db::name('fulfillment_task')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'group_id' => 1, 'report_id' => 1, 'report_item_id' => 1,
            'process_id' => (int)$packing['id'], 'task_type' => 'process', 'source_key' => 'test:assignment',
            'status' => 'unassigned', 'is_settlement_task' => 1, 'ticket_no' => 'WT-TEST', 'create_time' => time(), 'update_time' => time(),
        ]);
        self::assertFalse(FulfillmentTaskLogic::assign(['id' => $taskId, 'employee_id' => (int)$employee['id']]));

        Db::name('fulfillment_task')->where('id', $taskId)->update(['process_id' => (int)$killFish['id']]);
        $assigned = FulfillmentTaskLogic::assign(['id' => $taskId, 'employee_id' => (int)$employee['id']]);
        self::assertNotFalse($assigned, FulfillmentTaskLogic::getError());
        self::assertSame('printable', $assigned['status']);

        $print = FulfillmentTaskLogic::printData(['id' => $taskId]);
        self::assertNotFalse($print, FulfillmentTaskLogic::getError());
        self::assertSame('XP-N160II', $print['printer']['name']);
        self::assertNotFalse(FulfillmentTaskLogic::printResult(['id' => $taskId, 'print_log_id' => $print['print_log_id'], 'success' => 0, 'error_message' => '连接中断']));
        self::assertSame('print_failed', Db::name('fulfillment_task')->where('id', $taskId)->value('status'));
        self::assertNotFalse(FulfillmentTaskLogic::printResult(['id' => $taskId, 'print_log_id' => $print['print_log_id'], 'success' => 0, 'error_message' => '连接中断']));
        self::assertSame(1, (int)Db::name('fulfillment_task')->where('id', $taskId)->value('print_count'), '同一打印回执重放不能重复计数');

        $retry = FulfillmentTaskLogic::printData(['id' => $taskId]);
        self::assertNotFalse($retry, FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::printResult(['id' => $taskId, 'print_log_id' => $retry['print_log_id'], 'success' => 1, 'error_message' => '']));
        self::assertSame('printed', Db::name('fulfillment_task')->where('id', $taskId)->value('status'));

        $recovered = FulfillmentTaskLogic::recover(['id' => $taskId, 'actual_weight' => '3.25', 'actual_price' => '28.00', 'recovery_note' => '纸票已回收']);
        self::assertNotFalse($recovered, FulfillmentTaskLogic::getError());
        self::assertSame('recovered', $recovered['status']);
        self::assertSame('3.25', (string)$recovered['actual_weight']);
    }

    public function test_cancelled_report_idempotency_replay_does_not_revive_tasks(): void
    {
        $customerId = $this->createCustomer('取消回放客户');
        $goodsId = $this->createCustomerReportGoods('海鲈鱼', 'TASK-CANCEL');
        $warehouseId = $this->createCustomerReportWarehouse('取消回放仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-cancel-replay', '1', '杀好');
        $submitted = CustomerReportLogic::submit($payload);
        self::assertNotFalse($submitted, CustomerReportLogic::getError());
        self::assertFalse(CustomerReportLogic::cancel(['id' => $submitted['id'], 'version' => $submitted['version']]));
        self::assertSame('取消报货单必须填写原因', CustomerReportLogic::getError());
        $cancelRequest = ['id' => $submitted['id'], 'version' => $submitted['version'], 'reason' => '客户临时取消'];
        $cancelled = CustomerReportLogic::cancel($cancelRequest);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        self::assertSame('客户临时取消', $cancelled['cancellation_reason']);
        self::assertSame(self::ADMIN_ID, (int)$cancelled['cancelled_by']);
        self::assertGreaterThan(0, (int)$cancelled['cancelled_time']);
        $audit = Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'customer_report')->where('action', 'cancel')->where('target_id', (int)$submitted['id'])->find();
        self::assertNotEmpty($audit);
        self::assertSame(self::ADMIN_ID, (int)$audit['admin_id']);
        self::assertSame('客户临时取消', $audit['remark']);
        self::assertSame('客户临时取消', json_decode((string)$audit['after_data'], true)['cancellation_reason']);
        $replayedCancel = CustomerReportLogic::cancel($cancelRequest);
        self::assertNotFalse($replayedCancel, CustomerReportLogic::getError());
        self::assertSame((int)$cancelled['version'], (int)$replayedCancel['version']);
        self::assertSame(1, Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'customer_report')->where('action', 'cancel')->where('target_id', (int)$submitted['id'])->count());
        $conflictingReplay = $cancelRequest;
        $conflictingReplay['reason'] = '改写取消原因';
        self::assertFalse(CustomerReportLogic::cancel($conflictingReplay));
        self::assertSame('报货单已取消，取消请求与已保存事实不一致', CustomerReportLogic::getError());
        $replayed = CustomerReportLogic::submit($payload);
        self::assertNotFalse($replayed, CustomerReportLogic::getError());
        self::assertSame('cancelled', $replayed['status']);
        self::assertSame(0, Db::name('fulfillment_task')->where('report_id', (int)$submitted['id'])->whereNotIn('status', ['cancelled', 'recovered', 'ready_to_bill', 'completed'])->count());
    }

    public function test_report_cannot_be_cancelled_after_any_paper_work_has_started(): void
    {
        $customerId = $this->createCustomer('已开工客户');
        $goodsId = $this->createCustomerReportGoods('已开工海鲈鱼', 'TASK-STARTED-CANCEL');
        $warehouseId = $this->createCustomerReportWarehouse('已开工仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $submitted = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-started-cancel', '1', '杀好'
        ));
        self::assertNotFalse($submitted, CustomerReportLogic::getError());
        $taskId = (int)Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$submitted['id'])->order('id')->value('id');
        Db::name('fulfillment_print_log')->insert([
            'tenant_id' => self::TENANT_ID, 'task_id' => $taskId, 'operator_id' => self::ADMIN_ID,
            'status' => 'pending', 'create_time' => time(), 'update_time' => time(),
        ]);

        self::assertFalse(CustomerReportLogic::cancel([
            'id' => $submitted['id'], 'version' => $submitted['version'], 'reason' => '纸票已经开始处理',
        ]));
        self::assertSame('报货单已经开始纸票或履约作业，不能取消', CustomerReportLogic::getError());
        self::assertSame('submitted_ready', Db::name('customer_report')->where('id', (int)$submitted['id'])->value('status'));
        self::assertSame('1.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        self::assertSame(0, Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'customer_report')->where('action', 'cancel')->where('target_id', (int)$submitted['id'])->count());
    }

    public function test_resolved_exception_uses_stable_process_source_key_after_sync(): void
    {
        $customerId = $this->createCustomer('人工确认客户');
        $goodsId = $this->createCustomerReportGoods('黄花鱼', 'TASK-RESOLVE');
        $warehouseId = $this->createCustomerReportWarehouse('人工确认仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '1.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-resolve-stable', '1', '按客户习惯处理'));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $exception = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->where('task_type', 'exception')->find();
        $purchase = Db::name('work_process')->where('tenant_id', self::TENANT_ID)->where('code', 'purchase')->find();
        self::assertFalse(FulfillmentTaskLogic::resolveException(['id' => $exception['id'], 'process_id' => $purchase['id'], 'requirement' => '错误选择采购']));
        $process = Db::name('work_process')->where('tenant_id', self::TENANT_ID)->where('code', 'kill_fish')->find();
        $resolved = FulfillmentTaskLogic::resolveException(['id' => $exception['id'], 'process_id' => $process['id'], 'requirement' => '人工确认杀鱼']);
        self::assertNotFalse($resolved, FulfillmentTaskLogic::getError());
        self::assertSame('item:' . $exception['report_item_id'] . ':process:kill_fish', $resolved['source_key']);
        FulfillmentTaskLogic::syncForReport((int)$report['id']);
        self::assertSame(1, Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->where('source_key', $resolved['source_key'])->count());
    }

    public function test_only_the_final_item_process_collects_settlement_values(): void
    {
        $customerId = $this->createCustomer('多工序结算客户');
        $goodsId = $this->createCustomerReportGoods('石斑鱼', 'TASK-SETTLEMENT-OWNER');
        $warehouseId = $this->createCustomerReportWarehouse('多工序仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-settlement-owner', '1', '杀好、活鱼打包'));
        self::assertNotFalse($report, CustomerReportLogic::getError());

        $tasks = Db::name('fulfillment_task')->alias('t')
            ->join('work_process p', 'p.id=t.process_id AND p.tenant_id=t.tenant_id')
            ->where('t.report_id', (int)$report['id'])->where('t.report_item_id', (int)$report['items'][0]['id'])
            ->where('t.task_type', 'process')->order(['p.sort' => 'asc', 'p.id' => 'asc'])->field('t.*')->select()->toArray();
        self::assertCount(2, $tasks);
        self::assertFalse((bool)FulfillmentTaskLogic::detail(['id' => (int)$tasks[0]['id']])['requires_settlement']);
        self::assertTrue((bool)FulfillmentTaskLogic::detail(['id' => (int)$tasks[1]['id']])['requires_settlement']);

        Db::name('fulfillment_task')->whereIn('id', array_column($tasks, 'id'))->update(['status' => 'printed']);
        self::assertNotFalse(FulfillmentTaskLogic::recover(['id' => (int)$tasks[0]['id']]));
        self::assertFalse(FulfillmentTaskLogic::recover(['id' => (int)$tasks[1]['id'], 'actual_weight' => '1.00', 'actual_price' => '0']));
        self::assertNotFalse(FulfillmentTaskLogic::recover(['id' => (int)$tasks[1]['id'], 'actual_weight' => '1.00', 'actual_price' => '28.00']));
        $values = FulfillmentTaskLogic::settlementValuesForReport((int)$report['id']);
        self::assertSame((int)$tasks[1]['id'], $values[(int)$report['items'][0]['id']]['task_id']);
        $processIds = array_map('intval', array_column(WorkforceLogic::processes([])['lists'], 'id'));
        self::assertNotFalse(WorkforceLogic::reorderProcesses(['ids' => array_reverse($processIds)]), WorkforceLogic::getError());
        $valuesAfterReorder = FulfillmentTaskLogic::settlementValuesForReport((int)$report['id']);
        self::assertSame((int)$tasks[1]['id'], $valuesAfterReorder[(int)$report['items'][0]['id']]['task_id']);
    }

    public function test_customer_report_convert_requires_ready_bookkeeping_and_defaults_to_base_pricing_unit(): void
    {
        $customerId = $this->createCustomer('记账门禁客户');
        $goodsId = $this->createCustomerReportGoods('东星斑', 'TASK-BILL-GATE');
        $warehouseId = $this->createCustomerReportWarehouse('记账门禁仓');
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $dimensionConfig = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['name' => '大连', 'code' => 'dalian']],
            ]],
        ]);
        $sku = $dimensionConfig['skus'][0];
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-bill-gate', '1', '杀好');
        $payload['items'][0]['sku_id'] = (int)$sku['id'];
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        self::assertFalse(CustomerReportLogic::convert(['id' => $report['id'], 'version' => $report['version']]));

        $processTask = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->where('report_item_id', '>', 0)->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$processTask['id'])->update([
            'status' => 'recovered', 'actual_weight' => '1.00', 'actual_price' => '28.00', 'recovered_time' => time(),
        ]);
        $delivery = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->whereLike('source_key', '%:delivery')->find();
        $bookkeeping = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->whereLike('source_key', '%:bookkeeping')->find();
        Db::name('fulfillment_task')->where('id', (int)$delivery['id'])->update(['status' => 'recovered']);
        Db::name('fulfillment_task')->where('id', (int)$bookkeeping['id'])->update(['status' => 'ready_to_bill']);

        $billed = FulfillmentTaskLogic::bill(['id' => (int)$bookkeeping['id']]);
        self::assertNotFalse($billed, FulfillmentTaskLogic::getError());
        $line = Db::name('customer_report_item')->where('id', (int)$report['items'][0]['id'])->find();
        self::assertSame((int)$line['base_unit_id'], (int)$line['pricing_unit_id']);
        self::assertSame((string)$line['base_unit_name'], (string)$line['pricing_unit_name']);
        self::assertSame('28.00', (string)$line['price']);
        $orderGoods = Db::name('order_goods')->where('source_line_type', 'customer_report_item')
            ->where('source_line_id', (int)$line['id'])->find();
        self::assertNotEmpty($orderGoods);
        self::assertSame((int)$sku['id'], (int)$orderGoods['sku_id']);
        self::assertSame((string)$sku['sku_name'], (string)$orderGoods['sku_name']);
        $salesDetail = SalesOrderLogic::detail(['id' => (int)$orderGoods['order_id']]);
        self::assertSame((string)$sku['sku_name'], (string)$salesDetail['goods'][0]['sku_name']);
    }

    public function test_report_edit_cannot_change_settlement_owner_after_any_ticket_started(): void
    {
        $customerId = $this->createCustomer('执行后编辑客户');
        $goodsId = $this->createCustomerReportGoods('龙虎斑', 'TASK-EDIT-AFTER-START');
        $warehouseId = $this->createCustomerReportWarehouse('执行后编辑仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-edit-after-start', '1', '杀好、活鱼打包'));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $nonOwner = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->where('report_item_id', (int)$report['items'][0]['id'])
            ->where('task_type', 'process')->where('is_settlement_task', 0)->find();
        Db::name('fulfillment_task')->where('id', (int)$nonOwner['id'])->update(['status' => 'recovered']);

        $edit = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'unused-edit-key', '1', '杀好');
        unset($edit['idempotency_key']);
        $edit['id'] = (int)$report['id'];
        $edit['version'] = (int)$report['version'];
        $edit['items'][0]['client_line_id'] = (int)$report['items'][0]['id'];
        self::assertFalse(CustomerReportLogic::edit($edit));
        self::assertSame('已有工票进入执行或回收，不能再编辑报货内容', CustomerReportLogic::getError());
        self::assertSame(1, Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->where('is_settlement_task', 1)->where('status', '<>', 'cancelled')->count());
    }

    public function test_report_edit_is_frozen_while_print_receipt_is_pending(): void
    {
        $customerId = $this->createCustomer('打印中编辑门禁客户');
        $goodsId = $this->createCustomerReportGoods('珍珠斑', 'TASK-EDIT-PENDING-PRINT');
        $warehouseId = $this->createCustomerReportWarehouse('打印中编辑门禁仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-edit-pending-print', '1', '杀好'));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $task = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$task['id'])->update(['status' => 'printable']);
        $print = FulfillmentTaskLogic::printData(['id' => (int)$task['id']]);
        self::assertNotFalse($print, FulfillmentTaskLogic::getError());

        $edit = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'unused-pending-print-edit-key', '1', '杀好');
        unset($edit['idempotency_key']);
        $edit['id'] = (int)$report['id'];
        $edit['version'] = (int)$report['version'];
        $edit['items'][0]['client_line_id'] = (int)$report['items'][0]['id'];
        self::assertFalse(CustomerReportLogic::edit($edit));
        self::assertSame('已有工票进入执行或回收，不能再编辑报货内容', CustomerReportLogic::getError());
        self::assertSame('pending', Db::name('fulfillment_print_log')->where('id', (int)$print['print_log_id'])->value('status'));
    }

    public function test_unresolved_exception_blocks_delivery_unlock(): void
    {
        $customerId = $this->createCustomer('异常门禁客户');
        $goodsId = $this->createCustomerReportGoods('海鲈鱼', 'TASK-EXCEPTION-GATE-A');
        $otherGoodsId = $this->createCustomerReportGoods('金鲳鱼', 'TASK-EXCEPTION-GATE-B');
        $warehouseId = $this->createCustomerReportWarehouse('异常门禁仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '1.00'));
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $otherGoodsId, '1.00'));
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-exception-gate', '1', '杀好');
        $payload['items'][] = array_merge($payload['items'][0], [
            'goods_id' => $otherGoodsId,
            'processing_requirement' => '按老板习惯处理',
            'processing' => '按老板习惯处理',
            'line_remark' => '按老板习惯处理',
        ]);
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $processTask = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$processTask['id'])->update(['status' => 'printed']);
        self::assertNotFalse(FulfillmentTaskLogic::recover(['id' => (int)$processTask['id'], 'actual_weight' => '1.00', 'actual_price' => '20.00']));
        self::assertSame('blocked', Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->whereLike('source_key', '%:delivery')->value('status'));
    }

    public function test_unused_initial_process_can_be_deleted_and_keywords_cover_generated_draft(): void
    {
        $processes = WorkforceLogic::processes([])['lists'];
        $livePack = current(array_filter($processes, static fn(array $process): bool => $process['code'] === 'live_pack'));
        $abalonePack = current(array_filter($processes, static fn(array $process): bool => $process['code'] === 'abalone_pack'));
        self::assertContains('活包', $livePack['keywords']);
        self::assertContains('氧气袋', $livePack['keywords']);
        self::assertContains('鲍鱼包', $abalonePack['keywords']);
        self::assertContains('冰袋', $abalonePack['keywords']);
        self::assertContains('泡沫箱', $abalonePack['keywords']);
        self::assertFalse(WorkforceLogic::saveProcess(['name' => '不支持的手工工序', 'trigger_type' => 'manual', 'keywords' => [], 'is_enabled' => 1]));
        self::assertNotFalse(WorkforceLogic::deleteProcess(['id' => (int)$abalonePack['id']]), WorkforceLogic::getError());
        $purchase = current(array_filter($processes, static fn(array $process): bool => $process['code'] === 'purchase'));
        self::assertFalse(WorkforceLogic::deleteProcess(['id' => (int)$purchase['id']]));
        self::assertFalse(WorkforceLogic::statusProcess(['id' => (int)$purchase['id'], 'is_enabled' => 0]));
    }

    private function fulfillmentPayload(int $customerId, int $goodsId, int $warehouseId, string $key, string $quantity, string $processing): array
    {
        return [
            'main_customer_id' => $customerId,
            'delivery_date' => '2026-08-10',
            'is_supplement' => 0,
            'idempotency_key' => $key,
            'remark' => '',
            'items' => [[
                'goods_id' => $goodsId,
                'warehouse_id' => $warehouseId,
                'unit_id' => 0,
                'unit_name' => '件',
                'order_qty' => $quantity,
                'piece_weight_confirmed' => 1,
                'piece_weight_min' => '1.00',
                'piece_weight_max' => '1.00',
                'price_status' => 'unpriced',
                'processing_requirement' => $processing,
                'processing' => $processing,
                'line_remark' => $processing,
            ]],
        ];
    }
}
