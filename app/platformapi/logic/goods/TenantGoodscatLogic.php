<?php

namespace app\platformapi\logic\goods;

use app\common\logic\BaseLogic;
use app\common\model\goods\TenantGoodscat;
use think\facade\Db;

class TenantGoodscatLogic extends BaseLogic
{
    public static function add(array $params): bool
    {
        Db::startTrans();
        try {
            TenantGoodscat::create([
                'tenant_id' => 0,
                'name' => trim((string)$params['name']),
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
        Db::startTrans();
        try {
            $model = TenantGoodscat::where('id', (int)$params['id'])
                ->where('tenant_id', 0)
                ->findOrEmpty();
            if ($model->isEmpty()) {
                self::setError('商品分类不存在');
                Db::rollback();
                return false;
            }

            $model->save([
                'name' => trim((string)$params['name']),
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
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->field(['id', 'name'])
            ->select()
            ->toArray();
    }
}
