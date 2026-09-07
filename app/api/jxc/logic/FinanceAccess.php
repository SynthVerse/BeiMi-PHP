<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\service\jxc\StoreMembershipService;

/** 后台管理员与小程序用户具有不同身份空间，不能仅用数字ID复用授权。 */
final class FinanceAccess
{
    public static function tenant(): int { return (int)(request()->tenantId ?? 0); }
    public static function userIdentity(): bool { return (bool)(request()->jxcFromUserToken ?? false); }
    public static function operator(): int { return self::userIdentity() ? (int)(request()->userId ?? 0) : (int)(request()->adminId ?? 0); }
    public static function owner(): bool
    {
        if (self::tenant() <= 0 || self::operator() <= 0) { return false; }
        if (self::userIdentity()) { return StoreMembershipService::isTenantAdmin(self::operator(), self::tenant()); }
        $info = (array)(request()->adminInfo ?? []);
        return (int)($info['root'] ?? 0) === 1 && (int)($info['tenant_id'] ?? 0) === self::tenant();
    }
    public static function has(string $permission): bool
    {
        return self::owner() || (self::userIdentity() && self::tenant() > 0 && self::operator() > 0 && WorkforceLogic::hasPermission($permission));
    }
    public static function require(string $permission, bool $ownerOnly = false): void
    {
        if (self::tenant() <= 0 || self::operator() <= 0) { throw new \DomainException('请先登录并选择门店'); }
        if ($ownerOnly ? !self::owner() : !self::has($permission)) { throw new \DomainException($ownerOnly ? '此项确认仅限门店最高权限人员' : '没有对应财务业务权限'); }
    }
    public static function actor(): array
    {
        return ['id' => self::operator(), 'type' => self::userIdentity() ? 'user' : 'tenant_admin',
            'name' => (string)(request()->adminInfo['name'] ?? '') ?: '操作人 #' . self::operator()];
    }
}
