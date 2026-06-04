<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSku;
use app\common\model\jxc\GoodsSupplier;
use app\common\model\jxc\GoodsSupplierPriceHistory;
use app\common\model\jxc\GoodsUnit;
use app\common\model\jxc\Vendor;
use think\facade\Db;

class GoodsSupplierMatrixLogic extends BaseLogic
{
    public static function lists(array $params): array
    {
        $goodsId = (int)($params['goods_id'] ?? $params['id'] ?? 0);
        if ($goodsId <= 0) {
            return [];
        }

        $query = Db::name('goods_supplier')
            ->alias('gs')
            ->leftJoin('goods_sku sku', 'sku.id = gs.sku_id AND sku.tenant_id = gs.tenant_id')
            ->leftJoin('vendor v', 'v.id = gs.supplier_id AND v.tenant_id = gs.tenant_id')
            ->where('gs.tenant_id', self::tenantId())
            ->where('gs.goods_id', $goodsId);

        $skuId = (int)($params['sku_id'] ?? 0);
        if ($skuId > 0) {
            $query->where('gs.sku_id', $skuId);
        }
        $supplierId = (int)($params['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $query->where('gs.supplier_id', $supplierId);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('gs.status', (int)$params['status']);
        }

        $rows = $query
            ->field('gs.*,sku.sku_name,sku.quality_status,sku.quality_label,v.supplier_name,v.contact,v.phone')
            ->order(['gs.sku_id' => 'asc', 'gs.is_preferred' => 'desc', 'gs.id' => 'desc'])
            ->select()
            ->toArray();

        return array_map([self::class, 'formatItem'], $rows);
    }

    public static function save(array $params): array|false
    {
        $goodsId = (int)($params['goods_id'] ?? $params['id'] ?? 0);
        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($goods->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }

        $relations = $params['relations'] ?? $params['suppliers'] ?? [];
        if (!is_array($relations)) {
            self::setError('供应商矩阵格式错误');
            return false;
        }

        Db::startTrans();
        try {
            $keptIds = [];
            foreach ($relations as $relation) {
                if (!is_array($relation)) {
                    continue;
                }
                $data = self::buildRelationData($goodsId, $relation);
                if ($data === false) {
                    Db::rollback();
                    return false;
                }

                if ((int)$data['is_preferred'] === 1) {
                    GoodsSupplier::where('tenant_id', self::tenantId())
                        ->where('goods_id', $goodsId)
                        ->where('sku_id', (int)$data['sku_id'])
                        ->update(['is_preferred' => 0, 'is_primary' => 0]);
                }

                $model = GoodsSupplier::where('tenant_id', self::tenantId())
                    ->where('goods_id', $goodsId)
                    ->where('sku_id', (int)$data['sku_id'])
                    ->where('supplier_id', (int)$data['supplier_id'])
                    ->findOrEmpty();

                $oldPrice = $model->isEmpty() ? null : (string)$model->purchase_price;
                if ($model->isEmpty()) {
                    $data['tenant_id'] = self::tenantId();
                    $data['goods_id'] = $goodsId;
                    $data['create_time'] = time();
                    $data['update_time'] = time();
                    $model = GoodsSupplier::create($data);
                } else {
                    $data['update_time'] = time();
                    $model->save($data);
                }
                $keptIds[] = (int)$model->id;

                $oldPriceText = $oldPrice === null ? null : self::money($oldPrice);
                if ($oldPriceText === null || $oldPriceText !== (string)$data['purchase_price']) {
                    GoodsSupplierPriceHistory::create([
                        'tenant_id' => self::tenantId(),
                        'goods_supplier_id' => (int)$model->id,
                        'goods_id' => $goodsId,
                        'sku_id' => (int)$data['sku_id'],
                        'supplier_id' => (int)$data['supplier_id'],
                        'purchase_price' => $data['purchase_price'],
                        'effective_date' => date('Y-m-d'),
                        'remark' => '供应商SKU采购价更新',
                        'create_time' => time(),
                    ]);
                }
            }

            self::deleteMissingRelations($goodsId, $keptIds);
            Db::commit();
            return self::lists(['goods_id' => $goodsId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function deleteMissingRelations(int $goodsId, array $keptIds): void
    {
        $query = GoodsSupplier::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('sku_id', '>', 0);
        if ($keptIds !== []) {
            $query->whereNotIn('id', $keptIds);
        }
        $query->delete();
    }

    public static function assertCanSupply(int $supplierId, int $goodsId, int $skuId): GoodsSupplier|false
    {
        $relation = GoodsSupplier::where('tenant_id', self::tenantId())
            ->where('supplier_id', $supplierId)
            ->where('goods_id', $goodsId)
            ->where('sku_id', $skuId)
            ->where('status', 1)
            ->findOrEmpty();
        if ($relation->isEmpty()) {
            self::setError('供应商未绑定该SKU，不能采购');
            return false;
        }
        return $relation;
    }

    public static function formatItem(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'relation_id' => (int)($row['id'] ?? 0),
            'goods_id' => (int)($row['goods_id'] ?? 0),
            'sku_id' => (int)($row['sku_id'] ?? 0),
            'sku_name' => (string)($row['sku_name'] ?? ''),
            'quality_status' => (string)($row['quality_status'] ?? ''),
            'quality_label' => (string)($row['quality_label'] ?? ''),
            'supplier_id' => (int)($row['supplier_id'] ?? 0),
            'supplier_name' => (string)($row['supplier_name'] ?? ''),
            'name' => (string)($row['supplier_name'] ?? ''),
            'contact' => (string)($row['contact'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'supplier_product_code' => (string)($row['supplier_product_code'] ?? ''),
            'supplier_goods_name' => (string)($row['supplier_goods_name'] ?? ''),
            'purchase_price' => (string)($row['purchase_price'] ?? '0.00'),
            'default_purchase_price' => (string)($row['purchase_price'] ?? '0.00'),
            'purchase_unit_id' => (int)($row['purchase_unit_id'] ?? 0),
            'purchase_unit_name' => (string)($row['purchase_unit_name'] ?? ''),
            'purchase_unit' => (string)($row['purchase_unit_name'] ?? ''),
            'settlement_unit_id' => (int)($row['settlement_unit_id'] ?? 0),
            'settlement_unit_name' => (string)($row['settlement_unit_name'] ?? ''),
            'settlement_unit' => (string)($row['settlement_unit_name'] ?? ''),
            'min_purchase_qty' => (string)($row['min_purchase_qty'] ?? '0.0000'),
            'daily_capacity_qty' => (string)($row['daily_capacity_qty'] ?? '0.0000'),
            'lead_time_days' => (int)($row['lead_time_days'] ?? 0),
            'is_preferred' => (int)($row['is_preferred'] ?? $row['is_primary'] ?? 0),
            'is_primary' => (int)($row['is_primary'] ?? $row['is_preferred'] ?? 0),
            'status' => (int)($row['status'] ?? 1),
            'remark' => (string)($row['remark'] ?? ''),
        ];
    }

    protected static function buildRelationData(int $goodsId, array $row): array|false
    {
        $skuId = (int)($row['sku_id'] ?? 0);
        $supplierId = (int)($row['supplier_id'] ?? $row['id'] ?? 0);
        if ($skuId <= 0) {
            self::setError('请选择SKU');
            return false;
        }
        if ($supplierId <= 0) {
            self::setError('请选择供应商');
            return false;
        }

        $sku = GoodsSku::where('id', $skuId)
            ->where('goods_id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($sku->isEmpty()) {
            self::setError('SKU不存在');
            return false;
        }

        $supplier = Vendor::where('id', $supplierId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($supplier->isEmpty()) {
            self::setError('供应商不存在');
            return false;
        }
        if ((int)($supplier->is_disabled ?? 0) === 1) {
            self::setError('停用供应商不可绑定SKU');
            return false;
        }

        $purchaseUnitId = (int)($row['purchase_unit_id'] ?? 0);
        $settlementUnitId = (int)($row['settlement_unit_id'] ?? $sku->base_unit_id ?? 0);
        $purchaseUnitName = self::unitName($purchaseUnitId, (string)($row['purchase_unit_name'] ?? $row['purchase_unit'] ?? ''));
        $settlementUnitName = self::unitName($settlementUnitId, (string)($row['settlement_unit_name'] ?? $row['settlement_unit'] ?? $sku->base_unit_name ?? ''));

        return [
            'sku_id' => $skuId,
            'supplier_id' => $supplierId,
            'is_primary' => (int)($row['is_primary'] ?? $row['is_preferred'] ?? 0) === 1 ? 1 : 0,
            'is_preferred' => (int)($row['is_preferred'] ?? $row['is_primary'] ?? 0) === 1 ? 1 : 0,
            'supplier_product_code' => trim((string)($row['supplier_product_code'] ?? '')),
            'supplier_goods_name' => trim((string)($row['supplier_goods_name'] ?? $row['supplier_product_name'] ?? '')),
            'purchase_price' => self::money($row['purchase_price'] ?? $row['default_purchase_price'] ?? 0),
            'purchase_unit_id' => $purchaseUnitId,
            'purchase_unit_name' => $purchaseUnitName,
            'settlement_unit_id' => $settlementUnitId,
            'settlement_unit_name' => $settlementUnitName,
            'min_purchase_qty' => self::decimal4($row['min_purchase_qty'] ?? 0),
            'daily_capacity_qty' => self::decimal4($row['daily_capacity_qty'] ?? 0),
            'lead_time_days' => max(0, (int)($row['lead_time_days'] ?? 0)),
            'status' => (int)($row['status'] ?? 1) === 0 ? 0 : 1,
            'remark' => trim((string)($row['remark'] ?? '')),
        ];
    }

    protected static function unitName(int $unitId, string $fallback): string
    {
        if ($unitId <= 0) {
            return $fallback;
        }
        $unit = GoodsUnit::where('id', $unitId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        return $unit->isEmpty() ? $fallback : (string)$unit->name;
    }

    protected static function money(mixed $value): string
    {
        return number_format(max(0, (float)$value), 2, '.', '');
    }

    protected static function decimal4(mixed $value): string
    {
        return number_format(max(0, (float)$value), 4, '.', '');
    }

    protected static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
