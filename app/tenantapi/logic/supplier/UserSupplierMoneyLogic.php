<?php
namespace app\tenantapi\logic\supplier;


use app\common\model\supplier\UserSupplierMoney;
use app\common\logic\BaseLogic;
use think\facade\Db;


/**
 * UserSupplierMoney逻辑
 * Class UserSupplierMoneyLogic
 * @package app\tenantapi\logic\supplier
 */
class UserSupplierMoneyLogic extends BaseLogic
{


    /**
     * @notes 添加
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public static function add(array $params): bool
    {
        Db::startTrans();
        try {
            UserSupplierMoney::create([
                'admin_id' => $params['admin_id'],
                'supplier_id' => $params['supplier_id'],
                'money' => $params['money'],
                'remarks' => $params['remarks'],
                'order_ids' => $params['order_ids'],
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
     * @date 2026/01/06 16:10
     */
    public static function edit(array $params): bool
    {
        Db::startTrans();
        try {
            UserSupplierMoney::where('id', $params['id'])->update([
                'admin_id' => $params['admin_id'],
                'supplier_id' => $params['supplier_id'],
                'money' => $params['money'],
                'remarks' => $params['remarks'],
                'order_ids' => $params['order_ids'],
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
     * @date 2026/01/06 16:10
     */
    public static function delete(array $params): bool
    {
        return UserSupplierMoney::destroy($params['id']);
    }


    /**
     * @notes 获取详情
     * @param $params
     * @return array
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public static function detail($params): array
    {
        return UserSupplierMoney::findOrEmpty($params['id'])->toArray();
    }
}