<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class CustomerReportProcessingGroupTest extends TestCase
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

    public function test_report_line_processing_groups_generate_independent_ordered_task_chains(): void
    {
        $kill = WorkforceLogic::saveProcess(['name' => '杀鱼', 'trigger_type' => 'report_selection', 'is_enabled' => 1, 'sort' => 10]);
        $pack = WorkforceLogic::saveProcess(['name' => '普通打包', 'trigger_type' => 'report_selection', 'is_enabled' => 1, 'sort' => 20]);
        self::assertNotFalse($kill, WorkforceLogic::getError());
        self::assertNotFalse($pack, WorkforceLogic::getError());
        $customerId = $this->createCustomer('加工分组客户');
        $goodsId = $this->createCustomerReportGoods('鲈鱼', 'GROUP-BASS');
        $warehouseId = $this->createCustomerReportWarehouse('加工仓');
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'processing-group-1', '10', '');
        $payload['items'][0]['processing_groups'] = [
            ['group_key' => 'killed', 'name' => '杀鱼后打包', 'planned_qty' => '7', 'process_ids' => [(int)$pack['id'], (int)$kill['id']]],
            ['group_key' => 'alive', 'name' => '活鱼', 'planned_qty' => '3', 'process_ids' => [(int)$pack['id']]],
        ];

        $report = CustomerReportLogic::submit($payload);

        self::assertNotFalse($report, CustomerReportLogic::getError());
        self::assertCount(2, $report['items'][0]['processing_groups']);
        self::assertSame(['杀鱼', '普通打包'], array_column($report['items'][0]['processing_groups'][0]['processes'], 'process_name_snapshot'));
        $groups = Db::name('customer_report_processing_group')->where('report_item_id', (int)$report['items'][0]['id'])->order('sort')->select()->toArray();
        self::assertCount(2, $groups);
        $firstTasks = Db::name('fulfillment_task')->where('processing_group_id', (int)$groups[0]['id'])->order('process_sort_snapshot')->select()->toArray();
        self::assertCount(2, $firstTasks);
        self::assertSame((int)$firstTasks[0]['id'], (int)$firstTasks[1]['depends_on_task_id']);
        self::assertSame(['printable', 'printable'], array_column($firstTasks, 'status'));
        self::assertFalse(FulfillmentTaskLogic::recover([
            'id' => (int)$firstTasks[0]['id'],
            'actual_weight' => '7.00',
        ]));
        self::assertSame(
            '加工组工票当前仅支持打印、重打和作废；最终实重请在待开单阶段按加工组录入',
            FulfillmentTaskLogic::getError()
        );
    }

    public function test_processing_group_quantities_must_equal_report_line_quantity(): void
    {
        $process = WorkforceLogic::saveProcess(['name' => '杀鱼', 'trigger_type' => 'report_selection', 'is_enabled' => 1, 'sort' => 10]);
        self::assertNotFalse($process, WorkforceLogic::getError());
        $customerId = $this->createCustomer('分组校验客户');
        $goodsId = $this->createCustomerReportGoods('桂鱼', 'GROUP-MANDARIN');
        $warehouseId = $this->createCustomerReportWarehouse('分组校验仓');
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'processing-group-invalid', '10', '');
        $payload['items'][0]['processing_groups'] = [
            ['group_key' => 'only', 'name' => '只分了一部分', 'planned_qty' => '9', 'process_ids' => [(int)$process['id']]],
        ];

        self::assertFalse(CustomerReportLogic::submit($payload));
        self::assertSame('加工分组计划数量之和必须等于报货数量', CustomerReportLogic::getError());
    }

    public function test_processing_group_payload_is_required_and_cannot_be_empty(): void
    {
        $customerId = $this->createCustomer('空分组校验客户');
        $goodsId = $this->createCustomerReportGoods('石斑鱼', 'GROUP-EMPTY');
        $warehouseId = $this->createCustomerReportWarehouse('空分组校验仓');
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'processing-group-empty', '5', '');
        unset($payload['items'][0]['processing_groups']);

        self::assertFalse(CustomerReportLogic::submit($payload));
        self::assertSame('每条报货明细必须至少添加一个加工分组', CustomerReportLogic::getError());

        $payload['idempotency_key'] = 'processing-group-explicit-empty';
        $payload['items'][0]['processing_groups'] = [];

        self::assertFalse(CustomerReportLogic::submit($payload));
        self::assertSame('每条报货明细必须至少添加一个加工分组', CustomerReportLogic::getError());
    }

    public function test_pending_billing_records_final_weight_per_processing_group_and_sums_the_item(): void
    {
        $process = WorkforceLogic::saveProcess(['name' => '分切', 'trigger_type' => 'report_selection', 'is_enabled' => 1, 'sort' => 10]);
        self::assertNotFalse($process, WorkforceLogic::getError());
        $customerId = $this->createCustomer('待开单称重客户');
        $goodsId = $this->createCustomerReportGoods('三文鱼', 'WEIGHT-SALMON');
        $warehouseId = $this->createCustomerReportWarehouse('称重仓');
        WarehouseSkuBalanceForGoodsTestAdapter::inbound($warehouseId, $goodsId, '10');
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'processing-weight-1', '10', '25');
        $payload['items'][0]['processing_groups'] = [
            ['group_key' => 'large', 'name' => '大片', 'planned_qty' => '7', 'process_ids' => [(int)$process['id']]],
            ['group_key' => 'small', 'name' => '小片', 'planned_qty' => '3', 'process_ids' => [(int)$process['id']]],
        ];
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());

        $groups = $report['items'][0]['processing_groups'];
        self::assertFalse(CustomerReportLogic::saveProcessingWeights([
            'id' => (int)$report['id'],
            'version' => (int)$report['version'],
            'groups' => [
                ['id' => (int)$groups[0]['id'], 'final_actual_weight' => '6.80'],
                ['id' => 999999, 'final_actual_weight' => '3.10'],
            ],
        ]));
        self::assertSame('提交的加工组与当前报货单不一致，请刷新后重试', CustomerReportLogic::getError());
        self::assertSame(
            ['0.00', '0.00'],
            Db::name('customer_report_processing_group')->where('report_id', (int)$report['id'])
                ->order('sort')->column('final_actual_weight')
        );
        $weighted = CustomerReportLogic::saveProcessingWeights([
            'id' => (int)$report['id'],
            'version' => (int)$report['version'],
            'groups' => [
                ['id' => (int)$groups[0]['id'], 'final_actual_weight' => '6.80'],
                ['id' => (int)$groups[1]['id'], 'final_actual_weight' => '3.10'],
            ],
        ]);

        self::assertNotFalse($weighted, CustomerReportLogic::getError());
        self::assertSame('9.90', $weighted['items'][0]['final_actual_weight']);
        self::assertSame('final_weight_recorded', $weighted['items'][0]['fulfillment_status']);
        self::assertSame(['6.80', '3.10'], array_column($weighted['items'][0]['processing_groups'], 'final_actual_weight'));
    }
}
