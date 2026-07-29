<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\Goods;
use app\common\model\jxc\StockFlow;
use think\facade\Db;

class StockService
{
    /**
     * 入库操作。
     *
     * 库存余额由 WarehouseGoodsBalanceService 维护；Goods.stock 仅由该服务
     * 汇总更新，库存流水中的前后值记录指定仓库的现存量。
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
        try {
            return Db::transaction(static function () use ($warehouseId, $goodsId, $quantity, $orderId, $orderType, $orderSn, $remark, $skuId, $batchId) {
                $movement = WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, $quantity);
                if ($movement === false) {
                    throw new \RuntimeException('Unable to receive warehouse stock.');
                }

                self::writeFlow([
                    'warehouse_id' => $warehouseId,
                    'goods_id' => $goodsId,
                    'sku_id' => $skuId,
                    'batch_id' => $batchId,
                    'order_id' => $orderId,
                    'order_type' => $orderType,
                    'order_sn' => $orderSn,
                    'flow_type' => StockFlow::FLOW_IN,
                    'quantity' => $quantity,
                    'remark' => $remark ?: '入库-' . $orderType,
                ], $movement);

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 出库操作。仓库可用量不足时拒绝，不能再写出负库存。
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
        try {
            return Db::transaction(static function () use ($warehouseId, $goodsId, $quantity, $orderId, $orderType, $orderSn, $remark, $skuId, $batchId) {
                $movement = WarehouseGoodsBalanceService::outbound($warehouseId, $goodsId, $quantity);
                if ($movement === false) {
                    throw new \RuntimeException('Unable to issue warehouse stock.');
                }

                self::writeFlow([
                    'warehouse_id' => $warehouseId,
                    'goods_id' => $goodsId,
                    'sku_id' => $skuId,
                    'batch_id' => $batchId,
                    'order_id' => $orderId,
                    'order_type' => $orderType,
                    'order_sn' => $orderSn,
                    'flow_type' => StockFlow::FLOW_OUT,
                    'quantity' => $quantity,
                    'remark' => $remark ?: '出库-' . $orderType,
                ], $movement);

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 按单据回滚库存。所有反向操作仍走仓库余额原语。
     */
    public static function rollback(int $orderId, string $orderType): bool
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            return false;
        }

        try {
            return Db::transaction(static function () use ($tenantId, $orderId, $orderType) {
                $flows = StockFlow::where('order_id', $orderId)
                    ->where('order_type', $orderType)
                    ->where('tenant_id', $tenantId)
                    ->lock(true)
                    ->select();

                $netByDimension = [];
                foreach ($flows as $flow) {
                    $dimension = implode(':', [
                        $tenantId,
                        (int)$flow->warehouse_id,
                        (int)$flow->goods_id,
                        (int)($flow->sku_id ?? 0),
                        (int)($flow->batch_id ?? 0),
                    ]);
                    if (!isset($netByDimension[$dimension])) {
                        $netByDimension[$dimension] = [
                            'warehouse_id' => (int)$flow->warehouse_id,
                            'goods_id' => (int)$flow->goods_id,
                            'sku_id' => (int)($flow->sku_id ?? 0),
                            'batch_id' => (int)($flow->batch_id ?? 0),
                            'order_sn' => (string)$flow->order_sn,
                            'net' => '0.0000',
                        ];
                    }
                    $quantity = (string)$flow->quantity;
                    $netByDimension[$dimension]['net'] = (int)$flow->flow_type === StockFlow::FLOW_IN
                        ? bcadd($netByDimension[$dimension]['net'], $quantity, 4)
                        : bcsub($netByDimension[$dimension]['net'], $quantity, 4);
                }

                uasort($netByDimension, static function (array $left, array $right): int {
                    return [$left['goods_id'], $left['warehouse_id'], $left['sku_id'], $left['batch_id']]
                        <=> [$right['goods_id'], $right['warehouse_id'], $right['sku_id'], $right['batch_id']];
                });

                // 先按商品 ID 固定加锁顺序，再由余额原语继续锁定仓库余额，避免多商品回滚互相等待。
                $goodsIds = array_values(array_unique(array_map(
                    static fn(array $item): int => (int)$item['goods_id'],
                    $netByDimension
                )));
                sort($goodsIds, SORT_NUMERIC);
                foreach ($goodsIds as $goodsId) {
                    $goods = Goods::where('id', $goodsId)
                        ->where('tenant_id', $tenantId)
                        ->lock(true)
                        ->find();
                    if (!$goods) {
                        throw new \RuntimeException('Unable to find goods while rolling back warehouse stock.');
                    }
                }

                foreach ($netByDimension as $item) {
                    $net = (string)$item['net'];
                    if (bccomp($net, '0.0000', 4) === 0) {
                        continue;
                    }

                    $quantity = ltrim($net, '-');
                    $flowType = bccomp($net, '0.0000', 4) > 0 ? StockFlow::FLOW_OUT : StockFlow::FLOW_IN;
                    $movement = $flowType === StockFlow::FLOW_OUT
                        ? WarehouseGoodsBalanceService::outbound((int)$item['warehouse_id'], (int)$item['goods_id'], $quantity)
                        : WarehouseGoodsBalanceService::inbound((int)$item['warehouse_id'], (int)$item['goods_id'], $quantity);
                    if ($movement === false) {
                        throw new \RuntimeException('Unable to roll back warehouse stock.');
                    }

                    self::writeFlow([
                        'warehouse_id' => (int)$item['warehouse_id'],
                        'goods_id' => (int)$item['goods_id'],
                        'sku_id' => (int)$item['sku_id'],
                        'batch_id' => (int)$item['batch_id'],
                        'order_id' => $orderId,
                        'order_type' => $orderType,
                        'order_sn' => (string)$item['order_sn'],
                        'flow_type' => $flowType,
                        'quantity' => $quantity,
                        'remark' => '回滚-' . $orderType,
                    ], $movement);
                }

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    private static function writeFlow(array $attributes, array $movement): void
    {
        $flow = StockFlow::create(array_merge([
            'tenant_id' => (int)(request()->tenantId ?? 0),
            'before_stock' => $movement['before_on_hand_qty'],
            'after_stock' => $movement['after_on_hand_qty'],
            'admin_id' => (int)(request()->adminId ?? 0),
            'create_time' => time(),
        ], $attributes));
        if (!$flow) {
            throw new \RuntimeException('Unable to write stock flow.');
        }
    }
}
