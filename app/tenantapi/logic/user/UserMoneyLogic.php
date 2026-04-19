<?php
namespace app\tenantapi\logic\user;


use app\common\model\user\UserMoney;
use app\common\logic\BaseLogic;
use think\facade\Db;


/**
 * UserMoney逻辑
 * Class UserMoneyLogic
 * @package app\tenantapi\logic\user
 */
class UserMoneyLogic extends BaseLogic
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
            UserMoney::create([
                'user_id' => $params['user_id'],
                'money' => $params['money'],
                'remarks' => $params['remarks'],
                'order_ids' => $params['order_ids']
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
            UserMoney::where('id', $params['id'])->update([
                'user_id' => $params['user_id'],
                'money' => $params['money'],
                'remarks' => $params['remarks'],
                'order_ids' => $params['order_ids']
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
        return UserMoney::destroy($params['id']);
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
        return UserMoney::findOrEmpty($params['id'])->toArray();
    }
}