<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSku;
use app\common\model\jxc\StockFlow;
use think\facade\Db;

class StockService
{
    /**
     * 入库操作。
     *
     * 库存余额由 WarehouseSkuBalanceService 维护；Goods.stock 仅由该模块
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
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return Db::transaction(static function () use ($warehouseId, $goodsId, $quantity, $orderId, $orderType, $orderSn, $remark, $skuId, $batchId) {
                    FinanceIntegration::lock();
                    $movement = WarehouseSkuBalanceService::inboundWithinTransaction($warehouseId, $skuId, $quantity);
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

                    NegativeInventoryLogic::autoOffsetWithinTransaction(
                        $warehouseId,
                        $skuId,
                        $movement,
                        $orderId,
                        $orderType,
                        $orderSn
                    );

                    return true;
                });
            } catch (\Throwable $exception) {
                if (self::isLockRetryable($exception) && $attempt < 2) {
                    usleep(20_000 * ($attempt + 1));
                    continue;
                }
                return false;
            }
        }
        return false;
    }

    /** 财务到货入口；调用方持有财务单据事务，不在这里另开事务。 */
    public static function inboundFinancePurchaseWithinTransaction(int $warehouseId, int $goodsId, int $skuId, string $quantity,
        int $documentId, int $arrivalLineId, string $date, ?string $amount, array $basis): int
    {
        FinanceIntegration::lock();
        $movement = WarehouseSkuBalanceService::inboundWithinTransaction($warehouseId, $skuId, $quantity);
        if ($movement === false) { throw new \DomainException('采购到货入库失败，请核对仓库与商品规格'); }
        $sn = 'FIN-ARR-' . $documentId;
        $flow = self::writeFlow(['warehouse_id' => $warehouseId, 'goods_id' => $goodsId, 'sku_id' => $skuId, 'batch_id' => 0,
            'order_id' => $documentId, 'order_type' => 'finance_purchase_arrival', 'order_sn' => $sn, 'flow_type' => StockFlow::FLOW_IN,
            'quantity' => $quantity, 'remark' => '采购实际到货'], $movement,
            ['origin' => 'purchase-arrival:' . $arrivalLineId, 'business_date' => $date, 'document_id' => $documentId, 'amount' => $amount, 'cost_basis' => $basis]);
        NegativeInventoryLogic::autoOffsetWithinTransaction($warehouseId, $skuId, $movement, $documentId, 'finance_purchase_arrival', $sn);
        return $flow;
    }

    /** 库内实物减少只出库一次；原因未查明时成本仍在待核实去向。 */
    public static function outboundFinanceInventoryLossWithinTransaction(int $warehouse, int $goods, int $sku, string $quantity, int $document, string $date): int
    {
        FinanceIntegration::lock(); $movement = WarehouseSkuBalanceService::outboundWithinTransaction($warehouse, $sku, $quantity);
        if ($movement === false) { throw new \DomainException('可用库存不足，请先核对仓库库存和预留，不能重复登记实物减少'); }
        return self::writeFlow(['warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'batch_id' => 0,
            'order_id' => $document, 'order_type' => 'finance_inventory_loss', 'order_sn' => 'FIN-LOSS-' . $document,
            'flow_type' => StockFlow::FLOW_OUT, 'quantity' => $quantity, 'remark' => '库内实物减少待核实'], $movement,
            ['business_date' => $date, 'document_id' => $document]);
    }

    /** 财务采购实际退离；库存、实物来源与移动平均成本在调用方事务一起写入。 */
    public static function outboundFinancePurchaseReturnWithinTransaction(int $warehouseId, int $goodsId, int $skuId, string $quantity,
        int $documentId, int $returnLineId, string $date): array
    {
        FinanceIntegration::lock();
        $movement = WarehouseSkuBalanceService::purchaseReturnWithinTransaction($warehouseId, $skuId, $quantity);
        if ($movement === false) { throw new \DomainException('采购实际退货出库失败'); }
        self::writeFlow(['warehouse_id' => $warehouseId, 'goods_id' => $goodsId, 'sku_id' => $skuId, 'batch_id' => 0,
            'order_id' => $documentId, 'order_type' => 'finance_purchase_return', 'order_sn' => 'FIN-RET-' . $documentId,
            'flow_type' => StockFlow::FLOW_OUT, 'quantity' => $quantity, 'remark' => '采购实际退离-明细' . $returnLineId], $movement,
            ['business_date' => $date, 'document_id' => $documentId, 'return_line_id' => $returnLineId]);
        $movement['negative_attribution_id'] = NegativeInventoryLogic::purchaseReturnWithinTransaction($warehouseId, $goodsId, $skuId, $movement, $documentId, $returnLineId, $date);
        return $movement;
    }

    /** 供应商退货争议的实际返回入库，恢复原退离成本而非新仓当日均价。 */
    public static function inboundFinancePurchaseReturnWithinTransaction(int $warehouse, int $goods, int $sku, string $quantity, int $document, array $returned, string $date): int
    {
        FinanceIntegration::lock(); $movement = WarehouseSkuBalanceService::inboundWithinTransaction($warehouse, $sku, $quantity);
        if ($movement === false) { throw new \DomainException('采购退货实际返回入库失败'); }
        $flow = self::writeFlow(['warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'batch_id' => 0, 'order_id' => $document,
            'order_type' => 'finance_purchase_return_back', 'order_sn' => 'FIN-BACK-' . $document, 'flow_type' => StockFlow::FLOW_IN,
            'quantity' => $quantity, 'remark' => '采购退货争议实际返回-明细' . $returned['id']], $movement,
            ['business_date' => $date, 'document_id' => $document, 'return_line_id' => (int)$returned['id'], 'original_warehouse_id' => (int)$returned['warehouse_id']]);
        $attribution = 0;
        if ($warehouse === (int)$returned['warehouse_id']) {
            $original = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('id', $returned['document_id'])->value('confirmed_result');
            foreach (FinanceValue::decode($original)['lines'] ?? [] as $line) {
                if ((int)$line['return_line_id'] === (int)$returned['id']) { $attribution = (int)($line['negative_attribution_id'] ?? 0); break; }
            }
        }
        if ($attribution > 0) { NegativeInventoryLogic::autoOffsetPurchaseReturnWithinTransaction($warehouse, $sku, $movement, $document, $attribution); }
        else { NegativeInventoryLogic::autoOffsetWithinTransaction($warehouse, $sku, $movement, $document, 'finance_purchase_return_back', 'FIN-BACK-' . $document); }
        return $flow;
    }

    /** 销售单实际交付重量更正入库；调用方持有销售结算事务。 */
    public static function inboundDeliveryCorrectionWithinTransaction(
        int $warehouseId,
        int $goodsId,
        int $skuId,
        string $quantity,
        int $salesOrderId,
        string $salesOrderSn,
        int $sourceLineId,
        int $settlementActionId
    ): array|false {
        FinanceIntegration::lock();
        $movement = WarehouseSkuBalanceService::inboundWithinTransaction($warehouseId, $skuId, $quantity);
        if ($movement === false) {
            return false;
        }
        self::writeFlow([
            'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'batch_id' => 0,
            'order_id' => $salesOrderId,
            'order_type' => 'sales_delivery_correction',
            'order_sn' => $salesOrderSn,
            'flow_type' => StockFlow::FLOW_IN,
            'quantity' => $quantity,
            'remark' => '销售单实际交付重量录入更正-减少实重',
        ], $movement);
        NegativeInventoryLogic::autoOffsetDeliveryCorrectionWithinTransaction(
            $warehouseId,
            $skuId,
            $movement,
            $salesOrderId,
            $salesOrderSn,
            $sourceLineId,
            $settlementActionId
        );
        return $movement;
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
                FinanceIntegration::lock();
                $movement = WarehouseSkuBalanceService::outbound($warehouseId, $skuId, $quantity);
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
     * Consume stock that was already reserved by an upstream workflow.
     *
     * The caller owns the transaction. This path must not use the normal
     * available-stock outbound primitive, otherwise the same quantity would be
     * checked and deducted twice.
     */
    public static function outboundReservedWithinTransaction(
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
            FinanceIntegration::lock();
            $movement = WarehouseSkuBalanceService::consumeReservedWithinTransaction(
                $warehouseId,
                $skuId,
                $quantity
            );
            if ($movement === false) {
                throw new \RuntimeException('Unable to issue reserved warehouse stock.');
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
                'remark' => $remark ?: '预留出库-' . $orderType,
            ], $movement);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 真实交付专用出库。调用方持有事务，并已经锁定报货单、明细、预留和销售单身份。
     * 返回本次新增负库存及预留消费/释放数量，普通库存入口不能调用该原语。
     */
    public static function outboundDeliveryWithinTransaction(
        int $warehouseId,
        int $goodsId,
        int $skuId,
        string $actualQuantity,
        string $reservationQuantity,
        int $salesOrderId,
        string $salesOrderSn,
        int $deliveryEventId
    ): array|false {
        FinanceIntegration::lock();
        $movement = WarehouseSkuBalanceService::deliverAttributedWithinTransaction(
            $warehouseId,
            $skuId,
            $actualQuantity,
            $reservationQuantity
        );
        if ($movement === false) {
            return false;
        }
        self::writeFlow([
            'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'batch_id' => 0,
            'order_id' => $salesOrderId,
            'order_type' => 'sales_delivery',
            'order_sn' => $salesOrderSn,
            'flow_type' => StockFlow::FLOW_OUT,
            'quantity' => $actualQuantity,
            'remark' => '实际交付出库-事件' . $deliveryEventId,
        ], $movement, ['delivery_event_id' => $deliveryEventId]);
        NegativeInventoryLogic::autoOffsetDeliveryReleaseWithinTransaction(
            $warehouseId,
            $skuId,
            $movement,
            $salesOrderId,
            $salesOrderSn,
            $deliveryEventId
        );
        return $movement;
    }

    /** 运输损耗专用出库；库存减少但不得计入客户销售数量。 */
    public static function outboundTransportLossWithinTransaction(
        int $warehouseId,
        int $goodsId,
        int $skuId,
        string $lossQuantity,
        string $reservationQuantity,
        int $deliveryEventId
    ): array|false {
        FinanceIntegration::lock();
        $movement = WarehouseSkuBalanceService::deliverAttributedWithinTransaction(
            $warehouseId,
            $skuId,
            $lossQuantity,
            $reservationQuantity
        );
        if ($movement === false) {
            return false;
        }
        self::writeFlow([
            'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'batch_id' => 0,
            'order_id' => $deliveryEventId,
            'order_type' => 'delivery_transport_loss',
            'order_sn' => 'DELIVERY-LOSS-' . $deliveryEventId,
            'flow_type' => StockFlow::FLOW_OUT,
            'quantity' => $lossQuantity,
            'remark' => '运输损耗出库-交付事件' . $deliveryEventId,
        ], $movement, ['delivery_event_id' => $deliveryEventId]);
        NegativeInventoryLogic::autoOffsetDeliveryReleaseWithinTransaction(
            $warehouseId,
            $skuId,
            $movement,
            0,
            'DELIVERY-LOSS-' . $deliveryEventId,
            $deliveryEventId
        );
        return $movement;
    }

    /** 部分交付终结余量时释放剩余预留，并审计化核减被释放量补平的负库存来源。 */
    public static function releaseDeliveryReservationWithinTransaction(
        int $warehouseId,
        int $skuId,
        string $quantity,
        int $salesOrderId,
        string $salesOrderSn,
        int $deliveryEventId
    ): array|false {
        $movement = WarehouseSkuBalanceService::releaseWithinTransaction($warehouseId, $skuId, $quantity);
        if ($movement === false) {
            return false;
        }
        NegativeInventoryLogic::autoOffsetDeliveryReleaseWithinTransaction(
            $warehouseId,
            $skuId,
            $movement,
            $salesOrderId,
            $salesOrderSn,
            $deliveryEventId
        );
        return $movement;
    }

    /** 负库存遗漏入库或核销调整；调用方持有事务并追加审计动作。 */
    public static function adjustNegativeWithinTransaction(
        int $warehouseId,
        int $goodsId,
        int $skuId,
        string $quantity,
        int $attributionId,
        string $actionType,
        string $reason,
        ?string $confirmedAmount = null
    ): array|false {
        FinanceIntegration::lock();
        $movement = WarehouseSkuBalanceService::inboundWithinTransaction($warehouseId, $skuId, $quantity);
        if ($movement === false) {
            return false;
        }
        self::writeFlow([
            'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'batch_id' => 0,
            'order_id' => $attributionId,
            'order_type' => 'negative_inventory_resolution',
            'order_sn' => 'NEG-' . $attributionId,
            'flow_type' => StockFlow::FLOW_IN,
            'quantity' => $quantity,
            'remark' => $actionType . '-' . $reason,
        ], $movement, ['amount' => $confirmedAmount, 'cost_basis' => $actionType . '：' . $reason]);
        return $movement;
    }

    public static function transfer(
        int $fromWarehouseId,
        int $toWarehouseId,
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
            return Db::transaction(static function () use ($fromWarehouseId, $toWarehouseId, $goodsId, $quantity, $orderId, $orderType, $orderSn, $remark, $skuId, $batchId) {
                FinanceIntegration::lock();
                $movements = WarehouseSkuBalanceService::transferWithinTransaction($fromWarehouseId, $toWarehouseId, $skuId, $quantity);
                if ($movements === false) {
                    throw new \RuntimeException('Unable to transfer warehouse SKU stock.');
                }
                $common = [
                    'create_time' => time(),
                    'goods_id' => $goodsId,
                    'sku_id' => $skuId,
                    'batch_id' => $batchId,
                    'order_id' => $orderId,
                    'order_type' => $orderType,
                    'order_sn' => $orderSn,
                    'quantity' => $quantity,
                ];
                $outboundFlow = self::writeFlow($common + [
                    'warehouse_id' => $fromWarehouseId,
                    'flow_type' => StockFlow::FLOW_OUT,
                    'remark' => $remark ?: '调拨出库-' . $orderType,
                ], $movements['outbound'], ['paired_transfer' => true]);
                $inboundFlow = self::writeFlow($common + [
                    'warehouse_id' => $toWarehouseId,
                    'flow_type' => StockFlow::FLOW_IN,
                    'remark' => $remark ?: '调拨入库-' . $orderType,
                ], $movements['inbound'], ['paired_transfer' => true]);
                FinanceStockCosts::transferWithinTransaction($outboundFlow, $inboundFlow, $fromWarehouseId, $toWarehouseId, $skuId, $quantity);
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
                if (FinanceIntegration::lock()) { throw new \DomainException('财务启用后不能按旧单回滚库存，请登记关联实物更正或退货'); }
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
                    return [$left['sku_id'], $left['goods_id'], $left['warehouse_id'], $left['batch_id']]
                        <=> [$right['sku_id'], $right['goods_id'], $right['warehouse_id'], $right['batch_id']];
                });

                // 新余额原语的固定加锁顺序是 SKU -> 商品 -> 仓库余额；批量回滚先按同一顺序锁住主体。
                $skuIds = array_values(array_unique(array_map(
                    static fn(array $item): int => (int)$item['sku_id'],
                    $netByDimension
                )));
                sort($skuIds, SORT_NUMERIC);
                foreach ($skuIds as $skuId) {
                    $sku = GoodsSku::where('id', $skuId)
                        ->where('tenant_id', $tenantId)
                        ->lock(true)
                        ->find();
                    if (!$sku) {
                        throw new \RuntimeException('Unable to find SKU while rolling back warehouse stock.');
                    }
                }

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
                        ? WarehouseSkuBalanceService::outbound((int)$item['warehouse_id'], (int)$item['sku_id'], $quantity)
                        : WarehouseSkuBalanceService::inbound((int)$item['warehouse_id'], (int)$item['sku_id'], $quantity);
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

    private static function writeFlow(array $attributes, array $movement, array $costContext = []): int
    {
        if ((int)($attributes['goods_id'] ?? 0) !== (int)($movement['goods_id'] ?? 0)
            || (int)($attributes['sku_id'] ?? 0) !== (int)($movement['sku_id'] ?? 0)
        ) {
            throw new \RuntimeException('Stock flow goods and SKU do not match the authoritative balance.');
        }
        $row = array_merge([
            'tenant_id' => (int)(request()->tenantId ?? 0),
            'before_stock' => $movement['before_on_hand_qty'],
            'after_stock' => $movement['after_on_hand_qty'],
            'admin_id' => (int)(request()->adminId ?? 0),
            'create_time' => time(),
        ], $attributes);
        $id = (int)Db::name('stock_flow')->insertGetId($row);
        if ($id <= 0) {
            throw new \RuntimeException('Unable to write stock flow.');
        }
        if (empty($costContext['paired_transfer'])) { FinanceStockCosts::flowWithinTransaction($id, $row, $costContext); }
        return $id;
    }

    private static function isLockRetryable(\Throwable $exception): bool
    {
        return in_array((int)$exception->getCode(), [1205, 1213], true)
            || str_contains($exception->getMessage(), '1205')
            || str_contains($exception->getMessage(), '1213');
    }
}
