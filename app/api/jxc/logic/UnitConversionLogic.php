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
    protected const SCOPE_GOODS_DAILY = 'goods_daily';
    protected const SCOPE_SUPPLIER_SKU_DAILY = 'supplier_sku_daily';

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

        $scope = self::normalizeSaveScope($params['scope'] ?? '');
        if ($scope === false) {
            return false;
        }

        $goodsId = (int)($params['goods_id'] ?? 0);
        $items = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (!isset($rule['goods_id']) && $goodsId > 0) {
                $rule['goods_id'] = $goodsId;
            }
            $data = self::buildRuleData($rule);
            if ($data === false) {
                return false;
            }
            $items[] = [
                'id' => (int)($rule['id'] ?? 0),
                'data' => $data,
            ];
        }

        if ($scope === '') {
            $scope = self::inferSaveScope($items);
        }

        $deleteGoodsId = self::resolveDeleteGoodsId($goodsId, $items, $scope);
        if ($deleteGoodsId === false) {
            return false;
        }

        if ($scope !== '' && !self::validateScopeItems($scope, $items, $deleteGoodsId)) {
            return false;
        }

        Db::startTrans();
        try {
            $keptIds = [];
            foreach ($items as $item) {
                $id = self::saveRuleItem($item['id'], $item['data'], $scope, $deleteGoodsId);
                if ($id === false) {
                    Db::rollback();
                    return false;
                }
                $keptIds[] = $id;
            }

            if ($deleteGoodsId > 0) {
                self::deleteMissingRules($deleteGoodsId, $keptIds, $scope);
            }
            Db::commit();
            return self::lists($params);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function saveRuleItem(int $id, array $data, string $scope, int $deleteGoodsId): int|false
    {
        $model = null;
        if ($id > 0) {
            $model = GoodsUnitConversionRule::where('id', $id)
                ->where('tenant_id', self::tenantId())
                ->findOrEmpty();
            if ($model->isEmpty()) {
                self::setError('换算规则不存在');
                return false;
            }
            $row = $model->toArray();
            if ($scope !== '' && ((int)($row['goods_id'] ?? 0) !== $deleteGoodsId || self::scopeFromData($row) !== $scope)) {
                self::setError('换算规则与保存范围不匹配');
                return false;
            }
        }

        $logicalModel = self::findByLogicalKey($data, $id);
        if (!$logicalModel->isEmpty()) {
            $model = $logicalModel;
        }

        if ($model === null) {
            $model = GoodsUnitConversionRule::create($data);
        } else {
            $model->save($data);
        }

        return (int)$model->id;
    }

    protected static function deleteMissingRules(int $goodsId, array $keptIds, string $scope): void
    {
        $query = GoodsUnitConversionRule::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId);
        self::applyScopeQuery($query, $scope);
        if ($keptIds !== []) {
            $query->whereNotIn('id', $keptIds);
        }
        $query->delete();
    }

    protected static function normalizeSaveScope(mixed $scope): string|false
    {
        $scope = trim((string)$scope);
        if ($scope === '') {
            return '';
        }
        if (in_array($scope, [self::SCOPE_GOODS_DAILY, self::SCOPE_SUPPLIER_SKU_DAILY], true)) {
            return $scope;
        }
        self::setError('保存范围错误');
        return false;
    }

    protected static function inferSaveScope(array $items): string
    {
        $scope = '';
        foreach ($items as $item) {
            $itemScope = self::scopeFromData($item['data']);
            if (!in_array($itemScope, [self::SCOPE_GOODS_DAILY, self::SCOPE_SUPPLIER_SKU_DAILY], true)) {
                return '';
            }
            if ($scope === '') {
                $scope = $itemScope;
                continue;
            }
            if ($scope !== $itemScope) {
                return '';
            }
        }
        return $scope;
    }

    protected static function resolveDeleteGoodsId(int $goodsId, array $items, string $scope): int|false
    {
        if ($scope === '') {
            return $goodsId;
        }

        $deleteGoodsId = $goodsId;
        foreach ($items as $item) {
            $itemGoodsId = (int)$item['data']['goods_id'];
            if ($itemGoodsId <= 0) {
                self::setError('请选择商品');
                return false;
            }
            if ($deleteGoodsId <= 0) {
                $deleteGoodsId = $itemGoodsId;
                continue;
            }
            if ($deleteGoodsId !== $itemGoodsId) {
                self::setError('换算规则商品与保存商品不一致');
                return false;
            }
        }

        if ($deleteGoodsId <= 0) {
            self::setError('请选择商品');
            return false;
        }
        return $deleteGoodsId;
    }

    protected static function validateScopeItems(string $scope, array $items, int $goodsId): bool
    {
        foreach ($items as $item) {
            $data = $item['data'];
            if ((int)$data['goods_id'] !== $goodsId || self::scopeFromData($data) !== $scope) {
                self::setError('换算规则与保存范围不匹配');
                return false;
            }
        }
        return true;
    }

    protected static function scopeFromData(array $data): string
    {
        $goodsId = (int)($data['goods_id'] ?? 0);
        $skuId = (int)($data['sku_id'] ?? 0);
        $supplierId = (int)($data['supplier_id'] ?? 0);

        if ($goodsId > 0 && $skuId === 0 && $supplierId === 0) {
            return self::SCOPE_GOODS_DAILY;
        }
        if ($goodsId > 0 && $skuId > 0 && $supplierId > 0) {
            return self::SCOPE_SUPPLIER_SKU_DAILY;
        }
        return '';
    }

    protected static function applyScopeQuery($query, string $scope): void
    {
        if ($scope === self::SCOPE_GOODS_DAILY) {
            $query->where('sku_id', 0)
                ->where('supplier_id', 0);
            return;
        }
        if ($scope === self::SCOPE_SUPPLIER_SKU_DAILY) {
            $query->where('sku_id', '>', 0)
                ->where('supplier_id', '>', 0);
        }
    }

    protected static function findByLogicalKey(array $data, int $excludeId = 0)
    {
        $query = GoodsUnitConversionRule::where('tenant_id', self::tenantId())
            ->where('goods_id', (int)$data['goods_id'])
            ->where('sku_id', (int)$data['sku_id'])
            ->where('supplier_id', (int)$data['supplier_id'])
            ->where('from_unit_id', (int)$data['from_unit_id'])
            ->where('to_unit_id', (int)$data['to_unit_id']);
        self::whereNullable($query, 'effective_date', $data['effective_date'] ?? null);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        return $query->order(['id' => 'desc'])->findOrEmpty();
    }

    protected static function whereNullable($query, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            $query->whereNull($field);
            return;
        }
        $query->where($field, $value);
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
            'scope' => self::formatScope($item),
            'effective_date' => $item['effective_date'] ?? null,
            'expire_date' => $item['expire_date'] ?? null,
            'status' => (int)($item['status'] ?? 1),
            'remark' => (string)($item['remark'] ?? ''),
        ];
    }

    protected static function formatScope(array $item): string
    {
        $goodsId = (int)($item['goods_id'] ?? 0);
        $skuId = (int)($item['sku_id'] ?? 0);
        $supplierId = (int)($item['supplier_id'] ?? 0);

        if ($goodsId === 0 && $skuId === 0 && $supplierId === 0) {
            return 'tenant_default';
        }
        if ($goodsId > 0 && $skuId === 0 && $supplierId === 0) {
            return self::SCOPE_GOODS_DAILY;
        }
        if ($goodsId > 0 && $skuId > 0 && $supplierId === 0) {
            return 'sku_daily';
        }
        if ($goodsId > 0 && $skuId > 0 && $supplierId > 0) {
            return self::SCOPE_SUPPLIER_SKU_DAILY;
        }
        return '';
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
