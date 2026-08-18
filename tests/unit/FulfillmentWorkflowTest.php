<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportBatchLogic;
use app\api\jxc\logic\FulfillmentChangeLogic;
use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\DeliveryInventoryLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\logic\GoodsDimensionLogic;
use app\api\jxc\logic\NegativeInventoryLogic;
use app\api\jxc\logic\SalesOrderLogic;
use app\api\jxc\logic\StockService;
use app\api\jxc\logic\WarehouseSkuBalanceService;
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

    public function test_fulfillment_paper_control_migration_is_safe_to_replay(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = $this->prepareMigration((string)file_get_contents(
            $root . '/database/migrations/20260818_000002_fulfillment_paper_control.sql'
        ));
        $this->runStatements($migration);
        $this->runStatements($migration);

        self::assertSame(1, Db::query("SHOW TABLES LIKE 'la_fulfillment_paper_copy'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW TABLES LIKE 'la_fulfillment_ticket_control'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW TABLES LIKE 'la_fulfillment_item_change'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW COLUMNS FROM `la_fulfillment_task` LIKE 'process_weight'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW COLUMNS FROM `la_fulfillment_task` LIKE 'process_name_snapshot'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW COLUMNS FROM `la_customer_report_item` LIKE 'final_actual_weight'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW COLUMNS FROM `la_fulfillment_ticket_control` LIKE 'item_change_id'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW INDEX FROM `la_fulfillment_ticket_control` WHERE Key_name = 'idx_tenant_fulfillment_ticket_control_change'") !== [] ? 1 : 0);
        self::assertSame(1, Db::query("SHOW INDEX FROM `la_fulfillment_item_change` WHERE Key_name = 'uk_tenant_fulfillment_item_change_idem'") !== [] ? 1 : 0);
    }

    public function test_self_delivery_negative_inventory_migration_is_safe_to_replay(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = $this->prepareMigration((string)file_get_contents(
            $root . '/database/migrations/20260818_000003_self_delivery_negative_inventory.sql'
        ));
        $this->runStatements($migration);
        $this->runStatements($migration);

        foreach ([
            'la_fulfillment_delivery_event', 'la_fulfillment_delivery_item',
            'la_negative_inventory_attribution', 'la_negative_inventory_todo',
            'la_negative_inventory_action', 'la_negative_inventory_setting',
        ] as $table) {
            self::assertNotEmpty(Db::query("SHOW TABLES LIKE '{$table}'"));
        }
        self::assertNotEmpty(Db::query("SHOW COLUMNS FROM `la_sales_order` LIKE 'settlement_status'"));
        self::assertNotEmpty(Db::query("SHOW COLUMNS FROM `la_sales_order` LIKE 'cost_status'"));
        self::assertNotEmpty(Db::query("SHOW COLUMNS FROM `la_sales_order` LIKE 'profit_status'"));
        self::assertEmpty(Db::query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_warehouse_sku_balance' AND CONSTRAINT_NAME='chk_warehouse_sku_on_hand_non_negative'"));
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
            'status' => 'unassigned', 'is_settlement_task' => 0, 'ticket_no' => 'WT-TEST', 'create_time' => time(), 'update_time' => time(),
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
        self::assertNotFalse(FulfillmentTaskLogic::recover(['id' => (int)$tasks[1]['id'], 'actual_weight' => '1.00']));
        $values = FulfillmentTaskLogic::settlementValuesForReport((int)$report['id']);
        self::assertSame((int)$tasks[1]['id'], $values[(int)$report['items'][0]['id']]['task_id']);
        self::assertSame('0.00', $values[(int)$report['items'][0]['id']]['actual_price']);
        $processIds = array_map('intval', array_column(WorkforceLogic::processes([])['lists'], 'id'));
        self::assertNotFalse(WorkforceLogic::reorderProcesses(['ids' => array_reverse($processIds)]), WorkforceLogic::getError());
        $valuesAfterReorder = FulfillmentTaskLogic::settlementValuesForReport((int)$report['id']);
        self::assertSame((int)$tasks[1]['id'], $valuesAfterReorder[(int)$report['items'][0]['id']]['task_id']);
    }

    public function test_only_final_weighing_updates_item_final_weight_and_process_weight_does_not_require_price(): void
    {
        $customerId = $this->createCustomer('最终称重客户');
        $goodsId = $this->createCustomerReportGoods('最终称重石斑', 'TASK-FINAL-WEIGHT');
        $warehouseId = $this->createCustomerReportWarehouse('最终称重仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '3.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-final-weight', '2', '杀好、活鱼打包'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $itemId = (int)$report['items'][0]['id'];
        $tasks = Db::name('fulfillment_task')->alias('t')
            ->join('work_process p', 'p.id=t.process_id AND p.tenant_id=t.tenant_id')
            ->where('t.report_item_id', $itemId)->where('t.task_type', 'process')
            ->order(['p.sort' => 'asc', 'p.id' => 'asc'])->field('t.*')->select()->toArray();
        self::assertCount(2, $tasks);
        Db::name('fulfillment_task')->whereIn('id', array_column($tasks, 'id'))->update(['status' => 'printed']);

        $process = FulfillmentTaskLogic::recover(['id' => (int)$tasks[0]['id'], 'actual_weight' => '1.80']);
        self::assertNotFalse($process, FulfillmentTaskLogic::getError());
        self::assertSame('1.80', (string)$process['process_weight']);
        self::assertSame('0.00', (string)Db::name('customer_report_item')->where('id', $itemId)->value('final_actual_weight'));

        $final = FulfillmentTaskLogic::recover(['id' => (int)$tasks[1]['id'], 'actual_weight' => '1.65']);
        self::assertNotFalse($final, FulfillmentTaskLogic::getError());
        $item = Db::name('customer_report_item')->where('id', $itemId)->find();
        self::assertSame('1.65', (string)$item['final_actual_weight']);
        self::assertSame((int)$tasks[1]['id'], (int)$item['final_weight_task_id']);
        self::assertSame('final_weight_recorded', (string)$item['fulfillment_status']);
        $values = FulfillmentTaskLogic::settlementValuesForReport((int)$report['id']);
        self::assertSame('1.65', $values[$itemId]['final_actual_weight']);
        self::assertArrayNotHasKey((int)$tasks[0]['id'], array_column($values, null, 'task_id'));
    }

    public function test_successful_reprint_keeps_ticket_identity_and_each_paper_copy_is_accounted(): void
    {
        $customerId = $this->createCustomer('重打工票客户');
        $goodsId = $this->createCustomerReportGoods('重打海鲈鱼', 'TASK-REPRINT');
        $warehouseId = $this->createCustomerReportWarehouse('重打仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-reprint', '1', '杀好'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $task = Db::name('fulfillment_task')->where('report_item_id', (int)$report['items'][0]['id'])
            ->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$task['id'])->update(['status' => 'printable']);

        $first = FulfillmentTaskLogic::printData(['id' => (int)$task['id']]);
        self::assertNotFalse($first, FulfillmentTaskLogic::getError());
        self::assertGreaterThan(0, (int)$first['print_requested_time']);
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => (int)$task['id'], 'print_log_id' => (int)$first['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());
        $second = FulfillmentTaskLogic::printData(['id' => (int)$task['id']]);
        self::assertNotFalse($second, FulfillmentTaskLogic::getError());
        self::assertSame($first['ticket']['ticket_no'], $second['ticket']['ticket_no']);
        self::assertSame(2, (int)$second['copy_no']);
        self::assertGreaterThanOrEqual((int)$first['print_requested_time'], (int)$second['print_requested_time']);
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => (int)$task['id'], 'print_log_id' => (int)$second['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());
        self::assertSame(1, (int)FulfillmentTaskLogic::detail(['id' => (int)$task['id']])['reprint_count']);

        $oneCopy = FulfillmentTaskLogic::recover([
            'id' => (int)$task['id'], 'print_log_id' => (int)$first['print_log_id'], 'actual_weight' => '0.95',
        ]);
        self::assertNotFalse($oneCopy, FulfillmentTaskLogic::getError());
        self::assertSame('printed', $oneCopy['status'], '另一张成功纸票未回收时任务不能完成');
        $allCopies = FulfillmentTaskLogic::recover([
            'id' => (int)$task['id'], 'print_log_id' => (int)$second['print_log_id'], 'actual_weight' => '0.95',
        ]);
        self::assertNotFalse($allCopies, FulfillmentTaskLogic::getError());
        self::assertSame('recovered', $allCopies['status']);
        self::assertSame(0, Db::name('fulfillment_paper_copy')->where('task_id', (int)$task['id'])->where('paper_status', 'issued')->count());
        self::assertNotFalse(FulfillmentTaskLogic::recover([
            'id' => (int)$task['id'], 'print_log_id' => (int)$second['print_log_id'], 'actual_weight' => '0.95',
        ]), FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::recover([
            'id' => (int)$task['id'], 'actual_weight' => '0.95',
        ]), FulfillmentTaskLogic::getError());
        self::assertFalse(FulfillmentTaskLogic::recover([
            'id' => (int)$task['id'], 'print_log_id' => (int)$second['print_log_id'],
            'actual_weight' => '0.95', 'actual_price' => '12.00',
        ]));
        self::assertSame('该纸质工票副本已经处理，不能覆盖原结果', FulfillmentTaskLogic::getError());
        self::assertFalse(FulfillmentTaskLogic::recover([
            'id' => (int)$task['id'], 'actual_weight' => '0.95', 'recovery_note' => '试图覆盖原说明',
        ]));
        self::assertSame('工票已回收，不能覆盖回收模式、重量、价格或说明', FulfillmentTaskLogic::getError());
        self::assertFalse(FulfillmentTaskLogic::recover([
            'id' => (int)$task['id'], 'print_log_id' => (int)$second['print_log_id'], 'actual_weight' => '0.96',
        ]));
    }

    public function test_printed_content_reduction_creates_void_control_and_notice_before_new_ticket(): void
    {
        $customerId = $this->createCustomer('处理后减量客户');
        $goodsId = $this->createCustomerReportGoods('减量桂花鱼', 'TASK-REDUCE');
        $warehouseId = $this->createCustomerReportWarehouse('减量仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '6.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-reduce', '5', '杀好'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $itemId = (int)$report['items'][0]['id'];
        $task = Db::name('fulfillment_task')->where('report_item_id', $itemId)->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$task['id'])->update(['status' => 'printable']);
        $oldPrint = FulfillmentTaskLogic::printData(['id' => (int)$task['id']]);
        self::assertNotFalse($oldPrint, FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => (int)$task['id'], 'print_log_id' => (int)$oldPrint['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());

        $changed = FulfillmentChangeLogic::reduceItem([
            'report_item_id' => $itemId,
            'new_expected_base_qty' => '3.00',
            'processed_reduction_qty' => '1.00',
            'processed_disposition' => 'internal_loss',
            'reason' => '客户开工后减少两件，其中一件已加工报损',
            'idempotency_key' => 'reduce-after-print-1',
        ]);
        self::assertNotFalse($changed, FulfillmentChangeLogic::getError());
        self::assertSame('3.00', (string)$changed['item']['expected_base_qty']);
        self::assertSame('3.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        self::assertSame('5.0000', WarehouseGoodsBalanceService::onHand($warehouseId, $goodsId));
        $updatedTask = Db::name('fulfillment_task')->where('id', (int)$task['id'])->find();
        self::assertSame($task['ticket_no'], $updatedTask['ticket_no']);
        self::assertSame(2, (int)$updatedTask['content_version']);
        self::assertSame('printable', $updatedTask['status']);
        self::assertSame('void_required', Db::name('fulfillment_paper_copy')->where('print_log_id', (int)$oldPrint['print_log_id'])->value('paper_status'));
        $control = Db::name('fulfillment_ticket_control')->where('print_log_id', (int)$oldPrint['print_log_id'])->find();
        self::assertSame('pending_recovery', $control['status']);
        self::assertTrue(FulfillmentTaskLogic::hasUnaccountedPaperForReport((int)$report['id']));
        self::assertFalse(CustomerReportLogic::convert([
            'id' => (int)$report['id'],
            'version' => (int)Db::name('customer_report')->where('id', (int)$report['id'])->value('version'),
        ]));
        self::assertSame('仍有未回收或未完成作废控制的纸质工票，不能结算', CustomerReportLogic::getError());

        $noticeRequired = FulfillmentTaskLogic::paperControl([
            'control_id' => (int)$control['id'], 'resolution' => 'unrecoverable', 'note' => '旧票已随破损包装丢失',
        ]);
        self::assertNotFalse($noticeRequired, FulfillmentTaskLogic::getError());
        self::assertSame('notice_required', $noticeRequired['status']);
        $notice = FulfillmentTaskLogic::controlPrintData(['control_id' => (int)$control['id']]);
        self::assertNotFalse($notice, FulfillmentTaskLogic::getError());
        self::assertSame('change_notice', $notice['print_type']);
        $noticeReplay = FulfillmentTaskLogic::controlPrintData(['control_id' => (int)$control['id']]);
        self::assertNotFalse($noticeReplay, FulfillmentTaskLogic::getError());
        self::assertSame((int)$notice['print_log_id'], (int)$noticeReplay['print_log_id']);
        self::assertNotFalse(FulfillmentTaskLogic::controlPrintResult([
            'control_id' => (int)$control['id'], 'print_log_id' => (int)$notice['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());
        self::assertSame('closed', Db::name('fulfillment_ticket_control')->where('id', (int)$control['id'])->value('status'));
    }

    public function test_pending_and_accounted_paper_copies_are_versioned_when_content_changes(): void
    {
        $customerId = $this->createCustomer('打印版本控制客户');
        $goodsId = $this->createCustomerReportGoods('打印版本控制鱼', 'TASK-VERSION-CONTROL');
        $warehouseId = $this->createCustomerReportWarehouse('打印版本控制仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '9.00'));

        $pendingReport = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-pending-version', '3', '杀好'
        ));
        self::assertNotFalse($pendingReport, CustomerReportLogic::getError());
        $pendingItemId = (int)$pendingReport['items'][0]['id'];
        $pendingTask = Db::name('fulfillment_task')->where('report_item_id', $pendingItemId)->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$pendingTask['id'])->update(['status' => 'printable']);
        $pendingPrint = FulfillmentTaskLogic::printData(['id' => (int)$pendingTask['id']]);
        self::assertNotFalse($pendingPrint, FulfillmentTaskLogic::getError());

        $pendingChange = FulfillmentChangeLogic::reduceItem([
            'report_item_id' => $pendingItemId, 'new_expected_base_qty' => '2.00',
            'processed_reduction_qty' => '0.00', 'processed_disposition' => '',
            'reason' => '打印回执返回前客户减量', 'idempotency_key' => 'pending-print-reduce',
        ]);
        self::assertNotFalse($pendingChange, FulfillmentChangeLogic::getError());
        $pendingControl = Db::name('fulfillment_ticket_control')->where('report_item_id', $pendingItemId)->find();
        self::assertSame((int)$pendingChange['change']['id'], (int)$pendingControl['item_change_id']);
        self::assertSame('pending_recovery', (string)$pendingControl['status']);
        self::assertSame('superseded', (string)Db::name('fulfillment_print_log')->where('id', (int)$pendingPrint['print_log_id'])->value('status'));
        self::assertSame('void_required', (string)Db::name('fulfillment_paper_copy')->where('print_log_id', (int)$pendingPrint['print_log_id'])->value('paper_status'));
        self::assertFalse(FulfillmentTaskLogic::printResult([
            'id' => (int)$pendingTask['id'], 'print_log_id' => (int)$pendingPrint['print_log_id'], 'success' => 1,
        ]));
        self::assertSame('打印回执已过期', FulfillmentTaskLogic::getError());

        $recoveredReport = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-recovered-version', '3', '杀好'
        ));
        self::assertNotFalse($recoveredReport, CustomerReportLogic::getError());
        $recoveredItemId = (int)$recoveredReport['items'][0]['id'];
        $recoveredTask = Db::name('fulfillment_task')->where('report_item_id', $recoveredItemId)->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$recoveredTask['id'])->update(['status' => 'printable']);
        $recoveredPrint = FulfillmentTaskLogic::printData(['id' => (int)$recoveredTask['id']]);
        self::assertNotFalse($recoveredPrint, FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => (int)$recoveredTask['id'], 'print_log_id' => (int)$recoveredPrint['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::recover([
            'id' => (int)$recoveredTask['id'], 'print_log_id' => (int)$recoveredPrint['print_log_id'], 'actual_weight' => '2.80',
        ]), FulfillmentTaskLogic::getError());
        $recoveredChange = FulfillmentChangeLogic::reduceItem([
            'report_item_id' => $recoveredItemId, 'new_expected_base_qty' => '2.00',
            'processed_reduction_qty' => '0.00', 'processed_disposition' => '',
            'reason' => '纸票回收后客户减量', 'idempotency_key' => 'recovered-print-reduce',
        ]);
        self::assertNotFalse($recoveredChange, FulfillmentChangeLogic::getError());
        $updatedRecoveredTask = Db::name('fulfillment_task')->where('id', (int)$recoveredTask['id'])->find();
        self::assertSame(2, (int)$updatedRecoveredTask['content_version']);
        self::assertSame('printable', (string)$updatedRecoveredTask['status']);
        self::assertSame('0.00', (string)$updatedRecoveredTask['process_weight']);
        self::assertSame('0.00', (string)Db::name('customer_report_item')->where('id', $recoveredItemId)->value('final_actual_weight'));
        self::assertSame('closed', (string)Db::name('fulfillment_ticket_control')->where('report_item_id', $recoveredItemId)->value('status'));
        self::assertSame('void_recovered', (string)Db::name('fulfillment_paper_copy')->where('print_log_id', (int)$recoveredPrint['print_log_id'])->value('paper_status'));

        $exceptionReport = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-exception-version', '3', '杀好'
        ));
        self::assertNotFalse($exceptionReport, CustomerReportLogic::getError());
        $exceptionItemId = (int)$exceptionReport['items'][0]['id'];
        $exceptionTask = Db::name('fulfillment_task')->where('report_item_id', $exceptionItemId)->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$exceptionTask['id'])->update(['status' => 'printable']);
        $exceptionPrint = FulfillmentTaskLogic::printData(['id' => (int)$exceptionTask['id']]);
        self::assertNotFalse($exceptionPrint, FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => (int)$exceptionTask['id'], 'print_log_id' => (int)$exceptionPrint['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::recoverException([
            'id' => (int)$exceptionTask['id'], 'print_log_id' => (int)$exceptionPrint['print_log_id'],
            'actual_weight' => '2.70', 'exception_reason' => 'lost', 'exception_note' => '电话核实原票丢失',
        ]), FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentChangeLogic::reduceItem([
            'report_item_id' => $exceptionItemId, 'new_expected_base_qty' => '2.00',
            'processed_reduction_qty' => '0.00', 'processed_disposition' => '',
            'reason' => '异常补录后客户减量', 'idempotency_key' => 'exception-print-reduce',
        ]), FulfillmentChangeLogic::getError());
        self::assertSame('notice_required', (string)Db::name('fulfillment_ticket_control')->where('report_item_id', $exceptionItemId)->value('status'));
        self::assertSame('void_required', (string)Db::name('fulfillment_paper_copy')->where('print_log_id', (int)$exceptionPrint['print_log_id'])->value('paper_status'));
    }

    public function test_lost_final_ticket_uses_exception_recovery_with_full_audit(): void
    {
        $customerId = $this->createCustomer('异常补录客户');
        $goodsId = $this->createCustomerReportGoods('异常补录鲈鱼', 'TASK-EXCEPTION-RECOVER');
        $warehouseId = $this->createCustomerReportWarehouse('异常补录仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-exception-recover', '1', '杀好'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $itemId = (int)$report['items'][0]['id'];
        $task = Db::name('fulfillment_task')->where('report_item_id', $itemId)->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$task['id'])->update(['status' => 'printable']);
        $printed = FulfillmentTaskLogic::printData(['id' => (int)$task['id']]);
        self::assertNotFalse($printed, FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => (int)$task['id'], 'print_log_id' => (int)$printed['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());

        self::assertFalse(FulfillmentTaskLogic::recoverException([
            'id' => (int)$task['id'], 'print_log_id' => (int)$printed['print_log_id'], 'actual_weight' => '0.88',
            'exception_reason' => 'lost', 'exception_note' => '',
        ]));
        $recovered = FulfillmentTaskLogic::recoverException([
            'id' => (int)$task['id'], 'print_log_id' => (int)$printed['print_log_id'], 'actual_weight' => '0.88',
            'exception_reason' => 'lost', 'exception_note' => '电话向称重员工核实后补录',
        ]);
        self::assertNotFalse($recovered, FulfillmentTaskLogic::getError());
        self::assertSame('exception', $recovered['recovery_mode']);
        self::assertSame('lost', $recovered['recovery_exception_reason']);
        self::assertSame('0.88', (string)Db::name('customer_report_item')->where('id', $itemId)->value('final_actual_weight'));
        $paper = Db::name('fulfillment_paper_copy')->where('print_log_id', (int)$printed['print_log_id'])->find();
        self::assertSame('lost', $paper['paper_status']);
        self::assertSame(self::ADMIN_ID, (int)$paper['accounted_by']);
        self::assertGreaterThan(0, (int)$paper['accounted_time']);
        self::assertSame(1, Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'fulfillment_task')->where('action', 'exception_recover')->where('target_id', (int)$task['id'])->count());
    }

    public function test_undelivered_is_explicit_idempotent_and_never_uses_zero_final_weight(): void
    {
        $customerId = $this->createCustomer('未交货客户');
        $goodsId = $this->createCustomerReportGoods('缺货龙虾', 'TASK-UNDELIVERED');
        $warehouseId = $this->createCustomerReportWarehouse('未交货仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-undelivered', '2', '活鱼打包'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $itemId = (int)$report['items'][0]['id'];
        $task = Db::name('fulfillment_task')->where('report_item_id', $itemId)->where('task_type', 'process')->find();
        Db::name('fulfillment_task')->where('id', (int)$task['id'])->update(['status' => 'printable']);
        $printed = FulfillmentTaskLogic::printData(['id' => (int)$task['id']]);
        self::assertNotFalse($printed, FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => (int)$task['id'], 'print_log_id' => (int)$printed['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());

        $request = [
            'report_item_id' => $itemId, 'reason_code' => 'shortage', 'reason' => '供应商最终无法补齐',
            'idempotency_key' => 'undelivered-1',
        ];
        $undelivered = FulfillmentChangeLogic::markUndelivered($request);
        self::assertNotFalse($undelivered, FulfillmentChangeLogic::getError());
        self::assertSame('undelivered', $undelivered['item']['fulfillment_status']);
        self::assertSame('0.00', (string)$undelivered['item']['final_actual_weight']);
        self::assertSame('0.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        self::assertSame(0, Db::name('fulfillment_task')->where('report_item_id', $itemId)->where('status', '<>', 'cancelled')->count());
        $groupId = (int)Db::name('fulfillment_task')->where('report_item_id', $itemId)->order('id')->value('group_id');
        self::assertSame('paper_control_pending', (string)Db::name('fulfillment_task_group')->where('id', $groupId)->value('status'));
        $controlId = (int)Db::name('fulfillment_ticket_control')->where('report_item_id', $itemId)->value('id');
        self::assertNotFalse(FulfillmentTaskLogic::paperControl([
            'control_id' => $controlId, 'resolution' => 'recovered', 'note' => '原纸票已收回并划线作废',
        ]), FulfillmentTaskLogic::getError());
        self::assertSame('completed', (string)Db::name('fulfillment_task_group')->where('id', $groupId)->value('status'));
        self::assertSame('fulfilled_undelivered', (string)Db::name('customer_report')->where('id', (int)$report['id'])->value('status'));
        self::assertSame((int)$undelivered['change']['id'], (int)FulfillmentChangeLogic::markUndelivered($request)['change']['id']);
        $conflict = $request;
        $conflict['reason_code'] = 'damage';
        self::assertFalse(FulfillmentChangeLogic::markUndelivered($conflict));
        self::assertSame('幂等键已用于不同的履约变更', FulfillmentChangeLogic::getError());
    }

    public function test_mixed_delivered_and_undelivered_items_only_outbound_and_stage_the_delivered_lines(): void
    {
        $customerId = $this->createCustomer('部分交货客户');
        $deliveredGoodsId = $this->createCustomerReportGoods('已交付石斑', 'TASK-MIXED-DELIVERED');
        $undeliveredGoodsId = $this->createCustomerReportGoods('未交付龙虾', 'TASK-MIXED-UNDELIVERED');
        $warehouseId = $this->createCustomerReportWarehouse('部分交货仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $deliveredGoodsId, '2.00'));
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $undeliveredGoodsId, '2.00'));
        $payload = $this->fulfillmentPayload(
            $customerId, $deliveredGoodsId, $warehouseId, 'task-mixed-undelivered', '1', '杀好'
        );
        $secondItem = $payload['items'][0];
        $secondItem['goods_id'] = $undeliveredGoodsId;
        $secondItem['processing_requirement'] = '活鱼打包';
        $secondItem['processing'] = '活鱼打包';
        $secondItem['line_remark'] = '活鱼打包';
        $payload['items'][] = $secondItem;
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $deliveredItem = current(array_filter(
            $report['items'], static fn(array $item): bool => (int)$item['goods_id'] === $deliveredGoodsId
        ));
        $undeliveredItem = current(array_filter(
            $report['items'], static fn(array $item): bool => (int)$item['goods_id'] === $undeliveredGoodsId
        ));

        self::assertNotFalse(FulfillmentChangeLogic::markUndelivered([
            'report_item_id' => (int)$undeliveredItem['id'], 'reason_code' => 'shortage',
            'reason' => '供应商最终无货', 'idempotency_key' => 'mixed-undelivered-line',
        ]), FulfillmentChangeLogic::getError());
        $finalTask = Db::name('fulfillment_task')->where('report_item_id', (int)$deliveredItem['id'])
            ->where('is_settlement_task', 1)->find();
        Db::name('fulfillment_task')->where('id', (int)$finalTask['id'])->update([
            'status' => 'recovered', 'actual_weight' => '0.90', 'process_weight' => '0.90',
            'actual_price' => '30.00', 'recovered_time' => time(),
        ]);
        Db::name('customer_report_item')->where('id', (int)$deliveredItem['id'])->update([
            'final_actual_weight' => '0.90', 'final_weight_task_id' => (int)$finalTask['id'],
            'fulfillment_status' => 'final_weight_recorded',
        ]);
        FulfillmentTaskLogic::refreshGroupForItem((int)$deliveredItem['id']);
        $delivery = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->whereLike('source_key', '%:delivery')->find();
        $bookkeeping = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->whereLike('source_key', '%:bookkeeping')->find();
        $delivered = DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => (int)$delivery['id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'mixed-delivered-handoff',
        ]);
        self::assertNotFalse($delivered, DeliveryInventoryLogic::getError());
        Db::name('fulfillment_task')->where('id', (int)$bookkeeping['id'])->update(['status' => 'ready_to_bill']);

        self::assertFalse(FulfillmentTaskLogic::bill(['id' => (int)$bookkeeping['id']]));
        self::assertSame('交付已经完成出库，请在后续销售结算中正式确认，不能再次扣减库存', FulfillmentTaskLogic::getError());
        self::assertSame('partially_delivered_pending', (string)Db::name('customer_report')->where('id', (int)$report['id'])->value('status'));
        self::assertSame(1, Db::name('order_goods')->where('source_line_type', 'customer_report_item')
            ->whereIn('source_line_id', [(int)$deliveredItem['id'], (int)$undeliveredItem['id']])->count());
        self::assertSame(1, Db::name('order_goods')->where('source_line_id', (int)$deliveredItem['id'])->count());
        self::assertSame(0, Db::name('order_goods')->where('source_line_id', (int)$undeliveredItem['id'])->count());
        self::assertSame('undelivered', (string)Db::name('customer_report_item')->where('id', (int)$undeliveredItem['id'])->value('fulfillment_status'));
        self::assertSame(0, Db::name('receivable_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_print_data_exposes_supplement_and_main_child_customer_hierarchy(): void
    {
        $mainCustomerId = $this->createCustomer('层级主客户');
        $childCustomerId = $this->createCustomer('层级子客户', $mainCustomerId);
        $goodsId = $this->createCustomerReportGoods('层级测试鱼', 'TASK-TICKET-HIERARCHY');
        $warehouseId = $this->createCustomerReportWarehouse('层级测试仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '4.00'));
        $batch = CustomerReportBatchLogic::start([
            'delivery_date' => '2026-08-10', 'idempotency_key' => 'ticket-hierarchy-batch',
        ]);
        self::assertNotFalse($batch, CustomerReportBatchLogic::getError());
        $originalPayload = $this->fulfillmentPayload(
            $mainCustomerId, $goodsId, $warehouseId, 'ticket-hierarchy-original', '1', '杀好'
        );
        $originalPayload['batch_id'] = (int)$batch['id'];
        $originalPayload['items'][0]['delivery_customer_id'] = $childCustomerId;
        $original = CustomerReportLogic::submit($originalPayload);
        self::assertNotFalse($original, CustomerReportLogic::getError());
        $processing = CustomerReportBatchLogic::process(['id' => (int)$batch['id'], 'version' => (int)$batch['version']]);
        self::assertNotFalse($processing, CustomerReportBatchLogic::getError());
        $supplementPayload = $this->fulfillmentPayload(
            $mainCustomerId, $goodsId, $warehouseId, 'ticket-hierarchy-supplement', '1', '杀好'
        );
        $supplementPayload['batch_id'] = (int)$processing['id'];
        $supplementPayload['is_supplement'] = 1;
        $supplementPayload['supplement_for_report_id'] = (int)$original['id'];
        $supplementPayload['items'][0]['delivery_customer_id'] = $childCustomerId;
        $supplement = CustomerReportLogic::submit($supplementPayload);
        self::assertNotFalse($supplement, CustomerReportLogic::getError());
        $supplementTask = Db::name('fulfillment_task')->where('report_id', (int)$supplement['id'])
            ->whereLike('source_key', '%:process:%')->order('id')->find();
        Db::name('fulfillment_task')->where('id', (int)$supplementTask['id'])->update(['status' => 'printable']);
        $supplementPrint = FulfillmentTaskLogic::printData(['id' => (int)$supplementTask['id']]);
        self::assertNotFalse($supplementPrint, FulfillmentTaskLogic::getError());
        $display = $supplementPrint['ticket']['ticket_display'];
        self::assertSame(1, (int)$display['is_supplement']);
        self::assertSame('层级主客户', (string)$display['main_customer']['name']);
        self::assertTrue((bool)$display['main_customer']['emphasis']);
        self::assertSame('层级子客户', (string)$display['delivery_customer']['name']);
        self::assertTrue((bool)$display['delivery_customer']['is_child']);
        self::assertFalse((bool)$display['delivery_customer']['emphasis']);

        $mainOnlyPayload = $this->fulfillmentPayload(
            $mainCustomerId, $goodsId, $warehouseId, 'ticket-hierarchy-main-only', '1', '杀好'
        );
        $mainOnly = CustomerReportLogic::submit($mainOnlyPayload);
        self::assertNotFalse($mainOnly, CustomerReportLogic::getError());
        $mainTask = Db::name('fulfillment_task')->where('report_id', (int)$mainOnly['id'])
            ->whereLike('source_key', '%:process:%')->order('id')->find();
        Db::name('fulfillment_task')->where('id', (int)$mainTask['id'])->update(['status' => 'printable']);
        $mainPrint = FulfillmentTaskLogic::printData(['id' => (int)$mainTask['id']]);
        self::assertNotFalse($mainPrint, FulfillmentTaskLogic::getError());
        self::assertSame('层级主客户', (string)$mainPrint['ticket']['ticket_display']['delivery_customer']['name']);
        self::assertFalse((bool)$mainPrint['ticket']['ticket_display']['delivery_customer']['is_child']);
    }

    public function test_process_catalog_rename_does_not_rewrite_an_existing_ticket_snapshot(): void
    {
        $customerId = $this->createCustomer('工序快照客户');
        $goodsId = $this->createCustomerReportGoods('工序快照鱼', 'TASK-PROCESS-SNAPSHOT');
        $warehouseId = $this->createCustomerReportWarehouse('工序快照仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-process-snapshot', '1', '杀好'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $task = Db::name('fulfillment_task')->where('report_item_id', (int)$report['items'][0]['id'])
            ->whereLike('source_key', '%:process:%')->find();
        Db::name('fulfillment_task')->where('id', (int)$task['id'])->update(['status' => 'printable']);
        $first = FulfillmentTaskLogic::printData(['id' => (int)$task['id']]);
        self::assertNotFalse($first, FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => (int)$task['id'], 'print_log_id' => (int)$first['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());
        $originalName = (string)$first['ticket']['ticket_display']['process_name'];
        $originalHash = (string)Db::name('fulfillment_task')->where('id', (int)$task['id'])->value('content_hash');
        Db::name('fulfillment_task')->where('id', (int)$task['id'])->update(['process_name_snapshot' => '']);
        $process = Db::name('work_process')->where('id', (int)$task['process_id'])->find();
        $renamed = WorkforceLogic::saveProcess([
            'id' => (int)$process['id'], 'name' => $originalName . '（目录新名）',
            'trigger_type' => (string)$process['trigger_type'],
            'keywords' => json_decode((string)$process['trigger_keywords'], true) ?: [],
            'sort' => (int)$process['sort'], 'is_enabled' => (int)$process['is_enabled'],
        ]);
        self::assertNotFalse($renamed, WorkforceLogic::getError());

        $detail = FulfillmentTaskLogic::detail(['id' => (int)$task['id']]);
        self::assertNotFalse($detail, FulfillmentTaskLogic::getError());
        self::assertSame($originalName, (string)$detail['process_name']);
        self::assertSame($originalName, (string)$detail['ticket_display']['process_name']);
        self::assertSame(1, (int)$detail['content_version']);
        self::assertSame($originalHash, (string)$detail['content_hash']);
        self::assertSame(0, Db::name('fulfillment_ticket_control')->where('task_id', (int)$task['id'])->count());
        $reprint = FulfillmentTaskLogic::printData(['id' => (int)$task['id']]);
        self::assertNotFalse($reprint, FulfillmentTaskLogic::getError());
        self::assertSame($originalName, (string)$reprint['ticket']['ticket_display']['process_name']);
        self::assertSame(1, (int)$reprint['ticket']['content_version']);
    }

    public function test_concurrent_reduction_with_one_idempotency_key_applies_inventory_once(): void
    {
        $customerId = $this->createCustomer('并发减量客户');
        $goodsId = $this->createCustomerReportGoods('并发减量鱼', 'TASK-CONCURRENT-REDUCE');
        $warehouseId = $this->createCustomerReportWarehouse('并发减量仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '3.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-concurrent-reduce-report', '3', '杀好'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $itemId = (int)$report['items'][0]['id'];
        $change = [
            'report_item_id' => $itemId, 'new_expected_base_qty' => '2.00',
            'processed_reduction_qty' => '0.00', 'processed_disposition' => '',
            'reason' => '并发幂等减量', 'idempotency_key' => 'concurrent-reduce-one-key',
        ];
        $paths = [];
        $startPath = tempnam(sys_get_temp_dir(), 'fulfillment-change-start-');
        unlink($startPath);
        try {
            $processes = [];
            for ($worker = 0; $worker < 2; $worker++) {
                $inputPath = tempnam(sys_get_temp_dir(), 'fulfillment-change-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'fulfillment-change-output-');
                $paths[] = $inputPath;
                $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode([
                    'tenant_id' => self::TENANT_ID, 'admin_id' => self::ADMIN_ID, 'change' => $change,
                ], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(dirname(__DIR__) . '/fixtures/fulfillment_change_worker.php') . ' '
                    . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' ' . escapeshellarg($startPath);
                $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $outputPath];
            }
            usleep(100_000);
            touch($startPath);
            foreach ($processes as $index => [$process, $pipes]) {
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $processes[$index][3] = $stdout;
                $processes[$index][4] = $stderr;
                self::assertSame(0, proc_close($process), '并发减量进程失败：stdout=' . $stdout . '; stderr=' . $stderr);
            }
            $responses = array_map(static function (array $process): array {
                $response = json_decode((string)file_get_contents($process[2]), true) ?: [];
                $response['_stdout'] = (string)($process[3] ?? '');
                $response['_stderr'] = (string)($process[4] ?? '');
                return $response;
            }, $processes);
            $diagnostic = json_encode($responses, JSON_UNESCAPED_UNICODE);
            self::assertNotEmpty($responses[0]['result'], $diagnostic);
            self::assertNotEmpty($responses[1]['result'], $diagnostic);
            self::assertSame(
                (int)$responses[0]['result']['change']['id'],
                (int)$responses[1]['result']['change']['id'],
                $diagnostic
            );
            self::assertSame(1, Db::name('fulfillment_item_change')->where('idempotency_key', 'concurrent-reduce-one-key')->count());
            self::assertSame('2.00', (string)Db::name('customer_report_item')->where('id', $itemId)->value('expected_base_qty'));
            self::assertSame('2.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        } finally {
            if (is_file($startPath)) { unlink($startPath); }
            foreach ($paths as $path) { if (is_file($path)) { unlink($path); } }
        }
    }

    public function test_reduction_rolls_back_inventory_item_control_and_change_when_audit_fails(): void
    {
        $customerId = $this->createCustomer('减量回滚客户');
        $goodsId = $this->createCustomerReportGoods('减量回滚鱼', 'TASK-REDUCE-ROLLBACK');
        $warehouseId = $this->createCustomerReportWarehouse('减量回滚仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '3.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-reduce-rollback', '3', '杀好'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $itemId = (int)$report['items'][0]['id'];
        Db::execute('RENAME TABLE `la_audit_log` TO `la_audit_log_forced_failure`');
        try {
            self::assertFalse(FulfillmentChangeLogic::reduceItem([
                'report_item_id' => $itemId, 'new_expected_base_qty' => '2.00',
                'processed_reduction_qty' => '0.00', 'processed_disposition' => '',
                'reason' => '强制审计失败验证回滚', 'idempotency_key' => 'reduce-rollback-1',
            ]));
        } finally {
            Db::execute('RENAME TABLE `la_audit_log_forced_failure` TO `la_audit_log`');
        }
        self::assertSame('3.00', (string)Db::name('customer_report_item')->where('id', $itemId)->value('expected_base_qty'));
        self::assertSame('3.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        self::assertSame(0, Db::name('fulfillment_item_change')->where('report_item_id', $itemId)->count());
        self::assertSame(0, Db::name('fulfillment_ticket_control')->where('report_item_id', $itemId)->count());
    }

    public function test_fulfillment_changes_enforce_electronic_permission_and_tenant_scope(): void
    {
        $customerId = $this->createCustomer('权限隔离客户');
        $goodsId = $this->createCustomerReportGoods('权限隔离鱼', 'TASK-CONTROL-AUTH');
        $warehouseId = $this->createCustomerReportWarehouse('权限隔离仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'task-control-auth', '2', '杀好'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $itemId = (int)$report['items'][0]['id'];

        $process = current(array_filter(
            WorkforceLogic::processes([])['lists'], static fn(array $row): bool => $row['code'] === 'kill_fish'
        ));
        $employee = WorkforceLogic::saveEmployee([
            'name' => '仅回收员工', 'mobile' => '13800000066', 'bind_user_id' => 996601, 'is_enabled' => 1,
            'process_ids' => [(int)$process['id']], 'permission_keys' => ['task.recover'],
        ]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->adminId = 0;
        request()->userId = 0;
        self::assertFalse(FulfillmentChangeLogic::reduceItem([
            'report_item_id' => $itemId, 'new_expected_base_qty' => '1.00',
            'processed_reduction_qty' => '0.00', 'reason' => '无控制权限', 'idempotency_key' => 'no-control-permission',
        ]));
        self::assertSame('没有执行该操作的电子权限', FulfillmentChangeLogic::getError());

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(FulfillmentChangeLogic::markUndelivered([
            'report_item_id' => $itemId, 'reason_code' => 'shortage', 'reason' => '跨租户猜测 ID',
            'idempotency_key' => 'cross-tenant-undelivered',
        ]));
        self::assertSame('报货明细不存在', FulfillmentChangeLogic::getError());
        self::assertSame('pending', (string)Db::name('customer_report_item')->where('tenant_id', self::TENANT_ID)
            ->where('id', $itemId)->value('fulfillment_status'));
        $this->prepareCustomerReportRequestContext();
    }

    public function test_customer_report_delivery_creates_pending_order_without_running_sales_settlement(): void
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
        Db::name('customer_report_item')->where('id', (int)$report['items'][0]['id'])->update([
            'final_actual_weight' => '1.00', 'final_weight_task_id' => (int)$processTask['id'],
            'fulfillment_status' => 'final_weight_recorded',
        ]);
        FulfillmentTaskLogic::refreshGroupForItem((int)$report['items'][0]['id']);
        $delivery = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->whereLike('source_key', '%:delivery')->find();
        $bookkeeping = Db::name('fulfillment_task')->where('report_id', (int)$report['id'])->whereLike('source_key', '%:bookkeeping')->find();
        self::assertNotFalse(DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => (int)$delivery['id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'task-bill-gate-handoff',
        ]), DeliveryInventoryLogic::getError());
        Db::name('fulfillment_task')->where('id', (int)$bookkeeping['id'])->update(['status' => 'ready_to_bill']);

        self::assertFalse(FulfillmentTaskLogic::bill(['id' => (int)$bookkeeping['id']]));
        self::assertSame('交付已经完成出库，请在后续销售结算中正式确认，不能再次扣减库存', FulfillmentTaskLogic::getError());
        $line = Db::name('customer_report_item')->where('id', (int)$report['items'][0]['id'])->find();
        $orderGoods = Db::name('order_goods')->where('source_line_type', 'customer_report_item')
            ->where('source_line_id', (int)$line['id'])->find();
        self::assertNotEmpty($orderGoods);
        self::assertSame((int)$sku['id'], (int)$orderGoods['sku_id']);
        self::assertSame((string)$sku['sku_name'], (string)$orderGoods['sku_name']);
        $salesDetail = SalesOrderLogic::detail(['id' => (int)$orderGoods['order_id']]);
        self::assertSame((string)$sku['sku_name'], (string)$salesDetail['goods'][0]['sku_name']);
        self::assertSame('pending', (string)$salesDetail['settlement_status']);
        self::assertSame('0.00', (string)$salesDetail['order_money']);
        self::assertSame(0, Db::name('receivable_flow')->where('tenant_id', self::TENANT_ID)->count());
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

    public function test_self_delivery_handoff_uses_final_weight_and_attributes_true_negative_stock_once(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-negative-once', '1.20', '1.50');

        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $fixture['delivery_task_id'],
            'event_type' => 'vehicle_departed',
            'idempotency_key' => 'delivery-vehicle-is-not-handoff',
        ]));
        self::assertSame('1.2000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());

        $params = [
            'task_id' => $fixture['delivery_task_id'],
            'event_type' => 'customer_handoff',
            'handoff_note' => '客户本人现场签收',
            'idempotency_key' => 'delivery-negative-once-event',
        ];
        $first = DeliveryInventoryLogic::confirmSelfDelivery($params);
        self::assertNotFalse($first, DeliveryInventoryLogic::getError());
        self::assertSame('completed', $first['status']);
        self::assertSame('1.5000', $first['items'][0]['actual_delivery_weight']);
        self::assertSame('0.3000', $first['items'][0]['negative_qty']);
        self::assertSame('-0.3000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('0.0000', WarehouseSkuBalanceService::reserved($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)
            ->where('order_type', 'sales_delivery')->where('quantity', '1.5000')->count());

        $attribution = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->find();
        self::assertNotEmpty($attribution);
        self::assertSame((int)$fixture['report_id'], (int)$attribution['report_id']);
        self::assertSame((int)$fixture['report_item_id'], (int)$attribution['report_item_id']);
        self::assertSame((int)$fixture['warehouse_id'], (int)$attribution['warehouse_id']);
        self::assertSame((int)$fixture['sku_id'], (int)$attribution['sku_id']);
        self::assertSame('0.3000', (string)$attribution['negative_qty']);
        self::assertGreaterThan(0, (int)$attribution['sales_order_id']);
        self::assertSame('最终实重超过可用库存', (string)$attribution['reason']);
        self::assertSame(1, Db::name('negative_inventory_todo')->where('attribution_id', (int)$attribution['id'])
            ->where('status', 'open')->where('assignee_scope', 'highest_privilege')->count());
        self::assertSame('pending', (string)Db::name('sales_order')->where('id', (int)$attribution['sales_order_id'])->value('settlement_status'));
        self::assertSame('1.5000', (string)Db::name('order_goods')->where('order_id', (int)$attribution['sales_order_id'])
            ->where('source_line_id', (int)$fixture['report_item_id'])->value('base_quantity'));

        $replayed = DeliveryInventoryLogic::confirmSelfDelivery($params);
        self::assertNotFalse($replayed, DeliveryInventoryLogic::getError());
        self::assertSame((int)$first['id'], (int)$replayed['id']);
        self::assertSame(1, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales_delivery')->count());
        self::assertSame(1, Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->count());

        $conflict = $params;
        $conflict['handoff_note'] = '同一幂等键的不同交付事实';
        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery($conflict));
        self::assertSame('同一幂等键不能提交不同的交付事实', DeliveryInventoryLogic::getError());
    }

    public function test_negative_threshold_requires_explanation_and_second_confirmation_without_partial_writes(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-threshold', '1.00', '1.40');
        Db::name('goods')->where('id', $fixture['goods_id'])->update(['cost' => '10.00']);
        Db::name('negative_inventory_setting')->insert([
            'tenant_id' => self::TENANT_ID,
            'quantity_threshold' => '99.0000',
            'amount_threshold' => '1.00',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $params = [
            'task_id' => $fixture['delivery_task_id'],
            'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-threshold-event',
        ];

        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery($params));
        self::assertSame('负库存超过配置阈值，必须填写说明并二次确认', DeliveryInventoryLogic::getError());
        self::assertSame('1.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('1.0000', WarehouseSkuBalanceService::reserved($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());

        $params['exception_reason'] = '称重结果超过系统库存，已复核纸票与客户货物';
        $params['second_confirmed'] = 1;
        self::assertNotFalse(DeliveryInventoryLogic::confirmSelfDelivery($params), DeliveryInventoryLogic::getError());
        self::assertSame('4.00', (string)Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->value('negative_amount'));
    }

    public function test_self_delivery_below_reserved_weight_releases_excess_without_creating_negative_todo(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-release-excess', '1.00', '0.60');
        $delivered = DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-release-excess-event',
        ]);
        self::assertNotFalse($delivered, DeliveryInventoryLogic::getError());
        self::assertSame('0.6000', $delivered['items'][0]['actual_delivery_weight']);
        self::assertSame('0.6000', $delivered['items'][0]['reservation_consumed_qty']);
        self::assertSame('0.4000', $delivered['items'][0]['reservation_released_qty']);
        self::assertSame('0.0000', $delivered['items'][0]['negative_qty']);
        self::assertSame('0.4000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('0.0000', WarehouseSkuBalanceService::reserved($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame(0, Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('negative_inventory_todo')->where('tenant_id', self::TENANT_ID)->count());
        $reservation = Db::name('customer_report_reservation')->where('report_item_id', $fixture['report_item_id'])->find();
        self::assertSame('0.60', (string)$reservation['consumed_base_qty']);
        self::assertSame('0.40', (string)$reservation['released_base_qty']);
    }

    public function test_self_delivery_attributes_negative_available_stock_reserved_by_another_order(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-other-reservation', '2.00', '1.50');
        self::assertNotFalse(WarehouseSkuBalanceService::reserve(
            $fixture['warehouse_id'], $fixture['sku_id'], '1.0000'
        ));
        self::assertSame('0.0000', WarehouseSkuBalanceService::available($fixture['warehouse_id'], $fixture['sku_id']));

        $delivered = DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-other-reservation-event',
        ]);
        self::assertNotFalse($delivered, DeliveryInventoryLogic::getError());
        self::assertSame('0.5000', $delivered['items'][0]['negative_qty']);
        self::assertSame('0.5000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('1.0000', WarehouseSkuBalanceService::reserved($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('-0.5000', WarehouseSkuBalanceService::available($fixture['warehouse_id'], $fixture['sku_id']));

        $source = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->find();
        self::assertSame('0.5000', (string)$source['negative_qty']);
        self::assertNotFalse(NegativeInventoryLogic::resolve([
            'id' => (int)$source['id'], 'action' => 'wait_inbound',
            'reason' => '等待其他订单占用库存补回', 'idempotency_key' => 'other-reservation-wait',
        ]), NegativeInventoryLogic::getError());
        self::assertTrue(StockService::inbound(
            $fixture['warehouse_id'], $fixture['goods_id'], '0.5000', 77882,
            'supply', 'SUP-OTHER-RESERVATION', '后续入库补平负可用量', $fixture['sku_id']
        ));
        self::assertSame('0.0000', WarehouseSkuBalanceService::available($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('resolved', (string)Db::name('negative_inventory_attribution')
            ->where('id', (int)$source['id'])->value('resolution_status'));
    }

    public function test_later_delivery_reservation_release_audits_and_offsets_an_earlier_negative_source(): void
    {
        $customerId = $this->createCustomer('跨订单释放预留客户');
        $goodsId = $this->createCustomerReportGoods('跨订单释放预留商品', 'DELIVERY-RELEASE-OFFSET');
        $warehouseId = $this->createCustomerReportWarehouse('跨订单释放预留仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $first = $this->selfDeliveryFixtureForCatalog(
            $customerId, $goodsId, $warehouseId, 'delivery-release-offset-a', '1.50'
        );
        $second = $this->selfDeliveryFixtureForCatalog(
            $customerId, $goodsId, $warehouseId, 'delivery-release-offset-b', '0.50'
        );
        self::assertSame('2.0000', WarehouseSkuBalanceService::reserved($warehouseId, $first['sku_id']));
        self::assertSame('0.0000', WarehouseSkuBalanceService::available($warehouseId, $first['sku_id']));

        $firstDelivery = DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $first['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-release-offset-event-a',
        ]);
        self::assertNotFalse($firstDelivery, DeliveryInventoryLogic::getError());
        self::assertSame('0.5000', $firstDelivery['items'][0]['negative_qty']);
        $source = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->find();
        self::assertSame('0.5000', (string)$source['remaining_qty']);
        self::assertSame('-0.5000', WarehouseSkuBalanceService::available($warehouseId, $first['sku_id']));

        $secondDelivery = DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $second['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-release-offset-event-b',
        ]);
        self::assertNotFalse($secondDelivery, DeliveryInventoryLogic::getError());
        self::assertSame('0.0000', $secondDelivery['items'][0]['negative_qty']);
        self::assertSame('0.0000', WarehouseSkuBalanceService::available($warehouseId, $first['sku_id']));
        $resolved = Db::name('negative_inventory_attribution')->where('id', (int)$source['id'])->find();
        self::assertSame('0.5000', (string)$resolved['negative_qty'], '不可覆盖原负库存来源数量');
        self::assertSame('0.0000', (string)$resolved['remaining_qty']);
        self::assertSame('resolved', (string)$resolved['resolution_status']);
        self::assertSame('closed', (string)Db::name('negative_inventory_todo')
            ->where('attribution_id', (int)$source['id'])->value('status'));
        self::assertSame(1, Db::name('negative_inventory_action')->where('attribution_id', (int)$source['id'])
            ->where('action_type', 'reservation_release_offset')->where('quantity', '0.5000')->count());
    }

    public function test_same_delivery_event_offsets_an_earlier_same_sku_line_before_finishing(): void
    {
        $customerId = $this->createCustomer('同事件同 SKU 客户');
        $goodsId = $this->createCustomerReportGoods('同事件同 SKU 商品', 'DELIVERY-SAME-EVENT-SKU');
        $warehouseId = $this->createCustomerReportWarehouse('同事件同 SKU 仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $payload = $this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'delivery-same-event-sku-report', '1', '杀好'
        );
        $payload['items'][] = $payload['items'][0];
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        self::assertCount(2, $report['items']);
        foreach (array_values($report['items']) as $index => $item) {
            $weight = $index === 0 ? '1.50' : '0.50';
            $finalTask = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
                ->where('report_item_id', (int)$item['id'])->where('is_settlement_task', 1)->find();
            self::assertNotEmpty($finalTask);
            Db::name('fulfillment_task')->where('id', (int)$finalTask['id'])->update([
                'status' => 'recovered', 'actual_weight' => $weight, 'process_weight' => $weight,
                'actual_price' => '20.00', 'recovered_time' => time(), 'update_time' => time(),
            ]);
            Db::name('customer_report_item')->where('id', (int)$item['id'])->update([
                'final_actual_weight' => $weight, 'final_weight_task_id' => (int)$finalTask['id'],
                'fulfillment_status' => 'final_weight_recorded', 'update_time' => time(),
            ]);
            FulfillmentTaskLogic::refreshGroupForItem((int)$item['id']);
        }
        $deliveryTask = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$report['id'])
            ->where('source_key', 'report:' . (int)$report['id'] . ':delivery')->find();
        self::assertNotEmpty($deliveryTask);
        self::assertNotSame('blocked', (string)$deliveryTask['status']);
        Db::name('negative_inventory_setting')->insert([
            'tenant_id' => self::TENANT_ID,
            'quantity_threshold' => '0.1000',
            'amount_threshold' => '0.10',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $delivery = DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => (int)$deliveryTask['id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-same-event-sku-event',
        ]);
        self::assertNotFalse($delivery, DeliveryInventoryLogic::getError());
        self::assertSame(['0.5000', '0.0000'], array_column($delivery['items'], 'negative_qty'));
        self::assertSame('0.0000', WarehouseSkuBalanceService::available(
            $warehouseId, (int)$report['items'][0]['sku_id']
        ));
        $source = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->find();
        self::assertSame('0.5000', (string)$source['negative_qty']);
        self::assertSame('0.0000', (string)$source['remaining_qty']);
        self::assertSame('resolved', (string)$source['resolution_status']);
        self::assertSame('closed', (string)Db::name('negative_inventory_todo')
            ->where('attribution_id', (int)$source['id'])->value('status'));
        self::assertSame(1, Db::name('negative_inventory_action')->where('attribution_id', (int)$source['id'])
            ->where('action_type', 'reservation_release_offset')->where('quantity', '0.5000')->count());
    }

    public function test_delivery_business_failure_rolls_back_the_event_and_all_side_effects(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-existing-formal-order', '1.00', '1.00');
        $report = Db::name('customer_report')->where('id', $fixture['report_id'])->find();
        $now = time();
        Db::name('sales_order')->insert([
            'tenant_id' => self::TENANT_ID,
            'order_sn' => 'SO-FORMAL-BEFORE-DELIVERY',
            'customer_id' => (int)$report['main_customer_id'],
            'customer_name' => (string)$report['main_customer_name'],
            'warehouse_id' => $fixture['warehouse_id'],
            'order_money' => '10.00',
            'order_pay_money' => '0.00',
            'order_arrears_money' => '10.00',
            'datetimesingle' => $now,
            'source_type' => 'customer_report',
            'source_id' => $fixture['report_id'],
            'source_version' => 1,
            'settlement_status' => 'formal',
            'cost_status' => 'confirmed',
            'profit_status' => 'accurate',
            'status' => 1,
            'admin_id' => self::ADMIN_ID,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-existing-formal-order-event',
        ]));
        self::assertSame('该报货单已存在正式销售单，不能重复交付出库', DeliveryInventoryLogic::getError());
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('fulfillment_delivery_item')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)
            ->where('order_type', 'sales_delivery')->count());
        self::assertSame('1.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('1.0000', WarehouseSkuBalanceService::reserved($fixture['warehouse_id'], $fixture['sku_id']));
    }

    public function test_concurrent_same_delivery_key_returns_one_event_and_one_inventory_side_effect(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-concurrent', '1.00', '1.20');
        $delivery = [
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'handoff_note' => '并发确认同一次客户交接', 'idempotency_key' => 'delivery-concurrent-event',
        ];
        $paths = [];
        $startPath = tempnam(sys_get_temp_dir(), 'delivery-start-');
        unlink($startPath);
        try {
            $processes = [];
            for ($worker = 0; $worker < 2; $worker++) {
                $inputPath = tempnam(sys_get_temp_dir(), 'delivery-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'delivery-output-');
                $paths[] = $inputPath;
                $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode([
                    'tenant_id' => self::TENANT_ID, 'admin_id' => self::ADMIN_ID, 'delivery' => $delivery,
                ], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(dirname(__DIR__) . '/fixtures/delivery_inventory_worker.php') . ' '
                    . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' ' . escapeshellarg($startPath);
                $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $outputPath];
            }
            usleep(100_000);
            touch($startPath);
            foreach ($processes as $index => [$process, $pipes]) {
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $processes[$index][3] = $stdout;
                $processes[$index][4] = $stderr;
                self::assertSame(0, proc_close($process), '并发交付进程失败：stdout=' . $stdout . '; stderr=' . $stderr);
            }
            $responses = array_map(static function (array $process): array {
                $response = json_decode((string)file_get_contents($process[2]), true) ?: [];
                $response['_stdout'] = (string)($process[3] ?? '');
                $response['_stderr'] = (string)($process[4] ?? '');
                return $response;
            }, $processes);
            $diagnostic = json_encode($responses, JSON_UNESCAPED_UNICODE);
            self::assertNotEmpty($responses[0]['result'], $diagnostic);
            self::assertNotEmpty($responses[1]['result'], $diagnostic);
            self::assertSame((int)$responses[0]['result']['id'], (int)$responses[1]['result']['id'], $diagnostic);
            self::assertSame(1, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
            self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales_delivery')->count());
            self::assertSame(1, Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->count());
            self::assertSame('-0.2000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        } finally {
            if (is_file($startPath)) { unlink($startPath); }
            foreach ($paths as $path) { if (is_file($path)) { unlink($path); } }
        }
    }

    public function test_two_reports_can_concurrently_create_one_missing_sku_balance_in_canonical_order(): void
    {
        $customerId = $this->createCustomer('无余额并发交付客户');
        $goodsId = $this->createCustomerReportGoods('无余额并发交付商品', 'DELIVERY-MISSING-BALANCE');
        $warehouseId = $this->createCustomerReportWarehouse('无余额并发交付仓');
        $first = $this->selfDeliveryFixtureForCatalog(
            $customerId, $goodsId, $warehouseId, 'delivery-missing-balance-a', '0.40'
        );
        $second = $this->selfDeliveryFixtureForCatalog(
            $customerId, $goodsId, $warehouseId, 'delivery-missing-balance-b', '0.40'
        );
        self::assertSame($first['sku_id'], $second['sku_id']);
        self::assertSame(0, Db::name('warehouse_sku_balance')->where('tenant_id', self::TENANT_ID)
            ->where('warehouse_id', $warehouseId)->where('sku_id', $first['sku_id'])->count());

        $startPath = tempnam(sys_get_temp_dir(), 'delivery-missing-start-');
        unlink($startPath);
        $paths = [];
        try {
            $processes = [];
            foreach ([$first, $second] as $index => $fixture) {
                $inputPath = tempnam(sys_get_temp_dir(), 'delivery-missing-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'delivery-missing-output-');
                $paths[] = $inputPath;
                $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode([
                    'tenant_id' => self::TENANT_ID,
                    'admin_id' => self::ADMIN_ID,
                    'delivery' => [
                        'task_id' => $fixture['delivery_task_id'],
                        'event_type' => 'customer_handoff',
                        'idempotency_key' => 'delivery-missing-balance-event-' . $index,
                    ],
                ], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(dirname(__DIR__) . '/fixtures/delivery_inventory_worker.php') . ' '
                    . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' ' . escapeshellarg($startPath);
                $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $outputPath];
            }
            usleep(100_000);
            touch($startPath);
            foreach ($processes as $index => [$process, $pipes]) {
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), '无余额并发交付失败：stdout=' . $stdout . '; stderr=' . $stderr);
                $response = json_decode((string)file_get_contents($processes[$index][2]), true) ?: [];
                self::assertNotEmpty($response['result'], json_encode($response, JSON_UNESCAPED_UNICODE));
            }
            self::assertSame(2, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
            self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)
                ->where('order_type', 'sales_delivery')->count());
            self::assertSame(2, Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->count());
            self::assertSame('-0.8000', WarehouseSkuBalanceService::onHand($warehouseId, $first['sku_id']));
            self::assertSame('0.0000', WarehouseSkuBalanceService::reserved($warehouseId, $first['sku_id']));
            self::assertSame('-0.8000', WarehouseSkuBalanceService::available($warehouseId, $first['sku_id']));
        } finally {
            if (is_file($startPath)) { unlink($startPath); }
            foreach ($paths as $path) { if (is_file($path)) { unlink($path); } }
        }
    }

    public function test_waiting_negative_inventory_is_auto_offset_by_later_inbound_without_erasing_source(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-auto-offset', '1.00', '1.30');
        $delivery = DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-auto-offset-event',
        ]);
        self::assertNotFalse($delivery, DeliveryInventoryLogic::getError());
        $attribution = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->find();

        $waiting = NegativeInventoryLogic::resolve([
            'id' => (int)$attribution['id'], 'action' => 'wait_inbound',
            'reason' => '等待当天采购到货', 'idempotency_key' => 'negative-wait-inbound',
        ]);
        self::assertNotFalse($waiting, NegativeInventoryLogic::getError());
        self::assertSame('waiting_inbound', $waiting['resolution_status']);

        self::assertTrue(StockService::inbound(
            $fixture['warehouse_id'], $fixture['goods_id'], '0.3000', 77881,
            'supply', 'SUP-AUTO-OFFSET', '采购到货自动补平负库存', $fixture['sku_id']
        ));
        $after = Db::name('negative_inventory_attribution')->where('id', (int)$attribution['id'])->find();
        self::assertSame('0.3000', (string)$after['negative_qty'], '负库存来源数量必须保持不可变');
        self::assertSame('0.0000', (string)$after['remaining_qty']);
        self::assertSame('resolved', (string)$after['resolution_status']);
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame(1, Db::name('negative_inventory_action')->where('attribution_id', (int)$attribution['id'])
            ->where('action_type', 'auto_offset')->where('quantity', '0.3000')->count());
        self::assertSame('closed', (string)Db::name('negative_inventory_todo')->where('attribution_id', (int)$attribution['id'])->value('status'));
    }

    public function test_concurrent_same_negative_resolution_key_appends_one_action(): void
    {
        $fixture = $this->selfDeliveryFixture('negative-resolution-concurrent', '1.00', '1.20');
        self::assertNotFalse(DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'negative-resolution-source-event',
        ]), DeliveryInventoryLogic::getError());
        $attributionId = (int)Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->value('id');
        $resolution = [
            'id' => $attributionId, 'action' => 'wait_inbound', 'reason' => '并发选择等待入库',
            'idempotency_key' => 'negative-resolution-concurrent-action',
        ];
        $paths = [];
        $startPath = tempnam(sys_get_temp_dir(), 'negative-start-');
        unlink($startPath);
        try {
            $processes = [];
            for ($worker = 0; $worker < 2; $worker++) {
                $inputPath = tempnam(sys_get_temp_dir(), 'negative-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'negative-output-');
                $paths[] = $inputPath;
                $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode([
                    'tenant_id' => self::TENANT_ID, 'admin_id' => self::ADMIN_ID, 'resolution' => $resolution,
                ], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(dirname(__DIR__) . '/fixtures/negative_inventory_worker.php') . ' '
                    . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' ' . escapeshellarg($startPath);
                $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $outputPath];
            }
            usleep(100_000);
            touch($startPath);
            foreach ($processes as $index => [$process, $pipes]) {
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $processes[$index][3] = $stdout;
                $processes[$index][4] = $stderr;
                self::assertSame(0, proc_close($process), '并发负库存处理进程失败：stdout=' . $stdout . '; stderr=' . $stderr);
            }
            $responses = array_map(static function (array $process): array {
                $response = json_decode((string)file_get_contents($process[2]), true) ?: [];
                $response['_stdout'] = (string)($process[3] ?? '');
                $response['_stderr'] = (string)($process[4] ?? '');
                return $response;
            }, $processes);
            $diagnostic = json_encode($responses, JSON_UNESCAPED_UNICODE);
            self::assertNotEmpty($responses[0]['result'], $diagnostic);
            self::assertNotEmpty($responses[1]['result'], $diagnostic);
            self::assertSame('waiting_inbound', (string)$responses[0]['result']['resolution_status'], $diagnostic);
            self::assertSame('waiting_inbound', (string)$responses[1]['result']['resolution_status'], $diagnostic);
            self::assertSame(1, Db::name('negative_inventory_action')->where('tenant_id', self::TENANT_ID)
                ->where('idempotency_key', 'negative-resolution-concurrent-action')->count());
        } finally {
            if (is_file($startPath)) { unlink($startPath); }
            foreach ($paths as $path) { if (is_file($path)) { unlink($path); } }
        }
    }

    public function test_concurrent_regular_inbound_and_negative_resolution_share_one_lock_order(): void
    {
        $fixture = $this->selfDeliveryFixture('negative-inbound-lock-order', '1.00', '1.20');
        self::assertNotFalse(DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'negative-inbound-lock-source-event',
        ]), DeliveryInventoryLogic::getError());
        $source = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->find();
        self::assertNotFalse(NegativeInventoryLogic::resolve([
            'id' => (int)$source['id'], 'action' => 'wait_inbound',
            'reason' => '并发锁序测试先等待入库', 'idempotency_key' => 'negative-inbound-lock-wait',
        ]), NegativeInventoryLogic::getError());

        $startPath = tempnam(sys_get_temp_dir(), 'negative-inbound-start-');
        unlink($startPath);
        $paths = [];
        try {
            $jobs = [
                [
                    'worker' => dirname(__DIR__) . '/fixtures/negative_inventory_worker.php',
                    'payload' => [
                        'tenant_id' => self::TENANT_ID, 'admin_id' => self::ADMIN_ID,
                        'resolution' => [
                            'id' => (int)$source['id'], 'action' => 'record_missing_inbound',
                            'quantity' => '0.1000', 'amount' => '3.00',
                            'reason' => '并发补录一半遗漏入库',
                            'idempotency_key' => 'negative-inbound-lock-manual',
                        ],
                    ],
                ],
                [
                    'worker' => dirname(__DIR__) . '/fixtures/stock_inbound_worker.php',
                    'payload' => [
                        'tenant_id' => self::TENANT_ID, 'admin_id' => self::ADMIN_ID,
                        'inbound' => [
                            'warehouse_id' => $fixture['warehouse_id'], 'goods_id' => $fixture['goods_id'],
                            'sku_id' => $fixture['sku_id'], 'quantity' => '0.1000', 'order_id' => 77883,
                            'order_type' => 'supply', 'order_sn' => 'SUP-LOCK-ORDER',
                            'remark' => '并发普通采购入库',
                        ],
                    ],
                ],
            ];
            $processes = [];
            foreach ($jobs as $job) {
                $inputPath = tempnam(sys_get_temp_dir(), 'negative-inbound-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'negative-inbound-output-');
                $paths[] = $inputPath;
                $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode($job['payload'], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($job['worker']) . ' '
                    . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' ' . escapeshellarg($startPath);
                $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $outputPath];
            }
            usleep(100_000);
            touch($startPath);
            foreach ($processes as $index => [$process, $pipes]) {
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), '锁序并发进程失败：stdout=' . $stdout . '; stderr=' . $stderr);
                $response = json_decode((string)file_get_contents($processes[$index][2]), true) ?: [];
                self::assertNotEmpty($response['result'], json_encode($response, JSON_UNESCAPED_UNICODE));
            }
            $after = Db::name('negative_inventory_attribution')->where('id', (int)$source['id'])->find();
            self::assertSame('0.0000', (string)$after['remaining_qty']);
            self::assertSame('resolved', (string)$after['resolution_status']);
            self::assertSame('0.0000', WarehouseSkuBalanceService::available($fixture['warehouse_id'], $fixture['sku_id']));
            self::assertSame(1, Db::name('negative_inventory_action')->where('attribution_id', (int)$source['id'])
                ->where('action_type', 'record_missing_inbound')->count());
            self::assertSame(1, Db::name('negative_inventory_action')->where('attribution_id', (int)$source['id'])
                ->where('action_type', 'auto_offset')->count());
        } finally {
            if (is_file($startPath)) { unlink($startPath); }
            foreach ($paths as $path) { if (is_file($path)) { unlink($path); } }
        }
    }

    public function test_inventory_writeoff_is_audited_and_can_leave_profit_cost_pending(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-writeoff', '1.00', '1.25');
        self::assertNotFalse(DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-writeoff-event',
        ]), DeliveryInventoryLogic::getError());
        $attribution = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->find();

        $resolved = NegativeInventoryLogic::resolve([
            'id' => (int)$attribution['id'], 'action' => 'inventory_writeoff',
            'quantity' => '0.2500', 'reason' => '复盘确认是称重与盘点差异',
            'cost_status' => 'pending', 'idempotency_key' => 'negative-writeoff-action',
        ]);
        self::assertNotFalse($resolved, NegativeInventoryLogic::getError());
        self::assertSame('resolved', $resolved['resolution_status']);
        self::assertSame('pending', $resolved['cost_status']);
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame(1, Db::name('negative_inventory_action')->where('attribution_id', (int)$attribution['id'])
            ->where('action_type', 'inventory_writeoff')->where('reason', '复盘确认是称重与盘点差异')->count());
        self::assertSame('cost_pending', (string)Db::name('sales_order')->where('id', (int)$attribution['sales_order_id'])->value('profit_status'));
        self::assertSame('pending', (string)Db::name('sales_order')->where('id', (int)$attribution['sales_order_id'])->value('cost_status'));

        $replayed = NegativeInventoryLogic::resolve([
            'id' => (int)$attribution['id'], 'action' => 'inventory_writeoff',
            'quantity' => '0.2500', 'reason' => '复盘确认是称重与盘点差异',
            'cost_status' => 'pending', 'idempotency_key' => 'negative-writeoff-action',
        ]);
        self::assertNotFalse($replayed, NegativeInventoryLogic::getError());
        self::assertSame(1, Db::name('negative_inventory_action')->where('tenant_id', self::TENANT_ID)
            ->where('idempotency_key', 'negative-writeoff-action')->count());
    }

    public function test_retained_negative_inventory_can_later_be_closed_by_recording_missing_inbound(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-missing-inbound', '1.00', '1.20');
        self::assertNotFalse(DeliveryInventoryLogic::confirmSelfDelivery([
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-missing-inbound-event',
        ]), DeliveryInventoryLogic::getError());
        $attribution = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->find();

        $retained = NegativeInventoryLogic::resolve([
            'id' => (int)$attribution['id'], 'action' => 'retain',
            'reason' => '先保留负数等待采购单据核对', 'idempotency_key' => 'negative-retain-action',
        ]);
        self::assertNotFalse($retained, NegativeInventoryLogic::getError());
        self::assertSame('retained', $retained['resolution_status']);
        self::assertSame('-0.2000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));

        $recorded = NegativeInventoryLogic::resolve([
            'id' => (int)$attribution['id'], 'action' => 'record_missing_inbound',
            'quantity' => '0.2000', 'amount' => '6.00', 'reason' => '补录漏记采购入库',
            'cost_status' => 'confirmed', 'idempotency_key' => 'negative-missing-inbound-action',
        ]);
        self::assertNotFalse($recorded, NegativeInventoryLogic::getError());
        self::assertSame('resolved', $recorded['resolution_status']);
        self::assertSame('confirmed', $recorded['cost_status']);
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame(1, Db::name('negative_inventory_action')->where('attribution_id', (int)$attribution['id'])
            ->where('action_type', 'record_missing_inbound')->where('amount', '6.00')->count());
        self::assertSame(1, Db::name('stock_flow')->where('order_type', 'negative_inventory_resolution')
            ->where('order_id', (int)$attribution['id'])->where('quantity', '0.2000')->count());
    }

    public function test_delivery_outbound_rolls_back_balance_order_flow_and_event_when_attribution_write_fails(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-rollback', '1.00', '1.20');
        Db::execute('ALTER TABLE `la_negative_inventory_attribution` ADD CONSTRAINT `chk_test_negative_attribution_fail` CHECK (`negative_qty` < 0)');
        try {
            self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery([
                'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
                'idempotency_key' => 'delivery-rollback-event',
            ]));
        } finally {
            Db::execute('ALTER TABLE `la_negative_inventory_attribution` DROP CONSTRAINT `chk_test_negative_attribution_fail`');
        }

        self::assertSame('1.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('1.0000', WarehouseSkuBalanceService::reserved($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('negative_inventory_todo')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_delivery_confirmation_rejects_cross_tenant_ids_and_missing_electronic_permission(): void
    {
        $fixture = $this->selfDeliveryFixture('delivery-tenant-permission', '1.00', '1.00');
        $params = [
            'task_id' => $fixture['delivery_task_id'], 'event_type' => 'customer_handoff',
            'idempotency_key' => 'delivery-tenant-permission-event',
        ];

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery($params));
        self::assertSame('送货任务不存在', DeliveryInventoryLogic::getError());

        $this->prepareCustomerReportRequestContext();
        request()->adminInfo = ['root' => 0];
        request()->adminId = 0;
        request()->userId = 0;
        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery($params));
        self::assertSame('没有执行该操作的电子权限', DeliveryInventoryLogic::getError());
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
        $this->prepareCustomerReportRequestContext();
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

    /** @return array{report_id:int,report_item_id:int,delivery_task_id:int,warehouse_id:int,goods_id:int,sku_id:int} */
    private function selfDeliveryFixture(string $key, string $stock, string $finalWeight): array
    {
        $customerId = $this->createCustomer('自配送客户-' . $key);
        $goodsId = $this->createCustomerReportGoods('自配送商品-' . $key, 'DELIVERY-' . $key);
        $warehouseId = $this->createCustomerReportWarehouse('自配送仓-' . $key);
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, $stock));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, $key, '1', '杀好'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $item = $report['items'][0];
        $finalTask = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_item_id', (int)$item['id'])->where('is_settlement_task', 1)->find();
        self::assertNotEmpty($finalTask);
        Db::name('fulfillment_task')->where('id', (int)$finalTask['id'])->update([
            'status' => 'recovered', 'actual_weight' => $finalWeight, 'process_weight' => $finalWeight,
            'actual_price' => '20.00', 'recovered_time' => time(), 'update_time' => time(),
        ]);
        Db::name('customer_report_item')->where('id', (int)$item['id'])->update([
            'final_actual_weight' => $finalWeight, 'final_weight_task_id' => (int)$finalTask['id'],
            'fulfillment_status' => 'final_weight_recorded', 'update_time' => time(),
        ]);
        FulfillmentTaskLogic::refreshGroupForItem((int)$item['id']);
        $deliveryTask = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$report['id'])->where('source_key', 'report:' . (int)$report['id'] . ':delivery')->find();
        self::assertNotEmpty($deliveryTask);
        self::assertNotSame('blocked', (string)$deliveryTask['status']);
        return [
            'report_id' => (int)$report['id'], 'report_item_id' => (int)$item['id'],
            'delivery_task_id' => (int)$deliveryTask['id'], 'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId, 'sku_id' => (int)$item['sku_id'],
        ];
    }

    /** @return array{report_id:int,report_item_id:int,delivery_task_id:int,warehouse_id:int,goods_id:int,sku_id:int} */
    private function selfDeliveryFixtureForCatalog(
        int $customerId,
        int $goodsId,
        int $warehouseId,
        string $key,
        string $finalWeight
    ): array {
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, $key, '1', '杀好'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $item = $report['items'][0];
        $finalTask = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_item_id', (int)$item['id'])->where('is_settlement_task', 1)->find();
        self::assertNotEmpty($finalTask);
        Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_item_id', (int)$item['id'])->whereLike('source_key', '%:shortage')
            ->update(['status' => 'recovered', 'recovered_time' => time(), 'update_time' => time()]);
        Db::name('fulfillment_task')->where('id', (int)$finalTask['id'])->update([
            'status' => 'recovered', 'actual_weight' => $finalWeight, 'process_weight' => $finalWeight,
            'actual_price' => '20.00', 'recovered_time' => time(), 'update_time' => time(),
        ]);
        Db::name('customer_report_item')->where('id', (int)$item['id'])->update([
            'final_actual_weight' => $finalWeight, 'final_weight_task_id' => (int)$finalTask['id'],
            'fulfillment_status' => 'final_weight_recorded', 'update_time' => time(),
        ]);
        FulfillmentTaskLogic::refreshGroupForItem((int)$item['id']);
        $deliveryTask = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$report['id'])
            ->where('source_key', 'report:' . (int)$report['id'] . ':delivery')->find();
        self::assertNotEmpty($deliveryTask);
        Db::name('fulfillment_task')->where('id', (int)$deliveryTask['id'])->update([
            'status' => 'printable', 'update_time' => time(),
        ]);
        return [
            'report_id' => (int)$report['id'], 'report_item_id' => (int)$item['id'],
            'delivery_task_id' => (int)$deliveryTask['id'], 'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId, 'sku_id' => (int)$item['sku_id'],
        ];
    }
}
