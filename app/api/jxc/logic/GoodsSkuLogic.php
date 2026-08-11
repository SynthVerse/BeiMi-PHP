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
use app\common\service\goods\GoodsSkuReferenceService;
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
        if ((string)($goods->dimension_mode ?? 'legacy') === 'generic') {
            self::setError('通用维度商品请通过商品维度维护SKU组合');
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
                    if ((int)($model->is_auto_generated ?? 0) === 1) {
                        self::setError('自动生成的SKU请通过商品维度维护');
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
        $status = [
            'status' => (int)($params['status'] ?? 1) === 0 ? 0 : 1,
            'purchase_status' => (int)($params['purchase_status'] ?? $params['status'] ?? 1) === 0 ? 0 : 1,
            'sale_status' => (int)($params['sale_status'] ?? $params['status'] ?? 1) === 0 ? 0 : 1,
            'update_time' => time(),
        ];
        if ((int)($model->dimension_disabled_snapshot ?? 0) >= 8
            && ($status['status'] === 1 || $status['purchase_status'] === 1 || $status['sale_status'] === 1)
        ) {
            self::setError('该SKU组合已从商品维度中移除，请先恢复组合');
            return false;
        }
        $model->save($status);
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
            'specification_status' => (string)($item['specification_status'] ?? ''),
            'specification_label' => (string)($item['specification_label'] ?? ''),
            'base_unit_id' => (int)($item['base_unit_id'] ?? 0),
            'base_unit_name' => (string)($item['base_unit_name'] ?? ''),
            'base_unit' => (string)($item['base_unit_name'] ?? ''),
            'purchase_status' => (int)($item['purchase_status'] ?? 1),
            'sale_status' => (int)($item['sale_status'] ?? 1),
            'status' => (int)($item['status'] ?? 1),
            'sort' => (int)($item['sort'] ?? 0),
            'remark' => (string)($item['remark'] ?? ''),
            'is_auto_generated' => (int)($item['is_auto_generated'] ?? 0),
            'dimension_disabled_snapshot' => (int)($item['dimension_disabled_snapshot'] ?? 0),
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

        $specificationLabel = trim((string)($sku['specification_label'] ?? ''));
        $specificationStatus = trim((string)($sku['specification_status'] ?? ''));
        if ($specificationStatus === '' && $specificationLabel !== '') {
            $specificationStatus = strtolower((string)preg_replace('/[^a-zA-Z0-9_]+/', '_', $specificationLabel));
        }

        $skuName = trim((string)($sku['sku_name'] ?? ''));
        if ($skuName === '') {
            $skuName = (string)$goods['name'] . '-' . $qualityLabel;
            if ($specificationLabel !== '') {
                $skuName .= '-' . $specificationLabel;
            }
        }

        $skuCode = trim((string)($sku['sku_code'] ?? ''));
        if ($skuCode === '') {
            $skuCode = 'SKU-' . (int)$goods['id'] . '-' . ($qualityStatus !== '' ? $qualityStatus : ($index + 1));
            if ($specificationStatus !== '') {
                $skuCode .= '-' . $specificationStatus;
            }
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
            'specification_status' => $specificationStatus,
            'specification_label' => $specificationLabel,
            'base_unit_id' => $baseUnitId,
            'base_unit_name' => $baseUnitName,
            'purchase_status' => (int)($sku['purchase_status'] ?? 1) === 0 ? 0 : 1,
            'sale_status' => (int)($sku['sale_status'] ?? 1) === 0 ? 0 : 1,
            'status' => (int)($sku['status'] ?? 1) === 0 ? 0 : 1,
            'sort' => (int)($sku['sort'] ?? $index),
            'remark' => trim((string)($sku['remark'] ?? '')),
            'is_auto_generated' => 0,
        ];
    }

    protected static function deleteMissingSkus(int $goodsId, array $keptIds): void
    {
        $query = GoodsSku::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('is_auto_generated', 0);
        if ($keptIds !== []) {
            $query->whereNotIn('id', $keptIds);
        }

        $deleteIds = $query->column('id');
        if ($deleteIds === []) {
            return;
        }

        foreach ($deleteIds as $deleteId) {
            if (GoodsSkuReferenceService::skuHasBusinessReferences(self::tenantId(), (int)$deleteId)) {
                throw new \RuntimeException('SKU已被业务单据使用，请停用后保留');
            }
            $deleteRelationIds = GoodsSupplier::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('sku_id', (int)$deleteId)
                ->column('id');
            if (GoodsSkuReferenceService::supplierRelationsHaveBusinessReferences(
                self::tenantId(),
                $deleteRelationIds
            )) {
                throw new \RuntimeException('SKU供应商关系已被订单明细使用，请停用后保留');
            }
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

        // 同步品质维度
        $spec = GoodsSpec::where('tenant_id', self::tenantId())
            ->where('code', 'quality_status')
            ->findOrEmpty();
        if ($spec->isEmpty()) {
            $spec = GoodsSpec::create([
                'tenant_id' => self::tenantId(),
                'template_id' => (int)$template->id,
                'name' => '品质状态',
                'code' => 'quality_status',
                'dimension_type' => GoodsDimensionLogic::TYPE_SKU,
                'status' => 1,
                'sort' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $valueCode = $data['quality_status'] !== '' ? $data['quality_status'] : 'default';
        $value = GoodsSpecValue::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('spec_id', (int)$spec->id)
            ->where('code', $valueCode)
            ->findOrEmpty();
        if ($value->isEmpty()) {
            $value = GoodsSpecValue::create([
                'tenant_id' => self::tenantId(),
                'goods_id' => $goodsId,
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

        // 同步规格维度（weight_grade）
        $specificationStatus = $data['specification_status'] ?? '';
        if ($specificationStatus !== '') {
            $weightSpec = GoodsSpec::where('tenant_id', self::tenantId())
                ->where('code', 'weight_grade')
                ->findOrEmpty();
            if ($weightSpec->isEmpty()) {
                $weightSpec = GoodsSpec::create([
                    'tenant_id' => self::tenantId(),
                    'template_id' => (int)$template->id,
                    'name' => '重量规格',
                    'code' => 'weight_grade',
                    'dimension_type' => GoodsDimensionLogic::TYPE_SKU,
                    'status' => 1,
                    'sort' => 1,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }

            $specValue = GoodsSpecValue::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('spec_id', (int)$weightSpec->id)
                ->where('code', $specificationStatus)
                ->findOrEmpty();
            if ($specValue->isEmpty()) {
                $specValue = GoodsSpecValue::create([
                    'tenant_id' => self::tenantId(),
                    'goods_id' => $goodsId,
                    'spec_id' => (int)$weightSpec->id,
                    'name' => $data['specification_label'] ?? $specificationStatus,
                    'code' => $specificationStatus,
                    'status' => 1,
                    'sort' => (int)$data['sort'],
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }

            $specRelation = GoodsSkuSpecValue::where('tenant_id', self::tenantId())
                ->where('sku_id', $skuId)
                ->where('spec_id', (int)$weightSpec->id)
                ->findOrEmpty();
            $specRelationData = [
                'tenant_id' => self::tenantId(),
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'spec_id' => (int)$weightSpec->id,
                'spec_value_id' => (int)$specValue->id,
                'spec_name' => '重量规格',
                'spec_value_name' => $data['specification_label'] ?? $specificationStatus,
                'create_time' => time(),
            ];
            if ($specRelation->isEmpty()) {
                GoodsSkuSpecValue::create($specRelationData);
            } else {
                $specRelation->save($specRelationData);
            }
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

    /**
     * 笛卡尔积生成SKU
     * @param array $params [goods_id, quality_ids[], specification_ids[]]
     */
    public static function generateFromCartesian(array $params): array|false
    {
        $goodsId = (int)($params['goods_id'] ?? $params['id'] ?? 0);
        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($goods->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }
        if ((string)($goods->dimension_mode ?? 'legacy') === 'generic') {
            self::setError('通用维度商品请通过商品维度维护SKU组合');
            return false;
        }

        $qualityIds = $params['quality_ids'] ?? [];
        $specificationIds = $params['specification_ids'] ?? [];
        if (!is_array($qualityIds) || empty($qualityIds)) {
            self::setError('请选择至少一个品质');
            return false;
        }
        if (!is_array($specificationIds) || empty($specificationIds)) {
            self::setError('请选择至少一个规格');
            return false;
        }
        $qualityIds = array_values(array_unique(array_map('intval', $qualityIds)));
        $specificationIds = array_values(array_unique(array_map('intval', $specificationIds)));

        // 获取品质值列表
        $qualityValues = Db::name('goods_spec_value')->alias('value')
            ->join('goods_spec spec', 'spec.id=value.spec_id AND spec.tenant_id=value.tenant_id')
            ->where('value.tenant_id', self::tenantId())
            ->where('value.goods_id', $goodsId)
            ->where('value.status', 1)
            ->where('spec.code', 'quality_status')
            ->whereIn('value.id', $qualityIds)
            ->field('value.*')
            ->select()
            ->toArray();
        if (count($qualityValues) !== count($qualityIds)) {
            self::setError('品质值不存在或不属于当前商品');
            return false;
        }

        // 获取规格值列表
        $specValues = Db::name('goods_spec_value')->alias('value')
            ->join('goods_spec spec', 'spec.id=value.spec_id AND spec.tenant_id=value.tenant_id')
            ->where('value.tenant_id', self::tenantId())
            ->where('value.goods_id', $goodsId)
            ->where('value.status', 1)
            ->where('spec.code', 'weight_grade')
            ->whereIn('value.id', $specificationIds)
            ->field('value.*')
            ->select()
            ->toArray();
        if (count($specValues) !== count($specificationIds)) {
            self::setError('规格值不存在或不属于当前商品');
            return false;
        }

        Db::startTrans();
        try {
            $goodsData = $goods->toArray();
            // 计算笛卡尔积
            $combinations = [];
            foreach ($qualityValues as $quality) {
                foreach ($specValues as $spec) {
                    $combinations[] = [
                        'quality_code' => $quality['code'],
                        'quality_name' => $quality['name'],
                        'spec_code' => $spec['code'],
                        'spec_name' => $spec['name'],
                    ];
                }
            }

            // 获取现有自动生成的SKU
            $existingAutoSkus = GoodsSku::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('is_auto_generated', 1)
                ->select()
                ->toArray();

            // 建立现有SKU索引 (quality_status + specification_status)
            $existingMap = [];
            foreach ($existingAutoSkus as $sku) {
                $key = $sku['quality_status'] . '|' . $sku['specification_status'];
                $existingMap[$key] = $sku;
            }

            // 新组合集合
            $newCombinationKeys = [];
            $keptIds = [];

            foreach ($combinations as $index => $combo) {
                $key = $combo['quality_code'] . '|' . $combo['spec_code'];
                $newCombinationKeys[] = $key;

                if (isset($existingMap[$key])) {
                    // 已存在，保留
                    $keptIds[] = (int)$existingMap[$key]['id'];
                } else {
                    // 新建SKU
                    $skuName = $goodsData['name'] . '-' . $combo['quality_name'] . '-' . $combo['spec_name'];
                    $skuCode = 'SKU-' . $goodsId . '-' . $combo['quality_code'] . '-' . $combo['spec_code'];

                    $skuData = [
                        'tenant_id' => self::tenantId(),
                        'goods_id' => $goodsId,
                        'sku_name' => $skuName,
                        'sku_code' => $skuCode,
                        'quality_status' => $combo['quality_code'],
                        'quality_label' => $combo['quality_name'],
                        'specification_status' => $combo['spec_code'],
                        'specification_label' => $combo['spec_name'],
                        'base_unit_id' => (int)($goodsData['unit_id'] ?? 0),
                        'base_unit_name' => (string)($goodsData['units'] ?? ''),
                        'purchase_status' => 1,
                        'sale_status' => 1,
                        'status' => 1,
                        'sort' => $index,
                        'remark' => '',
                        'is_auto_generated' => 1,
                        'dimension_disabled_snapshot' => 0,
                        'create_time' => time(),
                        'update_time' => time(),
                    ];

                    $model = GoodsSku::create($skuData);
                    $keptIds[] = (int)$model->id;
                    self::syncQualitySpecValue($goodsId, (int)$model->id, $skuData);
                }
            }

            // 删除不再存在的自动生成SKU
            $deleteIds = [];
            foreach ($existingAutoSkus as $sku) {
                $key = $sku['quality_status'] . '|' . $sku['specification_status'];
                if (!in_array($key, $newCombinationKeys)) {
                    $deleteIds[] = (int)$sku['id'];
                }
            }
            if (!empty($deleteIds)) {
                foreach ($deleteIds as $deleteId) {
                    $relationIds = GoodsSupplier::where('tenant_id', self::tenantId())
                        ->where('goods_id', $goodsId)
                        ->where('sku_id', $deleteId)
                        ->column('id');
                    if (GoodsSkuReferenceService::skuHasBusinessReferences(self::tenantId(), $deleteId)
                        || GoodsSkuReferenceService::supplierRelationsHaveBusinessReferences(self::tenantId(), $relationIds)
                    ) {
                        throw new \RuntimeException('SKU已被业务单据使用，请停用后保留');
                    }
                    GoodsSupplier::where('tenant_id', self::tenantId())
                        ->where('goods_id', $goodsId)
                        ->where('sku_id', $deleteId)
                        ->delete();
                }
                GoodsSkuSpecValue::where('tenant_id', self::tenantId())
                    ->whereIn('sku_id', $deleteIds)
                    ->delete();
                GoodsSku::where('tenant_id', self::tenantId())
                    ->where('goods_id', $goodsId)
                    ->whereIn('id', $deleteIds)
                    ->delete();
            }

            Db::commit();
            return self::lists(['goods_id' => $goodsId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
