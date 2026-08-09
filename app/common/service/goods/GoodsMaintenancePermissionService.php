<?php

declare(strict_types=1);

namespace app\common\service\goods;

use app\tenantapi\logic\auth\AuthLogic;

final class GoodsMaintenancePermissionService
{
    public const MAINTAIN_PERMISSION = 'goods.tenant_goods/add';

    public static function canMaintain(): bool
    {
        $adminInfo = request()->adminInfo ?? null;
        if (!is_array($adminInfo) || (bool)(request()->jxcFromUserToken ?? false)) {
            return false;
        }

        $tenantId = (int)(request()->tenantId ?? 0);
        $adminId = (int)(request()->adminId ?? 0);
        if ($tenantId <= 0
            || $adminId <= 0
            || $tenantId !== (int)($adminInfo['tenant_id'] ?? 0)
            || $adminId !== (int)($adminInfo['admin_id'] ?? 0)
        ) {
            return false;
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
}
