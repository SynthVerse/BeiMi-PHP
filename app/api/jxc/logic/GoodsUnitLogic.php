<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsUnit;
use think\facade\Db;

class GoodsUnitLogic extends BaseLogic
{
    public static function add(array $params): bool
    {
        if (GoodsUnit::where('tenant_id', self::tenantId())
            ->where('name', trim($params['name']))
            ->count() > 0
        ) {
            self::setError('单位名称已存在');
            return false;
        }

        Db::startTrans();
        try {
            GoodsUnit::create(self::measurementFields($params) + [
                'tenant_id' => self::tenantId(),
                'name' => trim($params['name']),
                'sort' => (int)($params['sort'] ?? 0),
                'status' => (int)($params['status'] ?? 1),
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
        $model = GoodsUnit::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('单位不存在');
            return false;
        }

        $saveData = [];
        try { $saveData += self::measurementFields($params); }
        catch (\DomainException $e) { self::setError($e->getMessage()); return false; }
        if ($saveData !== [] && self::referenced((int)$params['id'])) {
            self::setError('单位已被商品引用，不能原地修改计量类型或精度'); return false;
        }
        if (array_key_exists('name', $params) && trim((string)$params['name']) !== '') {
            if (trim((string)$params['name']) !== (string)$model->name
                && self::referenced((int)$model->id)) {
                self::setError('单位已被商品引用，不能通过改名重新定义其含义，请创建新单位'); return false;
            }
            $duplicate = GoodsUnit::where('name', trim($params['name']))
                ->where('tenant_id', self::tenantId())
                ->where('id', '<>', (int)$params['id'])
                ->count();
            if ($duplicate > 0) {
                self::setError('单位名称已存在');
                return false;
            }
            $saveData['name'] = trim((string)$params['name']);
        }

        if (array_key_exists('sort', $params) && $params['sort'] !== '') {
            $saveData['sort'] = (int)$params['sort'];
        }

        if (array_key_exists('status', $params) && $params['status'] !== '') {
            $saveData['status'] = (int)$params['status'];
        }

        if (empty($saveData)) {
            self::setError('未提供可更新内容');
            return false;
        }

        Db::startTrans();
        try {
            $model->save($saveData);
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
        $model = GoodsUnit::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('单位不存在');
            return false;
        }

        $goodsCount = Goods::where('unit_id', (int)$model->id)
            ->where('tenant_id', self::tenantId())
            ->count();
        if ($goodsCount > 0 || self::referenced((int)$model->id)) {
            self::setError('该单位已被商品使用，请先更换商品单位后再删除');
            return false;
        }

        return (bool)$model->delete();
    }

    public static function detail(array $params): array
    {
        $model = GoodsUnit::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            return [];
        }

        return self::formatItem($model->toArray());
    }

    public static function formatItem(array $item): array
    {
        $item['status'] = (int)($item['status'] ?? 1);
        $item['sort'] = (int)($item['sort'] ?? 0);
        return (TradeQuantityService::unit((int)$item['id']) ?? []) + $item;
    }

    private static function measurementFields(array $params): array
    {
        if (!isset($params['measure_type']) && !isset($params['quantity_precision'])) { return []; }
        $type = (string)($params['measure_type'] ?? 'count'); $precision = (int)($params['quantity_precision'] ?? ($type === 'weight' ? 4 : 0));
        if (!in_array($type, ['weight', 'count', 'other'], true) || $precision < 0 || $precision > 4) { throw new \DomainException('单位类型或精度无效'); }
        return ['measure_type' => $type, 'quantity_precision' => $precision];
    }

    private static function referenced(int $unitId): bool
    {
        return Db::name('goods_units_binding')->where('tenant_id', self::tenantId())->where('unit_id', $unitId)->count() > 0
            || Db::name('goods')->where('tenant_id', self::tenantId())->where('unit_id', $unitId)->count() > 0
            || Db::name('goods_sku')->where('tenant_id', self::tenantId())->where('base_unit_id', $unitId)->count() > 0;
    }

    protected static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
