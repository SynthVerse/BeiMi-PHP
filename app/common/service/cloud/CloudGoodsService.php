<?php

namespace app\common\service\cloud;

use app\common\cache\CloudGoodsCache;
use app\common\logic\BaseLogic;
use app\common\model\cloud\CloudGoods;
use app\common\model\cloud\CloudGoodsImport;
use app\common\model\goods\TenantGoodscat;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSupplier;
use app\common\model\jxc\GoodsUnit;
use app\common\model\jxc\Vendor;
use think\facade\Db;

class CloudGoodsService extends BaseLogic
{
    public static function visibleQuery(int $tenantId, mixed $scope = null)
    {
        $query = CloudGoods::where(function ($builder) use ($tenantId) {
            $builder->where(function ($public) {
                $public->where('scope', CloudGoods::SCOPE_PUBLIC)
                    ->where('tenant_id', 0)
                    ->where('status', CloudGoods::STATUS_ENABLED);
            });

            if ($tenantId > 0) {
                $builder->whereOr(function ($private) use ($tenantId) {
                    $private->where('scope', CloudGoods::SCOPE_PRIVATE)
                        ->where('tenant_id', $tenantId);
                });
            }
        });

        $scope = self::normalizeScope($scope);
        if ($scope > 0) {
            $query->where('scope', $scope);
        }
        return $query;
    }

    public static function publicQuery()
    {
        return CloudGoods::where('scope', CloudGoods::SCOPE_PUBLIC)->where('tenant_id', 0);
    }

    public static function privateQuery(int $tenantId)
    {
        return CloudGoods::where('scope', CloudGoods::SCOPE_PRIVATE)->where('tenant_id', $tenantId);
    }

    public static function listVisible(array $params, int $tenantId, int $offset, int $limit): array
    {
        $cacheParams = self::cacheParams('visible', $params, $tenantId, $offset, $limit);
        $cache = new CloudGoodsCache();
        $rows = $cache->rememberValue($cache->listKey($cacheParams), function () use ($params, $tenantId, $offset, $limit) {
            return self::applyFilters(self::visibleQuery($tenantId, $params['scope'] ?? null), $params)
                ->field(self::fields())
                ->limit($offset, $limit)
                ->order(['sort' => 'desc', 'id' => 'desc'])
                ->select()
                ->toArray();
        });
        return self::attachLoaded($rows, $tenantId);
    }

    public static function countVisible(array $params, int $tenantId): int
    {
        $cacheParams = self::cacheParams('visible_count', $params, $tenantId, 0, 0);
        $cache = new CloudGoodsCache();
        return (int)$cache->rememberValue($cache->listKey($cacheParams), function () use ($params, $tenantId) {
            return self::applyFilters(self::visibleQuery($tenantId, $params['scope'] ?? null), $params)->count();
        });
    }

    public static function listPublic(array $params, int $offset, int $limit): array
    {
        $cacheParams = self::cacheParams('public', $params, 0, $offset, $limit);
        $cache = new CloudGoodsCache();
        return $cache->rememberValue($cache->listKey($cacheParams), function () use ($params, $offset, $limit) {
            return self::applyFilters(self::publicQuery(), $params)
                ->field(self::fields())
                ->limit($offset, $limit)
                ->order(['sort' => 'desc', 'id' => 'desc'])
                ->select()
                ->toArray();
        });
    }

    public static function countPublic(array $params): int
    {
        $cacheParams = self::cacheParams('public_count', $params, 0, 0, 0);
        $cache = new CloudGoodsCache();
        return (int)$cache->rememberValue($cache->listKey($cacheParams), function () use ($params) {
            return self::applyFilters(self::publicQuery(), $params)->count();
        });
    }

    public static function listPrivateWithPublic(array $params, int $tenantId, int $offset, int $limit): array
    {
        return self::listVisible($params, $tenantId, $offset, $limit);
    }

    public static function countPrivateWithPublic(array $params, int $tenantId): int
    {
        return self::countVisible($params, $tenantId);
    }

    public static function detailVisible(int $id, int $tenantId): array
    {
        $cache = new CloudGoodsCache();
        $row = $cache->rememberValue($cache->detailKey($id, $tenantId), function () use ($id, $tenantId) {
            $model = self::visibleQuery($tenantId)->where('id', $id)->findOrEmpty();
            return $model->isEmpty() ? [] : $model->append(['scope_desc', 'status_desc'])->toArray();
        });
        if ($row === []) {
            return [];
        }
        return self::attachLoaded([$row], $tenantId)[0] ?? $row;
    }

    public static function detailPublic(int $id): array
    {
        $model = self::publicQuery()->where('id', $id)->findOrEmpty();
        return $model->isEmpty() ? [] : $model->append(['scope_desc', 'status_desc'])->toArray();
    }

    public static function detailPrivateOrPublic(int $id, int $tenantId): array
    {
        return self::detailVisible($id, $tenantId);
    }

    public static function addPublic(array $params, int $adminId = 0): array|false
    {
        return self::saveCloudGoods($params, CloudGoods::SCOPE_PUBLIC, 0, $adminId);
    }

    public static function editPublic(array $params, int $adminId = 0): bool
    {
        return self::updateCloudGoods($params, CloudGoods::SCOPE_PUBLIC, 0, $adminId);
    }

    public static function deletePublic(array $params): bool
    {
        return self::deleteCloudGoods((array)($params['id'] ?? []), CloudGoods::SCOPE_PUBLIC, 0);
    }

    public static function addPrivate(array $params, int $tenantId, int $adminId = 0): array|false
    {
        if ($tenantId <= 0) {
            self::setError('租户上下文缺失，请重新登录');
            return false;
        }
        return self::saveCloudGoods($params, CloudGoods::SCOPE_PRIVATE, $tenantId, $adminId);
    }

    public static function editPrivate(array $params, int $tenantId, int $adminId = 0): bool
    {
        if ($tenantId <= 0) {
            self::setError('租户上下文缺失，请重新登录');
            return false;
        }
        return self::updateCloudGoods($params, CloudGoods::SCOPE_PRIVATE, $tenantId, $adminId);
    }

    public static function deletePrivate(array $params, int $tenantId): bool
    {
        if ($tenantId <= 0) {
            self::setError('租户上下文缺失，请重新登录');
            return false;
        }
        return self::deleteCloudGoods((array)($params['id'] ?? []), CloudGoods::SCOPE_PRIVATE, $tenantId);
    }

    public static function loadToTenant(array $params, int $tenantId, int $userId = 0, int $adminId = 0): array|false
    {
        if ($tenantId <= 0) {
            self::setError('商品租户上下文缺失，请重新登录');
            return false;
        }

        $cloudGoodsId = (int)($params['cloud_goods_id'] ?? $params['id'] ?? 0);
        $unitId = (int)($params['unit_id'] ?? $params['units_id'] ?? 0);
        $categoryId = (int)($params['category_id'] ?? 0);
        $supplierId = (int)($params['primary_supplier_id'] ?? $params['supplier_id'] ?? 0);

        $cloudGoods = self::visibleQuery($tenantId)->where('id', $cloudGoodsId)->findOrEmpty();
        if ($cloudGoods->isEmpty()) {
            self::setError('云端商品不存在或无权限访问');
            return false;
        }

        $unit = GoodsUnit::where('id', $unitId)->where('tenant_id', $tenantId)->findOrEmpty();
        if ($unit->isEmpty()) {
            self::setError('请选择有效的本地单位');
            return false;
        }

        if ($categoryId > 0) {
            $category = TenantGoodscat::where('id', $categoryId)->where('tenant_id', $tenantId)->findOrEmpty();
            if ($category->isEmpty()) {
                self::setError('商品分类不存在');
                return false;
            }
        }

        if ($supplierId > 0) {
            $supplier = Vendor::where('id', $supplierId)->where('tenant_id', $tenantId)->findOrEmpty();
            if ($supplier->isEmpty()) {
                self::setError('供应商不存在');
                return false;
            }
        }

        $source = $cloudGoods->toArray();
        $duplicate = self::findDuplicateGoods($source, $tenantId, $unitId);
        if ($duplicate !== null) {
            return [
                'loaded' => false,
                'existing_goods_id' => (int)$duplicate['id'],
                'reason' => $duplicate['reason'],
            ];
        }

        Db::startTrans();
        try {
            $goods = Goods::create([
                'tenant_id' => $tenantId,
                'name' => (string)$source['name'],
                'product_code' => (string)($source['product_code'] ?? ''),
                'units' => (string)$unit->name,
                'unit_id' => $unitId,
                'price' => self::normalizeDecimal($source['price'] ?? 0),
                'cost' => self::normalizeDecimal($source['cost'] ?? 0),
                'stock' => self::normalizeDecimal($source['stock'] ?? 0),
                'category_id' => $categoryId,
                'primary_supplier_id' => $supplierId,
                'is_disabled' => (int)($source['is_disabled'] ?? 0),
                'remark' => (string)($source['remark'] ?? ''),
            ]);

            if ($supplierId > 0) {
                GoodsSupplier::create([
                    'tenant_id' => $tenantId,
                    'goods_id' => (int)$goods->id,
                    'supplier_id' => $supplierId,
                    'is_primary' => 1,
                    'purchase_price' => self::normalizeDecimal($source['cost'] ?? 0),
                    'status' => 1,
                ]);
            }

            CloudGoodsImport::create([
                'tenant_id' => $tenantId,
                'cloud_goods_id' => $cloudGoodsId,
                'goods_id' => (int)$goods->id,
                'user_id' => $userId,
                'admin_id' => $adminId,
                'source_scope' => (int)$source['scope'],
                'load_unit_id' => $unitId,
                'load_category_id' => $categoryId,
                'load_supplier_id' => $supplierId,
                'load_snapshot' => json_encode($source, JSON_UNESCAPED_UNICODE),
            ]);

            Db::commit();
            self::clearCache();
            return [
                'loaded' => true,
                'goods_id' => (int)$goods->id,
                'cloud_goods_id' => $cloudGoodsId,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function saveCloudGoods(array $params, int $scope, int $tenantId, int $adminId = 0): array|false
    {
        $data = self::buildSaveData($params, $scope, $tenantId, $adminId);
        if ($data['name'] === '') {
            self::setError('商品名称不能为空');
            return false;
        }

        if (!self::assertCloudUnique($data)) {
            return false;
        }

        try {
            $model = CloudGoods::create($data);
            self::clearCache();
            return ['id' => (int)$model->id];
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function updateCloudGoods(array $params, int $scope, int $tenantId, int $adminId = 0): bool
    {
        $id = (int)($params['id'] ?? 0);
        $model = CloudGoods::where('id', $id)->where('scope', $scope)->where('tenant_id', $tenantId)->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('云端商品不存在');
            return false;
        }

        $data = self::buildSaveData($params, $scope, $tenantId, $adminId, $model->toArray());
        if ($data['name'] === '') {
            self::setError('商品名称不能为空');
            return false;
        }

        if (!self::assertCloudUnique($data, $id)) {
            return false;
        }

        try {
            $model->save($data);
            self::clearCache();
            return true;
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function deleteCloudGoods(array $ids, int $scope, int $tenantId): bool
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            self::setError('请选择要删除的云端商品');
            return false;
        }

        try {
            CloudGoods::whereIn('id', $ids)->where('scope', $scope)->where('tenant_id', $tenantId)->delete();
            self::clearCache();
            return true;
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function buildSaveData(array $params, int $scope, int $tenantId, int $adminId = 0, array $current = []): array
    {
        $status = (int)($params['status'] ?? ($current['status'] ?? CloudGoods::STATUS_ENABLED));
        $isDisabled = (int)($params['is_disabled'] ?? ($current['is_disabled'] ?? 0));
        $name = trim((string)($params['name'] ?? $params['product_name'] ?? ($current['name'] ?? '')));
        $units = trim((string)($params['units'] ?? $params['unit'] ?? ($current['units'] ?? '')));

        return [
            'scope' => $scope,
            'tenant_id' => $tenantId,
            'owner_admin_id' => $adminId > 0 ? $adminId : (int)($current['owner_admin_id'] ?? 0),
            'owner_user_id' => (int)($params['owner_user_id'] ?? ($current['owner_user_id'] ?? 0)),
            'name' => $name,
            'product_code' => trim((string)($params['product_code'] ?? ($current['product_code'] ?? ''))),
            'units' => $units,
            'price' => self::normalizeDecimal($params['price'] ?? $params['units_money'] ?? ($current['price'] ?? 0)),
            'cost' => self::normalizeDecimal($params['cost'] ?? $params['purchase_price'] ?? ($current['cost'] ?? 0)),
            'stock' => self::normalizeDecimal($params['stock'] ?? ($current['stock'] ?? 0)),
            'category_name' => trim((string)($params['category_name'] ?? ($current['category_name'] ?? ''))),
            'supplier_name' => trim((string)($params['supplier_name'] ?? ($current['supplier_name'] ?? ''))),
            'is_disabled' => $isDisabled === 1 ? 1 : 0,
            'status' => $status === CloudGoods::STATUS_DISABLED ? CloudGoods::STATUS_DISABLED : CloudGoods::STATUS_ENABLED,
            'sort' => (int)($params['sort'] ?? ($current['sort'] ?? 0)),
            'remark' => trim((string)($params['remark'] ?? ($current['remark'] ?? ''))),
        ];
    }

    protected static function applyFilters($query, array $params)
    {
        $keyword = trim((string)($params['keyword'] ?? $params['name'] ?? $params['product_name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('product_code', 'like', '%' . $keyword . '%')
                    ->whereOr('units', 'like', '%' . $keyword . '%')
                    ->whereOr('category_name', 'like', '%' . $keyword . '%')
                    ->whereOr('supplier_name', 'like', '%' . $keyword . '%');
            });
        }

        if (($params['status'] ?? '') !== '') {
            $query->where('status', (int)$params['status']);
        }

        return $query;
    }

    protected static function attachLoaded(array $rows, int $tenantId): array
    {
        $ids = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $rows)));
        $loaded = [];
        if ($tenantId > 0 && $ids !== []) {
            $imports = CloudGoodsImport::where('tenant_id', $tenantId)
                ->whereIn('cloud_goods_id', $ids)
                ->field(['cloud_goods_id', 'goods_id'])
                ->select()
                ->toArray();
            foreach ($imports as $import) {
                $loaded[(int)$import['cloud_goods_id']] = (int)$import['goods_id'];
            }
        }

        foreach ($rows as &$row) {
            $row['scope'] = (int)($row['scope'] ?? 0);
            $row['tenant_id'] = (int)($row['tenant_id'] ?? 0);
            $row['is_public'] = $row['scope'] === CloudGoods::SCOPE_PUBLIC ? 1 : 0;
            $row['is_private'] = $row['scope'] === CloudGoods::SCOPE_PRIVATE ? 1 : 0;
            $row['scope_desc'] = $row['scope'] === CloudGoods::SCOPE_PUBLIC ? '公共库' : '私有库';
            $row['status_desc'] = (int)($row['status'] ?? 0) === CloudGoods::STATUS_ENABLED ? '启用' : '停用';
            $row['loaded'] = isset($loaded[(int)$row['id']]);
            $row['loaded_goods_id'] = $loaded[(int)$row['id']] ?? 0;
        }
        unset($row);
        return $rows;
    }

    protected static function findDuplicateGoods(array $source, int $tenantId, int $unitId): ?array
    {
        $productCode = trim((string)($source['product_code'] ?? ''));
        if ($productCode !== '') {
            $existing = Goods::where('tenant_id', $tenantId)->where('product_code', $productCode)->findOrEmpty();
            if (!$existing->isEmpty()) {
                return ['id' => (int)$existing->id, 'reason' => '商品编码已存在'];
            }
        }

        $existing = Goods::where('tenant_id', $tenantId)
            ->where('name', (string)$source['name'])
            ->where('unit_id', $unitId)
            ->findOrEmpty();
        if (!$existing->isEmpty()) {
            return ['id' => (int)$existing->id, 'reason' => '相同名称和单位的商品已存在'];
        }
        return null;
    }

    protected static function assertCloudUnique(array $data, int $ignoreId = 0): bool
    {
        $query = CloudGoods::where('scope', (int)$data['scope'])
            ->where('tenant_id', (int)$data['tenant_id'])
            ->where('name', $data['name'])
            ->where('units', $data['units']);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('相同名称和单位的云端商品已存在');
            return false;
        }

        if ($data['product_code'] !== '') {
            $codeQuery = CloudGoods::where('scope', (int)$data['scope'])
                ->where('tenant_id', (int)$data['tenant_id'])
                ->where('product_code', $data['product_code']);
            if ($ignoreId > 0) {
                $codeQuery->where('id', '<>', $ignoreId);
            }
            if ($codeQuery->count() > 0) {
                self::setError('云端商品编码已存在');
                return false;
            }
        }

        return true;
    }

    protected static function normalizeScope(mixed $scope): int
    {
        if ($scope === '' || $scope === null) {
            return 0;
        }
        $scopeValue = is_string($scope) ? strtolower(trim($scope)) : $scope;
        if (in_array($scopeValue, ['public', 'platform', '1', 1], true)) {
            return CloudGoods::SCOPE_PUBLIC;
        }
        if (in_array($scopeValue, ['private', 'tenant', '2', 2], true)) {
            return CloudGoods::SCOPE_PRIVATE;
        }
        return 0;
    }

    protected static function cacheParams(string $type, array $params, int $tenantId, int $offset, int $limit): array
    {
        return [
            'type' => $type,
            'tenant_id' => $tenantId,
            'scope' => self::normalizeScope($params['scope'] ?? null),
            'keyword' => trim((string)($params['keyword'] ?? $params['name'] ?? $params['product_name'] ?? '')),
            'status' => (string)($params['status'] ?? ''),
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    protected static function fields(): array
    {
        return [
            'id',
            'scope',
            'tenant_id',
            'owner_admin_id',
            'owner_user_id',
            'name',
            'product_code',
            'units',
            'price',
            'cost',
            'stock',
            'category_name',
            'supplier_name',
            'is_disabled',
            'status',
            'sort',
            'remark',
            'create_time',
            'update_time',
        ];
    }

    protected static function normalizeDecimal(mixed $value): string
    {
        return number_format(max(0, (float)$value), 2, '.', '');
    }

    public static function clearCache(): void
    {
        (new CloudGoodsCache())->clearAll();
    }
}
