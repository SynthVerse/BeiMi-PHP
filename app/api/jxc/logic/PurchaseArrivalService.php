<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\GoodsBatch;
use app\common\model\jxc\GoodsLossRecord;
use app\common\model\jxc\OrderGoods;
use app\common\model\jxc\PurchaseArrival;
use app\common\model\jxc\PurchaseArrivalDetail;

class PurchaseArrivalService
{
    public static function rebuildForSupplyOrder(array $order, array $goodsRows): array
    {
        self::deleteBySupplyOrder((int)$order['id']);

        $arrival = PurchaseArrival::create([
            'tenant_id' => self::tenantId(),
            'arrival_sn' => self::generateArrivalSn(),
            'supply_order_id' => (int)$order['id'],
            'supply_order_sn' => (string)$order['order_sn'],
            'supplier_id' => (int)$order['supplier_id'],
            'supplier_name' => (string)$order['supplier_name'],
            'warehouse_id' => (int)$order['warehouse_id'],
            'arrival_time' => (int)($order['datetimesingle'] ?? time()),
            'status' => 1,
            'remark' => (string)($order['remarks'] ?? ''),
            'admin_id' => (int)(request()->adminId ?? 0),
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $result = [];
        foreach ($goodsRows as $row) {
            $batch = GoodsBatch::create([
                'tenant_id' => self::tenantId(),
                'batch_sn' => self::generateBatchSn((int)$row['goods_id'], (int)$row['sku_id']),
                'goods_id' => (int)$row['goods_id'],
                'sku_id' => (int)$row['sku_id'],
                'supplier_id' => (int)$order['supplier_id'],
                'warehouse_id' => (int)$order['warehouse_id'],
                'supply_order_id' => (int)$order['id'],
                'order_goods_id' => (int)$row['id'],
                'base_unit_id' => (int)($row['base_unit_id'] ?? 0),
                'base_unit_name' => (string)($row['base_unit_name'] ?? ''),
                'expected_base_qty' => (string)($row['expected_base_qty'] ?? '0.0000'),
                'actual_base_qty' => (string)($row['actual_base_qty'] ?? $row['number'] ?? '0.0000'),
                'loss_base_qty' => (string)($row['loss_base_qty'] ?? '0.0000'),
                'conversion_snapshot' => json_encode([
                    'from_unit_id' => (int)($row['order_unit_id'] ?? 0),
                    'from_unit_name' => (string)($row['order_unit_name'] ?? ''),
                    'to_unit_id' => (int)($row['base_unit_id'] ?? 0),
                    'to_unit_name' => (string)($row['base_unit_name'] ?? ''),
                    'ratio' => (string)($row['conversion_rate'] ?? '1.000000'),
                    'source_type' => (string)($row['conversion_source_type'] ?? ''),
                    'effective_date' => $row['conversion_effective_date'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
                'arrival_time' => (int)($order['datetimesingle'] ?? time()),
                'status' => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);

            PurchaseArrivalDetail::create([
                'tenant_id' => self::tenantId(),
                'arrival_id' => (int)$arrival->id,
                'supply_order_id' => (int)$order['id'],
                'order_goods_id' => (int)$row['id'],
                'goods_id' => (int)$row['goods_id'],
                'sku_id' => (int)$row['sku_id'],
                'supplier_id' => (int)$order['supplier_id'],
                'batch_id' => (int)$batch->id,
                'order_qty' => (string)($row['order_qty'] ?? $row['number']),
                'order_unit_name' => (string)($row['order_unit_name'] ?? $row['units']),
                'expected_base_qty' => (string)($row['expected_base_qty'] ?? '0.0000'),
                'actual_base_qty' => (string)($row['actual_base_qty'] ?? $row['number']),
                'loss_base_qty' => (string)($row['loss_base_qty'] ?? '0.0000'),
                'loss_rate' => (string)($row['loss_rate'] ?? '0.000000'),
                'conversion_rate' => (string)($row['conversion_rate'] ?? '1.000000'),
                'conversion_source_type' => (string)($row['conversion_source_type'] ?? ''),
                'create_time' => time(),
            ]);

            if (bccomp((string)($row['loss_base_qty'] ?? '0.0000'), '0', 4) > 0) {
                GoodsLossRecord::create([
                    'tenant_id' => self::tenantId(),
                    'loss_type' => 'arrival_shortage',
                    'goods_id' => (int)$row['goods_id'],
                    'sku_id' => (int)$row['sku_id'],
                    'supplier_id' => (int)$order['supplier_id'],
                    'batch_id' => (int)$batch->id,
                    'supply_order_id' => (int)$order['id'],
                    'order_goods_id' => (int)$row['id'],
                    'expected_base_qty' => (string)($row['expected_base_qty'] ?? '0.0000'),
                    'actual_base_qty' => (string)($row['actual_base_qty'] ?? $row['number']),
                    'loss_base_qty' => (string)($row['loss_base_qty'] ?? '0.0000'),
                    'loss_rate' => (string)($row['loss_rate'] ?? '0.000000'),
                    'reason' => '采购到货实际称重短少',
                    'record_time' => (int)($order['datetimesingle'] ?? time()),
                    'admin_id' => (int)(request()->adminId ?? 0),
                    'create_time' => time(),
                ]);
            }

            OrderGoods::where('id', (int)$row['id'])
                ->where('tenant_id', self::tenantId())
                ->update(['batch_id' => (int)$batch->id, 'update_time' => time()]);

            $row['batch_id'] = (int)$batch->id;
            $result[] = $row;
        }

        return $result;
    }

    public static function deleteBySupplyOrder(int $orderId): void
    {
        $batchIds = GoodsBatch::where('tenant_id', self::tenantId())
            ->where('supply_order_id', $orderId)
            ->column('id');
        if ($batchIds !== []) {
            GoodsLossRecord::where('tenant_id', self::tenantId())
                ->whereIn('batch_id', $batchIds)
                ->delete();
        }
        GoodsLossRecord::where('tenant_id', self::tenantId())
            ->where('supply_order_id', $orderId)
            ->delete();
        PurchaseArrivalDetail::where('tenant_id', self::tenantId())
            ->where('supply_order_id', $orderId)
            ->delete();
        PurchaseArrival::where('tenant_id', self::tenantId())
            ->where('supply_order_id', $orderId)
            ->delete();
        GoodsBatch::where('tenant_id', self::tenantId())
            ->where('supply_order_id', $orderId)
            ->delete();
    }

    protected static function generateArrivalSn(): string
    {
        return 'ARR' . date('YmdHis') . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    protected static function generateBatchSn(int $goodsId, int $skuId): string
    {
        return 'B' . date('YmdHis') . '-' . $goodsId . '-' . $skuId . '-' . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    protected static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
