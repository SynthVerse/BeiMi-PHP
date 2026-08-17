<?php

namespace app\common\service\goods;

use app\common\model\jxc\GoodsSku;

/** 为没有组合维度的新商品建立唯一、稳定的基准 SKU。 */
class GoodsBaseSkuService
{
    private const DIMENSION_DISABLED_MARKER = 8;

    public static function ensure(
        int $tenantId,
        int $goodsId,
        string $goodsName,
        int $baseUnitId,
        string $baseUnitName
    ): GoodsSku {
        if ($tenantId <= 0 || $goodsId <= 0) {
            throw new \InvalidArgumentException('创建基准SKU需要有效的租户和商品');
        }
        $skuCode = 'SKU-' . $goodsId . '-BASE';
        $sku = GoodsSku::where('tenant_id', $tenantId)
            ->where('goods_id', $goodsId)
            ->where('sku_code', $skuCode)
            ->findOrEmpty();
        $data = [
            'sku_name' => $goodsName,
            'base_unit_id' => $baseUnitId,
            'base_unit_name' => $baseUnitName,
            'sort' => 0,
            'is_auto_generated' => 1,
            'update_time' => time(),
        ];
        if (!$sku->isEmpty()) {
            $snapshot = (int)($sku->dimension_disabled_snapshot ?? 0);
            if ($snapshot >= self::DIMENSION_DISABLED_MARKER) {
                $data += [
                    'status' => ($snapshot & 1) === 1 ? 1 : 0,
                    'purchase_status' => ($snapshot & 2) === 2 ? 1 : 0,
                    'sale_status' => ($snapshot & 4) === 4 ? 1 : 0,
                    'dimension_disabled_snapshot' => 0,
                ];
            }
            $sku->save($data);
            return $sku;
        }
        return GoodsSku::create($data + [
            'tenant_id' => $tenantId,
            'goods_id' => $goodsId,
            'sku_code' => $skuCode,
            'quality_status' => '',
            'quality_label' => '',
            'specification_status' => '',
            'specification_label' => '',
            'dimension_disabled_snapshot' => 0,
            'purchase_status' => 1,
            'sale_status' => 1,
            'status' => 1,
            'remark' => '',
            'create_time' => time(),
        ]);
    }
}
