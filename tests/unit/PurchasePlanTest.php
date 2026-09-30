<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportBatchLogic;
use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\PurchasePlanLogic;
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
        self::assertFalse(CustomerReportLogic::cancel([
            'id' => (int)$first['id'],
            'version' => (int)$first['version'],
            'reason' => '活动采购计划期间不可取消',
        ]));
        self::assertSame(
            '报货缺货来源已归入活动采购计划，请先终止采购计划后再取消报货',
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
        self::assertSame('0.00', (string)Db::name('customer_report_item')
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
