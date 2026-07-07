<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\InventoryReservationService;
use app\api\jxc\logic\SalesReservationLogic;
use app\api\jxc\logic\TaskCenterService;
use app\common\model\jxc\WorkTask;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TaskCenterTestSupport.php';

final class SalesReservationTaskCenterIntegrationTest extends TestCase
{
    use TaskCenterTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareRequestContext();
        $this->ensureTaskCenterTables();
        $this->cleanTaskCenterTenantData();
    }

    protected function tearDown(): void
    {
        $this->cleanTaskCenterTenantData();
        parent::tearDown();
    }

    public function test_submit_no_longer_creates_work_tasks_and_cancel_cancels_open_task_center_tasks(): void
    {
        self::assertTrue(class_exists(TaskCenterService::class), TaskCenterService::class . ' should exist');

        $packingEmployeeId = $this->createEmployee('打包员', 'packing');
        $procurementEmployeeId = $this->createEmployee('采购员', 'procurement');
        $goodsId = $this->createGoods('取消预定商品', 'TC-CANCEL', '10.0000');
        $reservation = $this->submitReservation($goodsId, 12, '取消客户');

        self::assertSame('0.0000', InventoryReservationService::availableForGoods($goodsId));
        self::assertSame(0, WorkTask::where('tenant_id', self::TENANT_ID)->count());

        TaskCenterService::saveAssignment([
            'task_date' => '2026-07-05',
            'assignments' => [
                [
                    'reservation_item_id' => (int)$reservation['items'][0]['id'],
                    'role_code' => 'packing',
                    'employee_id' => $packingEmployeeId,
                    'priority' => 'normal',
                ],
                [
                    'reservation_item_id' => (int)$reservation['items'][0]['id'],
                    'role_code' => 'procurement',
                    'employee_id' => $procurementEmployeeId,
                    'priority' => 'normal',
                ],
            ],
        ]);
        self::assertSame(2, WorkTask::where('tenant_id', self::TENANT_ID)->where('reservation_id', (int)$reservation['id'])->count());

        $cancelled = SalesReservationLogic::cancel(['id' => (int)$reservation['id']]);

        self::assertNotFalse($cancelled);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('10.0000', InventoryReservationService::availableForGoods($goodsId));
        self::assertSame(2, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('reservation_id', (int)$reservation['id'])
            ->where('status', 'cancelled')
            ->count());
    }
}
