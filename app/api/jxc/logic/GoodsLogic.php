<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Customer;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSupplier;
use app\common\model\jxc\GoodsSku;
use app\common\model\jxc\GoodsUnit;
use app\common\model\jxc\GoodsUnitsBinding;
use app\common\model\cloud\CloudGoodsImport;
use app\common\model\jxc\GoodsSkuSpecValue;
use app\common\model\jxc\GoodsSpecValue;
use app\common\model\jxc\OrderGoods;
use app\common\model\jxc\Vendor;
use think\facade\Db;

class GoodsLogic extends BaseLogic
{
    public static function add(array $params): array|false
    {
        $saveData = self::buildSaveData($params);
        $boundUnits = self::resolveBoundUnitsForSave($params, $saveData);
        if ($boundUnits === false) {
            return false;
        }
        self::applyBaseUnitToSaveData($saveData, $boundUnits);
        if ((int)$saveData['tenant_id'] <= 0) {
            self::setError('商品租户上下文缺失，请重新登录');
            return false;
        }
        if (!self::assertUnique($saveData)) {
            return false;
        }
        if ((int)$saveData['primary_supplier_id'] > 0 && !self::assertSupplierInTenant((int)$saveData['primary_supplier_id'])) {
            return false;
        }

        Db::startTrans();
        try {
            $goods = Goods::create($saveData);
            self::syncBoundUnits((int)$goods->id, $boundUnits);
            if ((int)$saveData['primary_supplier_id'] > 0) {
                self::ensurePrimarySupplierRelation((int)$goods->id, (int)$saveData['primary_supplier_id']);
            }
            Db::commit();
            return [
                'id' => (int)$goods->id,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function edit(array $params): bool
    {
        $model = Goods::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }

        $saveData = self::buildSaveData($params, $model->toArray());
        $oldBaseUnitId = (int)($model->unit_id ?? 0);
        $oldBaseUnitName = (string)($model->units ?? '');
        $boundUnits = self::resolveBoundUnitsForSave($params, $saveData, (int)$model->id);
        if ($boundUnits === false) {
            return false;
        }
        self::applyBaseUnitToSaveData($saveData, $boundUnits);
        if ((int)$saveData['tenant_id'] <= 0) {
            self::setError('商品租户上下文缺失，请重新登录');
            return false;
        }
        if (!self::assertUnique($saveData, (int)$params['id'])) {
            return false;
        }
        if ((int)$saveData['primary_supplier_id'] > 0 && !self::assertSupplierInTenant((int)$saveData['primary_supplier_id'])) {
            return false;
        }

        Db::startTrans();
        try {
            $model->save($saveData);
            if ($boundUnits !== null) {
                self::syncBoundUnits((int)$model->id, $boundUnits);
            }
            self::syncInheritedSkuBaseUnit(
                (int)$model->id,
                $oldBaseUnitId,
                $oldBaseUnitName,
                (int)$saveData['unit_id'],
                (string)$saveData['units']
            );
            self::syncPrimarySupplierFlag((int)$model->id, (int)$saveData['primary_supplier_id']);
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function delete(array $params): bool
    {
        $model = Goods::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }

        $orderGoodsCount = OrderGoods::where('goods_id', (int)$model->id)
            ->where('tenant_id', self::tenantId())
            ->count();
        if ($orderGoodsCount > 0) {
            self::setError('该商品已被订单明细使用，无法直接删除，请使用归档功能');
            return false;
        }

        Db::startTrans();
        try {
            GoodsSupplier::where('goods_id', (int)$model->id)
                ->where('tenant_id', self::tenantId())
                ->delete();
            GoodsUnitsBinding::where('goods_id', (int)$model->id)
                ->where('tenant_id', self::tenantId())
                ->delete();
            GoodsSku::where('goods_id', (int)$model->id)
                ->where('tenant_id', self::tenantId())
                ->delete();
            // 清除云端商品导入记录，允许重新从云端加载
            CloudGoodsImport::where('goods_id', (int)$model->id)
                ->where('tenant_id', self::tenantId())
                ->delete();
            // 清理SKU规格值映射
            GoodsSkuSpecValue::where('goods_id', (int)$model->id)
                ->where('tenant_id', self::tenantId())
                ->delete();
            // 清理品质/规格值
            GoodsSpecValue::where('goods_id', (int)$model->id)
                ->where('tenant_id', self::tenantId())
                ->delete();
            $model->delete();
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function archive(array $params): bool
    {
        $model = Goods::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }
        if ((int)$model->is_archived === 1) {
            self::setError('该商品已处于归档状态');
            return false;
        }
        try {
            $model->save(['is_archived' => 1]);
            return true;
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function unarchive(array $params): bool
    {
        $model = Goods::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }
        if ((int)$model->is_archived === 0) {
            self::setError('该商品未处于归档状态');
            return false;
        }
        try {
            $model->save(['is_archived' => 0]);
            return true;
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function detail(array $params): array
    {
        $model = Goods::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            return [];
        }

        $item = self::formatItem($model->toArray());
        $supplierData = self::supplierList(['id' => (int)$item['id']]);
        $item['primary_supplier'] = $supplierData['primary_supplier'] ?? null;
        $item['suppliers'] = $supplierData['suppliers'] ?? [];
        $item['supplier_count'] = $supplierData['supplier_count'] ?? 0;
        $item['skus'] = GoodsSkuLogic::lists(['goods_id' => (int)$item['id']]);
        $item['sku_count'] = count($item['skus']);
        $item['supplier_matrix'] = GoodsSupplierMatrixLogic::lists(['goods_id' => (int)$item['id']]);
        $item['bound_units'] = self::boundUnitsForGoods((int)$item['id'], $item);
        $item['recent_supply_orders'] = self::recentSupplyOrders((int)$item['id']);
        $item['stats'] = self::purchaseStats((int)$item['id'], (int)$item['supplier_count']);
        return $item;
    }

    public static function recommendations(array $params): array
    {
        $limit = self::clampInt($params['limit'] ?? 20, 1, 50);
        $hotDays = self::clampInt($params['hot_days'] ?? 90, 1, 365);
        $customerId = (int)($params['customer_id'] ?? 0);

        return [
            'customer_recent' => $customerId > 0
                ? self::customerRecentRecommendations($customerId, $limit)
                : [],
            'hot' => $customerId > 0 ? [] : self::hotSalesRecommendations($limit, $hotDays),
        ];
    }

    public static function unitsBinding(array $params): array
    {
        $goodsId = (int)($params['id'] ?? $params['goods_id'] ?? 0);
        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($goods->isEmpty()) {
            return [];
        }

        return self::boundUnitsForGoods($goodsId, self::formatItem($goods->toArray()));
    }

    public static function supplierList(array $params): array
    {
        $goodsId = (int)($params['id'] ?? $params['goods_id'] ?? 0);
        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($goods->isEmpty()) {
            return [
                'primary_supplier' => null,
                'suppliers' => [],
                'supplier_count' => 0,
            ];
        }

        $rows = GoodsSupplier::where('goods_id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->where('sku_id', 0)
            ->order(['is_primary' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();

        $suppliers = self::formatSupplierRelations($rows);
        $primarySupplierId = (int)($goods->primary_supplier_id ?? 0);
        $primary = null;
        foreach ($suppliers as $supplier) {
            if ((int)$supplier['supplier_id'] === $primarySupplierId || (int)$supplier['is_primary'] === 1) {
                $primary = $supplier;
                break;
            }
        }

        return [
            'primary_supplier' => $primary,
            'suppliers' => $suppliers,
            'supplier_count' => count($suppliers),
        ];
    }

    public static function saveSuppliers(array $params): array|false
    {
        $goodsId = (int)($params['id'] ?? $params['goods_id'] ?? 0);
        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($goods->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }

        $supplierRows = self::normalizeSupplierRows($params['suppliers'] ?? []);
        if ($supplierRows === false) {
            return false;
        }

        $primarySupplierId = (int)($params['primary_supplier_id'] ?? 0);
        if ($primarySupplierId <= 0) {
            foreach ($supplierRows as $row) {
                if ((int)$row['is_primary'] === 1) {
                    $primarySupplierId = (int)$row['supplier_id'];
                    break;
                }
            }
        }
        if ($primarySupplierId <= 0 && count($supplierRows) > 0) {
            $primarySupplierId = (int)$supplierRows[0]['supplier_id'];
        }

        $supplierIds = array_map(static fn($row) => (int)$row['supplier_id'], $supplierRows);
        if ($primarySupplierId > 0 && !in_array($primarySupplierId, $supplierIds, true)) {
            $supplierRows[] = [
                'supplier_id' => $primarySupplierId,
                'is_primary' => 1,
                'supplier_product_code' => '',
                'purchase_price' => '0.00',
                'min_purchase_qty' => '0.0000',
                'lead_time_days' => 0,
                'status' => 1,
                'remark' => '',
            ];
        }

        foreach ($supplierRows as $row) {
            if (!self::assertSupplierInTenant((int)$row['supplier_id'])) {
                return false;
            }
        }

        Db::startTrans();
        try {
            GoodsSupplier::where('goods_id', $goodsId)
                ->where('tenant_id', self::tenantId())
                ->where('sku_id', 0)
                ->delete();

            foreach ($supplierRows as $row) {
                $row['tenant_id'] = self::tenantId();
                $row['goods_id'] = $goodsId;
                $row['sku_id'] = 0;
                $row['is_primary'] = (int)$row['supplier_id'] === $primarySupplierId ? 1 : 0;
                GoodsSupplier::create($row);
            }

            $goods->save(['primary_supplier_id' => $primarySupplierId]);
            Db::commit();
            return self::supplierList(['id' => $goodsId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function attachSupplierSummary(array $items): array
    {
        $formatted = array_map([self::class, 'formatItem'], $items);
        $goodsIds = array_values(array_filter(array_map(static fn($item) => (int)($item['id'] ?? 0), $formatted)));
        if ($goodsIds === []) {
            return $formatted;
        }

        $relationRows = GoodsSupplier::whereIn('goods_id', $goodsIds)
            ->where('tenant_id', self::tenantId())
            ->where('sku_id', 0)
            ->order(['is_primary' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
        $relationsByGoods = [];
        foreach (self::formatSupplierRelations($relationRows) as $relation) {
            $relationsByGoods[(int)$relation['goods_id']][] = $relation;
        }

        foreach ($formatted as &$item) {
            $relations = $relationsByGoods[(int)$item['id']] ?? [];
            $item['supplier_count'] = count($relations);
            $item['sku_count'] = (int)GoodsSku::where('tenant_id', self::tenantId())
                ->where('goods_id', (int)$item['id'])
                ->count();
            $item['primary_supplier'] = null;
            foreach ($relations as $relation) {
                if ((int)$relation['supplier_id'] === (int)$item['primary_supplier_id'] || (int)$relation['is_primary'] === 1) {
                    $item['primary_supplier'] = $relation;
                    break;
                }
            }
            $item['supplier_name'] = $item['primary_supplier']['supplier_name'] ?? '';
        }
        unset($item);

        $goodsIds = array_column($formatted, 'id');
        if (!empty($goodsIds)) {
            $usedIds = OrderGoods::where('tenant_id', self::tenantId())
                ->whereIn('goods_id', $goodsIds)
                ->group('goods_id')
                ->column('goods_id');
            foreach ($formatted as &$item) {
                $item['has_orders'] = in_array((int)$item['id'], $usedIds);
            }
            unset($item);
        }

        return $formatted;
    }

    public static function formatItem(array $item): array
    {
        $item['product_name'] = $item['name'] ?? '';
        $item['product_code'] = $item['product_code'] ?? '';
        $item['code'] = $item['product_code'];
        $item['barcode'] = $item['product_code'];
        $item['units'] = $item['units'] ?? '';
        $item['unit'] = $item['units'];
        $item['unit_id'] = (int)($item['unit_id'] ?? 0);
        $item['units_id'] = $item['unit_id'];
        $item['price'] = (string)($item['price'] ?? '0.00');
        $item['units_money'] = $item['price'];
        $item['cost'] = (string)($item['cost'] ?? '0.00');
        $item['purchase_price'] = $item['cost'];
        $item['stock'] = (string)($item['stock'] ?? '0.00');
        $item['category_id'] = (int)($item['category_id'] ?? 0);
        $item['primary_supplier_id'] = (int)($item['primary_supplier_id'] ?? 0);
        $item['is_disabled'] = (int)($item['is_disabled'] ?? 0);
        $item['status'] = $item['is_disabled'] === 1 ? 0 : 1;
        $item['remark'] = $item['remark'] ?? '';
        $item['supplier_count'] = (int)($item['supplier_count'] ?? 0);
        $item['primary_supplier'] = $item['primary_supplier'] ?? null;
        return $item;
    }

    public static function normalizeDisabledStatus(mixed $status): ?int
    {
        if ($status === '') {
            return null;
        }
        $value = is_string($status) ? strtolower(trim($status)) : $status;
        if (in_array($value, ['enabled', 'enable', 'normal', 'active', '1', 1, true], true)) {
            return 0;
        }
        if (in_array($value, ['disabled', 'disable', 'inactive', '0', 0, false], true)) {
            return 1;
        }
        return null;
    }

    protected static function buildSaveData(array $params, array $current = []): array
    {
        $name = trim((string)($params['name'] ?? $params['product_name'] ?? ($current['name'] ?? '')));
        $unitId = (int)($params['unit_id'] ?? $params['units_id'] ?? ($current['unit_id'] ?? 0));
        $units = trim((string)($params['units'] ?? $params['unit'] ?? ($current['units'] ?? '')));

        if ($unitId > 0 && $units === '') {
            $unit = GoodsUnit::findOrEmpty($unitId);
            if (!$unit->isEmpty()) {
                $units = (string)$unit->name;
            }
        }

        $isDisabled = $params['is_disabled'] ?? null;
        if ($isDisabled === null && array_key_exists('status', $params)) {
            $isDisabled = self::normalizeDisabledStatus($params['status']);
        }

        return [
            'tenant_id' => (int)(request()->tenantId ?? ($current['tenant_id'] ?? 0)),
            'name' => $name,
            'product_code' => trim((string)($params['product_code'] ?? ($current['product_code'] ?? ''))),
            'units' => $units,
            'unit_id' => $unitId,
            'price' => self::normalizeDecimal($params['price'] ?? $params['units_money'] ?? ($current['price'] ?? 0)),
            'cost' => self::normalizeDecimal($params['cost'] ?? $params['purchase_price'] ?? ($current['cost'] ?? 0)),
            'stock' => self::normalizeDecimal($params['stock'] ?? ($current['stock'] ?? 0)),
            'category_id' => (int)($params['category_id'] ?? ($current['category_id'] ?? 0)),
            'primary_supplier_id' => (int)($params['primary_supplier_id'] ?? $params['supplier_id'] ?? ($current['primary_supplier_id'] ?? 0)),
            'is_disabled' => (int)($isDisabled ?? ($current['is_disabled'] ?? 0)),
            'remark' => trim((string)($params['remark'] ?? ($current['remark'] ?? ''))),
        ];
    }

    protected static function assertUnique(array $data, int $ignoreId = 0): bool
    {
        $query = Goods::where('tenant_id', (int)$data['tenant_id'])
            ->where('name', $data['name'])
            ->where('unit_id', (int)$data['unit_id']);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('相同名称和单位的商品已存在');
            return false;
        }

        if ($data['product_code'] !== '') {
            $codeQuery = Goods::where('tenant_id', (int)$data['tenant_id'])
                ->where('product_code', $data['product_code']);
            if ($ignoreId > 0) {
                $codeQuery->where('id', '<>', $ignoreId);
            }
            if ($codeQuery->count() > 0) {
                self::setError('商品编码已存在');
                return false;
            }
        }

        return true;
    }

    protected static function resolveBoundUnitsForSave(array $params, array $saveData, int $goodsId = 0): array|null|false
    {
        if (array_key_exists('bound_units', $params)) {
            return self::normalizeBoundUnits($params['bound_units'], $saveData, true);
        }

        if ($goodsId > 0 && self::boundUnitRows($goodsId) !== []) {
            return null;
        }

        return self::fallbackBoundUnits($saveData);
    }

    protected static function normalizeBoundUnits(mixed $rows, array $fallback, bool $strict): array|false
    {
        if (!is_array($rows)) {
            self::setError('绑定单位格式错误');
            return false;
        }

        if ($rows === []) {
            return self::fallbackBoundUnits($fallback);
        }

        $normalized = [];
        $seen = [];
        $baseCount = 0;
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                if ($strict) {
                    self::setError('绑定单位格式错误');
                    return false;
                }
                continue;
            }

            $unitId = (int)($row['unit_id'] ?? $row['units_id'] ?? $row['id'] ?? 0);
            if ($unitId <= 0) {
                if ($strict) {
                    self::setError('请选择绑定单位');
                    return false;
                }
                continue;
            }
            if (isset($seen[$unitId])) {
                self::setError('绑定单位不能重复');
                return false;
            }

            $unit = GoodsUnit::where('id', $unitId)
                ->where('tenant_id', self::tenantId())
                ->findOrEmpty();
            if ($unit->isEmpty()) {
                self::setError('绑定单位不存在');
                return false;
            }

            $isBase = (int)($row['is_base_unit'] ?? 0) === 1 ? 1 : 0;
            if ($isBase === 1) {
                $baseCount++;
            }
            $seen[$unitId] = true;
            $normalized[] = [
                'unit_id' => $unitId,
                'unit_name' => (string)($row['unit_name'] ?? $row['name'] ?? $unit->name ?? ''),
                'is_base_unit' => $isBase,
                'sort' => (int)($row['sort'] ?? $index),
                'status' => (int)($row['status'] ?? 1) === 0 ? 0 : 1,
            ];
        }

        if ($normalized === []) {
            return self::fallbackBoundUnits($fallback);
        }
        if ($baseCount > 1) {
            self::setError('基础单位只能设置一个');
            return false;
        }
        if ($baseCount === 0) {
            $normalized[0]['is_base_unit'] = 1;
        }

        return $normalized;
    }

    protected static function fallbackBoundUnits(array $saveData): array
    {
        $unitId = (int)($saveData['unit_id'] ?? 0);
        if ($unitId <= 0) {
            return [];
        }

        $unitName = (string)($saveData['units'] ?? '');
        if ($unitName === '') {
            $unit = GoodsUnit::where('id', $unitId)
                ->where('tenant_id', self::tenantId())
                ->findOrEmpty();
            $unitName = $unit->isEmpty() ? '' : (string)$unit->name;
        }

        return [[
            'unit_id' => $unitId,
            'unit_name' => $unitName,
            'is_base_unit' => 1,
            'sort' => 0,
            'status' => 1,
        ]];
    }

    protected static function applyBaseUnitToSaveData(array &$saveData, ?array $boundUnits): void
    {
        if (!$boundUnits) {
            return;
        }

        foreach ($boundUnits as $row) {
            if ((int)($row['is_base_unit'] ?? 0) === 1) {
                $saveData['unit_id'] = (int)$row['unit_id'];
                $saveData['units'] = (string)$row['unit_name'];
                return;
            }
        }
    }

    protected static function syncBoundUnits(int $goodsId, ?array $boundUnits): void
    {
        if ($boundUnits === null) {
            return;
        }

        GoodsUnitsBinding::where('goods_id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->delete();

        $now = time();
        foreach ($boundUnits as $row) {
            GoodsUnitsBinding::create([
                'tenant_id' => self::tenantId(),
                'goods_id' => $goodsId,
                'unit_id' => (int)$row['unit_id'],
                'unit_name' => (string)$row['unit_name'],
                'is_base_unit' => (int)$row['is_base_unit'] === 1 ? 1 : 0,
                'sort' => (int)$row['sort'],
                'status' => (int)$row['status'] === 0 ? 0 : 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
    }

    protected static function boundUnitRows(int $goodsId): array
    {
        return GoodsUnitsBinding::where('goods_id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->order(['is_base_unit' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();
    }

    public static function boundUnitsForGoods(int $goodsId, array $fallbackItem = []): array
    {
        $rows = self::boundUnitRows($goodsId);
        if ($rows === [] && (int)($fallbackItem['unit_id'] ?? $fallbackItem['units_id'] ?? 0) > 0) {
            return [[
                'id' => 0,
                'goods_id' => $goodsId,
                'unit_id' => (int)($fallbackItem['unit_id'] ?? $fallbackItem['units_id'] ?? 0),
                'units_id' => (int)($fallbackItem['unit_id'] ?? $fallbackItem['units_id'] ?? 0),
                'unit_name' => (string)($fallbackItem['units'] ?? $fallbackItem['unit'] ?? ''),
                'name' => (string)($fallbackItem['units'] ?? $fallbackItem['unit'] ?? ''),
                'is_base_unit' => 1,
                'sort' => 0,
                'status' => 1,
            ]];
        }

        return array_map(static fn($row) => [
            'id' => (int)($row['id'] ?? 0),
            'goods_id' => (int)($row['goods_id'] ?? 0),
            'unit_id' => (int)($row['unit_id'] ?? 0),
            'units_id' => (int)($row['unit_id'] ?? 0),
            'unit_name' => (string)($row['unit_name'] ?? ''),
            'name' => (string)($row['unit_name'] ?? ''),
            'is_base_unit' => (int)($row['is_base_unit'] ?? 0),
            'sort' => (int)($row['sort'] ?? 0),
            'status' => (int)($row['status'] ?? 1),
        ], $rows);
    }

    protected static function syncInheritedSkuBaseUnit(
        int $goodsId,
        int $oldBaseUnitId,
        string $oldBaseUnitName,
        int $newBaseUnitId,
        string $newBaseUnitName
    ): void {
        if ($newBaseUnitId <= 0 || ($oldBaseUnitId === $newBaseUnitId && $oldBaseUnitName === $newBaseUnitName)) {
            return;
        }

        GoodsSku::where('goods_id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->where(function ($query) use ($oldBaseUnitId, $oldBaseUnitName) {
                $query->where('base_unit_id', 0);
                if ($oldBaseUnitId > 0) {
                    $query->whereOr('base_unit_id', $oldBaseUnitId);
                }
                if ($oldBaseUnitName !== '') {
                    $query->whereOr(function ($subQuery) use ($oldBaseUnitName) {
                        $subQuery->where('base_unit_id', 0)
                            ->where('base_unit_name', $oldBaseUnitName);
                    });
                }
            })
            ->update([
                'base_unit_id' => $newBaseUnitId,
                'base_unit_name' => $newBaseUnitName,
                'update_time' => time(),
            ]);
    }

    protected static function normalizeSupplierRows(mixed $rows): array|false
    {
        if (!is_array($rows)) {
            self::setError('供应商列表格式错误');
            return false;
        }

        $normalized = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $supplierId = (int)($row['supplier_id'] ?? $row['id'] ?? 0);
            if ($supplierId <= 0 || isset($seen[$supplierId])) {
                continue;
            }
            $seen[$supplierId] = true;
            $normalized[] = [
                'supplier_id' => $supplierId,
                'is_primary' => (int)($row['is_primary'] ?? 0) === 1 ? 1 : 0,
                'supplier_product_code' => trim((string)($row['supplier_product_code'] ?? '')),
                'purchase_price' => self::normalizeDecimal($row['purchase_price'] ?? 0),
                'min_purchase_qty' => number_format(max(0, (float)($row['min_purchase_qty'] ?? 0)), 4, '.', ''),
                'lead_time_days' => max(0, (int)($row['lead_time_days'] ?? 0)),
                'status' => (int)($row['status'] ?? 1) === 0 ? 0 : 1,
                'remark' => trim((string)($row['remark'] ?? '')),
            ];
        }

        return $normalized;
    }

    protected static function formatSupplierRelations(array $rows): array
    {
        $supplierIds = array_values(array_unique(array_filter(array_map(static fn($row) => (int)($row['supplier_id'] ?? 0), $rows))));
        $vendors = [];
        if ($supplierIds !== []) {
            $vendorRows = Vendor::whereIn('id', $supplierIds)
                ->where('tenant_id', self::tenantId())
                ->select()
                ->toArray();
            foreach ($vendorRows as $vendor) {
                $vendors[(int)$vendor['id']] = $vendor;
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $supplierId = (int)($row['supplier_id'] ?? 0);
            $vendor = $vendors[$supplierId] ?? [];
            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'goods_id' => (int)($row['goods_id'] ?? 0),
                'sku_id' => (int)($row['sku_id'] ?? 0),
                'supplier_id' => $supplierId,
                'supplier_name' => (string)($vendor['supplier_name'] ?? ''),
                'name' => (string)($vendor['supplier_name'] ?? ''),
                'contact' => (string)($vendor['contact'] ?? ''),
                'phone' => (string)($vendor['phone'] ?? ''),
                'is_primary' => (int)($row['is_primary'] ?? 0),
                'is_preferred' => (int)($row['is_preferred'] ?? $row['is_primary'] ?? 0),
                'supplier_product_code' => (string)($row['supplier_product_code'] ?? ''),
                'supplier_goods_name' => (string)($row['supplier_goods_name'] ?? ''),
                'purchase_price' => (string)($row['purchase_price'] ?? '0.00'),
                'purchase_unit_id' => (int)($row['purchase_unit_id'] ?? 0),
                'purchase_unit_name' => (string)($row['purchase_unit_name'] ?? ''),
                'settlement_unit_id' => (int)($row['settlement_unit_id'] ?? 0),
                'settlement_unit_name' => (string)($row['settlement_unit_name'] ?? ''),
                'min_purchase_qty' => (string)($row['min_purchase_qty'] ?? '0.0000'),
                'daily_capacity_qty' => (string)($row['daily_capacity_qty'] ?? '0.0000'),
                'lead_time_days' => (int)($row['lead_time_days'] ?? 0),
                'last_purchase_price' => (string)($row['last_purchase_price'] ?? '0.00'),
                'last_purchase_time' => (int)($row['last_purchase_time'] ?? 0),
                'status' => (int)($row['status'] ?? 1),
                'remark' => (string)($row['remark'] ?? ''),
            ];
        }

        return $result;
    }

    protected static function ensurePrimarySupplierRelation(int $goodsId, int $supplierId): void
    {
        GoodsSupplier::where('goods_id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->where('sku_id', 0)
            ->update(['is_primary' => 0]);

        $relation = GoodsSupplier::where('goods_id', $goodsId)
            ->where('supplier_id', $supplierId)
            ->where('tenant_id', self::tenantId())
            ->where('sku_id', 0)
            ->findOrEmpty();
        if ($relation->isEmpty()) {
            GoodsSupplier::create([
                'tenant_id' => self::tenantId(),
                'goods_id' => $goodsId,
                'sku_id' => 0,
                'supplier_id' => $supplierId,
                'is_primary' => 1,
                'status' => 1,
            ]);
            return;
        }

        $relation->save(['is_primary' => 1, 'status' => 1]);
    }

    protected static function syncPrimarySupplierFlag(int $goodsId, int $primarySupplierId): void
    {
        if ($primarySupplierId <= 0) {
            GoodsSupplier::where('goods_id', $goodsId)
                ->where('tenant_id', self::tenantId())
                ->where('sku_id', 0)
                ->update(['is_primary' => 0]);
            return;
        }
        self::ensurePrimarySupplierRelation($goodsId, $primarySupplierId);
    }

    protected static function assertSupplierInTenant(int $supplierId): bool
    {
        $exists = Vendor::where('id', $supplierId)
            ->where('tenant_id', self::tenantId())
            ->count();
        if ($exists <= 0) {
            self::setError('供应商不存在');
            return false;
        }
        return true;
    }

    protected static function recentSupplyOrders(int $goodsId, int $limit = 5): array
    {
        return Db::name('order_goods')
            ->alias('og')
            ->join('supply_order so', 'so.id = og.order_id')
            ->where('og.tenant_id', self::tenantId())
            ->where('so.tenant_id', self::tenantId())
            ->where('og.goods_id', $goodsId)
            ->where('og.order_type', 'supply')
            ->field('so.id AS order_id,so.order_sn,so.supplier_id,so.supplier_name,so.datetimesingle,so.order_money,og.number,og.price,og.amount')
            ->order(['so.datetimesingle' => 'desc', 'so.id' => 'desc'])
            ->limit($limit)
            ->select()
            ->toArray();
    }

    protected static function customerRecentRecommendations(int $customerId, int $limit): array
    {
        $customerIds = self::recommendationCustomerIds($customerId);
        if ($customerIds === []) {
            return [];
        }

        $orderIds = Db::name('sales_order')
            ->where('tenant_id', self::tenantId())
            ->whereIn('customer_id', $customerIds)
            ->order(['datetimesingle' => 'desc', 'id' => 'desc'])
            ->limit(20)
            ->column('id');

        if ($orderIds === []) {
            return [];
        }

        return self::salesGoodsRecommendations([
            'order_ids' => array_values(array_map('intval', $orderIds)),
        ], $limit, 'customer_recent');
    }

    protected static function hotSalesRecommendations(int $limit, int $hotDays): array
    {
        return self::salesGoodsRecommendations([
            'start_time' => time() - ($hotDays * 86400),
        ], $limit, 'hot');
    }

    protected static function salesGoodsRecommendations(array $scope, int $limit, string $reason): array
    {
        $query = Db::name('order_goods')
            ->alias('og')
            ->join('sales_order so', 'so.id = og.order_id')
            ->join('goods g', 'g.id = og.goods_id')
            ->where('og.tenant_id', self::tenantId())
            ->where('so.tenant_id', self::tenantId())
            ->where('g.tenant_id', self::tenantId())
            ->where('og.order_type', 'sales')
            ->where('g.is_disabled', 0)
            ->where('g.is_archived', 0);

        if (!empty($scope['order_ids'])) {
            $query->whereIn('og.order_id', $scope['order_ids']);
        }
        if (!empty($scope['start_time'])) {
            $query->where('so.datetimesingle', '>=', (int)$scope['start_time']);
        }

        $rows = $query
            ->fieldRaw('g.id AS id,g.id AS goods_id,g.name,g.name AS product_name,g.product_code,g.units,g.unit_id,g.price,g.cost,g.stock,g.category_id,g.primary_supplier_id,g.is_disabled,COALESCE(SUM(og.number), 0) AS total_quantity,MAX(so.datetimesingle) AS last_sale_time')
            ->group('g.id,g.name,g.product_code,g.units,g.unit_id,g.price,g.cost,g.stock,g.category_id,g.primary_supplier_id,g.is_disabled')
            ->order('total_quantity desc,last_sale_time desc,g.id desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return self::formatRecommendationRows($rows, $reason);
    }

    protected static function recommendationCustomerIds(int $customerId): array
    {
        $customer = Customer::where('id', $customerId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($customer->isEmpty()) {
            return [];
        }

        $ids = [$customerId];
        if ((int)$customer->parent_id === 0) {
            $childIds = Customer::where('parent_id', $customerId)
                ->where('tenant_id', self::tenantId())
                ->column('id');
            $ids = array_merge($ids, array_map('intval', $childIds));
        }

        return array_values(array_unique(array_filter($ids)));
    }

    protected static function formatRecommendationRows(array $rows, string $reason): array
    {
        return array_map(static function (array $row) use ($reason) {
            $item = self::formatItem($row);
            $item['id'] = (int)($row['id'] ?? 0);
            $item['goods_id'] = (int)($row['goods_id'] ?? $row['id'] ?? 0);
            $item['total_quantity'] = number_format(max(0, (float)($row['total_quantity'] ?? 0)), 4, '.', '');
            $item['last_sale_time'] = (int)($row['last_sale_time'] ?? 0);
            $item['recommend_reason'] = $reason;
            return $item;
        }, $rows);
    }

    protected static function purchaseStats(int $goodsId, int $supplierCount): array
    {
        $stats = Db::name('order_goods')
            ->alias('og')
            ->join('supply_order so', 'so.id = og.order_id')
            ->where('og.tenant_id', self::tenantId())
            ->where('so.tenant_id', self::tenantId())
            ->where('og.goods_id', $goodsId)
            ->where('og.order_type', 'supply')
            ->fieldRaw('COUNT(DISTINCT so.id) AS order_count, COALESCE(SUM(og.amount), 0) AS purchase_amount, COALESCE(SUM(og.number), 0) AS purchase_quantity')
            ->find();

        return [
            'supplier_count' => $supplierCount,
            'purchase_order_count' => (int)($stats['order_count'] ?? 0),
            'purchase_amount' => (string)($stats['purchase_amount'] ?? '0.00'),
            'purchase_quantity' => (string)($stats['purchase_quantity'] ?? '0.00'),
        ];
    }

    protected static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    protected static function clampInt(mixed $value, int $min, int $max): int
    {
        return min($max, max($min, (int)$value));
    }

    protected static function normalizeDecimal(mixed $value): string
    {
        return number_format(max(0, (float)$value), 2, '.', '');
    }
}
