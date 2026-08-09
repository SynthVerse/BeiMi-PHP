<?php

namespace app\tenantapi\logic\goods;


use app\common\model\goods\TenantGoodscat;
use app\common\logic\BaseLogic;
use app\common\service\jxc\DefaultDataInitService;
use think\facade\Db;


/**
 * TenantGoodscat逻辑
 * Class TenantGoodscatLogic
 * @package app\tenantapi\logic\goods
 */
class TenantGoodscatLogic extends BaseLogic
{


    /**
     * @notes 添加
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public static function add(array $params): bool
    {
        $name = trim((string)$params['name']);
        if ($name === DefaultDataInitService::DEFAULT_GOODS_CATEGORY_NAME) {
            self::setError('默认商品分类由系统维护');
            return false;
        }

        Db::startTrans();
        try {
            TenantGoodscat::create([
                'tenant_id' => (int)(request()->tenantId ?? 0),
                'name' => $name,
                'sort' => $params['sort'],
                'is_show' => $params['is_show']
            ]);

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 编辑
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public static function edit(array $params): bool
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $category = TenantGoodscat::where('id', (int)$params['id'])
            ->where('tenant_id', $tenantId)
            ->findOrEmpty();
        if ($category->isEmpty()) {
            self::setError('商品分类不存在');
            return false;
        }

        $name = trim((string)$params['name']);
        if ((int)($category['is_default'] ?? 0) === 1
            && ($name !== DefaultDataInitService::DEFAULT_GOODS_CATEGORY_NAME
                || (int)$params['is_show'] !== 0)
        ) {
            self::setError('默认商品分类不可改名或隐藏');
            return false;
        }
        if ((int)($category['is_default'] ?? 0) !== 1
            && $name === DefaultDataInitService::DEFAULT_GOODS_CATEGORY_NAME
        ) {
            self::setError('默认商品分类由系统维护');
            return false;
        }

        Db::startTrans();
        try {
            TenantGoodscat::where('id', (int)$params['id'])
                ->where('tenant_id', $tenantId)
                ->update([
                'name' => $name,
                'sort' => $params['sort'],
                'is_show' => $params['is_show']
            ]);

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 删除
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public static function delete(array $params): bool
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $category = TenantGoodscat::where('id', (int)$params['id'])
            ->where('tenant_id', $tenantId)
            ->findOrEmpty();
        if ($category->isEmpty()) {
            self::setError('商品分类不存在');
            return false;
        }
        if ((int)($category['is_default'] ?? 0) === 1) {
            self::setError('默认商品分类不可删除');
            return false;
        }

        return $category->delete();
    }


    /**
     * @notes 获取详情
     * @param $params
     * @return array
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public static function detail($params): array
    {
        return TenantGoodscat::where('id', $params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty()
            ->toArray();
    }

    /**
     * @notes 获取所有
     * @param $params
     * @return array
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public static function all(): array
    {
        return TenantGoodscat::where(['is_show' => 0])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->order(['is_default' => 'desc', 'sort' => 'desc', 'id' => 'desc'])
            ->field(["id", "name", "is_default"])
            ->select()
            ->toArray();
    }
}
