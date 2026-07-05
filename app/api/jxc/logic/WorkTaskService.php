<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\SalesReservation;
use app\common\model\jxc\SalesReservationItem;
use app\common\model\jxc\TaskEmployee;
use app\common\model\jxc\TaskEmployeeRole;
use app\common\model\jxc\TaskRole;
use app\common\model\jxc\TaskType;
use app\common\model\jxc\TaskTypeRole;
use app\common\model\jxc\WorkTask;
use app\common\model\jxc\WorkTaskLog;

class WorkTaskService
{
    private const SYSTEM_ROLES = [
        TaskRole::MANAGER => '店长',
        TaskRole::PROCUREMENT => '采购',
        TaskRole::DELIVERY => '配送',
        TaskRole::PACKING => '打包',
    ];

    private const SYSTEM_TYPES = [
        TaskType::PROCUREMENT => ['name' => '采购任务', 'roles' => [TaskRole::PROCUREMENT]],
        TaskType::DELIVERY => ['name' => '配送任务', 'roles' => [TaskRole::DELIVERY]],
        TaskType::PACKING => ['name' => '打包任务', 'roles' => [TaskRole::PACKING]],
        TaskType::SALES_CONVERT => ['name' => '预定转销售', 'roles' => [TaskRole::MANAGER]],
    ];

    public static function ensureSystemDefaults(?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? self::tenantId();
        if ($tenantId <= 0) {
            return;
        }

        foreach (self::SYSTEM_ROLES as $code => $name) {
            TaskRole::where('tenant_id', $tenantId)->where('code', $code)->findOrEmpty()->isEmpty()
                && TaskRole::create([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'is_system' => 1,
                    'is_enabled' => 1,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
        }

        foreach (self::SYSTEM_TYPES as $code => $config) {
            $type = TaskType::where('tenant_id', $tenantId)->where('code', $code)->findOrEmpty();
            if ($type->isEmpty()) {
                $type = TaskType::create([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $config['name'],
                    'is_system' => 1,
                    'is_enabled' => 1,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }

            foreach ($config['roles'] as $roleCode) {
                $role = TaskRole::where('tenant_id', $tenantId)->where('code', $roleCode)->find();
                if (!$role) {
                    continue;
                }
                TaskTypeRole::where('tenant_id', $tenantId)
                    ->where('type_code', $code)
                    ->where('role_code', $roleCode)
                    ->findOrEmpty()
                    ->isEmpty()
                    && TaskTypeRole::create([
                        'tenant_id' => $tenantId,
                        'type_id' => (int)$type->id,
                        'role_id' => (int)$role->id,
                        'type_code' => $code,
                        'role_code' => $roleCode,
                        'create_time' => time(),
                    ]);
            }
        }
    }

    public static function currentEmployee(): ?TaskEmployee
    {
        $adminId = self::adminId();
        $userId = (int)(request()->userId ?? 0);
        if ($adminId > 0) {
            $employee = self::employeeByBinding('admin_id', $adminId);
            if ($employee !== null) {
                return $employee;
            }
        }
        if ($userId > 0) {
            return self::employeeByBinding('user_id', $userId);
        }
        return null;
    }

    public static function currentEmployeeId(): int
    {
        return (int)(self::currentEmployee()->id ?? 0);
    }

    public static function isManager(?int $employeeId = null): bool
    {
        $employeeId = $employeeId ?: self::currentEmployeeId();
        if ($employeeId <= 0) {
            return false;
        }
        return in_array(TaskRole::MANAGER, self::employeeRoleCodes($employeeId), true);
    }

    public static function employeeRoleCodes(int $employeeId): array
    {
        if ($employeeId <= 0) {
            return [];
        }
        $roleCodes = TaskEmployeeRole::where('tenant_id', self::tenantId())
            ->where('employee_id', $employeeId)
            ->column('role_code');
        if (empty($roleCodes)) {
            return [];
        }
        return array_values(array_unique(TaskRole::where('tenant_id', self::tenantId())
            ->where('is_enabled', 1)
            ->whereIn('code', $roleCodes)
            ->column('code')));
    }

    public static function typeRoleCodes(string $typeCode): array
    {
        if ($typeCode === '') {
            return [];
        }
        return array_values(array_unique(TaskTypeRole::where('tenant_id', self::tenantId())
            ->where('type_code', $typeCode)
            ->column('role_code')));
    }

    public static function employeeExecutableTypeCodes(int $employeeId): array
    {
        $roleCodes = self::employeeRoleCodes($employeeId);
        if (empty($roleCodes)) {
            return [];
        }
        return array_values(array_unique(TaskTypeRole::where('tenant_id', self::tenantId())
            ->whereIn('role_code', $roleCodes)
            ->column('type_code')));
    }

    public static function employeeCanOperateType(int $employeeId, string $typeCode): bool
    {
        if ($employeeId <= 0 || self::activeEmployee($employeeId) === null) {
            return false;
        }
        if (self::isManager($employeeId)) {
            return true;
        }
        $roleCodes = self::employeeRoleCodes($employeeId);
        if (empty($roleCodes)) {
            return false;
        }
        return TaskTypeRole::where('tenant_id', self::tenantId())
            ->where('type_code', $typeCode)
            ->whereIn('role_code', $roleCodes)
            ->count() > 0;
    }

    public static function create(array $params): array|false
    {
        self::ensureSystemDefaults();
        $typeCode = (string)($params['type_code'] ?? '');
        $type = TaskType::where('tenant_id', self::tenantId())->where('code', $typeCode)->where('is_enabled', 1)->findOrEmpty();
        if ($type->isEmpty()) {
            return false;
        }

        $assigneeId = (int)($params['assignee_employee_id'] ?? 0);
        $assignee = null;
        if ($assigneeId > 0) {
            $assignee = self::activeEmployee($assigneeId);
            if ($assignee === null || !self::employeeCanOperateType($assigneeId, $typeCode)) {
                return false;
            }
        }

        $sourceType = (string)($params['source_type'] ?? 'manual');
        if ($sourceType === 'manual' && (int)($params['source_id'] ?? 0) === 0) {
            $sourceType = 'manual:' . uniqid('', true);
        }

        $task = WorkTask::create([
            'tenant_id' => self::tenantId(),
            'sn' => self::generateSn(),
            'type_code' => $typeCode,
            'type_name' => (string)$type->name,
            'source_type' => $sourceType,
            'source_id' => (int)($params['source_id'] ?? 0),
            'source_sn' => (string)($params['source_sn'] ?? ''),
            'reservation_id' => (int)($params['reservation_id'] ?? 0),
            'reservation_sn' => (string)($params['reservation_sn'] ?? ''),
            'title' => trim((string)($params['title'] ?? $type->name)),
            'content' => (string)($params['content'] ?? ''),
            'assignee_employee_id' => $assigneeId,
            'assignee_employee_name' => $assignee ? (string)$assignee->name : '',
            'status' => WorkTask::STATUS_PENDING,
            'progress_num' => self::qty($params['progress_num'] ?? 0),
            'target_num' => self::qty($params['target_num'] ?? 0),
            'create_by' => self::adminId(),
            'update_by' => self::adminId(),
            'create_time' => time(),
            'update_time' => time(),
        ]);
        self::log((int)$task->id, 'create', '创建任务');
        return self::format($task->toArray());
    }

    public static function format(array $task): array
    {
        $status = (string)($task['status'] ?? '');
        $progress = self::qty($task['progress_num'] ?? 0);
        $target = self::qty($task['target_num'] ?? 0);

        return [
            'id' => (int)($task['id'] ?? 0),
            'sn' => (string)($task['sn'] ?? ''),
            'type_code' => (string)($task['type_code'] ?? ''),
            'type_name' => (string)($task['type_name'] ?? ''),
            'source_type' => (string)($task['source_type'] ?? ''),
            'source_id' => (int)($task['source_id'] ?? 0),
            'source_sn' => (string)($task['source_sn'] ?? ''),
            'reservation_id' => (int)($task['reservation_id'] ?? 0),
            'reservation_sn' => (string)($task['reservation_sn'] ?? ''),
            'title' => (string)($task['title'] ?? ''),
            'content' => (string)($task['content'] ?? ''),
            'assignee_employee_id' => (int)($task['assignee_employee_id'] ?? 0),
            'assignee_employee_name' => (string)($task['assignee_employee_name'] ?? ''),
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'progress_num' => $progress,
            'target_num' => $target,
            'progress_text' => $progress . '/' . $target,
            'create_time' => $task['create_time'] ?? '',
            'update_time' => $task['update_time'] ?? '',
            'actions' => self::actions($task),
            'logs' => self::logs((int)($task['id'] ?? 0)),
        ];
    }

    public static function actions(array $task): array
    {
        $status = (string)($task['status'] ?? '');
        $currentEmployeeId = self::currentEmployeeId();
        $isManager = self::isManager($currentEmployeeId);
        $isAssignee = $currentEmployeeId > 0 && $currentEmployeeId === (int)($task['assignee_employee_id'] ?? 0);
        $hasTypeRole = self::employeeCanOperateType($currentEmployeeId, (string)($task['type_code'] ?? ''));
        $isOpen = in_array($status, [WorkTask::STATUS_PENDING, WorkTask::STATUS_PROCESSING], true);

        return [
            'can_edit' => $isManager && $status === WorkTask::STATUS_PENDING,
            'can_assign' => $isManager && $isOpen,
            'can_start' => $status === WorkTask::STATUS_PENDING && ($isManager || $isAssignee || $hasTypeRole),
            'can_complete' => $status === WorkTask::STATUS_PROCESSING && ($isManager || $isAssignee || $hasTypeRole),
            'can_cancel' => $isManager && $isOpen,
            'can_view_source' => self::hasViewableSource($task),
        ];
    }

    public static function createProcurementWorkTaskForReservationItem(int $reservationItemId): array|false
    {
        self::ensureSystemDefaults();
        $item = SalesReservationItem::where('id', $reservationItemId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($item->isEmpty() || bccomp((string)$item->shortage_num, '0', 4) <= 0) {
            return false;
        }

        $existing = WorkTask::where('tenant_id', self::tenantId())
            ->where('type_code', TaskType::PROCUREMENT)
            ->where('source_type', 'sales_reservation_item')
            ->where('source_id', (int)$item->id)
            ->whereIn('status', [WorkTask::STATUS_PENDING, WorkTask::STATUS_PROCESSING])
            ->find();
        if ($existing) {
            return self::format($existing->toArray());
        }

        $reservation = SalesReservation::where('id', (int)$item->reservation_id)
            ->where('tenant_id', self::tenantId())
            ->find();

        return self::create([
            'type_code' => TaskType::PROCUREMENT,
            'source_type' => 'sales_reservation_item',
            'source_id' => (int)$item->id,
            'source_sn' => (string)($reservation->sn ?? ''),
            'reservation_id' => (int)$item->reservation_id,
            'reservation_sn' => (string)($reservation->sn ?? ''),
            'title' => '缺货采购-' . (string)$item->goods_name,
            'content' => (string)$item->goods_code,
            'progress_num' => '0.0000',
            'target_num' => self::qty($item->shortage_num),
        ]);
    }

    public static function backfillProcurementInbound(int $supplyOrderId, array $rows): void
    {
        self::ensureSystemDefaults();
        foreach ($rows as $row) {
            $goodsId = (int)($row['goods_id'] ?? 0);
            $remaining = self::qty($row['number'] ?? $row['inbound_num'] ?? 0);
            if ($goodsId <= 0 || bccomp($remaining, '0', 4) <= 0) {
                continue;
            }

            $tasks = WorkTask::where('tenant_id', self::tenantId())
                ->where('type_code', TaskType::PROCUREMENT)
                ->where('source_type', 'sales_reservation_item')
                ->whereIn('status', [WorkTask::STATUS_PENDING, WorkTask::STATUS_PROCESSING])
                ->order(['id' => 'asc'])
                ->select();

            foreach ($tasks as $task) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }

                $item = SalesReservationItem::where('id', (int)$task->source_id)
                    ->where('tenant_id', self::tenantId())
                    ->findOrEmpty();
                if ($item->isEmpty() || !self::procurementInboundMatchesReservationItem($item, $goodsId, $row)) {
                    continue;
                }

                $inboundKey = self::procurementInboundKey($supplyOrderId, (int)($row['id'] ?? 0));
                if (self::hasInboundLog((int)$task->id, $inboundKey)) {
                    continue;
                }

                $need = bcsub((string)$task->target_num, (string)$task->progress_num, 4);
                if (bccomp($need, '0', 4) <= 0) {
                    continue;
                }

                $inboundNum = bccomp($remaining, $need, 4) > 0 ? $need : $remaining;
                $progress = bcadd((string)$task->progress_num, $inboundNum, 4);
                $fulfilled = bccomp($progress, (string)$task->target_num, 4) >= 0;
                $task->save([
                    'progress_num' => self::qty($progress),
                    'status' => $fulfilled ? WorkTask::STATUS_COMPLETED : WorkTask::STATUS_PROCESSING,
                    'update_by' => self::adminId(),
                    'update_time' => time(),
                ]);
                self::log((int)$task->id, 'procurement_inbound', $inboundKey . ':' . self::qty($inboundNum));

                if ($fulfilled) {
                    self::markReservationItemReady($task->toArray(), $item);
                }

                $remaining = bcsub($remaining, $inboundNum, 4);
            }
        }
    }

    public static function cancelOpenProcurementWorkTasksForReservation(int $reservationId): void
    {
        if ($reservationId <= 0) {
            return;
        }
        WorkTask::where('tenant_id', self::tenantId())
            ->where('type_code', TaskType::PROCUREMENT)
            ->where('reservation_id', $reservationId)
            ->whereIn('status', [WorkTask::STATUS_PENDING, WorkTask::STATUS_PROCESSING])
            ->update([
                'status' => WorkTask::STATUS_CANCELLED,
                'update_by' => self::adminId(),
                'update_time' => time(),
            ]);
    }

    public static function createSalesConvertTask(array $params): array
    {
        self::ensureSystemDefaults();
        $reservationId = (int)($params['reservation_id'] ?? 0);
        $existing = WorkTask::where('tenant_id', self::tenantId())
            ->where('type_code', TaskType::SALES_CONVERT)
            ->where('reservation_id', $reservationId)
            ->whereIn('status', [WorkTask::STATUS_PENDING, WorkTask::STATUS_PROCESSING])
            ->find();
        if ($existing) {
            return self::format($existing->toArray());
        }

        $created = self::create([
            'type_code' => TaskType::SALES_CONVERT,
            'source_type' => 'sales_reservation',
            'source_id' => $reservationId,
            'source_sn' => (string)($params['reservation_sn'] ?? ''),
            'reservation_id' => $reservationId,
            'reservation_sn' => (string)($params['reservation_sn'] ?? ''),
            'title' => (string)($params['title'] ?? '预定转销售'),
            'content' => (string)($params['content'] ?? ''),
        ]);

        return $created ?: [];
    }

    public static function statusLabel(string $status): string
    {
        return [
            WorkTask::STATUS_PENDING => '待处理',
            WorkTask::STATUS_PROCESSING => '进行中',
            WorkTask::STATUS_COMPLETED => '已完成',
            WorkTask::STATUS_CANCELLED => '已取消',
        ][$status] ?? $status;
    }

    public static function log(int $taskId, string $action, string $content): void
    {
        WorkTaskLog::create([
            'tenant_id' => self::tenantId(),
            'task_id' => $taskId,
            'action' => $action,
            'content' => $content,
            'operator_employee_id' => self::currentEmployeeId(),
            'operator_admin_id' => self::adminId(),
            'create_time' => time(),
        ]);
    }

    private static function logs(int $taskId): array
    {
        if ($taskId <= 0) {
            return [];
        }
        return array_map(static fn(array $log): array => [
            'id' => (int)($log['id'] ?? 0),
            'action' => (string)($log['action'] ?? ''),
            'content' => (string)($log['content'] ?? ''),
            'operator_employee_id' => (int)($log['operator_employee_id'] ?? 0),
            'operator_admin_id' => (int)($log['operator_admin_id'] ?? 0),
            'create_time' => $log['create_time'] ?? '',
        ], WorkTaskLog::where('tenant_id', self::tenantId())
            ->where('task_id', $taskId)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray());
    }

    private static function typeName(string $typeCode): string
    {
        $type = TaskType::where('tenant_id', self::tenantId())->where('code', $typeCode)->find();
        return $type ? (string)$type->name : (self::SYSTEM_TYPES[$typeCode]['name'] ?? $typeCode);
    }

    private static function hasViewableSource(array $task): bool
    {
        $sourceType = (string)($task['source_type'] ?? '');
        if ($sourceType === '' || str_starts_with($sourceType, 'manual')) {
            return false;
        }
        return (int)($task['source_id'] ?? 0) > 0 || (int)($task['reservation_id'] ?? 0) > 0 || (string)($task['source_sn'] ?? '') !== '';
    }

    private static function hasInboundLog(int $taskId, string $inboundKey): bool
    {
        return WorkTaskLog::where('tenant_id', self::tenantId())
            ->where('task_id', $taskId)
            ->where('action', 'procurement_inbound')
            ->where('content', 'like', $inboundKey . ':%')
            ->count() > 0;
    }

    private static function procurementInboundKey(int $supplyOrderId, int $rowId): string
    {
        return 'supply_order:' . $supplyOrderId . ':row:' . $rowId;
    }

    private static function procurementInboundMatchesReservationItem(SalesReservationItem $item, int $goodsId, array $row): bool
    {
        return (int)$item->goods_id === $goodsId
            && (int)$item->warehouse_id === (int)($row['warehouse_id'] ?? 0)
            && (int)$item->sku_id === (int)($row['sku_id'] ?? 0)
            && (int)$item->spec_id === (int)($row['spec_id'] ?? 0);
    }

    private static function markReservationItemReady(array $task, SalesReservationItem $item): void
    {
        if ((string)$item->status === SalesReservationItem::STATUS_GAP_CLOSED) {
            return;
        }

        if (bccomp((string)$item->shortage_num, '0', 4) > 0) {
            InventoryReservationService::reserve($item->toArray(), (string)$item->shortage_num);
        }

        $item->save([
            'reserved_num' => self::qty($item->num),
            'shortage_num' => '0.0000',
            'status' => SalesReservationItem::STATUS_RESERVED,
            'update_time' => time(),
        ]);

        $reservation = SalesReservation::where('id', (int)($task['reservation_id'] ?? 0))
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($reservation->isEmpty() || (string)$reservation->status === SalesReservation::STATUS_GAP_CLOSED) {
            return;
        }

        $openShortage = SalesReservationItem::where('reservation_id', (int)$reservation->id)
            ->where('tenant_id', self::tenantId())
            ->whereIn('status', [SalesReservationItem::STATUS_SHORTAGE, SalesReservationItem::STATUS_GAP_CLOSED])
            ->count();
        if ($openShortage == 0) {
            $reservation->save([
                'status' => SalesReservation::STATUS_READY,
                'reserved_num' => self::qty($reservation->total_num),
                'shortage_num' => '0.0000',
                'update_by' => self::adminId(),
                'update_time' => time(),
            ]);
            self::createSalesConvertTask([
                'reservation_id' => (int)$reservation->id,
                'reservation_sn' => (string)$reservation->sn,
                'title' => '预定转销售',
            ]);
        }
    }

    private static function employeeByBinding(string $field, int $id): ?TaskEmployee
    {
        $employee = TaskEmployee::where('tenant_id', self::tenantId())
            ->where('is_enabled', 1)
            ->where($field, $id)
            ->findOrEmpty();
        return $employee->isEmpty() ? null : $employee;
    }

    private static function activeEmployee(int $employeeId): ?TaskEmployee
    {
        $employee = TaskEmployee::where('tenant_id', self::tenantId())
            ->where('id', $employeeId)
            ->where('is_enabled', 1)
            ->findOrEmpty();
        return $employee->isEmpty() ? null : $employee;
    }

    private static function generateSn(): string
    {
        return 'WT' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function qty(mixed $value): string
    {
        return number_format((float)$value, 4, '.', '');
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function adminId(): int
    {
        return (int)(request()->adminId ?? 0);
    }
}
