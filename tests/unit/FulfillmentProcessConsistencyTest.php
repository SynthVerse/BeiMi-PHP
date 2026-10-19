<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\DeliveryQuantityFacts;
use app\api\jxc\logic\FulfillmentChangeLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use tests\support\IsolatedDatabaseGuard;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

/** 履约分组数量、终态同步与 V2 动作回归；只允许既有隔离测试库。 */
final class FulfillmentProcessConsistencyTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.' . config('database.default'));
        self::assertTrue(IsolatedDatabaseGuard::acceptsConnection($connection), '必须是 127.0.0.1:3307 / beimi_test_* 隔离库');
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

    public function test_reducing_grouped_item_keeps_group_and_ticket_quantities_consistent(): void
    {
        $process = WorkforceLogic::saveProcess(['name' => '审计加工', 'trigger_type' => 'report_selection', 'sort' => 10, 'is_enabled' => 1]);
        self::assertNotFalse($process, WorkforceLogic::getError());
        $customer = $this->createCustomer('审计减量客户');
        $goods = $this->createCustomerReportGoods('审计减量商品', 'AUDIT-GROUP-REDUCE');
        $warehouse = $this->createCustomerReportWarehouse('审计仓');
        self::assertNotFalse(WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouse, $goods, '10'));
        $payload = $this->fulfillmentPayload($customer, $goods, $warehouse, 'audit-group-reduce', '10', '分组处理');
        $payload['items'][0]['processing_groups'] = [
            ['group_key' => 'seven', 'name' => '第一组', 'planned_qty' => '7', 'process_ids' => [(int)$process['id']]],
            ['group_key' => 'three', 'name' => '第二组', 'planned_qty' => '3', 'process_ids' => [(int)$process['id']]],
        ];
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $item = (int)$report['items'][0]['id'];
        self::assertFalse(FulfillmentChangeLogic::reduceItem([
            'report_item_id' => $item, 'new_expected_base_qty' => '8', 'processed_reduction_qty' => '0',
            'reason' => '未指定分组不应自动平均', 'idempotency_key' => 'reduce-without-group',
        ]));
        self::assertSame('请指定本次减量的加工分组', FulfillmentChangeLogic::getError());
        $firstGroupId = (int)Db::name('customer_report_processing_group')->where('tenant_id', self::TENANT_ID)
            ->where('report_item_id', $item)->order('sort')->value('id');
        $changed = FulfillmentChangeLogic::reduceItem([
            'report_item_id' => $item, 'processing_group_id' => $firstGroupId,
            'new_expected_base_qty' => '8', 'processed_reduction_qty' => '0',
            'reason' => '审计减量两件', 'idempotency_key' => 'audit-group-reduce-action',
        ]);
        self::assertNotFalse($changed, FulfillmentChangeLogic::getError());
        $groups = Db::name('customer_report_processing_group')->where('tenant_id', self::TENANT_ID)
            ->where('report_item_id', $item)->order('sort')->column('planned_qty');
        $tasks = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)->where('report_item_id', $item)
            ->where('processing_group_id', '>', 0)->order('id')->column('planned_qty');
        $sum = array_reduce($groups, static fn(string $carry, string $qty): string => bcadd($carry, $qty, 4), '0.0000');
        self::assertSame('8.0000', $sum, '减量成功后各加工分组计划量之和应等于新报货量');
        self::assertSame(array_map(static fn(string $qty): string => bcadd($qty, '0', 2), $groups), $tasks,
            '每组单工序票应保留该组对应量，不能都变成整行总量');
    }

    public function test_undelivered_item_does_not_regain_processing_tasks_on_report_resync(): void
    {
        $customer = $this->createCustomer('审计未交货客户');
        $goodsA = $this->createCustomerReportGoods('审计停止商品', 'AUDIT-UNDELIVERED-A');
        $goodsB = $this->createCustomerReportGoods('审计继续商品', 'AUDIT-UNDELIVERED-B');
        $warehouse = $this->createCustomerReportWarehouse('审计仓');
        self::assertNotFalse(WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouse, $goodsA, '2'));
        self::assertNotFalse(WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouse, $goodsB, '3'));
        $payload = $this->fulfillmentPayload($customer, $goodsA, $warehouse, 'audit-undelivered-report', '2', '分组处理');
        $other = $this->fulfillmentPayload($customer, $goodsB, $warehouse, 'unused', '3', '分组处理');
        $payload['items'][] = $other['items'][0];
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $item = (int)$report['items'][0]['id'];
        $changed = FulfillmentChangeLogic::markUndelivered([
            'report_item_id' => $item, 'reason_code' => 'customer_cancel', 'reason' => '审计客户取消此商品',
            'idempotency_key' => 'audit-undelivered-action',
        ]);
        self::assertNotFalse($changed, FulfillmentChangeLogic::getError());
        self::assertSame(0, Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_item_id', $item)->where('status', '<>', 'cancelled')->count());
        Db::transaction(static fn() => FulfillmentTaskLogic::syncForReport((int)$report['id']));
        $itemStatus = Db::name('customer_report_item')->where('tenant_id', self::TENANT_ID)->where('id', $item)->value('fulfillment_status');
        self::assertSame('undelivered', $itemStatus);
        self::assertSame(0, Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_item_id', $item)->where('status', '<>', 'cancelled')->count(), '同单重同步不得复活已明确未交货商品的工序任务');
        $otherItem = (int)$report['items'][1]['id'];
        self::assertNotFalse(FulfillmentChangeLogic::markUndelivered([
            'report_item_id' => $otherItem, 'reason_code' => 'customer_cancel',
            'reason' => '审计客户取消剩余商品', 'idempotency_key' => 'audit-last-undelivered',
        ]), FulfillmentChangeLogic::getError());
        Db::transaction(static fn() => FulfillmentTaskLogic::syncForReport((int)$report['id']));
        self::assertSame(0, Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$report['id'])->where('status', '<>', 'cancelled')->count(),
            '整单未交货后重同步也不能复活送货任务');
    }

    public function test_v2_authorized_administrator_has_a_reachable_fulfillment_change_action(): void
    {
        self::assertTrue(WorkforceLogic::hasPermission('task.control'), '审计账号必须具备减量与未交货操作权限');
        $process = WorkforceLogic::saveProcess(['name' => '审计直接备货', 'trigger_type' => 'report_selection', 'sort' => 10, 'is_enabled' => 1]);
        $delivery = WorkforceLogic::saveProcess(['name' => '审计送货', 'trigger_type' => 'all_processing_completed', 'sort' => 900, 'is_enabled' => 1]);
        self::assertNotFalse($process, WorkforceLogic::getError());
        self::assertNotFalse($delivery, WorkforceLogic::getError());
        $unit = $this->createCustomerReportUnit('瓶');
        $goods = $this->createCustomerReportGoods('审计 V2 商品', 'AUDIT-V2-ACTIONS', '瓶');
        $sku = $this->customerReportSkuId($goods);
        Db::name('goods')->where('id', $goods)->update(['unit_id' => $unit]);
        Db::name('goods_sku')->where('id', $sku)->update(['base_unit_id' => $unit, 'base_unit_name' => '瓶']);
        Db::name('goods_units_binding')->insert([
            'tenant_id' => self::TENANT_ID, 'goods_id' => $goods, 'unit_id' => $unit,
            'unit_name' => '瓶', 'is_base_unit' => 1, 'status' => 1,
        ]);
        $warehouse = $this->createCustomerReportWarehouse('审计 V2 仓');
        self::assertNotFalse(WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouse, $goods, '5'));
        $report = CustomerReportLogic::submit([
            'quantity_contract_version' => 2, 'main_customer_id' => $this->createCustomer('审计 V2 客户'),
            'delivery_date' => date('Y-m-d'), 'delivery_arrangement' => ['delivery_method' => 'self_delivery'],
            'idempotency_key' => 'audit-v2-change-actions',
            'items' => [[
                'goods_id' => $goods, 'sku_id' => $sku, 'warehouse_id' => $warehouse,
                'unit_id' => $unit, 'unit_name' => '瓶', 'order_qty' => '5', 'price_status' => 'unpriced',
                'processing_groups' => [['group_key' => 'all', 'name' => '全部数量', 'planned_qty' => '5', 'process_ids' => [(int)$process['id']]]],
            ]],
        ]);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        self::assertSame(2, (int)Db::name('customer_report_item')->where('id', $report['items'][0]['id'])->value('quantity_contract_version'));
        try {
            DeliveryQuantityFacts::prepare([
                'idempotency_key' => 'audit-v2-no-selected-groups',
                'items' => [['report_item_id' => (int)$report['items'][0]['id'], 'facts_version' => 1]],
            ]);
            self::fail('V2 新交付请求不能省略实际交付子组');
        } catch (\DomainException $e) {
            self::assertStringContainsString('明确选择', $e->getMessage());
        }
        $result = FulfillmentTaskLogic::lists([]);
        self::assertNotFalse($result, FulfillmentTaskLogic::getError());
        $tasks = array_values(array_filter($result['lists'], static fn(array $task): bool => (int)$task['report_id'] === (int)$report['id']));
        self::assertCount(2, $tasks, 'V2 报货应已生成备货和整单交付任务');
        $reduceAllowed = count(array_filter($tasks, static fn(array $task): bool => (bool)($task['actions']['reduce_item']['allowed'] ?? false)));
        $undeliveredAllowed = count(array_filter($tasks, static fn(array $task): bool => (bool)($task['actions']['mark_undelivered']['allowed'] ?? false)));
        self::assertGreaterThan(0, min($reduceAllowed, $undeliveredAllowed), '合法管理员的新 V2 报货必须存在可达的减量和未交货操作');
    }
}
