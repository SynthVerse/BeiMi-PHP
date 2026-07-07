<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\InventoryReservation;
use app\common\model\jxc\SalesReservation;
use app\common\model\jxc\SalesReservationItem;
use app\common\model\jxc\TaskEmployee;
use app\common\model\jxc\TaskEmployeeRole;
use app\common\model\jxc\TaskPrintLog;
use app\common\model\jxc\WorkTask;
use app\common\model\jxc\WorkTaskLog;
use think\facade\Db;

class TaskCenterService
{
    private const ROLE_LABELS = [
        WorkTask::ROLE_PACKING => '打包',
        WorkTask::ROLE_FISH_KILL => '杀鱼',
        WorkTask::ROLE_PROCUREMENT => '采购',
        WorkTask::ROLE_MANAGER => '店长',
    ];

    private const OPEN_STATUSES = [
        WorkTask::STATUS_PENDING,
        WorkTask::STATUS_ASSIGNED,
        WorkTask::STATUS_PROCESSING,
        WorkTask::STATUS_BLOCKED,
    ];

    private const TERMINAL_STATUSES = [
        WorkTask::STATUS_COMPLETED,
        WorkTask::STATUS_CANCELLED,
    ];

    private const PRINT_RESULT_STATUSES = [
        'print_success' => 'success',
        'print_failed' => 'failed',
        'print_simulated' => 'simulated',
    ];

    private const STATUS_TRANSITIONS = [
        WorkTask::STATUS_PENDING => [
            WorkTask::STATUS_ASSIGNED,
            WorkTask::STATUS_PROCESSING,
            WorkTask::STATUS_BLOCKED,
            WorkTask::STATUS_CANCELLED,
        ],
        WorkTask::STATUS_ASSIGNED => [
            WorkTask::STATUS_PROCESSING,
            WorkTask::STATUS_BLOCKED,
            WorkTask::STATUS_CANCELLED,
        ],
        WorkTask::STATUS_PROCESSING => [
            WorkTask::STATUS_BLOCKED,
            WorkTask::STATUS_COMPLETED,
            WorkTask::STATUS_CANCELLED,
        ],
        WorkTask::STATUS_BLOCKED => [
            WorkTask::STATUS_PROCESSING,
            WorkTask::STATUS_CANCELLED,
        ],
        WorkTask::STATUS_COMPLETED => [],
        WorkTask::STATUS_CANCELLED => [],
    ];

    public static function dashboard(array $params = []): array
    {
        $query = self::taskQuery($params);
        $rows = $query->field('task_kind,role_code,status,COUNT(*) as total')
            ->group('task_kind,role_code,status')
            ->select()
            ->toArray();

        $summary = [
            'total' => 0,
            'by_status' => [],
            'by_kind' => [],
            'by_role' => [],
        ];
        foreach ($rows as $row) {
            $total = (int)($row['total'] ?? 0);
            $summary['total'] += $total;
            $summary['by_status'][(string)$row['status']] = ($summary['by_status'][(string)$row['status']] ?? 0) + $total;
            $summary['by_kind'][(string)$row['task_kind']] = ($summary['by_kind'][(string)$row['task_kind']] ?? 0) + $total;
            $summary['by_role'][(string)$row['role_code']] = ($summary['by_role'][(string)$row['role_code']] ?? 0) + $total;
        }

        return $summary;
    }

    public static function reservationsSelect(array $params = []): array
    {
        $query = SalesReservation::where('tenant_id', self::tenantId())
            ->whereNotIn('status', [SalesReservation::STATUS_CANCELLED, SalesReservation::STATUS_CONVERTED])
            ->order(['id' => 'desc']);
        if (($params['keyword'] ?? '') !== '') {
            $keyword = trim((string)$params['keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('sn', 'like', '%' . $keyword . '%')
                    ->whereOr('customer_name', 'like', '%' . $keyword . '%');
            });
        }
        if (($params['status'] ?? '') !== '') {
            $query->where('status', (string)$params['status']);
        }

        $pageNo = max(1, (int)($params['page_no'] ?? $params['page'] ?? 1));
        $pageSize = max(1, (int)($params['page_size'] ?? $params['pagesize'] ?? 20));
        $count = $query->count();
        $rows = $query->page($pageNo, $pageSize)->select()->toArray();
        $ids = array_column($rows, 'id');
        $itemCounts = empty($ids) ? [] : SalesReservationItem::where('tenant_id', self::tenantId())
            ->whereIn('reservation_id', $ids)
            ->field('reservation_id,COUNT(*) as total')
            ->group('reservation_id')
            ->column('total', 'reservation_id');

        return [
            'lists' => array_map(static function (array $row) use ($itemCounts): array {
                return [
                    'id' => (int)$row['id'],
                    'sn' => (string)$row['sn'],
                    'customer_id' => (int)$row['customer_id'],
                    'customer_name' => (string)$row['customer_name'],
                    'status' => (string)$row['status'],
                    'total_num' => self::qty($row['total_num'] ?? 0),
                    'reserved_num' => self::qty($row['reserved_num'] ?? 0),
                    'shortage_num' => self::qty($row['shortage_num'] ?? 0),
                    'item_count' => (int)($itemCounts[(int)$row['id']] ?? 0),
                ];
            }, $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    public static function preview(array $params): array
    {
        $rows = self::selectedReservationItems($params);
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::formatReservationItemForTask($row, [
                'available_num' => InventoryReservationService::availableForGoods((int)$row['goods_id']),
            ]);
        }

        return [
            'items' => $items,
            'summary' => self::summaryFromItems($items),
        ];
    }

    public static function saveAssignment(array $params): array
    {
        $taskDate = self::normalizeTaskDate($params['task_date'] ?? null);
        $priority = self::normalizePriority((string)($params['priority'] ?? 'normal'));
        $assignmentRows = self::normalizeAssignmentRows($params);
        $roleAssignments = self::normalizeRoleAssignments($params);
        $fulfillmentRoles = self::fulfillmentRoles($params, $roleAssignments);
        $items = self::selectedReservationItems($params);
        $assignmentsByItem = [];
        foreach ($assignmentRows as $assignment) {
            $assignmentsByItem[(int)$assignment['reservation_item_id']][] = $assignment;
        }

        $created = ['fulfillment' => 0, 'procurement' => 0];
        $updated = ['fulfillment' => 0, 'procurement' => 0];
        $taskIds = [];

        Db::startTrans();
        try {
            foreach ($items as $item) {
                $parentTaskId = 0;
                $itemAssignments = $assignmentsByItem[(int)$item['id']] ?? [];
                if (!empty($itemAssignments)) {
                    usort($itemAssignments, static function (array $left, array $right): int {
                        return ((string)$left['role_code'] === WorkTask::ROLE_PROCUREMENT ? 1 : 0)
                            <=> ((string)$right['role_code'] === WorkTask::ROLE_PROCUREMENT ? 1 : 0);
                    });
                    foreach ($itemAssignments as $assignment) {
                        $roleCode = (string)$assignment['role_code'];
                        $taskKind = $roleCode === WorkTask::ROLE_PROCUREMENT
                            ? WorkTask::KIND_PROCUREMENT
                            : WorkTask::KIND_FULFILLMENT;
                        if ($taskKind === WorkTask::KIND_PROCUREMENT && bccomp((string)$item['shortage_num'], '0', 4) <= 0) {
                            continue;
                        }
                        $task = self::upsertTaskForItem(
                            $item,
                            $taskKind,
                            $roleCode,
                            (int)$assignment['employee_id'],
                            $taskDate,
                            self::normalizePriority((string)$assignment['priority']),
                            $taskKind === WorkTask::KIND_PROCUREMENT ? $parentTaskId : 0
                        );
                        $bucket = $taskKind === WorkTask::KIND_PROCUREMENT ? 'procurement' : 'fulfillment';
                        $created[$bucket] += $task['was_created'] ? 1 : 0;
                        $updated[$bucket] += $task['was_created'] ? 0 : 1;
                        if ($taskKind === WorkTask::KIND_FULFILLMENT) {
                            $parentTaskId = $parentTaskId ?: (int)$task['id'];
                        }
                        $taskIds[] = (int)$task['id'];
                    }
                    continue;
                }

                foreach ($fulfillmentRoles as $roleCode) {
                    $task = self::upsertTaskForItem(
                        $item,
                        WorkTask::KIND_FULFILLMENT,
                        $roleCode,
                        (int)($roleAssignments[$roleCode] ?? 0),
                        $taskDate,
                        $priority,
                        0
                    );
                    $created['fulfillment'] += $task['was_created'] ? 1 : 0;
                    $updated['fulfillment'] += $task['was_created'] ? 0 : 1;
                    $parentTaskId = $parentTaskId ?: (int)$task['id'];
                    $taskIds[] = (int)$task['id'];
                }

                if (bccomp((string)$item['shortage_num'], '0', 4) > 0) {
                    $task = self::upsertTaskForItem(
                        $item,
                        WorkTask::KIND_PROCUREMENT,
                        WorkTask::ROLE_PROCUREMENT,
                        (int)($roleAssignments[WorkTask::ROLE_PROCUREMENT] ?? 0),
                        $taskDate,
                        $priority,
                        $parentTaskId
                    );
                    $created['procurement'] += $task['was_created'] ? 1 : 0;
                    $updated['procurement'] += $task['was_created'] ? 0 : 1;
                    $taskIds[] = (int)$task['id'];
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return [
            'task_ids' => array_values(array_unique($taskIds)),
            'created' => $created,
            'updated' => $updated,
            'summary' => [
                'reservation_count' => count(array_values(array_unique(array_column($items, 'reservation_id')))),
                'item_count' => count($items),
            ],
        ];
    }

    public static function items(array $params = []): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? $params['page'] ?? 1));
        $pageSize = max(1, (int)($params['page_size'] ?? $params['pagesize'] ?? 20));
        $query = self::taskQuery($params)->order(['id' => 'desc']);
        $count = $query->count();
        $rows = $query->page($pageNo, $pageSize)->select()->toArray();

        return [
            'lists' => array_map([self::class, 'formatTask'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    public static function employeeBoard(array $params = []): array
    {
        $employees = TaskEmployee::where('tenant_id', self::tenantId())
            ->where('is_enabled', 1)
            ->order(['id' => 'asc'])
            ->select()
            ->toArray();
        $employeeIds = array_column($employees, 'id');
        $roles = empty($employeeIds) ? [] : TaskEmployeeRole::whereIn('employee_id', $employeeIds)
            ->select()
            ->toArray();
        $roleMap = [];
        foreach ($roles as $role) {
            $roleMap[(int)$role['employee_id']][] = (string)$role['role_code'];
        }

        $taskRows = self::taskQuery($params)
            ->whereIn('status', self::OPEN_STATUSES)
            ->order(['id' => 'asc'])
            ->select()
            ->toArray();
        $taskMap = [];
        foreach ($taskRows as $task) {
            $taskMap[(int)$task['assignee_employee_id']][] = self::formatTask($task);
        }

        return [
            'employees' => array_map(static function (array $employee) use ($roleMap, $taskMap): array {
                $id = (int)$employee['id'];
                return [
                    'id' => $id,
                    'name' => (string)$employee['name'],
                    'mobile' => (string)($employee['mobile'] ?? ''),
                    'role_codes' => array_values(array_unique($roleMap[$id] ?? [])),
                    'tasks' => $taskMap[$id] ?? [],
                ];
            }, $employees),
            'unassigned' => $taskMap[0] ?? [],
        ];
    }

    public static function procurementShortage(array $params = []): array
    {
        $params['task_kind'] = WorkTask::KIND_PROCUREMENT;
        $query = self::taskQuery($params)
            ->whereIn('status', self::OPEN_STATUSES)
            ->order(['id' => 'asc']);
        $rows = $query->select()->toArray();

        return [
            'lists' => array_map([self::class, 'formatTask'], $rows),
            'count' => count($rows),
        ];
    }

    public static function printData(array $params): array
    {
        $taskIds = self::normalizeIds($params['task_ids'] ?? $params['ids'] ?? []);
        $query = WorkTask::where('tenant_id', self::tenantId());
        if (!empty($taskIds)) {
            $query->whereIn('id', $taskIds);
        } else {
            self::applyTaskFilters($query, $params);
        }
        $tasks = $query->order(['id' => 'asc'])->select()->toArray();
        $taskIds = array_values(array_map('intval', array_column($tasks, 'id')));
        $reservationItemIds = array_values(array_unique(array_map('intval', array_column($tasks, 'source_id'))));
        $printNo = self::generatePrintNo();
        $payload = [
            'title' => '任务打印',
            'items' => array_map([self::class, 'formatPrintItem'], $tasks),
        ];

        return [
            'print_no' => $printNo,
            'task_date' => self::normalizeTaskDate($params['task_date'] ?? ($tasks[0]['task_date'] ?? null)),
            'scope' => (string)($params['scope'] ?? 'task'),
            'task_ids' => $taskIds,
            'reservation_item_ids' => $reservationItemIds,
            'device' => [
                'id' => (string)($params['device_id'] ?? ''),
                'name' => (string)($params['device_name'] ?? ''),
            ],
            'payload' => $payload,
            'print_payload' => $payload,
        ];
    }

    public static function status(array $params): array|false
    {
        $status = (string)($params['status'] ?? '');
        if (isset(self::PRINT_RESULT_STATUSES[$status])) {
            return self::recordPrintResult($params, self::PRINT_RESULT_STATUSES[$status], $status);
        }
        if (!in_array($status, WorkTask::VALID_STATUSES, true)) {
            return false;
        }
        $ids = self::normalizeIds($params['task_ids'] ?? $params['ids'] ?? ($params['id'] ?? 0));
        if (empty($ids)) {
            return false;
        }

        $tasks = WorkTask::where('tenant_id', self::tenantId())
            ->whereIn('id', $ids)
            ->select();
        $taskMap = [];
        foreach ($tasks as $task) {
            $taskMap[(int)$task->id] = $task;
        }
        if (count($taskMap) !== count($ids)) {
            return false;
        }

        $employee = self::currentEmployee();
        $canManage = self::canManageTaskCenter($employee);
        foreach ($ids as $id) {
            $task = $taskMap[$id];
            if (!self::canOperateTask($task->toArray(), $employee, $canManage)) {
                return false;
            }
            if (!self::canTransition((string)$task->status, $status)) {
                return false;
            }
        }

        Db::startTrans();
        try {
            foreach ($ids as $id) {
                $task = $taskMap[$id];
                $from = (string)$task->status;
                $progress = $status === WorkTask::STATUS_COMPLETED
                    ? (string)$task->demand_num
                    : self::qty($params['progress_num'] ?? $task->progress_num);
                $task->save([
                    'status' => $status,
                    'progress_num' => $progress,
                    'status_reason' => (string)($params['status_reason'] ?? $params['reason'] ?? ''),
                    'stock_status' => (string)$task->task_kind === WorkTask::KIND_PROCUREMENT && $status === WorkTask::STATUS_COMPLETED
                        ? WorkTask::STOCK_PROCUREMENT_DONE
                        : (string)$task->stock_status,
                    'update_by' => self::adminId(),
                    'update_time' => time(),
                ]);
                self::log((int)$task->id, 'status', (string)($params['status_reason'] ?? ''), $from, $status, $params);
                if ((string)$task->task_kind === WorkTask::KIND_PROCUREMENT && $status === WorkTask::STATUS_COMPLETED) {
                    self::completeProcurementTask($task->toArray());
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return self::items(['ids' => $ids, 'page_size' => count($ids)]);
    }

    private static function recordPrintResult(array $params, string $result, string $action): array|false
    {
        $ids = self::normalizeIds($params['task_ids'] ?? $params['ids'] ?? ($params['id'] ?? 0));
        if (empty($ids)) {
            return false;
        }

        $tasks = WorkTask::where('tenant_id', self::tenantId())
            ->whereIn('id', $ids)
            ->select();
        $taskRows = [];
        foreach ($tasks as $task) {
            $taskRows[(int)$task->id] = $task;
        }
        if (count($taskRows) !== count($ids)) {
            return false;
        }

        $employee = self::currentEmployee();
        $canManage = self::canManageTaskCenter($employee);
        foreach ($ids as $id) {
            if (!self::canOperateTask($taskRows[$id]->toArray(), $employee, $canManage)) {
                return false;
            }
        }

        $now = time();
        $reservationItemIds = [];
        foreach ($taskRows as $task) {
            $sourceId = (int)$task->source_id;
            if ($sourceId > 0) {
                $reservationItemIds[] = $sourceId;
            }
        }
        $reservationItemIds = array_values(array_unique($reservationItemIds));
        $employeeId = (int)($params['employee_id'] ?? 0);
        $printedBy = $employeeId > 0 ? self::activeEmployee($employeeId) : null;

        Db::startTrans();
        try {
            if ($result === 'success') {
                WorkTask::where('tenant_id', self::tenantId())
                    ->whereIn('id', $ids)
                    ->inc('print_count')
                    ->update(['last_print_time' => $now, 'update_time' => $now]);
            }

            TaskPrintLog::create([
                'tenant_id' => self::tenantId(),
                'print_no' => self::generatePrintNo(),
                'task_date' => self::normalizeTaskDate($params['task_date'] ?? ($taskRows[$ids[0]]->task_date ?? null)),
                'scope' => (string)($params['scope'] ?? 'task'),
                'employee_id' => $employeeId,
                'employee_name' => $printedBy ? (string)$printedBy->name : '',
                'role_code' => (string)($params['role_code'] ?? ''),
                'task_ids_json' => json_encode($ids, JSON_UNESCAPED_UNICODE),
                'reservation_item_ids_json' => json_encode($reservationItemIds, JSON_UNESCAPED_UNICODE),
                'device_id' => (string)($params['device_id'] ?? ''),
                'device_name' => (string)($params['device_name'] ?? ''),
                'result' => $result,
                'error_code' => (string)($params['error_code'] ?? ''),
                'error_message' => (string)($params['error_message'] ?? $params['status_reason'] ?? ''),
                'create_by' => self::adminId(),
                'create_time' => $now,
            ]);

            foreach ($ids as $id) {
                $task = $taskRows[$id];
                self::log((int)$task->id, $action, (string)($params['status_reason'] ?? ''), (string)$task->status, (string)$task->status, $params);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return self::items(['ids' => $ids, 'page_size' => count($ids)]);
    }

    public static function cancelByReservation(int $reservationId): void
    {
        if ($reservationId <= 0) {
            return;
        }
        $tasks = WorkTask::where('tenant_id', self::tenantId())
            ->where('reservation_id', $reservationId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->select();
        foreach ($tasks as $task) {
            $from = (string)$task->status;
            $task->save([
                'status' => WorkTask::STATUS_CANCELLED,
                'status_reason' => '销售预定取消',
                'update_by' => self::adminId(),
                'update_time' => time(),
            ]);
            self::log((int)$task->id, 'cancel_by_reservation', '销售预定取消', $from, WorkTask::STATUS_CANCELLED);
        }
    }

    public static function applyProcurementInbound(int $supplyOrderId, array $rows): void
    {
        foreach ($rows as $row) {
            $goodsId = (int)($row['goods_id'] ?? 0);
            $remaining = self::qty($row['number'] ?? $row['inbound_num'] ?? $row['actual_base_qty'] ?? 0);
            if ($goodsId <= 0 || bccomp($remaining, '0', 4) <= 0) {
                continue;
            }

            // P0 intentionally matches procurement inbound by same tenant + goods_id + open FIFO only.
            // SKU, warehouse, batch, and complex unit matching belong to P2.
            $tasks = WorkTask::where('tenant_id', self::tenantId())
                ->where('task_kind', WorkTask::KIND_PROCUREMENT)
                ->where('goods_id', $goodsId)
                ->whereIn('status', self::OPEN_STATUSES)
                ->order(['id' => 'asc'])
                ->select();

            foreach ($tasks as $task) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }
                $inboundKey = self::inboundKey($supplyOrderId, (int)($row['id'] ?? 0));
                if (self::hasInboundLog((int)$task->id, $inboundKey)) {
                    continue;
                }

                $need = bcsub((string)$task->demand_num, (string)$task->progress_num, 4);
                if (bccomp($need, '0', 4) <= 0) {
                    continue;
                }
                $inboundNum = bccomp($remaining, $need, 4) > 0 ? $need : $remaining;
                $progress = bcadd((string)$task->progress_num, $inboundNum, 4);
                $fulfilled = bccomp($progress, (string)$task->demand_num, 4) >= 0;
                $from = (string)$task->status;
                $to = $fulfilled ? WorkTask::STATUS_COMPLETED : WorkTask::STATUS_PROCESSING;
                $task->save([
                    'progress_num' => self::qty($progress),
                    'status' => $to,
                    'stock_status' => $fulfilled ? WorkTask::STOCK_PROCUREMENT_DONE : WorkTask::STOCK_SHORTAGE,
                    'update_by' => self::adminId(),
                    'update_time' => time(),
                ]);
                self::log((int)$task->id, 'procurement_inbound', $inboundKey . ':' . self::qty($inboundNum), $from, $to, $row);

                if ($fulfilled) {
                    self::completeProcurementTask($task->toArray());
                }
                $remaining = bcsub($remaining, $inboundNum, 4);
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
        return $userId > 0 ? self::employeeByBinding('user_id', $userId) : null;
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
        $employee = TaskEmployee::where('tenant_id', self::tenantId())->where('id', $employeeId)->find();
        return (int)($employee->is_manager ?? 0) === 1
            || in_array(WorkTask::ROLE_MANAGER, self::employeeRoleCodes($employeeId), true);
    }

    public static function employeeRoleCodes(int $employeeId): array
    {
        if ($employeeId <= 0) {
            return [];
        }
        return array_values(array_unique(TaskEmployeeRole::where('tenant_id', self::tenantId())
            ->where('employee_id', $employeeId)
            ->column('role_code')));
    }

    public static function formatTask(array $task): array
    {
        return [
            'id' => (int)($task['id'] ?? 0),
            'sn' => (string)($task['sn'] ?? ''),
            'task_date' => (string)($task['task_date'] ?? ''),
            'task_kind' => (string)($task['task_kind'] ?? ''),
            'role_code' => (string)($task['role_code'] ?? ''),
            'role_name' => self::ROLE_LABELS[(string)($task['role_code'] ?? '')] ?? (string)($task['role_code'] ?? ''),
            'source_type' => (string)($task['source_type'] ?? ''),
            'source_id' => (int)($task['source_id'] ?? 0),
            'parent_task_id' => (int)($task['parent_task_id'] ?? 0),
            'reservation_id' => (int)($task['reservation_id'] ?? 0),
            'reservation_sn' => (string)($task['reservation_sn'] ?? ''),
            'customer_id' => (int)($task['customer_id'] ?? 0),
            'customer_name' => (string)($task['customer_name'] ?? ''),
            'goods_id' => (int)($task['goods_id'] ?? 0),
            'goods_name' => (string)($task['goods_name'] ?? ''),
            'goods_code' => (string)($task['goods_code'] ?? ''),
            'unit_id' => (int)($task['unit_id'] ?? 0),
            'unit_name' => (string)($task['unit_name'] ?? ''),
            'demand_num' => self::qty($task['demand_num'] ?? 0),
            'reserved_num' => self::qty($task['reserved_num'] ?? 0),
            'shortage_num' => self::qty($task['shortage_num'] ?? 0),
            'progress_num' => self::qty($task['progress_num'] ?? 0),
            'stock_status' => (string)($task['stock_status'] ?? ''),
            'assignee_employee_id' => (int)($task['assignee_employee_id'] ?? 0),
            'assignee_employee_name' => (string)($task['assignee_employee_name'] ?? ''),
            'assigned_by' => (int)($task['assigned_by'] ?? 0),
            'assigned_time' => (int)($task['assigned_time'] ?? 0),
            'status' => (string)($task['status'] ?? ''),
            'priority' => (string)($task['priority'] ?? 'normal'),
            'status_reason' => (string)($task['status_reason'] ?? ''),
            'print_count' => (int)($task['print_count'] ?? 0),
            'last_print_time' => (int)($task['last_print_time'] ?? 0),
            'create_time' => $task['create_time'] ?? '',
            'update_time' => $task['update_time'] ?? '',
        ];
    }

    private static function upsertTaskForItem(
        array $item,
        string $taskKind,
        string $roleCode,
        int $employeeId,
        string $taskDate,
        string $priority,
        int $parentTaskId
    ): array {
        $employee = $employeeId > 0 ? self::activeEmployee($employeeId) : null;
        $status = $employee ? WorkTask::STATUS_ASSIGNED : WorkTask::STATUS_PENDING;
        $stockStatus = bccomp((string)$item['shortage_num'], '0', 4) > 0 ? WorkTask::STOCK_SHORTAGE : WorkTask::STOCK_ENOUGH;
        $demand = $taskKind === WorkTask::KIND_PROCUREMENT ? (string)$item['shortage_num'] : (string)$item['num'];
        $reserved = $taskKind === WorkTask::KIND_PROCUREMENT ? '0.0000' : (string)$item['reserved_num'];
        $shortage = $taskKind === WorkTask::KIND_PROCUREMENT ? (string)$item['shortage_num'] : (string)$item['shortage_num'];

        $existing = WorkTask::where('tenant_id', self::tenantId())
            ->where('task_kind', $taskKind)
            ->where('role_code', $roleCode)
            ->where('source_type', 'sales_reservation_item')
            ->where('source_id', (int)$item['id'])
            ->find();

        $data = [
            'task_date' => $taskDate,
            'parent_task_id' => $parentTaskId,
            'reservation_id' => (int)$item['reservation_id'],
            'reservation_sn' => (string)$item['reservation_sn'],
            'customer_id' => (int)$item['customer_id'],
            'customer_name' => (string)$item['customer_name'],
            'goods_id' => (int)$item['goods_id'],
            'goods_name' => (string)$item['goods_name'],
            'goods_code' => (string)$item['goods_code'],
            'unit_id' => (int)$item['unit_id'],
            'unit_name' => (string)$item['unit_name'],
            'demand_num' => self::qty($demand),
            'reserved_num' => self::qty($reserved),
            'shortage_num' => self::qty($shortage),
            'stock_status' => $stockStatus,
            'assignee_employee_id' => $employee ? (int)$employee->id : 0,
            'assignee_employee_name' => $employee ? (string)$employee->name : '',
            'assigned_by' => $employee ? self::adminId() : 0,
            'assigned_time' => $employee ? time() : 0,
            'priority' => $priority,
            'update_by' => self::adminId(),
            'update_time' => time(),
        ];

        if ($existing) {
            if (!in_array((string)$existing->status, [WorkTask::STATUS_PROCESSING, WorkTask::STATUS_COMPLETED, WorkTask::STATUS_CANCELLED], true)) {
                $data['status'] = $status;
            }
            $existing->save($data);
            self::log((int)$existing->id, 'assignment_update', '更新任务分配', (string)$existing->status, (string)($data['status'] ?? $existing->status), $data);
            $result = $existing->toArray();
            $result['was_created'] = false;
            return $result;
        }

        $task = WorkTask::create(array_merge($data, [
            'tenant_id' => self::tenantId(),
            'sn' => self::generateTaskSn(),
            'task_kind' => $taskKind,
            'role_code' => $roleCode,
            'source_type' => 'sales_reservation_item',
            'source_id' => (int)$item['id'],
            'progress_num' => '0.0000',
            'status' => $status,
            'create_by' => self::adminId(),
            'create_time' => time(),
            'delete_time' => null,
        ]));
        self::log((int)$task->id, 'assignment_create', '创建任务分配', '', $status, $task->toArray());
        $result = $task->toArray();
        $result['was_created'] = true;
        return $result;
    }

    private static function selectedReservationItems(array $params): array
    {
        $assignmentItemIds = array_column(self::normalizeAssignmentRows($params), 'reservation_item_id');
        $itemIds = self::normalizeIds($params['item_ids'] ?? $params['reservation_item_ids'] ?? $assignmentItemIds);
        $reservationIds = self::normalizeIds($params['reservation_ids'] ?? $params['ids'] ?? []);
        $query = SalesReservationItem::alias('i')
            ->join('sales_reservation r', 'r.id = i.reservation_id')
            ->where('i.tenant_id', self::tenantId())
            ->where('r.tenant_id', self::tenantId())
            ->whereNotIn('r.status', [SalesReservation::STATUS_CANCELLED, SalesReservation::STATUS_CONVERTED])
            ->field('i.id,i.reservation_id,r.sn AS reservation_sn,r.customer_id,r.customer_name,i.goods_id,i.goods_name,i.goods_code,i.unit_id,i.unit_name,i.num,i.reserved_num,i.shortage_num,i.status');
        if (!empty($itemIds)) {
            $query->whereIn('i.id', $itemIds);
        } elseif (!empty($reservationIds)) {
            $query->whereIn('i.reservation_id', $reservationIds);
        } else {
            return [];
        }

        return $query->order(['i.reservation_id' => 'asc', 'i.id' => 'asc'])->select()->toArray();
    }

    private static function taskQuery(array $params)
    {
        $query = WorkTask::where('tenant_id', self::tenantId());
        self::applyTaskFilters($query, $params);
        return $query;
    }

    private static function applyTaskFilters($query, array $params): void
    {
        if (($params['task_date'] ?? '') !== '') {
            $query->where('task_date', self::normalizeTaskDate($params['task_date']));
        }
        foreach (['task_kind', 'role_code', 'status', 'stock_status'] as $field) {
            if (($params[$field] ?? '') !== '') {
                $query->where($field, (string)$params[$field]);
            }
        }
        foreach (['assignee_employee_id', 'reservation_id', 'goods_id'] as $field) {
            if ((int)($params[$field] ?? 0) > 0) {
                $query->where($field, (int)$params[$field]);
            }
        }
        $ids = self::normalizeIds($params['ids'] ?? []);
        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }
    }

    private static function completeProcurementTask(array $task): void
    {
        $item = SalesReservationItem::where('tenant_id', self::tenantId())
            ->where('id', (int)$task['source_id'])
            ->findOrEmpty();
        if ($item->isEmpty() || bccomp((string)$item->shortage_num, '0', 4) <= 0) {
            return;
        }

        InventoryReservationService::reserve($item->toArray(), (string)$item->shortage_num);
        $item->save([
            'reserved_num' => self::qty($item->num),
            'shortage_num' => '0.0000',
            'status' => SalesReservationItem::STATUS_RESERVED,
            'update_time' => time(),
        ]);
        WorkTask::where('tenant_id', self::tenantId())
            ->where('source_type', 'sales_reservation_item')
            ->where('source_id', (int)$item->id)
            ->update([
                'reserved_num' => self::qty($item->num),
                'shortage_num' => '0.0000',
                'stock_status' => WorkTask::STOCK_PROCUREMENT_DONE,
                'update_by' => self::adminId(),
                'update_time' => time(),
            ]);
        self::refreshReservationStockStatus((int)$item->reservation_id);
    }

    private static function refreshReservationStockStatus(int $reservationId): void
    {
        $reservation = SalesReservation::where('tenant_id', self::tenantId())->where('id', $reservationId)->findOrEmpty();
        if ($reservation->isEmpty()) {
            return;
        }
        $items = SalesReservationItem::where('tenant_id', self::tenantId())->where('reservation_id', $reservationId)->select()->toArray();
        $reservedTotal = '0.0000';
        $shortageTotal = '0.0000';
        foreach ($items as $item) {
            $reservedTotal = bcadd($reservedTotal, (string)$item['reserved_num'], 4);
            $shortageTotal = bcadd($shortageTotal, (string)$item['shortage_num'], 4);
        }
        $reservation->save([
            'status' => bccomp($shortageTotal, '0', 4) > 0 ? SalesReservation::STATUS_SHORTAGE : SalesReservation::STATUS_READY,
            'reserved_num' => self::qty($reservedTotal),
            'shortage_num' => self::qty($shortageTotal),
            'update_by' => self::adminId(),
            'update_time' => time(),
        ]);
    }

    private static function formatReservationItemForTask(array $item, array $extra = []): array
    {
        return array_merge([
            'id' => (int)$item['id'],
            'reservation_id' => (int)$item['reservation_id'],
            'reservation_sn' => (string)$item['reservation_sn'],
            'customer_id' => (int)$item['customer_id'],
            'customer_name' => (string)$item['customer_name'],
            'goods_id' => (int)$item['goods_id'],
            'goods_name' => (string)$item['goods_name'],
            'goods_code' => (string)$item['goods_code'],
            'unit_id' => (int)$item['unit_id'],
            'unit_name' => (string)$item['unit_name'],
            'demand_num' => self::qty($item['num'] ?? 0),
            'reserved_num' => self::qty($item['reserved_num'] ?? 0),
            'shortage_num' => self::qty($item['shortage_num'] ?? 0),
            'status' => (string)$item['status'],
        ], $extra);
    }

    private static function summaryFromItems(array $items): array
    {
        $summary = ['item_count' => count($items), 'demand_num' => '0.0000', 'reserved_num' => '0.0000', 'shortage_num' => '0.0000'];
        foreach ($items as $item) {
            $summary['demand_num'] = bcadd($summary['demand_num'], (string)$item['demand_num'], 4);
            $summary['reserved_num'] = bcadd($summary['reserved_num'], (string)$item['reserved_num'], 4);
            $summary['shortage_num'] = bcadd($summary['shortage_num'], (string)$item['shortage_num'], 4);
        }
        $summary['demand_num'] = self::qty($summary['demand_num']);
        $summary['reserved_num'] = self::qty($summary['reserved_num']);
        $summary['shortage_num'] = self::qty($summary['shortage_num']);
        return $summary;
    }

    private static function normalizeAssignmentRows(array $params): array
    {
        $raw = $params['assignments'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reservationItemId = (int)($row['reservation_item_id'] ?? 0);
            $roleCode = (string)($row['role_code'] ?? '');
            if ($reservationItemId <= 0 || $roleCode === '') {
                continue;
            }
            $rows[] = [
                'reservation_item_id' => $reservationItemId,
                'role_code' => $roleCode,
                'employee_id' => (int)($row['employee_id'] ?? 0),
                'priority' => self::normalizePriority((string)($row['priority'] ?? ($params['priority'] ?? 'normal'))),
            ];
        }

        return $rows;
    }

    private static function normalizeRoleAssignments(array $params): array
    {
        $assignments = [];
        $raw = $params['role_assignments'] ?? $params['assignments'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_array($value)) {
                    $roleCode = (string)($value['role_code'] ?? $key);
                    $employeeId = (int)($value['employee_id'] ?? $value['assignee_employee_id'] ?? 0);
                } else {
                    $roleCode = (string)$key;
                    $employeeId = (int)$value;
                }
                if ($roleCode !== '') {
                    $assignments[$roleCode] = $employeeId;
                }
            }
        }
        if (($params['role_code'] ?? '') !== '') {
            $assignments[(string)$params['role_code']] = (int)($params['employee_id'] ?? $params['assignee_employee_id'] ?? 0);
        }
        return $assignments;
    }

    private static function fulfillmentRoles(array $params, array $roleAssignments): array
    {
        $roleCodes = $params['role_codes'] ?? [];
        if (is_string($roleCodes)) {
            $roleCodes = array_filter(array_map('trim', explode(',', $roleCodes)));
        }
        if (empty($roleCodes)) {
            $roleCodes = array_keys(array_diff_key($roleAssignments, [WorkTask::ROLE_PROCUREMENT => true, WorkTask::ROLE_MANAGER => true]));
        }
        if (empty($roleCodes)) {
            $roleCodes = [WorkTask::ROLE_PACKING];
        }
        return array_values(array_unique(array_filter(array_map('strval', $roleCodes), static fn(string $role): bool => $role !== WorkTask::ROLE_PROCUREMENT)));
    }

    private static function normalizeIds(mixed $value): array
    {
        if (is_numeric($value)) {
            $value = [(int)$value];
        } elseif (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)));
        }
        return array_values(array_unique(array_filter(array_map('intval', is_array($value) ? $value : []), static fn(int $id): bool => $id > 0)));
    }

    private static function normalizeTaskDate(mixed $value): string
    {
        $text = trim((string)$value);
        return $text !== '' ? substr($text, 0, 10) : date('Y-m-d');
    }

    private static function normalizePriority(string $priority): string
    {
        return in_array($priority, ['normal', 'high', 'urgent'], true) ? $priority : 'normal';
    }

    private static function formatPrintItem(array $task): array
    {
        return [
            'task_id' => (int)$task['id'],
            'task_sn' => (string)$task['sn'],
            'role_code' => (string)$task['role_code'],
            'role_name' => self::ROLE_LABELS[(string)$task['role_code']] ?? (string)$task['role_code'],
            'reservation_sn' => (string)$task['reservation_sn'],
            'customer_name' => (string)$task['customer_name'],
            'goods_name' => (string)$task['goods_name'],
            'goods_code' => (string)$task['goods_code'],
            'unit_name' => (string)$task['unit_name'],
            'demand_num' => self::qty($task['demand_num'] ?? 0),
            'reserved_num' => self::qty($task['reserved_num'] ?? 0),
            'shortage_num' => self::qty($task['shortage_num'] ?? 0),
            'assignee_employee_name' => (string)$task['assignee_employee_name'],
        ];
    }

    private static function activeEmployee(int $employeeId): ?TaskEmployee
    {
        if ($employeeId <= 0) {
            return null;
        }
        $employee = TaskEmployee::where('tenant_id', self::tenantId())
            ->where('id', $employeeId)
            ->where('is_enabled', 1)
            ->findOrEmpty();
        return $employee->isEmpty() ? null : $employee;
    }

    private static function canManageTaskCenter(?TaskEmployee $employee): bool
    {
        if ($employee !== null) {
            return (int)($employee->is_manager ?? 0) === 1
                || in_array(WorkTask::ROLE_MANAGER, self::employeeRoleCodes((int)$employee->id), true);
        }

        if ((bool)(request()->jxcFromUserToken ?? false)) {
            return false;
        }

        $adminInfo = request()->adminInfo ?? [];
        return self::adminId() > 0 || (int)($adminInfo['root'] ?? 0) === 1;
    }

    private static function canOperateTask(array $task, ?TaskEmployee $employee, bool $canManage): bool
    {
        if ($canManage) {
            return true;
        }
        if ($employee === null) {
            return false;
        }

        $employeeId = (int)$employee->id;
        if ((int)($task['assignee_employee_id'] ?? 0) === $employeeId) {
            return true;
        }

        return (int)($task['assignee_employee_id'] ?? 0) === 0
            && in_array((string)($task['role_code'] ?? ''), self::employeeRoleCodes($employeeId), true);
    }

    private static function canTransition(string $from, string $to): bool
    {
        if (!in_array($from, WorkTask::VALID_STATUSES, true) || !in_array($to, WorkTask::VALID_STATUSES, true)) {
            return false;
        }
        if ($from === $to) {
            return !in_array($from, self::TERMINAL_STATUSES, true);
        }

        return in_array($to, self::STATUS_TRANSITIONS[$from] ?? [], true);
    }

    private static function employeeByBinding(string $field, int $id): ?TaskEmployee
    {
        $employee = TaskEmployee::where('tenant_id', self::tenantId())
            ->where($field, $id)
            ->where('is_enabled', 1)
            ->findOrEmpty();
        return $employee->isEmpty() ? null : $employee;
    }

    private static function log(int $taskId, string $action, string $content, string $from = '', string $to = '', array $payload = []): void
    {
        WorkTaskLog::create([
            'tenant_id' => self::tenantId(),
            'task_id' => $taskId,
            'action' => $action,
            'status_from' => $from,
            'status_to' => $to,
            'content' => $content,
            'payload_json' => empty($payload) ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'operator_employee_id' => self::currentEmployeeId(),
            'operator_admin_id' => self::adminId(),
            'create_time' => time(),
        ]);
    }

    private static function hasInboundLog(int $taskId, string $inboundKey): bool
    {
        return WorkTaskLog::where('tenant_id', self::tenantId())
            ->where('task_id', $taskId)
            ->where('action', 'procurement_inbound')
            ->where('content', 'like', $inboundKey . ':%')
            ->count() > 0;
    }

    private static function inboundKey(int $supplyOrderId, int $rowId): string
    {
        return 'supply_order:' . $supplyOrderId . ':row:' . $rowId;
    }

    private static function generateTaskSn(): string
    {
        return 'TC' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function generatePrintNo(): string
    {
        return 'TP' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
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
