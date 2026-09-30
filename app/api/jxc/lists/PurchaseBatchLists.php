<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\PurchasePlanLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\PurchaseBatch;

class PurchaseBatchLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            throw new \RuntimeException('租户无效');
        }

        $query = PurchaseBatch::field([
            'id', 'purchase_plan_id', 'batch_no', 'warehouse_id', 'warehouse_name', 'datetimesingle', 'remarks',
            'status', 'supplier_count', 'line_count', 'total_amount', 'create_time', 'update_time',
        ])->where('tenant_id', $tenantId);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['batch_no'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('batch_no', '%' . $keyword . '%');
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
        $rows = $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        $suggestions = PurchasePlanLogic::prioritySuggestions(array_map('intval', array_column($rows, 'purchase_plan_id')));
        foreach ($rows as &$row) {
            $planId = (int)($row['purchase_plan_id'] ?? 0);
            $row['priority_suggestion'] = $suggestions[$planId] ?? PurchasePlanLogic::emptyPrioritySuggestion();
        }
        unset($row);
        return $rows;
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
