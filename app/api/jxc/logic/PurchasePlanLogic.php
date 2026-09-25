<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 缺货采购计划：归集来源，实际到货后由用户显式分配，不做预分配。 */
final class PurchasePlanLogic extends BaseLogic
{
    private const SCALE = 4;
    private const ACTIVE = ['pending', 'partial'];

    /** @return array{lists:array<int,array<string,mixed>>,count:int,page_no:int,page_size:int}|false */
    public static function lists(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requireAnyPermission(['purchase.plan.view', 'purchase.plan.manage'])) {
            return false;
        }
        $page = max(1, (int)($params['page_no'] ?? 1));
        $size = min(100, max(1, (int)($params['page_size'] ?? 20)));
        $query = Db::name('purchase_plan')->where('tenant_id', self::tenantId());
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $count = (int)(clone $query)->count();
        $rows = $query->order('id desc')->page($page, $size)->select()->toArray();
        foreach ($rows as &$row) {
            $row['remaining_qty'] = self::decimal(bcsub(
                (string)$row['planned_qty'],
                (string)$row['allocated_qty'],
                self::SCALE
            ));
            $row['source_count'] = (int)Db::name('purchase_plan_source')->where('tenant_id', self::tenantId())
                ->where('purchase_plan_id', (int)$row['id'])->count();
        }
        unset($row);
        return ['lists' => $rows, 'count' => $count, 'page_no' => $page, 'page_size' => $size];
    }

    /** @return array<string,mixed>|false */
    public static function detail(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requireAnyPermission(['purchase.plan.view', 'purchase.plan.manage'])) {
            return false;
        }
        return self::detailById((int)($params['id'] ?? 0));
    }

    /** @return array<string,mixed>|false */
    public static function arrivalPreview(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requireAnyPermission(['purchase.plan.view', 'purchase.plan.manage'])) {
            return false;
        }
        $plan = Db::name('purchase_plan')->where('tenant_id', self::tenantId())
            ->where('id', (int)($params['id'] ?? 0))->find();
        $batch = Db::name('purchase_batch')->where('tenant_id', self::tenantId())
            ->where('id', (int)($params['purchase_batch_id'] ?? 0))->find();
        if (!$plan || !in_array((string)$plan['status'], self::ACTIVE, true)
            || !$batch || (int)$plan['warehouse_id'] !== (int)$batch['warehouse_id']) {
            self::setError('采购计划或采购批次不存在，或入库仓库不一致');
            return false;
        }
        if ((int)($batch['purchase_plan_id'] ?? 0) > 0 && (int)$batch['purchase_plan_id'] !== (int)$plan['id']) {
            self::setError('该采购批次已归属其他采购计划');
            return false;
        }
        $quantity = self::arrivalQuantity((int)$batch['id'], (int)$plan['sku_id']);
        if (bccomp($quantity, '0.0000', self::SCALE) <= 0) {
            self::setError('采购批次没有该计划 SKU 的实际到货数量');
            return false;
        }
        return [
            'purchase_plan_id' => (int)$plan['id'],
            'purchase_batch_id' => (int)$batch['id'],
            'batch_no' => (string)$batch['batch_no'],
            'arrival_qty' => $quantity,
            'base_unit_name' => (string)$plan['base_unit_name'],
        ];
    }

    /** @return array<string,mixed>|false */
    public static function create(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('purchase.plan.manage')) {
            return false;
        }
        $taskIds = array_values(array_unique(array_filter(array_map('intval', (array)($params['task_ids'] ?? [])))));
        sort($taskIds, SORT_NUMERIC);
        if ($taskIds === []) {
            self::setError('请至少选择一个缺货采购任务');
            return false;
        }
        try {
            return Db::transaction(static function () use ($taskIds) {
                $rows = Db::name('fulfillment_task')->alias('t')
                    ->join('customer_report_item i', 'i.id=t.report_item_id AND i.tenant_id=t.tenant_id')
                    ->join('customer_report r', 'r.id=t.report_id AND r.tenant_id=t.tenant_id')
                    ->where('t.tenant_id', self::tenantId())->whereIn('t.id', $taskIds)
                    ->whereNull('i.delete_time')->whereNull('r.delete_time')
                    ->field('t.id AS task_id,t.source_key,t.status AS task_status,t.report_id,t.report_item_id,'
                        . 'i.warehouse_id,i.goods_id,i.goods_name,i.sku_id,i.sku_name,i.base_unit_name,i.shortage_base_qty,'
                        . 'r.batch_id,r.main_customer_name,r.delivery_date')
                    ->order('t.id')->lock(true)->select()->toArray();
                if (count($rows) !== count($taskIds)) {
                    self::setError('所选采购任务不存在或已失效');
                    return false;
                }
                $scope = null;
                foreach ($rows as $row) {
                    if (preg_match('/^item:\d+:shortage$/', (string)$row['source_key']) !== 1
                        || in_array((string)$row['task_status'], ['cancelled', 'completed'], true)
                        || bccomp((string)$row['shortage_base_qty'], '0.00', 2) <= 0) {
                        self::setError('只能选择仍有缺口的库存不足采购任务');
                        return false;
                    }
                    $current = [(int)$row['batch_id'], (int)$row['warehouse_id'], (int)$row['sku_id']];
                    if ($scope !== null && $scope !== $current) {
                        self::setError('同一采购计划只能归集相同报货批次、仓库和 SKU 的任务');
                        return false;
                    }
                    $scope = $current;
                }
                [$batchId, $warehouseId, $skuId] = $scope;
                $scopeKey = hash('sha256', $batchId . ':' . $warehouseId . ':' . $skuId);
                $plan = Db::name('purchase_plan')->where('tenant_id', self::tenantId())
                    ->where('active_scope_key', $scopeKey)->whereIn('status', self::ACTIVE)->lock(true)->find();
                $now = time();
                if (!$plan) {
                    $first = $rows[0];
                    $warehouseName = (string)Db::name('warehouse')->where('tenant_id', self::tenantId())
                        ->where('id', $warehouseId)->value('name');
                    $planId = (int)Db::name('purchase_plan')->insertGetId([
                        'tenant_id' => self::tenantId(), 'batch_id' => $batchId,
                        'warehouse_id' => $warehouseId, 'warehouse_name' => $warehouseName,
                        'goods_id' => (int)$first['goods_id'], 'goods_name' => (string)$first['goods_name'],
                        'sku_id' => $skuId, 'sku_name' => (string)$first['sku_name'],
                        'base_unit_name' => (string)$first['base_unit_name'],
                        'planned_qty' => '0.0000', 'arrived_qty' => '0.0000',
                        'allocated_qty' => '0.0000', 'surplus_qty' => '0.0000',
                        'status' => 'pending', 'active_scope_key' => $scopeKey,
                        'termination_reason' => '', 'create_time' => $now, 'update_time' => $now,
                    ]);
                } else {
                    $planId = (int)$plan['id'];
                }
                foreach ($rows as $row) {
                    $activeSource = Db::name('purchase_plan_source')->alias('s')
                        ->join('purchase_plan p', 'p.id=s.purchase_plan_id AND p.tenant_id=s.tenant_id')
                        ->where('s.tenant_id', self::tenantId())->where('s.task_id', (int)$row['task_id'])
                        ->whereIn('p.status', self::ACTIVE)->lock(true)->field('s.id,s.purchase_plan_id')->find();
                    if ($activeSource && (int)$activeSource['purchase_plan_id'] !== $planId) {
                        self::setError('所选采购任务已经归入其他活动采购计划');
                        return false;
                    }
                    if ($activeSource) {
                        continue;
                    }
                    Db::name('purchase_plan_source')->insert([
                        'tenant_id' => self::tenantId(), 'purchase_plan_id' => $planId,
                        'task_id' => (int)$row['task_id'], 'report_id' => (int)$row['report_id'],
                        'report_item_id' => (int)$row['report_item_id'],
                        'customer_name' => (string)$row['main_customer_name'],
                        'delivery_date' => $row['delivery_date'] ?: null,
                        'shortage_qty' => self::decimal((string)$row['shortage_base_qty']),
                        'allocated_qty' => '0.0000', 'status' => 'pending',
                        'create_time' => $now, 'update_time' => $now,
                    ]);
                }
                self::refreshPlanTotals($planId, $now);
                return self::detailById($planId);
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('采购计划创建失败，请刷新任务后重试');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function attachArrival(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('purchase.plan.manage')) {
            return false;
        }
        $planId = (int)($params['id'] ?? 0);
        $purchaseBatchId = (int)($params['purchase_batch_id'] ?? 0);
        $surplusText = trim((string)($params['surplus_qty'] ?? '0'));
        if ($planId <= 0 || $purchaseBatchId <= 0 || !self::validDecimal($surplusText, 4, true)) {
            self::setError('采购计划、采购批次或库存余量无效');
            return false;
        }
        $allocations = [];
        foreach ((array)($params['allocations'] ?? []) as $entry) {
            $sourceId = (int)($entry['source_id'] ?? 0);
            $quantityText = trim((string)($entry['allocated_qty'] ?? ''));
            if ($sourceId <= 0 || isset($allocations[$sourceId]) || !self::validDecimal($quantityText, 2, false)) {
                self::setError('来源分配必须唯一、数量大于 0 且最多保留两位小数');
                return false;
            }
            $allocations[$sourceId] = self::decimal($quantityText);
        }
        $surplus = self::decimal($surplusText);
        try {
            return Db::transaction(static function () use ($planId, $purchaseBatchId, $allocations, $surplus) {
                $plan = Db::name('purchase_plan')->where('tenant_id', self::tenantId())->where('id', $planId)
                    ->lock(true)->find();
                if (!$plan) {
                    self::setError('采购计划不存在');
                    return false;
                }
                $batch = Db::name('purchase_batch')->where('tenant_id', self::tenantId())->where('id', $purchaseBatchId)
                    ->lock(true)->find();
                if (!$batch || (int)$batch['warehouse_id'] !== (int)$plan['warehouse_id']) {
                    self::setError('采购批次不存在或入库仓库与计划不一致');
                    return false;
                }
                if ((int)($batch['purchase_plan_id'] ?? 0) > 0 && (int)$batch['purchase_plan_id'] !== $planId) {
                    self::setError('该采购批次已归属其他采购计划');
                    return false;
                }
                $existing = Db::name('purchase_plan_arrival')->where('tenant_id', self::tenantId())
                    ->where('purchase_plan_id', $planId)->where('purchase_batch_id', $purchaseBatchId)->find();
                if ($existing) {
                    $savedAllocations = [];
                    $savedRows = Db::name('purchase_plan_allocation')->where('tenant_id', self::tenantId())
                        ->where('arrival_id', (int)$existing['id'])->order('source_id')->select()->toArray();
                    foreach ($savedRows as $savedRow) {
                        $savedAllocations[(int)$savedRow['source_id']] = self::decimal((string)$savedRow['allocated_qty']);
                    }
                    if (bccomp((string)$existing['surplus_qty'], $surplus, self::SCALE) !== 0
                        || count($savedAllocations) !== count($allocations)) {
                        self::setError('该采购批次已经按不同内容完成来源分配，不能覆盖原结果');
                        return false;
                    }
                    foreach ($allocations as $sourceId => $quantity) {
                        if (!isset($savedAllocations[$sourceId])
                            || bccomp($savedAllocations[$sourceId], $quantity, self::SCALE) !== 0) {
                            self::setError('该采购批次已经按不同内容完成来源分配，不能覆盖原结果');
                            return false;
                        }
                    }
                    return self::detailById($planId);
                }
                if (!in_array((string)$plan['status'], self::ACTIVE, true)) {
                    self::setError('采购计划已结束，不能登记新的到货批次');
                    return false;
                }
                $arrivalQty = self::arrivalQuantity($purchaseBatchId, (int)$plan['sku_id']);
                if (bccomp($arrivalQty, '0.0000', self::SCALE) <= 0) {
                    self::setError('采购批次没有该计划 SKU 的实际到货数量');
                    return false;
                }
                $sources = Db::name('purchase_plan_source')->where('tenant_id', self::tenantId())
                    ->where('purchase_plan_id', $planId)->order('id')->lock(true)->select()->toArray();
                $sourcesById = [];
                foreach ($sources as $source) {
                    $sourcesById[(int)$source['id']] = $source;
                }
                $allocatedTotal = '0.0000';
                foreach ($allocations as $sourceId => $quantity) {
                    $source = $sourcesById[$sourceId] ?? null;
                    if (!$source || (string)$source['status'] === 'released') {
                        self::setError('到货分配包含不属于当前计划的来源');
                        return false;
                    }
                    $remaining = bcsub((string)$source['shortage_qty'], (string)$source['allocated_qty'], self::SCALE);
                    if (bccomp($quantity, $remaining, self::SCALE) > 0) {
                        self::setError('到货分配数量超过来源剩余缺口');
                        return false;
                    }
                    $allocatedTotal = bcadd($allocatedTotal, $quantity, self::SCALE);
                }
                if (bccomp(bcadd($allocatedTotal, $surplus, self::SCALE), $arrivalQty, self::SCALE) !== 0) {
                    self::setError('来源分配数量与库存余量之和必须等于本批实际到货数量');
                    return false;
                }
                $now = time();
                $arrivalId = (int)Db::name('purchase_plan_arrival')->insertGetId([
                    'tenant_id' => self::tenantId(), 'purchase_plan_id' => $planId,
                    'purchase_batch_id' => $purchaseBatchId, 'arrival_qty' => $arrivalQty,
                    'allocated_qty' => $allocatedTotal, 'surplus_qty' => $surplus, 'create_time' => $now,
                ]);
                foreach ($allocations as $sourceId => $quantity) {
                    $source = $sourcesById[$sourceId];
                    if (!CustomerReportLogic::allocatePurchaseForItemWithinTransaction(
                        (int)$source['report_item_id'],
                        $quantity
                    )) {
                        self::setError(CustomerReportLogic::getError());
                        throw new \RuntimeException('source_allocation_failed');
                    }
                    $newAllocated = bcadd((string)$source['allocated_qty'], $quantity, self::SCALE);
                    $sourceStatus = bccomp($newAllocated, (string)$source['shortage_qty'], self::SCALE) === 0
                        ? 'fulfilled' : 'partial';
                    Db::name('purchase_plan_source')->where('tenant_id', self::tenantId())->where('id', $sourceId)->update([
                        'allocated_qty' => $newAllocated, 'status' => $sourceStatus, 'update_time' => $now,
                    ]);
                    Db::name('purchase_plan_allocation')->insert([
                        'tenant_id' => self::tenantId(), 'purchase_plan_id' => $planId,
                        'arrival_id' => $arrivalId, 'source_id' => $sourceId,
                        'allocated_qty' => $quantity, 'create_time' => $now,
                    ]);
                }
                Db::name('purchase_batch')->where('tenant_id', self::tenantId())->where('id', $purchaseBatchId)
                    ->update(['purchase_plan_id' => $planId, 'update_time' => $now]);
                self::refreshPlanTotals($planId, $now);
                return self::detailById($planId);
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('采购到货分配失败，库存和来源均未改变');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function terminate(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('purchase.plan.manage')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $reason = trim((string)($params['reason'] ?? ''));
        if ($id <= 0 || $reason === '' || mb_strlen($reason) > 500) {
            self::setError('终止采购计划必须填写原因');
            return false;
        }
        try {
            return Db::transaction(static function () use ($id, $reason) {
                $plan = Db::name('purchase_plan')->where('tenant_id', self::tenantId())->where('id', $id)
                    ->lock(true)->find();
                if (!$plan || !in_array((string)$plan['status'], self::ACTIVE, true)) {
                    self::setError('采购计划不存在或已结束');
                    return false;
                }
                $now = time();
                Db::name('purchase_plan_source')->where('tenant_id', self::tenantId())->where('purchase_plan_id', $id)
                    ->whereIn('status', ['pending', 'partial'])->update(['status' => 'released', 'update_time' => $now]);
                Db::name('purchase_plan')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                    'status' => 'terminated', 'active_scope_key' => null,
                    'termination_reason' => $reason, 'update_time' => $now,
                ]);
                return self::detailById($id);
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('采购计划终止失败');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    private static function detailById(int $id): array|false
    {
        $plan = Db::name('purchase_plan')->where('tenant_id', self::tenantId())->where('id', $id)->find();
        if (!$plan) {
            self::setError('采购计划不存在');
            return false;
        }
        $sources = Db::name('purchase_plan_source')->where('tenant_id', self::tenantId())
            ->where('purchase_plan_id', $id)->order(['delivery_date' => 'asc', 'id' => 'asc'])->select()->toArray();
        foreach ($sources as &$source) {
            $source['remaining_qty'] = self::decimal(bcsub(
                (string)$source['shortage_qty'],
                (string)$source['allocated_qty'],
                self::SCALE
            ));
        }
        unset($source);
        $arrivals = Db::name('purchase_plan_arrival')->where('tenant_id', self::tenantId())
            ->where('purchase_plan_id', $id)->order('id')->select()->toArray();
        foreach ($arrivals as &$arrival) {
            $arrival['allocations'] = Db::name('purchase_plan_allocation')->where('tenant_id', self::tenantId())
                ->where('arrival_id', (int)$arrival['id'])->order('id')->select()->toArray();
        }
        unset($arrival);
        $attachedBatchIds = array_map('intval', array_column($arrivals, 'purchase_batch_id'));
        $pendingBatchQuery = Db::name('purchase_batch')->where('tenant_id', self::tenantId())
            ->where('purchase_plan_id', $id);
        if ($attachedBatchIds !== []) {
            $pendingBatchQuery->whereNotIn('id', $attachedBatchIds);
        }
        $pendingArrivalBatches = $pendingBatchQuery->order('id')->field('id,batch_no,datetimesingle,status')->select()->toArray();
        $plan['remaining_qty'] = self::decimal(bcsub(
            (string)$plan['planned_qty'],
            (string)$plan['allocated_qty'],
            self::SCALE
        ));
        $plan['sources'] = $sources;
        $plan['arrivals'] = $arrivals;
        $plan['pending_arrival_batches'] = $pendingArrivalBatches;
        return $plan;
    }

    private static function refreshPlanTotals(int $planId, int $now): void
    {
        $sources = Db::name('purchase_plan_source')->where('tenant_id', self::tenantId())
            ->where('purchase_plan_id', $planId)->select()->toArray();
        $planned = '0.0000';
        $allocated = '0.0000';
        foreach ($sources as $source) {
            $planned = bcadd($planned, (string)$source['shortage_qty'], self::SCALE);
            $allocated = bcadd($allocated, (string)$source['allocated_qty'], self::SCALE);
        }
        $arrivals = Db::name('purchase_plan_arrival')->where('tenant_id', self::tenantId())
            ->where('purchase_plan_id', $planId)->select()->toArray();
        $arrived = '0.0000';
        $surplus = '0.0000';
        foreach ($arrivals as $arrival) {
            $arrived = bcadd($arrived, (string)$arrival['arrival_qty'], self::SCALE);
            $surplus = bcadd($surplus, (string)$arrival['surplus_qty'], self::SCALE);
        }
        $complete = $sources !== [] && bccomp($planned, $allocated, self::SCALE) === 0;
        $update = [
            'planned_qty' => $planned, 'arrived_qty' => $arrived,
            'allocated_qty' => $allocated, 'surplus_qty' => $surplus,
            'status' => $complete ? 'complete' : ($arrivals === [] ? 'pending' : 'partial'),
            'update_time' => $now,
        ];
        if ($complete) {
            $update['active_scope_key'] = null;
        }
        Db::name('purchase_plan')->where('tenant_id', self::tenantId())->where('id', $planId)->update($update);
    }

    private static function validDecimal(string $value, int $scale, bool $allowZero): bool
    {
        if (preg_match('/^\d+(?:\.\d{1,' . $scale . '})?$/', $value) !== 1) {
            return false;
        }
        return bccomp($value, '0', $scale) >= ($allowZero ? 0 : 1);
    }

    private static function arrivalQuantity(int $purchaseBatchId, int $skuId): string
    {
        $supplyOrderIds = array_map('intval', Db::name('purchase_batch_supply_order')
            ->where('tenant_id', self::tenantId())->where('purchase_batch_id', $purchaseBatchId)
            ->column('supply_order_id'));
        if ($supplyOrderIds === []) {
            return '0.0000';
        }
        $rows = Db::name('order_goods')->where('tenant_id', self::tenantId())
            ->where('order_type', 'supply')->whereIn('order_id', $supplyOrderIds)
            ->where('sku_id', $skuId)->field('actual_base_qty')->select()->toArray();
        $quantity = '0.0000';
        foreach ($rows as $row) {
            $quantity = bcadd($quantity, (string)$row['actual_base_qty'], self::SCALE);
        }
        return self::decimal($quantity);
    }

    private static function decimal(string $value): string
    {
        return bcadd($value, '0', self::SCALE);
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
