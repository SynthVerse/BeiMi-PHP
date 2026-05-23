<?php

declare(strict_types=1);

namespace app\common\service\jxc;

use think\facade\Db;

class TenantInviteService
{
    public const TYPE_MEMBER = 'member';
    public const TYPE_RELATION = 'relation';
    public const STATUS_ACTIVE = 1;

    public static function createInvite(array $data): array
    {
        $tenantId = (int)($data['tenant_id'] ?? 0);
        $creatorUserId = (int)($data['creator_user_id'] ?? 0);
        $inviteType = (string)($data['invite_type'] ?? self::TYPE_MEMBER);
        $time = time();

        if ($tenantId <= 0 || $creatorUserId <= 0) {
            throw new \RuntimeException('邀请码参数错误');
        }
        if (!in_array($inviteType, [self::TYPE_MEMBER, self::TYPE_RELATION], true)) {
            throw new \RuntimeException('邀请码类型错误');
        }

        $id = Db::name('tenant_invite')->insertGetId([
            'tenant_id' => $tenantId,
            'creator_user_id' => $creatorUserId,
            'code' => self::generateCode(),
            'invite_type' => $inviteType,
            'target_user_id' => (int)($data['target_user_id'] ?? 0),
            'target_tenant_id' => (int)($data['target_tenant_id'] ?? 0),
            'role' => (string)($data['role'] ?? StoreMembershipService::ROLE_MEMBER),
            'relation_type' => (string)($data['relation_type'] ?? ''),
            'status' => self::STATUS_ACTIVE,
            'expire_time' => (int)($data['expire_time'] ?? 0),
            'max_uses' => (int)($data['max_uses'] ?? 0),
            'used_count' => 0,
            'extra' => self::encodeJson($data['extra'] ?? null),
            'create_time' => $time,
            'update_time' => $time,
            'delete_time' => null,
        ]);

        return self::formatInvite((array)Db::name('tenant_invite')->where('id', $id)->find());
    }

    public static function preview(string $code, string $inviteType, int $userId = 0, int $tenantId = 0): array
    {
        return self::formatInvite(self::validate($code, $inviteType, $userId, $tenantId, false));
    }

    public static function consume(string $code, string $inviteType, int $userId = 0, int $tenantId = 0): array
    {
        $invite = self::validate($code, $inviteType, $userId, $tenantId, true);
        Db::name('tenant_invite')->where('id', (int)$invite['id'])->update([
            'used_count' => (int)$invite['used_count'] + 1,
            'update_time' => time(),
        ]);
        $invite['used_count'] = (int)$invite['used_count'] + 1;
        return self::formatInvite($invite);
    }

    public static function generateCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $existsInvite = Db::name('tenant_invite')
                ->where('code', $code)
                ->whereNull('delete_time')
                ->count() > 0;
            $existsMember = Db::name('tenant_member')
                ->where('invite_code', $code)
                ->whereNull('delete_time')
                ->count() > 0;
        } while ($existsInvite || $existsMember);

        return $code;
    }

    private static function validate(string $code, string $inviteType, int $userId, int $tenantId, bool $lock): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new \RuntimeException('邀请码不能为空');
        }

        $query = Db::name('tenant_invite')
            ->where('code', $code)
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('delete_time');
        if ($lock) {
            $query->lock(true);
        }
        $invite = $query->find();
        if (!$invite) {
            throw new \RuntimeException('邀请码无效');
        }

        $actualType = (string)($invite['invite_type'] ?? self::TYPE_MEMBER);
        if ($actualType === '') {
            $actualType = self::TYPE_MEMBER;
        }
        if ($actualType !== $inviteType) {
            throw new \RuntimeException('邀请码类型不匹配');
        }

        $expireTime = (int)($invite['expire_time'] ?? 0);
        if ($expireTime > 0 && $expireTime < time()) {
            throw new \RuntimeException('邀请码已过期');
        }
        $maxUses = (int)($invite['max_uses'] ?? 0);
        if ($maxUses > 0 && (int)($invite['used_count'] ?? 0) >= $maxUses) {
            throw new \RuntimeException('邀请码已达使用上限');
        }
        $targetUserId = (int)($invite['target_user_id'] ?? 0);
        if ($targetUserId > 0 && $userId > 0 && $targetUserId !== $userId) {
            throw new \RuntimeException('该邀请码不适用于当前用户');
        }
        $targetTenantId = (int)($invite['target_tenant_id'] ?? 0);
        if ($targetTenantId > 0 && $tenantId > 0 && $targetTenantId !== $tenantId) {
            throw new \RuntimeException('该邀请码不适用于当前店铺');
        }

        return (array)$invite;
    }

    public static function formatInvite(array $invite): array
    {
        return [
            'id' => (int)($invite['id'] ?? 0),
            'tenant_id' => (int)($invite['tenant_id'] ?? 0),
            'creator_user_id' => (int)($invite['creator_user_id'] ?? 0),
            'code' => (string)($invite['code'] ?? ''),
            'invite_code' => (string)($invite['code'] ?? ''),
            'invite_type' => (string)(($invite['invite_type'] ?? '') ?: self::TYPE_MEMBER),
            'target_user_id' => (int)($invite['target_user_id'] ?? 0),
            'target_tenant_id' => (int)($invite['target_tenant_id'] ?? 0),
            'role' => (string)($invite['role'] ?? StoreMembershipService::ROLE_MEMBER),
            'relation_type' => (string)($invite['relation_type'] ?? ''),
            'status' => (int)($invite['status'] ?? 0),
            'expire_time' => (int)($invite['expire_time'] ?? 0),
            'max_uses' => (int)($invite['max_uses'] ?? 0),
            'used_count' => (int)($invite['used_count'] ?? 0),
            'extra' => self::decodeJson($invite['extra'] ?? null),
            'create_time' => $invite['create_time'] ?? '',
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
