<?php

namespace app\common\service\cloud;

use app\common\cache\CloudGoodsCache;
use app\common\logic\BaseLogic;
use app\common\model\cloud\CloudGoods;
use app\common\model\cloud\CloudGoodsImport;
use app\common\model\goods\TenantGoodscat;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsUnit;
use think\facade\Db;

class CloudGoodsService extends BaseLogic
{
    public static function visibleQuery(int $tenantId)
    {
        return self::publicQuery()->where('status', CloudGoods::STATUS_ENABLED);
    }

    public static function publicQuery()
    {
        return CloudGoods::where('scope', CloudGoods::SCOPE_PUBLIC)
            ->where('tenant_id', 0)
            ->where('status', '<>', CloudGoods::STATUS_ARCHIVED);
    }

    public static function publicArchivedQuery()
    {
        return CloudGoods::where('scope', CloudGoods::SCOPE_PUBLIC)
            ->where('tenant_id', 0)
            ->where('status', CloudGoods::STATUS_ARCHIVED);
    }

    public static function listVisible(array $params, int $tenantId, int $offset, int $limit): array
    {
        $cacheParams = self::cacheParams('visible', $params, $tenantId, $offset, $limit);
        $cache = new CloudGoodsCache();
        $rows = $cache->rememberValue($cache->listKey($cacheParams), function () use ($params, $tenantId, $offset, $limit) {
            return self::applyFilters(self::visibleQuery($tenantId), $params)
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
            return self::applyFilters(self::visibleQuery($tenantId), $params)->count();
        });
    }

    public static function listPublic(array $params, int $offset, int $limit): array
    {
        $cacheParams = self::cacheParams('public', $params, 0, $offset, $limit);
        $cache = new CloudGoodsCache();
        $rows = $cache->rememberValue($cache->listKey($cacheParams), function () use ($params, $offset, $limit) {
            return self::applyFilters(self::publicQuery(), $params)
                ->field(self::publicListFields())
                ->limit($offset, $limit)
                ->order(['sort' => 'desc', 'id' => 'desc'])
                ->select()
                ->toArray();
        });
        return self::attachPublicMeta($rows);
    }

    public static function countPublic(array $params): int
    {
        $cacheParams = self::cacheParams('public_count', $params, 0, 0, 0);
        $cache = new CloudGoodsCache();
        return (int)$cache->rememberValue($cache->listKey($cacheParams), function () use ($params) {
            return self::applyFilters(self::publicQuery(), $params)->count();
        });
    }

    public static function listArchivedPublic(array $params, int $offset, int $limit): array
    {
        $cacheParams = self::cacheParams('public_archive', $params, 0, $offset, $limit);
        $cache = new CloudGoodsCache();
        $rows = $cache->rememberValue($cache->listKey($cacheParams), function () use ($params, $offset, $limit) {
            return self::applyFilters(self::publicArchivedQuery(), $params)
                ->field(self::publicListFields())
                ->limit($offset, $limit)
                ->order(['update_time' => 'desc', 'id' => 'desc'])
                ->select()
                ->toArray();
        });
        return self::attachPublicMeta($rows);
    }

    public static function countArchivedPublic(array $params): int
    {
        $cacheParams = self::cacheParams('public_archive_count', $params, 0, 0, 0);
        $cache = new CloudGoodsCache();
        return (int)$cache->rememberValue($cache->listKey($cacheParams), function () use ($params) {
            return self::applyFilters(self::publicArchivedQuery(), $params)->count();
        });
    }

    public static function detailVisible(int $id, int $tenantId): array
    {
        $cache = new CloudGoodsCache();
        $row = $cache->rememberValue($cache->detailKey($id, 0), function () use ($id, $tenantId) {
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
        $model = CloudGoods::where('scope', CloudGoods::SCOPE_PUBLIC)
            ->where('tenant_id', 0)
            ->where('id', $id)
            ->findOrEmpty();
        return $model->isEmpty() ? [] : $model->append(['scope_desc', 'status_desc'])->toArray();
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
        return self::archiveCloudGoods((array)($params['id'] ?? []), CloudGoods::SCOPE_PUBLIC, 0);
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

        $cloudGoods = self::visibleQuery($tenantId)->field(self::fields())->where('id', $cloudGoodsId)->findOrEmpty();
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
                'is_disabled' => (int)($source['is_disabled'] ?? 0),
                'remark' => (string)($source['remark'] ?? ''),
            ]);

            CloudGoodsImport::create([
                'tenant_id' => $tenantId,
                'cloud_goods_id' => $cloudGoodsId,
                'goods_id' => (int)$goods->id,
                'user_id' => $userId,
                'admin_id' => $adminId,
                'source_scope' => (int)$source['scope'],
                'load_unit_id' => $unitId,
                'load_category_id' => $categoryId,
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
        if ($data === false) {
            return false;
        }
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
        if ($data === false) {
            return false;
        }
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

    protected static function archiveCloudGoods(array $ids, int $scope, int $tenantId): bool
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            self::setError('请选择要归档的云端商品');
            return false;
        }

        try {
            CloudGoods::whereIn('id', $ids)
                ->where('scope', $scope)
                ->where('tenant_id', $tenantId)
                ->where('status', '<>', CloudGoods::STATUS_ARCHIVED)
                ->update([
                    'status' => CloudGoods::STATUS_ARCHIVED,
                    'update_time' => time(),
                ]);
            self::clearCache();
            return true;
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function buildSaveData(array $params, int $scope, int $tenantId, int $adminId = 0, array $current = []): array|false
    {
        $status = (int)($params['status'] ?? ($current['status'] ?? CloudGoods::STATUS_ENABLED));
        $isDisabled = (int)($params['is_disabled'] ?? ($current['is_disabled'] ?? 0));
        $name = trim((string)($params['name'] ?? $params['product_name'] ?? ($current['name'] ?? '')));
        $isPlatformPublic = $scope === CloudGoods::SCOPE_PUBLIC && $tenantId === 0;
        $units = $isPlatformPublic
            ? ''
            : trim((string)($params['units'] ?? $params['unit'] ?? ($current['units'] ?? '')));
        $category = self::resolvePublicCategory($params, $current);
        if ($category === false) {
            return false;
        }

        return [
            'scope' => $scope,
            'tenant_id' => $tenantId,
            'owner_admin_id' => $adminId > 0 ? $adminId : (int)($current['owner_admin_id'] ?? 0),
            'owner_user_id' => (int)($params['owner_user_id'] ?? ($current['owner_user_id'] ?? 0)),
            'name' => $name,
            'product_code' => trim((string)($params['product_code'] ?? ($current['product_code'] ?? ''))),
            'units' => $units,
            'price' => self::normalizeDecimal(
                $isPlatformPublic ? 0 : ($params['price'] ?? $params['units_money'] ?? ($current['price'] ?? 0))
            ),
            'cost' => self::normalizeDecimal(
                $isPlatformPublic ? 0 : ($params['cost'] ?? $params['purchase_price'] ?? ($current['cost'] ?? 0))
            ),
            'stock' => self::normalizeDecimal(
                $isPlatformPublic ? 0 : ($params['stock'] ?? ($current['stock'] ?? 0))
            ),
            'category_id' => $category['id'],
            'category_name' => $category['name'],
            'is_disabled' => $isDisabled === 1 ? 1 : 0,
            'status' => self::normalizeManageStatus($status),
            'sort' => (int)($params['sort'] ?? ($current['sort'] ?? 0)),
            'remark' => trim((string)($params['remark'] ?? ($current['remark'] ?? ''))),
        ];
    }

    protected static function resolvePublicCategory(array $params, array $current = []): array|false
    {
        $hasCategoryId = array_key_exists('category_id', $params);
        $categoryId = $hasCategoryId ? (int)$params['category_id'] : (int)($current['category_id'] ?? 0);
        $categoryName = $hasCategoryId ? '' : trim((string)($current['category_name'] ?? ''));

        if ($categoryId <= 0) {
            return ['id' => 0, 'name' => $categoryName];
        }

        $category = TenantGoodscat::where('id', $categoryId)
            ->where('tenant_id', 0)
            ->field(['id', 'name'])
            ->findOrEmpty();
        if ($category->isEmpty()) {
            self::setError('请选择有效的商品分类');
            return false;
        }

        return [
            'id' => (int)$category->id,
            'name' => (string)$category->name,
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
                    ->whereOr('category_name', 'like', '%' . $keyword . '%');
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
        $loadedCounts = [];
        if ($tenantId > 0 && $ids !== []) {
            $imports = CloudGoodsImport::where('tenant_id', $tenantId)
                ->whereIn('cloud_goods_id', $ids)
                ->field(['cloud_goods_id', 'goods_id'])
                ->select()
                ->toArray();
            foreach ($imports as $import) {
                $cloudGoodsId = (int)$import['cloud_goods_id'];
                $loaded[$cloudGoodsId] = (int)$import['goods_id'];
                $loadedCounts[$cloudGoodsId] = ($loadedCounts[$cloudGoodsId] ?? 0) + 1;
            }
        }

        foreach ($rows as &$row) {
            $row['scope'] = (int)($row['scope'] ?? 0);
            $row['tenant_id'] = (int)($row['tenant_id'] ?? 0);
            $row['status_desc'] = self::statusDesc((int)($row['status'] ?? 0));
            $row['loaded'] = isset($loaded[(int)$row['id']]);
            $row['loaded_goods_id'] = $loaded[(int)$row['id']] ?? 0;
            $row['loaded_count'] = $loadedCounts[(int)$row['id']] ?? 0;
        }
        unset($row);
        return $rows;
    }

    protected static function attachPublicMeta(array $rows): array
    {
        $ids = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $rows)));
        $loadedCounts = [];
        if ($ids !== []) {
            $imports = CloudGoodsImport::whereIn('cloud_goods_id', $ids)
                ->field('cloud_goods_id,COUNT(*) as loaded_count')
                ->group('cloud_goods_id')
                ->select()
                ->toArray();
            foreach ($imports as $import) {
                $loadedCounts[(int)$import['cloud_goods_id']] = (int)$import['loaded_count'];
            }
        }

        foreach ($rows as &$row) {
            $row['scope'] = (int)($row['scope'] ?? 0);
            $row['tenant_id'] = (int)($row['tenant_id'] ?? 0);
            $row['status'] = (int)($row['status'] ?? 0);
            $row['status_desc'] = self::statusDesc($row['status']);
            $row['loaded_count'] = $loadedCounts[(int)$row['id']] ?? 0;
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
            ->where('status', '<>', CloudGoods::STATUS_ARCHIVED);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('云端商品名称已存在');
            return false;
        }

        if ($data['product_code'] !== '') {
            $codeQuery = CloudGoods::where('scope', (int)$data['scope'])
                ->where('tenant_id', (int)$data['tenant_id'])
                ->where('product_code', $data['product_code'])
                ->where('status', '<>', CloudGoods::STATUS_ARCHIVED);
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

    protected static function cacheParams(string $type, array $params, int $tenantId, int $offset, int $limit): array
    {
        return [
            'type' => $type,
            'tenant_id' => $tenantId,
            'scope' => CloudGoods::SCOPE_PUBLIC,
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
            'category_id',
            'category_name',
            'is_disabled',
            'status',
            'sort',
            'remark',
            'create_time',
            'update_time',
        ];
    }

    protected static function publicListFields(): array
    {
        return [
            'id',
            'scope',
            'tenant_id',
            'owner_admin_id',
            'owner_user_id',
            'name',
            'product_code',
            'category_id',
            'category_name',
            'is_disabled',
            'status',
            'sort',
            'remark',
            'create_time',
            'update_time',
        ];
    }

    protected static function normalizeManageStatus(int $status): int
    {
        if ($status === CloudGoods::STATUS_ARCHIVED) {
            return CloudGoods::STATUS_ARCHIVED;
        }
        return $status === CloudGoods::STATUS_DISABLED ? CloudGoods::STATUS_DISABLED : CloudGoods::STATUS_ENABLED;
    }

    protected static function statusDesc(int $status): string
    {
        return match ($status) {
            CloudGoods::STATUS_ENABLED => '启用',
            CloudGoods::STATUS_ARCHIVED => '已归档',
            default => '停用',
        };
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
