<?php

namespace app\tenantapi\logic\user;


use app\common\model\goods\TenantGoods;
use app\common\model\user\User;
use app\common\model\user\UserMoney;
use app\common\model\user\UserOrder;
use app\common\logic\BaseLogic;
use app\common\model\user\UserOrderGoods;
use think\facade\Db;


/**
 * UserOrder逻辑
 * Class UserOrderLogic
 * @package app\tenantapi\logic\user
 */
class UserOrderLogic extends BaseLogic
{


    /**
     * @notes 添加
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public static function add(array $params, int $adminId): bool
    {
        $userInfo = User::where("id", $params['user_id'])->find();
        if ($userInfo == null) {
            self::setError("供应商信息不存在");
            return false;
        }

        $goodsIds = array_unique(array_filter(array_column($params['goods'], "id")));
        if (count($goodsIds) != count($params['goods'])) {
            self::setError("商品信息错误");
            return false;
        }

        $glist = TenantGoods::whereIn("id", implode(",", $goodsIds))->field(["id", "name", "units"])->select()->toArray();
        if (count($glist) != count($params['goods'])) {
            self::setError("商品信息错误");
            return false;
        }

        $goodsPrice = array_column($params['goods'], "price", "id");
        $goodsWeight = array_column($params['goods'], "weight", "id");

        //计算金额
        $orderMoney = 0;
        $goodsNumber = 0;
        $orderGoodsIndex = [];
        $nowtime = time();
        foreach ($glist as $value) {
            $temp = [];
            $temp['user_id'] = $userInfo['id'];
            $temp['goods_id'] = $value['id'];
            $temp['name'] = $value['name'];
            $temp['number'] = $goodsWeight[$value['id']];
            $temp['price'] = $goodsPrice[$value['id']];
            $temp['amount'] = bcmul(strval($temp['number']), strval($temp['price']), 2);
            $temp['units'] = $value['units'];
            $temp['units_money'] = $goodsPrice[$value['id']];
            $orderMoney = bcadd($temp['amount'], strval($orderMoney), 2);
            $goodsNumber = bcadd($temp['number'], strval($goodsNumber), 2);
            array_push($orderGoodsIndex, $temp);
        }

        $orderSn = "KH" . date('YmdHis') . rand(100000, 999999);
        Db::startTrans();
        try {
            $umodel = UserOrder::create([
                'order_sn' => $orderSn,
                'user_id' => $userInfo['id'],
                'order_number' => count($params["goods"]),
                'order_money' => $orderMoney,
                'order_pay_money' => 0,
                'order_arrears_money' => $orderMoney,
                'goods_number' => $goodsNumber,
                'status' => 1,
                'pay_status' => 1,
                'order_status' => 1,
                'datetimesingle' => date("Y-m-d H:i:s"),
                'remarks' => "",
                'admin_id' => $adminId,
            ]);

            $orderId = $umodel->getLastInsID();
            foreach ($orderGoodsIndex as $k => $val) {
                $orderGoodsIndex[$k]["order_id"] = $orderId;
            }

            (new UserOrderGoods())->saveAll($orderGoodsIndex);
            Db::commit();
            self::userStatic($userInfo["id"], 3);
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
     * @date 2026/01/07 09:40
     */
    public static function delete(array $params): bool
    {
        $orderInfo = UserOrder::where("id", $params['id'])->with(["user", "admin"])->find()->toArray();
        if (!$orderInfo) {
            self::setError("查询订单信息失败");
            return false;
        }

        UserOrder::destroy($params['id']);
        self::userStatic($orderInfo["user_id"], 3);
        return true;
    }


    /**
     * @notes 获取详情
     * @param $params
     * @return array
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public static function detail($params): array
    {
        $orderInfo = UserOrder::where("id", $params['id'])->with(["user", "admin"])->find()->toArray();
        if (!$orderInfo) {
            self::setError("查询订单信息失败");
            return [];
        }

        $orderInfo["user_name"] = $orderInfo["user"]["real_name"];
        $orderInfo["admin_name"] = $orderInfo["admin"]["name"];
        unset($orderInfo["user"]);
        unset($orderInfo["admin"]);

        $goodsList = UserOrderGoods::where("order_id", $params['id'])->select()->toArray();

        return ["order" => $orderInfo, "goods" => $goodsList];
    }


    /**
     * @notes 订单支付
     * @param $params
     * @return array
     * @date 2025/12/22 16:53
     */
    public static function pay(array $params, int $adminId): bool
    {
        $orderInfo = UserOrder::findOrEmpty($params['id'])->toArray();
        if (!$orderInfo) {
            self::setError("查询订单信息失败");
            return false;
        }

        $arrearsMoney = floatval($orderInfo['order_arrears_money']);
        $payMoney = floatval($params['order_money']);
        if ($payMoney <= 0 || $payMoney > $arrearsMoney) {
            self::setError("付款金额不能小于欠款金额");
            return false;
        }

        $updates = [];
        $updates['order_pay_money'] = bcadd(strval($orderInfo['order_pay_money']), strval($payMoney), 2);
        $updates['order_arrears_money'] = bcsub(strval($orderInfo['order_arrears_money']), strval($payMoney), 2);
        if ($updates['order_arrears_money'] == 0) {
            $updates['status'] = 3;
            $updates['pay_status'] = 2;
        } else {
            $updates['status'] = 2;
        }
        Db::startTrans();
        try {
            UserOrder::where('id', $params['id'])->update($updates);
            UserMoney::create([
                'admin_id' => $adminId,
                'user_id' => $orderInfo['user_id'],
                'money' => $payMoney,
                'remarks' => "订单付款",
                'order_ids' => $orderInfo['id'],
            ]);
            Db::commit();
            self::userStatic($orderInfo["user_id"], 2);
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }

        return true;
    }


    public static function userStatic(int $userId, int $type = 1)
    {
        $updates = [];
        if ($type == 1 || $type == 3) {
            $orderMoney = UserOrder::where('user_id', $userId)->sum("order_money");
            $updates["order_money"] = $orderMoney;
        }

        if ($type == 2 || $type == 3) {
            $orderArrearsMoney = UserOrder::where('user_id', $userId)->sum("order_arrears_money");
            $updates["order_arrears_money"] = $orderArrearsMoney;
        }

        User::where('id', $userId)->update($updates);
    }
}