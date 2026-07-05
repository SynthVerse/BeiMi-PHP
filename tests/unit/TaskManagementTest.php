<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\TaskEmployeeLogic;
use app\api\jxc\logic\SalesReservationLogic;
use app\api\jxc\logic\TaskRoleLogic;
use app\api\jxc\logic\TaskTypeLogic;
use app\api\jxc\logic\WorkTaskLogic;
use app\api\jxc\logic\WorkTaskService;
use app\common\model\jxc\SalesReservation;
use app\common\model\jxc\SalesReservationItem;
use app\common\model\jxc\TaskEmployee;
use app\common\model\jxc\TaskEmployeeRole;
use app\common\model\jxc\TaskRole;
use app\common\model\jxc\TaskType;
use app\common\model\jxc\TaskTypeRole;
use app\common\model\jxc\WorkTask;
use app\common\model\jxc\WorkTaskLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class TaskManagementTest extends TestCase
{
    private const TENANT_ID = 881001;
    private const OTHER_TENANT_ID = 881002;
    private const MANAGER_ADMIN_ID = 991001;
    private const WORKER_ADMIN_ID = 991002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertImplementationClassesExist();
        $this->prepareRequestContext(self::TENANT_ID, self::MANAGER_ADMIN_ID);
        $this->ensureTaskTables();
        $this->ensureSalesReservationTables();
        $this->cleanTenantData(self::TENANT_ID);
        $this->cleanTenantData(self::OTHER_TENANT_ID);
    }

    protected function tearDown(): void
    {
        $this->cleanTenantData(self::TENANT_ID);
        $this->cleanTenantData(self::OTHER_TENANT_ID);
        parent::tearDown();
    }

    public function test_first_manager_can_be_bootstrapped_when_no_manager_exists(): void
    {
        $manager = TaskEmployeeLogic::create([
            'name' => '首个店长',
            'admin_id' => self::MANAGER_ADMIN_ID,
            'role_codes' => ['manager'],
        ]);

        self::assertNotFalse($manager);
        self::assertGreaterThan(0, (int)$manager['id']);
        self::assertContains('manager', $manager['role_codes']);
        self::assertSame(1, TaskEmployee::where('tenant_id', self::TENANT_ID)->where('is_enabled', 1)->count());
    }

    public function test_front_created_user_bound_manager_is_recognized_by_current_login(): void
    {
        $managerId = $this->createEmployeeWithBindings('前端创建店长', 0, self::MANAGER_ADMIN_ID, ['manager']);

        $this->prepareRequestContext(self::TENANT_ID, self::MANAGER_ADMIN_ID);

        self::assertSame($managerId, WorkTaskService::currentEmployeeId());
        self::assertTrue(WorkTaskService::isManager());
    }

    public function test_non_manager_cannot_create_employee_after_manager_exists(): void
    {
        $manager = $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        self::assertGreaterThan(0, $manager);

        $this->prepareRequestContext(self::TENANT_ID, self::WORKER_ADMIN_ID);
        $result = TaskEmployeeLogic::create([
            'name' => '配送员',
            'admin_id' => self::WORKER_ADMIN_ID,
            'role_codes' => ['delivery'],
        ]);

        self::assertFalse($result);
        self::assertSame('TASK_PERMISSION_DENIED', TaskEmployeeLogic::getReturnData()['error_code'] ?? null);
    }

    public function test_non_manager_cannot_list_or_view_task_employees(): void
    {
        $managerId = $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $workerId = $this->createEmployee('配送员', self::WORKER_ADMIN_ID, ['delivery']);
        self::assertGreaterThan(0, $managerId);
        self::assertGreaterThan(0, $workerId);

        $this->prepareRequestContext(self::TENANT_ID, self::WORKER_ADMIN_ID);

        self::assertSame([], TaskEmployeeLogic::lists(['page_no' => 1, 'page_size' => 20])['lists']);
        self::assertSame(0, TaskEmployeeLogic::lists(['page_no' => 1, 'page_size' => 20])['count']);
        self::assertSame([], TaskEmployeeLogic::detail(['id' => $managerId]));
    }

    public function test_worker_lists_assigned_tasks_and_role_visible_task_types_while_manager_sees_all(): void
    {
        $managerId = $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $workerId = $this->createEmployee('配送员', self::WORKER_ADMIN_ID, ['delivery']);
        $otherWorkerId = $this->createEmployee('打包员', 991003, ['packing']);

        $assigned = $this->createTask('送货任务', 'delivery', $workerId);
        $roleVisible = $this->createTask('未分配送货任务', 'delivery', 0);
        $unassigned = $this->createTask('打包任务', 'packing', $otherWorkerId);

        $this->prepareRequestContext(self::TENANT_ID, self::WORKER_ADMIN_ID);
        $workerList = WorkTaskLogic::lists(['page_no' => 1, 'page_size' => 20]);
        self::assertSame([$roleVisible, $assigned], array_column($workerList['lists'], 'id'));

        $this->prepareRequestContext(self::TENANT_ID, self::MANAGER_ADMIN_ID);
        $managerList = WorkTaskLogic::lists(['page_no' => 1, 'page_size' => 20]);
        self::assertContains($assigned, array_column($managerList['lists'], 'id'));
        self::assertContains($roleVisible, array_column($managerList['lists'], 'id'));
        self::assertContains($unassigned, array_column($managerList['lists'], 'id'));
        self::assertSame($managerId, WorkTaskService::currentEmployeeId());
    }

    public function test_multi_role_worker_sees_and_operates_matching_task_types_once_and_is_denied_outside_roles(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $workerId = $this->createEmployee('采购配送员', self::WORKER_ADMIN_ID, ['delivery', 'procurement']);
        $packingId = $this->createEmployee('打包员', 991003, ['packing']);

        $assignedDelivery = $this->createTask('已分配送货任务', 'delivery', $workerId);
        $roleDelivery = $this->createTask('角色送货任务', 'delivery', 0);
        $roleProcurement = $this->createTask('角色采购任务', 'procurement', 0);
        $packing = $this->createTask('打包任务', 'packing', $packingId);

        $this->prepareRequestContext(self::TENANT_ID, self::WORKER_ADMIN_ID);
        $list = WorkTaskLogic::lists(['page_no' => 1, 'page_size' => 20]);
        $ids = array_column($list['lists'], 'id');

        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertContains($assignedDelivery, $ids);
        self::assertContains($roleDelivery, $ids);
        self::assertContains($roleProcurement, $ids);
        self::assertNotContains($packing, $ids);

        self::assertSame('processing', WorkTaskLogic::start(['id' => $roleProcurement])['status']);
        self::assertFalse(WorkTaskLogic::start(['id' => $packing]));
        self::assertSame('TASK_STATUS_INVALID', WorkTaskLogic::getReturnData()['error_code'] ?? null);
    }

    public function test_manager_can_create_custom_role_and_bind_custom_task_type(): void
    {
        self::assertTrue(class_exists(TaskRoleLogic::class), TaskRoleLogic::class . ' should exist');

        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);

        $role = TaskRoleLogic::create([
            'code' => 'fish_kill',
            'name' => '杀鱼',
            'is_enabled' => 1,
        ]);
        self::assertNotFalse($role);
        self::assertSame('fish_kill', $role['code']);
        self::assertSame(0, $role['is_system']);

        $type = TaskTypeLogic::create([
            'code' => 'fish_kill_task',
            'name' => '杀鱼任务',
            'role_codes' => ['fish_kill'],
            'is_enabled' => 1,
        ]);
        self::assertNotFalse($type);
        self::assertSame(['fish_kill'], $type['role_codes']);
        self::assertSame(['杀鱼'], $type['role_names']);

        $list = TaskTypeLogic::lists();
        $created = array_values(array_filter($list['lists'], static fn(array $row): bool => $row['code'] === 'fish_kill_task'));
        self::assertCount(1, $created);
        self::assertSame(['fish_kill'], $created[0]['role_codes']);
        self::assertSame(1, TaskTypeRole::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'fish_kill_task')
            ->where('role_code', 'fish_kill')
            ->count());
    }

    public function test_custom_multi_role_worker_can_see_and_operate_custom_type_without_duplicates(): void
    {
        self::assertTrue(class_exists(TaskRoleLogic::class), TaskRoleLogic::class . ' should exist');

        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        TaskRoleLogic::create(['code' => 'fish_kill', 'name' => '杀鱼']);
        TaskTypeLogic::create([
            'code' => 'fish_kill_task',
            'name' => '杀鱼任务',
            'role_codes' => ['fish_kill', 'delivery'],
        ]);
        $workerId = $this->createEmployee('配送杀鱼员', self::WORKER_ADMIN_ID, ['delivery', 'fish_kill']);
        $packingId = $this->createEmployee('打包员', 991003, ['packing']);

        $fishTask = $this->createTask('杀鱼任务', 'fish_kill_task', 0);
        $deliveryTask = $this->createTask('配送任务', 'delivery', 0);
        $packingTask = $this->createTask('打包任务', 'packing', $packingId);

        $this->prepareRequestContext(self::TENANT_ID, self::WORKER_ADMIN_ID);
        $list = WorkTaskLogic::lists(['page_no' => 1, 'page_size' => 20]);
        $ids = array_column($list['lists'], 'id');

        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertContains($fishTask, $ids);
        self::assertContains($deliveryTask, $ids);
        self::assertNotContains($packingTask, $ids);

        self::assertSame('processing', WorkTaskLogic::start(['id' => $fishTask])['status']);

        $this->prepareRequestContext(self::TENANT_ID, 991003);
        self::assertFalse(WorkTaskLogic::start(['id' => $deliveryTask]));
        self::assertSame('TASK_STATUS_INVALID', WorkTaskLogic::getReturnData()['error_code'] ?? null);
        self::assertSame($workerId, TaskEmployee::where('tenant_id', self::TENANT_ID)->where('admin_id', self::WORKER_ADMIN_ID)->value('id'));
    }

    public function test_manager_can_filter_tasks_by_assignee_employee(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $deliveryId = $this->createEmployee('配送员', self::WORKER_ADMIN_ID, ['delivery']);
        $packingId = $this->createEmployee('打包员', 991003, ['packing']);
        $deliveryTaskId = $this->createTask('指定配送任务', 'delivery', $deliveryId);
        $packingTaskId = $this->createTask('指定打包任务', 'packing', $packingId);

        $list = WorkTaskLogic::lists([
            'page_no' => 1,
            'page_size' => 20,
            'assignee_employee_id' => $deliveryId,
        ]);

        self::assertSame([$deliveryTaskId], array_column($list['lists'], 'id'));
        self::assertNotContains($packingTaskId, array_column($list['lists'], 'id'));
    }

    public function test_worker_cannot_view_task_detail_outside_assigned_or_role_visible_scope(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $deliveryId = $this->createEmployee('配送员', self::WORKER_ADMIN_ID, ['delivery']);
        $packingId = $this->createEmployee('打包员', 991003, ['packing']);
        $deliveryTaskId = $this->createTask('可见配送任务', 'delivery', $deliveryId);
        $packingTaskId = $this->createTask('不可见打包任务', 'packing', $packingId);

        $this->prepareRequestContext(self::TENANT_ID, self::WORKER_ADMIN_ID);

        self::assertSame($deliveryTaskId, (int)WorkTaskLogic::detail(['id' => $deliveryTaskId])['id']);
        self::assertSame([], WorkTaskLogic::detail(['id' => $packingTaskId]));
    }

    public function test_role_executor_can_operate_matching_unassigned_task_type(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $this->createEmployee('配送员', self::WORKER_ADMIN_ID, ['delivery']);
        $taskId = $this->createTask('角色可执行配送任务', 'delivery', 0);

        $this->prepareRequestContext(self::TENANT_ID, self::WORKER_ADMIN_ID);
        $started = WorkTaskLogic::start(['id' => $taskId]);

        self::assertNotFalse($started);
        self::assertSame('processing', $started['status']);
    }

    public function test_manager_cannot_assign_task_to_employee_without_matching_type_role(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $packingId = $this->createEmployee('打包员', 991003, ['packing']);
        $taskId = $this->createTask('配送任务', 'delivery', 0);

        $result = WorkTaskLogic::assign([
            'id' => $taskId,
            'assignee_employee_id' => $packingId,
        ]);

        self::assertFalse($result);
        self::assertSame('TASK_ASSIGNEE_ROLE_MISMATCH', WorkTaskLogic::getReturnData()['error_code'] ?? null);
    }

    public function test_manager_cannot_assign_task_to_disabled_employee(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $disabledDeliveryId = $this->createEmployeeWithBindings('停用配送员', 991004, 0, ['delivery'], 0);
        $taskId = $this->createTask('配送任务', 'delivery', 0);

        $result = WorkTaskLogic::assign([
            'id' => $taskId,
            'assignee_employee_id' => $disabledDeliveryId,
        ]);

        self::assertFalse($result);
        self::assertSame('TASK_ASSIGNEE_INVALID', WorkTaskLogic::getReturnData()['error_code'] ?? null);
    }

    public function test_manager_cannot_create_task_with_missing_disabled_or_mismatched_assignee(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $packingId = $this->createEmployee('打包员', 991003, ['packing']);
        $disabledDeliveryId = $this->createEmployeeWithBindings('停用配送员', 991004, 0, ['delivery'], 0);

        $missing = WorkTaskLogic::create([
            'type_code' => 'delivery',
            'title' => '缺失执行人',
            'assignee_employee_id' => 999999,
        ]);
        self::assertFalse($missing);
        self::assertSame('TASK_ASSIGNEE_INVALID', WorkTaskLogic::getReturnData()['error_code'] ?? null);

        $disabled = WorkTaskLogic::create([
            'type_code' => 'delivery',
            'title' => '停用执行人',
            'assignee_employee_id' => $disabledDeliveryId,
        ]);
        self::assertFalse($disabled);
        self::assertSame('TASK_ASSIGNEE_INVALID', WorkTaskLogic::getReturnData()['error_code'] ?? null);

        $mismatched = WorkTaskLogic::create([
            'type_code' => 'delivery',
            'title' => '错配执行人',
            'assignee_employee_id' => $packingId,
        ]);
        self::assertFalse($mismatched);
        self::assertSame('TASK_ASSIGNEE_ROLE_MISMATCH', WorkTaskLogic::getReturnData()['error_code'] ?? null);
    }

    public function test_actions_are_derived_from_role_assignment_source_and_status(): void
    {
        $managerId = $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $workerId = $this->createEmployee('配送员', self::WORKER_ADMIN_ID, ['delivery']);
        $taskId = $this->createTask('送货任务', 'delivery', $workerId, ['source_type' => 'manual']);

        $this->prepareRequestContext(self::TENANT_ID, self::WORKER_ADMIN_ID);
        $pending = WorkTaskLogic::detail(['id' => $taskId]);
        self::assertSame([
            'can_edit' => false,
            'can_assign' => false,
            'can_start' => true,
            'can_complete' => false,
            'can_cancel' => false,
            'can_view_source' => false,
        ], $pending['actions']);

        $started = WorkTaskLogic::start(['id' => $taskId]);
        self::assertNotFalse($started);
        $processing = WorkTaskLogic::detail(['id' => $taskId]);
        self::assertSame('processing', $processing['status']);
        self::assertSame('进行中', $processing['status_label']);
        self::assertTrue($processing['actions']['can_complete']);

        $this->prepareRequestContext(self::TENANT_ID, self::MANAGER_ADMIN_ID);
        $managerView = WorkTaskLogic::detail(['id' => $taskId]);
        self::assertSame($managerId, WorkTaskService::currentEmployeeId());
        self::assertTrue($managerView['actions']['can_assign']);
        self::assertTrue($managerView['actions']['can_cancel']);
    }

    public function test_work_task_detail_includes_operation_logs(): void
    {
        $managerId = $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $taskId = $this->createTask('带日志任务', 'delivery', $managerId);
        WorkTaskLog::create([
            'tenant_id' => self::TENANT_ID,
            'task_id' => $taskId,
            'action' => 'custom',
            'content' => '自定义操作记录',
            'operator_employee_id' => $managerId,
            'operator_admin_id' => self::MANAGER_ADMIN_ID,
            'create_time' => time(),
        ]);

        $detail = WorkTaskLogic::detail(['id' => $taskId]);

        self::assertArrayHasKey('logs', $detail);
        self::assertSame(['custom'], array_column($detail['logs'], 'action'));
        self::assertSame('自定义操作记录', $detail['logs'][0]['content']);
    }

    public function test_legacy_procurement_runtime_is_removed_from_classes_and_routes(): void
    {
        self::assertFalse(class_exists('app\\common\\model\\jxc\\ProcurementTask'));
        self::assertFalse(class_exists('app\\common\\model\\jxc\\ProcurementTaskInbound'));
        self::assertFalse(class_exists('app\\api\\jxc\\logic\\ProcurementTaskService'));
        self::assertFalse(class_exists('app\\api\\jxc\\controller\\ProcurementTaskController'));
        self::assertFalse(class_exists('app\\api\\controller\\jxc\\ProcurementTaskController'));

        $routes = file_get_contents(root_path() . 'app/api/route/jxc.php');
        self::assertIsString($routes);
        self::assertStringNotContainsString('jxc/procurement_task', $routes);
        self::assertStringNotContainsString('ProcurementTask', $routes);
    }

    public function test_sales_convert_task_is_unique_for_active_reservation(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);

        $first = WorkTaskService::createSalesConvertTask([
            'reservation_id' => 71001,
            'reservation_sn' => 'RSV71001',
            'title' => '预定转销售',
        ]);
        $second = WorkTaskService::createSalesConvertTask([
            'reservation_id' => 71001,
            'reservation_sn' => 'RSV71001',
            'title' => '重复预定转销售',
        ]);

        self::assertSame((int)$first['id'], (int)$second['id']);
        self::assertSame(1, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'sales_convert')
            ->where('reservation_id', 71001)
            ->whereIn('status', ['pending', 'processing'])
            ->count());
    }

    public function test_ready_sales_reservation_submit_creates_sales_convert_task_once(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $goodsId = $this->createGoods('现货预定商品', 'TM-READY', '8.00');

        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [['goods_id' => $goodsId, 'num' => 3]],
        ]);
        self::assertNotFalse($reservation);
        self::assertSame(1, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'sales_convert')
            ->where('reservation_id', (int)$reservation['id'])
            ->whereIn('status', ['pending', 'processing'])
            ->count());

        WorkTaskService::createSalesConvertTask([
            'reservation_id' => (int)$reservation['id'],
            'reservation_sn' => (string)$reservation['sn'],
            'title' => '重复调用',
        ]);

        self::assertSame('ready', $reservation['status']);
        self::assertSame(1, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'sales_convert')
            ->where('reservation_id', (int)$reservation['id'])
            ->whereIn('status', ['pending', 'processing'])
            ->count());
    }

    public function test_shortage_sales_reservation_submit_creates_procurement_work_task(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $goodsId = $this->createGoods('缺货预定商品', 'TM-SHORT', '0.00');

        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [['goods_id' => $goodsId, 'num' => 5]],
        ]);
        self::assertNotFalse($reservation);

        $item = $reservation['items'][0];
        self::assertArrayNotHasKey('procurement_task_id', $item);
        self::assertArrayHasKey('work_task', $item);
        self::assertSame('shortage', $reservation['status']);

        $task = WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'procurement')
            ->where('source_type', 'sales_reservation_item')
            ->where('source_id', (int)$item['id'])
            ->find();
        self::assertNotNull($task);
        self::assertSame((int)$reservation['id'], (int)$task->reservation_id);
        self::assertSame((string)$reservation['sn'], (string)$task->reservation_sn);
        self::assertSame('5.0000', (string)$task->target_num);
        self::assertSame('0.0000', (string)$task->progress_num);
        self::assertSame('pending', (string)$task->status);
        self::assertSame(1, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'procurement')
            ->where('source_type', 'sales_reservation_item')
            ->where('source_id', (int)$item['id'])
            ->whereIn('status', ['pending', 'processing'])
            ->count());
        self::assertSame(0, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'sales_convert')
            ->where('reservation_id', (int)$reservation['id'])
            ->whereIn('status', ['pending', 'processing'])
            ->count());
    }

    public function test_procurement_inbound_backfill_updates_work_task_progress_and_sales_convert_once(): void
    {
        $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $goodsId = $this->createGoods('补齐预定商品', 'TM-FILL', '0.00');

        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [['goods_id' => $goodsId, 'num' => 5]],
        ]);
        self::assertNotFalse($reservation);
        $itemId = (int)$reservation['items'][0]['id'];
        $task = WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'procurement')
            ->where('source_type', 'sales_reservation_item')
            ->where('source_id', $itemId)
            ->find();
        self::assertNotNull($task);

        WorkTaskService::backfillProcurementInbound(1001, [[
            'id' => 2001,
            'goods_id' => $goodsId,
            'number' => '2.0000',
        ]]);
        $partial = WorkTask::find((int)$task->id);
        self::assertSame('2.0000', (string)$partial->progress_num);
        self::assertSame('processing', (string)$partial->status);

        WorkTaskService::backfillProcurementInbound(1001, [[
            'id' => 2001,
            'goods_id' => $goodsId,
            'number' => '2.0000',
        ]]);
        $deduped = WorkTask::find((int)$task->id);
        self::assertSame('2.0000', (string)$deduped->progress_num);

        WorkTaskService::backfillProcurementInbound(1002, [[
            'id' => 2002,
            'goods_id' => $goodsId,
            'number' => '3.0000',
        ]]);
        WorkTaskService::backfillProcurementInbound(1003, [[
            'id' => 2003,
            'goods_id' => $goodsId,
            'number' => '5.0000',
        ]]);

        $completed = WorkTask::find((int)$task->id);
        self::assertSame('5.0000', (string)$completed->progress_num);
        self::assertSame('completed', (string)$completed->status);
        self::assertSame('reserved', (string)SalesReservationItem::find($itemId)->status);
        self::assertSame('ready', (string)SalesReservation::find((int)$reservation['id'])->status);
        self::assertSame(1, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'sales_convert')
            ->where('reservation_id', (int)$reservation['id'])
            ->whereIn('status', ['pending', 'processing'])
            ->count());
    }

    public function test_tenant_isolation_blocks_cross_tenant_task_detail(): void
    {
        $managerId = $this->createEmployee('店长', self::MANAGER_ADMIN_ID, ['manager']);
        $taskId = $this->createTask('本租户任务', 'delivery', $managerId);

        $this->prepareRequestContext(self::OTHER_TENANT_ID, self::MANAGER_ADMIN_ID);
        $this->seedSystemRows(self::OTHER_TENANT_ID);
        TaskEmployee::create([
            'tenant_id' => self::OTHER_TENANT_ID,
            'name' => '其他租户店长',
            'admin_id' => self::MANAGER_ADMIN_ID,
            'user_id' => 0,
            'is_enabled' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        self::assertSame([], WorkTaskLogic::detail(['id' => $taskId]));
        self::assertSame(0, WorkTaskLogic::lists(['page_no' => 1, 'page_size' => 20])['count']);
    }

    private function assertImplementationClassesExist(): void
    {
        foreach ([
            TaskEmployeeLogic::class,
            WorkTaskLogic::class,
            WorkTaskService::class,
            TaskEmployee::class,
            TaskEmployeeRole::class,
            TaskRole::class,
            TaskType::class,
            TaskTypeRole::class,
            WorkTask::class,
        ] as $class) {
            self::assertTrue(class_exists($class), $class . ' should exist');
        }
    }

    private function prepareRequestContext(int $tenantId, int $adminId): void
    {
        request()->tenantId = $tenantId;
        request()->adminId = $adminId;
        request()->userId = $adminId;
        request()->adminInfo = [
            'admin_id' => $adminId,
            'tenant_id' => $tenantId,
        ];
    }

    private function ensureTaskTables(): void
    {
        $prefix = env('database.prefix', 'la_');
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$prefix}task_employee` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `mobile` varchar(30) NOT NULL DEFAULT '',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_admin` (`tenant_id`, `admin_id`),
  KEY `idx_tenant_user` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `code` varchar(50) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `is_system` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_employee_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `role_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `role_code` varchar(50) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_role` (`tenant_id`, `employee_id`, `role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_type` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `code` varchar(50) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `is_system` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_type_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `type_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `role_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `type_code` varchar(50) NOT NULL DEFAULT '',
  `role_code` varchar(50) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_role` (`tenant_id`, `type_code`, `role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}work_task` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sn` varchar(64) NOT NULL DEFAULT '',
  `type_code` varchar(50) NOT NULL DEFAULT '',
  `type_name` varchar(100) NOT NULL DEFAULT '',
  `source_type` varchar(50) NOT NULL DEFAULT '',
  `source_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `source_sn` varchar(64) NOT NULL DEFAULT '',
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_sn` varchar(64) NOT NULL DEFAULT '',
  `title` varchar(200) NOT NULL DEFAULT '',
  `content` text NULL,
  `assignee_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `assignee_employee_name` varchar(100) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `progress_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `target_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_source` (`tenant_id`, `source_type`, `source_id`),
  KEY `idx_tenant_status` (`tenant_id`, `status`),
  KEY `idx_tenant_assignee` (`tenant_id`, `assignee_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}work_task_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `action` varchar(50) NOT NULL DEFAULT '',
  `content` varchar(500) NOT NULL DEFAULT '',
  `operator_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `operator_admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`, `task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                Db::execute($statement);
            }
        }
    }

    private function cleanTenantData(int $tenantId): void
    {
        foreach ([
            'work_task_log', 'work_task', 'task_type_role', 'task_employee_role', 'task_type', 'task_role', 'task_employee',
            'inventory_reservation', 'sales_reservation_item', 'sales_reservation',
            'order_goods', 'sales_order', 'stock_flow', 'goods',
        ] as $table) {
            try {
                Db::name($table)->where('tenant_id', $tenantId)->delete();
            } catch (\Throwable) {
            }
        }
    }

    private function ensureSalesReservationTables(): void
    {
        $prefix = env('database.prefix', 'la_');
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$prefix}sales_reservation` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sn` varchar(64) NOT NULL DEFAULT '',
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `customer_name` varchar(100) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'draft',
  `total_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `shortage_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `converted_sales_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `remark` varchar(500) NOT NULL DEFAULT '',
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sn` (`tenant_id`, `sn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}sales_reservation_item` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_name` varchar(200) NOT NULL DEFAULT '',
  `goods_code` varchar(100) NOT NULL DEFAULT '',
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `unit_name` varchar(50) NOT NULL DEFAULT '',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `shortage_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(32) NOT NULL DEFAULT 'reserved',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_reservation` (`tenant_id`, `reservation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}inventory_reservation` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `consumed_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `released_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_goods_status` (`tenant_id`, `goods_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                Db::execute($statement);
            }
        }
    }

    private function createGoods(string $name, string $code, string $stock): int
    {
        return (int)Db::name('goods')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'product_code' => $code . '-' . uniqid(),
            'units' => '件',
            'unit_id' => 0,
            'price' => '1.00',
            'cost' => '0.00',
            'stock' => $stock,
            'category_id' => 0,
            'is_disabled' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function seedSystemRows(int $tenantId): void
    {
        WorkTaskService::ensureSystemDefaults($tenantId);
    }

    private function createEmployee(string $name, int $adminId, array $roleCodes): int
    {
        return $this->createEmployeeWithBindings($name, $adminId, 0, $roleCodes);
    }

    private function createEmployeeWithBindings(string $name, int $adminId, int $userId, array $roleCodes, int $isEnabled = 1): int
    {
        $this->seedSystemRows(self::TENANT_ID);
        $employee = TaskEmployee::create([
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'admin_id' => $adminId,
            'user_id' => $userId,
            'mobile' => '',
            'is_enabled' => $isEnabled,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        foreach ($roleCodes as $roleCode) {
            $role = TaskRole::where('tenant_id', self::TENANT_ID)->where('code', $roleCode)->find();
            TaskEmployeeRole::create([
                'tenant_id' => self::TENANT_ID,
                'employee_id' => (int)$employee->id,
                'role_id' => (int)$role->id,
                'role_code' => $roleCode,
                'create_time' => time(),
            ]);
        }
        return (int)$employee->id;
    }

    private function createTask(string $title, string $typeCode, int $assigneeEmployeeId, array $extra = []): int
    {
        $type = TaskType::where('tenant_id', self::TENANT_ID)->where('code', $typeCode)->find();
        $employee = $assigneeEmployeeId > 0 ? TaskEmployee::find($assigneeEmployeeId) : null;
        return (int)WorkTask::create(array_merge([
            'tenant_id' => self::TENANT_ID,
            'sn' => 'WT' . uniqid(),
            'type_code' => $typeCode,
            'type_name' => (string)$type->name,
            'source_type' => 'manual:' . uniqid(),
            'source_id' => 0,
            'source_sn' => '',
            'reservation_id' => 0,
            'reservation_sn' => '',
            'title' => $title,
            'content' => '',
            'assignee_employee_id' => $assigneeEmployeeId,
            'assignee_employee_name' => $employee ? (string)$employee->name : '',
            'status' => 'pending',
            'progress_num' => '0.0000',
            'target_num' => '1.0000',
            'create_by' => self::MANAGER_ADMIN_ID,
            'update_by' => self::MANAGER_ADMIN_ID,
            'create_time' => time(),
            'update_time' => time(),
        ], $extra))->id;
    }

}
