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
            'S02' => self::printing($buffer),
            'S03' => self::tasks($buffer),
            'S04' => self::deliveries($buffer),
            default => null,
        };
        return $buffer->finish();
    }

    private static function reports(TodoItemBuffer $buffer): void
    {
        if (!self::any(['report.edit', 'task.control'])) { return; }
        self::chunks('customer_report', static fn($query) => $query
            ->whereIn('status', ['submitted_ready', 'submitted_shortage'])->whereNull('delete_time'),
            static function (array $row) use ($buffer): void {
                $date = (string)($row['delivery_date'] ?? '');
                $buffer->add(self::item('report:' . $row['id'], 'customer_report', (int)$row['id'],
                    '报货单 ' . (string)$row['sn'] . ' 待继续处理', (string)$row['main_customer_name'],
                    $date, $date, (string)$row['status'], '继续处理', 'report', ['id' => (int)$row['id']]));
            });
    }

    private static function printing(TodoItemBuffer $buffer): void
    {
        $canPrint = FinanceAccess::has('task.print');
        $canReprint = FinanceAccess::has('task.reprint');
        if (!$canPrint && !$canReprint) { return; }
        self::taskChunks(static fn($query) => $query
            ->whereIn('t.status', ['printable', 'print_failed']), static function (array $row) use ($buffer, $canPrint, $canReprint): void {
                $failed = (string)$row['status'] === 'print_failed';
                if (($failed || (int)$row['print_count'] > 0) ? !$canReprint : !$canPrint) { return; }
                $date = (string)($row['delivery_date'] ?? '') ?: self::dateFromTime((int)$row['create_time']);
                $buffer->add(self::item('task-print:' . $row['id'], 'fulfillment_task', (int)$row['id'],
                    ($failed ? '工票打印失败：' : '工票待打印：') . self::taskName($row),
                    (string)$row['customer_name'], $date, null,
                    $failed ? ((string)$row['last_print_error'] ?: '打印结果未成功') : '尚未完成工票打印',
                    $failed ? '重试打印' : '打印工票', 'fulfillment_task', ['id' => (int)$row['id'],
                        'report_id' => (int)$row['report_id'], 'delivery_date' => $date]));
            });
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

    private static function deliveries(TodoItemBuffer $buffer): void
    {
        if (!FinanceAccess::has('delivery.confirm') && !FinanceAccess::has('delivery.line.manage')) { return; }
        if (FinanceAccess::has('delivery.confirm')) {
            $tenant = FinanceAccess::tenant();
            $itemState = Db::name('customer_report_item')->where('tenant_id', $tenant)->whereNull('delete_time')
                ->field("report_id,SUM(CASE WHEN fulfillment_status IN ('final_weight_recorded','partially_delivered_pending') AND final_weight_task_id>0 AND final_actual_weight>(COALESCE(fulfilled_base_qty,0)+COALESCE(delivery_loss_total_qty,0)+COALESCE(undelivered_total_qty,0)) THEN 1 ELSE 0 END) AS actionable_count")
                ->group('report_id')->buildSql();
            self::taskChunks(static fn($query) => $query
                ->leftJoin([$itemState => 'di'], 'di.report_id=t.report_id')
                ->whereLike('t.source_key', '%:delivery')
                ->whereNotIn('t.status', ['blocked', 'recovered', 'ready_to_bill', 'completed', 'cancelled'])
                ->where('di.actionable_count', '>', 0),
                static function (array $row) use ($buffer): void {
                    $date = (string)($row['delivery_date'] ?? '') ?: self::dateFromTime((int)$row['create_time']);
                    $buffer->add(self::item('delivery-task:' . $row['id'], 'fulfillment_task', (int)$row['id'],
                        '配送待处理：' . self::taskName($row), (string)$row['customer_name'],
                        $date, null, '实际交付或交接尚未确认', '处理配送', 'delivery',
                        ['task_id' => (int)$row['id'], 'report_id' => (int)$row['report_id'], 'delivery_date' => $date]));
                });
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
