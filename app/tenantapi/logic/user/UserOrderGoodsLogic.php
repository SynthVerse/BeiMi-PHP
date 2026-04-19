<?php
namespace app\tenantapi\logic\user;


use app\common\model\user\UserOrderGoods;
use app\common\logic\BaseLogic;
use think\facade\Db;


/**
 * UserOrderGoods逻辑
 * Class UserOrderGoodsLogic
 * @package app\tenantapi\logic\user
 */
class UserOrderGoodsLogic extends BaseLogic
{


    /**
     * @notes 添加
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public static function add(array $params): bool
    {
        Db::startTrans();
        try {
            UserOrderGoods::create([
                'tenant_id' => $params['tenant_id'],
                'user_id' => $params['user_id'],
                'order_id' => $params['order_id'],
                'goods_id' => $params['goods_id'],
                'name' => $params['name'],
                'number' => $params['number'],
                'units' => $params['units'],
                'units_money' => $params['units_money'],
                'price' => $params['price'],
                'amount' => $params['amount']
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
     * @date 2026/01/07 09:41
     */
    public static function edit(array $params): bool
    {
        Db::startTrans();
        try {
            UserOrderGoods::where('id', $params['id'])->update([
                'tenant_id' => $params['tenant_id'],
                'user_id' => $params['user_id'],
                'order_id' => $params['order_id'],
                'goods_id' => $params['goods_id'],
                'name' => $params['name'],
                'number' => $params['number'],
                'units' => $params['units'],
                'units_money' => $params['units_money'],
                'price' => $params['price'],
                'amount' => $params['amount']
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
     * @date 2026/01/07 09:41
     */
    public static function delete(array $params): bool
    {
        return UserOrderGoods::destroy($params['id']);
    }


    /**
     * @notes 获取详情
     * @param $params
     * @return array
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public static function detail($params): array
    {
        return UserOrderGoods::findOrEmpty($params['id'])->toArray();
    }
}