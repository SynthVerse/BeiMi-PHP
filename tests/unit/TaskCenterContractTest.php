<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\TaskCenterService;
use app\common\model\jxc\WorkTask;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/TaskCenterTestSupport.php';

final class TaskCenterContractTest extends TestCase
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

    public function test_assignment_save_rebuilds_item_level_fulfillment_and_procurement_tasks_for_multiple_reservations(): void
    {
        self::assertTrue(class_exists(TaskCenterService::class), TaskCenterService::class . ' should exist');

        $packingEmployeeId = $this->createEmployee('打包员', 'packing');
        $procurementEmployeeId = $this->createEmployee('采购员', 'procurement');
        $enoughGoodsId = $this->createGoods('现货商品', 'TC-ENOUGH', '10.0000');
        $shortageGoodsId = $this->createGoods('缺货商品', 'TC-SHORT', '0.0000');

        $readyReservation = $this->submitReservation($enoughGoodsId, 3, '客户A');
        $shortageReservation = $this->submitReservation($shortageGoodsId, 5, '客户B');

        self::assertSame(0, WorkTask::where('tenant_id', self::TENANT_ID)->count(), 'submit must not auto-create task center tasks');

        $result = TaskCenterService::saveAssignment([
            'task_date' => '2026-07-05',
            'assignments' => [
                [
                    'reservation_item_id' => (int)$readyReservation['items'][0]['id'],
                    'role_code' => 'packing',
                    'employee_id' => $packingEmployeeId,
                    'priority' => 'normal',
                ],
                [
                    'reservation_item_id' => (int)$shortageReservation['items'][0]['id'],
                    'role_code' => 'packing',
                    'employee_id' => $packingEmployeeId,
                    'priority' => 'normal',
                ],
                [
                    'reservation_item_id' => (int)$shortageReservation['items'][0]['id'],
                    'role_code' => 'procurement',
                    'employee_id' => $procurementEmployeeId,
                    'priority' => 'normal',
                ],
            ],
        ]);

        self::assertSame(2, $result['created']['fulfillment']);
        self::assertSame(1, $result['created']['procurement']);

        $fulfillment = WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('task_kind', 'fulfillment')
            ->order(['id' => 'asc'])
            ->select()
            ->toArray();
        self::assertCount(2, $fulfillment);
        self::assertSame(['packing', 'packing'], array_column($fulfillment, 'role_code'));
        self::assertSame(['enough', 'shortage'], array_column($fulfillment, 'stock_status'));
        self::assertSame($packingEmployeeId, (int)$fulfillment[0]['assignee_employee_id']);

        $procurement = WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('task_kind', 'procurement')
            ->find();
        self::assertNotNull($procurement);
        self::assertSame('procurement', (string)$procurement->role_code);
        self::assertSame((int)$shortageReservation['items'][0]['id'], (int)$procurement->source_id);
        self::assertSame('5.0000', (string)$procurement->demand_num);
        self::assertSame('5.0000', (string)$procurement->shortage_num);
        self::assertSame('shortage', (string)$procurement->stock_status);
        self::assertSame($procurementEmployeeId, (int)$procurement->assignee_employee_id);
        self::assertGreaterThan(0, (int)$procurement->parent_task_id);
    }

    public function test_task_center_exposes_only_the_new_nine_routes_and_no_legacy_task_entries(): void
    {
        $routes = file_get_contents(root_path() . 'app/api/route/jxc.php');

        self::assertIsString($routes);
        foreach ([
            "Route::get('jxc/task/dashboard'",
            "Route::get('jxc/task/reservations/select'",
            "Route::post('jxc/task/reservations/preview'",
            "Route::get('jxc/task/items'",
            "Route::post('jxc/task/assignment/save'",
            "Route::get('jxc/task/employee-board'",
            "Route::get('jxc/task/procurement/shortage'",
            "Route::post('jxc/task/print-data'",
            "Route::post('jxc/task/status'",
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }

        foreach ([
            'jxc/task/lists',
            'jxc.Task/lists',
            'TaskRole',
            'TaskType',
            'TaskEmployee',
            'WorkTask',
        ] as $legacy) {
            self::assertStringNotContainsString($legacy, $routes);
        }
    }

    public function test_legacy_task_center_runtime_classes_are_not_available_for_default_dispatch(): void
    {
        self::assertFalse(method_exists(\app\api\jxc\controller\TaskController::class, 'lists'));

        foreach ([
            'app\\api\\jxc\\logic\\WorkTaskService',
            'app\\api\\jxc\\logic\\WorkTaskLogic',
            'app\\api\\jxc\\logic\\TaskRoleLogic',
            'app\\api\\jxc\\logic\\TaskTypeLogic',
            'app\\api\\jxc\\logic\\TaskEmployeeLogic',
            'app\\api\\jxc\\controller\\TaskRoleController',
            'app\\api\\jxc\\controller\\TaskTypeController',
            'app\\api\\jxc\\controller\\TaskEmployeeController',
            'app\\api\\controller\\jxc\\TaskRoleController',
            'app\\api\\controller\\jxc\\TaskTypeController',
            'app\\api\\controller\\jxc\\TaskEmployeeController',
            'app\\common\\model\\jxc\\TaskRole',
            'app\\common\\model\\jxc\\TaskType',
            'app\\common\\model\\jxc\\TaskTypeRole',
        ] as $class) {
            self::assertFalse(class_exists($class), $class . ' should be removed from P0 task center runtime');
        }
    }

    public function test_status_allows_manager_to_advance_tasks_but_keeps_terminal_states_closed(): void
    {
        $this->createEmployee('店长', 'manager', self::ADMIN_ID);
        $workerId = $this->createEmployee('打包员', 'packing', self::ADMIN_ID + 1);
        $taskId = $this->createTaskCenterTask([
            'task_kind' => WorkTask::KIND_FULFILLMENT,
            'role_code' => WorkTask::ROLE_PACKING,
            'assignee_employee_id' => $workerId,
            'assignee_employee_name' => '打包员',
            'status' => WorkTask::STATUS_ASSIGNED,
        ]);

        $processing = TaskCenterService::status([
            'id' => $taskId,
            'status' => WorkTask::STATUS_PROCESSING,
        ]);
        self::assertNotFalse($processing);
        self::assertSame(WorkTask::STATUS_PROCESSING, (string)WorkTask::find($taskId)->status);

        $completed = TaskCenterService::status([
            'id' => $taskId,
            'status' => WorkTask::STATUS_COMPLETED,
        ]);
        self::assertNotFalse($completed);
        self::assertSame(WorkTask::STATUS_COMPLETED, (string)WorkTask::find($taskId)->status);

        $reopened = TaskCenterService::status([
            'id' => $taskId,
            'status' => WorkTask::STATUS_PROCESSING,
        ]);
        self::assertFalse($reopened);
        self::assertSame(WorkTask::STATUS_COMPLETED, (string)WorkTask::find($taskId)->status);
    }

    public function test_status_limits_regular_employee_to_assigned_or_role_visible_tasks(): void
    {
        $workerAdminId = self::ADMIN_ID + 20;
        $workerId = $this->createEmployee('打包员', 'packing', $workerAdminId);
        $ownTaskId = $this->createTaskCenterTask([
            'role_code' => WorkTask::ROLE_PACKING,
            'assignee_employee_id' => $workerId,
            'assignee_employee_name' => '打包员',
            'status' => WorkTask::STATUS_ASSIGNED,
        ]);
        $roleVisibleTaskId = $this->createTaskCenterTask([
            'role_code' => WorkTask::ROLE_PACKING,
            'assignee_employee_id' => 0,
            'status' => WorkTask::STATUS_PENDING,
        ]);
        $outsideRoleTaskId = $this->createTaskCenterTask([
            'role_code' => WorkTask::ROLE_PROCUREMENT,
            'assignee_employee_id' => 0,
            'status' => WorkTask::STATUS_PENDING,
        ]);

        $this->prepareRequestContext(self::TENANT_ID, $workerAdminId);

        self::assertNotFalse(TaskCenterService::status(['id' => $ownTaskId, 'status' => WorkTask::STATUS_PROCESSING]));
        self::assertNotFalse(TaskCenterService::status(['id' => $roleVisibleTaskId, 'status' => WorkTask::STATUS_PROCESSING]));

        $denied = TaskCenterService::status(['id' => $outsideRoleTaskId, 'status' => WorkTask::STATUS_PROCESSING]);
        self::assertFalse($denied);
        self::assertSame(WorkTask::STATUS_PENDING, (string)WorkTask::find($outsideRoleTaskId)->status);
    }

    public function test_status_rejects_illegal_transitions_and_cross_tenant_ids(): void
    {
        $this->createEmployee('店长', 'manager', self::ADMIN_ID);
        $taskId = $this->createTaskCenterTask([
            'status' => WorkTask::STATUS_PENDING,
        ]);
        $otherTenantTaskId = $this->createTaskCenterTask([
            'tenant_id' => self::OTHER_TENANT_ID,
            'status' => WorkTask::STATUS_PENDING,
        ]);

        $illegal = TaskCenterService::status([
            'id' => $taskId,
            'status' => WorkTask::STATUS_COMPLETED,
        ]);
        self::assertFalse($illegal);
        self::assertSame(WorkTask::STATUS_PENDING, (string)WorkTask::find($taskId)->status);

        $crossTenant = TaskCenterService::status([
            'id' => $otherTenantTaskId,
            'status' => WorkTask::STATUS_PROCESSING,
        ]);
        self::assertFalse($crossTenant);
        self::assertSame(WorkTask::STATUS_PENDING, (string)Db::name('work_task')->where('id', $otherTenantTaskId)->value('status'));
    }

    public function test_print_data_returns_ble_payload_without_recording_print_side_effects(): void
    {
        $packingEmployeeId = $this->createEmployee('打包员', 'packing');
        $goodsId = $this->createGoods('打印商品', 'TC-PRINT', '10.0000');
        $reservation = $this->submitReservation($goodsId, 2, '打印客户');

        TaskCenterService::saveAssignment([
            'task_date' => '2026-07-05',
            'assignments' => [[
                'reservation_item_id' => (int)$reservation['items'][0]['id'],
                'role_code' => 'packing',
                'employee_id' => $packingEmployeeId,
                'priority' => 'normal',
            ]],
        ]);
        $taskId = (int)WorkTask::where('tenant_id', self::TENANT_ID)->value('id');

        $payload = TaskCenterService::printData([
            'scope' => 'task',
            'task_ids' => [$taskId],
            'device_id' => 'BLE-001',
            'device_name' => '小票机',
        ]);

        self::assertSame('task', $payload['scope']);
        self::assertSame([$taskId], $payload['task_ids']);
        self::assertSame('BLE-001', $payload['device']['id']);
        self::assertSame('打印商品', $payload['payload']['items'][0]['goods_name']);
        self::assertSame('2.0000', $payload['payload']['items'][0]['demand_num']);
        self::assertSame(0, (int)WorkTask::find($taskId)->print_count);
        self::assertSame(0, Db::name('task_print_log')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_status_print_success_records_print_log_and_increments_print_count(): void
    {
        $this->createEmployee('店长', 'manager', self::ADMIN_ID);
        $taskId = $this->createTaskCenterTask([
            'status' => WorkTask::STATUS_ASSIGNED,
            'goods_name' => '打印记录商品',
        ]);

        $result = TaskCenterService::status([
            'task_ids' => [$taskId],
            'status' => 'print_success',
            'device_id' => 'BLE-002',
            'device_name' => '打印机A',
        ]);

        self::assertNotFalse($result);
        $task = WorkTask::find($taskId);
        self::assertSame(1, (int)$task->print_count);
        self::assertGreaterThan(0, (int)$task->last_print_time);

        $log = Db::name('task_print_log')
            ->where('tenant_id', self::TENANT_ID)
            ->where('result', 'success')
            ->find();
        self::assertNotEmpty($log);
        self::assertSame('BLE-002', (string)$log['device_id']);
        self::assertSame([$taskId], json_decode((string)$log['task_ids_json'], true));
    }
}
