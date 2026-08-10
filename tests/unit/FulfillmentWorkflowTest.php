<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\logic\WorkforceLogic;
use app\api\jxc\logic\WarehouseGoodsBalanceService;
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

        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-idem-1', '2', '杀好、活鱼打包');
        $first = CustomerReportLogic::submit($payload);
        self::assertNotFalse($first, CustomerReportLogic::getError());
        self::assertSame('2026-08-10', $first['delivery_date']);
        self::assertSame(1, (int)$first['is_supplement']);

        $tasks = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$first['id'])->order('id')->select()->toArray();
        self::assertCount(5, $tasks, '采购、杀鱼、活鱼打包、送货、记账各一项');
        self::assertSame(1, count(array_filter($tasks, static fn(array $task): bool => $task['source_key'] === 'item:' . $first['items'][0]['id'] . ':shortage')));
        self::assertSame(2, count(array_filter($tasks, static fn(array $task): bool => (int)$task['report_item_id'] === (int)$first['items'][0]['id'] && $task['task_type'] === 'process')));

        $again = CustomerReportLogic::submit($payload);
        self::assertNotFalse($again, CustomerReportLogic::getError());
        self::assertSame(5, Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)->where('report_id', (int)$first['id'])->count());
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
        $cancelled = CustomerReportLogic::cancel(['id' => $submitted['id'], 'version' => $submitted['version']]);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        $replayed = CustomerReportLogic::submit($payload);
        self::assertNotFalse($replayed, CustomerReportLogic::getError());
        self::assertSame('cancelled', $replayed['status']);
        self::assertSame(0, Db::name('fulfillment_task')->where('report_id', (int)$submitted['id'])->whereNotIn('status', ['cancelled', 'recovered', 'ready_to_bill', 'completed'])->count());
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
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.00'));
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'task-bill-gate', '1', '杀好'));
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
            'is_supplement' => 1,
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
