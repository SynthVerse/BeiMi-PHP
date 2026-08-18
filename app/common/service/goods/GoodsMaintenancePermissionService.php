<?php

declare(strict_types=1);

namespace app\common\service\goods;

use app\common\service\jxc\StoreMembershipService;
use app\tenantapi\logic\auth\AuthLogic;
use think\facade\Db;

final class GoodsMaintenancePermissionService
{
    public const MAINTAIN_PERMISSION = 'goods.tenant_goods/add';
    public const USER_MAINTAIN_PERMISSION = 'goods.maintain';

    public static function canMaintain(): bool
    {
        $adminInfo = request()->adminInfo ?? null;
        if (!is_array($adminInfo)) {
            return false;
        }

        $tenantId = (int)(request()->tenantId ?? 0);
        $fromUserToken = (bool)(request()->jxcFromUserToken ?? false);
        $adminId = (int)(request()->adminId ?? 0);
        $userId = (int)(request()->userId ?? 0);
        $principalId = $fromUserToken ? $userId : $adminId;
        if ($tenantId <= 0
            || $principalId <= 0
            || $tenantId !== (int)($adminInfo['tenant_id'] ?? 0)
            || $principalId !== (int)($adminInfo['admin_id'] ?? 0)
        ) {
            return false;
        }

        if ($fromUserToken) {
            return self::userTokenCanMaintain($userId, $tenantId);
        }

        if ((int)($adminInfo['root'] ?? 0) === 1) {
            return true;
        }

        return in_array(
            self::MAINTAIN_PERMISSION,
            AuthLogic::getAuthByAdminId($adminId),
            true
        );
    }

    private static function userTokenCanMaintain(int $userId, int $tenantId): bool
    {
        if (StoreMembershipService::isTenantAdmin($userId, $tenantId)) {
            return true;
        }

        $employeeId = (int)Db::name('employee')
            ->where('tenant_id', $tenantId)
            ->where('bind_user_id', $userId)
            ->where('is_enabled', 1)
            ->whereNull('delete_time')
            ->value('id');
        return $employeeId > 0 && Db::name('employee_permission')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('permission_key', self::USER_MAINTAIN_PERMISSION)
            ->count() > 0;
    }
}
