<?php

namespace app\common\service\goods;

use app\common\model\jxc\GoodsSku;

/** 统一处理“明确选择 SKU”与“单 SKU 商品自动选择基准 SKU”。 */
class GoodsSkuSelectionService
{
    public static function forSale(int $tenantId, int $goodsId, int $requestedSkuId = 0): GoodsSku
    {
        return self::resolve($tenantId, $goodsId, $requestedSkuId, 'sale_status', '销售');
    }

    public static function forPurchase(int $tenantId, int $goodsId, int $requestedSkuId = 0): GoodsSku
    {
        return self::resolve($tenantId, $goodsId, $requestedSkuId, 'purchase_status', '采购');
    }

    private static function resolve(
        int $tenantId,
        int $goodsId,
        int $requestedSkuId,
        string $operationStatusField,
        string $operationLabel
    ): GoodsSku {
        if ($tenantId <= 0 || $goodsId <= 0) {
            throw new \InvalidArgumentException('商品或租户上下文无效');
        }
        $query = GoodsSku::where('tenant_id', $tenantId)
            ->where('goods_id', $goodsId)
            ->where('status', 1)
            ->where($operationStatusField, 1)
            ->where('dimension_disabled_snapshot', 0);
        if ($requestedSkuId > 0) {
            $sku = (clone $query)->where('id', $requestedSkuId)->findOrEmpty();
            if ($sku->isEmpty()) {
                throw new \InvalidArgumentException('SKU不属于当前商品或不可' . $operationLabel);
            }
            return $sku;
        }

        $available = $query->order(['sort' => 'asc', 'id' => 'asc'])->limit(2)->select();
        if ($available->count() === 1) {
            return $available[0];
        }
        if ($available->count() === 0) {
            throw new \InvalidArgumentException('商品尚未配置可' . $operationLabel . 'SKU');
        }
        throw new \InvalidArgumentException('该商品必须明确选择一个可' . $operationLabel . 'SKU');
    }
}
