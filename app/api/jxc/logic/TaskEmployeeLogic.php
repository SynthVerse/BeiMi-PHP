<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\TaskEmployee;
use app\common\model\jxc\TaskEmployeeRole;
use app\common\model\jxc\TaskRole;
use think\facade\Db;

class TaskEmployeeLogic extends BaseLogic
{
    public static function lists(array $params = []): array
    {
        WorkTaskService::ensureSystemDefaults();
        if (!WorkTaskService::isManager()) {
            return [
                'lists' => [],
                'count' => 0,
                'page_no' => (int)($params['page_no'] ?? 1),
                'page_size' => (int)($params['page_size'] ?? 20),
            ];
        }
        $query = TaskEmployee::where('tenant_id', self::tenantId())->order(['id' => 'desc']);
        if (($params['is_enabled'] ?? '') !== '') {
            $query->where('is_enabled', (int)$params['is_enabled']);
        }
        $count = $query->count();
        $rows = $query->page((int)($params['page_no'] ?? 1), (int)($params['page_size'] ?? 20))->select()->toArray();
        return [
            'lists' => array_map([self::class, 'format'], $rows),
            'count' => $count,
            'page_no' => (int)($params['page_no'] ?? 1),
            'page_size' => (int)($params['page_size'] ?? 20),
        ];
    }

    public static function detail(array $params): array
    {
        if (!WorkTaskService::isManager()) {
            return [];
        }
        $employee = TaskEmployee::where('tenant_id', self::tenantId())->where('id', (int)($params['id'] ?? 0))->findOrEmpty();
        return $employee->isEmpty() ? [] : self::format($employee->toArray());
    }

    public static function create(array $params): array|false
    {
        self::clearError();
        WorkTaskService::ensureSystemDefaults();
        $roleCodes = self::normalizeRoleCodes($params['role_codes'] ?? $params['roles'] ?? []);
        if (!self::canManageEmployees($roleCodes)) {
            return self::failWithCode('无任务员工管理权限', 'TASK_PERMISSION_DENIED');
        }

        Db::startTrans();
        try {
            $employee = TaskEmployee::create([
                'tenant_id' => self::tenantId(),
                'name' => trim((string)($params['name'] ?? '')),
                'user_id' => (int)($params['user_id'] ?? 0),
                'admin_id' => (int)($params['admin_id'] ?? 0),
                'mobile' => (string)($params['mobile'] ?? ''),
                'is_enabled' => (int)($params['is_enabled'] ?? 1),
                'create_time' => time(),
                'update_time' => time(),
            ]);
            self::syncRoles((int)$employee->id, $roleCodes);
            Db::commit();
            return self::detail(['id' => (int)$employee->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            return self::failWithCode($e->getMessage(), 'TASK_EMPLOYEE_SAVE_FAILED');
        }
    }

    public static function edit(array $params): array|false
    {
        self::clearError();
        if (!WorkTaskService::isManager()) {
            return self::failWithCode('无任务员工管理权限', 'TASK_PERMISSION_DENIED');
        }
        $employee = TaskEmployee::where('tenant_id', self::tenantId())->where('id', (int)($params['id'] ?? 0))->findOrEmpty();
        if ($employee->isEmpty()) {
            return self::failWithCode('员工不存在', 'TASK_EMPLOYEE_NOT_FOUND');
        }
        $employee->save([
            'name' => trim((string)($params['name'] ?? $employee->name)),
            'mobile' => (string)($params['mobile'] ?? $employee->mobile),
            'is_enabled' => (int)($params['is_enabled'] ?? $employee->is_enabled),
            'update_time' => time(),
        ]);
        if (isset($params['role_codes']) || isset($params['roles'])) {
            self::syncRoles((int)$employee->id, self::normalizeRoleCodes($params['role_codes'] ?? $params['roles']));
        }
        return self::detail(['id' => (int)$employee->id]);
    }

    public static function status(array $params): array|false
    {
        $params['is_enabled'] = (int)($params['is_enabled'] ?? $params['status'] ?? 1);
        return self::edit($params);
    }

    public static function format(array $employee): array
    {
        $roleCodes = TaskEmployeeRole::where('tenant_id', self::tenantId())
            ->where('employee_id', (int)($employee['id'] ?? 0))
            ->column('role_code');
        return [
            'id' => (int)($employee['id'] ?? 0),
            'name' => (string)($employee['name'] ?? ''),
            'user_id' => (int)($employee['user_id'] ?? 0),
            'admin_id' => (int)($employee['admin_id'] ?? 0),
            'mobile' => (string)($employee['mobile'] ?? ''),
            'is_enabled' => (int)($employee['is_enabled'] ?? 1),
            'role_codes' => $roleCodes,
            'create_time' => $employee['create_time'] ?? '',
            'update_time' => $employee['update_time'] ?? '',
        ];
    }

    private static function canManageEmployees(array $roleCodes): bool
    {
        if (WorkTaskService::isManager()) {
            return true;
        }

        $hasManager = TaskEmployeeRole::where('tenant_id', self::tenantId())
            ->where('role_code', TaskRole::MANAGER)
            ->count() > 0;
        return !$hasManager && in_array(TaskRole::MANAGER, $roleCodes, true);
    }

    private static function syncRoles(int $employeeId, array $roleCodes): void
    {
        TaskEmployeeRole::where('tenant_id', self::tenantId())->where('employee_id', $employeeId)->delete();
        foreach ($roleCodes as $roleCode) {
            $role = TaskRole::where('tenant_id', self::tenantId())->where('code', $roleCode)->find();
            if (!$role) {
                continue;
            }
            TaskEmployeeRole::create([
                'tenant_id' => self::tenantId(),
                'employee_id' => $employeeId,
                'role_id' => (int)$role->id,
                'role_code' => $roleCode,
                'create_time' => time(),
            ]);
        }
    }

    private static function normalizeRoleCodes(mixed $roleCodes): array
    {
        if (is_string($roleCodes)) {
            $roleCodes = array_filter(array_map('trim', explode(',', $roleCodes)));
        }
        return array_values(array_unique(array_map('strval', is_array($roleCodes) ? $roleCodes : [])));
    }

    private static function failWithCode(string $message, string $code): false
    {
        self::setError($message);
        self::setReturnData(['error_code' => $code]);
        return false;
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
