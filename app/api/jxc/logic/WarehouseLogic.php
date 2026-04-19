<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\PurchaseOrder;
use app\common\model\jxc\SalesOrder;
use app\common\model\jxc\SalesReturnOrder;
use app\common\model\jxc\SupplyOrder;
use app\common\model\jxc\Warehouse;
use think\facade\Db;

class WarehouseLogic extends BaseLogic
{
    public static function add(array $params): bool
    {
        if (Warehouse::where('name', trim($params['name']))->count() > 0) {
            self::setError('仓库名称已存在');
            return false;
        }

        Db::startTrans();
        try {
            Warehouse::create(self::buildSaveData($params));
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
        $model = Warehouse::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('仓库不存在');
            return false;
        }

        $duplicate = Warehouse::where('name', trim($params['name']))
            ->where('id', '<>', (int)$params['id'])
            ->count();
        if ($duplicate > 0) {
            self::setError('仓库名称已存在');
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
        $model = Warehouse::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('仓库不存在');
            return false;
        }

        $orderCount = SalesOrder::where('warehouse_id', (int)$model->id)->count()
            + SupplyOrder::where('warehouse_id', (int)$model->id)->count()
            + PurchaseOrder::where('warehouse_id', (int)$model->id)->count()
            + SalesReturnOrder::where('warehouse_id', (int)$model->id)->count();
        if ($orderCount > 0) {
            self::setError('该仓库已被订单使用，请先处理相关订单后再删除');
            return false;
        }

        return (bool)$model->delete();
    }

    public static function detail(array $params): array
    {
        $model = Warehouse::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            return [];
        }

        return self::formatItem($model->toArray());
    }

    public static function changeStatus(array $params): bool
    {
        $model = Warehouse::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('仓库不存在');
            return false;
        }

        return (bool)$model->save([
            'is_enabled' => (int)$params['status'],
        ]);
    }

    public static function formatItem(array $item): array
    {
        $item['status'] = (int)($item['is_enabled'] ?? 1);
        $item['address'] = trim((string)($item['address'] ?? ''));
        $item['province'] = $item['province'] ?? '';
        $item['city'] = $item['city'] ?? '';
        $item['district'] = $item['district'] ?? '';
        $item['address_detail'] = $item['address_detail'] ?? '';
        $item['contact'] = $item['contact'] ?? '';
        $item['phone'] = $item['phone'] ?? '';
        return $item;
    }

    protected static function buildSaveData(array $params, array $current = []): array
    {
        $province = trim((string)($params['province'] ?? ($current['province'] ?? '')));
        $city = trim((string)($params['city'] ?? ($current['city'] ?? '')));
        $district = trim((string)($params['district'] ?? ($current['district'] ?? '')));
        $addressDetail = trim((string)($params['address_detail'] ?? ($params['addressDetail'] ?? ($current['address_detail'] ?? ''))));
        $address = trim((string)($params['address'] ?? ''));

        if ($address === '') {
            $address = $province . $city . $district . $addressDetail;
        }

        return [
            'name' => trim((string)$params['name']),
            'province' => $province,
            'city' => $city,
            'district' => $district,
            'address_detail' => $addressDetail,
            'address' => $address,
            'contact' => trim((string)($params['contact'] ?? ($current['contact'] ?? ''))),
            'phone' => trim((string)($params['phone'] ?? ($current['phone'] ?? ''))),
            'is_enabled' => (int)($params['status'] ?? ($params['is_enabled'] ?? ($current['is_enabled'] ?? 1))),
            'sort' => (int)($params['sort'] ?? ($current['sort'] ?? 0)),
        ];
    }
}
