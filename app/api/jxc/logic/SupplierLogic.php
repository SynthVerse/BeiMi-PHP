<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\GoodsSupplier;
use app\common\model\jxc\SupplyOrder;
use app\common\model\jxc\Vendor;
use think\facade\Db;

class SupplierLogic extends BaseLogic
{
    public static function add(array $params): array|false
    {
        if (self::tenantId() <= 0) {
            self::setError('供应商租户上下文缺失，请重新登录');
            return false;
        }
        if (Vendor::where('tenant_id', self::tenantId())
            ->where('supplier_name', trim($params['supplier_name']))
            ->count() > 0
        ) {
            self::setError('供应商名称已存在');
            return false;
        }

        Db::startTrans();
        try {
            $supplier = Vendor::create(self::buildSaveData($params));
            Db::commit();
            return ['id' => (int)$supplier->id];
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function edit(array $params): bool
    {
        $model = Vendor::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('供应商不存在');
            return false;
        }

        $duplicate = Vendor::where('supplier_name', trim($params['supplier_name']))
            ->where('tenant_id', self::tenantId())
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
        $model = Vendor::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            self::setError('供应商不存在');
            return false;
        }

        $relationCount = GoodsSupplier::where('supplier_id', (int)$model->id)
            ->where('tenant_id', self::tenantId())
            ->count();
        if ($relationCount > 0) {
            self::setError('该供应商已被商品关联，请先解除商品供应商关系后再删除');
            return false;
        }

        $supplyCount = SupplyOrder::where('supplier_id', (int)$model->id)
            ->where('tenant_id', self::tenantId())
            ->count();
        if ($supplyCount > 0) {
            self::setError('该供应商已被进货单使用，请先删除相关订单后再删除');
            return false;
        }

        return (bool)$model->delete();
    }

    public static function detail(array $params): array
    {
        $model = Vendor::where('id', (int)$params['id'])
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($model->isEmpty()) {
            return [];
        }

        $item = self::formatItem($model->toArray());
        $item['goods_count'] = (int)GoodsSupplier::where('supplier_id', (int)$item['id'])
            ->where('tenant_id', self::tenantId())
            ->count();
        return $item;
    }

    public static function goods(array $params): array
    {
        $supplierId = (int)($params['supplier_id'] ?? $params['id'] ?? 0);
        if ($supplierId <= 0) {
            return [
                'data' => [],
                'total' => 0,
                'page' => 1,
                'pagesize' => 15,
            ];
        }

        $supplier = Vendor::where('id', $supplierId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($supplier->isEmpty()) {
            return [
                'data' => [],
                'total' => 0,
                'page' => 1,
                'pagesize' => 15,
            ];
        }

        $page = max(1, (int)($params['page_no'] ?? $params['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? $params['pagesize'] ?? 15)));
        $offset = ($page - 1) * $pageSize;

        $query = Db::name('goods_supplier')
            ->alias('gs')
            ->join('goods g', 'g.id = gs.goods_id')
            ->where('gs.tenant_id', self::tenantId())
            ->where('g.tenant_id', self::tenantId())
            ->where('gs.supplier_id', $supplierId);

        $total = (clone $query)->count();
        $rows = $query
            ->field('gs.id AS relation_id,gs.supplier_id,gs.is_primary,gs.supplier_product_code,gs.purchase_price,gs.min_purchase_qty,gs.lead_time_days,gs.last_purchase_price,gs.last_purchase_time,gs.status AS relation_status,gs.remark AS relation_remark,g.id,g.name,g.product_code,g.units,g.unit_id,g.price,g.cost,g.stock,g.category_id,g.primary_supplier_id,g.is_disabled,g.remark,g.create_time,g.update_time')
            ->order(['gs.is_primary' => 'desc', 'gs.id' => 'desc'])
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $data = [];
        foreach ($rows as $row) {
            $item = GoodsLogic::formatItem($row);
            $item['relation_id'] = (int)($row['relation_id'] ?? 0);
            $item['supplier_id'] = (int)$supplierId;
            $item['supplier_name'] = (string)($supplier->supplier_name ?? '');
            $item['is_primary_supplier'] = (int)($row['is_primary'] ?? 0);
            $item['supplier_product_code'] = (string)($row['supplier_product_code'] ?? '');
            $item['supplier_purchase_price'] = (string)($row['purchase_price'] ?? '0.00');
            $item['min_purchase_qty'] = (string)($row['min_purchase_qty'] ?? '0.0000');
            $item['lead_time_days'] = (int)($row['lead_time_days'] ?? 0);
            $item['last_purchase_price'] = (string)($row['last_purchase_price'] ?? '0.00');
            $item['last_purchase_time'] = (int)($row['last_purchase_time'] ?? 0);
            $item['relation_status'] = (int)($row['relation_status'] ?? 1);
            $item['relation_remark'] = (string)($row['relation_remark'] ?? '');
            $data[] = $item;
        }

        return [
            'data' => $data,
            'total' => (int)$total,
            'page' => $page,
            'pagesize' => $pageSize,
        ];
    }

    public static function paymoney(array $params): array|false
    {
        $supplierId = (int)($params['supplier_id'] ?? $params['id'] ?? 0);
        $amount = self::money(max(0, (float)($params['money'] ?? $params['amount'] ?? 0)));
        if ($supplierId <= 0) {
            self::setError('供应商不存在');
            return false;
        }
        if (bccomp($amount, '0', 2) <= 0) {
            self::setError('请输入付款金额');
            return false;
        }

        Db::startTrans();
        try {
            $model = Vendor::where('id', $supplierId)
                ->where('tenant_id', self::tenantId())
                ->lock(true)
                ->findOrEmpty();
            if ($model->isEmpty()) {
                self::setError('供应商不存在');
                Db::rollback();
                return false;
            }
            if ((int)($model->is_disabled ?? 0) === 1) {
                self::setError('停用供应商不可付款，请先启用');
                Db::rollback();
                return false;
            }

            $currentPayable = (string)($model->order_payable ?? '0.00');
            if (bccomp($currentPayable, '0', 2) <= 0) {
                self::setError('当前无可付款金额');
                Db::rollback();
                return false;
            }
            if (bccomp($amount, $currentPayable, 2) > 0) {
                self::setError('付款金额不能超过当前欠额');
                Db::rollback();
                return false;
            }

            $orderSn = 'PAY-' . date('YmdHis') . '-' . $supplierId . '-' . random_int(1000, 9999);
            $remark = trim((string)($params['remark'] ?? '供应商付款'));
            $reduced = FinanceService::reducePayable(
                $supplierId,
                $amount,
                0,
                'manual_payment',
                $orderSn,
                $remark
            );
            if (!$reduced) {
                self::setError('付款失败，请稍后重试');
                Db::rollback();
                return false;
            }

            Db::commit();
            return self::detail(['id' => $supplierId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
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
        $item['order_payable'] = (string)($item['order_payable'] ?? '0.00');
        $item['order_paid_money'] = (string)($item['order_paid_money'] ?? '0.00');
        return $item;
    }

    protected static function buildSaveData(array $params, array $current = []): array
    {
        return [
            'tenant_id' => self::tenantId(),
            'supplier_name' => trim((string)$params['supplier_name']),
            'contact' => trim((string)($params['contact'] ?? ($current['contact'] ?? ''))),
            'phone' => trim((string)($params['phone'] ?? ($current['phone'] ?? ''))),
            'address' => trim((string)($params['address'] ?? ($current['address'] ?? ''))),
            'remark' => trim((string)($params['remark'] ?? ($current['remark'] ?? ''))),
            'is_disabled' => (int)($params['is_disabled'] ?? ($current['is_disabled'] ?? 0)),
        ];
    }

    protected static function money(mixed $value): string
    {
        return number_format(max(0, (float)$value), 2, '.', '');
    }

    protected static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
