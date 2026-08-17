<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSku;
use app\common\model\jxc\GoodsSkuSpecValue;
use app\common\model\jxc\GoodsSpec;
use app\common\model\jxc\GoodsSpecValue;
use app\common\model\jxc\GoodsSupplier;
use app\common\model\jxc\OrderGoods;
use app\common\service\goods\GoodsMaintenancePermissionService;
use app\common\service\goods\GoodsBaseSkuService;
use app\common\service\goods\GoodsSkuReferenceService;
use think\facade\Db;

/**
 * 租户商品维度模块。
 *
 * goods_spec 保存租户级定义，goods_spec_value 保存商品选项；只有 sku 类型
 * 会写入 goods_sku_spec_value 并生成 SKU，descriptive 类型仅作为商品属性。
 */
class GoodsDimensionLogic extends BaseLogic
{
    private const DIMENSION_DISABLED_MARKER = 8;
    private const BASE_SKU_CODE_SUFFIX = 'BASE';

    public const TYPE_SKU = 'sku';
    public const TYPE_DESCRIPTIVE = 'descriptive';
    public const TYPE_UNUSED = 'unused';
    public const MAX_PRODUCT_DIMENSIONS = 10;
    public const MAX_VALUES_PER_DIMENSION = 100;
    public const MAX_SKU_COMBINATIONS = 500;
    public const MAX_DIMENSION_NAME_LENGTH = 100;
    public const MAX_DIMENSION_VALUE_NAME_LENGTH = 100;
    public const MAX_SKU_NAME_LENGTH = 200;

    public static function definitions(array $params = []): array
    {
        $query = GoodsSpec::where('tenant_id', self::tenantId());
        if (array_key_exists('status', $params) && $params['status'] !== '') {
            $query->where('status', (int)$params['status'] === 0 ? 0 : 1);
        }
        $rows = $query->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();

        return array_map(static function (array $row): array {
            $id = (int)$row['id'];
            $referenceCount = self::definitionReferenceCount($id);
            return self::formatDefinition($row, $referenceCount);
        }, $rows);
    }

    public static function saveDefinition(array $params): array|false
    {
        if (!self::canMaintain()) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $model = null;
        $current = [];
        if ($id > 0) {
            $model = GoodsSpec::where('tenant_id', self::tenantId())->where('id', $id)->findOrEmpty();
            if ($model->isEmpty()) {
                self::setError('维度不存在');
                return false;
            }
            $current = $model->toArray();
        }
        $name = trim((string)($params['name'] ?? $current['name'] ?? ''));
        $code = array_key_exists('code', $params)
            ? self::normalizeCode((string)$params['code'], $name, 'dimension')
            : (string)($current['code'] ?? self::normalizeCode('', $name, 'dimension'));
        $type = (string)($params['dimension_type'] ?? ($current === [] ? self::TYPE_SKU : self::typeOf($current)));
        if ($name === '') {
            self::setError('维度名称不能为空');
            return false;
        }
        if (mb_strlen($name) > self::MAX_DIMENSION_NAME_LENGTH) {
            self::setError('维度名称不能超过' . self::MAX_DIMENSION_NAME_LENGTH . '个字符');
            return false;
        }
        if (!in_array($type, [self::TYPE_SKU, self::TYPE_DESCRIPTIVE], true)) {
            self::setError('维度类型无效');
            return false;
        }

        $duplicate = GoodsSpec::where('tenant_id', self::tenantId())
            ->where('code', $code);
        if ($id > 0) {
            $duplicate->where('id', '<>', $id);
        }
        if ($duplicate->count() > 0) {
            self::setError('维度编码已存在');
            return false;
        }

        $now = time();
        $data = [
            'name' => $name,
            'code' => $code,
            'dimension_type' => $type,
            'status' => (int)($params['status'] ?? $current['status'] ?? 1) === 0 ? 0 : 1,
            'sort' => (int)($params['sort'] ?? $current['sort'] ?? 0),
            'update_time' => $now,
        ];

        if ($id > 0) {
            $referenced = self::definitionReferenceCount($id) > 0;
            if ($referenced && ((string)$model->code !== $code || self::typeOf($model->toArray()) !== $type)) {
                self::setError('已被商品引用的维度不能修改编码或类型，可修改名称或停用');
                return false;
            }
            $model->save($data);
            GoodsSkuSpecValue::where('tenant_id', self::tenantId())
                ->where('spec_id', $id)
                ->update(['spec_name' => $name]);
            return self::formatDefinition($model->toArray(), self::definitionReferenceCount($id));
        }

        $model = GoodsSpec::create($data + [
            'tenant_id' => self::tenantId(),
            'template_id' => 0,
            'create_time' => $now,
        ]);
        return self::formatDefinition($model->toArray(), 0);
    }

    public static function deleteDefinition(array $params): bool
    {
        if (!self::canMaintain()) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $model = GoodsSpec::where('tenant_id', self::tenantId())->where('id', $id)->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('维度不存在');
            return false;
        }
        if (self::definitionReferenceCount($id) > 0) {
            self::setError('维度已被商品引用，请停用后保留');
            return false;
        }
        $model->delete();
        return true;
    }

    public static function productDimensions(array $params): array
    {
        $goodsId = (int)($params['goods_id'] ?? $params['id'] ?? 0);
        if (!self::goodsExists($goodsId)) {
            return ['dimensions' => [], 'skus' => []];
        }

        $values = GoodsSpecValue::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('status', 1)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();
        $definitionIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['spec_id'], $values)));
        $definitions = [];
        if ($definitionIds !== []) {
            $settings = Db::name('goods_dimension_setting')
                ->where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->whereIn('spec_id', $definitionIds)
                ->select()
                ->toArray();
            $settingsBySpecId = [];
            foreach ($settings as $setting) {
                $settingsBySpecId[(int)$setting['spec_id']] = $setting;
            }
            $definitionRows = GoodsSpec::where('tenant_id', self::tenantId())
                ->whereIn('id', $definitionIds)
                ->order(['sort' => 'asc', 'id' => 'asc'])
                ->select()
                ->toArray();
            foreach ($definitionRows as $definition) {
                $setting = $settingsBySpecId[(int)$definition['id']] ?? [];
                $usageMode = self::usageModeOf($setting, $definition);
                $definition['dimension_type'] = $usageMode;
                $definition['sort'] = (int)($setting['sort'] ?? $definition['sort'] ?? 0);
                $definition['values'] = array_values(array_map(
                    [self::class, 'formatValue'],
                    array_filter($values, static fn(array $value): bool => (int)$value['spec_id'] === (int)$definition['id'])
                ));
                $definitions[] = self::formatDefinition($definition, self::definitionReferenceCount((int)$definition['id'])) + [
                    'usage_mode' => $usageMode,
                    'values' => $definition['values'],
                ];
            }
            usort($definitions, [self::class, 'compareDimensions']);
        }

        return [
            'dimensions' => $definitions,
            'skus' => self::formatSkusWithDimensions($goodsId),
        ];
    }

    public static function saveProductDimensions(array $params): array|false
    {
        if (!self::canMaintain()) {
            return false;
        }
        $goodsId = (int)($params['goods_id'] ?? $params['id'] ?? 0);
        $goods = Goods::where('tenant_id', self::tenantId())->where('id', $goodsId)->findOrEmpty();
        if ($goods->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }
        $normalized = self::normalizeProductDimensions($goodsId, $params['dimensions'] ?? []);
        if ($normalized === false) {
            return false;
        }
        $skuDimensions = array_values(array_filter(
            $normalized,
            static fn(array $dimension): bool => $dimension['dimension_type'] === self::TYPE_SKU
        ));
        usort($skuDimensions, [self::class, 'compareDimensions']);
        if (!self::assertCombinationCapacity($skuDimensions)) {
            return false;
        }

        Db::startTrans();
        try {
            self::saveDimensionSettings($goodsId, $normalized);
            $savedDimensions = self::saveDimensionValues($goodsId, $normalized);
            $savedSkuDimensions = array_values(array_filter(
                $savedDimensions,
                static fn(array $dimension): bool => $dimension['dimension_type'] === self::TYPE_SKU
            ));
            usort($savedSkuDimensions, [self::class, 'compareDimensions']);
            $combinations = self::resolveCombinations($savedSkuDimensions, $params['combinations'] ?? null);
            if ($combinations === false) {
                Db::rollback();
                return false;
            }
            if ((string)($goods->dimension_mode ?? 'legacy') !== 'generic') {
                self::retireLegacyManualSkus($goodsId);
            }
            Goods::where('tenant_id', self::tenantId())->where('id', $goodsId)->update([
                'dimension_mode' => 'generic',
                'update_time' => time(),
            ]);
            self::syncSkus($goods->toArray(), $savedSkuDimensions, $combinations);
            Db::commit();
            return self::productDimensions(['goods_id' => $goodsId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function refreshGeneratedSkuNames(int $goodsId, string $goodsName): void
    {
        $skus = GoodsSku::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('is_auto_generated', 1)
            ->select()
            ->toArray();
        foreach ($skus as $sku) {
            $labels = GoodsSkuSpecValue::where('tenant_id', self::tenantId())
                ->where('sku_id', (int)$sku['id'])
                ->order('id', 'asc')
                ->column('spec_value_name');
            $isBaseSku = (string)($sku['sku_code'] ?? '') === self::baseSkuCode($goodsId);
            if ($labels !== [] || $isBaseSku) {
                $skuName = self::buildSkuName($goodsName, $labels);
                GoodsSku::where('tenant_id', self::tenantId())
                    ->where('id', (int)$sku['id'])
                    ->update([
                        'sku_name' => $skuName,
                        'update_time' => time(),
                    ]);
            }
        }
    }

    private static function normalizeProductDimensions(int $goodsId, mixed $rows): array|false
    {
        if (!is_array($rows)) {
            self::setError('商品维度格式错误');
            return false;
        }
        if (count($rows) > self::MAX_PRODUCT_DIMENSIONS) {
            self::setError('商品维度最多配置' . self::MAX_PRODUCT_DIMENSIONS . '个');
            return false;
        }
        $normalized = [];
        $seen = [];
        foreach (array_values($rows) as $dimensionIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            $dimensionId = (int)($row['dimension_id'] ?? $row['id'] ?? 0);
            if ($dimensionId <= 0 || isset($seen[$dimensionId])) {
                self::setError('商品维度不能重复');
                return false;
            }
            $definition = GoodsSpec::where('tenant_id', self::tenantId())
                ->where('id', $dimensionId)
                ->findOrEmpty();
            if ($definition->isEmpty()) {
                self::setError('商品维度不存在');
                return false;
            }
            $usageMode = self::normalizeUsageMode(
                (string)($row['usage_mode'] ?? $row['dimension_type'] ?? self::typeOf($definition->toArray()))
            );
            if ($usageMode === false) {
                return false;
            }
            $seen[$dimensionId] = true;
            if ($usageMode === self::TYPE_UNUSED) {
                continue;
            }
            $assignedValues = GoodsSpecValue::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('spec_id', $dimensionId)
                ->where('status', 1)
                ->order(['sort' => 'asc', 'id' => 'asc'])
                ->select()
                ->toArray();
            $alreadyAssigned = $assignedValues !== [];
            if ((int)$definition->status === 0 && !$alreadyAssigned) {
                self::setError('商品维度已停用，不能用于新的商品配置');
                return false;
            }
            $values = $row['values'] ?? [];
            if (!is_array($values)) {
                self::setError('维度选项格式错误');
                return false;
            }
            if (count($values) > self::MAX_VALUES_PER_DIMENSION) {
                self::setError('每个维度最多配置' . self::MAX_VALUES_PER_DIMENSION . '个选项');
                return false;
            }
            $normalizedValues = [];
            $valueCodes = [];
            foreach (array_values($values) as $valueIndex => $value) {
                if (!is_array($value) || (int)($value['status'] ?? 1) === 0) {
                    continue;
                }
                $name = trim((string)($value['name'] ?? ''));
                if ($name === '') {
                    self::setError('维度选项名称不能为空');
                    return false;
                }
                if (mb_strlen($name) > self::MAX_DIMENSION_VALUE_NAME_LENGTH) {
                    self::setError('维度选项名称不能超过' . self::MAX_DIMENSION_VALUE_NAME_LENGTH . '个字符');
                    return false;
                }
                $code = self::normalizeCode((string)($value['code'] ?? ''), $name, 'value');
                if (isset($valueCodes[$code])) {
                    self::setError('同一维度的选项不能重复');
                    return false;
                }
                $valueCodes[$code] = true;
                $normalizedValues[] = [
                    'id' => (int)($value['id'] ?? 0),
                    'name' => $name,
                    'code' => $code,
                    'status' => 1,
                    'sort' => (int)($value['sort'] ?? $valueIndex),
                ];
            }
            if ($usageMode === self::TYPE_SKU && $normalizedValues === []) {
                self::setError('SKU维度必须至少包含一个有效选项');
                return false;
            }
            if ($normalizedValues === []) {
                continue;
            }
            if ((int)$definition->status === 0) {
                $assignedById = [];
                foreach ($assignedValues as $assignedValue) {
                    $assignedById[(int)$assignedValue['id']] = $assignedValue;
                }
                $unchanged = count($assignedById) === count($normalizedValues);
                foreach ($normalizedValues as $normalizedValue) {
                    $assignedValue = $assignedById[(int)$normalizedValue['id']] ?? null;
                    if ($assignedValue === null
                        || (string)$assignedValue['name'] !== $normalizedValue['name']
                        || (string)$assignedValue['code'] !== $normalizedValue['code']
                    ) {
                        $unchanged = false;
                        break;
                    }
                }
                if (!$unchanged) {
                    self::setError('已停用维度只能保留原商品配置，不能增删或修改选项');
                    return false;
                }
                $normalizedValues = array_map(static fn(array $value): array => [
                    'id' => (int)$value['id'],
                    'name' => (string)$value['name'],
                    'code' => (string)$value['code'],
                    'status' => 1,
                    'sort' => (int)$value['sort'],
                ], $assignedValues);
            }
            $normalized[] = [
                'id' => $dimensionId,
                'name' => (string)$definition->name,
                'code' => (string)$definition->code,
                'dimension_type' => $usageMode,
                'usage_mode' => $usageMode,
                'sort' => (int)($row['sort'] ?? $definition->sort ?? $dimensionIndex),
                'values' => $normalizedValues,
            ];
        }
        return $normalized;
    }

    private static function saveDimensionSettings(int $goodsId, array $dimensions): void
    {
        $keptSpecIds = [];
        $now = time();
        foreach ($dimensions as $dimension) {
            $specId = (int)$dimension['id'];
            $keptSpecIds[] = $specId;
            $data = [
                'usage_mode' => (string)$dimension['usage_mode'],
                'sort' => (int)$dimension['sort'],
                'update_time' => $now,
            ];
            $setting = Db::name('goods_dimension_setting')
                ->where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('spec_id', $specId)
                ->find();
            if ($setting) {
                Db::name('goods_dimension_setting')->where('id', (int)$setting['id'])->update($data);
                continue;
            }
            Db::name('goods_dimension_setting')->insert($data + [
                'tenant_id' => self::tenantId(),
                'goods_id' => $goodsId,
                'spec_id' => $specId,
                'create_time' => $now,
            ]);
        }

        $stale = Db::name('goods_dimension_setting')
            ->where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId);
        if ($keptSpecIds !== []) {
            $stale->whereNotIn('spec_id', $keptSpecIds);
        }
        $stale->delete();
    }

    private static function saveDimensionValues(int $goodsId, array $dimensions): array
    {
        $keptValueIds = [];
        foreach ($dimensions as &$dimension) {
            foreach ($dimension['values'] as &$value) {
                $query = GoodsSpecValue::where('tenant_id', self::tenantId())
                    ->where('goods_id', $goodsId)
                    ->where('spec_id', $dimension['id']);
                $model = $value['id'] > 0
                    ? (clone $query)->where('id', $value['id'])->findOrEmpty()
                    : (clone $query)->where('code', $value['code'])->findOrEmpty();
                $data = [
                    'name' => $value['name'],
                    'code' => $value['code'],
                    'status' => 1,
                    'sort' => $value['sort'],
                    'update_time' => time(),
                ];
                if ($model->isEmpty()) {
                    $model = GoodsSpecValue::create($data + [
                        'tenant_id' => self::tenantId(),
                        'goods_id' => $goodsId,
                        'spec_id' => $dimension['id'],
                        'create_time' => time(),
                    ]);
                } else {
                    $model->save($data);
                }
                $value['id'] = (int)$model->id;
                GoodsSkuSpecValue::where('tenant_id', self::tenantId())
                    ->where('spec_value_id', (int)$model->id)
                    ->update([
                        'spec_name' => (string)$dimension['name'],
                        'spec_value_name' => (string)$value['name'],
                    ]);
                $keptValueIds[] = (int)$model->id;
            }
            unset($value);
        }
        unset($dimension);

        $staleQuery = GoodsSpecValue::where('tenant_id', self::tenantId())->where('goods_id', $goodsId);
        if ($keptValueIds !== []) {
            $staleQuery->whereNotIn('id', $keptValueIds);
        }
        $staleQuery->update(['status' => 0, 'update_time' => time()]);
        return $dimensions;
    }

    private static function resolveCombinations(array $dimensions, mixed $requested): array|false
    {
        if ($dimensions === []) {
            return [[]];
        }
        $valueMaps = [];
        foreach ($dimensions as $dimension) {
            $valueMaps[$dimension['code']] = [];
            foreach ($dimension['values'] as $value) {
                $valueMaps[$dimension['code']][$value['code']] = $value;
            }
        }
        if ($requested === null) {
            return self::cartesian($dimensions);
        }
        if (!is_array($requested) || $requested === []) {
            self::setError('请至少启用一个SKU组合');
            return false;
        }
        if (count($requested) > self::MAX_SKU_COMBINATIONS) {
            self::setError('SKU组合最多允许' . self::MAX_SKU_COMBINATIONS . '个');
            return false;
        }

        $result = [];
        $seen = [];
        foreach ($requested as $row) {
            if (!is_array($row) || (int)($row['enabled'] ?? 1) === 0) {
                continue;
            }
            $codes = $row['values'] ?? [];
            if (!is_array($codes) || count($codes) !== count($dimensions)) {
                self::setError('SKU组合必须为每个SKU维度选择一个选项');
                return false;
            }
            $combination = [];
            foreach ($dimensions as $dimension) {
                $valueCode = trim((string)($codes[$dimension['code']] ?? ''));
                if ($valueCode === '' || !isset($valueMaps[$dimension['code']][$valueCode])) {
                    self::setError('SKU组合包含无效维度选项');
                    return false;
                }
                $combination[] = ['dimension' => $dimension, 'value' => $valueMaps[$dimension['code']][$valueCode]];
            }
            $key = implode('|', array_map(static fn(array $item): string => $item['dimension']['code'] . '=' . $item['value']['code'], $combination));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $combination;
            }
        }
        if ($result === []) {
            self::setError('请至少启用一个SKU组合');
            return false;
        }
        return $result;
    }

    private static function cartesian(array $dimensions): array
    {
        $result = [[]];
        foreach ($dimensions as $dimension) {
            $next = [];
            foreach ($result as $prefix) {
                foreach ($dimension['values'] as $value) {
                    $next[] = array_merge($prefix, [['dimension' => $dimension, 'value' => $value]]);
                }
            }
            $result = $next;
        }
        return $result;
    }

    private static function syncSkus(array $goods, array $dimensions, array $combinations): void
    {
        $goodsId = (int)$goods['id'];
        $keptIds = [];
        foreach ($combinations as $index => $combination) {
            if ($combination === []) {
                $sku = GoodsBaseSkuService::ensure(
                    self::tenantId(),
                    $goodsId,
                    (string)$goods['name'],
                    (int)($goods['unit_id'] ?? 0),
                    (string)($goods['units'] ?? '')
                );
                $skuId = (int)$sku->id;
                $keptIds[] = $skuId;
                GoodsSkuSpecValue::where('tenant_id', self::tenantId())->where('sku_id', $skuId)->delete();
                continue;
            }
            $labels = array_map(static fn(array $item): string => $item['value']['name'], $combination);
            $identity = self::skuIdentity($combination);
            $skuCode = 'SKU-' . $goodsId . '-' . substr(sha1($identity), 0, 20);
            $legacy = self::legacyFields($combination);
            $data = [
                'sku_name' => self::buildSkuName((string)$goods['name'], $labels),
                'sku_code' => $skuCode,
                'quality_status' => $legacy['quality_status'],
                'quality_label' => $legacy['quality_label'],
                'specification_status' => $legacy['specification_status'],
                'specification_label' => $legacy['specification_label'],
                'base_unit_id' => (int)($goods['unit_id'] ?? 0),
                'base_unit_name' => (string)($goods['units'] ?? ''),
                'sort' => $index,
                'is_auto_generated' => 1,
                'update_time' => time(),
            ];
            $sku = GoodsSku::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('sku_code', $skuCode)
                ->findOrEmpty();
            if ($sku->isEmpty()) {
                $sku = GoodsSku::create($data + [
                    'tenant_id' => self::tenantId(),
                    'goods_id' => $goodsId,
                    'dimension_disabled_snapshot' => 0,
                    'purchase_status' => 1,
                    'sale_status' => 1,
                    'status' => 1,
                    'remark' => '',
                    'create_time' => time(),
                ]);
            } else {
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
            }
            $skuId = (int)$sku->id;
            $keptIds[] = $skuId;
            GoodsSkuSpecValue::where('tenant_id', self::tenantId())->where('sku_id', $skuId)->delete();
            foreach ($combination as $item) {
                GoodsSkuSpecValue::create([
                    'tenant_id' => self::tenantId(),
                    'goods_id' => $goodsId,
                    'sku_id' => $skuId,
                    'spec_id' => (int)$item['dimension']['id'],
                    'spec_value_id' => (int)$item['value']['id'],
                    'spec_name' => (string)$item['dimension']['name'],
                    'spec_value_name' => (string)$item['value']['name'],
                    'create_time' => time(),
                ]);
            }
        }

        $staleQuery = GoodsSku::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('is_auto_generated', 1);
        if ($keptIds !== []) {
            $staleQuery->whereNotIn('id', $keptIds);
        }
        $staleIds = array_map('intval', $staleQuery->column('id'));
        foreach ($staleIds as $skuId) {
            $supplierRelationIds = GoodsSupplier::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('sku_id', $skuId)
                ->column('id');
            $usedBySku = GoodsSkuReferenceService::skuHasBusinessReferences(self::tenantId(), $skuId);
            $usedBySupplierRelation = GoodsSkuReferenceService::supplierRelationsHaveBusinessReferences(
                self::tenantId(),
                $supplierRelationIds
            );
            if ($usedBySku || $usedBySupplierRelation) {
                $sku = GoodsSku::where('id', $skuId)
                    ->where('tenant_id', self::tenantId())
                    ->findOrEmpty();
                $snapshot = self::disabledSnapshot($sku);
                GoodsSku::where('id', $skuId)->where('tenant_id', self::tenantId())->update([
                    'status' => 0,
                    'purchase_status' => 0,
                    'sale_status' => 0,
                    'dimension_disabled_snapshot' => $snapshot,
                    'update_time' => time(),
                ]);
                continue;
            }
            GoodsSupplier::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('sku_id', $skuId)
                ->delete();
            GoodsSkuSpecValue::where('tenant_id', self::tenantId())->where('sku_id', $skuId)->delete();
            GoodsSku::where('tenant_id', self::tenantId())->where('id', $skuId)->delete();
        }
    }

    private static function retireLegacyManualSkus(int $goodsId): void
    {
        $manualSkus = GoodsSku::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('is_auto_generated', 0)
            ->select();
        foreach ($manualSkus as $sku) {
            $skuId = (int)$sku->id;
            $relationIds = GoodsSupplier::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('sku_id', $skuId)
                ->column('id');
            if (GoodsSkuReferenceService::skuHasBusinessReferences(self::tenantId(), $skuId)
                || GoodsSkuReferenceService::supplierRelationsHaveBusinessReferences(self::tenantId(), $relationIds)
            ) {
                GoodsSku::where('tenant_id', self::tenantId())->where('id', $skuId)->update([
                    'status' => 0,
                    'purchase_status' => 0,
                    'sale_status' => 0,
                    'dimension_disabled_snapshot' => self::disabledSnapshot($sku),
                    'update_time' => time(),
                ]);
                continue;
            }
            GoodsSupplier::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->where('sku_id', $skuId)
                ->delete();
            GoodsSkuSpecValue::where('tenant_id', self::tenantId())->where('sku_id', $skuId)->delete();
            GoodsSku::where('tenant_id', self::tenantId())->where('id', $skuId)->delete();
        }
    }

    private static function disabledSnapshot(GoodsSku $sku): int
    {
        $snapshot = (int)($sku->dimension_disabled_snapshot ?? 0);
        if ($snapshot >= self::DIMENSION_DISABLED_MARKER) {
            return $snapshot;
        }
        $snapshot = self::DIMENSION_DISABLED_MARKER;
        $snapshot |= (int)$sku->status === 1 ? 1 : 0;
        $snapshot |= (int)$sku->purchase_status === 1 ? 2 : 0;
        $snapshot |= (int)$sku->sale_status === 1 ? 4 : 0;
        return $snapshot;
    }

    /** @param array<int,string> $labels */
    private static function buildSkuName(string $goodsName, array $labels): string
    {
        $skuName = $labels === [] ? $goodsName : $goodsName . '-' . implode('-', $labels);
        if (mb_strlen($skuName) > self::MAX_SKU_NAME_LENGTH) {
            throw new \RuntimeException(
                'SKU名称不能超过' . self::MAX_SKU_NAME_LENGTH . '个字符，请缩短商品名或维度选项'
            );
        }
        return $skuName;
    }

    private static function formatSkusWithDimensions(int $goodsId): array
    {
        $skus = GoodsSkuLogic::lists(['goods_id' => $goodsId]);
        if ($skus === []) {
            return [];
        }
        $relations = GoodsSkuSpecValue::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        foreach ($skus as &$sku) {
            $sku['dimensions'] = array_values(array_map(static fn(array $row): array => [
                'dimension_id' => (int)$row['spec_id'],
                'value_id' => (int)$row['spec_value_id'],
                'dimension_name' => (string)$row['spec_name'],
                'value_name' => (string)$row['spec_value_name'],
            ], array_filter($relations, static fn(array $row): bool => (int)$row['sku_id'] === (int)$sku['id'])));
        }
        unset($sku);
        return $skus;
    }

    private static function legacyFields(array $combination): array
    {
        $legacy = [
            'quality_status' => '',
            'quality_label' => '',
            'specification_status' => '',
            'specification_label' => '',
        ];
        foreach ($combination as $item) {
            if ($item['dimension']['code'] === 'quality_status') {
                $legacy['quality_status'] = $item['value']['code'];
                $legacy['quality_label'] = $item['value']['name'];
            }
            if ($item['dimension']['code'] === 'weight_grade') {
                $legacy['specification_status'] = $item['value']['code'];
                $legacy['specification_label'] = $item['value']['name'];
            }
        }
        return $legacy;
    }

    private static function formatDefinition(array $row, int $referenceCount): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'code' => (string)($row['code'] ?? ''),
            'dimension_type' => self::typeOf($row),
            'status' => (int)($row['status'] ?? 1),
            'sort' => (int)($row['sort'] ?? 0),
            'reference_count' => $referenceCount,
            'is_referenced' => $referenceCount > 0,
        ];
    }

    private static function formatValue(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'dimension_id' => (int)($row['spec_id'] ?? 0),
            'goods_id' => (int)($row['goods_id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'code' => (string)($row['code'] ?? ''),
            'status' => (int)($row['status'] ?? 1),
            'sort' => (int)($row['sort'] ?? 0),
        ];
    }

    private static function definitionReferenceCount(int $id): int
    {
        $valueReferences = (int)GoodsSpecValue::where('tenant_id', self::tenantId())
            ->where('spec_id', $id)
            ->where('goods_id', '>', 0)
            ->count();
        $skuReferences = (int)GoodsSkuSpecValue::where('tenant_id', self::tenantId())
            ->where('spec_id', $id)
            ->count();
        $settingReferences = (int)Db::name('goods_dimension_setting')
            ->where('tenant_id', self::tenantId())
            ->where('spec_id', $id)
            ->count();
        return max($valueReferences, $skuReferences, $settingReferences);
    }

    private static function normalizeCode(string $code, string $name, string $prefix): string
    {
        $code = strtolower(trim($code));
        $code = trim((string)preg_replace('/[^a-z0-9_]+/', '_', $code), '_');
        if ($code !== '') {
            return substr($code, 0, 50);
        }
        return $prefix . '_' . substr(sha1($name), 0, 12);
    }

    private static function typeOf(array $row): string
    {
        return ($row['dimension_type'] ?? self::TYPE_SKU) === self::TYPE_DESCRIPTIVE
            ? self::TYPE_DESCRIPTIVE
            : self::TYPE_SKU;
    }

    private static function usageModeOf(array $setting, array $definition): string
    {
        $usageMode = (string)($setting['usage_mode'] ?? '');
        return in_array($usageMode, [self::TYPE_SKU, self::TYPE_DESCRIPTIVE], true)
            ? $usageMode
            : self::typeOf($definition);
    }

    private static function normalizeUsageMode(string $usageMode): string|false
    {
        $usageMode = strtolower(trim($usageMode));
        if (in_array($usageMode, [self::TYPE_SKU, self::TYPE_DESCRIPTIVE, self::TYPE_UNUSED], true)) {
            return $usageMode;
        }
        self::setError('商品维度用途无效');
        return false;
    }

    private static function baseSkuCode(int $goodsId): string
    {
        return 'SKU-' . $goodsId . '-' . self::BASE_SKU_CODE_SUFFIX;
    }

    private static function skuIdentity(array $combination): string
    {
        $parts = array_map(static fn(array $item): array => [
            'dimension_id' => (int)$item['dimension']['id'],
            'value_id' => (int)$item['value']['id'],
        ], $combination);
        usort($parts, static fn(array $left, array $right): int =>
            [$left['dimension_id'], $left['value_id']] <=> [$right['dimension_id'], $right['value_id']]
        );
        return implode('|', array_map(
            static fn(array $part): string => $part['dimension_id'] . '=' . $part['value_id'],
            $parts
        ));
    }

    private static function compareDimensions(array $left, array $right): int
    {
        return [(int)($left['sort'] ?? 0), (int)($left['id'] ?? 0), (string)($left['code'] ?? '')]
            <=> [(int)($right['sort'] ?? 0), (int)($right['id'] ?? 0), (string)($right['code'] ?? '')];
    }

    private static function assertCombinationCapacity(array $dimensions): bool
    {
        $combinationCount = 1;
        foreach ($dimensions as $dimension) {
            $valueCount = count($dimension['values'] ?? []);
            if ($valueCount === 0 || $combinationCount > intdiv(self::MAX_SKU_COMBINATIONS, $valueCount)) {
                self::setError('SKU组合总数不能超过' . self::MAX_SKU_COMBINATIONS . '个');
                return false;
            }
            $combinationCount *= $valueCount;
        }
        return true;
    }

    private static function goodsExists(int $goodsId): bool
    {
        return $goodsId > 0 && Goods::where('tenant_id', self::tenantId())->where('id', $goodsId)->count() > 0;
    }

    private static function canMaintain(): bool
    {
        if (GoodsMaintenancePermissionService::canMaintain()) {
            return true;
        }
        self::setError('当前账号没有商品维护权限');
        return false;
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
