<?php
namespace app\tenantapi\logic\goods;


use app\common\model\goods\TenantGoods;
use app\common\logic\BaseLogic;
use think\facade\Db;


/**
 * TenantGoods逻辑
 * Class TenantGoodsLogic
 * @package app\platform\logic
 */
class TenantGoodsLogic extends BaseLogic
{


    /**
     * @notes 添加
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/04 14:21
     */
    public static function add(array $params): bool
    {
        Db::startTrans();
        try {
            TenantGoods::create([
                'name' => $params['name'],
                'cate_id' => $params['cate_id'],
                'units' => $params['units'],
                'short_name' => $params['short_name'],
                'moneys' => $params['moneys'],
                'sort' => $params['sort'],
                'is_show' => $params['is_show'],
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
     * @date 2025/12/04 14:21
     */
    public static function edit(array $params): bool
    {
        Db::startTrans();
        try {
            TenantGoods::where('id', $params['id'])->update([
                'name' => $params['name'],
                'cate_id' => $params['cate_id'],
                'units' => $params['units'],
                'short_name' => $params['short_name'],
                'moneys' => $params['moneys'],
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
     * @date 2025/12/04 14:21
     */
    public static function delete(array $params): bool
    {
        return TenantGoods::destroy($params['id']);
    }


    /**
     * @notes 获取详情
     * @param $params
     * @return array
     * @author likeadmin
     * @date 2025/12/04 14:21
     */
    public static function detail($params): array
    {
        return TenantGoods::findOrEmpty($params['id'])->toArray();
    }
}