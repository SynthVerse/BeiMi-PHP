<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 真负库存最高权限待办与追加式处理动作。 */
final class NegativeInventoryLogic extends BaseLogic
{
    private const SCALE = 4;

    /** 已确认实物退离新增的负现存量，保留单据与明细引用并进入既有最高权限待办。 */
    public static function purchaseReturnWithinTransaction(int $warehouse, int $goods, int $sku, array $movement, int $document, int $line, string $date): int
    {
        return self::registerPhysicalShortage($warehouse, $goods, $sku, $movement, $date,
            '采购实际退货 FIN-RET-' . $document . ' / 明细 #' . $line, '实际退离已确认，库存来源需核实');
    }

    public static function inventoryCountWithinTransaction(int $warehouse, int $goods, int $sku, array $movement, int $document, string $date): int
    {
        return self::registerPhysicalShortage($warehouse, $goods, $sku, $movement, $date,
            '盘点截止差额 FIN-COUNT-' . $document . ' / SKU #' . $sku, '盘点差额已确认，后续出入库与负现存来源需核实');
    }

    private static function registerPhysicalShortage(int $warehouse, int $goods, int $sku, array $movement, string $date, string $reason, string $explanation): int
    {
        $before = bccomp($movement['before_on_hand_qty'], '0', 4) < 0 ? ltrim($movement['before_on_hand_qty'], '-') : '0';
        $after = bccomp($movement['after_on_hand_qty'], '0', 4) < 0 ? ltrim($movement['after_on_hand_qty'], '-') : '0';
        $quantity = bcsub($after, $before, 4);
        if (bccomp($quantity, '0', 4) <= 0) { return 0; }
        $now = time();
        $id = (int)Db::name('negative_inventory_attribution')->insertGetId(['tenant_id' => self::tenantId(),
            'delivery_item_id' => null, 'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku,
            'negative_qty' => $quantity, 'remaining_qty' => $quantity, 'negative_amount' => '0.00', 'cost_status' => 'pending',
            'reason' => $reason,
            'threshold_explanation' => $explanation, 'threshold_confirmed' => 1,
            'resolution_status' => 'open', 'operator_id' => self::operatorId(), 'occurred_time' => strtotime($date), 'update_time' => $now]);
        if ($id <= 0 || Db::name('negative_inventory_todo')->insert(['tenant_id' => self::tenantId(), 'attribution_id' => $id,
            'status' => 'open', 'severity' => 'red', 'assignee_scope' => 'highest_privilege', 'create_time' => $now, 'update_time' => $now]) !== 1) {
            throw new \DomainException('实物变化未能建立负库存来源待办');
        }
        return $id;
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int}|false */
    public static function todos(array $params = []): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('inventory.negative.manage')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $status = trim((string)($params['status'] ?? 'open'));
        $query = Db::name('negative_inventory_todo')->alias('todo')
            ->join('negative_inventory_attribution source', 'source.id=todo.attribution_id AND source.tenant_id=todo.tenant_id')
            ->leftJoin('sales_order sales', 'sales.id=source.sales_order_id AND sales.tenant_id=source.tenant_id')
            ->where('todo.tenant_id', self::tenantId());
        $attributionId = (int)($params['attribution_id'] ?? 0);
        if ($attributionId > 0) {
            $query->where('todo.attribution_id', $attributionId);
        }
        if (in_array($status, ['open', 'closed'], true)) {
            $query->where('todo.status', $status);
        }
        $rows = $query->field('todo.id AS todo_id,todo.status AS todo_status,todo.severity,todo.assignee_scope,'
            . "source.id AS attribution_id,source.*,IFNULL(sales.order_sn, '') AS order_sn")
            ->order('todo.id desc')->select()->toArray();
        return ['lists' => $rows, 'count' => count($rows)];
    }

    /** @return array<string,mixed>|false */
    public static function resolve(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('inventory.negative.manage')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $action = trim((string)($params['action'] ?? ''));
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        $quantity = self::nonNegative((string)($params['quantity'] ?? '0'));
        $amount = self::money((string)($params['amount'] ?? '0'));
        $reason = mb_substr(trim((string)($params['reason'] ?? '')), 0, 500);
        $costStatus = trim((string)($params['cost_status'] ?? 'confirmed'));
        if ($id <= 0 || $idempotencyKey === '' || mb_strlen($idempotencyKey) > 96
            || !in_array($action, ['wait_inbound', 'record_missing_inbound', 'inventory_writeoff', 'retain'], true)
            || $quantity === false || $amount === false || !in_array($costStatus, ['confirmed', 'pending'], true)) {
            self::setError('负库存处理参数不完整');
            return false;
        }
        if ($reason === '') {
            self::setError('负库存处理必须填写原因');
            return false;
        }
        if (in_array($action, ['record_missing_inbound', 'inventory_writeoff'], true)
            && bccomp($quantity, '0.0000', self::SCALE) <= 0) {
            self::setError('负库存补录或核销数量必须大于 0');
            return false;
        }
        if ($action === 'record_missing_inbound' && bccomp($amount, '0.00', 2) <= 0) {
            self::setError('补录遗漏入库必须记录采购金额');
            return false;
        }
        if ($action === 'record_missing_inbound') {
            $costStatus = 'confirmed';
        }
        $fingerprint = hash('sha256', json_encode([
            'id' => $id,
            'action' => $action,
            'quantity' => $quantity,
            'amount' => $amount,
            'reason' => $reason,
            'cost_status' => $costStatus,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = Db::transaction(static function () use (
                $id,
                $action,
                $idempotencyKey,
                $quantity,
                $amount,
                $reason,
                $costStatus,
                $fingerprint
            ) {
                FinanceIntegration::lock();
                $existing = Db::name('negative_inventory_action')->where('tenant_id', self::tenantId())
                    ->where('idempotency_key', $idempotencyKey)->find();
                if ($existing) {
                    if (!hash_equals((string)$existing['request_fingerprint'], $fingerprint)) {
                        self::setError('同一幂等键不能提交不同的负库存处理事实');
                        return false;
                    }
                    return self::detailWithinTransaction((int)$existing['attribution_id']);
                }
                $sourceRef = Db::name('negative_inventory_attribution')->where('tenant_id', self::tenantId())
                    ->where('id', $id)->field('id,warehouse_id,sku_id')->find();
                if (!$sourceRef) {
                    self::setError('负库存来源不存在');
                    return false;
                }
                if (!WarehouseSkuBalanceService::lockBalanceWithinTransaction(
                    (int)$sourceRef['warehouse_id'],
                    (int)$sourceRef['sku_id']
                )) {
                    self::setError('负库存来源的仓库 SKU 余额不存在');
                    throw new \RuntimeException('negative_inventory_balance_lock_failed');
                }
                $source = Db::name('negative_inventory_attribution')->where('tenant_id', self::tenantId())
                    ->where('id', $id)->lock(true)->find();
                if (!$source) {
                    self::setError('负库存来源不存在');
                    return false;
                }
                $replayed = Db::name('negative_inventory_action')->where('tenant_id', self::tenantId())
                    ->where('idempotency_key', $idempotencyKey)->lock(true)->find();
                if ($replayed) {
                    if (!hash_equals((string)$replayed['request_fingerprint'], $fingerprint)) {
                        self::setError('同一幂等键不能提交不同的负库存处理事实');
                        return false;
                    }
                    return self::detailWithinTransaction((int)$replayed['attribution_id']);
                }
                if ((string)$source['resolution_status'] === 'resolved') {
                    self::setError('负库存已经处理完成，不能追加不同处理');
                    return false;
                }
                $remaining = self::decimal((string)$source['remaining_qty']);
                if (in_array($action, ['record_missing_inbound', 'inventory_writeoff'], true)
                    && bccomp($quantity, $remaining, self::SCALE) > 0) {
                    self::setError('处理数量不能超过该来源剩余负库存');
                    return false;
                }
                $before = $source;
                $now = time();
                $afterRemaining = $remaining;
                $nextStatus = (string)$source['resolution_status'];
                $nextCostStatus = (string)$source['cost_status'];
                if ($action === 'wait_inbound') {
                    $nextStatus = 'waiting_inbound';
                } elseif ($action === 'retain') {
                    $nextStatus = 'retained';
                } else {
                    $movement = StockService::adjustNegativeWithinTransaction(
                        (int)$source['warehouse_id'],
                        (int)$source['goods_id'],
                        (int)$source['sku_id'],
                        $quantity,
                        $id,
                        $action,
                        $reason,
                        $costStatus === 'confirmed' ? $amount : null
                    );
                    if ($movement === false) {
                        throw new \RuntimeException('negative_inventory_adjustment_failed');
                    }
                    $afterRemaining = bcsub($remaining, $quantity, self::SCALE);
                    $nextStatus = bccomp($afterRemaining, '0.0000', self::SCALE) === 0 ? 'resolved' : (string)$source['resolution_status'];
                    $nextCostStatus = $costStatus;
                }
                Db::name('negative_inventory_attribution')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                    'remaining_qty' => $afterRemaining,
                    'resolution_status' => $nextStatus,
                    'cost_status' => $nextCostStatus,
                    'resolved_time' => $nextStatus === 'resolved' ? $now : 0,
                    'update_time' => $now,
                ]);
                if ($nextCostStatus === 'pending' && (int)$source['sales_order_id'] > 0) {
                    SalesOrderLogic::markCostPendingWithinTransaction((int)$source['sales_order_id']);
                }
                self::updateTodoWithinTransaction($id, $nextStatus === 'resolved', $now);
                $after = $before;
                $after['remaining_qty'] = $afterRemaining;
                $after['resolution_status'] = $nextStatus;
                $after['cost_status'] = $nextCostStatus;
                $actionId = (int)Db::name('negative_inventory_action')->insertGetId([
                    'tenant_id' => self::tenantId(),
                    'attribution_id' => $id,
                    'action_type' => $action,
                    'quantity' => $quantity,
                    'amount' => $amount,
                    'reason' => $reason,
                    'cost_status' => $nextCostStatus,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'operator_id' => self::operatorId(),
                    'action_time' => $now,
                    'before_snapshot' => json_encode($before, JSON_UNESCAPED_UNICODE),
                    'after_snapshot' => json_encode($after, JSON_UNESCAPED_UNICODE),
                ]);
                if ($actionId <= 0) {
                    throw new \RuntimeException('negative_inventory_action_insert_failed');
                }
                AuditService::logWithinTransaction(
                    'negative_inventory',
                    $action,
                    $id,
                    'NEG-' . $id,
                    $before,
                    $after,
                    $reason
                );
                return self::detailWithinTransaction($id);
                });
                $committedReplay = self::replayAfterConcurrentCommit($idempotencyKey, $fingerprint);
                if ($committedReplay !== false) {
                    self::clearError();
                    return $committedReplay;
                }
                if ($result !== false) {
                    self::setError('负库存处理没有形成可重放的追加动作');
                }
                return false;
            } catch (\Throwable $exception) {
                if (self::isLockRetryable($exception) && $attempt < 2) {
                    self::clearError();
                    usleep(20_000 * ($attempt + 1));
                    continue;
                }
                $committedReplay = self::replayAfterConcurrentCommit($idempotencyKey, $fingerprint);
                if ($committedReplay !== false) {
                    self::clearError();
                    return $committedReplay;
                }
                if (!self::hasError()) {
                    self::setError('负库存处理失败');
                }
                return false;
            }
        }
        self::setError('负库存处理失败');
        return false;
    }

    /**
     * 普通入库事务完成余额增加后，按来源发生顺序补平选择“等待入库”的负库存。
     * 这里不再改库存，只追加来源分摊动作。
     *
     * @param array<string,mixed> $movement
     */
    public static function autoOffsetWithinTransaction(
        int $warehouseId,
        int $skuId,
        array $movement,
        int $orderId,
        string $orderType,
        string $orderSn
    ): void {
        self::allocateHealedAvailabilityWithinTransaction(
            $warehouseId,
            $skuId,
            $movement,
            $orderId,
            $orderType,
            $orderSn,
            ['waiting_inbound'],
            'auto_offset',
            '后续入库自动补平'
        );
    }

    /**
     * 另一订单少交释放预留时，可用量回升本身就是可审计的后续业务动作。
     * 它按来源发生顺序核减仍未解决的归因，不覆盖原始 negative_qty。
     *
     * @param array<string,mixed> $movement
     */
    public static function autoOffsetDeliveryReleaseWithinTransaction(
        int $warehouseId,
        int $skuId,
        array $movement,
        int $salesOrderId,
        string $salesOrderSn,
        int $deliveryEventId
    ): void {
        self::allocateHealedAvailabilityWithinTransaction(
            $warehouseId,
            $skuId,
            $movement,
            $salesOrderId,
            'sales_delivery_release',
            $salesOrderSn . ':event:' . $deliveryEventId,
            ['open', 'waiting_inbound', 'retained'],
            'reservation_release_offset',
            '交付释放预留自动补平'
        );
    }

    /**
     * 实际交付重量减少时，先冲销本销售单同一来源明细的负库存，再按既有 FIFO 处理剩余量。
     * 余额锁已经由调用方持有；来源仍按全局 FIFO 顺序加锁，排序只影响锁后的分摊优先级。
     * @param array<string,mixed> $movement
     */
    public static function autoOffsetDeliveryCorrectionWithinTransaction(
        int $warehouseId,
        int $skuId,
        array $movement,
        int $salesOrderId,
        string $salesOrderSn,
        int $sourceLineId,
        int $settlementActionId
    ): void {
        self::allocateHealedAvailabilityWithinTransaction(
            $warehouseId,
            $skuId,
            $movement,
            $salesOrderId,
            'sales_delivery_correction',
            $salesOrderSn,
            ['open', 'waiting_inbound', 'retained'],
            'sales_delivery_correction_offset',
            '实际交付重量更正定向补平',
            $salesOrderId,
            $sourceLineId,
            $settlementActionId
        );
    }

    public static function autoOffsetPurchaseReturnWithinTransaction(int $warehouse, int $sku, array $movement, int $document, int $attribution): void
    {
        // 采购实际退离按负现存量归因；实际运回时只冲回本次真正恢复的负现存量。
        $movement['before_available_qty'] = $movement['before_on_hand_qty']; $movement['after_available_qty'] = $movement['after_on_hand_qty'];
        self::allocateHealedAvailabilityWithinTransaction($warehouse, $sku, $movement, $document, 'finance_purchase_return_back', 'FIN-BACK-' . $document,
            ['open', 'waiting_inbound', 'retained'], 'purchase_return_back_offset', '采购退货实际运回定向补平', 0, 0, $document, $attribution);
    }

    /**
     * @param array<string,mixed> $movement
     * @param array<int,string> $eligibleStatuses
     */
    private static function allocateHealedAvailabilityWithinTransaction(
        int $warehouseId,
        int $skuId,
        array $movement,
        int $orderId,
        string $orderType,
        string $orderSn,
        array $eligibleStatuses,
        string $actionType,
        string $reasonPrefix,
        int $preferredSalesOrderId = 0,
        int $preferredReportItemId = 0,
        int $businessActionId = 0,
        int $preferredAttributionId = 0
    ): void {
        $beforeNegative = bccomp((string)$movement['before_available_qty'], '0.0000', self::SCALE) < 0
            ? ltrim((string)$movement['before_available_qty'], '-') : '0.0000';
        $afterNegative = bccomp((string)$movement['after_available_qty'], '0.0000', self::SCALE) < 0
            ? ltrim((string)$movement['after_available_qty'], '-') : '0.0000';
        $healed = bcsub($beforeNegative, $afterNegative, self::SCALE);
        if (bccomp($healed, '0.0000', self::SCALE) <= 0) {
            return;
        }
        $sources = Db::name('negative_inventory_attribution')->where('tenant_id', self::tenantId())
            ->where('warehouse_id', $warehouseId)->where('sku_id', $skuId)
            ->whereIn('resolution_status', $eligibleStatuses)->where('remaining_qty', '>', 0)
            ->order(['occurred_time' => 'asc', 'id' => 'asc'])->lock(true)->select()->toArray();
        if ($preferredSalesOrderId > 0 || $preferredAttributionId > 0) {
            usort($sources, static function (array $left, array $right) use ($preferredSalesOrderId, $preferredReportItemId, $preferredAttributionId): int {
                $priority = static function (array $source) use ($preferredSalesOrderId, $preferredReportItemId, $preferredAttributionId): int {
                    if ($preferredAttributionId > 0) { return (int)$source['id'] === $preferredAttributionId ? -1 : 2; }
                    if ((int)$source['sales_order_id'] === $preferredSalesOrderId
                        && $preferredReportItemId > 0
                        && (int)$source['report_item_id'] === $preferredReportItemId) {
                        return 0;
                    }
                    return (int)$source['sales_order_id'] === $preferredSalesOrderId ? 1 : 2;
                };
                return [$priority($left), (int)$left['occurred_time'], (int)$left['id']]
                    <=> [$priority($right), (int)$right['occurred_time'], (int)$right['id']];
            });
        }
        $remainingHealed = $healed;
        foreach ($sources as $source) {
            if (bccomp($remainingHealed, '0.0000', self::SCALE) <= 0) {
                break;
            }
            $sourceRemaining = self::decimal((string)$source['remaining_qty']);
            $allocated = bccomp($sourceRemaining, $remainingHealed, self::SCALE) <= 0
                ? $sourceRemaining : $remainingHealed;
            $afterRemaining = bcsub($sourceRemaining, $allocated, self::SCALE);
            $resolved = bccomp($afterRemaining, '0.0000', self::SCALE) === 0;
            $afterStatus = $resolved ? 'resolved' : (string)$source['resolution_status'];
            $now = time();
            Db::name('negative_inventory_attribution')->where('tenant_id', self::tenantId())
                ->where('id', (int)$source['id'])->update([
                    'remaining_qty' => $afterRemaining,
                    'resolution_status' => $afterStatus,
                    'resolved_time' => $resolved ? $now : 0,
                    'update_time' => $now,
                ]);
            self::updateTodoWithinTransaction((int)$source['id'], $resolved, $now);
            $after = $source;
            $after['remaining_qty'] = $afterRemaining;
            $after['resolution_status'] = $afterStatus;
            $fingerprint = hash('sha256', implode('|', [
                $actionType, (string)$source['id'], $orderType, (string)$orderId, $orderSn,
                (string)$movement['before_available_qty'], (string)$movement['after_available_qty'], $allocated,
                (string)$businessActionId,
            ]));
            $reason = $reasonPrefix . '：' . $orderType . '-' . $orderSn;
            $inserted = Db::name('negative_inventory_action')->insert([
                'tenant_id' => self::tenantId(),
                'attribution_id' => (int)$source['id'],
                'action_type' => $actionType,
                'quantity' => $allocated,
                'amount' => '0.00',
                'reason' => $reason,
                'cost_status' => (string)$source['cost_status'],
                'idempotency_key' => 'auto-offset:' . substr($fingerprint, 0, 80),
                'request_fingerprint' => $fingerprint,
                'operator_id' => self::operatorId(),
                'action_time' => $now,
                'before_snapshot' => json_encode($source, JSON_UNESCAPED_UNICODE),
                'after_snapshot' => json_encode($after, JSON_UNESCAPED_UNICODE),
            ]);
            if ($inserted !== 1) {
                throw new \RuntimeException('negative_inventory_auto_offset_insert_failed');
            }
            AuditService::logWithinTransaction(
                'negative_inventory',
                $actionType,
                (int)$source['id'],
                'NEG-' . (int)$source['id'],
                $source,
                $after,
                $reason
            );
            $remainingHealed = bcsub($remainingHealed, $allocated, self::SCALE);
        }
    }

    /** @return array<string,mixed>|false */
    private static function detailWithinTransaction(int $id): array|false
    {
        $source = Db::name('negative_inventory_attribution')->where('tenant_id', self::tenantId())
            ->where('id', $id)->find();
        if (!$source) {
            return false;
        }
        $source['id'] = (int)$source['id'];
        $source['actions'] = Db::name('negative_inventory_action')->where('tenant_id', self::tenantId())
            ->where('attribution_id', $id)->order('id')->select()->toArray();
        return $source;
    }

    /** @return array<string,mixed>|false */
    private static function replayAfterConcurrentCommit(string $idempotencyKey, string $fingerprint): array|false
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $action = Db::name('negative_inventory_action')->where('tenant_id', self::tenantId())
                ->where('idempotency_key', $idempotencyKey)->find();
            if ($action) {
                if (!hash_equals((string)$action['request_fingerprint'], $fingerprint)) {
                    return false;
                }
                return self::detailWithinTransaction((int)$action['attribution_id']);
            }
            if ($attempt < 4) {
                usleep(($attempt + 1) * 20_000);
            }
        }
        return false;
    }

    private static function updateTodoWithinTransaction(int $attributionId, bool $resolved, int $now): void
    {
        Db::name('negative_inventory_todo')->where('tenant_id', self::tenantId())
            ->where('attribution_id', $attributionId)->update([
                'status' => $resolved ? 'closed' : 'open',
                'resolved_by' => $resolved ? self::operatorId() : 0,
                'resolved_time' => $resolved ? $now : 0,
                'update_time' => $now,
            ]);
    }

    private static function nonNegative(string $value): string|false
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d+(?:\.\d{1,4})?$/', $value) !== 1) {
            return false;
        }
        return self::decimal($value);
    }

    private static function money(string $value): string|false
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return false;
        }
        return bcadd($value, '0', 2);
    }

    private static function decimal(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
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
