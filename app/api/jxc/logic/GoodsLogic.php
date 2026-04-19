<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsUnit;
use think\facade\Db;

class GoodsLogic extends BaseLogic
{
    public static function add(array $params): array|false
    {
        $saveData = self::buildSaveData($params);
        if (!self::assertUnique($saveData)) {
            return false;
        }

        Db::startTrans();
        try {
            $goods = Goods::create($saveData);
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
        $model = Goods::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }

        $saveData = self::buildSaveData($params, $model->toArray());
        if (!self::assertUnique($saveData, (int)$params['id'])) {
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
        $model = Goods::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('商品不存在');
            return false;
        }

        return (bool)$model->delete();
    }

    public static function detail(array $params): array
    {
        $model = Goods::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            return [];
        }

        return self::formatItem($model->toArray());
    }

    public static function formatItem(array $item): array
    {
        $item['product_name'] = $item['name'] ?? '';
        $item['product_code'] = $item['product_code'] ?? '';
        $item['units'] = $item['units'] ?? '';
        $item['unit'] = $item['units'];
        $item['unit_id'] = (int)($item['unit_id'] ?? 0);
        $item['units_id'] = $item['unit_id'];
        $item['price'] = (string)($item['price'] ?? '0.00');
        $item['units_money'] = $item['price'];
        $item['cost'] = (string)($item['cost'] ?? '0.00');
        $item['stock'] = (string)($item['stock'] ?? '0.00');
        $item['category_id'] = (int)($item['category_id'] ?? 0);
        $item['is_disabled'] = (int)($item['is_disabled'] ?? 0);
        $item['status'] = $item['is_disabled'] === 1 ? 0 : 1;
        $item['remark'] = $item['remark'] ?? '';
        return $item;
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

        return [
            'name' => $name,
            'product_code' => trim((string)($params['product_code'] ?? ($current['product_code'] ?? ''))),
            'units' => $units,
            'unit_id' => $unitId,
            'price' => self::normalizeDecimal($params['price'] ?? $params['units_money'] ?? ($current['price'] ?? 0)),
            'cost' => self::normalizeDecimal($params['cost'] ?? ($current['cost'] ?? 0)),
            'stock' => self::normalizeDecimal($params['stock'] ?? ($current['stock'] ?? 0)),
            'category_id' => (int)($params['category_id'] ?? ($current['category_id'] ?? 0)),
            'is_disabled' => (int)($params['is_disabled'] ?? ($current['is_disabled'] ?? 0)),
            'remark' => trim((string)($params['remark'] ?? ($current['remark'] ?? ''))),
        ];
    }

    protected static function assertUnique(array $data, int $ignoreId = 0): bool
    {
        $query = Goods::where('name', $data['name'])
            ->where('unit_id', (int)$data['unit_id']);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('相同名称和单位的商品已存在');
            return false;
        }

        if ($data['product_code'] !== '') {
            $codeQuery = Goods::where('product_code', $data['product_code']);
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

    protected static function normalizeDecimal(mixed $value): string
    {
        return number_format(max(0, (float)$value), 2, '.', '');
    }
}
