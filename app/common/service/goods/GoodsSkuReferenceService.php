<?php

namespace app\common\service\goods;

use think\facade\Db;

class GoodsSkuReferenceService
{
    public static function skuHasBusinessReferences(int $tenantId, int $skuId): bool
    {
        foreach ([
            'order_goods',
            'goods_supplier_price_history',
            'goods_unit_conversion_rule',
            'stock_flow',
            'purchase_arrival_detail',
            'goods_batch',
            'goods_loss_record',
            'purchase_return_order_lists',
            'customer_report_item',
        ] as $table) {
            try {
                if (Db::name($table)->where('tenant_id', $tenantId)->where('sku_id', $skuId)->count() > 0) {
                    return true;
                }
            } catch (\Throwable $error) {
                if (!self::isMissingTableException($error)) {
                    throw $error;
                }
            }
        }
        return false;
    }

    /** @param array<int,int|string> $relationIds */
    public static function supplierRelationsHaveBusinessReferences(int $tenantId, array $relationIds): bool
    {
        if ($relationIds === []) {
            return false;
        }
        foreach ([
            ['table' => 'order_goods', 'column' => 'supplier_relation_id'],
            ['table' => 'goods_supplier_price_history', 'column' => 'goods_supplier_id'],
        ] as $reference) {
            try {
                if (Db::name($reference['table'])
                    ->where('tenant_id', $tenantId)
                    ->whereIn($reference['column'], $relationIds)
                    ->count() > 0
                ) {
                    return true;
                }
            } catch (\Throwable $error) {
                if (!self::isMissingTableException($error)) {
                    throw $error;
                }
            }
        }
        return false;
    }

    private static function isMissingTableException(\Throwable $error): bool
    {
        $code = strtoupper((string)$error->getCode());
        $message = strtolower($error->getMessage());
        return $code === '42S02'
            || str_contains($message, 'base table or view not found')
            || (str_contains($message, "doesn't exist") && str_contains($message, 'table'));
    }
}
