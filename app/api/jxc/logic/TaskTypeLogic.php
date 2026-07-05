<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\TaskRole;
use app\common\model\jxc\TaskType;
use app\common\model\jxc\TaskTypeRole;
use think\facade\Db;

class TaskTypeLogic extends BaseLogic
{
    public static function lists(array $params = []): array
    {
        WorkTaskService::ensureSystemDefaults();
        $query = TaskType::where('tenant_id', (int)(request()->tenantId ?? 0))->order(['id' => 'asc']);
        if (($params['is_enabled'] ?? '') !== '') {
            $query->where('is_enabled', (int)$params['is_enabled']);
        }
        $rows = $query->select()->toArray();
        return ['lists' => array_map([self::class, 'format'], $rows), 'count' => count($rows), 'page_no' => 1, 'page_size' => count($rows)];
    }

    public static function detail(array $params): array
    {
        WorkTaskService::ensureSystemDefaults();
        $type = TaskType::where('tenant_id', self::tenantId())->where('id', (int)($params['id'] ?? 0))->findOrEmpty();
        return $type->isEmpty() ? [] : self::format($type->toArray());
    }

    public static function create(array $params): array|false
    {
        self::clearError();
        if (!WorkTaskService::isManager()) {
            return self::failWithCode('无任务类型管理权限', 'TASK_PERMISSION_DENIED');
        }
        $roleCodes = self::normalizeRoleCodes($params['role_codes'] ?? []);
        $invalidRoleCodes = self::invalidRoleCodes($roleCodes);
        if (!empty($invalidRoleCodes)) {
            return self::failWithCode('任务角色不存在或已停用', 'TASK_ROLE_INVALID');
        }

        Db::startTrans();
        try {
            $type = TaskType::create([
                'tenant_id' => self::tenantId(),
                'code' => trim((string)($params['code'] ?? '')),
                'name' => trim((string)($params['name'] ?? '')),
                'is_system' => 0,
                'is_enabled' => (int)($params['is_enabled'] ?? 1),
                'create_time' => time(),
                'update_time' => time(),
            ]);
            self::syncRoles((int)$type->id, (string)$type->code, $roleCodes);
            Db::commit();
            return self::detail(['id' => (int)$type->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            return self::failWithCode($e->getMessage(), 'TASK_TYPE_SAVE_FAILED');
        }
    }

    public static function edit(array $params): array|false
    {
        self::clearError();
        if (!WorkTaskService::isManager()) {
            return self::failWithCode('无任务类型管理权限', 'TASK_PERMISSION_DENIED');
        }
        $type = TaskType::where('tenant_id', self::tenantId())->where('id', (int)($params['id'] ?? 0))->findOrEmpty();
        if ($type->isEmpty()) {
            return self::failWithCode('任务类型不存在', 'TASK_TYPE_NOT_FOUND');
        }

        $roleCodes = null;
        if (isset($params['role_codes'])) {
            $roleCodes = self::normalizeRoleCodes($params['role_codes']);
            $invalidRoleCodes = self::invalidRoleCodes($roleCodes);
            if (!empty($invalidRoleCodes)) {
                return self::failWithCode('任务角色不存在或已停用', 'TASK_ROLE_INVALID');
            }
        }

        Db::startTrans();
        try {
            $type->save([
                'name' => trim((string)($params['name'] ?? $type->name)),
                'is_enabled' => (int)($params['is_enabled'] ?? $type->is_enabled),
                'update_time' => time(),
            ]);
            if ($roleCodes !== null) {
                self::syncRoles((int)$type->id, (string)$type->code, $roleCodes);
            }
            Db::commit();
            return self::detail(['id' => (int)$type->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            return self::failWithCode($e->getMessage(), 'TASK_TYPE_SAVE_FAILED');
        }
    }

    public static function status(array $params): array|false
    {
        $params['is_enabled'] = (int)($params['is_enabled'] ?? $params['status'] ?? 1);
        return self::edit($params);
    }

    public static function format(array $type): array
    {
        $roleRows = TaskTypeRole::where('tenant_id', self::tenantId())
            ->where('type_code', (string)($type['code'] ?? ''))
            ->select()
            ->toArray();
        $roleCodes = array_values(array_unique(array_map('strval', array_column($roleRows, 'role_code'))));
        $roleNames = [];
        if (!empty($roleCodes)) {
            $roleNameMap = TaskRole::where('tenant_id', self::tenantId())->whereIn('code', $roleCodes)->column('name', 'code');
            foreach ($roleCodes as $roleCode) {
                $roleNames[] = (string)($roleNameMap[$roleCode] ?? $roleCode);
            }
        }

        return [
            'id' => (int)($type['id'] ?? 0),
            'code' => (string)($type['code'] ?? ''),
            'name' => (string)($type['name'] ?? ''),
            'is_system' => (int)($type['is_system'] ?? 0),
            'is_enabled' => (int)($type['is_enabled'] ?? 1),
            'role_codes' => $roleCodes,
            'role_names' => $roleNames,
            'create_time' => $type['create_time'] ?? '',
            'update_time' => $type['update_time'] ?? '',
        ];
    }

    private static function syncRoles(int $typeId, string $typeCode, array $roleCodes): void
    {
        TaskTypeRole::where('tenant_id', self::tenantId())->where('type_code', $typeCode)->delete();
        foreach ($roleCodes as $roleCode) {
            $role = TaskRole::where('tenant_id', self::tenantId())->where('code', $roleCode)->where('is_enabled', 1)->find();
            if (!$role) {
                continue;
            }
            TaskTypeRole::create([
                'tenant_id' => self::tenantId(),
                'type_id' => $typeId,
                'role_id' => (int)$role->id,
                'type_code' => $typeCode,
                'role_code' => $roleCode,
                'create_time' => time(),
            ]);
        }
    }

    private static function invalidRoleCodes(array $roleCodes): array
    {
        if (empty($roleCodes)) {
            return [];
        }
        $existing = TaskRole::where('tenant_id', self::tenantId())->where('is_enabled', 1)->whereIn('code', $roleCodes)->column('code');
        return array_values(array_diff($roleCodes, $existing));
    }

    private static function normalizeRoleCodes(mixed $roleCodes): array
    {
        if (is_string($roleCodes)) {
            $roleCodes = array_filter(array_map('trim', explode(',', $roleCodes)));
        }
        return array_values(array_unique(array_filter(array_map('strval', is_array($roleCodes) ? $roleCodes : []), static fn(string $code): bool => $code !== '')));
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
