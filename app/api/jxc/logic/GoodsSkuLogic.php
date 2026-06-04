<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSku;
use app\common\model\jxc\GoodsSkuSpecValue;
use app\common\model\jxc\GoodsSpec;
use app\common\model\jxc\GoodsSpecTemplate;
use app\common\model\jxc\GoodsSpecValue;
use app\common\model\jxc\GoodsSupplier;
use app\common\model\jxc\GoodsUnit;
use think\facade\Db;

class GoodsSkuLogic extends BaseLogic
{
    public static function lists(array $params): array
    {
        $goodsId = (int)($params['goods_id'] ?? $params['id'] ?? 0);
        if ($goodsId <= 0 || !self::goodsExists($goodsId)) {
            return [];
        }

        $rows = GoodsSku::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->order(['sort' => 'asc', 'id' => 'asc'])
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

        $skus = $params['skus'] ?? [];
        if (!is_array($skus)) {
            self::setError('SKU列表格式错误');
            return false;
        }

        Db::startTrans();
        try {
            $keptIds = [];
            foreach (array_values($skus) as $index => $sku) {
                if (!is_array($sku)) {
                    continue;
                }
                $data = self::buildSaveData($goods->toArray(), $sku, $index);
                $id = (int)($sku['id'] ?? 0);
                if ($id > 0) {
                    $model = GoodsSku::where('id', $id)
                        ->where('goods_id', $goodsId)
                        ->where('tenant_id', self::tenantId())
                        ->findOrEmpty();
                    if ($model->isEmpty()) {
                        self::setError('SKU不存在');
                        Db::rollback();
                        return false;
                    }
                    if (!self::assertUniqueCode($goodsId, $data['sku_code'], $id)) {
                        Db::rollback();
                        return false;
                    }
                    $data['update_time'] = time();
                    $model->save($data);
                    $keptIds[] = (int)$model->id;
                    self::syncQualitySpecValue($goodsId, (int)$model->id, $data);
                } else {
                    if (!self::assertUniqueCode($goodsId, $data['sku_code'])) {
                        Db::rollback();
                        return false;
                    }
                    $data['tenant_id'] = self::tenantId();
                    $data['goods_id'] = $goodsId;
                    $data['create_time'] = time();
                    $data['update_time'] = time();
                    $model = GoodsSku::create($data);
                    $keptIds[] = (int)$model->id;
                    self::syncQualitySpecValue($goodsId, (int)$model->id, $data);
                }
            }

            self::deleteMissingSkus($goodsId, $keptIds);
            Db::commit();
            return self::lists(['goods_id' => $goodsId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function status(array $params): bool
    {
        $model = GoodsSku::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('SKU不存在');
            return false;
        }
        $model->save([
            'status' => (int)($params['status'] ?? 1) === 0 ? 0 : 1,
            'purchase_status' => (int)($params['purchase_status'] ?? $params['status'] ?? 1) === 0 ? 0 : 1,
            'sale_status' => (int)($params['sale_status'] ?? $params['status'] ?? 1) === 0 ? 0 : 1,
            'update_time' => time(),
        ]);
        return true;
    }

    public static function formatItem(array $item): array
    {
        return [
            'id' => (int)($item['id'] ?? 0),
            'goods_id' => (int)($item['goods_id'] ?? 0),
            'sku_name' => (string)($item['sku_name'] ?? ''),
            'name' => (string)($item['sku_name'] ?? ''),
            'sku_code' => (string)($item['sku_code'] ?? ''),
            'quality_status' => (string)($item['quality_status'] ?? ''),
            'quality_label' => (string)($item['quality_label'] ?? ''),
            'base_unit_id' => (int)($item['base_unit_id'] ?? 0),
            'base_unit_name' => (string)($item['base_unit_name'] ?? ''),
            'base_unit' => (string)($item['base_unit_name'] ?? ''),
            'purchase_status' => (int)($item['purchase_status'] ?? 1),
            'sale_status' => (int)($item['sale_status'] ?? 1),
            'status' => (int)($item['status'] ?? 1),
            'sort' => (int)($item['sort'] ?? 0),
            'remark' => (string)($item['remark'] ?? ''),
        ];
    }

    protected static function buildSaveData(array $goods, array $sku, int $index): array
    {
        $qualityLabel = trim((string)($sku['quality_label'] ?? $sku['quality_name'] ?? $sku['name'] ?? ''));
        $qualityStatus = trim((string)($sku['quality_status'] ?? $sku['quality_code'] ?? ''));
        if ($qualityStatus === '' && $qualityLabel !== '') {
            $qualityStatus = strtolower((string)preg_replace('/[^a-zA-Z0-9_]+/', '_', $qualityLabel));
        }
        if ($qualityLabel === '') {
            $qualityLabel = $qualityStatus !== '' ? $qualityStatus : 'default';
        }

        $skuName = trim((string)($sku['sku_name'] ?? ''));
        if ($skuName === '') {
            $skuName = (string)$goods['name'] . '-' . $qualityLabel;
        }

        $skuCode = trim((string)($sku['sku_code'] ?? ''));
        if ($skuCode === '') {
            $skuCode = 'SKU-' . (int)$goods['id'] . '-' . ($qualityStatus !== '' ? $qualityStatus : ($index + 1));
        }

        $baseUnitId = (int)($sku['base_unit_id'] ?? $goods['unit_id'] ?? 0);
        $baseUnitName = trim((string)($sku['base_unit_name'] ?? $sku['base_unit'] ?? $goods['units'] ?? ''));
        if ($baseUnitId > 0 && $baseUnitName === '') {
            $unit = GoodsUnit::where('id', $baseUnitId)
                ->where('tenant_id', self::tenantId())
                ->findOrEmpty();
            if (!$unit->isEmpty()) {
                $baseUnitName = (string)$unit->name;
            }
        }

        return [
            'sku_name' => $skuName,
            'sku_code' => $skuCode,
            'quality_status' => $qualityStatus,
            'quality_label' => $qualityLabel,
            'base_unit_id' => $baseUnitId,
            'base_unit_name' => $baseUnitName,
            'purchase_status' => (int)($sku['purchase_status'] ?? 1) === 0 ? 0 : 1,
            'sale_status' => (int)($sku['sale_status'] ?? 1) === 0 ? 0 : 1,
            'status' => (int)($sku['status'] ?? 1) === 0 ? 0 : 1,
            'sort' => (int)($sku['sort'] ?? $index),
            'remark' => trim((string)($sku['remark'] ?? '')),
        ];
    }

    protected static function deleteMissingSkus(int $goodsId, array $keptIds): void
    {
        $query = GoodsSku::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId);
        if ($keptIds !== []) {
            $query->whereNotIn('id', $keptIds);
        }

        $deleteIds = $query->column('id');
        if ($deleteIds === []) {
            return;
        }

        GoodsSkuSpecValue::where('tenant_id', self::tenantId())
            ->whereIn('sku_id', $deleteIds)
            ->delete();
        GoodsSupplier::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->whereIn('sku_id', $deleteIds)
            ->delete();
        GoodsSku::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->whereIn('id', $deleteIds)
            ->delete();
    }

    protected static function syncQualitySpecValue(int $goodsId, int $skuId, array $data): void
    {
        $template = GoodsSpecTemplate::where('tenant_id', self::tenantId())
            ->where('code', 'aquatic_quality')
            ->findOrEmpty();
        if ($template->isEmpty()) {
            $template = GoodsSpecTemplate::create([
                'tenant_id' => self::tenantId(),
                'name' => '水产品质',
                'code' => 'aquatic_quality',
                'status' => 1,
                'sort' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $spec = GoodsSpec::where('tenant_id', self::tenantId())
            ->where('template_id', (int)$template->id)
            ->where('code', 'quality_status')
            ->findOrEmpty();
        if ($spec->isEmpty()) {
            $spec = GoodsSpec::create([
                'tenant_id' => self::tenantId(),
                'template_id' => (int)$template->id,
                'name' => '品质状态',
                'code' => 'quality_status',
                'status' => 1,
                'sort' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $valueCode = $data['quality_status'] !== '' ? $data['quality_status'] : 'default';
        $value = GoodsSpecValue::where('tenant_id', self::tenantId())
            ->where('spec_id', (int)$spec->id)
            ->where('code', $valueCode)
            ->findOrEmpty();
        if ($value->isEmpty()) {
            $value = GoodsSpecValue::create([
                'tenant_id' => self::tenantId(),
                'spec_id' => (int)$spec->id,
                'name' => $data['quality_label'],
                'code' => $valueCode,
                'status' => 1,
                'sort' => (int)$data['sort'],
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $relation = GoodsSkuSpecValue::where('tenant_id', self::tenantId())
            ->where('sku_id', $skuId)
            ->where('spec_id', (int)$spec->id)
            ->findOrEmpty();
        $relationData = [
            'tenant_id' => self::tenantId(),
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'spec_id' => (int)$spec->id,
            'spec_value_id' => (int)$value->id,
            'spec_name' => '品质状态',
            'spec_value_name' => $data['quality_label'],
            'create_time' => time(),
        ];
        if ($relation->isEmpty()) {
            GoodsSkuSpecValue::create($relationData);
        } else {
            $relation->save($relationData);
        }
    }

    protected static function assertUniqueCode(int $goodsId, string $skuCode, int $ignoreId = 0): bool
    {
        $query = GoodsSku::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('sku_code', $skuCode);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('SKU编码已存在');
            return false;
        }
        return true;
    }

    protected static function goodsExists(int $goodsId): bool
    {
        return Goods::where('id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->count() > 0;
    }

    protected static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
