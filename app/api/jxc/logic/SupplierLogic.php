<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Vendor;
use think\facade\Db;

class SupplierLogic extends BaseLogic
{
    public static function add(array $params): bool
    {
        if (Vendor::where('supplier_name', trim($params['supplier_name']))->count() > 0) {
            self::setError('供应商名称已存在');
            return false;
        }

        Db::startTrans();
        try {
            Vendor::create(self::buildSaveData($params));
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
        $model = Vendor::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('供应商不存在');
            return false;
        }

        $duplicate = Vendor::where('supplier_name', trim($params['supplier_name']))
            ->where('id', '<>', (int)$params['id'])
            ->count();
        if ($duplicate > 0) {
            self::setError('供应商名称已存在');
            return false;
        }

        Db::startTrans();
        try {
            $model->save(self::buildSaveData($params, $model->toArray()));
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
        $model = Vendor::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('供应商不存在');
            return false;
        }

        return (bool)$model->delete();
    }

    public static function detail(array $params): array
    {
        $model = Vendor::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            return [];
        }

        return self::formatItem($model->toArray());
    }

    public static function formatItem(array $item): array
    {
        $item['supplier_name'] = $item['supplier_name'] ?? '';
        $item['name'] = $item['supplier_name'];
        $item['contact'] = $item['contact'] ?? '';
        $item['phone'] = $item['phone'] ?? '';
        $item['address'] = $item['address'] ?? '';
        $item['remark'] = $item['remark'] ?? '';
        $item['is_disabled'] = (int)($item['is_disabled'] ?? 0);
        $item['status'] = $item['is_disabled'] === 1 ? 0 : 1;
        $item['order_money'] = (string)($item['order_money'] ?? '0.00');
        return $item;
    }

    protected static function buildSaveData(array $params, array $current = []): array
    {
        return [
            'supplier_name' => trim((string)$params['supplier_name']),
            'contact' => trim((string)($params['contact'] ?? ($current['contact'] ?? ''))),
            'phone' => trim((string)($params['phone'] ?? ($current['phone'] ?? ''))),
            'address' => trim((string)($params['address'] ?? ($current['address'] ?? ''))),
            'remark' => trim((string)($params['remark'] ?? ($current['remark'] ?? ''))),
            'is_disabled' => (int)($params['is_disabled'] ?? ($current['is_disabled'] ?? 0)),
        ];
    }
}
