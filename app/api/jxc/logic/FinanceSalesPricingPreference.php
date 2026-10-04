<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 从仍有效的正式事实读取偏好；草稿、预览和重试不会改变排序。 */
final class FinanceSalesPricingPreference
{
    public static function latest(int $customer, int $goods, int $sku): ?array
    {
        $tenant = FinanceAccess::tenant();
        $row = Db::name('finance_sales_pricing_fact')->alias('p')
            ->join('finance_document d', "d.tenant_id=p.tenant_id AND d.id=p.document_id AND d.status='confirmed'")
            ->leftJoin('finance_correction c', 'c.tenant_id=p.tenant_id AND c.original_document_id=p.document_id')
            ->join('goods_units_binding b', 'b.tenant_id=p.tenant_id AND b.goods_id=p.goods_id AND b.unit_id=p.price_unit_id AND b.status=1')
            ->where('p.tenant_id', $tenant)->where('p.customer_id', $customer)->where('p.goods_id', $goods)->where('p.sku_id', $sku)
            ->whereNull('c.original_document_id')->field('p.price_unit_id,p.price_unit_name,p.document_id AS source_document_id,p.confirmed_at')->order('p.id desc')->find();
        return $row ?: null;
    }

    public static function record(int $customer, int $document, array $lines): void
    {
        foreach ($lines as $line) {
            if (($line['fact_version'] ?? 1) < 2) { continue; }
            Db::name('finance_sales_pricing_fact')->insert(['tenant_id' => FinanceAccess::tenant(), 'customer_id' => $customer,
                'goods_id' => $line['goods_id'], 'sku_id' => $line['sku_id'], 'document_id' => $document, 'delivery_item_id' => $line['delivery_item_id'],
                'price_unit_id' => $line['price_unit_id'], 'price_unit_name' => $line['price_unit_name'], 'confirmed_at' => time()]);
        }
    }
}
