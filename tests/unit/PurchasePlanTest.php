<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportBatchLogic;
use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\logic\PurchasePlanLogic;
use app\api\jxc\logic\PendingGoodsLogic;
use app\api\jxc\logic\PurchaseReturnOrderLogic;
use app\api\jxc\logic\PurchaseBatchLogic;
use app\api\jxc\logic\SupplyOrderLogic;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class PurchasePlanTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        $this->createCustomerReportUnit('斤');
    }

    protected function tearDown(): void
    {
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_first_purchase_print_prepares_one_plan_and_reprint_does_not_duplicate_source(): void
    {
        self::assertNotFalse(WorkforceLogic::saveProcess([
            'name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1,
        ]), WorkforceLogic::getError());
        $customerId = $this->createCustomer('自动采购客户');
        $goodsId = $this->createCustomerReportGoods('自动采购鲈鱼', 'PRINT-PLAN-BASS');
        $warehouseId = $this->createCustomerReportWarehouse('自动采购仓');
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'purchase-plan-on-print', '4', '20'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $taskId = $this->shortageTaskId($report);
        self::assertSame(0, Db::name('purchase_plan_source')->where('tenant_id', self::TENANT_ID)
            ->where('task_id', $taskId)->count());

        $print = FulfillmentTaskLogic::printData(['id' => $taskId]);
        self::assertNotFalse($print, FulfillmentTaskLogic::getError());
        $source = Db::name('purchase_plan_source')->where('tenant_id', self::TENANT_ID)
            ->where('task_id', $taskId)->find();
        self::assertNotFalse($source);
        $planId = (int)$source['purchase_plan_id'];
        self::assertGreaterThan(0, $planId);
        self::assertSame((int)$print['print_log_id'],
            (int)FulfillmentTaskLogic::pendingPrint(['id' => $taskId])['print_log_id']);
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => $taskId, 'print_log_id' => $print['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());

        $reprint = FulfillmentTaskLogic::printData(['id' => $taskId]);
        self::assertNotFalse($reprint, FulfillmentTaskLogic::getError());
        self::assertSame(1, Db::name('purchase_plan_source')->where('tenant_id', self::TENANT_ID)
            ->where('task_id', $taskId)->where('purchase_plan_id', $planId)->count());
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => $taskId, 'print_log_id' => $reprint['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());
        self::assertNotFalse(PurchasePlanLogic::terminate(['id' => $planId, 'reason' => '供应商不再供货']), PurchasePlanLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::printData(['id' => $taskId]), FulfillmentTaskLogic::getError());
        self::assertSame(0, Db::name('purchase_plan_source')->alias('s')
            ->join('purchase_plan p', 'p.id=s.purchase_plan_id AND p.tenant_id=s.tenant_id')
            ->where('s.tenant_id', self::TENANT_ID)->where('s.task_id', $taskId)
            ->whereIn('p.status', ['pending', 'partial'])->count(),
            '终止采购计划后补打工票不得悄悄创建新的采购计划');
    }

    /** @dataProvider legacyPurchaseShortageCases */
    public function test_legacy_printed_purchase_recovers_only_remaining_shortage_when_reprinted(string $shortage, int $expectedPlans): void
    {
        self::assertNotFalse(WorkforceLogic::saveProcess([
            'name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1,
        ]), WorkforceLogic::getError());
        $customerId = $this->createCustomer('旧采购票客户');
        $goodsId = $this->createCustomerReportGoods('旧采购票鲈鱼', 'LEGACY-PRINT-PLAN');
        $warehouseId = $this->createCustomerReportWarehouse('旧采购票仓');
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'legacy-purchase-plan-print', '4', '20'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $taskId = $this->shortageTaskId($report);
        $print = FulfillmentTaskLogic::printData(['id' => $taskId]);
        self::assertNotFalse($print, FulfillmentTaskLogic::getError());
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => $taskId, 'print_log_id' => $print['print_log_id'], 'success' => 1,
        ]), FulfillmentTaskLogic::getError());

        // 仅清除隔离夹具中的计划，模拟旧版本已打印但从未创建计划的数据。
        $planId = (int)Db::name('purchase_plan_source')->where('tenant_id', self::TENANT_ID)
            ->where('task_id', $taskId)->value('purchase_plan_id');
        Db::name('purchase_plan_source')->where('tenant_id', self::TENANT_ID)->where('purchase_plan_id', $planId)->delete();
        Db::name('purchase_plan')->where('tenant_id', self::TENANT_ID)->where('id', $planId)->delete();
        Db::name('customer_report_item')->where('tenant_id', self::TENANT_ID)
            ->where('id', (int)$report['items'][0]['id'])->update(['shortage_base_qty' => $shortage]);
        self::assertSame(0, PurchasePlanLogic::lists([])['count']);

        $reprint = FulfillmentTaskLogic::printData(['id' => $taskId]);
        self::assertNotFalse($reprint, FulfillmentTaskLogic::getError());
        self::assertSame($expectedPlans, PurchasePlanLogic::lists([])['count'], '仅把旧票仍未满足的缺货归集为计划');
        if ($expectedPlans > 0) {
            self::assertGreaterThan(0, (int)$reprint['ticket']['purchase_plan_id']);
        } else {
            self::assertSame(0, (int)$reprint['ticket']['purchase_plan_id']);
        }
    }

    public static function legacyPurchaseShortageCases(): array
    {
        return ['仍有缺货' => ['4.0000', 1], '缺货已补足' => ['0.0000', 0]];
    }

    public function test_purchase_tasks_with_the_same_scope_are_consolidated_and_released_after_termination(): void
    {
        $purchaseProcess = WorkforceLogic::saveProcess([
            'name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1,
        ]);
        self::assertNotFalse($purchaseProcess, WorkforceLogic::getError());
        $customerId = $this->createCustomer('采购计划客户');
        $deliveryCustomerId = $this->createCustomer('采购计划实际收货门店', $customerId);
        $goodsId = $this->createCustomerReportGoods('采购鲈鱼', 'PLAN-BASS');
        $warehouseId = $this->createCustomerReportWarehouse('采购计划仓');
        $batch = CustomerReportBatchLogic::start([
            'delivery_date' => '2026-08-10',
            'idempotency_key' => 'purchase-plan-report-batch',
        ]);
        self::assertNotFalse($batch, CustomerReportBatchLogic::getError());

        $firstPayload = $this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'purchase-plan-first', '4', '20'
        );
        $firstPayload['batch_id'] = (int)$batch['id'];
        $firstPayload['items'][0]['delivery_customer_id'] = $deliveryCustomerId;
        $secondPayload = $this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'purchase-plan-second', '6', '20'
        );
        $secondPayload['batch_id'] = (int)$batch['id'];
        $first = CustomerReportLogic::submit($firstPayload);
        $second = CustomerReportLogic::submit($secondPayload);
        self::assertNotFalse($first, CustomerReportLogic::getError());
        self::assertNotFalse($second, CustomerReportLogic::getError());

        $firstTask = $this->shortageTaskId($first);
        $secondTask = $this->shortageTaskId($second);
        $plan = PurchasePlanLogic::create(['task_ids' => [$firstTask]]);
        self::assertNotFalse($plan, PurchasePlanLogic::getError());
        $samePlan = PurchasePlanLogic::create(['task_ids' => [$secondTask]]);
        self::assertNotFalse($samePlan, PurchasePlanLogic::getError());
        self::assertSame((int)$plan['id'], (int)$samePlan['id']);
        self::assertSame('10.0000', $samePlan['planned_qty']);
        self::assertCount(2, $samePlan['sources']);
        $firstSource = current(array_filter(
            $samePlan['sources'],
            static fn(array $source): bool => (int)$source['report_id'] === (int)$first['id']
        ));
        self::assertIsArray($firstSource);
        self::assertSame('采购计划实际收货门店', $firstSource['customer_name']);

        $editPayload = $firstPayload;
        $editPayload['id'] = (int)$first['id'];
        $editPayload['version'] = (int)$first['version'];
        $editPayload['items'][0]['id'] = (int)$first['items'][0]['id'];
        self::assertFalse(CustomerReportLogic::edit($editPayload));
        self::assertSame(
            '报货缺货来源已归入活动采购计划，请先终止采购计划后再编辑',
            CustomerReportLogic::getError()
        );

        self::assertFalse(CustomerReportLogic::retry([
            'id' => (int)$first['id'],
            'version' => (int)$first['version'],
        ]));
        self::assertSame(
            '报货缺货来源已归入活动采购计划，请先终止采购计划后再重试缺货预留',
            CustomerReportLogic::getError()
        );
        $terminated = PurchasePlanLogic::terminate(['id' => (int)$plan['id'], 'reason' => '供应商无法供货']);
        self::assertNotFalse($terminated, PurchasePlanLogic::getError());
        self::assertSame('terminated', $terminated['status']);
        $replacement = PurchasePlanLogic::create(['task_ids' => [$firstTask]]);
        self::assertNotFalse($replacement, PurchasePlanLogic::getError());
        self::assertNotSame((int)$plan['id'], (int)$replacement['id']);
    }

    public function test_priority_suggestion_uses_only_unsatisfied_sources_and_never_invents_midnight(): void
    {
        $now = time();
        $planId = (int)Db::name('purchase_plan')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'batch_id' => 0,
            'warehouse_id' => 1, 'warehouse_name' => '优先建议仓',
            'goods_id' => 1, 'goods_name' => '优先建议商品',
            'sku_id' => 1, 'sku_name' => '默认 SKU', 'base_unit_name' => '斤',
            'planned_qty' => '9.0000', 'arrived_qty' => '0.0000',
            'allocated_qty' => '3.0000', 'surplus_qty' => '0.0000',
            'status' => 'partial', 'active_scope_key' => hash('sha256', 'priority-suggestion'),
            'termination_reason' => '', 'create_time' => $now, 'update_time' => $now,
        ]);
        $missingId = (int)Db::name('purchase_plan_source')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'purchase_plan_id' => $planId,
            'task_id' => 101, 'report_id' => 201, 'report_item_id' => 301,
            'customer_name' => '时间待完善客户', 'delivery_date' => '2026-10-01',
            'earliest_delivery_time' => null,
            'shortage_qty' => '4.0000', 'allocated_qty' => '0.0000', 'status' => 'pending',
            'create_time' => $now, 'update_time' => $now,
        ]);
        $readyId = (int)Db::name('purchase_plan_source')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'purchase_plan_id' => $planId,
            'task_id' => 102, 'report_id' => 202, 'report_item_id' => 302,
            'customer_name' => '六点收货客户', 'delivery_date' => '2026-09-30',
            'earliest_delivery_time' => '06:00:00',
            'shortage_qty' => '3.0000', 'allocated_qty' => '1.0000', 'status' => 'partial',
            'create_time' => $now, 'update_time' => $now,
        ]);
        Db::name('purchase_plan_source')->insert([
            'tenant_id' => self::TENANT_ID, 'purchase_plan_id' => $planId,
            'task_id' => 103, 'report_id' => 203, 'report_item_id' => 303,
            'customer_name' => '已满足客户', 'delivery_date' => '2026-09-29',
            'earliest_delivery_time' => '05:00:00',
            'shortage_qty' => '2.0000', 'allocated_qty' => '2.0000', 'status' => 'fulfilled',
            'create_time' => $now, 'update_time' => $now,
        ]);

        $detail = PurchasePlanLogic::detail(['id' => $planId]);
        self::assertNotFalse($detail, PurchasePlanLogic::getError());
        self::assertSame('ready', $detail['priority_suggestion']['status']);
        self::assertSame('2026-09-30', $detail['priority_suggestion']['delivery_date']);
        self::assertSame('06:00', $detail['priority_suggestion']['earliest_delivery_time']);
        self::assertSame($readyId, $detail['priority_suggestion']['source_id']);
        self::assertSame(2, $detail['priority_suggestion']['unsatisfied_source_count']);
        self::assertSame(1, $detail['priority_suggestion']['missing_time_source_count']);
        self::assertFalse($detail['priority_suggestion']['automatic_allocation']);

        Db::name('purchase_plan_source')->where('id', $missingId)->update([
            'delivery_date' => '2026-09-29', 'update_time' => time(),
        ]);
        $earlierMissing = PurchasePlanLogic::detail(['id' => $planId]);
        self::assertNotFalse($earlierMissing, PurchasePlanLogic::getError());
        self::assertSame('missing', $earlierMissing['priority_suggestion']['status']);
        self::assertSame('2026-09-29', $earlierMissing['priority_suggestion']['delivery_date']);
        self::assertSame($missingId, $earlierMissing['priority_suggestion']['source_id']);

        Db::name('purchase_plan_source')->where('id', $missingId)->update([
            'delivery_date' => '2026-10-01', 'update_time' => time(),
        ]);

        $batchId = (int)Db::name('purchase_batch')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'purchase_plan_id' => $planId,
            'batch_no' => 'PB-PRIORITY', 'warehouse_id' => 1, 'warehouse_name' => '优先建议仓',
            'datetimesingle' => $now, 'remarks' => '', 'status' => 'submitted',
            'supplier_count' => 0, 'line_count' => 0, 'total_amount' => '0.00',
            'idempotency_key' => 'pb-priority', 'request_fingerprint' => hash('sha256', 'pb-priority'),
            'admin_id' => self::ADMIN_ID, 'create_time' => $now, 'update_time' => $now,
        ]);
        $batch = PurchaseBatchLogic::detail(['id' => $batchId]);
        self::assertNotFalse($batch, PurchaseBatchLogic::getError());
        self::assertSame('06:00', $batch['priority_suggestion']['earliest_delivery_time']);

        Db::name('purchase_plan_source')->where('id', $readyId)->update([
            'allocated_qty' => '3.0000', 'status' => 'fulfilled', 'update_time' => time(),
        ]);
        $after = PurchasePlanLogic::detail(['id' => $planId]);
        self::assertNotFalse($after, PurchasePlanLogic::getError());
        self::assertSame('missing', $after['priority_suggestion']['status']);
        self::assertSame('2026-10-01', $after['priority_suggestion']['delivery_date']);
        self::assertSame('', $after['priority_suggestion']['earliest_delivery_time']);
        self::assertSame($missingId, $after['priority_suggestion']['source_id']);
        self::assertSame(1, $after['priority_suggestion']['unsatisfied_source_count']);
    }

    public function test_customer_cancel_after_purchase_releases_only_its_source_and_keeps_arrival_history(): void
    {
        WorkforceLogic::saveProcess(['name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1]);
        $customer = $this->createCustomer('取消采购客户');
        $goods = $this->createCustomerReportGoods('取消采购鲈鱼', 'PLAN-CANCEL');
        $warehouse = $this->createCustomerReportWarehouse('取消采购仓');
        $batch = CustomerReportBatchLogic::start(['delivery_date' => '2026-08-10', 'idempotency_key' => 'cancel-report-batch']);
        $payload = $this->fulfillmentPayload($customer, $goods, $warehouse, 'cancel-first', '5', '20');
        $payload['batch_id'] = $batch['id'];
        $first = CustomerReportLogic::submit($payload);
        $payload['idempotency_key'] = 'cancel-second';
        $second = CustomerReportLogic::submit($payload);
        $plan = PurchasePlanLogic::create(['task_ids' => [$this->shortageTaskId($first), $this->shortageTaskId($second)]]);
        self::assertNotFalse($plan, PurchasePlanLogic::getError());
        $ids = array_column($plan['sources'], 'id', 'report_id');
        $sku = $this->customerReportSkuId($goods);
        $arrival = $this->createArrivalBatch((int)$plan['id'], $warehouse, $goods, $sku, '8.0000');
        WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouse, $goods, '8.0000');
        $this->holdArrivalForTest($arrival, $warehouse, $sku, '8.0000');
        $allocated = PurchasePlanLogic::attachArrival(['id' => $plan['id'], 'purchase_batch_id' => $arrival,
            'allocations' => [['source_id' => $ids[$first['id']], 'allocated_qty' => '3.00'], ['source_id' => $ids[$second['id']], 'allocated_qty' => '4.00']],
            'surplus_qty' => '1.0000']);
        self::assertNotFalse($allocated, PurchasePlanLogic::getError());
        $first = CustomerReportLogic::detail(['id' => $first['id']]);
        $request = ['id' => $first['id'], 'version' => $first['version'], 'reason' => '已备部分货，客户取消'];
        $cancelled = CustomerReportLogic::cancel($request);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('8.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('7.0000', WarehouseSkuBalanceService::reserved($warehouse, $sku));
        self::assertSame('3.0000', $cancelled['procurement_sources'][0]['allocations'][0]['allocated_qty']);
        $remaining = PurchasePlanLogic::detail(['id' => $plan['id']]);
        self::assertSame('partial', $remaining['status']);
        self::assertSame('released', $remaining['sources'][0]['status']);
        self::assertSame('0.0000', $remaining['sources'][0]['remaining_qty']);
        self::assertSame('1.0000', $remaining['sources'][1]['remaining_qty']);
        self::assertNotFalse(CustomerReportLogic::cancel($request), CustomerReportLogic::getError());
        self::assertSame('7.0000', WarehouseSkuBalanceService::reserved($warehouse, $sku));
        $rows = PendingGoodsLogic::lists(['report_id' => $first['id']]);
        self::assertCount(1, $rows['lists']);
        $pending = $rows['lists'][0];
        self::assertSame('3.0000', $pending['pending_qty']);
        $keep = ['id' => $pending['id'], 'version' => $pending['version'], 'expected_tenant_id' => self::TENANT_ID,
            'idempotency_key' => 'pending-keep-partial-test', 'action' => 'keep', 'quantity' => '1.2500'];
        $kept = PendingGoodsLogic::action($keep);
        self::assertNotFalse($kept, PendingGoodsLogic::getError());
        self::assertSame('1.7500', $kept['pending_qty']);
        self::assertSame('8.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku), '留库存不重复入库');
        self::assertSame('5.7500', WarehouseSkuBalanceService::reserved($warehouse, $sku));
        self::assertNotFalse(PendingGoodsLogic::action($keep), PendingGoodsLogic::getError());
        self::assertSame('5.7500', WarehouseSkuBalanceService::reserved($warehouse, $sku), '重复点击不重复释放');
        self::assertFalse(PendingGoodsLogic::action(array_replace($keep, ['quantity' => '2'])));
        self::assertFalse(PendingGoodsLogic::action(array_replace($keep, ['idempotency_key' => 'pending-stale-version-test'])));
        self::assertFalse(PendingGoodsLogic::action(array_replace($keep, ['expected_tenant_id' => self::OTHER_TENANT_ID])));
        $planReturn = array_replace($keep, ['version' => $kept['version'], 'action' => 'plan_return', 'quantity' => '1.75',
            'idempotency_key' => 'pending-plan-return-test', 'supply_order_id' => $pending['supply_orders'][0]['id']]);
        $planned = PendingGoodsLogic::action($planReturn);
        self::assertNotFalse($planned, PendingGoodsLogic::getError());
        self::assertSame('1.7500', $planned['return_qty']);
        self::assertSame('5.7500', WarehouseSkuBalanceService::reserved($warehouse, $sku), '安排退货不释放');
        $withdrawn = PendingGoodsLogic::action(array_replace($keep, ['version' => $planned['version'], 'action' => 'cancel_return',
            'idempotency_key' => 'pending-cancel-return-test', 'return_id' => $planned['returns'][0]['id']]));
        self::assertNotFalse($withdrawn, PendingGoodsLogic::getError());
        self::assertSame('1.7500', $withdrawn['pending_qty']);
        self::assertSame('0.0000', $withdrawn['return_qty']);
        self::assertSame('5.7500', WarehouseSkuBalanceService::reserved($warehouse, $sku), '撤回退货保持锁定');
        $newReport = CustomerReportLogic::submit($this->fulfillmentPayload($customer, $goods, $warehouse, 'new-after-pending', '5', '20'));
        self::assertNotFalse($newReport, CustomerReportLogic::getError());
        self::assertSame('2.2500', $newReport['reserved_base_qty'], '新订单只得到原可用 1 加上明确留库 1.25');
        self::assertSame('2.7500', $newReport['shortage_base_qty']);
        self::assertFalse(Db::transaction(fn() => WarehouseSkuBalanceService::deliverAttributedWithinTransaction($warehouse, $sku, '7', '2.25')), '实际交付也不能挪用待定实物');
        self::assertSame('8.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
    }

    public function test_cancel_before_allocation_releases_plan_hold_and_retains_original_supplier_orders(): void
    {
        WorkforceLogic::saveProcess(['name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1]);
        $customer = $this->createCustomer('未分配取消客户');
        $goods = $this->createCustomerReportGoods('未分配蛏子', 'PLAN-CANCEL-HOLD');
        $warehouse = $this->createCustomerReportWarehouse('未分配取消仓');
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customer, $goods, $warehouse, 'cancel-held', '5', '20'));
        $plan = PurchasePlanLogic::create(['task_ids' => [$this->shortageTaskId($report)]]);
        $sku = $this->customerReportSkuId($goods);
        $arrival = $this->createArrivalBatch((int)$plan['id'], $warehouse, $goods, $sku, '5.0000');
        WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouse, $goods, '5.0000');
        $this->holdArrivalForTest($arrival, $warehouse, $sku, '5.0000');
        $cancelled = CustomerReportLogic::cancel(['id' => $report['id'], 'version' => $report['version'], 'reason' => '已采购到货但尚未分配，客户取消']);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        self::assertSame('5.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('5.0000', WarehouseSkuBalanceService::reserved($warehouse, $sku));
        self::assertNotEmpty($cancelled['procurement_sources'][0]['unallocated_arrivals'][0]['supply_orders']);
        $closed = PurchasePlanLogic::detail(['id' => $plan['id']]);
        self::assertSame('terminated', $closed['status']);
        self::assertSame('0.0000', $closed['remaining_qty']);
        self::assertSame('0.0000', $closed['pending_arrival_batches'][0]['plan_held_qty']);
        self::assertFalse(PurchasePlanLogic::attachArrival(['id' => $plan['id'], 'purchase_batch_id' => $arrival,
            'allocations' => [['source_id' => $plan['sources'][0]['id'], 'allocated_qty' => '5.00']], 'surplus_qty' => '0.0000']));
    }


    public function test_pending_return_hold_uses_current_read_after_another_connection_plans_return(): void
    {
        $goods = $this->createCustomerReportGoods('并发待退鲈鱼', 'PENDING-CONCURRENT');
        $warehouse = $this->createCustomerReportWarehouse('并发待退仓'); $sku = $this->customerReportSkuId($goods);
        WarehouseSkuBalanceService::inbound($warehouse, $sku, '5'); WarehouseSkuBalanceService::reserve($warehouse, $sku, '5');
        $pending = (int)Db::name('pending_goods')->insertGetId(['tenant_id' => self::TENANT_ID, 'origin_key' => 'concurrent-test',
            'report_id' => 1, 'purchase_batch_id' => 1, 'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'total_qty' => '5', 'pending_qty' => '5', 'create_time' => time(), 'update_time' => time()]);
        $config = config('database.connections.mysql');
        $other = new \PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); Db::startTrans();
        try {
            Db::name('pending_goods_return')->where('tenant_id', self::TENANT_ID)->select();
            $other->beginTransaction();
            $update = $other->prepare('UPDATE ' . Db::name('pending_goods')->getTable() . ' SET pending_qty=0,return_qty=5 WHERE id=? AND tenant_id=?'); $update->execute([$pending, self::TENANT_ID]);
            $insert = $other->prepare('INSERT INTO ' . Db::name('pending_goods_return')->getTable() . ' (tenant_id,pending_goods_id,supply_order_id,quantity,remaining_qty,create_time,update_time) VALUES (?,?,1,5,5,?,?)'); $insert->execute([self::TENANT_ID, $pending, time(), time()]); $other->commit();
            self::assertFalse(WarehouseSkuBalanceService::deliverAttributedWithinTransaction($warehouse, $sku, '1', '0'), '旧快照也不能忽略刚安排的待退锁定');
            self::assertSame('5.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        } finally { Db::rollback(); }
    }

    public function test_historical_pending_recovery_is_explicit_idempotent_and_preserves_new_reservations(): void
    {
        WorkforceLogic::saveProcess(['name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1]);
        $customer = $this->createCustomer('历史余货客户'); $goods = $this->createCustomerReportGoods('历史鲈鱼', 'PENDING-HISTORY');
        $warehouse = $this->createCustomerReportWarehouse('历史仓'); $sku = $this->customerReportSkuId($goods);
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customer, $goods, $warehouse, 'historical-pending-report', '98', '20'));
        $plan = PurchasePlanLogic::create(['task_ids' => [$this->shortageTaskId($report)]]);
        $batch = $this->createArrivalBatch((int)$plan['id'], $warehouse, $goods, $sku, '98.0000');
        WarehouseSkuBalanceService::inbound($warehouse, $sku, '98'); $this->holdArrivalForTest($batch, $warehouse, $sku, '98');
        self::assertNotFalse(CustomerReportLogic::cancel(['id' => $report['id'], 'version' => $report['version'], 'reason' => '模拟升级前取消']));
        // 模拟旧版取消只释放库存、未创建待定记录的真实数据形态。
        Db::name('pending_goods')->where('tenant_id', self::TENANT_ID)->where('report_id', $report['id'])->delete();
        self::assertNotFalse(Db::transaction(fn() => WarehouseSkuBalanceService::releaseWithinTransaction($warehouse, $sku, '98')));
        $new = CustomerReportLogic::submit($this->fulfillmentPayload($customer, $goods, $warehouse, 'new-before-recovery', '30', '20'));
        self::assertSame('30.0000', $new['reserved_base_qty']);
        $preview = PendingGoodsLogic::recoveryPreview(['report_id' => $report['id']]);
        self::assertSame('68.0000', $preview['candidates'][0]['recoverable_qty']);
        $command = ['report_id' => $report['id'], 'origin_key' => 'batch:' . $batch, 'quantity' => '68', 'expected_tenant_id' => self::TENANT_ID, 'physical_confirmed' => 1];
        self::assertFalse(PendingGoodsLogic::recover(array_replace($command, ['quantity' => '98'])));
        self::assertFalse(PendingGoodsLogic::recover(array_replace($command, ['physical_confirmed' => 0])));
        self::assertNotFalse(PendingGoodsLogic::recover($command), PendingGoodsLogic::getError());
        self::assertNotFalse(PendingGoodsLogic::recover($command), PendingGoodsLogic::getError());
        self::assertSame('98.0000', WarehouseSkuBalanceService::reserved($warehouse, $sku));
        self::assertCount(1, PendingGoodsLogic::lists(['report_id' => $report['id']])['lists']);
        self::assertSame([], PendingGoodsLogic::recoveryPreview(['report_id' => $report['id']])['candidates']);
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(PendingGoodsLogic::recoveryPreview(['report_id' => $report['id']]));
        $this->prepareCustomerReportRequestContext();
    }

    public function test_pending_return_rejection_and_loss_keep_auditable_facts_without_second_stock_movement(): void
    {
        $schema = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260630_000001_create_purchase_return_order.sql');
        $this->runStatements($this->authoritativeCreateTable($schema, 'purchase_return_order'));
        $this->runStatements($this->authoritativeCreateTable($schema, 'purchase_return_order_lists'));
        if (Db::query("SHOW COLUMNS FROM `la_supply_order` LIKE 'return_status'") === []) { Db::execute('ALTER TABLE la_supply_order ADD COLUMN return_status tinyint NOT NULL DEFAULT 0'); }
        Db::name('tenant')->where('id', self::TENANT_ID)->delete();
        Db::name('tenant')->insert(['id' => self::TENANT_ID, 'sn' => 'PG882101', 'name' => '待定测试门店', 'disable' => 0, 'create_time' => time(), 'update_time' => time(), 'delete_time' => null]);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '待定测试供应商', 'order_payable' => '100', 'order_money' => '100']);
        WorkforceLogic::saveProcess(['name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1]);
        $customer = $this->createCustomer('分阶段退货客户'); $goods = $this->createCustomerReportGoods('退货鲈鱼', 'PENDING-RETURN');
        $warehouse = $this->createCustomerReportWarehouse('退货仓'); $sku = $this->customerReportSkuId($goods);
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customer, $goods, $warehouse, 'pending-return-report', '5', '20'));
        $plan = PurchasePlanLogic::create(['task_ids' => [$this->shortageTaskId($report)]]);
        $batch = $this->createArrivalBatch((int)$plan['id'], $warehouse, $goods, $sku, '5.0000');
        WarehouseSkuBalanceService::inbound($warehouse, $sku, '5'); $this->holdArrivalForTest($batch, $warehouse, $sku, '5');
        $orderId = (int)Db::name('purchase_batch_supply_order')->where('tenant_id', self::TENANT_ID)->where('purchase_batch_id', $batch)->value('supply_order_id');
        Db::name('supply_order')->where('id', $orderId)->update(['supplier_id' => $vendor]);
        $cancelled = CustomerReportLogic::cancel(['id' => $report['id'], 'version' => $report['version'], 'reason' => '需要退货']);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        $pending = PendingGoodsLogic::lists(['report_id' => $report['id']])['lists'][0];
        $command = ['id' => $pending['id'], 'expected_tenant_id' => self::TENANT_ID];
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'idempotency_key' => 'pending-full-return-plan', 'action' => 'plan_return', 'quantity' => '5', 'supply_order_id' => $orderId]);
        $line = Db::name('order_goods')->where('tenant_id', self::TENANT_ID)->where('order_id', $orderId)->where('order_type', 'supply')->find();
        $departure = ['pending_return_id' => $pending['returns'][0]['id'], 'original_supply_order_id' => $orderId, 'supplier_id' => $vendor,
            'idempotent_key' => 'pending-actual-departure-test', 'goods' => [['original_supply_order_list_id' => $line['id'], 'goods_id' => $goods, 'sku_id' => $sku, 'return_num' => '5', 'price' => '20']]];
        $out = PurchaseReturnOrderLogic::publish($departure);
        self::assertNotFalse($out, PurchaseReturnOrderLogic::getError());
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('100.00', (string)Db::name('vendor')->where('id', $vendor)->value('order_payable'), '退离不冲减应付');
        self::assertNotFalse(PurchaseReturnOrderLogic::publish($departure));
        $pending = PendingGoodsLogic::detail(['id' => $pending['id']]); $factId = $pending['returns'][0]['facts'][0]['id'];
        self::assertSame('supplier', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertSame('5.0000', $pending['return_qty'], '离仓后仍是待处理');
        $rejection = $command + ['version' => $pending['version'], 'action' => 'accept_return', 'fact_id' => $factId,
            'quantity' => '0', 'amount' => '1', 'note' => '供应商暂不认可', 'idempotency_key' => 'pending-rejection-test'];
        self::assertFalse(PendingGoodsLogic::action($rejection));
        $rejection['amount'] = '0';
        $pending = PendingGoodsLogic::action($rejection);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('5.0000', $pending['returns'][0]['facts'][0]['unresolved_qty']);
        self::assertSame('supplier', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertCount(1, $pending['returns'][0]['facts'][0]['history']);
        $loss = $command + ['version' => $pending['version'], 'action' => 'return_loss', 'fact_id' => $factId,
            'quantity' => '5', 'note' => '货物实际变质且确认损失', 'idempotency_key' => 'pending-loss-test'];
        $pending = PendingGoodsLogic::action($loss);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('0.0000', $pending['pending_qty']);
        self::assertSame('0.0000', $pending['return_qty']);
        self::assertSame('complete', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertCount(2, $pending['returns'][0]['facts'][0]['history']);
        self::assertSame('loss', $pending['returns'][0]['facts'][0]['history'][1]['kind']);
        self::assertNotFalse(PendingGoodsLogic::action($loss));
        self::assertSame('100.00', (string)Db::name('vendor')->where('id', $vendor)->value('order_payable'));
        Db::name('vendor')->where('tenant_id', self::TENANT_ID)->where('id', $vendor)->delete();
    }

    public function test_pending_return_waits_for_supplier_and_money_and_rejected_goods_return_locked(): void
    {
        $schema = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260630_000001_create_purchase_return_order.sql');
        $this->runStatements($this->authoritativeCreateTable($schema, 'purchase_return_order'));
        $this->runStatements($this->authoritativeCreateTable($schema, 'purchase_return_order_lists'));
        if (Db::query("SHOW COLUMNS FROM `la_supply_order` LIKE 'return_status'") === []) { Db::execute('ALTER TABLE la_supply_order ADD COLUMN return_status tinyint NOT NULL DEFAULT 0'); }
        Db::name('tenant')->where('id', self::TENANT_ID)->delete();
        Db::name('tenant')->insert(['id' => self::TENANT_ID, 'sn' => 'PG882101', 'name' => '待定测试门店', 'disable' => 0, 'create_time' => time(), 'update_time' => time(), 'delete_time' => null]);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '待定测试供应商', 'order_payable' => '100', 'order_money' => '100']);
        WorkforceLogic::saveProcess(['name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1]);
        $customer = $this->createCustomer('分阶段退货客户'); $goods = $this->createCustomerReportGoods('退货鲈鱼', 'PENDING-RETURN');
        $warehouse = $this->createCustomerReportWarehouse('退货仓'); $sku = $this->customerReportSkuId($goods);
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customer, $goods, $warehouse, 'pending-return-report', '5', '20'));
        $plan = PurchasePlanLogic::create(['task_ids' => [$this->shortageTaskId($report)]]);
        $batch = $this->createArrivalBatch((int)$plan['id'], $warehouse, $goods, $sku, '5.0000');
        WarehouseSkuBalanceService::inbound($warehouse, $sku, '5'); $this->holdArrivalForTest($batch, $warehouse, $sku, '5');
        $orderId = (int)Db::name('purchase_batch_supply_order')->where('tenant_id', self::TENANT_ID)->where('purchase_batch_id', $batch)->value('supply_order_id');
        Db::name('supply_order')->where('id', $orderId)->update(['supplier_id' => $vendor]);
        $cancelled = CustomerReportLogic::cancel(['id' => $report['id'], 'version' => $report['version'], 'reason' => '需要退货']);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        $pending = PendingGoodsLogic::lists(['report_id' => $report['id']])['lists'][0];
        $command = ['id' => $pending['id'], 'expected_tenant_id' => self::TENANT_ID];
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'idempotency_key' => 'pending-full-return-plan', 'action' => 'plan_return', 'quantity' => '5', 'supply_order_id' => $orderId]);
        $line = Db::name('order_goods')->where('tenant_id', self::TENANT_ID)->where('order_id', $orderId)->where('order_type', 'supply')->find();
        $departure = ['pending_return_id' => $pending['returns'][0]['id'], 'original_supply_order_id' => $orderId, 'supplier_id' => $vendor,
            'idempotent_key' => 'pending-actual-departure-test', 'goods' => [['original_supply_order_list_id' => $line['id'], 'goods_id' => $goods, 'sku_id' => $sku, 'return_num' => '5', 'price' => '20']]];
        $out = PurchaseReturnOrderLogic::publish($departure);
        self::assertNotFalse($out, PurchaseReturnOrderLogic::getError());
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('100.00', (string)Db::name('vendor')->where('id', $vendor)->value('order_payable'), '退离不冲减应付');
        self::assertNotFalse(PurchaseReturnOrderLogic::publish($departure));
        $pending = PendingGoodsLogic::detail(['id' => $pending['id']]); $factId = $pending['returns'][0]['facts'][0]['id'];
        self::assertSame('supplier', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertSame('5.0000', $pending['return_qty'], '离仓后仍是待处理');
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'action' => 'accept_return', 'fact_id' => $factId,
            'quantity' => '3', 'amount' => '60', 'note' => '供方签收三斤，同意六十元', 'idempotency_key' => 'pending-supplier-accept-test']);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('2.0000', $pending['returns'][0]['facts'][0]['unresolved_qty']);
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'action' => 'return_back', 'fact_id' => $factId,
            'quantity' => '2', 'note' => '未认可两斤已回仓', 'idempotency_key' => 'pending-goods-back-test']);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('2.0000', $pending['pending_qty']); self::assertSame('0.0000', WarehouseSkuBalanceService::available($warehouse, $sku));
        self::assertSame('money', $pending['returns'][0]['facts'][0]['next_step']);
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'action' => 'settle_return', 'fact_id' => $factId,
            'amount' => '60', 'settlement_kind' => 'offset', 'note' => '供应商确认抵扣本次未付六十元', 'idempotency_key' => 'pending-money-offset-test']);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('0.0000', $pending['return_qty']); self::assertSame('3.0000', $pending['returned_qty']);
        self::assertSame('complete', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertSame('40.00', (string)Db::name('vendor')->where('id', $vendor)->value('order_payable'));
        Db::name('vendor')->where('tenant_id', self::TENANT_ID)->where('id', $vendor)->delete();
    }

    public function test_purchase_plan_delivery_priority_migration_is_safe_to_replay(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = $this->prepareMigration((string)file_get_contents(
            $root . '/database/migrations/20260930_000004_purchase_plan_delivery_priority.sql'
        ));

        $this->runStatements($migration);
        $this->runStatements($migration);

        self::assertNotEmpty(Db::query("SHOW COLUMNS FROM `la_purchase_plan_source` LIKE 'earliest_delivery_time'"));
        self::assertNotEmpty(Db::query("SHOW INDEX FROM `la_purchase_plan_source` WHERE Key_name = 'idx_purchase_plan_source_priority'"));
    }

    public function test_partial_arrivals_are_explicitly_allocated_to_sources_or_surplus(): void
    {
        $purchaseProcess = WorkforceLogic::saveProcess([
            'name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1,
        ]);
        self::assertNotFalse($purchaseProcess, WorkforceLogic::getError());
        $customerId = $this->createCustomer('分批到货客户');
        $goodsId = $this->createCustomerReportGoods('分批鲈鱼', 'PLAN-PARTIAL');
        $warehouseId = $this->createCustomerReportWarehouse('分批到货仓');
        $report = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'purchase-plan-partial', '10', '20'
        ));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $plan = PurchasePlanLogic::create(['task_ids' => [$this->shortageTaskId($report)]]);
        self::assertNotFalse($plan, PurchasePlanLogic::getError());
        $sourceId = (int)$plan['sources'][0]['id'];
        $skuId = $this->customerReportSkuId($goodsId);

        $firstBatchId = $this->createArrivalBatch((int)$plan['id'], $warehouseId, $goodsId, $skuId, '4.0000');
        WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouseId, $goodsId, '4.0000');
        $this->holdArrivalForTest($firstBatchId, $warehouseId, $skuId, '4.0000');
        self::assertSame('0.0000', WarehouseSkuBalanceService::available($warehouseId, $skuId));
        $firstSupplyOrderId = (int)Db::name('purchase_batch_supply_order')
            ->where('tenant_id', self::TENANT_ID)->where('purchase_batch_id', $firstBatchId)
            ->value('supply_order_id');
        self::assertFalse(SupplyOrderLogic::edit(['id' => $firstSupplyOrderId]));
        self::assertSame(
            '采购计划到货批次的子进货单不可编辑，请按原到货事实完成分配，差异另行办理采购退货或新建到货批次',
            SupplyOrderLogic::getError()
        );
        self::assertSame('4.0000', (string)Db::name('purchase_batch')
            ->where('tenant_id', self::TENANT_ID)->where('id', $firstBatchId)->value('plan_held_qty'));
        self::assertFalse(PurchasePlanLogic::terminate([
            'id' => (int)$plan['id'],
            'reason' => '尚有未分配到货，不应终止',
        ]));
        self::assertSame(
            '采购计划仍有已入库但未分配的采购批次，请先完成来源分配',
            PurchasePlanLogic::getError()
        );
        $partial = PurchasePlanLogic::attachArrival([
            'id' => (int)$plan['id'],
            'purchase_batch_id' => $firstBatchId,
            'allocations' => [['source_id' => $sourceId, 'allocated_qty' => '3.00']],
            'surplus_qty' => '1.0000',
        ]);
        self::assertNotFalse($partial, PurchasePlanLogic::getError());
        self::assertSame('partial', $partial['status']);
        self::assertSame('4.0000', $partial['arrived_qty']);
        self::assertSame('3.0000', $partial['allocated_qty']);
        self::assertSame('1.0000', $partial['surplus_qty']);
        self::assertSame('7.0000', $partial['sources'][0]['remaining_qty']);

        $replayed = PurchasePlanLogic::attachArrival([
            'id' => (int)$plan['id'],
            'purchase_batch_id' => $firstBatchId,
            'allocations' => [['source_id' => $sourceId, 'allocated_qty' => '3.00']],
            'surplus_qty' => '1.0000',
        ]);
        self::assertNotFalse($replayed, PurchasePlanLogic::getError());
        self::assertFalse(PurchasePlanLogic::attachArrival([
            'id' => (int)$plan['id'],
            'purchase_batch_id' => $firstBatchId,
            'allocations' => [['source_id' => $sourceId, 'allocated_qty' => '4.00']],
            'surplus_qty' => '0.0000',
        ]));
        self::assertSame('该采购批次已经按不同内容完成来源分配，不能覆盖原结果', PurchasePlanLogic::getError());

        $secondBatchId = $this->createArrivalBatch((int)$plan['id'], $warehouseId, $goodsId, $skuId, '7.0000');
        WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouseId, $goodsId, '7.0000');
        $this->holdArrivalForTest($secondBatchId, $warehouseId, $skuId, '7.0000');
        $thirdBatchId = $this->createArrivalBatch((int)$plan['id'], $warehouseId, $goodsId, $skuId, '1.0000');
        WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouseId, $goodsId, '1.0000');
        $this->holdArrivalForTest($thirdBatchId, $warehouseId, $skuId, '1.0000');
        $sourceSatisfiedWithPendingArrival = PurchasePlanLogic::attachArrival([
            'id' => (int)$plan['id'],
            'purchase_batch_id' => $secondBatchId,
            'allocations' => [['source_id' => $sourceId, 'allocated_qty' => '7.00']],
            'surplus_qty' => '0.0000',
        ]);
        self::assertNotFalse($sourceSatisfiedWithPendingArrival, PurchasePlanLogic::getError());
        self::assertSame('partial', $sourceSatisfiedWithPendingArrival['status']);
        self::assertCount(1, $sourceSatisfiedWithPendingArrival['pending_arrival_batches']);
        self::assertSame($thirdBatchId, (int)$sourceSatisfiedWithPendingArrival['pending_arrival_batches'][0]['id']);

        $complete = PurchasePlanLogic::attachArrival([
            'id' => (int)$plan['id'],
            'purchase_batch_id' => $thirdBatchId,
            'allocations' => [],
            'surplus_qty' => '1.0000',
        ]);
        self::assertNotFalse($complete, PurchasePlanLogic::getError());
        self::assertSame('complete', $complete['status']);
        self::assertSame('12.0000', $complete['arrived_qty']);
        self::assertSame('10.0000', $complete['allocated_qty']);
        self::assertSame('2.0000', $complete['surplus_qty']);
        self::assertSame('0.0000', $complete['sources'][0]['remaining_qty']);
        $completeReplay = PurchasePlanLogic::attachArrival([
            'id' => (int)$plan['id'],
            'purchase_batch_id' => $thirdBatchId,
            'allocations' => [],
            'surplus_qty' => '1.0000',
        ]);
        self::assertNotFalse($completeReplay, PurchasePlanLogic::getError());
        self::assertSame('complete', $completeReplay['status']);
        self::assertSame('0.0000', (string)Db::name('customer_report_item')
            ->where('tenant_id', self::TENANT_ID)->where('id', (int)$report['items'][0]['id'])->value('shortage_base_qty'));
    }

    public function test_unbatched_reports_do_not_share_a_purchase_plan_scope(): void
    {
        $purchaseProcess = WorkforceLogic::saveProcess([
            'name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1,
        ]);
        self::assertNotFalse($purchaseProcess, WorkforceLogic::getError());
        $customerId = $this->createCustomer('未编批采购客户');
        $goodsId = $this->createCustomerReportGoods('未编批鲈鱼', 'PLAN-UNBATCHED');
        $warehouseId = $this->createCustomerReportWarehouse('未编批采购仓');
        $first = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'purchase-plan-unbatched-first', '2', ''
        ));
        $second = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'purchase-plan-unbatched-second', '3', ''
        ));
        self::assertNotFalse($first, CustomerReportLogic::getError());
        self::assertNotFalse($second, CustomerReportLogic::getError());
        $firstTaskId = $this->shortageTaskId($first);
        $secondTaskId = $this->shortageTaskId($second);

        self::assertFalse(PurchasePlanLogic::create(['task_ids' => [$firstTaskId, $secondTaskId]]));
        self::assertSame(
            '同一采购计划只能归集相同报货批次、仓库和 SKU 的任务',
            PurchasePlanLogic::getError()
        );
        $firstPlan = PurchasePlanLogic::create(['task_ids' => [$firstTaskId]]);
        $secondPlan = PurchasePlanLogic::create(['task_ids' => [$secondTaskId]]);
        self::assertNotFalse($firstPlan, PurchasePlanLogic::getError());
        self::assertNotFalse($secondPlan, PurchasePlanLogic::getError());
        self::assertNotSame((int)$firstPlan['id'], (int)$secondPlan['id']);
    }

    /** @param array<string,mixed> $report */
    private function shortageTaskId(array $report): int
    {
        foreach ((array)($report['task_group']['tasks'] ?? []) as $task) {
            if (str_ends_with((string)$task['source_key'], ':shortage')) {
                return (int)$task['id'];
            }
        }
        self::fail('缺货采购任务未生成');
    }

    private function createArrivalBatch(
        int $planId,
        int $warehouseId,
        int $goodsId,
        int $skuId,
        string $actualQuantity
    ): int {
        $now = time();
        $batchId = (int)Db::name('purchase_batch')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'purchase_plan_id' => $planId,
            'batch_no' => 'TEST-PB-' . uniqid(),
            'warehouse_id' => $warehouseId,
            'warehouse_name' => '分批到货仓',
            'datetimesingle' => $now,
            'remarks' => '',
            'status' => 'submitted',
            'supplier_count' => 1,
            'line_count' => 1,
            'total_amount' => '0.00',
            'idempotency_key' => 'test-' . uniqid(),
            'request_fingerprint' => hash('sha256', uniqid('', true)),
            'admin_id' => self::ADMIN_ID,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $supplyOrderId = (int)Db::name('supply_order')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'order_sn' => 'TEST-SO-' . uniqid(),
            'supplier_id' => 1,
            'supplier_name' => '测试供应商',
            'warehouse_id' => $warehouseId,
            'order_money' => '0.00',
            'order_pay_money' => '0.00',
            'order_arrears_money' => '0.00',
            'datetimesingle' => $now,
            'status' => 1,
            'purpose_type' => 'supply',
            'purchase_batch_id' => $batchId,
            'remarks' => '',
            'admin_id' => self::ADMIN_ID,
            'idempotent_key' => 'test-supply-' . uniqid(),
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('purchase_batch_supply_order')->insert([
            'tenant_id' => self::TENANT_ID,
            'purchase_batch_id' => $batchId,
            'supply_order_id' => $supplyOrderId,
            'supplier_id' => 1,
            'supplier_name' => '测试供应商',
            'line_count' => 1,
            'order_money' => '0.00',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('order_goods')->insert([
            'tenant_id' => self::TENANT_ID,
            'order_id' => $supplyOrderId,
            'order_type' => 'supply',
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'sku_name' => '测试 SKU',
            'name' => '分批鲈鱼',
            'units' => '斤',
            'number' => $actualQuantity,
            'base_quantity' => $actualQuantity,
            'actual_base_qty' => $actualQuantity,
            'price' => '0.00',
            'amount' => '0.00',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        return $batchId;
    }

    private function holdArrivalForTest(int $batchId, int $warehouseId, int $skuId, string $quantity): void
    {
        self::assertNotFalse(
            WarehouseSkuBalanceService::reserve($warehouseId, $skuId, $quantity),
            '测试采购到货应先从通用可用库存锁定'
        );
        Db::name('purchase_batch')->where('tenant_id', self::TENANT_ID)->where('id', $batchId)
            ->update(['plan_held_qty' => $quantity, 'update_time' => time()]);
    }
}
