<?php

namespace app\platformapi\logic\goods;

use app\common\logic\BaseLogic;
use app\common\model\goods\TenantGoodscat;
use app\common\service\jxc\DefaultDataInitService;
use think\facade\Db;

class TenantGoodscatLogic extends BaseLogic
{
    public static function add(array $params): bool
    {
        $name = trim((string)$params['name']);
        if ($name === DefaultDataInitService::DEFAULT_GOODS_CATEGORY_NAME) {
            self::setError('平台默认分类由系统维护');
            return false;
        }

        Db::startTrans();
        try {
            TenantGoodscat::create([
                'tenant_id' => 0,
                'name' => $name,
                'sort' => (int)($params['sort'] ?? 0),
                'is_show' => (int)($params['is_show'] ?? 0),
            ]);

            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function edit(array $params): bool
    {
        $model = TenantGoodscat::where('id', (int)$params['id'])
            ->where('tenant_id', 0)
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('商品分类不存在');
            return false;
        }
        if ((int)($model['is_default'] ?? 0) === 1) {
            self::setError('平台默认分类由系统维护');
            return false;
        }

        $name = trim((string)$params['name']);
        if ($name === DefaultDataInitService::DEFAULT_GOODS_CATEGORY_NAME) {
            self::setError('平台默认分类由系统维护');
            return false;
        }

        Db::startTrans();
        try {
            $model->save([
                'name' => $name,
                'sort' => (int)($params['sort'] ?? 0),
                'is_show' => (int)($params['is_show'] ?? 0),
            ]);

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
        $ids = array_values(array_filter(array_map('intval', (array)$params['id'])));
        if ($ids === []) {
            self::setError('请选择要删除的分类');
            return false;
        }

        $containsDefault = TenantGoodscat::where('tenant_id', 0)
            ->whereIn('id', $ids)
            ->where('is_default', 1)
            ->count() > 0;
        if ($containsDefault) {
            self::setError('平台默认分类由系统维护');
            return false;
        }

        return TenantGoodscat::where('tenant_id', 0)
            ->whereIn('id', $ids)
            ->delete() !== false;
    }

    public static function detail(array $params): array
    {
        return TenantGoodscat::where('id', (int)$params['id'])
            ->where('tenant_id', 0)
            ->findOrEmpty()
            ->toArray();
    }

    public static function all(): array
    {
        return TenantGoodscat::where('tenant_id', 0)
            ->where('is_show', 0)
            ->order(['is_default' => 'desc', 'sort' => 'desc', 'id' => 'desc'])
            ->field(['id', 'name', 'is_default'])
            ->select()
            ->toArray();
    }
}
