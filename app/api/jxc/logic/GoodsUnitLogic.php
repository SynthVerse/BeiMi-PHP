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
        if (GoodsUnit::where('name', trim($params['name']))->count() > 0) {
            self::setError('单位名称已存在');
            return false;
        }

        Db::startTrans();
        try {
            GoodsUnit::create([
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
        if (array_key_exists('name', $params) && trim((string)$params['name']) !== '') {
            $duplicate = GoodsUnit::where('name', trim($params['name']))
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
        $model = GoodsUnit::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('单位不存在');
            return false;
        }

        $goodsCount = Goods::where('unit_id', (int)$model->id)->count();
        if ($goodsCount > 0) {
            self::setError('该单位已被商品使用，请先更换商品单位后再删除');
            return false;
        }

        return (bool)$model->delete();
    }

    public static function detail(array $params): array
    {
        $model = GoodsUnit::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            return [];
        }

        return self::formatItem($model->toArray());
    }

    public static function formatItem(array $item): array
    {
        $basicUnits = ['件', '千克', '克', '升', '毫升', '米', '厘米', '毫米', '个', '只'];
        $name = $item['name'] ?? '';
        $item['type'] = in_array($name, $basicUnits) ? '基本单位' : '衍生单位';
        $item['status'] = (int)($item['status'] ?? 1);
        $item['sort'] = (int)($item['sort'] ?? 0);
        return $item;
    }
}
