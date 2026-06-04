<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSku;
use app\common\model\jxc\GoodsUnit;
use app\common\model\jxc\GoodsUnitConversionRule;
use app\common\model\jxc\Vendor;
use think\facade\Db;

class UnitConversionLogic extends BaseLogic
{
    public static function lists(array $params): array
    {
        $query = GoodsUnitConversionRule::where('tenant_id', self::tenantId());

        foreach (['goods_id', 'sku_id', 'supplier_id', 'from_unit_id', 'to_unit_id', 'status'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $query->where($field, (int)$params[$field]);
            }
        }

        $rows = $query->order(['goods_id' => 'desc', 'sku_id' => 'desc', 'supplier_id' => 'desc', 'effective_date' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();

        return array_map([self::class, 'formatRule'], $rows);
    }

    public static function saveRules(array $params): array|false
    {
        $rules = $params['rules'] ?? $params['conversions'] ?? [];
        if (!is_array($rules)) {
            self::setError('换算规则格式错误');
            return false;
        }

        Db::startTrans();
        try {
            $keptIds = [];
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $data = self::buildRuleData($rule);
                if ($data === false) {
                    Db::rollback();
                    return false;
                }

                $id = (int)($rule['id'] ?? 0);
                if ($id > 0) {
                    $model = GoodsUnitConversionRule::where('id', $id)
                        ->where('tenant_id', self::tenantId())
                        ->findOrEmpty();
                    if ($model->isEmpty()) {
                        self::setError('换算规则不存在');
                        Db::rollback();
                        return false;
                    }
                    $model->save($data);
                    $keptIds[] = (int)$model->id;
                } else {
                    $model = GoodsUnitConversionRule::create($data);
                    $keptIds[] = (int)$model->id;
                }
            }

            $goodsId = (int)($params['goods_id'] ?? 0);
            if ($goodsId > 0) {
                self::deleteMissingRules($goodsId, $keptIds);
            }
            Db::commit();
            return self::lists($params);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function deleteMissingRules(int $goodsId, array $keptIds): void
    {
        $query = GoodsUnitConversionRule::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId);
        if ($keptIds !== []) {
            $query->whereNotIn('id', $keptIds);
        }
        $query->delete();
    }

    public static function delete(array $params): bool
    {
        $model = GoodsUnitConversionRule::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('换算规则不存在');
            return false;
        }
        return (bool)$model->delete();
    }

    public static function resolve(array $params): array|false
    {
        $result = self::resolveData(
            (int)($params['goods_id'] ?? 0),
            (int)($params['sku_id'] ?? 0),
            (int)($params['supplier_id'] ?? 0),
            (int)($params['from_unit_id'] ?? 0),
            (int)($params['to_unit_id'] ?? 0),
            (string)($params['date'] ?? $params['effective_date'] ?? '')
        );

        if ($result === false) {
            return false;
        }
        return $result;
    }

    public static function resolveData(
        int $goodsId,
        int $skuId,
        int $supplierId,
        int $fromUnitId,
        int $toUnitId,
        string $date = ''
    ): array|false {
        if ($fromUnitId > 0 && $toUnitId > 0 && $fromUnitId === $toUnitId) {
            return self::identityRule($fromUnitId, $toUnitId);
        }

        $targetDate = self::normalizeDate($date);
        $candidates = [
            ['supplier_sku_daily', $goodsId, $skuId, $supplierId],
            ['sku_daily', $goodsId, $skuId, 0],
            ['goods_daily', $goodsId, 0, 0],
            ['tenant_default', 0, 0, 0],
        ];

        foreach ($candidates as [$sourceType, $candidateGoodsId, $candidateSkuId, $candidateSupplierId]) {
            if ($sourceType === 'supplier_sku_daily' && ($candidateGoodsId <= 0 || $candidateSkuId <= 0 || $candidateSupplierId <= 0)) {
                continue;
            }
            if ($sourceType === 'sku_daily' && ($candidateGoodsId <= 0 || $candidateSkuId <= 0)) {
                continue;
            }
            if ($sourceType === 'goods_daily' && $candidateGoodsId <= 0) {
                continue;
            }

            $rule = GoodsUnitConversionRule::where('tenant_id', self::tenantId())
                ->where('goods_id', $candidateGoodsId)
                ->where('sku_id', $candidateSkuId)
                ->where('supplier_id', $candidateSupplierId)
                ->where('from_unit_id', $fromUnitId)
                ->where('to_unit_id', $toUnitId)
                ->where('status', 1)
                ->where(function ($query) use ($targetDate) {
                    $query->whereNull('effective_date')
                        ->whereOr('effective_date', '<=', $targetDate);
                })
                ->where(function ($query) use ($targetDate) {
                    $query->whereNull('expire_date')
                        ->whereOr('expire_date', '>=', $targetDate);
                })
                ->order(['effective_date' => 'desc', 'id' => 'desc'])
                ->findOrEmpty();

            if (!$rule->isEmpty()) {
                $item = self::formatRule($rule->toArray());
                $item['source_type'] = $sourceType;
                return $item;
            }
        }

        self::setError('未找到有效单位换算规则');
        return false;
    }

    protected static function buildRuleData(array $rule): array|false
    {
        $goodsId = (int)($rule['goods_id'] ?? 0);
        $skuId = (int)($rule['sku_id'] ?? 0);
        $supplierId = (int)($rule['supplier_id'] ?? 0);
        $fromUnitId = (int)($rule['from_unit_id'] ?? $rule['source_unit_id'] ?? 0);
        $toUnitId = (int)($rule['to_unit_id'] ?? $rule['target_unit_id'] ?? 0);
        $ratio = (float)($rule['ratio'] ?? $rule['conversion_rate'] ?? 0);

        if ($ratio <= 0) {
            self::setError('换算比例必须大于0');
            return false;
        }
        if ($fromUnitId <= 0 || $toUnitId <= 0) {
            self::setError('请选择换算单位');
            return false;
        }
        if ($goodsId > 0 && !self::existsInTenant(Goods::class, $goodsId)) {
            self::setError('商品不存在');
            return false;
        }
        if ($skuId > 0 && !self::existsInTenant(GoodsSku::class, $skuId, $goodsId)) {
            self::setError('SKU不存在');
            return false;
        }
        if ($supplierId > 0 && !self::existsInTenant(Vendor::class, $supplierId)) {
            self::setError('供应商不存在');
            return false;
        }

        $fromUnit = self::unitName($fromUnitId, (string)($rule['from_unit_name'] ?? $rule['source_unit_name'] ?? ''));
        $toUnit = self::unitName($toUnitId, (string)($rule['to_unit_name'] ?? $rule['target_unit_name'] ?? ''));

        return [
            'tenant_id' => self::tenantId(),
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'supplier_id' => $supplierId,
            'from_unit_id' => $fromUnitId,
            'from_unit_name' => $fromUnit,
            'to_unit_id' => $toUnitId,
            'to_unit_name' => $toUnit,
            'ratio' => number_format($ratio, 6, '.', ''),
            'effective_date' => self::nullableDate($rule['effective_date'] ?? $rule['date'] ?? null),
            'expire_date' => self::nullableDate($rule['expire_date'] ?? null),
            'status' => (int)($rule['status'] ?? 1) === 0 ? 0 : 1,
            'remark' => trim((string)($rule['remark'] ?? '')),
            'create_time' => time(),
            'update_time' => time(),
        ];
    }

    public static function formatRule(array $item): array
    {
        return [
            'id' => (int)($item['id'] ?? 0),
            'goods_id' => (int)($item['goods_id'] ?? 0),
            'sku_id' => (int)($item['sku_id'] ?? 0),
            'supplier_id' => (int)($item['supplier_id'] ?? 0),
            'from_unit_id' => (int)($item['from_unit_id'] ?? 0),
            'from_unit_name' => (string)($item['from_unit_name'] ?? ''),
            'from_unit' => (string)($item['from_unit_name'] ?? ''),
            'to_unit_id' => (int)($item['to_unit_id'] ?? 0),
            'to_unit_name' => (string)($item['to_unit_name'] ?? ''),
            'to_unit' => (string)($item['to_unit_name'] ?? ''),
            'ratio' => (string)($item['ratio'] ?? '0.000000'),
            'conversion_rate' => (string)($item['ratio'] ?? '0.000000'),
            'source_type' => (string)($item['source_type'] ?? ''),
            'effective_date' => $item['effective_date'] ?? null,
            'expire_date' => $item['expire_date'] ?? null,
            'status' => (int)($item['status'] ?? 1),
            'remark' => (string)($item['remark'] ?? ''),
        ];
    }

    protected static function existsInTenant(string $modelClass, int $id, int $goodsId = 0): bool
    {
        $query = $modelClass::where('id', $id)->where('tenant_id', self::tenantId());
        if ($goodsId > 0 && $modelClass === GoodsSku::class) {
            $query->where('goods_id', $goodsId);
        }
        return $query->count() > 0;
    }

    protected static function identityRule(int $fromUnitId, int $toUnitId): array
    {
        $name = self::unitName($fromUnitId, '');
        return [
            'id' => 0,
            'from_unit_id' => $fromUnitId,
            'from_unit_name' => $name,
            'from_unit' => $name,
            'to_unit_id' => $toUnitId,
            'to_unit_name' => $name,
            'to_unit' => $name,
            'ratio' => '1.000000',
            'conversion_rate' => '1.000000',
            'source_type' => 'identity',
            'effective_date' => null,
            'expire_date' => null,
            'status' => 1,
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

    protected static function normalizeDate(string $date): string
    {
        if ($date === '') {
            return date('Y-m-d');
        }
        if (is_numeric($date)) {
            return date('Y-m-d', (int)$date);
        }
        return substr($date, 0, 10);
    }

    protected static function nullableDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        return self::normalizeDate((string)$date);
    }

    protected static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
