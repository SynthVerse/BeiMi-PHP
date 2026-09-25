<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 报货任务组、纸质工票分配、蓝牙打印结果与工票回收状态机。 */
final class FulfillmentTaskLogic extends BaseLogic
{
    private const FINISHED = ['recovered', 'ready_to_bill', 'completed', 'cancelled'];

    /** 在报货事务中调用；按 source_key 幂等生成任务。 */
    public static function syncForReport(int $reportId): void
    {
        $tenantId = self::tenantId();
        $report = Db::name('customer_report')->where('tenant_id', $tenantId)->where('id', $reportId)->whereNull('delete_time')->find();
        if (!$report) {
            return;
        }
        if ((string)$report['status'] === 'cancelled') {
            return;
        }
        $processes = Db::name('work_process')->where('tenant_id', $tenantId)->where('is_enabled', 1)->whereNull('delete_time')
            ->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        $purchaseProcess = null;
        $deliveryProcess = null;
        foreach ($processes as $process) {
            if ((string)$process['trigger_type'] === 'inventory_shortage') {
                $purchaseProcess ??= $process;
            } elseif ((string)$process['trigger_type'] === 'all_processing_completed') {
                $deliveryProcess ??= $process;
            }
        }
        $now = time();
        $deliveryDate = self::date((string)($report['delivery_date'] ?? ''));
        $group = Db::name('fulfillment_task_group')->where('tenant_id', $tenantId)->where('report_id', $reportId)->lock(true)->find();
        if ($group) {
            $groupId = (int)$group['id'];
            Db::name('fulfillment_task_group')->where('id', $groupId)->update([
                'delivery_date' => $deliveryDate,
                'is_supplement' => (int)($report['is_supplement'] ?? 0),
                'update_time' => $now,
            ]);
        } else {
            $groupId = (int)Db::name('fulfillment_task_group')->insertGetId([
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'delivery_date' => $deliveryDate,
                'is_supplement' => (int)($report['is_supplement'] ?? 0),
                'status' => 'open',
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        $desired = [];
        $items = Db::name('customer_report_item')->where('tenant_id', $tenantId)->where('report_id', $reportId)
            ->whereNull('delete_time')->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        foreach ($items as $item) {
            $itemId = (int)$item['id'];
            $requirement = trim((string)$item['processing_requirement']);
            $shortageTaskId = 0;
            if (bccomp((string)$item['shortage_base_qty'], '0.00', 2) > 0 && $purchaseProcess !== null) {
                $key = 'item:' . $itemId . ':shortage';
                $desired[] = $key;
                $shortageTaskId = self::upsertTask($report, $item, $groupId, $purchaseProcess, $key, [
                    'status' => 'printable',
                    'is_settlement_task' => 0,
                    'requirement' => '缺货 ' . self::decimal((string)$item['shortage_base_qty']) . (string)$item['base_unit_name'],
                    'planned_qty' => self::decimal((string)$item['shortage_base_qty']),
                ]);
            } elseif (bccomp((string)$item['shortage_base_qty'], '0.00', 2) > 0) {
                $key = 'item:' . $itemId . ':exception:missing_inventory_shortage_process';
                $desired[] = $key;
                self::upsertTask($report, $item, $groupId, null, $key, [
                    'task_type' => 'exception',
                    'exception_code' => 'missing_inventory_shortage_process',
                    'status' => 'exception',
                    'is_settlement_task' => 0,
                    'requirement' => '店铺尚未配置“库存不足自动产生”的执行工序',
                ]);
            }
            $specificationShortageTaskId = 0;
            if ((string)($item['specification_verification_status'] ?? '') === 'failed' && $purchaseProcess !== null) {
                $specificationShortagePrefix = 'item:' . $itemId . ':specification_shortage';
                $activeSpecificationShortage = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                    ->where('report_id', (int)$report['id'])->whereLike('source_key', $specificationShortagePrefix . '%')
                    ->whereNotIn('status', array_merge(self::FINISHED, ['cancelled']))->order('id desc')->find();
                $key = (string)($activeSpecificationShortage['source_key'] ?? '');
                if ($key === '') {
                    $round = (int)Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                        ->where('report_id', (int)$report['id'])->whereLike('source_key', $specificationShortagePrefix . '%')->count() + 1;
                    $key = $specificationShortagePrefix . ':' . $round;
                }
                $desired[] = $key;
                $specificationShortageTaskId = self::upsertTask($report, $item, $groupId, $purchaseProcess, $key, [
                    'status' => 'printable',
                    'is_settlement_task' => 0,
                    'requirement' => '规格缺货：单条 ' . self::decimal((string)$item['piece_weight_min'])
                        . '～' . self::decimal((string)$item['piece_weight_max']) . '斤；'
                        . trim((string)($item['specification_verification_note'] ?? '')),
                    'planned_qty' => self::decimal((string)$item['expected_base_qty']),
                ]);
            }
            $blockingPurchaseTaskId = $shortageTaskId > 0 ? $shortageTaskId : $specificationShortageTaskId;

            $matched = [];
            if ($requirement !== '' && $matched === []) {
                $manual = Db::name('fulfillment_task')->alias('t')
                    ->join('work_process p', 'p.id=t.process_id AND p.tenant_id=t.tenant_id')
                    ->where('t.tenant_id', $tenantId)->where('t.report_item_id', $itemId)
                    ->whereLike('t.source_key', 'item:' . $itemId . ':process:%')
                    ->where('p.is_enabled', 1)->whereNull('p.delete_time')
                    ->field('p.*')->find();
                if ($manual) {
                    $matched[(int)$manual['id']] = $manual;
                }
            }
            if ($requirement === '' || $matched === []) {
                $code = $requirement === '' ? 'missing_remark' : 'unrecognized_remark';
                $key = 'item:' . $itemId . ':exception:' . $code;
                $desired[] = $key;
                self::upsertTask($report, $item, $groupId, null, $key, [
                    'task_type' => 'exception',
                    'exception_code' => $code,
                    'status' => 'exception',
                    'is_settlement_task' => 0,
                    'requirement' => $requirement,
                ]);
            } else {
                $matched = array_values($matched);
                usort($matched, static fn(array $left, array $right): int => [(int)$left['sort'], (int)$left['id']] <=> [(int)$right['sort'], (int)$right['id']]);
                $candidateSourceKeys = array_map(static fn(array $process): string => 'item:' . $itemId . ':process:' . $process['code'], $matched);
                $existingOwner = Db::name('fulfillment_task')->where('tenant_id', $tenantId)->where('report_item_id', $itemId)
                    ->where('is_settlement_task', 1)->where('status', '<>', 'cancelled')->order('id asc')->find();
                $selectedSourceKey = '';
                if ($existingOwner && (in_array((string)$existingOwner['source_key'], $candidateSourceKeys, true)
                    || in_array((string)$existingOwner['status'], self::FINISHED, true))) {
                    $selectedSourceKey = (string)$existingOwner['source_key'];
                } elseif ($candidateSourceKeys !== []) {
                    $selectedSourceKey = (string)end($candidateSourceKeys);
                }
                Db::name('fulfillment_task')->where('tenant_id', $tenantId)->where('report_item_id', $itemId)
                    ->whereIn('status', ['unassigned', 'blocked', 'printable', 'print_failed', 'exception'])
                    ->update(['is_settlement_task' => 0, 'update_time' => $now]);
                foreach ($matched as $process) {
                    $key = 'item:' . $itemId . ':process:' . $process['code'];
                    $desired[] = $key;
                    self::upsertTask($report, $item, $groupId, $process, $key, [
                        'depends_on_task_id' => $blockingPurchaseTaskId,
                        'status' => 'printable',
                        'is_settlement_task' => $key === $selectedSourceKey ? 1 : 0,
                        'requirement' => $requirement,
                    ]);
                }
            }
        }

        if ($deliveryProcess !== null) {
            $key = 'report:' . $reportId . ':delivery';
            $desired[] = $key;
            self::upsertTask($report, null, $groupId, $deliveryProcess, $key, [
                'status' => 'printable',
                'is_settlement_task' => 0,
                'requirement' => '全部加工完成后送货',
            ]);
        } else {
            $key = 'report:' . $reportId . ':exception:missing_delivery_process';
            $desired[] = $key;
            self::upsertTask($report, null, $groupId, null, $key, [
                'task_type' => 'exception',
                'exception_code' => 'missing_delivery_process',
                'status' => 'exception',
                'is_settlement_task' => 0,
                'requirement' => '店铺尚未配置“全部加工完成后自动产生”的执行工序',
            ]);
        }

        if ($desired !== []) {
            Db::name('fulfillment_task')->where('tenant_id', $tenantId)->where('report_id', $reportId)
                ->whereNotIn('source_key', $desired)->whereIn('status', ['unassigned', 'blocked', 'printable', 'print_failed', 'exception'])
                ->update(['status' => 'cancelled', 'update_time' => $now]);
        }
        self::refreshGroupStates($groupId);
    }

    public static function cancelReport(int $reportId): void
    {
        $groupId = (int)Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())->where('report_id', $reportId)->value('id');
        if ($groupId <= 0) {
            return;
        }
        Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('group_id', $groupId)
            ->whereNotIn('status', self::FINISHED)->update(['status' => 'cancelled', 'update_time' => time()]);
        Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())->where('id', $groupId)
            ->update(['status' => 'cancelled', 'update_time' => time()]);
    }

    public static function resyncReportsWaitingForProcess(string $triggerType): void
    {
        $exceptionCode = match ($triggerType) {
            'inventory_shortage' => 'missing_inventory_shortage_process',
            'all_processing_completed' => 'missing_delivery_process',
            default => '',
        };
        if ($exceptionCode === '') {
            return;
        }
        $reportIds = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
            ->where('status', 'exception')->where('exception_code', $exceptionCode)
            ->distinct(true)->column('report_id');
        foreach (array_map('intval', $reportIds) as $reportId) {
            if ($reportId > 0) {
                self::syncForReport($reportId);
            }
        }
    }

    /** @return array<string,mixed>|false */
    public static function dashboard(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.view')) {
            return false;
        }
        $deliveryDate = self::date((string)($params['delivery_date'] ?? ''));
        $groupIds = Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())->where('delivery_date', $deliveryDate)
            ->where('status', '<>', 'cancelled')->column('id');
        $tasks = $groupIds === [] ? [] : Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
            ->whereIn('group_id', $groupIds)->where('status', '<>', 'cancelled')->order(['process_id' => 'asc', 'id' => 'asc'])->select()->toArray();
        $tasks = self::hydrateTasks($tasks);
        $exceptions = [];
        $groups = [];
        foreach ($tasks as $task) {
            if (in_array($task['status'], ['exception', 'print_failed', 'unassigned'], true)
                || ($task['status'] === 'blocked' && (int)$task['depends_on_task_id'] > 0)) {
                $task['action'] = self::exceptionAction($task);
                $exceptions[] = $task;
                continue;
            }
            $groups[$task['process_name'] ?: '待确认'][] = $task;
        }
        $processGroups = [];
        foreach ($groups as $name => $processTasks) {
            $processGroups[] = [
                'name' => $name,
                'process_id' => (int)($processTasks[0]['process_id'] ?? 0),
                'tasks' => $processTasks,
            ];
        }
        $counts = ['printable' => 0, 'working' => 0, 'recovered' => 0];
        foreach ($tasks as $task) {
            if (in_array($task['status'], ['printable', 'print_failed'], true)) {
                $counts['printable']++;
            } elseif (in_array($task['status'], ['printed', 'in_progress'], true)) {
                $counts['working']++;
            } elseif (in_array($task['status'], ['recovered', 'ready_to_bill', 'completed'], true)) {
                $counts['recovered']++;
            }
        }
        $customerCount = $groupIds === [] ? 0 : (int)Db::name('fulfillment_task_group')->alias('g')
            ->join('customer_report r', 'r.id=g.report_id AND r.tenant_id=g.tenant_id')
            ->where('g.tenant_id', self::tenantId())->whereIn('g.id', $groupIds)->whereNull('r.delete_time')
            ->count('DISTINCT r.main_customer_id');
        $negativeTodos = [];
        if (WorkforceLogic::hasPermission('inventory.negative.manage')) {
            $negativeResult = NegativeInventoryLogic::todos(['status' => 'open']);
            $negativeTodos = $negativeResult === false ? [] : $negativeResult['lists'];
        }
        return [
            'batch' => ['delivery_date' => $deliveryDate, 'customer_count' => $customerCount, 'task_group_count' => count($groupIds)],
            'printer' => ['name' => 'XP-N160II', 'paper_width_mm' => 80, 'transport' => 'bluetooth'],
            'exceptions' => array_slice($exceptions, 0, 20),
            'exception_count' => count($exceptions) + count($negativeTodos),
            'negative_inventory_todos' => array_slice($negativeTodos, 0, 20),
            'negative_inventory_count' => count($negativeTodos),
            'tabs' => $counts,
            'process_groups' => $processGroups,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function groupForReport(int $reportId): ?array
    {
        $group = Db::name('fulfillment_task_group')
            ->where('tenant_id', self::tenantId())
            ->where('report_id', $reportId)
            ->find();
        if (!$group) {
            return null;
        }
        $tasks = Db::name('fulfillment_task')
            ->where('tenant_id', self::tenantId())
            ->where('group_id', (int)$group['id'])
            ->where('status', '<>', 'cancelled')
            ->order(['process_id' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();
        $group['tasks'] = self::hydrateTasks($tasks);
        return $group;
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int}|false */
    public static function lists(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.view')) {
            return false;
        }
        $query = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('status', '<>', 'cancelled');
        $scope = (string)($params['status_scope'] ?? '');
        if ($scope === 'printable') {
            $query->whereIn('status', ['printable', 'print_failed']);
        } elseif ($scope === 'working') {
            $query->whereIn('status', ['printed', 'in_progress']);
        } elseif ($scope === 'recovered') {
            $query->whereIn('status', ['recovered', 'ready_to_bill', 'completed']);
        } elseif ($scope === 'exception') {
            $query->whereIn('status', ['exception', 'print_failed']);
        }
        $rows = self::hydrateTasks($query->order('id desc')->select()->toArray());
        return ['lists' => $rows, 'count' => count($rows)];
    }

    /** @return array<string,mixed>|false */
    public static function detail(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.view')) {
            return false;
        }
        return self::taskById((int)($params['id'] ?? 0));
    }

    /** @return array<string,mixed>|false */
    public static function resolveException(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.control') || !WorkforceLogic::requirePermission('report.remark')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $processId = (int)($params['process_id'] ?? 0);
        try {
            return Db::transaction(static function () use ($id, $processId, $params) {
                $process = Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $processId)
                    ->where('trigger_type', 'report_selection')->where('is_enabled', 1)->whereNull('delete_time')->lock(true)->find();
                $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)
                    ->where('status', 'exception')->lock(true)->find();
                if (!$process || !$task) {
                    self::setError('待确认任务或工序不存在');
                    return false;
                }
                $itemId = (int)$task['report_item_id'];
                $sourceKey = 'item:' . $itemId . ':process:' . (string)$process['code'];
                $duplicate = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                    ->where('report_id', (int)$task['report_id'])->where('source_key', $sourceKey)->where('id', '<>', $id)
                    ->lock(true)->find();
                $owner = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('report_item_id', $itemId)
                    ->where('is_settlement_task', 1)->where('status', '<>', 'cancelled')->lock(true)->find();
                if ($duplicate && !in_array((string)$duplicate['status'], ['unassigned', 'blocked', 'printable', 'print_failed', 'exception', 'cancelled'], true)) {
                    if ((string)$process['code'] !== 'purchase' && (!$owner || (int)$owner['id'] === (int)$duplicate['id'])) {
                        Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', (int)$duplicate['id'])
                            ->update(['is_settlement_task' => 1, 'update_time' => time()]);
                    }
                    Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)
                        ->update(['status' => 'cancelled', 'update_time' => time()]);
                    return self::taskById((int)$duplicate['id']);
                }
                $becomesOwner = (string)$process['code'] !== 'purchase'
                    && (!$owner || (int)$owner['id'] === $id || ($duplicate && (int)$owner['id'] === (int)$duplicate['id']));
                if ($duplicate) {
                    Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', (int)$duplicate['id'])->delete();
                }
                $purchaseId = (int)Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                    ->where('report_item_id', $itemId)->whereLike('source_key', 'item:' . $itemId . ':shortage')->value('id');
                if ((string)$process['code'] === 'purchase') {
                    $purchaseId = 0;
                }
                $requirement = trim((string)($params['requirement'] ?? $task['requirement']));
                Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                    'source_key' => $sourceKey,
                    'process_id' => $processId,
                    'process_name_snapshot' => (string)$process['name'],
                    'process_sort_snapshot' => (int)$process['sort'],
                    'task_type' => 'process',
                    'exception_code' => '',
                    'depends_on_task_id' => $purchaseId,
                    'requirement' => $requirement,
                    'is_settlement_task' => $becomesOwner ? 1 : 0,
                    'status' => 'printable',
                    'update_time' => time(),
                ]);
                if ($itemId > 0) {
                    Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', $itemId)
                        ->update(['processing_requirement' => $requirement, 'update_time' => time()]);
                }
                return self::taskById($id);
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('异常任务确认失败');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function printData(array $params): array|false
    {
        self::clearError();
        $id = (int)($params['id'] ?? 0);
        try {
            return Db::transaction(static function () use ($id) {
                $taskRef = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)
                    ->field('id,report_id')->find();
                if (!$taskRef) {
                    self::setError('任务当前不可打印');
                    return false;
                }
                $reportId = (int)$taskRef['report_id'];
                if ($reportId > 0) {
                    Db::name('customer_report')->where('tenant_id', self::tenantId())->where('id', $reportId)->lock(true)->find();
                }
                $raw = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->lock(true)->find();
                if (!$raw || !in_array((string)$raw['status'], ['printable', 'print_failed', 'printed', 'in_progress'], true)) {
                    self::setError('任务当前不可打印');
                    return false;
                }
                $raw = self::ensureContentIdentity($raw);
                $successfulCopies = (int)Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                    ->where('task_id', $id)->where('print_type', 'task')->whereNotIn('paper_status', ['not_issued', 'print_failed'])->count();
                $permission = $successfulCopies > 0 || in_array((string)$raw['status'], ['print_failed', 'printed', 'in_progress'], true)
                    ? 'task.reprint' : 'task.print';
                if (!WorkforceLogic::requirePermission($permission)) {
                    return false;
                }
                $pending = Db::name('fulfillment_print_log')->alias('l')
                    ->join('fulfillment_paper_copy c', 'c.print_log_id=l.id AND c.tenant_id=l.tenant_id')
                    ->where('l.tenant_id', self::tenantId())->where('l.task_id', $id)
                    ->where('c.print_type', 'task')->where('l.status', 'pending')->lock(true)->field('l.id')->find();
                if ($pending) {
                    self::setError('上一张工票的打印回执尚未确认');
                    return false;
                }
                $now = time();
                $logId = (int)Db::name('fulfillment_print_log')->insertGetId([
                    'tenant_id' => self::tenantId(), 'task_id' => $id, 'operator_id' => self::operatorId(),
                    'status' => 'pending', 'create_time' => $now, 'update_time' => $now,
                ]);
                $copyNo = $successfulCopies + 1;
                Db::name('fulfillment_paper_copy')->insert([
                    'tenant_id' => self::tenantId(), 'task_id' => $id, 'print_log_id' => $logId,
                    'control_id' => 0, 'print_type' => 'task', 'copy_no' => $copyNo,
                    'ticket_no' => (string)$raw['ticket_no'], 'content_version' => (int)$raw['content_version'],
                    'content_hash' => (string)$raw['content_hash'], 'paper_status' => 'not_issued',
                    'create_time' => $now, 'update_time' => $now,
                ]);
                return [
                    'print_log_id' => $logId,
                    'print_requested_time' => $now,
                    'copy_no' => $copyNo,
                    'is_reprint' => $successfulCopies > 0,
                    'printer' => ['name' => 'XP-N160II', 'paper_width_mm' => 80, 'transport' => 'bluetooth'],
                    'ticket' => self::taskById($id),
                ];
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('打印准备失败');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function printResult(array $params): array|false
    {
        self::clearError();
        $id = (int)($params['id'] ?? 0);
        $logId = (int)($params['print_log_id'] ?? 0);
        $success = (int)($params['success'] ?? 0) === 1;
        $error = mb_substr(trim((string)($params['error_message'] ?? '')), 0, 255);
        try {
            return Db::transaction(static function () use ($id, $logId, $success, $error) {
                $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->lock(true)->find();
                $log = Db::name('fulfillment_print_log')->where('tenant_id', self::tenantId())->where('id', $logId)
                    ->where('task_id', $id)->lock(true)->find();
                $copy = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                    ->where('print_log_id', $logId)->where('task_id', $id)->where('print_type', 'task')->lock(true)->find();
                if (!$task || !$log || !$copy) {
                    self::setError('打印回执无效');
                    return false;
                }
                if ((int)$copy['content_version'] !== (int)$task['content_version']
                    || (string)$copy['content_hash'] !== (string)$task['content_hash']
                    || (string)$log['status'] === 'superseded') {
                    self::setError('打印回执已过期');
                    return false;
                }
                $wanted = $success ? 'success' : 'failed';
                if ((string)$log['status'] !== 'pending') {
                    if (!WorkforceLogic::requireAnyPermission(['task.print', 'task.reprint'])) {
                        return false;
                    }
                    if ((string)$log['status'] === $wanted) {
                        return self::taskById($id);
                    }
                    self::setError('打印回执结果与已保存结果冲突');
                    return false;
                }
                $permission = (int)$task['print_count'] > 0 || (string)$task['status'] === 'print_failed' ? 'task.reprint' : 'task.print';
                if (!WorkforceLogic::requirePermission($permission)) {
                    return false;
                }
                if (!in_array((string)$task['status'], ['printable', 'print_failed', 'printed', 'in_progress'], true)) {
                    self::setError('打印回执已过期');
                    return false;
                }
                $now = time();
                Db::name('fulfillment_print_log')->where('tenant_id', self::tenantId())->where('id', $logId)
                    ->where('status', 'pending')->update(['status' => $wanted, 'error_message' => $error, 'update_time' => $now]);
                Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())->where('id', (int)$copy['id'])->update([
                    'paper_status' => $success ? 'issued' : 'print_failed',
                    'printed_time' => $success ? $now : 0, 'update_time' => $now,
                ]);
                Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                    'status' => $success ? 'printed' : 'print_failed',
                    'print_count' => (int)$task['print_count'] + 1,
                    'last_print_status' => $wanted,
                    'last_print_error' => $success ? '' : $error,
                    'last_print_time' => $now,
                    'update_time' => $now,
                ]);
                return self::taskById($id);
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('打印回执保存失败');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function recover(array $params): array|false
    {
        return self::recoverPaper($params, false);
    }

    /** @return array<string,mixed>|false */
    public static function recoverException(array $params): array|false
    {
        return self::recoverPaper($params, true);
    }

    /** @return array<string,mixed>|false */
    public static function specificationShortage(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.recover')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $printLogId = (int)($params['print_log_id'] ?? 0);
        $note = mb_substr(trim((string)($params['specification_note'] ?? '')), 0, 500);
        if ($note === '') {
            self::setError('登记规格不符时必须填写现场核实说明');
            return false;
        }
        try {
            return Db::transaction(static function () use ($id, $printLogId, $note) {
                $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->lock(true)->find();
                if (!$task || !in_array((string)$task['status'], ['printed', 'in_progress'], true)) {
                    self::setError('只有作业中的最终称重工票可以登记规格不符');
                    return false;
                }
                $item = Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                    ->where('id', (int)$task['report_item_id'])->lock(true)->find();
                if (!$item || (int)($item['piece_weight_confirmed'] ?? 0) !== 1 || !self::isSettlementTask($task)) {
                    self::setError('当前任务没有需要核实的单条重量要求');
                    return false;
                }
                $copyQuery = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                    ->where('task_id', $id)->where('print_type', 'task')->where('paper_status', 'issued');
                if ($printLogId > 0) {
                    $copyQuery->where('print_log_id', $printLogId);
                } else {
                    $copyQuery->where('content_version', (int)$task['content_version']);
                }
                $issuedCopies = $copyQuery->lock(true)->order('id')->select()->toArray();
                if ($printLogId <= 0 && count($issuedCopies) > 1) {
                    self::setError('存在多张未回收工票，必须指定本次核实的打印副本，其余副本继续作废控制');
                    return false;
                }
                $copy = $issuedCopies[0] ?? null;
                if (!$copy) {
                    self::setError('没有可登记规格不符的未回收纸质工票副本');
                    return false;
                }
                $now = time();
                Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', (int)$item['id'])->update([
                    'specification_verification_status' => 'failed',
                    'specification_verification_note' => $note,
                    'specification_verified_by' => self::operatorId(),
                    'specification_verified_time' => $now,
                    'update_time' => $now,
                ]);
                Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())->where('id', (int)$copy['id'])->update([
                        'paper_status' => 'recovered',
                        'accounted_by' => self::operatorId(),
                        'accounted_time' => $now,
                        'account_reason' => 'specification_shortage',
                        'account_note' => $note,
                        'update_time' => $now,
                    ]);
                self::requireVoidControlsForOtherCopies($task, $item, (int)$copy['id'], $note, $now);
                Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                    'status' => 'exception',
                    'exception_code' => 'specification_shortage',
                    'recovery_note' => $note,
                    'update_time' => $now,
                ]);
                AuditService::logWithinTransaction(
                    'fulfillment_task', 'specification_shortage', $id, (string)$task['ticket_no'],
                    $task,
                    ['status' => 'exception', 'specification_verification_status' => 'failed', 'note' => $note],
                    $note
                );
                self::syncForReport((int)$task['report_id']);
                return self::taskById($id);
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('规格不符登记失败，任务状态未改变');
            }
            return false;
        }
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $item */
    private static function requireVoidControlsForOtherCopies(array $task, array $item, int $recoveredCopyId, string $reason, int $now): void
    {
        $copies = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
            ->where('task_id', (int)$task['id'])->where('print_type', 'task')->where('paper_status', 'issued')
            ->where('id', '<>', $recoveredCopyId)->order('id')->lock(true)->select()->toArray();
        foreach ($copies as $copy) {
            $actionKey = 'specification_shortage:' . (int)$task['id'] . ':paper:' . (int)$copy['id'];
            $existing = Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
                ->where('action_key', $actionKey)->find();
            if ($existing) {
                continue;
            }
            Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())->where('id', (int)$copy['id'])
                ->update(['paper_status' => 'void_required', 'update_time' => $now]);
            Db::name('fulfillment_ticket_control')->insert([
                'tenant_id' => self::tenantId(),
                'report_id' => (int)$task['report_id'],
                'report_item_id' => (int)$item['id'],
                'item_change_id' => 0,
                'task_id' => (int)$task['id'],
                'print_log_id' => (int)$copy['print_log_id'],
                'action_type' => 'void',
                'action_key' => $actionKey,
                'reason' => '规格不符，旧纸票必须回收或打印作废通知：' . $reason,
                'before_snapshot' => json_encode(self::ticketSnapshot($task), JSON_UNESCAPED_UNICODE),
                'after_snapshot' => json_encode(['specification_verification_status' => 'failed'], JSON_UNESCAPED_UNICODE),
                'status' => 'pending_recovery',
                'resolution' => '',
                'resolution_note' => '',
                'resolved_by' => 0,
                'resolved_time' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
    }

    /** @return array<string,mixed>|false */
    public static function bill(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('settlement.bill')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $locator = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->find();
        if (!$locator || !str_ends_with((string)$locator['source_key'], ':bookkeeping')) {
            self::setError('只有已回收的记账工票可以确认开单');
            return false;
        }
        $reportId = (int)$locator['report_id'];
        try {
            return self::transactionWithRetry(static function () use ($id, $reportId) {
                $report = Db::name('customer_report')->where('tenant_id', self::tenantId())
                    ->where('id', $reportId)->lock(true)->find();
                if (!$report) {
                    self::setError('关联报货单不存在');
                    return false;
                }
                $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                    ->where('id', $id)->lock(true)->find();
                if (!$task || !str_ends_with((string)$task['source_key'], ':bookkeeping')
                    || (int)$task['report_id'] !== $reportId
                    || !in_array((string)$task['status'], ['ready_to_bill', 'completed'], true)) {
                    self::setError('只有已回收的记账工票可以确认开单');
                    return false;
                }
                if (self::hasUnaccountedPaperForReport((int)$report['id'])) {
                    self::setError('仍有未回收或未完成作废控制的纸质工票，不能结算');
                    return false;
                }
                $orders = Db::name('sales_order')->where('tenant_id', self::tenantId())
                    ->where('source_type', 'customer_report')->where('source_id', (int)$report['id'])
                    ->whereIn('settlement_status', ['pending', 'pending_weight_review', 'formal'])
                    ->field('id,order_sn,warehouse_id,settlement_status,settlement_version,order_money')
                    ->order('id')->select()->toArray();
                if ($orders === []) {
                    self::setError('真实交付尚未形成待结算销售单，不能确认开单');
                    return false;
                }
                if ((string)$task['status'] === 'ready_to_bill') {
                    $now = time();
                    if (Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)
                        ->update(['status' => 'completed', 'update_time' => $now]) === false
                        || Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())
                            ->where('id', (int)$task['group_id'])->update(['status' => 'completed', 'update_time' => $now]) === false) {
                        throw new \RuntimeException('fulfillment_bill_completion_failed');
                    }
                }
                return ['task' => self::taskById($id), 'report' => $report, 'settlement_orders' => $orders];
            });
        } catch (\Throwable) {
            self::setError('确认开单失败，任务状态未改变');
            return false;
        }
    }

    /** @return array<int,array{final_actual_weight:string,actual_weight:string,actual_price:string,task_id:int}> */
    public static function settlementValuesForReport(int $reportId): array
    {
        $rows = Db::name('customer_report_item')->alias('i')
            ->join('fulfillment_task t', 't.id=i.final_weight_task_id AND t.tenant_id=i.tenant_id')
            ->where('i.tenant_id', self::tenantId())->where('i.report_id', $reportId)->whereNull('i.delete_time')
            ->where('i.fulfillment_status', 'final_weight_recorded')->where('i.final_actual_weight', '>', 0)
            ->field('i.id AS report_item_id,i.final_actual_weight,t.id,t.actual_price')->select()->toArray();
        $values = [];
        foreach ($rows as $row) {
            $itemId = (int)$row['report_item_id'];
            $values[$itemId] = [
                'final_actual_weight' => self::decimal((string)$row['final_actual_weight']),
                'actual_weight' => self::decimal((string)$row['final_actual_weight']),
                'actual_price' => self::decimal((string)$row['actual_price']),
                'task_id' => (int)$row['id'],
            ];
        }
        return $values;
    }

    /** @return array<string,mixed>|false */
    private static function recoverPaper(array $params, bool $exception): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.recover')
            || ($exception && !WorkforceLogic::requirePermission('task.control'))) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $printLogId = (int)($params['print_log_id'] ?? 0);
        $exceptionReason = trim((string)($params['exception_reason'] ?? ''));
        $exceptionNote = mb_substr(trim((string)($params['exception_note'] ?? '')), 0, 500);
        if ($exception && (!in_array($exceptionReason, ['lost', 'damaged', 'illegible'], true) || $exceptionNote === '')) {
            self::setError('异常工票补录必须选择丢失、破损或字迹不清并填写核实说明');
            return false;
        }
        try {
            return Db::transaction(static function () use ($id, $printLogId, $params, $exception, $exceptionReason, $exceptionNote) {
                $taskRef = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)
                    ->field('id,report_id,report_item_id')->find();
                if (!$taskRef) {
                    self::setError('工票不存在');
                    return false;
                }
                if ((int)$taskRef['report_id'] > 0) {
                    Db::name('customer_report')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$taskRef['report_id'])->lock(true)->find();
                }
                $lockedItem = (int)$taskRef['report_item_id'] > 0
                    ? Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$taskRef['report_item_id'])->lock(true)->find()
                    : null;
                $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->lock(true)->find();
                if (!$task) {
                    self::setError('工票不存在');
                    return false;
                }
                $task = self::ensureContentIdentity($task);
                $requiresSettlement = self::isSettlementTask($task);
                if ($requiresSettlement && !WorkforceLogic::requirePermission('settlement.weight')) {
                    return false;
                }
                $weightInput = trim((string)($params['actual_weight'] ?? ''));
                $weight = self::money($weightInput === '' ? '0' : $weightInput);
                if ($weight === false || ($requiresSettlement && bccomp($weight, '0.00', 2) <= 0)) {
                    self::setError($requiresSettlement ? '最终称重工序必须录入大于 0 的最终实重' : '过程重量格式不正确');
                    return false;
                }
                $priceInput = trim((string)($params['actual_price'] ?? ''));
                $price = $priceInput === '' ? self::decimal((string)$task['actual_price']) : self::money($priceInput);
                if ($price === false) {
                    self::setError('已确认单价格式不正确');
                    return false;
                }
                if ($priceInput !== '' && !WorkforceLogic::requirePermission('settlement.price')) {
                    return false;
                }
                $note = mb_substr(trim((string)($params['recovery_note'] ?? '')), 0, 500);
                $specificationVerification = self::validateSpecificationVerification($lockedItem, $requiresSettlement, $weight, $params);
                if ($specificationVerification === false) {
                    return false;
                }
                $copy = null;
                if ($printLogId > 0) {
                    $copy = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                        ->where('task_id', $id)->where('print_log_id', $printLogId)->where('print_type', 'task')->lock(true)->find();
                    if (!$copy) {
                        self::setError('纸质工票副本不存在');
                        return false;
                    }
                } else {
                    $issued = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())->where('task_id', $id)
                        ->where('print_type', 'task')->where('content_version', (int)$task['content_version'])
                        ->where('paper_status', 'issued')->lock(true)->select()->toArray();
                    if (count($issued) > 1) {
                        self::setError('存在多张未回收工票，必须指定本次回收的打印副本');
                        return false;
                    }
                    $copy = $issued[0] ?? null;
                }
                $allowedPaperStates = $exception ? ['issued'] : ['issued'];
                if ($copy && !in_array((string)$copy['paper_status'], $allowedPaperStates, true)) {
                    $savedModeMatches = $exception
                        ? (string)$copy['paper_status'] === $exceptionReason && (string)$copy['account_note'] === $exceptionNote
                        : (string)$copy['paper_status'] === 'recovered';
                    $requestedNote = $exception ? $exceptionNote : $note;
                    if ($savedModeMatches
                        && bccomp((string)$task['process_weight'], $weight, 2) === 0
                        && bccomp((string)$task['actual_price'], $price, 2) === 0
                        && (string)$task['recovery_note'] === $requestedNote
                        && self::specificationVerificationMatches($lockedItem, $specificationVerification)) {
                        return self::taskById($id);
                    }
                    self::setError('该纸质工票副本已经处理，不能覆盖原结果');
                    return false;
                }
                if (!$copy && in_array((string)$task['status'], ['recovered', 'ready_to_bill'], true)) {
                    $accountedCopies = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                        ->where('task_id', $id)->where('print_type', 'task')
                        ->where('content_version', (int)$task['content_version'])->where('accounted_time', '>', 0)
                        ->order('id')->select()->toArray();
                    $modeMatches = $exception ? $accountedCopies !== [] : true;
                    foreach ($accountedCopies as $accountedCopy) {
                        if ($exception) {
                            $modeMatches = $modeMatches
                                && (string)$accountedCopy['account_reason'] === $exceptionReason
                                && (string)$accountedCopy['account_note'] === $exceptionNote;
                        } elseif ((string)$accountedCopy['account_reason'] !== 'normal_recovery') {
                            $modeMatches = false;
                        }
                    }
                    $requestedNote = $exception ? $exceptionNote : $note;
                    if ($modeMatches
                        && bccomp((string)$task['process_weight'], $weight, 2) === 0
                        && bccomp((string)$task['actual_price'], $price, 2) === 0
                        && (string)$task['recovery_note'] === $requestedNote
                        && self::specificationVerificationMatches($lockedItem, $specificationVerification)) {
                        return self::taskById($id);
                    }
                    self::setError('工票已回收，不能覆盖回收模式、重量、价格或说明');
                    return false;
                }
                if (!$copy && !in_array((string)$task['status'], ['printed', 'in_progress'], true)) {
                    self::setError('只有已打印的工票可以回收');
                    return false;
                }
                if (bccomp((string)$task['process_weight'], '0.00', 2) > 0
                    && bccomp((string)$task['process_weight'], $weight, 2) !== 0) {
                    self::setError('同一工序任务的纸票副本重量不一致，不能覆盖已回录结果');
                    return false;
                }
                $finalItem = null;
                if ($requiresSettlement) {
                    $finalItem = $lockedItem;
                    if (!$finalItem || (string)($finalItem['fulfillment_status'] ?? '') === 'undelivered') {
                        self::setError('未交货明细不能回录最终实重');
                        return false;
                    }
                    if ((int)($finalItem['final_weight_task_id'] ?? 0) > 0
                        && (int)$finalItem['final_weight_task_id'] !== $id
                        && bccomp((string)$finalItem['final_actual_weight'], '0.00', 2) > 0) {
                        self::setError('该报货明细已有其他最终称重工序结果');
                        return false;
                    }
                }
                if (str_ends_with((string)$task['source_key'], ':shortage')
                    && !in_array((string)$task['status'], ['recovered', 'completed'], true)
                    && !CustomerReportLogic::completePurchaseForItem((int)$task['report_item_id'])) {
                    self::setError(CustomerReportLogic::getError());
                    return false;
                }
                if (str_contains((string)$task['source_key'], ':specification_shortage')
                    && !in_array((string)$task['status'], ['recovered', 'completed'], true)) {
                    Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$task['report_item_id'])->update([
                            'specification_verification_status' => 'pending',
                            'verified_piece_count' => 0,
                            'verified_piece_weight_min' => '0.00',
                            'verified_piece_weight_max' => '0.00',
                            'specification_verification_note' => '',
                            'specification_verified_by' => 0,
                            'specification_verified_time' => 0,
                            'update_time' => time(),
                        ]);
                }
                $now = time();
                if ($copy) {
                    Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())->where('id', (int)$copy['id'])->update([
                        'paper_status' => $exception ? $exceptionReason : 'recovered',
                        'accounted_by' => self::operatorId(), 'accounted_time' => $now,
                        'account_reason' => $exception ? $exceptionReason : 'normal_recovery',
                        'account_note' => $exception ? $exceptionNote : $note, 'update_time' => $now,
                    ]);
                }
                $outstandingCurrentCopies = (int)Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                    ->where('task_id', $id)->where('print_type', 'task')->where('content_version', (int)$task['content_version'])
                    ->where('paper_status', 'issued')->count();
                $finishedStatus = str_ends_with((string)$task['source_key'], ':bookkeeping') ? 'ready_to_bill' : 'recovered';
                $nextStatus = $outstandingCurrentCopies === 0 ? $finishedStatus : 'printed';
                Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                    'status' => $nextStatus, 'actual_weight' => $weight, 'process_weight' => $weight,
                    'actual_price' => $price, 'recovery_note' => $exception ? $exceptionNote : $note,
                    'recovered_by' => self::operatorId(), 'recovered_time' => $now, 'update_time' => $now,
                ]);
                if ($requiresSettlement) {
                    $itemUpdate = [
                        'final_actual_weight' => $weight, 'final_weight_task_id' => $id,
                        'fulfillment_status' => 'final_weight_recorded', 'update_time' => $now,
                    ];
                    if (is_array($specificationVerification)) {
                        $itemUpdate = array_merge($itemUpdate, $specificationVerification, [
                            'specification_verification_status' => 'confirmed',
                            'specification_verified_by' => self::operatorId(),
                            'specification_verified_time' => $now,
                        ]);
                    }
                    Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', (int)$finalItem['id'])->update($itemUpdate);
                }
                AuditService::logWithinTransaction(
                    'fulfillment_task', $exception ? 'exception_recover' : 'recover', $id, (string)$task['ticket_no'],
                    $task,
                    [
                        'status' => $nextStatus, 'process_weight' => $weight,
                        'recovery_mode' => $exception ? 'exception' : 'normal',
                        'reason' => $exception ? $exceptionReason : 'normal_recovery',
                        'note' => $exception ? $exceptionNote : $note,
                        'operator_id' => self::operatorId(), 'recovered_time' => $now,
                    ],
                    $exception ? $exceptionNote : $note
                );
                if ($nextStatus === $finishedStatus) {
                    self::refreshGroupStates((int)$task['group_id']);
                }
                return self::taskById($id);
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError($exception ? '异常工票补录失败' : '工票回收失败');
            }
            return false;
        }
    }

    /** @param array<string,mixed>|null $item @param array<string,mixed> $params @return array<string,mixed>|null|false */
    private static function validateSpecificationVerification(?array $item, bool $requiresSettlement, string $actualWeight, array $params): array|null|false
    {
        if (!$requiresSettlement || !$item || (int)($item['piece_weight_confirmed'] ?? 0) !== 1) {
            return null;
        }
        if ((string)($params['specification_result'] ?? '') !== 'confirmed') {
            self::setError('该明细有单条重量要求，请先逐条核实规格；不符合时请选择“规格不符，转补货”');
            return false;
        }
        $count = (int)($params['verified_piece_count'] ?? 0);
        $min = self::money((string)($params['verified_piece_weight_min'] ?? ''));
        $max = self::money((string)($params['verified_piece_weight_max'] ?? ''));
        if ($count <= 0 || $min === false || $max === false || bccomp($min, '0.00', 2) <= 0 || bccomp($max, $min, 2) < 0) {
            self::setError('请输入实际条数和现场核实的最小、最大单条重量');
            return false;
        }
        $requiredMin = self::decimal((string)($item['piece_weight_min'] ?? '0'));
        $requiredMax = self::decimal((string)($item['piece_weight_max'] ?? '0'));
        if (bccomp($min, $requiredMin, 2) < 0 || bccomp($max, $requiredMax, 2) > 0) {
            self::setError('现场核实的单条重量不在客户要求范围内');
            return false;
        }
        $acceptableMin = self::decimal((string)($item['acceptable_base_qty_min'] ?? $item['expected_base_qty'] ?? '0'));
        $acceptableMax = self::decimal((string)($item['acceptable_base_qty_max'] ?? $item['expected_base_qty'] ?? '0'));
        if (bccomp($actualWeight, $acceptableMin, 2) < 0 || bccomp($actualWeight, $acceptableMax, 2) > 0) {
            self::setError('最终实重不在客户可接受总重量范围内');
            return false;
        }
        $possibleMin = bcmul((string)$count, $min, 2);
        $possibleMax = bcmul((string)$count, $max, 2);
        if (bccomp($actualWeight, $possibleMin, 2) < 0 || bccomp($actualWeight, $possibleMax, 2) > 0) {
            self::setError('最终实重与实际条数、单条重量核实结果不一致');
            return false;
        }
        return [
            'verified_piece_count' => $count,
            'verified_piece_weight_min' => $min,
            'verified_piece_weight_max' => $max,
            'specification_verification_note' => mb_substr(trim((string)($params['specification_note'] ?? '')), 0, 500),
        ];
    }

    /** @param array<string,mixed>|null $item @param array<string,mixed>|null|false $verification */
    private static function specificationVerificationMatches(?array $item, array|null|false $verification): bool
    {
        if ($verification === null) {
            return true;
        }
        if ($verification === false || !$item || (string)($item['specification_verification_status'] ?? '') !== 'confirmed') {
            return false;
        }
        return (int)($item['verified_piece_count'] ?? 0) === (int)$verification['verified_piece_count']
            && bccomp((string)($item['verified_piece_weight_min'] ?? '0'), (string)$verification['verified_piece_weight_min'], 2) === 0
            && bccomp((string)($item['verified_piece_weight_max'] ?? '0'), (string)$verification['verified_piece_weight_max'], 2) === 0
            && (string)($item['specification_verification_note'] ?? '') === (string)$verification['specification_verification_note'];
    }

    /** @return array<string,mixed>|false */
    public static function paperControl(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.control')) {
            return false;
        }
        $controlId = (int)($params['control_id'] ?? 0);
        $resolution = trim((string)($params['resolution'] ?? ''));
        $note = mb_substr(trim((string)($params['note'] ?? '')), 0, 500);
        if (!in_array($resolution, ['recovered', 'unrecoverable'], true) || $note === '') {
            self::setError('工票作废控制必须选择已收回或无法收回并填写说明');
            return false;
        }
        try {
            return Db::transaction(static function () use ($controlId, $resolution, $note) {
                $control = Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
                    ->where('id', $controlId)->lock(true)->find();
                if (!$control) {
                    self::setError('工票作废控制不存在');
                    return false;
                }
                if ((string)$control['status'] === 'closed') {
                    if ((string)$control['resolution'] === $resolution && (string)$control['resolution_note'] === $note) {
                        return $control;
                    }
                    self::setError('工票作废控制已完成，不能覆盖处理结果');
                    return false;
                }
                if ((string)$control['status'] !== 'pending_recovery') {
                    self::setError('工票作废控制当前不可变更');
                    return false;
                }
                $now = time();
                $status = $resolution === 'recovered' ? 'closed' : 'notice_required';
                Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())->where('id', $controlId)->update([
                    'status' => $status, 'resolution' => $resolution, 'resolution_note' => $note,
                    'resolved_by' => self::operatorId(), 'resolved_time' => $now, 'update_time' => $now,
                ]);
                if ($resolution === 'recovered') {
                    Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                        ->where('print_log_id', (int)$control['print_log_id'])->update([
                            'paper_status' => 'void_recovered', 'accounted_by' => self::operatorId(), 'accounted_time' => $now,
                            'account_reason' => 'void_recovered', 'account_note' => $note, 'update_time' => $now,
                        ]);
                }
                AuditService::logWithinTransaction(
                    'fulfillment_ticket_control', 'resolve', $controlId, '', $control,
                    array_replace($control, ['status' => $status, 'resolution' => $resolution, 'resolution_note' => $note]), $note
                );
                self::refreshGroupForTask((int)$control['task_id']);
                return Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())->where('id', $controlId)->find();
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('工票作废控制保存失败');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function controlPrintData(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.control') || !WorkforceLogic::requirePermission('task.reprint')) {
            return false;
        }
        $controlId = (int)($params['control_id'] ?? 0);
        try {
            return Db::transaction(static function () use ($controlId) {
                $control = Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
                    ->where('id', $controlId)->lock(true)->find();
                if (!$control) {
                    self::setError('当前没有需要打印的工票作废通知');
                    return false;
                }
                if ((string)$control['status'] === 'notice_printing' && (int)$control['notice_print_log_id'] > 0) {
                    $existingLog = Db::name('fulfillment_print_log')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$control['notice_print_log_id'])->where('task_id', (int)$control['task_id'])
                        ->where('status', 'pending')->find();
                    $existingCopy = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                        ->where('print_log_id', (int)$control['notice_print_log_id'])->where('control_id', $controlId)
                        ->where('paper_status', 'not_issued')->find();
                    if ($existingLog && $existingCopy) {
                        return self::controlPrintPayload($control, (int)$existingLog['id'], (int)$existingLog['create_time']);
                    }
                }
                if ((string)$control['status'] !== 'notice_required') {
                    self::setError('当前没有需要打印的工票作废通知');
                    return false;
                }
                $now = time();
                $logId = (int)Db::name('fulfillment_print_log')->insertGetId([
                    'tenant_id' => self::tenantId(), 'task_id' => (int)$control['task_id'], 'operator_id' => self::operatorId(),
                    'status' => 'pending', 'create_time' => $now, 'update_time' => $now,
                ]);
                Db::name('fulfillment_paper_copy')->insert([
                    'tenant_id' => self::tenantId(), 'task_id' => (int)$control['task_id'], 'print_log_id' => $logId,
                    'control_id' => $controlId, 'print_type' => $control['action_type'] === 'void' ? 'void_notice' : 'change_notice',
                    'copy_no' => 0, 'ticket_no' => '', 'content_version' => 1,
                    'content_hash' => hash('sha256', (string)$control['after_snapshot']), 'paper_status' => 'not_issued',
                    'create_time' => $now, 'update_time' => $now,
                ]);
                Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())->where('id', $controlId)->update([
                    'status' => 'notice_printing', 'notice_print_log_id' => $logId, 'update_time' => $now,
                ]);
                return self::controlPrintPayload($control, $logId, $now);
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('工票作废通知准备失败');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function controlPrintResult(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.control') || !WorkforceLogic::requirePermission('task.reprint')) {
            return false;
        }
        $controlId = (int)($params['control_id'] ?? 0);
        $logId = (int)($params['print_log_id'] ?? 0);
        $success = (int)($params['success'] ?? 0) === 1;
        $error = mb_substr(trim((string)($params['error_message'] ?? '')), 0, 255);
        try {
            return Db::transaction(static function () use ($controlId, $logId, $success, $error) {
                $control = Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
                    ->where('id', $controlId)->lock(true)->find();
                $log = Db::name('fulfillment_print_log')->where('tenant_id', self::tenantId())->where('id', $logId)
                    ->where('task_id', (int)($control['task_id'] ?? 0))->lock(true)->find();
                $copy = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                    ->where('print_log_id', $logId)->where('control_id', $controlId)->lock(true)->find();
                if (!$control || !$log || !$copy) {
                    self::setError('工票作废通知打印回执无效');
                    return false;
                }
                $wanted = $success ? 'success' : 'failed';
                if ((string)$log['status'] !== 'pending') {
                    if ((string)$log['status'] === $wanted) {
                        return $control;
                    }
                    self::setError('工票作废通知回执与已保存结果冲突');
                    return false;
                }
                $now = time();
                Db::name('fulfillment_print_log')->where('tenant_id', self::tenantId())->where('id', $logId)->update([
                    'status' => $wanted, 'error_message' => $error, 'update_time' => $now,
                ]);
                Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())->where('id', (int)$copy['id'])->update([
                    'paper_status' => $success ? 'notice_printed' : 'print_failed',
                    'printed_time' => $success ? $now : 0, 'update_time' => $now,
                ]);
                $status = $success ? 'closed' : 'notice_required';
                Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())->where('id', $controlId)->update([
                    'status' => $status, 'update_time' => $now,
                ]);
                if ($success) {
                    Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                        ->where('print_log_id', (int)$control['print_log_id'])->update([
                            'paper_status' => 'void_notice_printed', 'accounted_by' => self::operatorId(),
                            'accounted_time' => $now, 'account_reason' => 'void_notice_printed',
                            'account_note' => (string)$control['resolution_note'], 'update_time' => $now,
                        ]);
                    AuditService::logWithinTransaction(
                        'fulfillment_ticket_control', 'notice_printed', $controlId, '', $control,
                        array_replace($control, ['status' => 'closed', 'notice_print_log_id' => $logId]), (string)$control['resolution_note']
                    );
                }
                self::refreshGroupForTask((int)$control['task_id']);
                return Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())->where('id', $controlId)->find();
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('工票作废通知回执保存失败');
            }
            return false;
        }
    }

    /** @param array<string,mixed> $control @return array<string,mixed> */
    private static function controlPrintPayload(array $control, int $logId, int $requestedTime): array
    {
        return [
            'print_log_id' => $logId,
            'print_requested_time' => $requestedTime,
            'print_type' => $control['action_type'] === 'void' ? 'void_notice' : 'change_notice',
            'printer' => ['name' => 'XP-N160II', 'paper_width_mm' => 80, 'transport' => 'bluetooth'],
            'notice' => [
                'control_id' => (int)$control['id'], 'action_type' => $control['action_type'], 'reason' => $control['reason'],
                'before' => json_decode((string)$control['before_snapshot'], true),
                'after' => json_decode((string)$control['after_snapshot'], true),
            ],
        ];
    }

    public static function hasUnaccountedPaperForReport(int $reportId): bool
    {
        if ($reportId <= 0) {
            return false;
        }
        $paperCount = (int)Db::name('fulfillment_paper_copy')->alias('c')
            ->join('fulfillment_task t', 't.id=c.task_id AND t.tenant_id=c.tenant_id')
            ->where('c.tenant_id', self::tenantId())->where('t.report_id', $reportId)
            ->whereIn('c.paper_status', ['issued', 'void_required'])->count();
        if ($paperCount > 0) {
            return true;
        }
        $controlCount = (int)Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
            ->where('report_id', $reportId)->where('status', '<>', 'closed')->count();
        if ($controlCount > 0) {
            return true;
        }
        return (int)Db::name('fulfillment_print_log')->alias('l')
            ->join('fulfillment_task t', 't.id=l.task_id AND t.tenant_id=l.tenant_id')
            ->where('l.tenant_id', self::tenantId())->where('t.report_id', $reportId)->where('l.status', 'pending')->count() > 0;
    }

    /**
     * 调用方必须已经锁定报货单和明细并处于事务内。
     * @param array<string,mixed> $before @param array<string,mixed> $after
     * @return array<int,array<string,mixed>>
     */
    public static function invalidatePrintedItemTickets(
        int $itemId,
        int $itemChangeId,
        string $actionType,
        string $actionKey,
        string $reason,
        array $before,
        array $after,
        string $plannedQty
    ): array {
        $tasks = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('report_item_id', $itemId)
            ->where('status', '<>', 'cancelled')->order('id')->lock(true)->select()->toArray();
        $controls = [];
        foreach ($tasks as $task) {
            $task = self::ensureContentIdentity($task);
            $copies = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                ->where('task_id', (int)$task['id'])->where('print_type', 'task')
                ->where('content_version', (int)$task['content_version'])->where('paper_status', '<>', 'print_failed')
                ->order('id')->lock(true)->select()->toArray();
            $nextVersion = (int)$task['content_version'] + ($copies === [] ? 0 : 1);
            $mustRepeatTask = $copies !== []
                || bccomp((string)($task['process_weight'] ?? '0'), '0.00', 2) > 0
                || in_array((string)$task['status'], ['recovered', 'ready_to_bill', 'completed'], true);
            Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', (int)$task['id'])->update([
                'planned_qty' => self::decimal($plannedQty), 'content_version' => $nextVersion, 'content_hash' => '',
                'status' => $mustRepeatTask ? 'printable' : (string)$task['status'],
                'actual_weight' => '0.00', 'process_weight' => '0.00', 'actual_price' => '0.00',
                'recovery_note' => '', 'recovered_by' => 0, 'recovered_time' => 0, 'update_time' => time(),
            ]);
            $updatedTask = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', (int)$task['id'])->find();
            self::ensureContentIdentity($updatedTask);
            foreach ($copies as $copy) {
                $controlKey = $actionKey . ':paper:' . (int)$copy['id'];
                $existing = Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
                    ->where('action_key', $controlKey)->find();
                if ($existing) {
                    $controls[] = $existing;
                    continue;
                }
                $paperStatus = (string)$copy['paper_status'];
                $autoRecovered = in_array($paperStatus, ['recovered', 'void_recovered', 'void_notice_printed'], true);
                $exceptionAccounted = in_array($paperStatus, ['lost', 'damaged', 'illegible'], true);
                $now = time();
                if ($paperStatus === 'not_issued') {
                    Db::name('fulfillment_print_log')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$copy['print_log_id'])->where('status', 'pending')
                        ->update(['status' => 'superseded', 'error_message' => 'printed_content_changed', 'update_time' => $now]);
                }
                Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())->where('id', (int)$copy['id'])->update([
                    'paper_status' => $autoRecovered ? 'void_recovered' : 'void_required', 'update_time' => $now,
                ]);
                $controlId = (int)Db::name('fulfillment_ticket_control')->insertGetId([
                    'tenant_id' => self::tenantId(), 'report_id' => (int)$task['report_id'], 'report_item_id' => $itemId,
                    'item_change_id' => $itemChangeId,
                    'task_id' => (int)$task['id'], 'print_log_id' => (int)$copy['print_log_id'],
                    'action_type' => $actionType, 'action_key' => $controlKey, 'reason' => $reason,
                    'before_snapshot' => json_encode($before, JSON_UNESCAPED_UNICODE),
                    'after_snapshot' => json_encode($after, JSON_UNESCAPED_UNICODE),
                    'status' => $autoRecovered ? 'closed' : ($exceptionAccounted ? 'notice_required' : 'pending_recovery'),
                    'resolution' => ($autoRecovered || $exceptionAccounted) ? ($autoRecovered ? 'recovered' : 'unrecoverable') : '',
                    'resolution_note' => ($autoRecovered || $exceptionAccounted)
                        ? ((string)$copy['account_note'] !== '' ? (string)$copy['account_note'] : $reason) : '',
                    'resolved_by' => ($autoRecovered || $exceptionAccounted)
                        ? ((int)$copy['accounted_by'] > 0 ? (int)$copy['accounted_by'] : self::operatorId()) : 0,
                    'resolved_time' => ($autoRecovered || $exceptionAccounted)
                        ? ((int)$copy['accounted_time'] > 0 ? (int)$copy['accounted_time'] : $now) : 0,
                    'create_time' => $now, 'update_time' => $now,
                ]);
                $controls[] = Db::name('fulfillment_ticket_control')->where('id', $controlId)->find();
            }
        }
        Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', $itemId)->update([
            'final_actual_weight' => '0.00', 'final_weight_task_id' => 0,
            'fulfillment_status' => (string)($after['fulfillment_status'] ?? 'pending'), 'update_time' => time(),
        ]);
        self::refreshGroupForItem($itemId);
        return $controls;
    }

    public static function refreshGroupForItem(int $itemId): void
    {
        $groupId = (int)Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
            ->where('report_item_id', $itemId)->order('id')->value('group_id');
        self::refreshGroupStates($groupId);
    }

    private static function refreshGroupForTask(int $taskId): void
    {
        $groupId = (int)Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
            ->where('id', $taskId)->value('group_id');
        self::refreshGroupStates($groupId);
    }

    /** @param array<string,mixed> $report @param array<string,mixed>|null $item @param array<string,mixed>|null $process @param array<string,mixed> $overrides */
    private static function upsertTask(array $report, ?array $item, int $groupId, ?array $process, string $sourceKey, array $overrides): int
    {
        $tenantId = self::tenantId();
        $existing = Db::name('fulfillment_task')->where('tenant_id', $tenantId)->where('report_id', (int)$report['id'])->where('source_key', $sourceKey)->find();
        $now = time();
        $base = [
            'group_id' => $groupId,
            'report_item_id' => (int)($item['id'] ?? 0),
            'process_id' => (int)($process['id'] ?? 0),
            'process_name_snapshot' => $existing && trim((string)($existing['process_name_snapshot'] ?? '')) !== ''
                ? (string)$existing['process_name_snapshot'] : (string)($process['name'] ?? ''),
            'process_sort_snapshot' => $existing
                ? (int)$existing['process_sort_snapshot'] : (int)($process['sort'] ?? 0),
            'task_type' => 'process',
            'exception_code' => '',
            'depends_on_task_id' => 0,
            'status' => 'unassigned',
            'customer_name' => (string)($item['delivery_customer_name'] ?? $report['main_customer_name'] ?? ''),
            'goods_name' => (string)((($item['sku_name'] ?? '') ?: ($item['goods_name'] ?? ''))),
            'planned_qty' => self::decimal((string)($item['expected_base_qty'] ?? '0')),
            'unit_name' => (string)($item['base_unit_name'] ?? $item['unit_name'] ?? ''),
            'requirement' => (string)($item['processing_requirement'] ?? ''),
            'is_settlement_task' => 0,
            'update_time' => $now,
        ];
        $data = array_merge($base, $overrides);
        if ($existing) {
            if (in_array((string)$existing['status'], ['unassigned', 'blocked', 'printable', 'print_failed', 'exception', 'cancelled'], true)) {
                if ((int)$existing['assignee_employee_id'] > 0 && $data['status'] === 'unassigned') {
                    $data['status'] = 'printable';
                }
                $data['content_hash'] = '';
                Db::name('fulfillment_task')->where('tenant_id', $tenantId)->where('id', (int)$existing['id'])->update($data);
            }
            return (int)$existing['id'];
        }
        return (int)Db::name('fulfillment_task')->insertGetId($data + [
            'tenant_id' => $tenantId,
            'report_id' => (int)$report['id'],
            'source_key' => $sourceKey,
            'ticket_no' => 'WT' . date('ymd') . str_pad((string)$groupId, 5, '0', STR_PAD_LEFT) . substr(sha1($sourceKey), 0, 4),
            'create_time' => $now,
        ]);
    }

    private static function refreshGroupStates(int $groupId): void
    {
        if ($groupId <= 0) {
            return;
        }
        $tasks = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('group_id', $groupId)->select()->toArray();
        if ($tasks === []) {
            return;
        }
        foreach ($tasks as $task) {
            $dependsOn = (int)$task['depends_on_task_id'];
            if ($dependsOn > 0 && (string)$task['status'] === 'blocked' && self::dependencyReady($dependsOn)) {
                Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', (int)$task['id'])->update([
                    'status' => (int)$task['assignee_employee_id'] > 0 ? 'printable' : 'unassigned', 'update_time' => time(),
                ]);
            }
        }
        $upstreamRows = array_values(array_filter($tasks, static fn(array $task): bool => !str_ends_with((string)$task['source_key'], ':delivery') && !str_ends_with((string)$task['source_key'], ':bookkeeping')));
        $allUpstreamFinished = $upstreamRows !== [] && count(array_filter($upstreamRows, static fn(array $task): bool => in_array((string)$task['status'], self::FINISHED, true))) === count($upstreamRows);
        if ($allUpstreamFinished) {
            self::unlockReportTask($groupId, 'delivery');
        }
        $delivery = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('group_id', $groupId)->whereLike('source_key', '%:delivery')->find();
        if ($delivery && in_array((string)$delivery['status'], self::FINISHED, true)) {
            self::unlockReportTask($groupId, 'bookkeeping');
        }
        $remaining = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('group_id', $groupId)->whereNotIn('status', self::FINISHED)->count();
        $openControls = (int)Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
            ->whereIn('task_id', array_map('intval', array_column($tasks, 'id')))->where('status', '<>', 'closed')->count();
        $groupStatus = $openControls > 0 ? 'paper_control_pending' : ($remaining === 0 ? 'completed' : 'open');
        Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())->where('id', $groupId)
            ->update(['status' => $groupStatus, 'update_time' => time()]);
    }

    private static function unlockReportTask(int $groupId, string $code): void
    {
        $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('group_id', $groupId)->whereLike('source_key', '%:' . $code)->find();
        if ($task && (string)$task['status'] === 'blocked') {
            Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', (int)$task['id'])->update([
                'status' => (int)$task['assignee_employee_id'] > 0 ? 'printable' : 'unassigned', 'update_time' => time(),
            ]);
        }
    }

    private static function dependencyReady(int $taskId): bool
    {
        if ($taskId <= 0) {
            return true;
        }
        $status = (string)Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $taskId)->value('status');
        return in_array($status, ['recovered', 'ready_to_bill', 'completed'], true);
    }

    /** @return array<string,mixed>|false */
    private static function taskById(int $id): array|false
    {
        $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->find();
        if (!$task) {
            self::setError('任务不存在');
            return false;
        }
        return self::hydrateTasks([$task])[0];
    }

    /** @param array<int,array<string,mixed>> $tasks @return array<int,array<string,mixed>> */
    private static function hydrateTasks(array $tasks): array
    {
        $processIds = array_values(array_unique(array_filter(array_map(static fn(array $task): int => (int)$task['process_id'], $tasks))));
        $processes = $processIds === [] ? [] : Db::name('work_process')->where('tenant_id', self::tenantId())->whereIn('id', $processIds)->column('name', 'id');
        $itemIds = array_values(array_unique(array_filter(array_map(static fn(array $task): int => (int)$task['report_item_id'], $tasks))));
        $items = $itemIds === [] ? [] : Db::name('customer_report_item')->where('tenant_id', self::tenantId())
            ->whereIn('id', $itemIds)->column(
                'piece_weight_confirmed,piece_weight_min,piece_weight_max,acceptable_base_qty_min,acceptable_base_qty_max,specification_verification_status,specification_verification_note',
                'id'
            );
        $settlementTaskIds = self::settlementTaskIdsForItems($itemIds);
        foreach ($tasks as &$task) {
            if (trim((string)($task['process_name_snapshot'] ?? '')) === '' && (int)$task['process_id'] > 0) {
                $task['process_name_snapshot'] = (string)($processes[$task['process_id']] ?? '');
                if ($task['process_name_snapshot'] !== '') {
                    Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', (int)$task['id'])->update([
                        'process_name_snapshot' => $task['process_name_snapshot'], 'update_time' => time(),
                    ]);
                }
            }
            $task = self::ensureContentIdentity($task);
            $task['process_name'] = (string)($task['process_name_snapshot'] ?? '');
            $task['is_supplement'] = (int)Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())->where('id', (int)$task['group_id'])->value('is_supplement');
            $task['requires_settlement'] = (int)($settlementTaskIds[(int)$task['report_item_id']] ?? 0) === (int)$task['id'];
            $requirementItem = $items[(int)$task['report_item_id']] ?? [];
            $task['piece_weight_confirmed'] = (int)($requirementItem['piece_weight_confirmed'] ?? 0);
            $task['piece_weight_min'] = self::decimal((string)($requirementItem['piece_weight_min'] ?? '0'));
            $task['piece_weight_max'] = self::decimal((string)($requirementItem['piece_weight_max'] ?? '0'));
            $task['acceptable_base_qty_min'] = self::decimal((string)($requirementItem['acceptable_base_qty_min'] ?? '0'));
            $task['acceptable_base_qty_max'] = self::decimal((string)($requirementItem['acceptable_base_qty_max'] ?? '0'));
            $task['specification_verification_status'] = (string)($requirementItem['specification_verification_status'] ?? 'not_required');
            $task['specification_verification_note'] = (string)($requirementItem['specification_verification_note'] ?? '');
            $successfulCopies = (int)Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                ->where('task_id', (int)$task['id'])->where('print_type', 'task')
                ->whereNotIn('paper_status', ['not_issued', 'print_failed'])->count();
            $task['reprint_count'] = max(0, $successfulCopies - 1);
            $task['outstanding_paper_count'] = (int)Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                ->where('task_id', (int)$task['id'])->whereIn('paper_status', ['issued', 'void_required'])->count();
            $task['issued_paper_copies'] = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                ->where('task_id', (int)$task['id'])->where('print_type', 'task')->where('paper_status', 'issued')
                ->order('id')->field('id,print_log_id,copy_no,printed_time')->select()->toArray();
            foreach ($task['issued_paper_copies'] as &$paperCopy) {
                $paperCopy['label'] = '第 ' . max(1, (int)$paperCopy['copy_no']) . ' 张 · '
                    . ((int)$paperCopy['printed_time'] > 0 ? date('m-d H:i', (int)$paperCopy['printed_time']) : '打印时间待确认');
            }
            unset($paperCopy);
            $task['paper_controls'] = Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
                ->where('task_id', (int)$task['id'])->where('status', '<>', 'closed')->order('id')
                ->field('id,action_type,reason,status')->select()->toArray();
            $accounted = Db::name('fulfillment_paper_copy')->where('tenant_id', self::tenantId())
                ->where('task_id', (int)$task['id'])->where('accounted_time', '>', 0)->order('accounted_time desc,id desc')->find();
            $task['recovery_mode'] = $accounted && in_array((string)$accounted['account_reason'], ['lost', 'damaged', 'illegible'], true)
                ? 'exception' : ($accounted ? 'normal' : '');
            $task['recovery_exception_reason'] = $task['recovery_mode'] === 'exception' ? (string)$accounted['account_reason'] : '';
            $task['recovery_exception_note'] = $task['recovery_mode'] === 'exception' ? (string)$accounted['account_note'] : '';
            $task['ticket_display'] = self::ticketSnapshot($task);
            unset($task['assignee_employee_id'], $task['assignee_name']);
        }
        unset($task);
        return $tasks;
    }

    /** @param array<string,mixed> $task */
    private static function isSettlementTask(array $task): bool
    {
        $itemId = (int)($task['report_item_id'] ?? 0);
        if ($itemId <= 0) {
            return false;
        }
        $ids = self::settlementTaskIdsForItems([$itemId]);
        return (int)($ids[$itemId] ?? 0) === (int)($task['id'] ?? 0);
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private static function ensureContentIdentity(array $task): array
    {
        $task = self::ensureProcessNameSnapshot($task);
        $snapshot = self::ticketSnapshot($task);
        $hash = hash('sha256', (string)json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        if ((string)($task['content_hash'] ?? '') !== $hash) {
            Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', (int)$task['id'])->update([
                'content_hash' => $hash, 'update_time' => time(),
            ]);
            $task['content_hash'] = $hash;
        }
        $task['content_version'] = max(1, (int)($task['content_version'] ?? 1));
        return $task;
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private static function ensureProcessNameSnapshot(array $task): array
    {
        if (trim((string)($task['process_name_snapshot'] ?? '')) !== '' || (int)($task['process_id'] ?? 0) <= 0) {
            return $task;
        }
        $name = (string)Db::name('work_process')->where('tenant_id', self::tenantId())
            ->where('id', (int)$task['process_id'])->value('name');
        if ($name !== '') {
            Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', (int)$task['id'])
                ->where('process_name_snapshot', '')->update(['process_name_snapshot' => $name, 'update_time' => time()]);
            $task['process_name_snapshot'] = $name;
        }
        return $task;
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private static function ticketSnapshot(array $task): array
    {
        $report = Db::name('customer_report')->where('tenant_id', self::tenantId())->where('id', (int)($task['report_id'] ?? 0))
            ->field('sn,main_customer_id,main_customer_name,is_supplement')->find() ?: [];
        $item = (int)($task['report_item_id'] ?? 0) > 0
            ? (Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', (int)$task['report_item_id'])
                ->field('goods_name,sku_name,delivery_customer_id,delivery_customer_name,base_unit_name,piece_weight_confirmed,piece_weight_min,piece_weight_max,acceptable_base_qty_min,acceptable_base_qty_max')->find() ?: [])
            : [];
        $processName = (string)($task['process_name_snapshot'] ?? '');
        $mainName = (string)($report['main_customer_name'] ?? '');
        $deliveryName = (string)($item['delivery_customer_name'] ?? $mainName);
        return [
            'ticket_no' => (string)($task['ticket_no'] ?? ''),
            'report_sn' => (string)($report['sn'] ?? ''),
            'content_version' => max(1, (int)($task['content_version'] ?? 1)),
            'is_supplement' => (int)($report['is_supplement'] ?? 0),
            'main_customer' => ['name' => $mainName, 'emphasis' => true],
            'delivery_customer' => [
                'name' => $deliveryName,
                'is_child' => (int)($item['delivery_customer_id'] ?? 0) > 0
                    && (int)($item['delivery_customer_id'] ?? 0) !== (int)($report['main_customer_id'] ?? 0),
                'emphasis' => false,
            ],
            'process_name' => $processName,
            'goods_name' => (string)(($item['sku_name'] ?? '') ?: ($item['goods_name'] ?? $task['goods_name'] ?? '')),
            'planned_qty' => self::decimal((string)($task['planned_qty'] ?? '0')),
            'unit_name' => (string)(($item['base_unit_name'] ?? '') ?: ($task['unit_name'] ?? '')),
            'requirement' => trim(implode('；', array_filter([
                (string)($task['requirement'] ?? ''),
                (int)($item['piece_weight_confirmed'] ?? 0) === 1
                    ? '单条 ' . self::decimal((string)$item['piece_weight_min']) . '～' . self::decimal((string)$item['piece_weight_max']) . '斤'
                    : '',
                (int)($item['piece_weight_confirmed'] ?? 0) === 1
                    ? '可接受总重 ' . self::decimal((string)$item['acceptable_base_qty_min']) . '～' . self::decimal((string)$item['acceptable_base_qty_max']) . '斤'
                    : '',
            ]))),
        ];
    }

    /** @param array<int,int> $itemIds @return array<int,int> report_item_id => task_id */
    private static function settlementTaskIdsForItems(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($itemIds === []) {
            return [];
        }
        $rows = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->whereIn('report_item_id', $itemIds)
            ->where('is_settlement_task', 1)->where('status', '<>', 'cancelled')
            ->order(['report_item_id' => 'asc', 'id' => 'asc'])->field('id,report_item_id')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $itemId = (int)$row['report_item_id'];
            if (!isset($result[$itemId])) {
                $result[$itemId] = (int)$row['id'];
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $task @return array{label:string,type:string,trigger_type?:string} */
    private static function exceptionAction(array $task): array
    {
        if ($task['status'] === 'blocked') {
            return ['label' => '等待前置', 'type' => 'blocked'];
        }
        if ($task['status'] === 'print_failed') {
            return ['label' => '重试', 'type' => 'retry_print'];
        }
        if (in_array((string)$task['exception_code'], ['missing_inventory_shortage_process', 'missing_delivery_process'], true)) {
            if (!WorkforceLogic::hasPermission('process.manage')) {
                return ['label' => '联系管理员', 'type' => 'contact_admin'];
            }
            return [
                'label' => '配置工序',
                'type' => 'configure_process',
                'trigger_type' => (string)$task['exception_code'] === 'missing_inventory_shortage_process'
                    ? 'inventory_shortage' : 'all_processing_completed',
            ];
        }
        return match ((string)$task['exception_code']) {
            'missing_remark' => ['label' => '补备注', 'type' => 'edit_report'],
            'unrecognized_remark' => ['label' => '确认工序', 'type' => 'resolve_process'],
            default => ['label' => '去处理', 'type' => 'open_task'],
        };
    }

    private static function transactionWithRetry(callable $operation): mixed
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return Db::transaction($operation);
            } catch (\Throwable $exception) {
                $retryable = in_array((int)$exception->getCode(), [1205, 1213], true)
                    || str_contains($exception->getMessage(), '1205')
                    || str_contains($exception->getMessage(), '1213');
                if (!$retryable || $attempt === 3) {
                    throw $exception;
                }
                usleep(50_000 * $attempt);
            }
        }
        throw new \RuntimeException('fulfillment_transaction_retry_exhausted');
    }

    private static function money(string $value): string|false
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return false;
        }
        return self::decimal($value);
    }

    private static function decimal(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', 2);
    }

    private static function date(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return date('Y-m-d');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('invalid_delivery_date');
        }
        return $value;
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function operatorId(): int
    {
        return (int)(request()->userId ?? request()->adminId ?? 0);
    }
}
