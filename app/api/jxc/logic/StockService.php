<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\Goods;
use app\common\model\jxc\StockFlow;

class StockService
{
    /**
     * 入库操作
     * @param int $warehouseId 仓库ID
     * @param int $goodsId 商品ID
     * @param string $quantity 入库数量（正数）
     * @param int $orderId 关联单据ID
     * @param string $orderType 单据类型
     * @param string $orderSn 单据编号
     * @param string $remark 备注
     * @return bool
     */
    public static function inbound(
        int $warehouseId,
        int $goodsId,
        string $quantity,
        int $orderId,
        string $orderType,
        string $orderSn,
        string $remark = ''
    ): bool {
        $goods = Goods::where('id', $goodsId)->lock(true)->find();
        if (!$goods) {
            return false;
        }

        $beforeStock = (string)$goods->stock;
        $afterStock = bcadd($beforeStock, $quantity, 2);

        // 更新商品库存
        Goods::where('id', $goodsId)->update([
            'stock' => $afterStock,
            'update_time' => time(),
        ]);

        // 写入库存流水
        StockFlow::create([
            'tenant_id'    => (int)(request()->tenantId ?? 0),
            'warehouse_id' => $warehouseId,
            'goods_id'     => $goodsId,
            'order_id'     => $orderId,
            'order_type'   => $orderType,
            'order_sn'     => $orderSn,
            'flow_type'    => StockFlow::FLOW_IN,
            'quantity'     => $quantity,
            'before_stock' => $beforeStock,
            'after_stock'  => $afterStock,
            'admin_id'     => (int)(request()->adminId ?? 0),
            'remark'       => $remark ?: '入库-' . $orderType,
            'create_time'  => time(),
        ]);

        return true;
    }

    /**
     * 出库操作
     * @param int $warehouseId 仓库ID
     * @param int $goodsId 商品ID
     * @param string $quantity 出库数量（正数）
     * @param int $orderId 关联单据ID
     * @param string $orderType 单据类型
     * @param string $orderSn 单据编号
     * @param string $remark 备注
     * @return bool
     */
    public static function outbound(
        int $warehouseId,
        int $goodsId,
        string $quantity,
        int $orderId,
        string $orderType,
        string $orderSn,
        string $remark = ''
    ): bool {
        $goods = Goods::where('id', $goodsId)->lock(true)->find();
        if (!$goods) {
            return false;
        }

        $beforeStock = (string)$goods->stock;
        $afterStock = bcsub($beforeStock, $quantity, 2);
        // 允许负库存（初期不阻断，只记录）

        // 更新商品库存
        Goods::where('id', $goodsId)->update([
            'stock' => $afterStock,
            'update_time' => time(),
        ]);

        // 写入库存流水
        StockFlow::create([
            'tenant_id'    => (int)(request()->tenantId ?? 0),
            'warehouse_id' => $warehouseId,
            'goods_id'     => $goodsId,
            'order_id'     => $orderId,
            'order_type'   => $orderType,
            'order_sn'     => $orderSn,
            'flow_type'    => StockFlow::FLOW_OUT,
            'quantity'     => $quantity,
            'before_stock' => $beforeStock,
            'after_stock'  => $afterStock,
            'admin_id'     => (int)(request()->adminId ?? 0),
            'remark'       => $remark ?: '出库-' . $orderType,
            'create_time'  => time(),
        ]);

        return true;
    }

    /**
     * 按单据回滚库存（根据已记录的流水反向操作）
     * @param int $orderId 单据ID
     * @param string $orderType 单据类型
     * @return bool
     */
    public static function rollback(int $orderId, string $orderType): bool
    {
        $flows = StockFlow::where('order_id', $orderId)
            ->where('order_type', $orderType)
            ->select();

        foreach ($flows as $flow) {
            if ($flow->flow_type == StockFlow::FLOW_OUT) {
                // 出库流水 → 回补库存（入库）
                $goods = Goods::where('id', $flow->goods_id)->lock(true)->find();
                if ($goods) {
                    $beforeStock = (string)$goods->stock;
                    $afterStock = bcadd($beforeStock, (string)$flow->quantity, 2);
                    Goods::where('id', $flow->goods_id)->update([
                        'stock' => $afterStock,
                        'update_time' => time(),
                    ]);

                    StockFlow::create([
                        'tenant_id'    => $flow->tenant_id,
                        'warehouse_id' => $flow->warehouse_id,
                        'goods_id'     => $flow->goods_id,
                        'order_id'     => $orderId,
                        'order_type'   => $orderType,
                        'order_sn'     => $flow->order_sn,
                        'flow_type'    => StockFlow::FLOW_IN,
                        'quantity'     => $flow->quantity,
                        'before_stock' => $beforeStock,
                        'after_stock'  => $afterStock,
                        'admin_id'     => (int)(request()->adminId ?? 0),
                        'remark'       => '回滚-' . $orderType,
                        'create_time'  => time(),
                    ]);
                }
            } elseif ($flow->flow_type == StockFlow::FLOW_IN) {
                // 入库流水 → 扣减库存（出库）
                $goods = Goods::where('id', $flow->goods_id)->lock(true)->find();
                if ($goods) {
                    $beforeStock = (string)$goods->stock;
                    $afterStock = bcsub($beforeStock, (string)$flow->quantity, 2);
                    Goods::where('id', $flow->goods_id)->update([
                        'stock' => $afterStock,
                        'update_time' => time(),
                    ]);

                    StockFlow::create([
                        'tenant_id'    => $flow->tenant_id,
                        'warehouse_id' => $flow->warehouse_id,
                        'goods_id'     => $flow->goods_id,
                        'order_id'     => $orderId,
                        'order_type'   => $orderType,
                        'order_sn'     => $flow->order_sn,
                        'flow_type'    => StockFlow::FLOW_OUT,
                        'quantity'     => $flow->quantity,
                        'before_stock' => $beforeStock,
                        'after_stock'  => $afterStock,
                        'admin_id'     => (int)(request()->adminId ?? 0),
                        'remark'       => '回滚-' . $orderType,
                        'create_time'  => time(),
                    ]);
                }
            }
        }

        return true;
    }
}
