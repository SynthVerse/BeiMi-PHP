<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\PurchasePlanLogic;
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
        $goodsId = $this->createCustomerReportGoods('采购鲈鱼', 'PLAN-BASS');
        $warehouseId = $this->createCustomerReportWarehouse('采购计划仓');

        $first = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'purchase-plan-first', '4', '20'
        ));
        $second = CustomerReportLogic::submit($this->fulfillmentPayload(
            $customerId, $goodsId, $warehouseId, 'purchase-plan-second', '6', '20'
        ));
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

        $terminated = PurchasePlanLogic::terminate(['id' => (int)$plan['id'], 'reason' => '供应商无法供货']);
        self::assertNotFalse($terminated, PurchasePlanLogic::getError());
        self::assertSame('terminated', $terminated['status']);
        $replacement = PurchasePlanLogic::create(['task_ids' => [$firstTask]]);
        self::assertNotFalse($replacement, PurchasePlanLogic::getError());
        self::assertNotSame((int)$plan['id'], (int)$replacement['id']);
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
        $complete = PurchasePlanLogic::attachArrival([
            'id' => (int)$plan['id'],
            'purchase_batch_id' => $secondBatchId,
            'allocations' => [['source_id' => $sourceId, 'allocated_qty' => '7.00']],
            'surplus_qty' => '0.0000',
        ]);
        self::assertNotFalse($complete, PurchasePlanLogic::getError());
        self::assertSame('complete', $complete['status']);
        self::assertSame('11.0000', $complete['arrived_qty']);
        self::assertSame('10.0000', $complete['allocated_qty']);
        self::assertSame('1.0000', $complete['surplus_qty']);
        self::assertSame('0.0000', $complete['sources'][0]['remaining_qty']);
        $completeReplay = PurchasePlanLogic::attachArrival([
            'id' => (int)$plan['id'],
            'purchase_batch_id' => $secondBatchId,
            'allocations' => [['source_id' => $sourceId, 'allocated_qty' => '7.00']],
            'surplus_qty' => '0.0000',
        ]);
        self::assertNotFalse($completeReplay, PurchasePlanLogic::getError());
        self::assertSame('complete', $completeReplay['status']);
        self::assertSame('0.00', (string)Db::name('customer_report_item')
            ->where('tenant_id', self::TENANT_ID)->where('id', (int)$report['items'][0]['id'])->value('shortage_base_qty'));
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
        $supplyOrderId = 900000 + $batchId;
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
}
