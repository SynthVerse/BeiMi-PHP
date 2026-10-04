<?php

namespace app\common\service\cloud;

use app\api\jxc\logic\GoodsDimensionLogic;
use app\common\model\jxc\GoodsSpec;
use think\facade\Db;

/** 云端公共商品（tenant_id=0）的通用维度快照与租户导入。 */
class CloudGoodsDimensionService
{
    /** @return array{dimensions: array<int,array<string,mixed>>, combinations: array<int,array<string,mixed>>} */
    public static function snapshot(int $cloudGoodsId): array
    {
        $values = Db::name('goods_spec_value')
            ->where('tenant_id', 0)
            ->where('goods_id', $cloudGoodsId)
            ->where('status', 1)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();
        if ($values === []) {
            return ['dimensions' => [], 'combinations' => []];
        }

        $specIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['spec_id'],
            $values
        )));
        $definitions = Db::name('goods_spec')
            ->where('tenant_id', 0)
            ->whereIn('id', $specIds)
            ->where('status', 1)
            ->select()
            ->toArray();
        $settings = Db::name('goods_dimension_setting')
            ->where('tenant_id', 0)
            ->where('goods_id', $cloudGoodsId)
            ->whereIn('spec_id', $specIds)
            ->select()
            ->toArray();
        $settingsBySpecId = [];
        foreach ($settings as $setting) {
            $settingsBySpecId[(int)$setting['spec_id']] = $setting;
        }

        $dimensions = [];
        foreach ($definitions as $definition) {
            $specId = (int)$definition['id'];
            $dimensionValues = array_values(array_map(
                static fn(array $value): array => [
                    'name' => (string)$value['name'],
                    'code' => (string)$value['code'],
                    'sort' => (int)$value['sort'],
                ],
                array_filter($values, static fn(array $value): bool => (int)$value['spec_id'] === $specId)
            ));
            if ($dimensionValues === []) {
                continue;
            }
            $setting = $settingsBySpecId[$specId] ?? [];
            $usageMode = (string)($setting['usage_mode'] ?? $definition['dimension_type'] ?? 'sku');
            if (!in_array($usageMode, [GoodsDimensionLogic::TYPE_SKU, GoodsDimensionLogic::TYPE_DESCRIPTIVE], true)) {
                $usageMode = GoodsDimensionLogic::TYPE_SKU;
            }
            $dimensions[] = [
                'source_id' => $specId,
                'name' => (string)$definition['name'],
                'code' => (string)$definition['code'],
                'dimension_type' => $usageMode,
                'usage_mode' => $usageMode,
                'sort' => (int)($setting['sort'] ?? $definition['sort'] ?? 0),
                'values' => $dimensionValues,
            ];
        }
        usort($dimensions, static fn(array $left, array $right): int => [$left['sort'], $left['source_id']] <=> [$right['sort'], $right['source_id']]);

        $skuDimensions = array_values(array_filter(
            $dimensions,
            static fn(array $dimension): bool => $dimension['usage_mode'] === GoodsDimensionLogic::TYPE_SKU
        ));
        return [
            'dimensions' => $dimensions,
            'combinations' => self::enabledCombinations($cloudGoodsId, $skuDimensions, $values),
        ];
    }

    /** @param array{dimensions?: array<int,array<string,mixed>>, combinations?: array<int,array<string,mixed>>} $snapshot */
    public static function importToTenant(array $snapshot, int $tenantId, int $goodsId): void
    {
        $dimensions = array_values($snapshot['dimensions'] ?? []);
        if ($dimensions === []) {
            return;
        }

        $payloadDimensions = [];
        $hasSkuDimension = false;
        foreach ($dimensions as $dimension) {
            $code = trim((string)($dimension['code'] ?? ''));
            if ($code === '') {
                throw new \RuntimeException('云端商品包含无效的维度编码');
            }
            $definition = GoodsSpec::where('tenant_id', $tenantId)->where('code', $code)->findOrEmpty();
            if ($definition->isEmpty()) {
                $definition = GoodsSpec::create([
                    'tenant_id' => $tenantId,
                    'template_id' => 0,
                    'name' => (string)($dimension['name'] ?? $code),
                    'code' => $code,
                    'dimension_type' => (string)($dimension['dimension_type'] ?? GoodsDimensionLogic::TYPE_SKU),
                    'status' => 1,
                    'sort' => (int)($dimension['sort'] ?? 0),
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }
            $usageMode = (string)($dimension['usage_mode'] ?? $dimension['dimension_type'] ?? GoodsDimensionLogic::TYPE_SKU);
            $hasSkuDimension = $hasSkuDimension || $usageMode === GoodsDimensionLogic::TYPE_SKU;
            $payloadDimensions[] = [
                'dimension_id' => (int)$definition->id,
                'usage_mode' => $usageMode,
                'sort' => (int)($dimension['sort'] ?? 0),
                'values' => array_values($dimension['values'] ?? []),
            ];
        }

        $combinations = array_values($snapshot['combinations'] ?? []);
        if ($hasSkuDimension && $combinations === []) {
            throw new \RuntimeException('云端商品的SKU维度没有已启用组合');
        }
        $params = [
            'goods_id' => $goodsId,
            'dimensions' => $payloadDimensions,
        ];
        if ($hasSkuDimension) {
            $params['combinations'] = $combinations;
        }
        $result = GoodsDimensionLogic::saveProductDimensions($params);
        if ($result === false) {
            throw new \RuntimeException(GoodsDimensionLogic::getError());
        }
    }

    /**
     * @param array<int,array<string,mixed>> $skuDimensions
     * @param array<int,array<string,mixed>> $values
     * @return array<int,array{values: array<string,string>, enabled: int}>
     */
    private static function enabledCombinations(int $cloudGoodsId, array $skuDimensions, array $values): array
    {
        if ($skuDimensions === []) {
            return [];
        }
        $skuIds = array_map('intval', Db::name('goods_sku')
            ->where('tenant_id', 0)
            ->where('goods_id', $cloudGoodsId)
            ->where('status', 1)
            ->where('purchase_status', 1)
            ->where('sale_status', 1)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->column('id'));
        if ($skuIds === []) {
            return [];
        }
        $relations = Db::name('goods_sku_spec_value')
            ->where('tenant_id', 0)
            ->where('goods_id', $cloudGoodsId)
            ->whereIn('sku_id', $skuIds)
            ->select()
            ->toArray();
        $valueById = [];
        foreach ($values as $value) {
            $valueById[(int)$value['id']] = $value;
        }
        $dimensionBySourceId = [];
        foreach ($skuDimensions as $dimension) {
            $dimensionBySourceId[(int)$dimension['source_id']] = $dimension;
        }

        $combinations = [];
        $seen = [];
        foreach ($skuIds as $skuId) {
            $codes = [];
            foreach ($relations as $relation) {
                if ((int)$relation['sku_id'] !== $skuId) {
                    continue;
                }
                $dimension = $dimensionBySourceId[(int)$relation['spec_id']] ?? null;
                $value = $valueById[(int)$relation['spec_value_id']] ?? null;
                if ($dimension !== null && $value !== null) {
                    $codes[(string)$dimension['code']] = (string)$value['code'];
                }
            }
            if (count($codes) !== count($skuDimensions)) {
                continue;
            }
            ksort($codes);
            $identity = json_encode($codes, JSON_UNESCAPED_UNICODE);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $combinations[] = ['values' => $codes, 'enabled' => 1];
        }
        return $combinations;
    }
}
