<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\TaskRole;

class TaskRoleLogic extends BaseLogic
{
    public static function lists(array $params = []): array
    {
        WorkTaskService::ensureSystemDefaults();
        $query = TaskRole::where('tenant_id', self::tenantId())->order(['is_system' => 'desc', 'id' => 'asc']);
        if (($params['is_enabled'] ?? '') !== '') {
            $query->where('is_enabled', (int)$params['is_enabled']);
        }
        $rows = $query->select()->toArray();
        return ['lists' => array_map([self::class, 'format'], $rows), 'count' => count($rows), 'page_no' => 1, 'page_size' => count($rows)];
    }

    public static function detail(array $params): array
    {
        WorkTaskService::ensureSystemDefaults();
        $role = TaskRole::where('tenant_id', self::tenantId())->where('id', (int)($params['id'] ?? 0))->findOrEmpty();
        return $role->isEmpty() ? [] : self::format($role->toArray());
    }

    public static function create(array $params): array|false
    {
        self::clearError();
        if (!WorkTaskService::isManager()) {
            return self::failWithCode('无任务角色管理权限', 'TASK_PERMISSION_DENIED');
        }

        $code = self::normalizeCode((string)($params['code'] ?? ''));
        if ($code === '') {
            return self::failWithCode('角色编码不能为空', 'TASK_ROLE_CODE_EMPTY');
        }
        if (TaskRole::where('tenant_id', self::tenantId())->where('code', $code)->count() > 0) {
            return self::failWithCode('角色编码已存在', 'TASK_ROLE_CODE_EXISTS');
        }

        $role = TaskRole::create([
            'tenant_id' => self::tenantId(),
            'code' => $code,
            'name' => trim((string)($params['name'] ?? '')),
            'is_system' => 0,
            'is_enabled' => (int)($params['is_enabled'] ?? 1),
            'create_time' => time(),
            'update_time' => time(),
        ]);
        return self::format($role->toArray());
    }

    public static function edit(array $params): array|false
    {
        self::clearError();
        if (!WorkTaskService::isManager()) {
            return self::failWithCode('无任务角色管理权限', 'TASK_PERMISSION_DENIED');
        }
        $role = TaskRole::where('tenant_id', self::tenantId())->where('id', (int)($params['id'] ?? 0))->findOrEmpty();
        if ($role->isEmpty()) {
            return self::failWithCode('任务角色不存在', 'TASK_ROLE_NOT_FOUND');
        }
        $role->save([
            'name' => trim((string)($params['name'] ?? $role->name)),
            'is_enabled' => (int)($params['is_enabled'] ?? $role->is_enabled),
            'update_time' => time(),
        ]);
        return self::detail(['id' => (int)$role->id]);
    }

    public static function status(array $params): array|false
    {
        $params['is_enabled'] = (int)($params['is_enabled'] ?? $params['status'] ?? 1);
        return self::edit($params);
    }

    public static function format(array $role): array
    {
        return [
            'id' => (int)($role['id'] ?? 0),
            'code' => (string)($role['code'] ?? ''),
            'name' => (string)($role['name'] ?? ''),
            'is_system' => (int)($role['is_system'] ?? 0),
            'is_enabled' => (int)($role['is_enabled'] ?? 1),
            'create_time' => $role['create_time'] ?? '',
            'update_time' => $role['update_time'] ?? '',
        ];
    }

    private static function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
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
