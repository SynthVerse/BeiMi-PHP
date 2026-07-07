<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\SupplyOrderLogic;
use app\api\jxc\logic\TaskCenterService;
use app\common\model\jxc\SalesReservation;
use app\common\model\jxc\SalesReservationItem;
use app\common\model\jxc\WorkTask;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/TaskCenterTestSupport.php';

final class SupplyOrderTaskCenterIntegrationTest extends TestCase
{
    use TaskCenterTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareRequestContext();
        $this->ensureTaskCenterTables();
        $this->cleanTaskCenterTenantData();
        $this->cleanTaskCenterTenantData(self::OTHER_TENANT_ID);
    }

    protected function tearDown(): void
    {
        $this->cleanTaskCenterTenantData();
        $this->cleanTaskCenterTenantData(self::OTHER_TENANT_ID);
        parent::tearDown();
    }

    public function test_supply_order_publish_applies_procurement_inbound_to_open_goods_shortage_fifo(): void
    {
        self::assertTrue(class_exists(TaskCenterService::class), TaskCenterService::class . ' should exist');

        $packingEmployeeId = $this->createEmployee('打包员', 'packing');
        $procurementEmployeeId = $this->createEmployee('采购员', 'procurement');
        $warehouseId = $this->createWarehouse('任务中心仓库');
        $supplierId = $this->createVendor('任务中心供应商');
        $goodsId = $this->createGoods('采购回填商品', 'TC-SUPPLY', '0.0000');

        $firstReservation = $this->submitReservation($goodsId, 3, 'FIFO客户A');
        $secondReservation = $this->submitReservation($goodsId, 4, 'FIFO客户B');
        TaskCenterService::saveAssignment([
            'task_date' => '2026-07-05',
            'assignments' => [
                [
                    'reservation_item_id' => (int)$firstReservation['items'][0]['id'],
                    'role_code' => 'packing',
                    'employee_id' => $packingEmployeeId,
                    'priority' => 'normal',
                ],
                [
                    'reservation_item_id' => (int)$firstReservation['items'][0]['id'],
                    'role_code' => 'procurement',
                    'employee_id' => $procurementEmployeeId,
                    'priority' => 'normal',
                ],
                [
                    'reservation_item_id' => (int)$secondReservation['items'][0]['id'],
                    'role_code' => 'packing',
                    'employee_id' => $packingEmployeeId,
                    'priority' => 'normal',
                ],
                [
                    'reservation_item_id' => (int)$secondReservation['items'][0]['id'],
                    'role_code' => 'procurement',
                    'employee_id' => $procurementEmployeeId,
                    'priority' => 'normal',
                ],
            ],
        ]);

        $published = SupplyOrderLogic::publish([
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_pay_money' => '5.00',
            'goods' => [[
                'goods_id' => $goodsId,
                'order_qty' => 5,
                'price' => '1.00',
            ]],
        ]);

        self::assertNotFalse($published);

        $tasks = WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('task_kind', 'procurement')
            ->order(['id' => 'asc'])
            ->select()
            ->toArray();
        self::assertCount(2, $tasks);
        self::assertSame('completed', $tasks[0]['status']);
        self::assertSame('3.0000', (string)$tasks[0]['progress_num']);
        self::assertSame('processing', $tasks[1]['status']);
        self::assertSame('2.0000', (string)$tasks[1]['progress_num']);

        self::assertSame('ready', (string)SalesReservation::find((int)$firstReservation['id'])->status);
        self::assertSame('shortage', (string)SalesReservation::find((int)$secondReservation['id'])->status);
        self::assertSame('reserved', (string)SalesReservationItem::where('reservation_id', (int)$firstReservation['id'])->find()->status);
        self::assertSame('shortage', (string)SalesReservationItem::where('reservation_id', (int)$secondReservation['id'])->find()->status);
    }

    public function test_procurement_inbound_uses_same_tenant_goods_only_fifo_and_ignores_terminal_tasks(): void
    {
        $goodsId = $this->createGoods('goods-only商品', 'TC-GOODS-ONLY', '0.0000');
        $terminalTaskId = $this->createTaskCenterTask([
            'task_kind' => WorkTask::KIND_PROCUREMENT,
            'role_code' => WorkTask::ROLE_PROCUREMENT,
            'goods_id' => $goodsId,
            'source_type' => 'test-terminal',
            'source_id' => 8001,
            'demand_num' => '2.0000',
            'shortage_num' => '2.0000',
            'progress_num' => '2.0000',
            'stock_status' => WorkTask::STOCK_PROCUREMENT_DONE,
            'status' => WorkTask::STATUS_COMPLETED,
        ]);
        $openTaskId = $this->createTaskCenterTask([
            'task_kind' => WorkTask::KIND_PROCUREMENT,
            'role_code' => WorkTask::ROLE_PROCUREMENT,
            'goods_id' => $goodsId,
            'source_type' => 'test-open',
            'source_id' => 8002,
            'demand_num' => '3.0000',
            'shortage_num' => '3.0000',
            'progress_num' => '0.0000',
            'stock_status' => WorkTask::STOCK_SHORTAGE,
            'status' => WorkTask::STATUS_PENDING,
        ]);
        $otherTenantTaskId = $this->createTaskCenterTask([
            'tenant_id' => self::OTHER_TENANT_ID,
            'task_kind' => WorkTask::KIND_PROCUREMENT,
            'role_code' => WorkTask::ROLE_PROCUREMENT,
            'goods_id' => $goodsId,
            'source_type' => 'test-other-tenant',
            'source_id' => 8003,
            'demand_num' => '4.0000',
            'shortage_num' => '4.0000',
            'progress_num' => '0.0000',
            'stock_status' => WorkTask::STOCK_SHORTAGE,
            'status' => WorkTask::STATUS_PENDING,
        ]);

        TaskCenterService::applyProcurementInbound(7001, [[
            'id' => 9001,
            'goods_id' => $goodsId,
            'warehouse_id' => 999,
            'sku_id' => 888,
            'spec_id' => 777,
            'number' => '3.0000',
        ]]);

        self::assertSame('2.0000', (string)WorkTask::find($terminalTaskId)->progress_num);
        self::assertSame(WorkTask::STATUS_COMPLETED, (string)WorkTask::find($terminalTaskId)->status);
        self::assertSame('3.0000', (string)WorkTask::find($openTaskId)->progress_num);
        self::assertSame(WorkTask::STATUS_COMPLETED, (string)WorkTask::find($openTaskId)->status);
        self::assertSame('0.0000', (string)Db::name('work_task')->where('id', $otherTenantTaskId)->value('progress_num'));
        self::assertSame(WorkTask::STATUS_PENDING, (string)Db::name('work_task')->where('id', $otherTenantTaskId)->value('status'));
    }
}
