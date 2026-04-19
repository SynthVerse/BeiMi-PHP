<?php

namespace app\tenantapi\logic\user;

use app\common\enum\user\AccountLogEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\logic\AccountLogLogic;
use app\common\logic\BaseLogic;
use app\common\model\tenant\Tenant;
use app\common\model\user\User;
use app\common\model\user\UserMoney;
use app\common\model\user\UserOrder;
use think\facade\Db;

/**
 * 用户逻辑层
 * Class TenantLogic
 * @package app\tenantapi\logic\user
 */
class UserLogic extends BaseLogic
{

    /**
     * @notes 用户详情
     * @param int $userId
     * @return array
     * @author 段誉
     * @date 2022/9/22 16:32
     */
    public static function detail(int $userId): array
    {
        $field = [
            'id', 'real_name', 'account', 'nickname', 'real_name', 'mobile', 'create_time', 'login_time', 'user_money', 'is_disable'
        ];

        $user = User::where(['id' => $userId])->field($field)
            ->findOrEmpty();
        return $user->toArray();
    }


    /**
     * @notes 更新用户信息
     * @param array $params
     * @return User
     * @author 段誉
     * @date 2022/9/22 16:38
     */
    public static function setUserInfo(array $params)
    {
        return User::update([
            $params['field'] => $params['value']
        ], ['id' => $params['id']]);
    }

    /**
     * @notes 删除
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/08 10:57
     */
    public static function delete(array $params): bool
    {
        return User::destroy($params['id']);
    }

    /**
     * @notes 更新用户信息
     * @param array $params
     * @return User
     * @author 段誉
     * @date 2022/9/22 16:38
     */
    public static function addUserInfo(array $params)
    {
        $params['account'] = Tenant::createUserSn();
        $params['password'] = md5($params['account']);
        return User::create($params);
    }


    /**
     * @notes 调整用户余额
     * @param array $params
     * @return bool|string
     * @author 段誉
     * @date 2023/2/23 14:25
     */
    public static function adjustUserMoney(array $params)
    {
        Db::startTrans();
        try {
            $user = User::find($params['user_id']);
            if (AccountLogEnum::INC == $params['action']) {
                //调整可用余额
                $user->user_money += $params['num'];
                $user->save();
                //记录日志
                AccountLogLogic::add(
                    $user->id,
                    AccountLogEnum::UM_INC_ADMIN,
                    AccountLogEnum::INC,
                    $params['num'],
                    '',
                    $params['remark'] ?? ''
                );
            } else {
                $user->user_money -= $params['num'];
                $user->save();
                //记录日志
                AccountLogLogic::add(
                    $user->id,
                    AccountLogEnum::UM_DEC_ADMIN,
                    AccountLogEnum::DEC,
                    $params['num'],
                    '',
                    $params['remark'] ?? ''
                );
            }

            Db::commit();
            return true;

        } catch (\Exception $e) {
            Db::rollback();
            return $e->getMessage();
        }
    }

    /**
     * @notes 订单支付
     * @param $params
     * @return array
     * @date 2025/12/22 16:53
     */
    public static function pay(array $params, int $adminId): bool
    {
        $info = User::findOrEmpty($params['id'])->toArray();
        if (!$info) {
            self::setError("查询信息失败");
            return false;
        }

        $money = floatval($params["order_money"]);
        if ($info["order_arrears_money"] < $money || $money <= 0) {
            self::setError("付款金额大于欠款金额");
            return false;
        }

        $updates = [];
        $ids = 0;
        $idsArr = [];
        while (true) {
            $infoTemp = UserOrder::where("user_id", $params['id'])->where("id", ">", $ids)->where(["pay_status" => 1])->field(["id", "order_money", "order_pay_money", "order_arrears_money"])->order("id", "asc")->find();
            if ($infoTemp == null) {
                break;
            }

            $temp = [];
            $temp["id"] = $infoTemp["id"];
            if ($infoTemp["order_arrears_money"] < $money) {
                $temp['order_pay_money'] = bcadd(strval($infoTemp['order_pay_money']), strval($infoTemp['order_arrears_money']), 2);
                $temp['order_arrears_money'] = 0;
                $temp['pay_status'] = 2;
                $temp['status'] = 3;
                $money = bcsub(strval($money), strval($infoTemp['order_arrears_money']), 2);
                array_push($updates, $temp);
                $ids = $infoTemp["id"];
            } else {
                $temp['order_pay_money'] = bcadd(strval($infoTemp['order_pay_money']), strval($money), 2);
                $temp['order_arrears_money'] = bcsub(strval($infoTemp['order_arrears_money']), strval($money), 2);
                $temp['pay_status'] = $infoTemp["order_arrears_money"] == $money ? 2 : 1;
                $temp['status'] = $infoTemp["order_arrears_money"] == $money ? 3 : 2;
                array_push($updates, $temp);
                break;
            }

        }

        if (count($updates) == 0) {
            self::setError("暂无可付款订单");
            return false;
        }

        Db::startTrans();
        try {
            $idsArr = array_column($updates, "id");
            foreach ($updates as $k => $val) {
                $tempUpdate = $val;
                unset($tempUpdate["id"]);
                UserOrder::where("id", $val["id"])->update($tempUpdate);
            }

            UserMoney::create([
                'admin_id' => $adminId,
                'user_id' => $params['id'],
                'money' => $params["order_money"],
                'remarks' => "订单付款",
                'order_ids' => implode(",", $idsArr),
            ]);

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }

        return true;
    }

}