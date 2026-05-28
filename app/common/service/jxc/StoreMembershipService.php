<?php

declare(strict_types=1);

namespace app\common\service\jxc;

use app\common\cache\UserTokenCache;
use app\common\model\tenant\Tenant;
use think\facade\Db;
use think\facade\Log;

class StoreMembershipService
{
    private const AUTO_PROVISION_NOTE = '微信小程序用户自动创建';

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';
    public const STATUS_ACTIVE = 1;
    public const STATUS_DISABLED = 0;

    public static function createStore(int $userId, array $params, ?string $token = null): array
    {
        $name = trim((string)($params['name'] ?? $params['store_name'] ?? $params['tenant_name'] ?? ''));
        if ($userId <= 0) {
            throw new \RuntimeException('请先登录');
        }
        if ($name === '') {
            throw new \RuntimeException('店铺名称不能为空');
        }

        return Db::transaction(function () use ($userId, $params, $name, $token) {
            $lockedUserId = (int)Db::name('user')->where('id', $userId)->lock(true)->value('id');
            if ($lockedUserId <= 0) {
                throw new \RuntimeException('请先登录');
            }
            if (self::hasCreatedStore($userId)) {
                throw new \RuntimeException('已有店铺，请勿重复创建');
            }

            $time = time();
            $sn = Tenant::createUserSn();
            $tenantId = (int)Db::name('tenant')->insertGetId([
                'sn'                  => $sn,
                'name'                => $name,
                'avatar'              => (string)($params['avatar'] ?? ''),
                'tel'                 => (string)($params['tel'] ?? $params['phone'] ?? ''),
                'domain_alias'        => '',
                'domain_alias_enable' => 0,
                'disable'             => 0,
                'notes'               => (string)($params['notes'] ?? $params['remark'] ?? ''),
                'tactics'             => 0,
                'expired_time'        => $time,
                'create_time'         => $time,
                'update_time'         => $time,
            ]);

            if ($tenantId <= 0) {
                throw new \RuntimeException('创建店铺失败');
            }

            self::createOrActivateMember($tenantId, $userId, self::ROLE_OWNER, 0, self::generateInviteCode());
            self::ensureTenantInvite($tenantId, $userId);
            DefaultDataInitService::initForTenant($tenantId);
            self::setCurrentTenant($userId, $tenantId, $token);

            Log::info('[StoreMembership] store created', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
            ]);

            return self::formatTenant((array)Db::name('tenant')->where('id', $tenantId)->find(), $userId);
        });
    }

    public static function joinByInviteCode(int $userId, string $inviteCode, ?string $token = null): array
    {
        $inviteCode = strtoupper(trim($inviteCode));
        if ($userId <= 0) {
            throw new \RuntimeException('请先登录');
        }
        if ($inviteCode === '') {
            throw new \RuntimeException('邀请码不能为空');
        }

        return Db::transaction(function () use ($userId, $inviteCode, $token) {
            try {
                $invite = TenantInviteService::consume($inviteCode, TenantInviteService::TYPE_MEMBER, $userId);
                $tenantId = (int)$invite['tenant_id'];
                $inviterId = (int)$invite['creator_user_id'];
                $role = self::normalizeMemberRole((string)($invite['role'] ?? self::ROLE_MEMBER));
            } catch (\RuntimeException $e) {
                $inviteExists = Db::name('tenant_invite')
                    ->where('code', $inviteCode)
                    ->whereNull('delete_time')
                    ->count() > 0;
                if ($inviteExists) {
                    throw $e;
                }
                $ownerMember = Db::name('tenant_member')
                    ->where('invite_code', $inviteCode)
                    ->where('status', self::STATUS_ACTIVE)
                    ->whereNull('delete_time')
                    ->find();
                if (!$ownerMember) {
                    throw new \RuntimeException('邀请码无效');
                }
                $tenantId = (int)$ownerMember['tenant_id'];
                $inviterId = (int)$ownerMember['user_id'];
                $role = self::ROLE_MEMBER;
            }

            $tenant = Db::name('tenant')->where('id', $tenantId)->whereNull('delete_time')->find();
            if (!$tenant || (int)($tenant['disable'] ?? 0) === 1) {
                throw new \RuntimeException('店铺不可用');
            }

            self::createOrActivateMember($tenantId, $userId, $role, $inviterId);
            self::setCurrentTenant($userId, $tenantId, $token);

            return self::formatTenant((array)$tenant, $userId);
        });
    }

    public static function switchTenant(int $userId, int $tenantId, ?string $token = null): array
    {
        if ($userId <= 0) {
            throw new \RuntimeException('请先登录');
        }
        if (!self::hasActiveMembership($userId, $tenantId)) {
            self::ensureLegacyMembership($userId, $tenantId);
        }
        if (!self::hasActiveMembership($userId, $tenantId)) {
            throw new \RuntimeException('无权访问该店铺');
        }

        $tenant = Db::name('tenant')->where('id', $tenantId)->whereNull('delete_time')->find();
        if (!$tenant || (int)($tenant['disable'] ?? 0) === 1) {
            throw new \RuntimeException('店铺不可用');
        }

        self::setCurrentTenant($userId, $tenantId, $token);
        return self::formatTenant((array)$tenant, $userId);
    }

    public static function listTenants(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        self::ensureLegacyDefaultMembership($userId);

        $rows = Db::name('tenant_member')
            ->alias('m')
            ->join('tenant t', 't.id = m.tenant_id')
            ->where('m.user_id', $userId)
            ->where('m.status', self::STATUS_ACTIVE)
            ->whereNull('m.delete_time')
            ->whereNull('t.delete_time')
            ->field('t.id,t.sn,t.name,t.avatar,t.tel,t.disable,t.notes,t.create_time,m.role,m.invite_code,m.joined_at')
            ->order('m.id desc')
            ->select()
            ->toArray();

        return array_map(fn($row) => self::formatTenant((array)$row, $userId), $rows);
    }

    public static function hasCreatedStore(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return Db::name('tenant_member')
            ->alias('m')
            ->join('tenant t', 't.id = m.tenant_id')
            ->where('m.user_id', $userId)
            ->where('m.role', self::ROLE_OWNER)
            ->where('m.status', self::STATUS_ACTIVE)
            ->whereNull('m.delete_time')
            ->whereNull('t.delete_time')
            ->whereRaw('(`t`.`notes` IS NULL OR `t`.`notes` <> ?)', [self::AUTO_PROVISION_NOTE])
            ->count() > 0;
    }

    public static function currentTenant(int $userId, int $tenantId): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            return [];
        }
        self::ensureLegacyMembership($userId, $tenantId);

        $tenant = Db::name('tenant')->where('id', $tenantId)->whereNull('delete_time')->find();
        if (!$tenant || !self::hasActiveMembership($userId, $tenantId)) {
            return [];
        }
        return self::formatTenant((array)$tenant, $userId);
    }

    public static function requireCurrentMembership(int $userId, int $tenantId): bool
    {
        if ($userId <= 0 || $tenantId <= 0) {
            return false;
        }
        if (self::hasActiveMembership($userId, $tenantId)) {
            return true;
        }
        self::ensureLegacyMembership($userId, $tenantId);
        return self::hasActiveMembership($userId, $tenantId);
    }

    public static function getInviteCode(int $userId, int $tenantId): string
    {
        if (!self::isTenantAdmin($userId, $tenantId)) {
            throw new \RuntimeException('需要店铺管理员权限');
        }

        $invite = Db::name('tenant_invite')
            ->where('tenant_id', $tenantId)
            ->where('invite_type', TenantInviteService::TYPE_MEMBER)
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('delete_time')
            ->order('id asc')
            ->find();
        if ($invite) {
            return (string)$invite['code'];
        }

        return self::ensureTenantInvite($tenantId, $userId);
    }

    public static function memberInvite(int $userId, int $tenantId): array
    {
        $code = self::getInviteCode($userId, $tenantId);
        $invite = Db::name('tenant_invite')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->where('invite_type', TenantInviteService::TYPE_MEMBER)
            ->whereNull('delete_time')
            ->find();

        return TenantInviteService::formatInvite((array)$invite);
    }

    public static function isTenantAdmin(int $userId, int $tenantId): bool
    {
        return in_array(self::memberRole($userId, $tenantId), [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    public static function memberRole(int $userId, int $tenantId): string
    {
        if ($userId <= 0 || $tenantId <= 0) {
            return '';
        }
        self::ensureLegacyMembership($userId, $tenantId);

        return (string)Db::name('tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('delete_time')
            ->value('role');
    }

    private static function ensureTenantInvite(int $tenantId, int $userId): string
    {
        $member = Db::name('tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('role', self::ROLE_OWNER)
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('delete_time')
            ->order('id asc')
            ->find();
        if (!$member) {
            $member = Db::name('tenant_member')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('status', self::STATUS_ACTIVE)
                ->whereNull('delete_time')
                ->find();
        }

        $inviteCode = (string)($member['invite_code'] ?? '');
        if ($inviteCode === '') {
            $inviteCode = TenantInviteService::generateCode();
            Db::name('tenant_member')->where('id', (int)$member['id'])->update([
                'invite_code' => $inviteCode,
                'update_time' => time(),
            ]);
        }

        $time = time();
        $exists = Db::name('tenant_invite')
            ->where('tenant_id', $tenantId)
            ->where('code', $inviteCode)
            ->whereNull('delete_time')
            ->find();
        if (!$exists) {
            Db::name('tenant_invite')->insert([
                'tenant_id' => $tenantId,
                'creator_user_id' => $userId,
                'code' => $inviteCode,
                'invite_type' => TenantInviteService::TYPE_MEMBER,
                'target_user_id' => 0,
                'target_tenant_id' => 0,
                'role' => self::ROLE_MEMBER,
                'relation_type' => '',
                'status' => self::STATUS_ACTIVE,
                'expire_time' => 0,
                'max_uses' => 0,
                'used_count' => 0,
                'extra' => null,
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => null,
            ]);
        }

        return $inviteCode;
    }

    private static function createOrActivateMember(
        int $tenantId,
        int $userId,
        string $role,
        int $inviterId = 0,
        string $inviteCode = ''
    ): void {
        $time = time();
        $existing = Db::name('tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->find();

        if ($existing) {
            Db::name('tenant_member')->where('id', (int)$existing['id'])->update([
                'role' => $existing['role'] ?: self::normalizeMemberRole($role),
                'status' => self::STATUS_ACTIVE,
                'delete_time' => null,
                'update_time' => $time,
            ]);
            return;
        }

        Db::name('tenant_member')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role' => self::normalizeMemberRole($role),
            'status' => self::STATUS_ACTIVE,
            'invite_code' => $inviteCode,
            'inviter_id' => $inviterId,
            'joined_at' => $time,
            'create_time' => $time,
            'update_time' => $time,
            'delete_time' => null,
        ]);
    }

    private static function hasActiveMembership(int $userId, int $tenantId): bool
    {
        if ($userId <= 0 || $tenantId <= 0) {
            return false;
        }

        return Db::name('tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('delete_time')
            ->count() > 0;
    }

    private static function ensureLegacyDefaultMembership(int $userId): void
    {
        $tenantId = (int)Db::name('user')->where('id', $userId)->value('tenant_id');
        if ($tenantId > 0) {
            self::ensureLegacyMembership($userId, $tenantId);
        }
    }

    private static function ensureLegacyMembership(int $userId, int $tenantId): void
    {
        if ($tenantId <= 0 || $userId <= 0 || self::hasActiveMembership($userId, $tenantId)) {
            return;
        }

        $currentTenantId = (int)Db::name('user')->where('id', $userId)->value('tenant_id');
        if ($currentTenantId !== $tenantId) {
            return;
        }

        $tenantExists = Db::name('tenant')->where('id', $tenantId)->whereNull('delete_time')->count() > 0;
        if (!$tenantExists) {
            return;
        }

        self::createOrActivateMember($tenantId, $userId, self::ROLE_OWNER, 0, TenantInviteService::generateCode());
    }

    private static function setCurrentTenant(int $userId, int $tenantId, ?string $token = null): void
    {
        Db::name('user')->where('id', $userId)->update([
            'tenant_id' => $tenantId,
            'update_time' => time(),
        ]);

        if ($token) {
            $cache = new UserTokenCache();
            $cache->deleteUserInfo($token);
            $cache->setUserInfo($token);
        }
    }

    private static function generateInviteCode(): string
    {
        return TenantInviteService::generateCode();
    }

    private static function normalizeMemberRole(string $role): string
    {
        return in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_VIEWER], true)
            ? $role
            : self::ROLE_MEMBER;
    }

    private static function formatTenant(array $tenant, int $userId): array
    {
        $tenantId = (int)($tenant['id'] ?? $tenant['tenant_id'] ?? 0);
        $member = $tenantId > 0 ? Db::name('tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find() : null;

        return [
            'id' => $tenantId,
            'tenant_id' => $tenantId,
            'sn' => (string)($tenant['sn'] ?? ''),
            'name' => (string)($tenant['name'] ?? ''),
            'store_name' => (string)($tenant['name'] ?? ''),
            'avatar' => (string)($tenant['avatar'] ?? ''),
            'tel' => (string)($tenant['tel'] ?? ''),
            'disable' => (int)($tenant['disable'] ?? 0),
            'notes' => (string)($tenant['notes'] ?? ''),
            'role' => (string)($member['role'] ?? $tenant['role'] ?? ''),
            'invite_code' => (string)($member['invite_code'] ?? $tenant['invite_code'] ?? ''),
            'create_time' => $tenant['create_time'] ?? '',
        ];
    }
}
