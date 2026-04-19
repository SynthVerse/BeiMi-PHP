<?php

namespace app\tenantapi\logic\goods;


use app\common\model\goods\TenantGoodscat;
use app\common\logic\BaseLogic;
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
        Db::startTrans();
        try {
            TenantGoodscat::create([
                'name' => $params['name'],
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
        Db::startTrans();
        try {
            TenantGoodscat::where('id', $params['id'])->update([
                'name' => $params['name'],
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
        return TenantGoodscat::destroy($params['id']);
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
        return TenantGoodscat::findOrEmpty($params['id'])->toArray();
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
        return TenantGoodscat::where(['is_show' => 0])->order(['sort' => 'desc', 'id' => 'desc'])->field(["id", "name"])->select()->toArray();
    }
}