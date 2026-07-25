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
        string $remark = '',
        int $skuId = 0,
        int $batchId = 0
    ): bool {
        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->lock(true)
            ->find();
        if (!$goods) {
            return false;
        }

        $beforeStock = (string)$goods->stock;
        $afterStock = bcadd($beforeStock, $quantity, 2);

        // 更新商品库存
        Goods::where('id', $goodsId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->update([
            'stock' => $afterStock,
            'update_time' => time(),
        ]);

        // 写入库存流水
        StockFlow::create([
            'tenant_id'    => (int)(request()->tenantId ?? 0),
            'warehouse_id' => $warehouseId,
            'goods_id'     => $goodsId,
            'sku_id'       => $skuId,
            'batch_id'     => $batchId,
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
        string $remark = '',
        int $skuId = 0,
        int $batchId = 0
    ): bool {
        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->lock(true)
            ->find();
        if (!$goods) {
            return false;
        }

        $beforeStock = (string)$goods->stock;
        $afterStock = bcsub($beforeStock, $quantity, 2);
        // 允许负库存（初期不阻断，只记录）

        // 更新商品库存
        Goods::where('id', $goodsId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->update([
            'stock' => $afterStock,
            'update_time' => time(),
        ]);

        // 写入库存流水
        StockFlow::create([
            'tenant_id'    => (int)(request()->tenantId ?? 0),
            'warehouse_id' => $warehouseId,
            'goods_id'     => $goodsId,
            'sku_id'       => $skuId,
            'batch_id'     => $batchId,
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
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            return false;
        }

        $flows = StockFlow::where('order_id', $orderId)
            ->where('order_type', $orderType)
            ->where('tenant_id', $tenantId)
            ->lock(true)
            ->select();

        $netByDimension = [];
        foreach ($flows as $flow) {
            $dimension = implode(':', [
                $tenantId,
                $orderId,
                $orderType,
                (int)$flow->warehouse_id,
                (int)$flow->goods_id,
                (int)($flow->sku_id ?? 0),
                (int)($flow->batch_id ?? 0),
            ]);
            if (!isset($netByDimension[$dimension])) {
                $netByDimension[$dimension] = [
                    'tenant_id' => $tenantId,
                    'warehouse_id' => (int)$flow->warehouse_id,
                    'goods_id' => (int)$flow->goods_id,
                    'sku_id' => (int)($flow->sku_id ?? 0),
                    'batch_id' => (int)($flow->batch_id ?? 0),
                    'order_sn' => (string)$flow->order_sn,
                    'net' => '0.00',
                ];
            }
            $quantity = (string)$flow->quantity;
            $netByDimension[$dimension]['net'] = (int)$flow->flow_type === StockFlow::FLOW_IN
                ? bcadd($netByDimension[$dimension]['net'], $quantity, 2)
                : bcsub($netByDimension[$dimension]['net'], $quantity, 2);
        }

        uasort($netByDimension, static function (array $left, array $right): int {
            return [
                $left['goods_id'],
                $left['warehouse_id'],
                $left['sku_id'],
                $left['batch_id'],
            ] <=> [
                $right['goods_id'],
                $right['warehouse_id'],
                $right['sku_id'],
                $right['batch_id'],
            ];
        });

        $goodsIds = array_values(array_unique(array_map(
            static fn(array $item): int => $item['goods_id'],
            $netByDimension
        )));
        sort($goodsIds, SORT_NUMERIC);
        $stocks = [];
        foreach ($goodsIds as $goodsId) {
            $goods = Goods::where('id', $goodsId)
                ->where('tenant_id', $tenantId)
                ->lock(true)
                ->find();
            if (!$goods) {
                return false;
            }
            $stocks[$goodsId] = (string)$goods->stock;
        }

        foreach ($netByDimension as $item) {
            $net = (string)$item['net'];
            if (bccomp($net, '0', 2) === 0) {
                continue;
            }
            $goodsId = (int)$item['goods_id'];
            $quantity = ltrim($net, '-');
            $flowType = bccomp($net, '0', 2) > 0 ? StockFlow::FLOW_OUT : StockFlow::FLOW_IN;
            $beforeStock = $stocks[$goodsId];
            $afterStock = $flowType === StockFlow::FLOW_IN
                ? bcadd($beforeStock, $quantity, 2)
                : bcsub($beforeStock, $quantity, 2);
            $updated = Goods::where('id', $goodsId)
                ->where('tenant_id', $tenantId)
                ->update(['stock' => $afterStock, 'update_time' => time()]);
            if ($updated === false) {
                return false;
            }
            $stocks[$goodsId] = $afterStock;

            StockFlow::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => (int)$item['warehouse_id'],
                'goods_id' => $goodsId,
                'sku_id' => (int)$item['sku_id'],
                'batch_id' => (int)$item['batch_id'],
                'order_id' => $orderId,
                'order_type' => $orderType,
                'order_sn' => (string)$item['order_sn'],
                'flow_type' => $flowType,
                'quantity' => $quantity,
                'before_stock' => $beforeStock,
                'after_stock' => $afterStock,
                'admin_id' => (int)(request()->adminId ?? 0),
                'remark' => '回滚-' . $orderType,
                'create_time' => time(),
            ]);
        }

        return true;
    }
}
