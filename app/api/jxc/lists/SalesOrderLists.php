<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\SalesOrderLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\SalesOrder;

class SalesOrderLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = SalesOrder::field([
            'id',
            'order_sn',
            'customer_id',
            'customer_name',
            'warehouse_id',
            'order_money',
            'order_pay_money',
            'order_arrears_money',
            'datetimesingle',
            'status',
            'purpose_type',
            'remarks',
            'admin_id',
            'create_time',
            'update_time',
        ]);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['order_sn'] ?? $this->params['customer_name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('order_sn', '%' . $keyword . '%')
                    ->whereOr('customer_name', 'like', '%' . $keyword . '%');
            });
        }

        $customerId = (int)($this->params['customer_id'] ?? 0);
        if ($customerId > 0) {
            $query->where('customer_id', $customerId);
        }

        $warehouseId = (int)($this->params['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ((int)($this->params['returnable_only'] ?? 0) === 1) {
            $query->where('status', '<>', SalesOrder::STATUS_RETURNED);
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

        return SalesOrderLogic::formatList($lists);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
