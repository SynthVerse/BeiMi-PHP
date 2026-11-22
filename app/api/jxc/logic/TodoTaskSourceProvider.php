<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 任务域待办适配器；业务完成状态仍由原任务/交付页面维护。 */
final class TodoTaskSourceProvider
{
    /** @param array<int,string|int>|null $after @return array{count:int,after_count:int,items:array<int,array<string,mixed>>} */
    public static function query(string $source, int $limit, ?array $after, array $filters = []): array
    {
        $buffer = new TodoItemBuffer($source, 'task', $limit, $after, $filters);
        match ($source) {
            'S01' => self::reports($buffer),
            'S03' => self::tasks($buffer),
            'S28' => self::pendingGoods($buffer),
            default => null,
        };
        return $buffer->finish();
    }

    private static function tasks(TodoItemBuffer $buffer): void
    {
        if (!FinanceAccess::has('task.control')) { return; }
        $excluded = ['blocked', 'printable', 'print_failed', 'recovered', 'ready_to_bill', 'completed', 'cancelled'];
        self::taskChunks(static fn($query) => $query->whereNotIn('t.status', $excluded)
            ->where(static fn($query) => $query->where('t.task_type', 'exception')->whereOr('t.exception_code', '<>', ''))
            ->whereNotLike('t.source_key', '%:delivery'), static function (array $row) use ($buffer): void {
                $code = (string)$row['exception_code'];
                if (in_array($code, ['missing_inventory_shortage_process', 'missing_delivery_process'], true)
                    && !FinanceAccess::has('process.manage')) { return; }
                if (in_array($code, ['missing_remark', 'unrecognized_remark'], true)
                    && !FinanceAccess::has('report.remark')) { return; }
                $date = (string)($row['delivery_date'] ?? '') ?: self::dateFromTime((int)$row['create_time']);
                $buffer->add(self::item('task:' . $row['id'], 'fulfillment_task', (int)$row['id'],
                    '任务待处理：' . self::taskName($row), (string)$row['customer_name'],
                    $date, null,
                    (string)$row['status'] . ((string)$row['exception_code'] !== '' ? ' / ' . (string)$row['exception_code'] : ''),
                    '查看任务', 'fulfillment_task', ['id' => (int)$row['id'], 'report_id' => (int)$row['report_id'],
                        'delivery_date' => $date]));
            });
    }

    private static function reports(TodoItemBuffer $buffer): void
    {
        if (self::any(['report.edit', 'task.control', 'delivery.confirm'])) {
            self::undeliveredReports($buffer);
        }
        if (!FinanceAccess::has('delivery.line.manage')) { return; }
        self::chunks('line_vehicle_trip', static fn($query) => $query
            ->whereNotIn('status', ['completed', 'closed']), static function (array $row) use ($buffer): void {
                $buffer->add(self::item('line-trip:' . $row['id'], 'line_vehicle_trip', (int)$row['id'],
                    '送站趟次 ' . (string)$row['trip_no'] . ' 待完成', '', (string)$row['trip_date'],
                    (string)$row['trip_date'], (string)$row['status'], '处理送站', 'line_trip',
                    ['id' => (int)$row['id'], 'delivery_date' => (string)$row['trip_date']]));
            });
    }

    private static function pendingGoods(TodoItemBuffer $buffer): void
    {
        if (!FinanceAccess::has('purchase.plan.manage')) { return; }
        $tenant = FinanceAccess::tenant();
        $handoffs = Db::name('pending_goods_return')->where('tenant_id', $tenant)
            ->where('status', 'planned')->where('remaining_qty', '>', 0)
            ->field('pending_goods_id,SUM(remaining_qty) AS handoff_qty')->group('pending_goods_id')->buildSql();
        $query = Db::name('pending_goods')->alias('p')->where('p.tenant_id', $tenant)
            ->leftJoin([$handoffs => 'h'], 'h.pending_goods_id=p.id')
            ->leftJoin('goods g', 'g.id=p.goods_id AND g.tenant_id=p.tenant_id')
            ->leftJoin('goods_sku s', 's.id=p.sku_id AND s.tenant_id=p.tenant_id')
            ->leftJoin('customer_report r', 'r.id=p.report_id AND r.tenant_id=p.tenant_id')
            ->leftJoin('purchase_plan_allocation a', "a.tenant_id=p.tenant_id AND p.origin_key LIKE 'allocation:%' AND a.id=CAST(SUBSTRING_INDEX(p.origin_key, ':', -1) AS UNSIGNED)")
            ->leftJoin('purchase_plan_source src', "src.tenant_id=p.tenant_id AND src.report_id=p.report_id AND src.id=CASE WHEN p.origin_key LIKE 'plan-arrival:%:source:%' THEN CAST(SUBSTRING_INDEX(p.origin_key, ':', -1) AS UNSIGNED) ELSE a.source_id END")
            ->field("p.id,p.report_id,p.goods_id,p.create_time,p.pending_qty,h.handoff_qty,r.sn,r.main_customer_name,src.customer_name,"
                . "COALESCE(NULLIF(src.goods_name,''),g.name) AS goods_name,COALESCE(NULLIF(src.sku_name,''),s.sku_name) AS sku_name,"
                . "COALESCE(NULLIF(src.base_unit_name,''),s.base_unit_name) AS base_unit_name");
        $includeNativeReturns = !FinanceIntegration::active();
        if ($includeNativeReturns) {
            // 财务退货明细及退款沿用各自的待办，只有未启用账套的原业务退货需要在这里接续。
            $resolutions = Db::name('pending_goods_return_resolution')->where('tenant_id', $tenant)
                ->field("fact_id,SUM(CASE WHEN kind IN ('accepted','returned','loss') THEN quantity ELSE 0 END) AS resolved_qty,"
                    . "SUM(CASE WHEN kind='accepted' THEN amount WHEN kind IN ('offset','refund') THEN -amount ELSE 0 END) AS refund_balance")
                ->group('fact_id')->buildSql();
            $openFacts = Db::name('pending_goods_return_fact')->alias('f')
                ->join('pending_goods_return pr', 'pr.tenant_id=f.tenant_id AND pr.id=f.pending_return_id')
                ->leftJoin([$resolutions => 'x'], 'x.fact_id=f.id')
                ->where('f.tenant_id', $tenant)->whereNull('f.finance_line_id')->where('f.closed', 0)
                ->field('pr.pending_goods_id,SUM(f.quantity-COALESCE(x.resolved_qty,0)) AS supplier_qty,SUM(COALESCE(x.refund_balance,0)) AS refund_balance')
                ->group('pr.pending_goods_id')->buildSql();
            $query->leftJoin([$openFacts => 'f'], 'f.pending_goods_id=p.id')->field('f.supplier_qty,f.refund_balance');
        }
        $query->where(static function ($scope) use ($includeNativeReturns) {
            $scope->where('p.pending_qty', '>', 0)->whereOr('h.handoff_qty', '>', 0);
            if ($includeNativeReturns) { $scope->whereOr('f.pending_goods_id', '>', 0); }
        });
        $last = 0;
        do {
            $rows = (clone $query)->where('p.id', '>', $last)->order('p.id')->limit(500)->select()->toArray();
            foreach (TodoQueryBudget::candidates($rows) as $row) {
                $last = (int)$row['id'];
                $quantities = [];
                foreach (['pending_qty' => '待定', 'handoff_qty' => '待退离', 'supplier_qty' => '待供方确认'] as $field => $label) {
                    if (bccomp((string)($row[$field] ?? '0'), '0', 4) > 0) {
                        $unit = $row['base_unit_name'] ?: '（单位待核实）';
                        $quantities[] = $label . ' ' . rtrim(rtrim((string)$row[$field], '0'), '.') . $unit;
                    }
                }
                if (bccomp((string)($row['refund_balance'] ?? '0'), '0', 2) > 0) {
                    $quantities[] = '待处理款项 ¥' . bcadd((string)$row['refund_balance'], '0', 2);
                }
                $goods = (string)$row['goods_name'] ?: '商品 #' . $row['goods_id'];
                $sku = trim((string)$row['sku_name']);
                $customer = $row['customer_name'] ?: $row['main_customer_name'];
                $report = $row['sn'] ?: '报货单 #' . $row['report_id'];
                $buffer->add(self::item('pending-goods:' . $last, 'pending_goods', $last,
                    $sku !== '' && $sku !== $goods ? "$goods · $sku" : $goods,
                    implode(' · ', array_filter([$customer, $report])),
                    self::dateFromTime((int)$row['create_time']), null,
                    $quantities ? implode(' · ', $quantities) : '退货后续待处理',
                    bccomp((string)$row['pending_qty'], '0', 4) > 0 ? '处理余货' : '继续退货',
                    'pending_goods', ['id' => $last, 'report_id' => (int)$row['report_id']]));
            }
        } while (count($rows) === 500);
    }

    private static function undeliveredReports(TodoItemBuffer $buffer): void
    {
        $tenant = FinanceAccess::tenant();
        $itemState = Db::name('customer_report_item')->where('tenant_id', $tenant)->whereNull('delete_time')
            ->field("report_id,COUNT(*) AS item_count,"
                . "SUM(CASE WHEN fulfillment_status NOT IN ('delivered','partially_delivered_completed','undelivered','delivery_exception_completed') THEN 1 ELSE 0 END) AS unresolved_count,"
                . "SUM(CASE WHEN fulfillment_status='pending' THEN 1 ELSE 0 END) AS unmeasured_count,"
                . "SUM(CASE WHEN shortage_base_qty>0 THEN 1 ELSE 0 END) AS shortage_count,"
                . "SUM(CASE WHEN fulfilled_base_qty>0 THEN 1 ELSE 0 END) AS delivered_count")
            ->group('report_id')->buildSql();
        $last = 0;
        do {
            $rows = Db::name('customer_report')->alias('r')
                ->leftJoin([$itemState => 'di'], 'di.report_id=r.id')
                ->where('r.tenant_id', $tenant)->whereNull('r.delete_time')->where('r.submitted_time', '>', 0)
                ->whereNotIn('r.status', array_merge(CustomerReportSalesDocument::CLOSED_REPORT_STATUSES,
                    ['draft', 'cancelled', 'delivery_exception_completed']))
                ->where(static fn($query) => $query->whereNull('di.item_count')->whereOr('di.unresolved_count', '>', 0))
                ->where('r.id', '>', $last)->field('r.*,di.item_count,di.unmeasured_count,di.shortage_count,di.delivered_count')
                ->order('r.id')->limit(500)->select()->toArray();
            foreach (TodoQueryBudget::candidates($rows) as $row) {
                $last = (int)$row['id'];
                $reason = match (true) {
                    (int)($row['delivered_count'] ?? 0) > 0 || (string)$row['status'] === 'partially_delivered_pending' => '部分交付',
                    (int)($row['item_count'] ?? 0) === 0 || (int)($row['unmeasured_count'] ?? 0) > 0
                        || (string)$row['status'] === 'submitted_pending_measurement' => '待称重',
                    (int)($row['shortage_count'] ?? 0) > 0 || (string)$row['status'] === 'submitted_shortage' => '待补货',
                    (string)($row['delivery_arrangement_status'] ?? '') !== 'ready' => '待完善送货安排',
                    default => '待交付',
                };
                $date = (string)($row['delivery_date'] ?? '') ?: self::dateFromTime((int)$row['submitted_time']);
                $action = $reason === '待交付' && FinanceAccess::has('delivery.confirm') ? '确认实际交付' : '查看报货';
                $buffer->add(self::item('delivery-report:' . $last, 'customer_report', $last,
                    '报货单 ' . (string)$row['sn'] . ' · ' . $reason, (string)$row['main_customer_name'],
                    $date, $date, $reason, $action, 'report', ['id' => $last, 'focus' => 'delivery']));
            }
        } while (count($rows) === 500);
    }

    /** @param callable(object):object $scope @param callable(array):void $consume */
    private static function taskChunks(callable $scope, callable $consume): void
    {
        $last = 0;
        do {
            $query = Db::name('fulfillment_task')->alias('t')
                ->leftJoin('fulfillment_task_group g', 'g.tenant_id=t.tenant_id AND g.id=t.group_id')
                ->where('t.tenant_id', FinanceAccess::tenant())->where('t.id', '>', $last)
                ->field('t.*,g.delivery_date');
            $rows = $scope($query)->order('t.id')->limit(500)->select()->toArray();
            foreach (TodoQueryBudget::candidates($rows) as $row) { $last = (int)$row['id']; $consume($row); }
        } while (count($rows) === 500);
    }

    /** @param callable(object):object $scope @param callable(array):void $consume */
    private static function chunks(string $table, callable $scope, callable $consume): void
    {
        $last = 0;
        do {
            $query = Db::name($table)->where('tenant_id', FinanceAccess::tenant())->where('id', '>', $last);
            $rows = $scope($query)->order('id')->limit(500)->select()->toArray();
            foreach (TodoQueryBudget::candidates($rows) as $row) { $last = (int)$row['id']; $consume($row); }
        } while (count($rows) === 500);
    }

    /** @param array<int,string> $permissions */
    private static function any(array $permissions): bool
    {
        foreach ($permissions as $permission) { if (FinanceAccess::has($permission)) { return true; } }
        return false;
    }

    private static function taskName(array $row): string
    {
        return trim((string)$row['goods_name'] . ' ' . (string)$row['requirement']) ?: ('任务 #' . $row['id']);
    }

    private static function dateFromTime(int $time): ?string { return $time > 0 ? date('Y-m-d', $time) : null; }

    /** @return array<string,mixed> */
    private static function item(string $key, string $sourceType, int $sourceId, string $title, string $object,
        ?string $businessDate, ?string $dueDate, string $reason, string $action, string $targetType, array $params): array
    {
        return ['key' => $key, 'kind' => $sourceType, 'source' => ['type' => $sourceType, 'id' => $sourceId],
            'title' => $title, 'business_object' => $object, 'business_date' => $businessDate,
            'due_date' => $dueDate, 'reason' => $reason, 'action' => $action,
            'target' => ['type' => $targetType, 'params' => $params]];
    }
}
