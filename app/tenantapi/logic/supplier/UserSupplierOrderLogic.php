<?php

namespace app\tenantapi\logic\supplier;


use app\common\model\goods\TenantGoods;
use app\common\model\supplier\UserSupplier;
use app\common\model\supplier\UserSupplierMoney;
use app\common\model\supplier\UserSupplierOrder;
use app\common\logic\BaseLogic;
use app\common\model\supplier\UserSupplierOrderGoods;
use think\facade\Db;


/**
 * UserSupplierOrder逻辑
 * Class UserSupplierOrderLogic
 * @package app\tenantapi\logic\supplier
 */
class UserSupplierOrderLogic extends BaseLogic
{


    /**
     * @notes 添加
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public static function add(array $params): bool
    {
        $orderSn = date('YmdHis') . rand(100000, 999999);

        //查询供应商信息
        $supplierInfo = UserSupplier::where("id", $params['supplier_id'])->find();
        if ($supplierInfo == null) {
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
            $temp['goods_id'] = $value['id'];
            $temp['name'] = $value['name'];
            $temp['number'] = $goodsWeight[$value['id']];
            $temp['price'] = $goodsPrice[$value['id']];
            $temp['amount'] = bcmul(strval($temp['number']), strval($temp['price']), 2);
            $temp['units'] = $value['units'];
            $orderMoney = bcadd($temp['amount'], strval($orderMoney), 2);
            $goodsNumber = bcadd($temp['number'], strval($goodsNumber), 2);
            array_push($orderGoodsIndex, $temp);
        }

        Db::startTrans();
        try {
            $umodel = UserSupplierOrder::create([
                'order_sn' => $orderSn,
                'supplier_id' => $params['supplier_id'],
                'supplier_name' => $supplierInfo['name'],
                'order_number' => count($params["goods"]),
                'order_money' => $orderMoney,
                'order_pay_money' => 0,
                'order_arrears_money' => $orderMoney,
                'goods_number' => $goodsNumber,
                'status' => 1,
                'pay_status' => 1,
                'datetimesingle' => date("Y-m-d H:i:s"),
                'remarks' => "",
            ]);
            $orderId = $umodel->getLastInsID();
            foreach ($orderGoodsIndex as $k => $val) {
                $orderGoodsIndex[$k]["order_id"] = $orderId;
            }
            (new UserSupplierOrderGoods())->saveAll($orderGoodsIndex);
            Db::commit();
            self::supplierStatic($supplierInfo["id"], 3);
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
     * @date 2025/12/22 16:53
     */
    public static function edit(array $params): bool
    {
        Db::startTrans();
        try {
            UserSupplierOrder::where('id', $params['id'])->update([
                'order_sn' => $params['order_sn'],
                'tenant_id' => $params['tenant_id'],
                'supplier_id' => $params['supplier_id'],
                'customer' => $params['customer'],
                'order_number' => $params['order_number'],
                'order_money' => $params['order_money'],
                'order_pay_money' => $params['order_pay_money'],
                'order_arrears_money' => $params['order_arrears_money'],
                'goods_number' => $params['goods_number'],
                'status' => $params['status'],
                'pay_status' => $params['pay_status'],
                'datetimesingle' => $params['datetimesingle'],
                'remarks' => $params['remarks'],
                'createtime' => $params['createtime'],
                'updatetime' => $params['updatetime']
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
     * @date 2025/12/22 16:53
     */
    public static function delete(array $params): bool
    {
        $orderInfo = UserSupplierOrder::findOrEmpty($params['id'])->toArray();
        if (!$orderInfo) {
            self::setError("查询订单信息失败");
            return false;
        }
        UserSupplierOrderGoods::where("order_id", "=", $params['id'])->delete();
        UserSupplierOrder::destroy($params['id']);
        self::supplierStatic($orderInfo["supplier_id"], 3);
        return true;
    }


    /**
     * @notes 获取详情
     * @param $params
     * @return array
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public static function detail($params): array
    {
        $orderInfo = UserSupplierOrder::findOrEmpty($params['id'])->toArray();
        if (!$orderInfo) {
            self::setError("查询订单信息失败");
            return [];
        }

        $goodsList = UserSupplierOrderGoods::where("order_id", $params['id'])->select()->toArray();

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
        $orderInfo = UserSupplierOrder::findOrEmpty($params['id'])->toArray();
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
            UserSupplierOrder::where('id', $params['id'])->update($updates);
            UserSupplierMoney::create([
                'admin_id' => $adminId,
                'supplier_id' => $orderInfo['supplier_id'],
                'money' => $payMoney,
                'remarks' => "订单付款",
                'order_ids' => $orderInfo['id'],
            ]);
            Db::commit();
            self::supplierStatic($orderInfo["supplier_id"], 2);
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }

        return true;
    }


    //统计信息
    public static function supplierStatic(int $supplierId, int $type = 1)
    {
        $updates = [];
        if ($type == 1 || $type == 3) {
            $orderMoney = UserSupplierOrder::where('supplier_id', $supplierId)->sum("order_money");
            $updates["order_money"] = $orderMoney;
        }

        if ($type == 2 || $type == 3) {
            $orderArrearsMoney = UserSupplierOrder::where('supplier_id', $supplierId)->sum("order_arrears_money");
            $updates["order_arrears_money"] = $orderArrearsMoney;
        }

        UserSupplier::where('id', $supplierId)->update($updates);
    }

}