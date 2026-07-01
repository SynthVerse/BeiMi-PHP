<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\PurchaseReturnOrderLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\PurchaseReturnOrder;

class PurchaseReturnOrderLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = PurchaseReturnOrder::field([
            'id',
            'order_sn',
            'original_supply_order_id',
            'original_order_sn',
            'supplier_id',
            'supplier_name',
            'warehouse_id',
            'order_money',
            'return_reason',
            'datetimesingle',
            'status',
            'remarks',
            'admin_id',
            'create_time',
            'update_time',
        ]);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['order_sn'] ?? $this->params['supplier_name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('order_sn', '%' . $keyword . '%')
                    ->whereOr('supplier_name', 'like', '%' . $keyword . '%')
                    ->whereOr('original_order_sn', 'like', '%' . $keyword . '%');
            });
        }

        $supplierId = (int)($this->params['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $query->where('supplier_id', $supplierId);
        }

        $warehouseId = (int)($this->params['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $query->where('warehouse_id', $warehouseId);
        }

        $startTime = (int)($this->params['start_time'] ?? 0);
        $endTime = (int)($this->params['end_time'] ?? 0);
        if ($startTime > 0 && $endTime > 0) {
            $query->whereBetween('datetimesingle', [$startTime, $endTime]);
        } elseif ($startTime > 0) {
            $query->where('datetimesingle', '>=', $startTime);
        } elseif ($endTime > 0) {
            $query->where('datetimesingle', '<=', $endTime);
        }

        return $query->order(['datetimesingle' => 'desc', 'id' => 'desc']);
    }

    public function lists(): array
    {
        $lists = $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        return PurchaseReturnOrderLogic::formatList($lists);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
