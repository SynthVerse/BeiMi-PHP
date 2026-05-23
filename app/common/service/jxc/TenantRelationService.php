<?php

declare(strict_types=1);

namespace app\common\service\jxc;

use think\facade\Db;

class TenantRelationService
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_DISABLED = 0;

    public static function createInvite(int $userId, int $parentTenantId, array $params): array
    {
        self::requireTenantAdmin($userId, $parentTenantId);
        self::requireUsableTenant($parentTenantId, '当前店铺不可用');

        $targetTenantId = (int)($params['target_tenant_id'] ?? $params['child_tenant_id'] ?? 0);
        if ($targetTenantId > 0) {
            self::assertCanCreateRelation($parentTenantId, $targetTenantId);
        }

        return TenantInviteService::createInvite([
            'tenant_id' => $parentTenantId,
            'creator_user_id' => $userId,
            'invite_type' => TenantInviteService::TYPE_RELATION,
            'target_user_id' => (int)($params['target_user_id'] ?? 0),
            'target_tenant_id' => $targetTenantId,
            'role' => '',
            'relation_type' => (string)($params['relation_type'] ?? 'default'),
            'expire_time' => (int)($params['expire_time'] ?? 0),
            'max_uses' => (int)($params['max_uses'] ?? 1),
            'extra' => [
                'permissions' => $params['permissions'] ?? [],
                'remark' => (string)($params['remark'] ?? ''),
            ],
        ]);
    }

    public static function previewInvite(int $userId, int $currentTenantId, string $code): array
    {
        self::requireTenantAdmin($userId, $currentTenantId);
        $invite = TenantInviteService::preview($code, TenantInviteService::TYPE_RELATION, $userId, $currentTenantId);

        return [
            'invite' => $invite,
            'parent_tenant' => self::tenantBase((int)$invite['tenant_id']),
            'child_tenant' => self::tenantBase($currentTenantId),
            'can_accept' => self::canCreateRelation((int)$invite['tenant_id'], $currentTenantId),
        ];
    }

    public static function acceptInvite(int $userId, int $childTenantId, string $code): array
    {
        self::requireTenantAdmin($userId, $childTenantId);

        return Db::transaction(function () use ($userId, $childTenantId, $code) {
            $invite = TenantInviteService::consume($code, TenantInviteService::TYPE_RELATION, $userId, $childTenantId);
            $parentTenantId = (int)$invite['tenant_id'];
            self::assertCanCreateRelation($parentTenantId, $childTenantId);

            $time = time();
            $parentRelation = self::activeParentRelation($parentTenantId);
            $level = $parentRelation ? (int)$parentRelation['level'] + 1 : 1;
            $parentPath = $parentRelation ? (string)$parentRelation['path'] : '/' . $parentTenantId . '/';
            $path = rtrim($parentPath, '/') . '/' . $childTenantId . '/';
            $extra = (array)($invite['extra'] ?? []);

            $relationId = Db::name('tenant_relation')->insertGetId([
                'parent_tenant_id' => $parentTenantId,
                'child_tenant_id' => $childTenantId,
                'relation_type' => (string)($invite['relation_type'] ?: 'default'),
                'status' => self::STATUS_ACTIVE,
                'level' => $level,
                'path' => $path,
                'invite_id' => (int)$invite['id'],
                'creator_user_id' => (int)$invite['creator_user_id'],
                'accepted_user_id' => $userId,
                'accepted_at' => $time,
                'permissions' => self::encodeJson($extra['permissions'] ?? []),
                'remark' => (string)($extra['remark'] ?? ''),
                'is_deleted' => 0,
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => null,
            ]);

            return self::formatRelation((array)Db::name('tenant_relation')->where('id', $relationId)->find(), true);
        });
    }

    public static function summary(int $userId, int $tenantId): array
    {
        self::requireTenantAdmin($userId, $tenantId);
        $parent = self::activeParentRelation($tenantId);
        $childrenCount = Db::name('tenant_relation')
            ->where('parent_tenant_id', $tenantId)
            ->where('status', self::STATUS_ACTIVE)
            ->where('is_deleted', 0)
            ->whereNull('delete_time')
            ->count();

        return [
            'tenant' => self::tenantBase($tenantId),
            'parent_relation' => $parent ? self::formatRelation((array)$parent, true) : null,
            'children_count' => (int)$childrenCount,
        ];
    }

    public static function children(int $userId, int $tenantId): array
    {
        self::requireTenantAdmin($userId, $tenantId);
        $rows = Db::name('tenant_relation')
            ->where('parent_tenant_id', $tenantId)
            ->where('status', self::STATUS_ACTIVE)
            ->where('is_deleted', 0)
            ->whereNull('delete_time')
            ->order('id asc')
            ->select()
            ->toArray();

        return array_map(fn($row) => self::formatRelation((array)$row, true), $rows);
    }

    public static function tree(int $userId, int $tenantId): array
    {
        self::requireTenantAdmin($userId, $tenantId);
        $root = [
            'tenant' => self::tenantBase($tenantId),
            'relation' => null,
            'children' => self::buildChildren($tenantId),
        ];
        return $root;
    }

    public static function unbind(int $userId, int $tenantId, array $params): array
    {
        self::requireTenantAdmin($userId, $tenantId);
        $relationId = (int)($params['relation_id'] ?? $params['id'] ?? 0);
        $childTenantId = (int)($params['child_tenant_id'] ?? 0);

        $query = Db::name('tenant_relation')
            ->where('status', self::STATUS_ACTIVE)
            ->where('is_deleted', 0)
            ->whereNull('delete_time');
        if ($relationId > 0) {
            $query->where('id', $relationId);
        } elseif ($childTenantId > 0) {
            $query->where('parent_tenant_id', $tenantId)->where('child_tenant_id', $childTenantId);
        } else {
            $query->where('child_tenant_id', $tenantId);
        }

        $relation = $query->find();
        if (!$relation) {
            throw new \RuntimeException('层级关系不存在');
        }
        if ((int)$relation['parent_tenant_id'] !== $tenantId && (int)$relation['child_tenant_id'] !== $tenantId) {
            throw new \RuntimeException('无权解除该层级关系');
        }

        Db::name('tenant_relation')->where('id', (int)$relation['id'])->update([
            'status' => self::STATUS_DISABLED,
            'is_deleted' => 1,
            'update_time' => time(),
            'delete_time' => time(),
        ]);

        return ['relation_id' => (int)$relation['id'], 'unbind' => true];
    }

    private static function buildChildren(int $tenantId): array
    {
        $rows = Db::name('tenant_relation')
            ->where('parent_tenant_id', $tenantId)
            ->where('status', self::STATUS_ACTIVE)
            ->where('is_deleted', 0)
            ->whereNull('delete_time')
            ->order('id asc')
            ->select()
            ->toArray();

        $children = [];
        foreach ($rows as $row) {
            $childId = (int)$row['child_tenant_id'];
            $children[] = [
                'tenant' => self::tenantBase($childId),
                'relation' => self::relationFields((array)$row),
                'children' => self::buildChildren($childId),
            ];
        }
        return $children;
    }

    private static function assertCanCreateRelation(int $parentTenantId, int $childTenantId): void
    {
        if (!self::canCreateRelation($parentTenantId, $childTenantId)) {
            throw new \RuntimeException('不能建立该店铺层级关系');
        }
    }

    private static function canCreateRelation(int $parentTenantId, int $childTenantId): bool
    {
        if ($parentTenantId <= 0 || $childTenantId <= 0 || $parentTenantId === $childTenantId) {
            return false;
        }
        if (!self::isUsableTenant($parentTenantId) || !self::isUsableTenant($childTenantId)) {
            return false;
        }
        if (self::activeParentRelation($childTenantId)) {
            return false;
        }

        $parentRelation = self::activeParentRelation($parentTenantId);
        if ($parentRelation && str_contains((string)$parentRelation['path'], '/' . $childTenantId . '/')) {
            return false;
        }

        return true;
    }

    private static function requireTenantAdmin(int $userId, int $tenantId): void
    {
        if (!StoreMembershipService::isTenantAdmin($userId, $tenantId)) {
            throw new \RuntimeException('需要店铺管理员权限');
        }
    }

    private static function requireUsableTenant(int $tenantId, string $message): void
    {
        if (!self::isUsableTenant($tenantId)) {
            throw new \RuntimeException($message);
        }
    }

    private static function isUsableTenant(int $tenantId): bool
    {
        $tenant = Db::name('tenant')->where('id', $tenantId)->whereNull('delete_time')->find();
        return $tenant && (int)($tenant['disable'] ?? 0) === 0;
    }

    private static function activeParentRelation(int $childTenantId): ?array
    {
        $relation = Db::name('tenant_relation')
            ->where('child_tenant_id', $childTenantId)
            ->where('status', self::STATUS_ACTIVE)
            ->where('is_deleted', 0)
            ->whereNull('delete_time')
            ->find();

        return $relation ? (array)$relation : null;
    }

    private static function tenantBase(int $tenantId): array
    {
        $tenant = $tenantId > 0 ? Db::name('tenant')
            ->where('id', $tenantId)
            ->whereNull('delete_time')
            ->field('id,sn,name,avatar,tel,disable,notes,create_time,update_time')
            ->find() : null;

        if (!$tenant) {
            return [];
        }

        return [
            'id' => (int)$tenant['id'],
            'tenant_id' => (int)$tenant['id'],
            'sn' => (string)($tenant['sn'] ?? ''),
            'name' => (string)($tenant['name'] ?? ''),
            'store_name' => (string)($tenant['name'] ?? ''),
            'avatar' => (string)($tenant['avatar'] ?? ''),
            'tel' => (string)($tenant['tel'] ?? ''),
            'disable' => (int)($tenant['disable'] ?? 0),
            'notes' => (string)($tenant['notes'] ?? ''),
            'create_time' => $tenant['create_time'] ?? '',
            'update_time' => $tenant['update_time'] ?? '',
        ];
    }

    private static function formatRelation(array $relation, bool $withTenants): array
    {
        $result = self::relationFields($relation);
        if ($withTenants) {
            $result['parent_tenant'] = self::tenantBase((int)$relation['parent_tenant_id']);
            $result['child_tenant'] = self::tenantBase((int)$relation['child_tenant_id']);
        }
        return $result;
    }

    private static function relationFields(array $relation): array
    {
        return [
            'id' => (int)($relation['id'] ?? 0),
            'parent_tenant_id' => (int)($relation['parent_tenant_id'] ?? 0),
            'child_tenant_id' => (int)($relation['child_tenant_id'] ?? 0),
            'relation_type' => (string)($relation['relation_type'] ?? ''),
            'status' => (int)($relation['status'] ?? 0),
            'level' => (int)($relation['level'] ?? 0),
            'path' => (string)($relation['path'] ?? ''),
            'invite_id' => (int)($relation['invite_id'] ?? 0),
            'creator_user_id' => (int)($relation['creator_user_id'] ?? 0),
            'accepted_user_id' => (int)($relation['accepted_user_id'] ?? 0),
            'accepted_at' => (int)($relation['accepted_at'] ?? 0),
            'permissions' => self::decodeJson($relation['permissions'] ?? null),
            'remark' => (string)($relation['remark'] ?? ''),
            'create_time' => $relation['create_time'] ?? '',
            'update_time' => $relation['update_time'] ?? '',
        ];
    }

    private static function encodeJson($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private static function decodeJson($value): array
    {
        if (empty($value)) {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
