<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\ReceivableFlow;
use think\facade\Db;

/** 交付完成后的销售结算。这里只结算金额与应收，不再次扣减库存。 */
final class SalesSettlementLogic extends BaseLogic
{
    private const QUANTITY_SCALE = 4;
    private const MONEY_SCALE = 2;
    private const PRINT_RECEIPT_TIMEOUT_SECONDS = 600;

    /** @return array<string,mixed>|false */
    public static function detail(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('settlement.view')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $orderId = self::positiveInteger($params['id'] ?? null);
        if ($orderId === false) {
            self::setError('销售单参数不正确');
            return false;
        }
        return self::detailById($orderId);
    }

    /** @return array<string,mixed>|false */
    public static function preparePrint(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('settlement.view')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $orderId = self::positiveInteger($params['id'] ?? null);
        $expectedVersion = self::positiveInteger($params['expected_version'] ?? null);
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        if ($orderId === false || $expectedVersion === false
            || $idempotencyKey === '' || strlen($idempotencyKey) > 96) {
            self::setError('销售单打印参数不正确');
            return false;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return Db::transaction(static function () use ($orderId, $expectedVersion, $idempotencyKey) {
                    $order = Db::name('sales_order')->where('tenant_id', self::tenantId())
                        ->where('id', $orderId)->lock(true)->find();
                    if (!$order || (string)($order['source_type'] ?? '') !== 'customer_report') {
                        self::setError('销售单不存在');
                        return false;
                    }
                    $currentVersion = (int)($order['settlement_version'] ?? 0);
                    if ((string)($order['settlement_status'] ?? '') !== 'formal' || $currentVersion <= 0) {
                        self::setError('销售单当前不可打印');
                        return false;
                    }
                    $existing = Db::name('customer_sales_print_log')->where('tenant_id', self::tenantId())
                        ->where('idempotency_key', $idempotencyKey)->find();
                    if ($existing) {
                        if ((int)$existing['order_id'] !== $orderId || (int)$existing['version'] !== $expectedVersion) {
                            self::setError('同一打印幂等键不能用于不同销售单或版本');
                            return false;
                        }
                        $successfulCopies = (int)Db::name('customer_sales_print_log')
                            ->where('tenant_id', self::tenantId())->where('order_id', $orderId)
                            ->where('status', 'success')->count();
                        return self::printPreparationResult($order, $existing, $successfulCopies);
                    }
                    if ($currentVersion !== $expectedVersion) {
                        self::setError('销售单版本已变化，请重新加载后再打印');
                        return false;
                    }
                    // The sales-order row already serializes preparation for one order. Keep this
                    // derived-row lookup non-locking so a missing pending row never creates a gap lock.
                    $pending = Db::name('customer_sales_print_log')->where('tenant_id', self::tenantId())
                        ->where('order_id', $orderId)->where('status', 'pending')
                        ->order('id', 'desc')->find();
                    if ($pending) {
                        $pendingTime = max((int)$pending['create_time'], (int)$pending['update_time']);
                        if ($pendingTime > time() - self::PRINT_RECEIPT_TIMEOUT_SECONDS) {
                            self::setError('上一张销售单的打印回执尚未确认');
                            return false;
                        }
                    }
                    $successfulCopies = (int)Db::name('customer_sales_print_log')
                        ->where('tenant_id', self::tenantId())->where('order_id', $orderId)
                        ->where('status', 'success')->count();
                    // Reserve a monotonically increasing physical-copy number. A stale pending
                    // receipt may still arrive as success, so its number must never be reused.
                    $lastReservedCopy = (int)Db::name('customer_sales_print_log')
                        ->where('tenant_id', self::tenantId())->where('order_id', $orderId)
                        ->whereIn('status', ['pending', 'success'])
                        ->max('copy_no');
                    $copyNo = $lastReservedCopy + 1;
                    $now = time();
                    $logId = (int)Db::name('customer_sales_print_log')->insertGetId([
                        'tenant_id' => self::tenantId(),
                        'order_id' => $orderId,
                        'version' => $currentVersion,
                        'copy_no' => $copyNo,
                        'idempotency_key' => $idempotencyKey,
                        'status' => 'pending',
                        'error_message' => '',
                        'operator_id' => self::operatorId(),
                        'create_time' => $now,
                        'update_time' => $now,
                        'printed_time' => 0,
                    ]);
                    if ($logId <= 0) {
                        throw new \RuntimeException('customer_sales_print_log_insert_failed');
                    }
                    return self::printPreparationResult($order, [
                        'id' => $logId,
                        'version' => $currentVersion,
                        'copy_no' => $copyNo,
                        'idempotency_key' => $idempotencyKey,
                        'status' => 'pending',
                        'create_time' => $now,
                    ], $successfulCopies);
                });
            } catch (\Throwable $exception) {
                $replayed = self::replayPrintPreparation($idempotencyKey, $orderId, $expectedVersion);
                if (is_array($replayed)) {
                    self::clearError();
                    return $replayed;
                }
                if ($replayed === false) {
                    return false;
                }
                if (self::isLockRetryable($exception) && $attempt < 2) {
                    self::clearError();
                    usleep(20_000 * ($attempt + 1));
                    continue;
                }
                if (!self::hasError()) {
                    self::setError(isset($_SERVER['JXC_PHPUNIT_ENV'])
                        ? '销售单打印准备失败：' . $exception->getMessage()
                        : '销售单打印准备失败');
                }
                return false;
            }
        }
        self::setError('销售单打印准备失败');
        return false;
    }

    /** @return array<string,mixed>|false|null */
    private static function replayPrintPreparation(string $idempotencyKey, int $orderId, int $expectedVersion): array|false|null
    {
        $log = Db::name('customer_sales_print_log')->where('tenant_id', self::tenantId())
            ->where('idempotency_key', $idempotencyKey)->find();
        if (!$log) {
            return null;
        }
        $order = Db::name('sales_order')->where('tenant_id', self::tenantId())->where('id', $orderId)->find();
        if (!$order || (int)$log['order_id'] !== $orderId || (int)$log['version'] !== $expectedVersion) {
            self::setError('同一打印幂等键不能用于不同销售单或版本');
            return false;
        }
        $successfulCopies = (int)Db::name('customer_sales_print_log')
            ->where('tenant_id', self::tenantId())->where('order_id', $orderId)
            ->where('status', 'success')->count();
        return self::printPreparationResult($order, $log, $successfulCopies);
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $log @return array<string,mixed> */
    private static function printPreparationResult(array $order, array $log, int $successfulCopies): array
    {
        $copyNo = (int)$log['copy_no'];
        return [
            'print_log_id' => (int)$log['id'],
            'idempotency_key' => (string)$log['idempotency_key'],
            'order_id' => (int)$order['id'],
            'order_sn' => (string)$order['order_sn'],
            'version' => (int)$log['version'],
            'copy_no' => $copyNo,
            'successful_print_count' => $successfulCopies,
            'reprint_count' => max(0, $copyNo - 1),
            'is_reprint' => $copyNo > 1,
            'status' => (string)$log['status'],
            'print_requested_time' => (int)$log['create_time'],
        ];
    }

    /** @return array<string,mixed>|false */
    public static function printResult(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('settlement.view')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $orderId = self::positiveInteger($params['id'] ?? null);
        $logId = self::positiveInteger($params['print_log_id'] ?? null);
        $success = (int)($params['success'] ?? -1);
        $errorMessage = mb_substr(trim((string)($params['error_message'] ?? '')), 0, 255);
        if ($orderId === false || $logId === false || !in_array($success, [0, 1], true)) {
            self::setError('销售单打印回执参数不正确');
            return false;
        }
        $wanted = $success === 1 ? 'success' : 'failed';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return Db::transaction(static function () use ($orderId, $logId, $success, $errorMessage, $wanted) {
                    $order = Db::name('sales_order')->where('tenant_id', self::tenantId())
                        ->where('id', $orderId)->lock(true)->find();
                    $log = Db::name('customer_sales_print_log')->where('tenant_id', self::tenantId())
                        ->where('id', $logId)->where('order_id', $orderId)->lock(true)->find();
                    if (!$order || !$log || (string)($order['source_type'] ?? '') !== 'customer_report') {
                        self::setError('销售单打印回执无效');
                        return false;
                    }
                    if ((string)$log['status'] !== 'pending') {
                        if ((string)$log['status'] === $wanted) {
                            return self::detailById($orderId);
                        }
                        self::setError('销售单打印回执结果与已保存结果冲突');
                        return false;
                    }
                    $now = time();
                    $updated = Db::name('customer_sales_print_log')->where('tenant_id', self::tenantId())
                        ->where('id', $logId)->where('status', 'pending')->update([
                            'status' => $wanted,
                            'error_message' => $success === 1 ? '' : $errorMessage,
                            'printed_time' => $success === 1 ? $now : 0,
                            'update_time' => $now,
                        ]);
                    if ($updated !== 1) {
                        throw new \RuntimeException('customer_sales_print_log_update_failed');
                    }
                    return self::detailById($orderId);
                });
            } catch (\Throwable $exception) {
                if (self::isLockRetryable($exception) && $attempt < 2) {
                    self::clearError();
                    usleep(20_000 * ($attempt + 1));
                    continue;
                }
                if (!self::hasError()) {
                    self::setError(isset($_SERVER['JXC_PHPUNIT_ENV'])
                        ? '销售单打印回执保存失败：' . $exception->getMessage()
                        : '销售单打印回执保存失败');
                }
                return false;
            }
        }
        self::setError('销售单打印回执保存失败');
        return false;
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int}|false */
    public static function lists(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('settlement.view')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $status = trim((string)($params['status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'pending_weight_review', 'formal', 'all'], true)) {
            self::setError('销售结算状态不正确');
            return false;
        }
        $query = Db::name('sales_order')->where('tenant_id', self::tenantId())
            ->where('source_type', 'customer_report');
        if ($status === 'pending') {
            $query->whereIn('settlement_status', ['pending', 'pending_weight_review']);
        } elseif ($status !== 'all') {
            $query->where('settlement_status', $status);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($nested) use ($keyword) {
                $nested->whereLike('order_sn', '%' . $keyword . '%')
                    ->whereOrLike('customer_name', '%' . $keyword . '%');
            });
        }
        $count = (int)(clone $query)->count();
        $rows = $query->order('id', 'desc')->select()->toArray();
        $identities = self::customerIdentitiesForOrders($rows);
        $lists = array_map(static fn(array $row): array => [
            'order_id' => (int)$row['id'],
            'order_sn' => (string)$row['order_sn'],
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => (string)$row['customer_name'],
            ...($identities[(int)$row['id']] ?? self::customerIdentity(
                (int)$row['customer_id'],
                (string)$row['customer_name'],
                null,
            )),
            'warehouse_id' => (int)$row['warehouse_id'],
            'source_report_id' => (int)($row['source_id'] ?? 0),
            'settlement_status' => (string)($row['settlement_status'] ?? 'formal'),
            'version' => (int)($row['settlement_version'] ?? 0),
            'order_money' => self::money((string)$row['order_money']),
            'profit_status' => (string)($row['profit_status'] ?? 'pending_settlement'),
        ], $rows);
        return ['lists' => $lists, 'count' => $count];
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int}|false */
    public static function weightDifferenceTodos(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('settlement.view')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $status = trim((string)($params['status'] ?? 'open'));
        if (!in_array($status, ['open', 'closed', 'all'], true)) {
            self::setError('计费重量差待办状态不正确');
            return false;
        }
        $query = Db::name('sales_weight_difference_todo')->alias('t')
            ->leftJoin('sales_order o', 'o.id=t.order_id AND o.tenant_id=t.tenant_id')
            ->where('t.tenant_id', self::tenantId());
        if ($status !== 'all') {
            $query->where('t.status', $status);
        }
        $rows = $query->field(['t.*', 'o.order_sn', 'o.customer_id', 'o.customer_name'])->order('t.id', 'desc')->select()->toArray();
        $identities = self::customerIdentitiesForOrders(array_map(static fn(array $row): array => [
            'id' => (int)($row['order_id'] ?? 0),
            'customer_id' => (int)($row['customer_id'] ?? 0),
            'customer_name' => (string)($row['customer_name'] ?? ''),
        ], $rows));
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['order_id'] = (int)$row['order_id'];
            $row['target_version'] = (int)$row['target_version'];
            $row = array_merge($row, $identities[$row['order_id']] ?? []);
            $row['differences'] = json_decode((string)$row['differences_json'], true) ?: [];
            unset($row['differences_json']);
        }
        unset($row);
        return ['lists' => $rows, 'count' => count($rows)];
    }

    /** @return array<string,mixed>|false */
    public static function submit(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('settlement.bill')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $request = self::normalizeSubmitRequest($params);
        if ($request === false) {
            return false;
        }
        if (bccomp($request['rounding_amount'], '0.00', self::MONEY_SCALE) > 0 && !(FinanceIntegration::active() ? FinanceAccess::has('finance.sales.rounding') : self::isHighestAuthority())) {
            self::setError(FinanceIntegration::active() ? '没有销售单独立抹零权限' : '只有最高权限人员可以执行销售单抹零');
            return false;
        }
        $fingerprint = self::fingerprint($request);
        $existing = self::actionByKey($request['idempotency_key']);
        if ($existing) {
            return self::replayAction($existing, $fingerprint);
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = Db::transaction(function () use ($request, $fingerprint) {
                    FinanceIntegration::lock();
                    $order = Db::name('sales_order')->where('tenant_id', self::tenantId())
                        ->where('id', $request['order_id'])->lock(true)->find();
                    if (!$order || (string)($order['source_type'] ?? '') !== 'customer_report') {
                        self::setError('销售单不存在');
                        return false;
                    }
                    $replayed = self::actionByKey($request['idempotency_key']);
                    if ($replayed) {
                        return self::replayAction($replayed, $fingerprint);
                    }
                    $currentVersion = (int)($order['settlement_version'] ?? 0);
                    if ($currentVersion !== $request['expected_version']) {
                        self::setError('销售单版本已变化，请重新加载后再保存');
                        return false;
                    }
                    if (Db::name('sales_weight_difference_todo')->where('tenant_id', self::tenantId())
                        ->where('order_id', (int)$order['id'])->where('status', 'open')->count() > 0) {
                        self::setError('该销售单已有待确认计费重量差，请先处理待办');
                        return false;
                    }
                    $rows = Db::name('order_goods')->where('tenant_id', self::tenantId())
                        ->where('order_id', (int)$order['id'])->where('order_type', 'sales')
                        ->order('id', 'asc')->lock(true)->select()->toArray();
                    $snapshot = self::buildSnapshot($order, $rows, $request);
                    if ($snapshot === false) {
                        return false;
                    }
                    $now = time();
                    $actionId = (int)Db::name('sales_settlement_action')->insertGetId([
                        'tenant_id' => self::tenantId(),
                        'order_id' => (int)$order['id'],
                        'action_type' => 'submit',
                        'idempotency_key' => $request['idempotency_key'],
                        'request_fingerprint' => $fingerprint,
                        'expected_version' => $currentVersion,
                        'target_version' => $currentVersion + 1,
                        'status' => 'processing',
                        'snapshot_json' => self::json($snapshot),
                        'result_json' => null,
                        'operator_id' => self::operatorId(),
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    if ($actionId <= 0) {
                        throw new \RuntimeException('sales_settlement_action_insert_failed');
                    }

                    if ($snapshot['differences'] !== []) {
                        self::markPendingReview($order);
                        $todoId = (int)Db::name('sales_weight_difference_todo')->insertGetId([
                            'tenant_id' => self::tenantId(),
                            'order_id' => (int)$order['id'],
                            'settlement_action_id' => $actionId,
                            'target_version' => $currentVersion + 1,
                            'differences_json' => self::json($snapshot['differences']),
                            'assignee_scope' => 'highest_privilege',
                            'status' => 'open',
                            'decision' => '',
                            'resolution_reason' => '',
                            'reviewer_id' => 0,
                            'resolved_time' => 0,
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                        if ($todoId <= 0) {
                            throw new \RuntimeException('sales_weight_difference_todo_insert_failed');
                        }
                        $result = self::pendingResult($order, $snapshot, $actionId, $todoId);
                        self::finishAction($actionId, 'pending_weight_review', $result);
                        AuditService::logWithinTransaction(
                            AuditService::MODULE_SALES_ORDER,
                            'weight_difference_pending',
                            (int)$order['id'],
                            (string)$order['order_sn'],
                            ['settlement_version' => $currentVersion],
                            ['target_version' => $currentVersion + 1, 'differences' => $snapshot['differences']],
                            '非零计费重量差进入最高权限待办'
                        );
                        return $result;
                    }

                    $result = self::formalize($order, $snapshot, $actionId);
                    self::finishAction($actionId, 'formal', $result);
                    return $result;
                });
                if ($result === false) {
                    $committed = self::replayAfterCommit($request['idempotency_key'], $fingerprint);
                    if ($committed !== false) {
                        self::clearError();
                        return $committed;
                    }
                }
                return $result;
            } catch (\Throwable $exception) {
                if (self::isLockRetryable($exception) && $attempt < 2) {
                    self::clearError();
                    usleep(20_000 * ($attempt + 1));
                    continue;
                }
                $committed = self::replayAfterCommit($request['idempotency_key'], $fingerprint);
                if ($committed !== false) {
                    self::clearError();
                    return $committed;
                }
                if (!self::hasError()) {
                    self::setError($exception instanceof \DomainException ? $exception->getMessage() : (isset($_SERVER['JXC_PHPUNIT_ENV'])
                        ? '销售结算失败，事务已回滚：' . $exception->getMessage()
                        : '销售结算失败，事务已回滚'));
                }
                return false;
            }
        }
        self::setError('销售结算失败，事务已回滚');
        return false;
    }

    /** @return array<string,mixed>|false */
    public static function resolveWeightDifference(array $params): array|false
    {
        self::clearError();
        if (!self::isHighestAuthority()) {
            self::setError('只有最高权限人员可以确认计费重量差');
            return false;
        }
        $todoId = self::positiveInteger($params['id'] ?? null);
        $decision = trim((string)($params['decision'] ?? ''));
        $reason = trim((string)($params['reason'] ?? ''));
        $inventoryReason = trim((string)($params['inventory_exception_reason'] ?? ''));
        $inventoryConfirmed = (int)($params['inventory_second_confirmed'] ?? 0) === 1;
        $overdueAcknowledged = (int)($params['overdue_acknowledged'] ?? 0) === 1;
        $key = trim((string)($params['idempotency_key'] ?? ''));
        if ($todoId === false || !in_array($decision, ['approve', 'reject'], true)
            || $reason === '' || mb_strlen($reason) > 500 || mb_strlen($inventoryReason) > 500
            || $key === '' || strlen($key) > 96) {
            self::setError('计费重量差处理参数不完整');
            return false;
        }
        $canonical = ['todo_id' => $todoId, 'decision' => $decision, 'reason' => $reason,
            'inventory_exception_reason' => $inventoryReason,
            'inventory_second_confirmed' => $inventoryConfirmed];
        if ($overdueAcknowledged) { $canonical['overdue_acknowledged'] = true; }
        $fingerprint = self::fingerprint($canonical);
        $existing = self::actionByKey($key);
        if ($existing) {
            return self::replayAction($existing, $fingerprint);
        }
        $located = Db::name('sales_weight_difference_todo')->where('tenant_id', self::tenantId())
            ->where('id', $todoId)->find();
        if (!$located) {
            self::setError('计费重量差待办不存在');
            return false;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = Db::transaction(function () use ($located, $todoId, $decision, $reason, $inventoryReason, $inventoryConfirmed, $overdueAcknowledged, $key, $fingerprint) {
                    FinanceIntegration::lock();
                    $order = Db::name('sales_order')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$located['order_id'])->lock(true)->find();
                    if (!$order) {
                        self::setError('销售单不存在');
                        return false;
                    }
                    $todo = Db::name('sales_weight_difference_todo')->where('tenant_id', self::tenantId())
                        ->where('id', $todoId)->lock(true)->find();
                    if (!$todo) {
                        self::setError('计费重量差待办不存在');
                        return false;
                    }
                    $replayed = self::actionByKey($key);
                    if ($replayed) {
                        return self::replayAction($replayed, $fingerprint);
                    }
                    if ((string)$todo['status'] !== 'open') {
                        self::setError('计费重量差待办已经处理');
                        return false;
                    }
                    $proposal = Db::name('sales_settlement_action')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$todo['settlement_action_id'])->lock(true)->find();
                    if (!$proposal || (string)$proposal['status'] !== 'pending_weight_review') {
                        self::setError('待确认销售结算不存在');
                        return false;
                    }
                    if ((int)$proposal['expected_version'] !== (int)($order['settlement_version'] ?? 0)) {
                        self::setError('销售单版本已变化，请重新加载后再保存');
                        return false;
                    }
                    $snapshot = json_decode((string)$proposal['snapshot_json'], true);
                    if (!is_array($snapshot)) {
                        throw new \RuntimeException('sales_settlement_snapshot_invalid');
                    }
                    if ($inventoryReason !== '') {
                        $snapshot['inventory_exception_reason'] = $inventoryReason;
                    }
                    if ($inventoryConfirmed) {
                        $snapshot['inventory_second_confirmed'] = true;
                    }
                    // 审批人为真正确认人，不能继承经办人此前的逾期知晓。
                    $snapshot['overdue_acknowledged'] = $overdueAcknowledged;
                    $now = time();
                    $actionId = (int)Db::name('sales_settlement_action')->insertGetId([
                        'tenant_id' => self::tenantId(),
                        'order_id' => (int)$order['id'],
                        'action_type' => 'weight_review',
                        'idempotency_key' => $key,
                        'request_fingerprint' => $fingerprint,
                        'expected_version' => (int)$proposal['expected_version'],
                        'target_version' => (int)$proposal['target_version'],
                        'status' => 'processing',
                        'snapshot_json' => self::json($snapshot),
                        'result_json' => null,
                        'operator_id' => self::operatorId(),
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    if ($actionId <= 0) {
                        throw new \RuntimeException('sales_settlement_review_action_insert_failed');
                    }
                    if ($decision === 'reject') {
                        Db::name('sales_weight_difference_todo')->where('tenant_id', self::tenantId())
                            ->where('id', $todoId)->update([
                                'status' => 'closed', 'decision' => 'reject', 'resolution_reason' => $reason,
                                'reviewer_id' => self::operatorId(), 'resolved_time' => $now, 'update_time' => $now,
                            ]);
                        Db::name('sales_settlement_action')->where('tenant_id', self::tenantId())
                            ->where('id', (int)$proposal['id'])->update(['status' => 'rejected', 'update_time' => $now]);
                        $restoredStatus = (int)($order['settlement_version'] ?? 0) > 0 ? 'formal' : 'pending';
                        if ((int)($order['settlement_version'] ?? 0) === 0) {
                            Db::name('sales_order')->where('tenant_id', self::tenantId())->where('id', (int)$order['id'])
                                ->update(['settlement_status' => $restoredStatus, 'update_time' => $now]);
                        }
                        $result = ['order_id' => (int)$order['id'], 'settlement_status' => $restoredStatus,
                            'version' => (int)($order['settlement_version'] ?? 0),
                            'can_print' => $restoredStatus === 'formal',
                            'decision' => 'reject'];
                        self::finishAction($actionId, 'rejected', $result);
                        return $result;
                    }

                    $result = self::formalize($order, $snapshot, $actionId, $reason);
                    self::finishAction($actionId, 'formal', $result);
                    Db::name('sales_weight_difference_todo')->where('tenant_id', self::tenantId())
                        ->where('id', $todoId)->update([
                            'status' => 'closed', 'decision' => 'approve', 'resolution_reason' => $reason,
                            'reviewer_id' => self::operatorId(), 'resolved_time' => $now, 'update_time' => $now,
                        ]);
                    Db::name('sales_settlement_action')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$proposal['id'])->update([
                            'status' => 'formal', 'result_json' => self::json($result), 'update_time' => $now,
                        ]);
                    AuditService::logWithinTransaction(
                        AuditService::MODULE_SALES_ORDER,
                        'weight_difference_resolved',
                        (int)$order['id'],
                        (string)$order['order_sn'],
                        ['todo_id' => $todoId, 'status' => 'open'],
                        ['todo_id' => $todoId, 'status' => 'closed', 'decision' => 'approve'],
                        $reason
                    );
                    return $result;
                });
                return $result;
            } catch (\Throwable $exception) {
                if (self::isLockRetryable($exception) && $attempt < 2) {
                    usleep(20_000 * ($attempt + 1));
                    continue;
                }
                $committed = self::replayAfterCommit($key, $fingerprint);
                if ($committed !== false) {
                    self::clearError();
                    return $committed;
                }
                if (!self::hasError()) {
                    self::setError($exception instanceof \DomainException ? $exception->getMessage() : (isset($_SERVER['JXC_PHPUNIT_ENV'])
                        ? '计费重量差处理失败，事务已回滚：' . $exception->getMessage()
                        : '计费重量差处理失败，事务已回滚'));
                }
                return false;
            }
        }
        self::setError('计费重量差处理失败，事务已回滚');
        return false;
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $snapshot */
    private static function markPendingReview(array $order): void
    {
        if ((int)($order['settlement_version'] ?? 0) > 0) {
            return;
        }
        $updated = Db::name('sales_order')->where('tenant_id', self::tenantId())->where('id', (int)$order['id'])->update([
            'settlement_status' => 'pending_weight_review',
            'update_time' => time(),
        ]);
        if ($updated === false) {
            throw new \RuntimeException('sales_settlement_draft_update_failed');
        }
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $snapshot @return array<string,mixed> */
    private static function formalize(array $order, array $snapshot, int $actionId, string $reviewReason = ''): array
    {
        $currentVersion = (int)($order['settlement_version'] ?? 0);
        $targetVersion = $currentVersion + 1;
        if (FinanceIntegration::active()) {
            $legacy = FinanceLegacySales::context($order); FinanceLegacySales::assertSnapshot($legacy, $snapshot);
            $snapshot['preserve_delivery_totals'] = $legacy['preserves_legacy_coverage'];
        }
        if (self::applyActualCorrections($order, $snapshot, $actionId)) {
            $order['cost_status'] = 'pending';
        }
        self::applyLines($snapshot['lines'], (bool)($snapshot['preserve_delivery_totals'] ?? false));

        $previousMoney = $currentVersion > 0 ? self::money((string)$order['order_money']) : '0.00';
        $delta = bcsub($snapshot['order_money'], $previousMoney, self::MONEY_SCALE);
        $finance = FinanceIntegration::active() ? FinanceSales::post($order, $snapshot, $actionId, $legacy) : null;
        if ($finance) {
            Db::name('customer')->where('tenant_id', self::tenantId())->where('id', (int)$order['customer_id'])
                ->update(['order_receivable' => $finance['debt_after_order'], 'update_time' => time()]);
        } elseif (bccomp($delta, '0.00', self::MONEY_SCALE) > 0) {
            if (!FinanceService::addReceivableWithinTransaction(
                (int)$order['customer_id'], $delta, (int)$order['id'], 'sales', (string)$order['order_sn'],
                '销售结算V' . $targetVersion . '应收增加'
            )) {
                throw new \RuntimeException('sales_settlement_receivable_add_failed');
            }
        } elseif (bccomp($delta, '0.00', self::MONEY_SCALE) < 0) {
            if (!FinanceService::reduceReceivableWithinTransaction(
                (int)$order['customer_id'], ltrim($delta, '-'), (int)$order['id'], 'sales', (string)$order['order_sn'],
                ReceivableFlow::TYPE_RETURN_REDUCE, '销售结算V' . $targetVersion . '录入更正'
            )) {
                throw new \RuntimeException('sales_settlement_receivable_reduce_failed');
            }
        }
        $debtAfter = Db::name('customer')->where('tenant_id', self::tenantId())
            ->where('id', (int)$order['customer_id'])->value('order_receivable');
        if ($debtAfter === null) {
            throw new \RuntimeException('sales_settlement_customer_missing');
        }
        $debtAfter = self::money((string)$debtAfter);
        $now = time();
        Db::name('customer_sales_preference')->duplicate([
            'show_cumulative_debt' => $snapshot['show_cumulative_debt'],
            'operator_id' => self::operatorId(),
            'update_time' => $now,
        ])->insert([
            'tenant_id' => self::tenantId(),
            'customer_id' => (int)$order['customer_id'],
            'show_cumulative_debt' => $snapshot['show_cumulative_debt'],
            'operator_id' => self::operatorId(),
            'create_time' => $now,
            'update_time' => $now,
        ]);

        $orderPay = self::money((string)($order['order_pay_money'] ?? '0.00'));
        $arrears = bcsub($snapshot['order_money'], $orderPay, self::MONEY_SCALE);
        if (bccomp($arrears, '0.00', self::MONEY_SCALE) < 0) {
            $arrears = '0.00';
        }
        $updated = Db::name('sales_order')->where('tenant_id', self::tenantId())->where('id', (int)$order['id'])->update([
            'goods_amount' => $snapshot['goods_amount'],
            'rounding_amount' => $snapshot['rounding_amount'],
            'rounding_reason' => $snapshot['rounding_reason'],
            'rounding_operator_id' => bccomp($snapshot['rounding_amount'], '0.00', 2) > 0 ? self::operatorId() : 0,
            'rounding_time' => bccomp($snapshot['rounding_amount'], '0.00', 2) > 0 ? $now : 0,
            'order_money' => $snapshot['order_money'],
            'order_arrears_money' => $arrears,
            'debt_after_order' => $debtAfter,
            'show_cumulative_debt' => $snapshot['show_cumulative_debt'],
            'settlement_status' => 'formal',
            'settlement_version' => $targetVersion,
            'settlement_time' => $now,
            'settlement_operator_id' => self::operatorId(),
            'profit_status' => (string)($order['cost_status'] ?? 'confirmed') === 'pending' ? 'cost_pending' : 'accurate',
            'update_time' => $now,
        ]);
        if ($updated === false) {
            throw new \RuntimeException('sales_settlement_order_update_failed');
        }
        $versionSnapshot = $snapshot;
        $versionSnapshot['version'] = $targetVersion;
        $versionSnapshot['debt_after_order'] = $debtAfter;
        $versionSnapshot['settlement_time'] = $now;
        $inserted = Db::name('sales_order_version')->insert([
            'tenant_id' => self::tenantId(),
            'order_id' => (int)$order['id'],
            'version' => $targetVersion,
            'order_money' => $snapshot['order_money'],
            'goods_amount' => $snapshot['goods_amount'],
            'rounding_amount' => $snapshot['rounding_amount'],
            'debt_after_order' => $debtAfter,
            'show_cumulative_debt' => $snapshot['show_cumulative_debt'],
            'snapshot_json' => self::json($versionSnapshot),
            'edit_reason' => $reviewReason !== '' ? $reviewReason : $snapshot['edit_reason'],
            'operator_id' => self::operatorId(),
            'create_time' => $now,
        ]);
        if ($inserted !== 1) {
            throw new \RuntimeException('sales_order_version_insert_failed');
        }
        AuditService::logWithinTransaction(
            AuditService::MODULE_SALES_ORDER,
            $targetVersion === 1 ? 'settlement_v1' : 'settlement_version',
            (int)$order['id'],
            (string)$order['order_sn'],
            ['version' => $currentVersion, 'order_money' => $previousMoney],
            ['version' => $targetVersion, 'order_money' => $snapshot['order_money'], 'debt_after_order' => $debtAfter,
                'action_id' => $actionId],
            $reviewReason !== '' ? $reviewReason : $snapshot['edit_reason']
        );
        return [
            'order_id' => (int)$order['id'],
            'order_sn' => (string)$order['order_sn'],
            'settlement_status' => 'formal',
            'finance' => $finance,
            'version' => $targetVersion,
            'goods_amount' => $snapshot['goods_amount'],
            'rounding_amount' => $snapshot['rounding_amount'],
            'order_money' => $snapshot['order_money'],
            'debt_after_order' => $debtAfter,
            'show_cumulative_debt' => (bool)$snapshot['show_cumulative_debt'],
            'can_print' => true,
            'profit_status' => (string)($order['cost_status'] ?? 'confirmed') === 'pending' ? 'cost_pending' : 'accurate',
        ];
    }

    /**
     * 录入更正只按差量调整物理库存，并追加更正事实；客户结算重量变化不会进入这里。
     * @param array<string,mixed> $order
     * @param array<string,mixed> $snapshot
     */
    private static function applyActualCorrections(array $order, array $snapshot, int $actionId): bool
    {
        $corrections = array_values(array_filter(
            $snapshot['lines'],
            static fn(array $line): bool => bccomp((string)$line['actual_delivery_delta'], '0.0000', self::QUANTITY_SCALE) !== 0
        ));
        if ($corrections === []) {
            return false;
        }
        usort($corrections, static fn(array $left, array $right): int => [
            (int)$left['sku_id'], (int)$left['goods_id'], (int)$left['order_goods_id'],
        ] <=> [
            (int)$right['sku_id'], (int)$right['goods_id'], (int)$right['order_goods_id'],
        ]);
        $negativeRows = [];
        $costPending = false;
        $now = time();
        foreach ($corrections as $line) {
            $delta = (string)$line['actual_delivery_delta'];
            $direction = bccomp($delta, '0.0000', self::QUANTITY_SCALE) > 0 ? 'outbound' : 'inbound';
            $quantity = $direction === 'outbound' ? $delta : ltrim($delta, '-');
            if ($direction === 'outbound') {
                $movement = StockService::outboundDeliveryWithinTransaction(
                    (int)$order['warehouse_id'],
                    (int)$line['goods_id'],
                    (int)$line['sku_id'],
                    $quantity,
                    '0.0000',
                    (int)$order['id'],
                    (string)$order['order_sn'],
                    0
                );
                if ($movement === false) {
                    throw new \RuntimeException('sales_delivery_correction_outbound_failed');
                }
                $negativeQty = self::quantity((string)$movement['negative_qty']);
                if (bccomp($negativeQty, '0.0000', self::QUANTITY_SCALE) > 0) {
                    $unitCost = self::money((string)(Db::name('goods')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$line['goods_id'])->value('cost') ?? '0'));
                    $costStatus = bccomp($unitCost, '0.00', self::MONEY_SCALE) > 0 ? 'confirmed' : 'pending';
                    $attributionId = (int)Db::name('negative_inventory_attribution')->insertGetId([
                        'tenant_id' => self::tenantId(),
                        'delivery_event_id' => 0,
                        'delivery_item_id' => 0,
                        'sales_order_id' => (int)$order['id'],
                        'report_id' => (int)($order['source_id'] ?? 0),
                        'report_item_id' => (int)$line['source_line_id'],
                        'warehouse_id' => (int)$order['warehouse_id'],
                        'goods_id' => (int)$line['goods_id'],
                        'sku_id' => (int)$line['sku_id'],
                        'negative_qty' => $negativeQty,
                        'negative_amount' => bcmul($negativeQty, $unitCost, self::MONEY_SCALE),
                        'remaining_qty' => $negativeQty,
                        'reason' => '销售单实际交付重量录入更正',
                        'threshold_explanation' => $snapshot['inventory_exception_reason'],
                        'threshold_confirmed' => $snapshot['inventory_second_confirmed'] ? 1 : 0,
                        'resolution_status' => 'open',
                        'cost_status' => $costStatus,
                        'operator_id' => self::operatorId(),
                        'occurred_time' => $now,
                        'resolved_time' => 0,
                        'update_time' => $now,
                    ]);
                    if ($attributionId <= 0 || Db::name('negative_inventory_todo')->insert([
                        'tenant_id' => self::tenantId(),
                        'attribution_id' => $attributionId,
                        'status' => 'open',
                        'severity' => 'red',
                        'assignee_scope' => 'highest_privilege',
                        'create_time' => $now,
                        'update_time' => $now,
                    ]) !== 1) {
                        throw new \RuntimeException('sales_delivery_correction_negative_todo_failed');
                    }
                    $negativeRows[] = ['id' => $attributionId, 'qty' => $negativeQty, 'unit_cost' => $unitCost];
                    if ($costStatus === 'pending') {
                        $costPending = true;
                        SalesOrderLogic::markCostPendingWithinTransaction((int)$order['id']);
                    }
                }
            } else {
                $movement = StockService::inboundDeliveryCorrectionWithinTransaction(
                    (int)$order['warehouse_id'],
                    (int)$line['goods_id'],
                    (int)$line['sku_id'],
                    $quantity,
                    (int)$order['id'],
                    (string)$order['order_sn'],
                    (int)$line['source_line_id'],
                    $actionId
                );
                if ($movement === false) {
                    throw new \RuntimeException('sales_delivery_correction_inbound_failed');
                }
            }
            if (Db::name('sales_delivery_correction')->insert([
                'tenant_id' => self::tenantId(),
                'order_id' => (int)$order['id'],
                'order_goods_id' => (int)$line['order_goods_id'],
                'settlement_action_id' => $actionId,
                'before_actual_quantity' => $line['previous_actual_delivery_weight'],
                'after_actual_quantity' => $line['actual_delivery_weight'],
                'delta_quantity' => $delta,
                'direction' => $direction,
                'reason' => $snapshot['edit_reason'],
                'operator_id' => self::operatorId(),
                'create_time' => $now,
            ]) !== 1) {
                throw new \RuntimeException('sales_delivery_correction_insert_failed');
            }
            if ((int)$line['source_line_id'] > 0) {
                Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                    ->where('id', (int)$line['source_line_id'])->update([
                        'fulfilled_base_qty' => $line['actual_delivery_weight'],
                        'update_time' => $now,
                    ]);
            }
        }

        if ($negativeRows !== []) {
            $setting = Db::name('negative_inventory_setting')->where('tenant_id', self::tenantId())->lock(true)->find();
            $quantityThreshold = self::quantity((string)($setting['quantity_threshold'] ?? '999999999.0000'));
            $amountThreshold = self::money((string)($setting['amount_threshold'] ?? '999999999.00'));
            $totalQty = '0.0000';
            $totalAmount = '0.00';
            foreach ($negativeRows as $negative) {
                $remaining = self::quantity((string)Db::name('negative_inventory_attribution')
                    ->where('tenant_id', self::tenantId())->where('id', (int)$negative['id'])->value('remaining_qty'));
                if (bccomp($remaining, '0.0000', self::QUANTITY_SCALE) <= 0) {
                    continue;
                }
                $totalQty = bcadd($totalQty, $remaining, self::QUANTITY_SCALE);
                $totalAmount = bcadd($totalAmount, bcmul($remaining, (string)$negative['unit_cost'], self::MONEY_SCALE), self::MONEY_SCALE);
            }
            if ((bccomp($totalQty, $quantityThreshold, self::QUANTITY_SCALE) > 0
                    || bccomp($totalAmount, $amountThreshold, self::MONEY_SCALE) > 0)
                && ($snapshot['inventory_exception_reason'] === '' || !$snapshot['inventory_second_confirmed'])) {
                self::setError('实际交付重量更正造成的负库存超过阈值，必须填写说明并二次确认');
                throw new \RuntimeException('sales_delivery_correction_threshold_confirmation_required');
            }
        }
        return $costPending;
    }

    /** @param array<int,array<string,mixed>> $lines */
    private static function applyLines(array $lines, bool $preserveDeliveryTotals = false): void
    {
        foreach ($lines as $line) {
            $updated = Db::name('order_goods')->where('tenant_id', self::tenantId())
                ->where('id', (int)$line['order_goods_id'])->update([
                    'number' => $line['pricing_quantity'],
                    ...($preserveDeliveryTotals ? [] : ['base_quantity' => $line['actual_delivery_weight']]),
                    'customer_settlement_quantity' => $line['customer_settlement_weight'],
                    'billing_weight_difference' => $line['billing_weight_difference'],
                    'price' => $line['price'],
                    'amount' => $line['amount'],
                    'pricing_unit_id' => $line['pricing_unit_id'],
                    'pricing_unit_name' => $line['pricing_unit_name'],
                    'price_status' => $line['price_status'],
                    'zero_price_reason' => $line['zero_price_reason'],
                    'per_unit_weight' => $line['per_unit_weight'],
                    'update_time' => time(),
                ]);
            if ($updated === false) {
                throw new \RuntimeException('sales_settlement_line_update_failed');
            }
        }
    }

    /** @param array<string,mixed> $order @param array<int,array<string,mixed>> $rows @param array<string,mixed> $request @return array<string,mixed>|false */
    private static function buildSnapshot(array $order, array $rows, array $request): array|false
    {
        $legacy = FinanceIntegration::active() ? FinanceLegacySales::context($order) : null;
        if ($legacy && $legacy['requires_delivery_batches']) { throw new \DomainException('该订单须按真实交付逐项结算，请进入交付分次结算'); }
        if ($legacy && $legacy['preserves_legacy_coverage']) { $rows = array_values(array_filter($rows, static fn(array $row): bool => isset($legacy['legacy_lines'][(int)$row['id']]))); }
        if ($rows === [] || count($rows) !== count($request['lines'])) {
            self::setError('销售结算商品明细不完整');
            return false;
        }
        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[(int)$row['id']] = $row;
        }
        $lines = [];
        $differences = [];
        $goodsAmount = '0.00';
        $precision = FinanceIntegration::active() ? FinanceSalesPrecision::selection((int)$order['customer_id'], $request) : null;
        $automaticDifference = '0.000000';
        foreach ($request['lines'] as $requested) {
            $id = (int)$requested['order_goods_id'];
            $row = $rowMap[$id] ?? null;
            if (!$row) {
                self::setError('销售结算商品明细不存在');
                return false;
            }
            $price = $requested['price'];
            if ($price === null) {
                self::setError('存在未定价商品，不能生成正式客户销售单');
                return false;
            }
            $priceStatus = bccomp($price, '0.00', self::MONEY_SCALE) === 0 ? 'zero' : 'priced';
            if ($priceStatus === 'zero' && $requested['zero_price_reason'] === '') {
                self::setError('零价商品必须填写零价原因');
                return false;
            }
            $requested = self::resolvePricingUnit($row, $requested);
            if ($requested === false) {
                return false;
            }
            $pricingQuantity = self::pricingQuantity($requested);
            if ($pricingQuantity === false) {
                return false;
            }
            $calculation = $precision ? FinanceSalesPrecision::amount($pricingQuantity, $price, $precision['actual_mode']) : null;
            $amount = $calculation['amount'] ?? bcmul($pricingQuantity, $price, self::MONEY_SCALE);
            if ($calculation) { $automaticDifference = bcadd($automaticDifference, $calculation['automatic_rounding_difference'], 6); }
            $goodsAmount = bcadd($goodsAmount, $amount, self::MONEY_SCALE);
            $beforeActual = self::quantity((string)(($legacy && $legacy['preserves_legacy_coverage']) ? $legacy['legacy_lines'][$id]['actual_delivery_weight'] : $row['base_quantity']));
            $actual = $requested['actual_delivery_weight'] ?? $beforeActual;
            if ((int)($order['settlement_version'] ?? 0) === 0
                && bccomp($actual, $beforeActual, self::QUANTITY_SCALE) !== 0) {
                self::setError('首次结算必须使用已完成交付的实际重量；保存V1后才能录入更正');
                return false;
            }
            $difference = bcsub($requested['customer_settlement_weight'], $actual, self::QUANTITY_SCALE);
            $line = [
                'order_goods_id' => $id,
                'goods_id' => (int)$row['goods_id'],
                'sku_id' => (int)($row['sku_id'] ?? 0),
                'name' => (string)$row['name'],
                'previous_actual_delivery_weight' => $beforeActual,
                'actual_delivery_weight' => $actual,
                'actual_delivery_delta' => bcsub($actual, $beforeActual, self::QUANTITY_SCALE),
                'customer_settlement_weight' => $requested['customer_settlement_weight'],
                'billing_weight_difference' => $difference,
                'pricing_quantity' => $pricingQuantity,
                'pricing_unit_id' => $requested['pricing_unit_id'],
                'pricing_unit_name' => $requested['pricing_unit_name'],
                'per_unit_weight' => $requested['per_unit_weight'],
                'price' => $price,
                'price_status' => $priceStatus,
                'zero_price_reason' => $priceStatus === 'zero' ? $requested['zero_price_reason'] : '',
                'amount' => $amount,
                'source_line_id' => (int)($row['source_line_id'] ?? 0),
                ...($calculation ?? []),
            ];
            $lines[] = $line;
            if (bccomp($difference, '0.0000', self::QUANTITY_SCALE) !== 0) {
                $differences[] = [
                    'order_goods_id' => $id,
                    'goods_id' => (int)$row['goods_id'],
                    'name' => (string)$row['name'],
                    'actual_delivery_weight' => $actual,
                    'customer_settlement_weight' => $requested['customer_settlement_weight'],
                    'billing_weight_difference' => $difference,
                ];
            }
        }
        if ($legacy) { FinanceLegacySales::assertSnapshot($legacy, ['lines' => $lines]); }
        $rounding = $request['rounding_amount'];
        if (bccomp($rounding, '0.00', self::MONEY_SCALE) > 0
            && (bccomp($rounding, $goodsAmount, self::MONEY_SCALE) >= 0)) {
            self::setError('抹零金额必须小于商品合计且不能把正常销售单减至零元');
            return false;
        }
        $threshold = Db::name('sales_settlement_setting')->where('tenant_id', self::tenantId())
            ->value('rounding_confirm_threshold');
        $threshold = $threshold === null ? '999999.99' : self::money((string)$threshold);
        if (!$precision && bccomp($rounding, $threshold, self::MONEY_SCALE) > 0
            && ($request['rounding_reason'] === '' || !$request['second_confirmed'])) {
            self::setError('抹零超过阈值，必须填写原因并二次确认');
            return false;
        }
        $orderMoney = bcsub($goodsAmount, $rounding, self::MONEY_SCALE);
        return [
            'order_id' => (int)$order['id'],
            'customer_id' => (int)$order['customer_id'],
            'warehouse_id' => (int)$order['warehouse_id'],
            'lines' => $lines,
            'differences' => $differences,
            'goods_amount' => $goodsAmount,
            ...($precision ? ['precision' => $precision, 'automatic_rounding_difference' => $automaticDifference] : []),
            'rounding_amount' => $rounding,
            'rounding_reason' => $request['rounding_reason'],
            'order_money' => $orderMoney,
            'show_cumulative_debt' => $request['show_cumulative_debt'],
            'edit_reason' => $request['edit_reason'],
            'inventory_exception_reason' => $request['inventory_exception_reason'],
            'inventory_second_confirmed' => $request['inventory_second_confirmed'],
            'due_date' => $request['due_date'] ?? null,
            'due_terms' => $legacy ? FinanceSalesRules::terms((int)$order['customer_id'], $legacy['business_date'] ?? date('Y-m-d')) : null,
            'due_date_reviewed' => $request['due_date_reviewed'] ?? false,
            'due_override_reason' => $request['due_override_reason'] ?? '',
            'credit_reviewed' => $request['credit_reviewed'] ?? false,
            'overdue_acknowledged' => $request['overdue_acknowledged'] ?? false,
            'opening_link_reviewed' => $request['opening_link_reviewed'] ?? false,
            'opening_source' => $request['opening_source'] ?? '',
            'credit_allocations' => $request['credit_allocations'] ?? [],
        ];
    }

    /** @param array<string,mixed> $requested */
    private static function pricingQuantity(array $requested): string|false
    {
        $weight = $requested['customer_settlement_weight'];
        $unit = mb_strtolower(trim($requested['pricing_unit_name']));
        if (in_array($unit, ['斤', 'jin'], true)) {
            return $weight;
        }
        if (in_array($unit, ['公斤', '千克', 'kg'], true)) {
            return bcdiv($weight, '2.0000', self::QUANTITY_SCALE);
        }
        if (in_array($unit, ['两', 'liang'], true)) {
            return bcmul($weight, '10.0000', self::QUANTITY_SCALE);
        }
        $quantity = $requested['pricing_quantity'];
        $perUnitWeight = $requested['per_unit_weight'];
        if ($quantity === '0.0000' || $perUnitWeight === '0.0000'
            || bccomp(bcmul($quantity, $perUnitWeight, self::QUANTITY_SCALE), $weight, self::QUANTITY_SCALE) !== 0) {
            self::setError('计件与计重换算必须提供已确认的单件重量');
            return false;
        }
        return $quantity;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $requested @return array<string,mixed>|false */
    private static function resolvePricingUnit(array $row, array $requested): array|false
    {
        $binding = Db::name('goods_units_binding')->alias('b')
            ->join('goods_unit u', 'u.id=b.unit_id AND u.tenant_id=b.tenant_id')
            ->where('b.tenant_id', self::tenantId())
            ->where('b.goods_id', (int)$row['goods_id'])
            ->where('b.unit_id', (int)$requested['pricing_unit_id'])
            ->where('b.status', 1)
            ->where('u.status', 1)
            ->field(['b.unit_id', 'u.name AS unit_name'])
            ->find();
        if (!$binding || trim((string)$binding['unit_name']) === '') {
            self::setError('计价单位未绑定到当前商品或已经停用');
            return false;
        }
        $requested['pricing_unit_id'] = (int)$binding['unit_id'];
        $requested['pricing_unit_name'] = trim((string)$binding['unit_name']);
        return $requested;
    }

    /** @return array<string,mixed>|false */
    private static function normalizeSubmitRequest(array $params): array|false
    {
        if (isset($params['precision_rules']) && (!is_array($params['precision_rules']) || array_is_list($params['precision_rules']) && $params['precision_rules'] !== [])) { self::setError('金额精度规则版本格式无效'); return false; }
        try { $dueDate = FinanceValue::date($params['due_date'] ?? null, true); }
        catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
        $orderId = self::positiveInteger($params['order_id'] ?? null);
        $expectedVersion = self::nonNegativeInteger($params['expected_version'] ?? null);
        $key = trim((string)($params['idempotency_key'] ?? ''));
        $rounding = self::inputMoney($params['rounding_amount'] ?? '0');
        $roundingReason = trim((string)($params['rounding_reason'] ?? ''));
        $editReason = trim((string)($params['edit_reason'] ?? ''));
        if ($orderId === false || $expectedVersion === false || $key === '' || strlen($key) > 96
            || $rounding === false || mb_strlen($roundingReason) > 500 || mb_strlen($editReason) > 500) {
            self::setError('销售结算参数不完整');
            return false;
        }
        if ($expectedVersion > 0 && $editReason === '') {
            self::setError('更正已保存销售单必须填写原因');
            return false;
        }
        $rawLines = $params['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            self::setError('销售结算必须提交商品明细');
            return false;
        }
        $lines = [];
        $seen = [];
        foreach ($rawLines as $raw) {
            if (!is_array($raw)) {
                self::setError('销售结算商品明细格式不正确');
                return false;
            }
            $id = self::positiveInteger($raw['order_goods_id'] ?? null);
            $weight = self::inputQuantity($raw['customer_settlement_weight'] ?? null, true);
            $pricingUnitId = self::positiveInteger($raw['pricing_unit_id'] ?? null);
            $price = array_key_exists('price', $raw) && $raw['price'] !== null && trim((string)$raw['price']) !== ''
                ? self::inputMoney($raw['price']) : null;
            $zeroReason = trim((string)($raw['zero_price_reason'] ?? ''));
            $pricingQuantity = self::inputQuantity($raw['pricing_quantity'] ?? '0', false);
            $perUnitWeight = self::inputQuantity($raw['per_unit_weight'] ?? '0', false);
            $actualDeliveryWeight = array_key_exists('actual_delivery_weight', $raw)
                && trim((string)$raw['actual_delivery_weight']) !== ''
                ? self::inputQuantity($raw['actual_delivery_weight'], true) : null;
            if ($id === false || $weight === false || $pricingUnitId === false
                || ($price === false) || mb_strlen($zeroReason) > 500
                || $pricingQuantity === false || $perUnitWeight === false || $actualDeliveryWeight === false
                || isset($seen[$id])) {
                self::setError('销售结算商品明细参数不正确');
                return false;
            }
            $seen[$id] = true;
            $lines[] = [
                'order_goods_id' => $id,
                'customer_settlement_weight' => $weight,
                'pricing_unit_id' => $pricingUnitId,
                'pricing_unit_name' => '',
                'pricing_quantity' => $pricingQuantity,
                'per_unit_weight' => $perUnitWeight,
                'price' => $price,
                'zero_price_reason' => $zeroReason,
                'actual_delivery_weight' => $actualDeliveryWeight,
            ];
        }
        usort($lines, fn(array $left, array $right): int => $left['order_goods_id'] <=> $right['order_goods_id']);
        $inventoryExceptionReason = trim((string)($params['inventory_exception_reason'] ?? ''));
        if (mb_strlen($inventoryExceptionReason) > 500) {
            self::setError('负库存二次确认说明过长');
            return false;
        }
        return [
            'order_id' => $orderId,
            'expected_version' => $expectedVersion,
            'idempotency_key' => $key,
            'rounding_amount' => $rounding,
            'rounding_reason' => $roundingReason,
            'second_confirmed' => (int)($params['second_confirmed'] ?? 0) === 1,
            'show_cumulative_debt' => (int)($params['show_cumulative_debt'] ?? 0) === 1 ? 1 : 0,
            'edit_reason' => $editReason,
            'inventory_exception_reason' => $inventoryExceptionReason,
            'inventory_second_confirmed' => (int)($params['inventory_second_confirmed'] ?? 0) === 1,
            ...array_filter([
            'due_date' => $dueDate,
            'due_date_reviewed' => (int)($params['due_date_reviewed'] ?? 0) === 1,
            'due_override_reason' => $params['due_override_reason'] ?? '',
            'credit_reviewed' => (int)($params['credit_reviewed'] ?? 0) === 1,
            'overdue_acknowledged' => (int)($params['overdue_acknowledged'] ?? 0) === 1,
            'opening_link_reviewed' => (int)($params['opening_link_reviewed'] ?? 0) === 1,
            'opening_source' => is_string($params['opening_source'] ?? '') ? ($params['opening_source'] ?? '') : '',
            'credit_allocations' => $params['credit_allocations'] ?? [],
            'precision_mode' => $params['precision_mode'] ?? null,
            'precision_rules' => $params['precision_rules'] ?? [],
            'precision_override_reason' => $params['precision_override_reason'] ?? '',
            ], static fn($value): bool => $value !== null && $value !== '' && $value !== false && $value !== []),
            'lines' => $lines,
        ];
    }

    /** @return array<string,mixed>|false */
    private static function detailById(int $orderId): array|false
    {
        $order = Db::name('sales_order')->where('tenant_id', self::tenantId())->where('id', $orderId)->find();
        if (!$order || (string)($order['source_type'] ?? '') !== 'customer_report') {
            self::setError('销售单不存在');
            return false;
        }
        $lines = Db::name('order_goods')->where('tenant_id', self::tenantId())->where('order_id', $orderId)
            ->where('order_type', 'sales')->order('id', 'asc')->select()->toArray();
        $legacy = FinanceIntegration::active() ? FinanceLegacySales::context($order) : null;
        if ($legacy && $legacy['preserves_legacy_coverage']) {
            $lines = array_values(array_filter($lines, static fn(array $line): bool => isset($legacy['legacy_lines'][(int)$line['id']])));
            foreach ($lines as &$line) { $line['total_actual_delivery_weight'] = $line['base_quantity']; $line['base_quantity'] = $legacy['legacy_lines'][(int)$line['id']]['actual_delivery_weight']; } unset($line);
        }
        foreach ($lines as &$line) {
            $line['id'] = (int)$line['id'];
            $line['actual_delivery_weight'] = self::quantity((string)$line['base_quantity']);
            $line['customer_settlement_weight'] = self::quantity((string)($line['customer_settlement_quantity'] ?? '0'));
            $line['billing_weight_difference'] = self::quantity((string)($line['billing_weight_difference'] ?? '0'));
            $line['price'] = self::money((string)$line['price']);
            $line['amount'] = self::money((string)$line['amount']);
        }
        unset($line);
        $mainCustomerId = (int)$order['customer_id'];
        $mainCustomerName = (string)$order['customer_name'];
        $sourceLineIds = array_values(array_unique(array_filter(array_map(
            static fn(array $line): int => (string)($line['source_line_type'] ?? '') === 'customer_report_item'
                ? (int)($line['source_line_id'] ?? 0)
                : 0,
            $lines
        ))));
        $reportItems = self::firstValidCustomerReportItemsForOrderSources([
            $orderId => $sourceLineIds,
        ]);
        $customerIdentity = self::customerIdentity(
            $mainCustomerId,
            $mainCustomerName,
            $reportItems[$orderId] ?? null,
        );
        $pendingProposal = null;
        $pendingAction = Db::name('sales_settlement_action')->where('tenant_id', self::tenantId())
            ->where('order_id', $orderId)->where('status', 'pending_weight_review')->order('id', 'desc')->find();
        if ($pendingAction) {
            $pendingProposal = json_decode((string)$pendingAction['snapshot_json'], true);
        }
        $showDebt = (int)($order['show_cumulative_debt'] ?? 0);
        if ((int)($order['settlement_version'] ?? 0) === 0) {
            $preference = Db::name('customer_sales_preference')->where('tenant_id', self::tenantId())
                ->where('customer_id', (int)$order['customer_id'])->value('show_cumulative_debt');
            $showDebt = $preference === null ? 0 : (int)$preference;
        }
        $successfulPrintCount = (int)Db::name('customer_sales_print_log')
            ->where('tenant_id', self::tenantId())->where('order_id', $orderId)
            ->where('status', 'success')->count();
        return [
            'order_id' => (int)$order['id'],
            'order_sn' => (string)$order['order_sn'],
            'customer_id' => (int)$order['customer_id'],
            'customer_name' => (string)$order['customer_name'],
            'finance' => FinanceCustomers::salesContext($order),
            ...$customerIdentity,
            'warehouse_id' => (int)$order['warehouse_id'],
            'settlement_status' => (string)($order['settlement_status'] ?? 'formal'),
            'version' => (int)($order['settlement_version'] ?? 0),
            'goods_amount' => self::money((string)($order['goods_amount'] ?? '0')),
            'rounding_amount' => self::money((string)($order['rounding_amount'] ?? '0')),
            'order_money' => self::money((string)$order['order_money']),
            'debt_after_order' => self::money((string)($order['debt_after_order'] ?? '0')),
            'show_cumulative_debt' => $showDebt === 1,
            'successful_print_count' => $successfulPrintCount,
            'reprint_count' => max(0, $successfulPrintCount - 1),
            'can_print' => (string)($order['settlement_status'] ?? '') === 'formal'
                && (int)($order['settlement_version'] ?? 0) > 0,
            'profit_status' => (string)($order['profit_status'] ?? 'pending_settlement'),
            'pending_proposal' => is_array($pendingProposal) ? $pendingProposal : null,
            'lines' => $lines,
        ];
    }

    /** @param array<int,array<string,mixed>> $orders @return array<int,array<string,int|string>> */
    private static function customerIdentitiesForOrders(array $orders): array
    {
        $identities = [];
        $orderIds = [];
        foreach ($orders as $order) {
            $orderId = (int)($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $orderIds[] = $orderId;
            $identities[$orderId] = self::customerIdentity(
                (int)($order['customer_id'] ?? 0),
                (string)($order['customer_name'] ?? ''),
                null,
            );
        }
        if ($orderIds === []) {
            return $identities;
        }
        $sourceRows = Db::name('order_goods')->where('tenant_id', self::tenantId())
            ->whereIn('order_id', $orderIds)->where('order_type', 'sales')
            ->where('source_line_type', 'customer_report_item')
            ->field(['order_id', 'source_line_id'])->select()->toArray();
        $sourceItemIdsByOrder = [];
        foreach ($sourceRows as $sourceRow) {
            $orderId = (int)($sourceRow['order_id'] ?? 0);
            $sourceItemId = (int)($sourceRow['source_line_id'] ?? 0);
            if ($orderId > 0 && $sourceItemId > 0) {
                $sourceItemIdsByOrder[$orderId][] = $sourceItemId;
            }
        }
        if ($sourceItemIdsByOrder === []) {
            return $identities;
        }
        $reportItems = self::firstValidCustomerReportItemsForOrderSources($sourceItemIdsByOrder);
        foreach ($reportItems as $orderId => $reportItem) {
            $identity = $identities[$orderId] ?? null;
            if ($identity === null) {
                continue;
            }
            $identities[$orderId] = self::customerIdentity(
                (int)$identity['main_customer_id'],
                (string)$identity['main_customer_name'],
                $reportItem,
            );
        }
        return $identities;
    }

    /**
     * Use the smallest valid customer_report_item ID for each sales order everywhere
     * customer identity is shown. This remains stable even when order_goods insertion
     * order differs from report item ID order or an earlier source item is soft-deleted.
     *
     * @param array<int,array<int,int>> $sourceItemIdsByOrder
     * @return array<int,array<string,mixed>>
     */
    private static function firstValidCustomerReportItemsForOrderSources(array $sourceItemIdsByOrder): array
    {
        $orderIdsBySourceItem = [];
        foreach ($sourceItemIdsByOrder as $orderId => $sourceItemIds) {
            foreach (array_unique($sourceItemIds) as $sourceItemId) {
                $sourceItemId = (int)$sourceItemId;
                if ($sourceItemId > 0) {
                    $orderIdsBySourceItem[$sourceItemId][] = (int)$orderId;
                }
            }
        }
        if ($orderIdsBySourceItem === []) {
            return [];
        }
        $reportItems = Db::name('customer_report_item')->where('tenant_id', self::tenantId())
            ->whereIn('id', array_keys($orderIdsBySourceItem))->whereNull('delete_time')
            ->field(['id', 'delivery_customer_id', 'delivery_customer_name'])
            ->order('id', 'asc')->select()->toArray();
        $firstByOrder = [];
        foreach ($reportItems as $reportItem) {
            foreach ($orderIdsBySourceItem[(int)$reportItem['id']] ?? [] as $orderId) {
                if (!isset($firstByOrder[$orderId])) {
                    $firstByOrder[$orderId] = $reportItem;
                }
            }
        }
        return $firstByOrder;
    }

    /** @param array<string,mixed>|null $reportItem @return array<string,int|string> */
    private static function customerIdentity(int $mainCustomerId, string $mainCustomerName, ?array $reportItem): array
    {
        $deliveryCustomerId = $mainCustomerId;
        $deliveryCustomerName = $mainCustomerName;
        $snapshotDeliveryCustomerId = (int)($reportItem['delivery_customer_id'] ?? 0);
        $snapshotDeliveryCustomerName = trim((string)($reportItem['delivery_customer_name'] ?? ''));
        if ($snapshotDeliveryCustomerId > 0 && $snapshotDeliveryCustomerName !== '') {
            $deliveryCustomerId = $snapshotDeliveryCustomerId;
            $deliveryCustomerName = $snapshotDeliveryCustomerName;
        }
        return [
            'main_customer_id' => $mainCustomerId,
            'main_customer_name' => $mainCustomerName,
            'delivery_customer_id' => $deliveryCustomerId,
            'delivery_customer_name' => $deliveryCustomerName,
            'sub_customer_name' => $deliveryCustomerId !== $mainCustomerId ? $deliveryCustomerName : '',
        ];
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $snapshot @return array<string,mixed> */
    private static function pendingResult(array $order, array $snapshot, int $actionId, int $todoId): array
    {
        return [
            'order_id' => (int)$order['id'],
            'order_sn' => (string)$order['order_sn'],
            'settlement_status' => 'pending_weight_review',
            'version' => (int)($order['settlement_version'] ?? 0),
            'target_version' => (int)($order['settlement_version'] ?? 0) + 1,
            'goods_amount' => $snapshot['goods_amount'],
            'rounding_amount' => $snapshot['rounding_amount'],
            'order_money' => $snapshot['order_money'],
            'action_id' => $actionId,
            'todo_id' => $todoId,
            'can_print' => (int)($order['settlement_version'] ?? 0) > 0,
        ];
    }

    private static function finishAction(int $actionId, string $status, array $result): void
    {
        $updated = Db::name('sales_settlement_action')->where('tenant_id', self::tenantId())
            ->where('id', $actionId)->update([
                'status' => $status,
                'result_json' => self::json($result),
                'update_time' => time(),
            ]);
        if ($updated === false) {
            throw new \RuntimeException('sales_settlement_action_update_failed');
        }
    }

    /** @param array<string,mixed> $action @return array<string,mixed>|false */
    private static function replayAction(array $action, string $fingerprint): array|false
    {
        $snapshot = json_decode((string)($action['snapshot_json'] ?? '{}'), true);
        if (!empty($snapshot['precision']['overridden']) && !FinanceAccess::has('finance.sales.precision_override')) { self::setError('没有本笔销售金额精度覆盖权限'); return false; }
        if (!hash_equals((string)$action['request_fingerprint'], $fingerprint)) {
            self::setError('同一幂等键不能提交不同的销售结算事实');
            return false;
        }
        $result = json_decode((string)($action['result_json'] ?? ''), true);
        if (!is_array($result)) {
            self::setError('销售结算仍在处理中，请稍后重试');
            return false;
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    private static function actionByKey(string $key): ?array
    {
        $row = Db::name('sales_settlement_action')->where('tenant_id', self::tenantId())
            ->where('idempotency_key', $key)->find();
        return $row ?: null;
    }

    /** @return array<string,mixed>|false */
    private static function replayAfterCommit(string $key, string $fingerprint): array|false
    {
        $action = self::actionByKey($key);
        return $action ? self::replayAction($action, $fingerprint) : false;
    }

    private static function isHighestAuthority(): bool
    {
        return WorkforceLogic::currentPermissions()['is_admin'];
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256', self::json($value));
    }

    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $json;
    }

    private static function inputMoney(mixed $value): string|false
    {
        $text = trim((string)$value);
        if ($text === '' || strlen($text) > 20 || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $text)) {
            return false;
        }
        return self::money($text);
    }

    private static function inputQuantity(mixed $value, bool $positive): string|false
    {
        $text = trim((string)$value);
        if ($text === '' || strlen($text) > 24 || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $text)) {
            return false;
        }
        $normalized = self::quantity($text);
        if ($positive && bccomp($normalized, '0.0000', self::QUANTITY_SCALE) <= 0) {
            return false;
        }
        return $normalized;
    }

    private static function positiveInteger(mixed $value): int|false
    {
        return self::inputInteger($value, 1);
    }

    private static function nonNegativeInteger(mixed $value): int|false
    {
        return self::inputInteger($value, 0);
    }

    private static function inputInteger(mixed $value, int $minimum): int|false
    {
        if (is_int($value)) {
            return $value >= $minimum ? $value : false;
        }
        if (!is_string($value) || !preg_match($minimum > 0 ? '/^[1-9]\d*$/' : '/^(?:0|[1-9]\d*)$/', $value)) {
            return false;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => 4_294_967_295]]);
        return $validated === false ? false : (int)$validated;
    }

    private static function money(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::MONEY_SCALE);
    }

    private static function quantity(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::QUANTITY_SCALE);
    }

    private static function isLockRetryable(\Throwable $exception): bool
    {
        return in_array((int)$exception->getCode(), [1205, 1213], true)
            || str_contains($exception->getMessage(), '1205')
            || str_contains($exception->getMessage(), '1213');
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
