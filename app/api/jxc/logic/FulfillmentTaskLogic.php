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
        WorkforceLogic::ensureInitialProcesses();
        $processes = Db::name('work_process')->where('tenant_id', $tenantId)->where('is_enabled', 1)->whereNull('delete_time')
            ->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        $byCode = [];
        $remarkProcesses = [];
        foreach ($processes as $process) {
            $byCode[(string)$process['code']] = $process;
            if ((string)$process['trigger_type'] === 'remark') {
                $remarkProcesses[] = $process;
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
            $requirement = trim((string)($item['processing_requirement'] ?: $item['line_remark']));
            $shortageTaskId = 0;
            if (bccomp((string)$item['shortage_base_qty'], '0.00', 2) > 0 && isset($byCode['purchase'])) {
                $key = 'item:' . $itemId . ':shortage';
                $desired[] = $key;
                $shortageTaskId = self::upsertTask($report, $item, $groupId, $byCode['purchase'], $key, [
                    'status' => 'unassigned',
                    'is_settlement_task' => 0,
                    'requirement' => '缺货 ' . self::decimal((string)$item['shortage_base_qty']) . (string)$item['base_unit_name'],
                    'planned_qty' => self::decimal((string)$item['shortage_base_qty']),
                ]);
            }

            $matched = [];
            if ($requirement !== '') {
                foreach ($remarkProcesses as $process) {
                    $keywords = json_decode((string)$process['trigger_keywords'], true);
                    foreach (is_array($keywords) ? $keywords : [] as $keyword) {
                        if ((string)$keyword !== '' && mb_stripos($requirement, (string)$keyword) !== false) {
                            $matched[(int)$process['id']] = $process;
                            break;
                        }
                    }
                }
            }
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
                        'depends_on_task_id' => $shortageTaskId,
                        'status' => $shortageTaskId > 0 ? 'blocked' : 'unassigned',
                        'is_settlement_task' => $key === $selectedSourceKey ? 1 : 0,
                        'requirement' => $requirement,
                    ]);
                }
            }
        }

        foreach (['delivery', 'bookkeeping'] as $code) {
            if (!isset($byCode[$code])) {
                continue;
            }
            $key = 'report:' . $reportId . ':' . $code;
            $desired[] = $key;
            self::upsertTask($report, null, $groupId, $byCode[$code], $key, [
                'status' => 'blocked',
                'is_settlement_task' => 0,
                'requirement' => $code === 'delivery' ? '全部前置工票回收后送货' : '送货票回收后记账',
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

    /** @return array<string,mixed>|false */
    public static function dashboard(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.view')) {
            return false;
        }
        WorkforceLogic::ensureInitialProcesses();
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
                'assignees' => array_values(array_unique(array_filter(array_column($processTasks, 'assignee_name')))),
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
        return [
            'batch' => ['delivery_date' => $deliveryDate, 'customer_count' => $customerCount, 'task_group_count' => count($groupIds)],
            'printer' => ['name' => 'XP-N160II', 'paper_width_mm' => 80, 'transport' => 'bluetooth'],
            'exceptions' => array_slice($exceptions, 0, 20),
            'exception_count' => count($exceptions),
            'tabs' => $counts,
            'process_groups' => $processGroups,
        ];
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

    /** @return array<int,array<string,mixed>>|false */
    public static function candidates(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.assign')) {
            return false;
        }
        $task = self::taskById((int)($params['id'] ?? 0));
        return $task === false ? false : WorkforceLogic::candidatesForProcess((int)$task['process_id']);
    }

    /** @return array<string,mixed>|false */
    public static function assign(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.assign')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $employeeId = (int)($params['employee_id'] ?? 0);
        try {
            $result = Db::transaction(static function () use ($id, $employeeId) {
                $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->lock(true)->find();
                if (!$task || (int)$task['process_id'] <= 0 || in_array((string)$task['status'], self::FINISHED, true)) {
                    self::setError('任务当前不可分配');
                    return false;
                }
                $employee = Db::name('employee')->alias('e')->join('employee_process ep', 'ep.employee_id=e.id AND ep.tenant_id=e.tenant_id')
                    ->where('e.tenant_id', self::tenantId())->where('e.id', $employeeId)->where('ep.process_id', (int)$task['process_id'])
                    ->where('e.is_enabled', 1)->whereNull('e.delete_time')->field('e.id,e.name')->find();
                if (!$employee) {
                    self::setError('该员工未配置此工序能力');
                    return false;
                }
                $status = self::dependencyReady((int)$task['depends_on_task_id']) ? 'printable' : 'blocked';
                Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                    'assignee_employee_id' => $employeeId,
                    'assignee_name' => (string)$employee['name'],
                    'status' => $status,
                    'update_time' => time(),
                ]);
                return self::taskById($id);
            });
            return $result;
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('任务分配失败');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function resolveException(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.assign') || !WorkforceLogic::requirePermission('report.remark')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $processId = (int)($params['process_id'] ?? 0);
        try {
            return Db::transaction(static function () use ($id, $processId, $params) {
                $process = Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $processId)
                    ->where('trigger_type', 'remark')->where('is_enabled', 1)->whereNull('delete_time')->lock(true)->find();
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
                    'task_type' => 'process',
                    'exception_code' => '',
                    'depends_on_task_id' => $purchaseId,
                    'requirement' => $requirement,
                    'is_settlement_task' => $becomesOwner ? 1 : 0,
                    'status' => self::dependencyReady($purchaseId) ? 'unassigned' : 'blocked',
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
                if (!$raw || !in_array((string)$raw['status'], ['printable', 'print_failed'], true)) {
                    self::setError('任务当前不可打印');
                    return false;
                }
                $permission = (int)$raw['print_count'] > 0 || (string)$raw['status'] === 'print_failed' ? 'task.reprint' : 'task.print';
                if (!WorkforceLogic::requirePermission($permission)) {
                    return false;
                }
                $pending = Db::name('fulfillment_print_log')->where('tenant_id', self::tenantId())
                    ->where('task_id', $id)->where('status', 'pending')->lock(true)->find();
                if ($pending) {
                    self::setError('上一张工票的打印回执尚未确认');
                    return false;
                }
                $now = time();
                $logId = (int)Db::name('fulfillment_print_log')->insertGetId([
                    'tenant_id' => self::tenantId(), 'task_id' => $id, 'operator_id' => self::operatorId(),
                    'status' => 'pending', 'create_time' => $now, 'update_time' => $now,
                ]);
                return [
                    'print_log_id' => $logId,
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
                if (!$task || !$log) {
                    self::setError('打印回执无效');
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
                if (!in_array((string)$task['status'], ['printable', 'print_failed'], true)) {
                    self::setError('打印回执已过期');
                    return false;
                }
                $now = time();
                Db::name('fulfillment_print_log')->where('tenant_id', self::tenantId())->where('id', $logId)
                    ->where('status', 'pending')->update(['status' => $wanted, 'error_message' => $error, 'update_time' => $now]);
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
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.recover')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        try {
            return Db::transaction(static function () use ($id, $params) {
                $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->lock(true)->find();
                if (!$task) {
                    self::setError('工票不存在');
                    return false;
                }
                $requiresSettlement = self::isSettlementTask($task);
                if ($requiresSettlement && !WorkforceLogic::requirePermission('settlement.weight')) {
                    return false;
                }
                if ($requiresSettlement && !WorkforceLogic::requirePermission('settlement.price')) {
                    return false;
                }
                $weightInput = trim((string)($params['actual_weight'] ?? ''));
                $priceInput = trim((string)($params['actual_price'] ?? ''));
                if ($requiresSettlement && ($weightInput === '' || $priceInput === '')) {
                    self::setError('最终工序票回收必须录入实际重量和已确认单价');
                    return false;
                }
                $weight = self::money($weightInput === '' ? '0' : $weightInput);
                $price = self::money($priceInput === '' ? '0' : $priceInput);
                if ($weight === false || $price === false) {
                    self::setError('实际重量或价格格式不正确');
                    return false;
                }
                if ($requiresSettlement && (bccomp($weight, '0.00', 2) <= 0 || bccomp($price, '0.00', 2) <= 0)) {
                    self::setError('最终实重和已确认单价必须大于 0，未定价时不能完成结算回收');
                    return false;
                }
                $note = mb_substr(trim((string)($params['recovery_note'] ?? '')), 0, 500);
                if (in_array((string)$task['status'], ['recovered', 'ready_to_bill', 'completed'], true)) {
                    if (
                        bccomp((string)$task['actual_weight'], $weight, 2) === 0
                        && bccomp((string)$task['actual_price'], $price, 2) === 0
                        && (string)$task['recovery_note'] === $note
                    ) {
                        return self::taskById($id);
                    }
                    self::setError('工票已回收，不能覆盖最终实重、实价或回收说明');
                    return false;
                }
                if (!in_array((string)$task['status'], ['printed', 'in_progress'], true)) {
                    self::setError('只有已打印的工票可以回收');
                    return false;
                }
                if (str_ends_with((string)$task['source_key'], ':shortage')
                    && !CustomerReportLogic::completePurchaseForItem((int)$task['report_item_id'])) {
                    self::setError(CustomerReportLogic::getError());
                    return false;
                }
                $now = time();
                $nextStatus = str_ends_with((string)$task['source_key'], ':bookkeeping') ? 'ready_to_bill' : 'recovered';
                Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                    'status' => $nextStatus,
                    'actual_weight' => $weight,
                    'actual_price' => $price,
                    'recovery_note' => $note,
                    'recovered_by' => self::operatorId(),
                    'recovered_time' => $now,
                    'update_time' => $now,
                ]);
                self::refreshGroupStates((int)$task['group_id']);
                return self::taskById($id);
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('工票回收失败');
            }
            return false;
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
        $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->find();
        if (!$task || !str_ends_with((string)$task['source_key'], ':bookkeeping') || (string)$task['status'] !== 'ready_to_bill') {
            self::setError('只有已回收的记账工票可以确认开单');
            return false;
        }
        $report = Db::name('customer_report')->where('tenant_id', self::tenantId())->where('id', (int)$task['report_id'])->find();
        if (!$report) {
            self::setError('关联报货单不存在');
            return false;
        }
        $result = CustomerReportLogic::convert(['id' => (int)$report['id'], 'version' => (int)$report['version']]);
        if ($result === false) {
            self::setError(CustomerReportLogic::getError());
            return false;
        }
        $now = time();
        Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $id)->update(['status' => 'completed', 'update_time' => $now]);
        Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())->where('id', (int)$task['group_id'])->update(['status' => 'completed', 'update_time' => $now]);
        return ['task' => self::taskById($id), 'report' => $result];
    }

    /** @return array<int,array{actual_weight:string,actual_price:string,task_id:int}> */
    public static function settlementValuesForReport(int $reportId): array
    {
        $itemIds = array_map('intval', Db::name('customer_report_item')->where('tenant_id', self::tenantId())
            ->where('report_id', $reportId)->whereNull('delete_time')->column('id'));
        $settlementTaskIds = array_values(self::settlementTaskIdsForItems($itemIds));
        if ($settlementTaskIds === []) {
            return [];
        }
        $rows = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('report_id', $reportId)
            ->whereIn('id', $settlementTaskIds)->whereIn('status', ['recovered', 'ready_to_bill', 'completed'])
            ->where('actual_weight', '>', 0)->where('actual_price', '>', 0)
            ->field('id,report_item_id,actual_weight,actual_price')->select()->toArray();
        $values = [];
        foreach ($rows as $row) {
            $itemId = (int)$row['report_item_id'];
            $values[$itemId] = [
                'actual_weight' => self::decimal((string)$row['actual_weight']),
                'actual_price' => self::decimal((string)$row['actual_price']),
                'task_id' => (int)$row['id'],
            ];
        }
        return $values;
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
        $tasks = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('group_id', $groupId)->where('status', '<>', 'cancelled')->select()->toArray();
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
        Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())->where('id', $groupId)
            ->update(['status' => $remaining === 0 ? 'completed' : 'open', 'update_time' => time()]);
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
        $settlementTaskIds = self::settlementTaskIdsForItems($itemIds);
        foreach ($tasks as &$task) {
            $task['process_name'] = (string)($processes[$task['process_id']] ?? '');
            $task['is_supplement'] = (int)Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())->where('id', (int)$task['group_id'])->value('is_supplement');
            $task['candidates_count'] = (int)$task['process_id'] > 0 ? count(WorkforceLogic::candidatesForProcess((int)$task['process_id'])) : 0;
            $task['requires_settlement'] = (int)($settlementTaskIds[(int)$task['report_item_id']] ?? 0) === (int)$task['id'];
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

    /** @param array<string,mixed> $task @return array{label:string,type:string} */
    private static function exceptionAction(array $task): array
    {
        if ($task['status'] === 'blocked') {
            return ['label' => '等待前置', 'type' => 'blocked'];
        }
        if ($task['status'] === 'print_failed') {
            return ['label' => '重试', 'type' => 'retry_print'];
        }
        if ($task['status'] === 'unassigned') {
            return [
                'label' => (string)$task['process_name'] === '采购' ? '生成采购票' : '去分配',
                'type' => 'assign',
            ];
        }
        return match ((string)$task['exception_code']) {
            'missing_remark' => ['label' => '补备注', 'type' => 'edit_report'],
            'unrecognized_remark' => ['label' => '确认工序', 'type' => 'resolve_process'],
            default => ['label' => '去处理', 'type' => 'open_task'],
        };
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
