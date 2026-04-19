<?php

namespace app\tenantapi\logic\supplier;


use app\common\model\supplier\UserSupplier;
use app\common\logic\BaseLogic;
use app\common\model\supplier\UserSupplierMoney;
use app\common\model\supplier\UserSupplierOrder;
use think\facade\Db;


/**
 * UserSupplier逻辑
 * Class UserSupplierLogic
 * @package app\tenantapi\logic\supplier
 */
class UserSupplierLogic extends BaseLogic
{


    /**
     * @notes 添加
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public static function add(array $params): bool
    {
        Db::startTrans();
        try {
            UserSupplier::create([
                'name' => $params['name']
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
     * @date 2025/12/22 16:14
     */
    public static function edit(array $params): bool
    {
        Db::startTrans();
        try {
            UserSupplier::where('id', $params['id'])->update([
                'name' => $params['name']
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
     * @date 2025/12/22 16:14
     */
    public static function delete(array $params): bool
    {
        return UserSupplier::destroy($params['id']);
    }


    /**
     * @notes 获取详情
     * @param $params
     * @return array
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public static function detail($params): array
    {
        return UserSupplier::findOrEmpty($params['id'])->toArray();
    }

    /**
     * @notes 订单支付
     * @param $params
     * @return array
     * @date 2025/12/22 16:53
     */
    public static function pay(array $params, int $adminId): bool
    {
        $info = UserSupplier::findOrEmpty($params['id'])->toArray();
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
            $infoTemp = UserSupplierOrder::where("supplier_id", $params['id'])->where("id", ">", $ids)->where(["pay_status" => 1])->field(["id", "order_money", "order_pay_money", "order_arrears_money"])->order("id", "asc")->find();
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
            $idsArr = array_column($updates,"id");
            foreach ($updates as $k => $val) {
                $tempUpdate = $val;
                unset($tempUpdate["id"]);
                UserSupplierOrder::where("id", $val["id"])->update($tempUpdate);
            }

            UserSupplierMoney::create([
                'admin_id' => $adminId,
                'supplier_id' => $params['id'],
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