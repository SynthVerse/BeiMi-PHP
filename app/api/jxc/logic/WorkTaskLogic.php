<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\TaskEmployee;
use app\common\model\jxc\WorkTask;
use think\facade\Db;

class WorkTaskLogic extends BaseLogic
{
    public static function lists(array $params = []): array
    {
        WorkTaskService::ensureSystemDefaults();
        $query = WorkTask::where('tenant_id', self::tenantId())->order(['id' => 'desc']);
        if (($params['status'] ?? '') !== '') {
            $query->where('status', (string)$params['status']);
        }
        if (($params['type_code'] ?? '') !== '') {
            $query->where('type_code', (string)$params['type_code']);
        }
        if ((int)($params['assignee_employee_id'] ?? 0) > 0) {
            $query->where('assignee_employee_id', (int)$params['assignee_employee_id']);
        }
        if (!WorkTaskService::isManager()) {
            $employeeId = WorkTaskService::currentEmployeeId();
            $typeCodes = WorkTaskService::employeeExecutableTypeCodes($employeeId);
            $query->where(function ($builder) use ($employeeId, $typeCodes) {
                if ($employeeId > 0) {
                    $builder->where('assignee_employee_id', $employeeId);
                } else {
                    $builder->where('id', 0);
                }
                if (!empty($typeCodes)) {
                    $builder->whereOr('type_code', 'in', $typeCodes);
                }
            });
        }
        $count = $query->count();
        $pageNo = (int)($params['page_no'] ?? $params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? $params['pagesize'] ?? 20);
        $rows = $query->page($pageNo, $pageSize)->select()->toArray();
        return [
            'lists' => array_map([WorkTaskService::class, 'format'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    public static function detail(array $params): array
    {
        $task = self::findTask((int)($params['id'] ?? 0));
        if (!$task || !self::canView($task)) {
            return [];
        }
        return $task ? WorkTaskService::format($task->toArray()) : [];
    }

    public static function create(array $params): array|false
    {
        self::clearError();
        if (!WorkTaskService::isManager()) {
            return self::failWithCode('无任务管理权限', 'TASK_PERMISSION_DENIED');
        }
        $assigneeCheck = self::validateAssignee((int)($params['assignee_employee_id'] ?? 0), (string)($params['type_code'] ?? ''));
        if ($assigneeCheck !== true) {
            return $assigneeCheck;
        }
        $result = WorkTaskService::create($params);
        if ($result === false) {
            return self::failWithCode('任务类型不存在', 'TASK_TYPE_NOT_FOUND');
        }
        return $result;
    }

    public static function edit(array $params): array|false
    {
        self::clearError();
        if (!WorkTaskService::isManager()) {
            return self::failWithCode('无任务管理权限', 'TASK_PERMISSION_DENIED');
        }
        $task = self::findTask((int)($params['id'] ?? 0));
        if (!$task || (string)$task->status !== WorkTask::STATUS_PENDING) {
            return self::failWithCode('任务不可编辑', 'TASK_STATUS_INVALID');
        }
        $task->save([
            'title' => trim((string)($params['title'] ?? $task->title)),
            'content' => (string)($params['content'] ?? $task->content),
            'target_num' => isset($params['target_num']) ? self::qty($params['target_num']) : $task->target_num,
            'update_by' => self::adminId(),
            'update_time' => time(),
        ]);
        WorkTaskService::log((int)$task->id, 'edit', '编辑任务');
        return self::detail(['id' => (int)$task->id]);
    }

    public static function assign(array $params): array|false
    {
        self::clearError();
        if (!WorkTaskService::isManager()) {
            return self::failWithCode('无任务分配权限', 'TASK_PERMISSION_DENIED');
        }
        $task = self::findTask((int)($params['id'] ?? 0));
        if (!$task || !in_array((string)$task->status, [WorkTask::STATUS_PENDING, WorkTask::STATUS_PROCESSING], true)) {
            return self::failWithCode('任务不可分配', 'TASK_STATUS_INVALID');
        }
        $employee = TaskEmployee::where('tenant_id', self::tenantId())
            ->where('id', (int)($params['assignee_employee_id'] ?? 0))
            ->where('is_enabled', 1)
            ->findOrEmpty();
        if ($employee->isEmpty()) {
            return self::failWithCode('执行人不存在或已停用', 'TASK_ASSIGNEE_INVALID');
        }
        if (!WorkTaskService::employeeCanOperateType((int)$employee->id, (string)$task->type_code)) {
            return self::failWithCode('员工角色不匹配任务类型', 'TASK_ASSIGNEE_ROLE_MISMATCH');
        }
        $task->save([
            'assignee_employee_id' => (int)$employee->id,
            'assignee_employee_name' => (string)$employee->name,
            'update_by' => self::adminId(),
            'update_time' => time(),
        ]);
        WorkTaskService::log((int)$task->id, 'assign', '分配任务');
        return self::detail(['id' => (int)$task->id]);
    }

    public static function start(array $params): array|false
    {
        return self::transition((int)($params['id'] ?? 0), [WorkTask::STATUS_PENDING], WorkTask::STATUS_PROCESSING, 'start', '开始任务');
    }

    public static function complete(array $params): array|false
    {
        self::clearError();
        $task = self::findTask((int)($params['id'] ?? 0));
        if (!$task || (string)$task->status !== WorkTask::STATUS_PROCESSING || !self::canOperate($task)) {
            return self::failWithCode('任务不可完成', 'TASK_STATUS_INVALID');
        }

        Db::startTrans();
        try {
            if ((string)$task->type_code === 'sales_convert') {
                $converted = SalesReservationLogic::convertSales(['id' => (int)$task->reservation_id]);
                if ($converted === false) {
                    Db::rollback();
                    return self::failWithCode(SalesReservationLogic::getError(), SalesReservationLogic::getReturnData()['error_code'] ?? 'TASK_SALES_CONVERT_FAILED');
                }
            }
            $task->save([
                'status' => WorkTask::STATUS_COMPLETED,
                'progress_num' => $task->target_num,
                'update_by' => self::adminId(),
                'update_time' => time(),
            ]);
            WorkTaskService::log((int)$task->id, 'complete', '完成任务');
            Db::commit();
            return self::detail(['id' => (int)$task->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            return self::failWithCode($e->getMessage(), 'TASK_COMPLETE_FAILED');
        }
    }

    public static function cancel(array $params): array|false
    {
        return self::transition((int)($params['id'] ?? 0), [WorkTask::STATUS_PENDING, WorkTask::STATUS_PROCESSING], WorkTask::STATUS_CANCELLED, 'cancel', '取消任务', true);
    }

    private static function transition(int $id, array $allowed, string $status, string $action, string $log, bool $managerOnly = false): array|false
    {
        self::clearError();
        $task = self::findTask($id);
        if (!$task || !in_array((string)$task->status, $allowed, true) || ($managerOnly ? !WorkTaskService::isManager() : !self::canOperate($task))) {
            return self::failWithCode('任务状态不允许操作', 'TASK_STATUS_INVALID');
        }
        $task->save([
            'status' => $status,
            'update_by' => self::adminId(),
            'update_time' => time(),
        ]);
        WorkTaskService::log((int)$task->id, $action, $log);
        return self::detail(['id' => (int)$task->id]);
    }

    private static function canOperate(WorkTask $task): bool
    {
        return WorkTaskService::isManager()
            || (WorkTaskService::currentEmployeeId() > 0 && WorkTaskService::currentEmployeeId() === (int)$task->assignee_employee_id)
            || WorkTaskService::employeeCanOperateType(WorkTaskService::currentEmployeeId(), (string)$task->type_code);
    }

    private static function canView(WorkTask $task): bool
    {
        return WorkTaskService::isManager()
            || (WorkTaskService::currentEmployeeId() > 0 && WorkTaskService::currentEmployeeId() === (int)$task->assignee_employee_id)
            || WorkTaskService::employeeCanOperateType(WorkTaskService::currentEmployeeId(), (string)$task->type_code);
    }

    private static function validateAssignee(int $employeeId, string $typeCode): bool
    {
        if ($employeeId <= 0) {
            return true;
        }
        $employee = TaskEmployee::where('tenant_id', self::tenantId())
            ->where('id', $employeeId)
            ->where('is_enabled', 1)
            ->findOrEmpty();
        if ($employee->isEmpty()) {
            return self::failWithCode('执行人不存在或已停用', 'TASK_ASSIGNEE_INVALID');
        }
        if (!WorkTaskService::employeeCanOperateType((int)$employee->id, $typeCode)) {
            return self::failWithCode('员工角色不匹配任务类型', 'TASK_ASSIGNEE_ROLE_MISMATCH');
        }
        return true;
    }

    private static function findTask(int $id): ?WorkTask
    {
        if ($id <= 0) {
            return null;
        }
        $task = WorkTask::where('tenant_id', self::tenantId())->where('id', $id)->findOrEmpty();
        return $task->isEmpty() ? null : $task;
    }

    private static function failWithCode(string $message, string $code): false
    {
        self::setError($message);
        self::setReturnData(['error_code' => $code]);
        return false;
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
