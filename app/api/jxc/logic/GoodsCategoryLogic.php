<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\goods\TenantGoodscat;
use app\common\service\goods\GoodsMaintenancePermissionService;
use app\common\service\jxc\DefaultDataInitService;
use think\facade\Db;

final class GoodsCategoryLogic extends BaseLogic
{
    public static function add(array $params): array|false
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            self::setError('商品分类租户上下文缺失，请重新登录');
            return false;
        }
        if (!GoodsMaintenancePermissionService::canMaintain()) {
            self::setError('当前账号没有商品维护权限');
            return false;
        }

        $name = trim((string)($params['name'] ?? ''));
        if ($name === DefaultDataInitService::DEFAULT_GOODS_CATEGORY_NAME) {
            self::setError('默认商品分类由系统维护');
            return false;
        }

        Db::startTrans();
        try {
            $lockedTenantId = (int)Db::name('tenant')
                ->where('id', $tenantId)
                ->lock(true)
                ->value('id');
            if ($lockedTenantId !== $tenantId) {
                Db::rollback();
                self::setError('当前租户不存在');
                return false;
            }

            $exists = TenantGoodscat::where('tenant_id', $tenantId)
                ->where('name', $name)
                ->whereNull('delete_time')
                ->count() > 0;
            if ($exists) {
                Db::rollback();
                self::setError('分类名称已存在');
                return false;
            }

            $category = TenantGoodscat::create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'sort' => 0,
                'is_show' => 0,
                'is_default' => null,
            ]);
            $result = [
                'id' => (int)$category->id,
                'name' => $name,
                'is_default' => 0,
            ];
            Db::commit();
            return $result;
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }
}
