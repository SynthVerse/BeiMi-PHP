<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 处理后减量与未交货；两者都不是通用报货编辑。 */
final class FulfillmentChangeLogic extends BaseLogic
{
    private const SCALE = 2;
    private const UNDELIVERED_REASONS = ['shortage', 'damage', 'customer_cancel'];
    private const DISPOSITIONS = ['return_to_stock', 'internal_loss', 'other'];

    /** 服务端统一裁决任务页可见的履约变更动作。 @return array<string,array<string,mixed>> */
    public static function actionsForItem(array $report, array $item, bool $hasReservation, bool $isPrimaryTask, bool $hasOpenPaperControl, bool $hasControlPermission): array
    {
        $blockedReason = !$isPrimaryTask
            ? '请从该商品的主处理任务操作'
            : (!$hasControlPermission
                ? '没有执行该操作的电子权限'
                : self::itemChangeBlockedReason($report, $item, $hasReservation, $hasOpenPaperControl));

        $allowed = $blockedReason === '';
        $base = ['allowed' => $allowed, 'blocked_reason' => $blockedReason];
        return [
            'reduce_item' => $base + ['current_expected_base_qty' => self::decimal((string)($item['expected_base_qty'] ?? '0'))],
            'mark_undelivered' => $base,
        ];
    }

    /** @return array<string,mixed>|false */
    public static function reduceItem(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.control')) {
            return false;
        }
        $itemId = (int)($params['report_item_id'] ?? 0);
        $key = trim((string)($params['idempotency_key'] ?? ''));
        $target = self::quantity((string)($params['new_expected_base_qty'] ?? ''));
        $processed = self::quantity((string)($params['processed_reduction_qty'] ?? '0'));
        $disposition = trim((string)($params['processed_disposition'] ?? ''));
        $inventoryAction = trim((string)($params['other_inventory_action'] ?? ''));
        $reason = mb_substr(trim((string)($params['reason'] ?? '')), 0, 500);
        if ($itemId <= 0 || $key === '' || mb_strlen($key) > 96 || $target === false || $processed === false || $reason === '') {
            self::setError('处理后减量参数不完整');
            return false;
        }
        if (bccomp($target, '0.00', self::SCALE) <= 0) {
            self::setError('整行不再交付必须使用未交货动作，不能把最终数量改为 0');
            return false;
        }
        if (bccomp($processed, '0.00', self::SCALE) > 0 && !in_array($disposition, self::DISPOSITIONS, true)) {
            self::setError('已加工减量必须选择转回可售库存、内部损耗或其他处理');
            return false;
        }
        if ($disposition === 'other' && !in_array($inventoryAction, ['release', 'consume'], true)) {
            self::setError('其他处理必须明确库存动作是释放或消耗');
            return false;
        }
        $fingerprint = self::fingerprint([
            'action' => 'reduce', 'item_id' => $itemId, 'target' => $target, 'processed' => $processed,
            'disposition' => $disposition, 'inventory_action' => $inventoryAction, 'reason' => $reason,
        ]);
        if (($replay = self::replay($key, $fingerprint)) !== null) {
            return $replay;
        }
        try {
            return self::transactionWithRetry(static function () use ($itemId, $key, $target, $processed, $disposition, $inventoryAction, $reason, $fingerprint) {
                if (($replay = self::replay($key, $fingerprint)) !== null) {
                    return $replay;
                }
                [$report, $item, $reservation] = self::lockedContext($itemId);
                if ($report === false) {
                    return false;
                }
                if (($replay = self::replay($key, $fingerprint)) !== null) {
                    return $replay;
                }
                if ((string)($item['fulfillment_status'] ?? 'pending') === 'undelivered') {
                    self::setError('未交货明细不能再执行处理后减量');
                    throw new \RuntimeException('possible_idempotency_race');
                }
                $current = self::decimal((string)$item['expected_base_qty']);
                if (bccomp($target, $current, self::SCALE) >= 0) {
                    self::setError('处理后减量的新数量必须小于当前数量');
                    if (bccomp($target, $current, self::SCALE) === 0) {
                        throw new \RuntimeException('possible_idempotency_race');
                    }
                    return false;
                }
                $reduction = bcsub($current, $target, self::SCALE);
                if (bccomp($processed, $reduction, self::SCALE) > 0) {
                    self::setError('已加工减量不能大于本次总减量');
                    return false;
                }
                $unprocessed = bcsub($reduction, $processed, self::SCALE);
                $activeReserved = self::activeReserved($reservation);
                if (in_array($disposition, ['internal_loss', 'other'], true)
                    && ($disposition === 'internal_loss' || $inventoryAction === 'consume')
                    && bccomp($processed, $activeReserved, self::SCALE) > 0) {
                    self::setError('已加工损耗超过当前有效预留，不能在减量动作中无归因出库');
                    return false;
                }
                $consume = ($disposition === 'internal_loss' || ($disposition === 'other' && $inventoryAction === 'consume'))
                    ? $processed : '0.00';
                $releaseWanted = bcadd($unprocessed, bccomp($consume, '0.00', self::SCALE) === 0 ? $processed : '0.00', self::SCALE);
                $release = bccomp($releaseWanted, bcsub($activeReserved, $consume, self::SCALE), self::SCALE) > 0
                    ? bcsub($activeReserved, $consume, self::SCALE) : $releaseWanted;
                self::applyReservationMovement($item, $reservation, $release, $consume, $disposition);

                $newReserved = bcsub($activeReserved, bcadd($release, $consume, self::SCALE), self::SCALE);
                $newShortage = bcsub($target, $newReserved, self::SCALE);
                if (bccomp($newShortage, '0.00', self::SCALE) < 0) {
                    $newShortage = '0.00';
                }
                $before = $item;
                $after = array_replace($item, [
                    'expected_base_qty' => $target,
                    'reserved_base_qty' => $newReserved,
                    'shortage_base_qty' => $newShortage,
                    'reduction_total_qty' => bcadd((string)($item['reduction_total_qty'] ?? '0'), $reduction, self::SCALE),
                    'final_actual_weight' => '0.00', 'final_weight_task_id' => 0,
                    'fulfillment_status' => 'pending',
                    'status' => bccomp($newShortage, '0.00', self::SCALE) > 0 ? 'submitted_shortage' : 'submitted_ready',
                    'update_time' => time(),
                ]);
                Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', $itemId)->update([
                    'expected_base_qty' => $after['expected_base_qty'], 'reserved_base_qty' => $after['reserved_base_qty'],
                    'shortage_base_qty' => $after['shortage_base_qty'], 'reduction_total_qty' => $after['reduction_total_qty'],
                    'status' => $after['status'], 'update_time' => $after['update_time'],
                ]);
                $changeId = self::insertChange([
                    'report_id' => (int)$report['id'], 'report_item_id' => $itemId, 'action_type' => 'reduce',
                    'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'quantity' => $reduction,
                    'processed_quantity' => $processed, 'disposition' => $disposition,
                    'reason_code' => '', 'reason' => $reason, 'before_data' => $before, 'after_data' => $after,
                ]);
                $controls = FulfillmentTaskLogic::invalidatePrintedItemTickets(
                    $itemId, $changeId, 'change', $key, $reason, $before, $after, $target
                );
                self::refreshReport((int)$report['id']);
                AuditService::logWithinTransaction(
                    'fulfillment_item', 'reduce', $itemId, (string)($report['sn'] ?? ''), $before, $after, $reason
                );
                return self::result($changeId, $controls);
            });
        } catch (\Throwable) {
            if (($replay = self::replay($key, $fingerprint)) !== null) {
                return $replay;
            }
            if (!self::hasError()) {
                self::setError('处理后减量失败，事务已回滚');
            }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function markUndelivered(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('task.control')) {
            return false;
        }
        $itemId = (int)($params['report_item_id'] ?? 0);
        $key = trim((string)($params['idempotency_key'] ?? ''));
        $reasonCode = trim((string)($params['reason_code'] ?? ''));
        $reason = mb_substr(trim((string)($params['reason'] ?? '')), 0, 500);
        if ($itemId <= 0 || $key === '' || mb_strlen($key) > 96 || !in_array($reasonCode, self::UNDELIVERED_REASONS, true) || $reason === '') {
            self::setError('未交货必须选择缺货、损坏或客户临时取消原因并填写说明');
            return false;
        }
        $fingerprint = self::fingerprint([
            'action' => 'undelivered', 'item_id' => $itemId, 'reason_code' => $reasonCode, 'reason' => $reason,
        ]);
        if (($replay = self::replay($key, $fingerprint)) !== null) {
            return $replay;
        }
        try {
            return self::transactionWithRetry(static function () use ($itemId, $key, $reasonCode, $reason, $fingerprint) {
                if (($replay = self::replay($key, $fingerprint)) !== null) {
                    return $replay;
                }
                [$report, $item, $reservation] = self::lockedContext($itemId);
                if ($report === false) {
                    return false;
                }
                if (($replay = self::replay($key, $fingerprint)) !== null) {
                    return $replay;
                }
                if ((string)($item['fulfillment_status'] ?? 'pending') === 'undelivered') {
                    self::setError('该明细已经记录为未交货');
                    throw new \RuntimeException('possible_idempotency_race');
                }
                $activeReserved = self::activeReserved($reservation);
                self::applyReservationMovement($item, $reservation, $activeReserved, '0.00');
                $before = $item;
                $after = array_replace($item, [
                    'reserved_base_qty' => '0.00', 'shortage_base_qty' => '0.00',
                    'final_actual_weight' => '0.00', 'final_weight_task_id' => 0,
                    'fulfillment_status' => 'undelivered', 'undelivered_reason_code' => $reasonCode,
                    'undelivered_reason' => $reason, 'status' => 'undelivered', 'update_time' => time(),
                ]);
                Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', $itemId)->update([
                    'reserved_base_qty' => '0.00', 'shortage_base_qty' => '0.00',
                    'final_actual_weight' => '0.00', 'final_weight_task_id' => 0,
                    'fulfillment_status' => 'undelivered', 'undelivered_reason_code' => $reasonCode,
                    'undelivered_reason' => $reason, 'status' => 'undelivered', 'update_time' => $after['update_time'],
                ]);
                $changeId = self::insertChange([
                    'report_id' => (int)$report['id'], 'report_item_id' => $itemId, 'action_type' => 'undelivered',
                    'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                    'quantity' => (string)$item['expected_base_qty'], 'processed_quantity' => '0.00',
                    'disposition' => '', 'reason_code' => $reasonCode, 'reason' => $reason,
                    'before_data' => $before, 'after_data' => $after,
                ]);
                $controls = FulfillmentTaskLogic::invalidatePrintedItemTickets(
                    $itemId, $changeId, 'void', $key, $reason, $before, $after, (string)$item['expected_base_qty']
                );
                Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('report_item_id', $itemId)
                    ->whereNotIn('status', ['cancelled', 'completed'])->update(['status' => 'cancelled', 'update_time' => time()]);
                self::refreshReport((int)$report['id']);
                $deliverableItems = (int)Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                    ->where('report_id', (int)$report['id'])->whereNull('delete_time')
                    ->where('fulfillment_status', '<>', 'undelivered')->count();
                if ($deliverableItems === 0) {
                    Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('report_id', (int)$report['id'])
                        ->where('report_item_id', 0)->whereNotIn('status', ['cancelled', 'completed'])
                        ->update(['status' => 'cancelled', 'update_time' => time()]);
                    Db::name('customer_report')->where('tenant_id', self::tenantId())->where('id', (int)$report['id'])
                        ->update(['status' => 'fulfilled_undelivered', 'update_time' => time()]);
                }
                FulfillmentTaskLogic::refreshGroupForItem($itemId);
                AuditService::logWithinTransaction(
                    'fulfillment_item', 'undelivered', $itemId, (string)($report['sn'] ?? ''), $before, $after, $reason
                );
                return self::result($changeId, $controls);
            });
        } catch (\Throwable) {
            if (($replay = self::replay($key, $fingerprint)) !== null) {
                return $replay;
            }
            if (!self::hasError()) {
                self::setError('未交货保存失败，事务已回滚');
            }
            return false;
        }
    }

    /** @return array{0:array<string,mixed>|false,1:array<string,mixed>,2:array<string,mixed>} */
    private static function lockedContext(int $itemId): array
    {
        FinanceIntegration::lock();
        $itemRef = Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', $itemId)->field('report_id')->find();
        if (!$itemRef) {
            self::setError('报货明细不存在');
            return [false, [], []];
        }
        $report = Db::name('customer_report')->where('tenant_id', self::tenantId())->where('id', (int)$itemRef['report_id'])->lock(true)->find();
        $item = Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', $itemId)->lock(true)->find();
        $reservation = Db::name('customer_report_reservation')->where('tenant_id', self::tenantId())
            ->where('report_item_id', $itemId)->lock(true)->find();
        $hasOpenPaperControl = $item && Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
            ->where('report_item_id', $itemId)->where('status', '<>', 'closed')->lock(true)->find();
        $blockedReason = self::itemChangeBlockedReason(
            $report ?: [],
            $item ?: [],
            (bool)$reservation,
            (bool)$hasOpenPaperControl
        );
        if ($blockedReason !== '') {
            self::setError($blockedReason);
            return [false, [], []];
        }
        return [$report, $item, $reservation];
    }

    /** 投影与事务写入口共用的商品履约变更资格规则。 */
    private static function itemChangeBlockedReason(array $report, array $item, bool $hasReservation, bool $hasOpenPaperControl): string
    {
        if (!$report || !$item || !$hasReservation) {
            return '报货明细或库存预留不可用';
        }
        if (in_array((string)($report['status'] ?? ''), ['cancelled', 'completed'], true)) {
            return '报货单当前不可变更';
        }
        if ((string)($item['fulfillment_status'] ?? 'pending') === 'undelivered') {
            return '该明细已经记录为未交货';
        }
        if (bccomp((string)($item['expected_base_qty'] ?? '0'), '0.00', self::SCALE) <= 0) {
            return '当前应交数量不可变更';
        }
        if ($hasOpenPaperControl) {
            return '请先完成现有纸票回收或作废控制';
        }
        return '';
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $reservation */
    private static function applyReservationMovement(array $item, array $reservation, string $release, string $consume, string $disposition = ''): void
    {
        $warehouseId = (int)$item['warehouse_id'];
        $skuId = (int)$item['sku_id'];
        if (bccomp($release, '0.00', self::SCALE) > 0
            && WarehouseSkuBalanceService::releaseWithinTransaction($warehouseId, $skuId, $release) === false) {
            throw new \RuntimeException('release_reserved_failed');
        }
        if (bccomp($consume, '0.00', self::SCALE) > 0) {
            $movement = FinanceIntegration::active()
                ? StockService::outboundReservedWithinTransaction($warehouseId, (int)$item['goods_id'], $consume, (int)$item['id'],
                    $disposition === 'internal_loss' ? 'fulfillment_internal_loss' : 'fulfillment_pending', 'FULFILLMENT-LOSS-' . $item['id'],
                    $disposition === 'internal_loss' ? '已加工减量的内部损耗' : '已加工减量的其他消耗，成本去向待核实', $skuId)
                : WarehouseSkuBalanceService::consumeReservedWithinTransaction($warehouseId, $skuId, $consume);
            if ($movement === false) { throw new \RuntimeException('consume_reserved_failed'); }
        }
        $released = bcadd((string)$reservation['released_base_qty'], $release, self::SCALE);
        $consumed = bcadd((string)$reservation['consumed_base_qty'], $consume, self::SCALE);
        $active = bcsub((string)$reservation['reserved_base_qty'], bcadd($released, $consumed, self::SCALE), self::SCALE);
        Db::name('customer_report_reservation')->where('tenant_id', self::tenantId())->where('id', (int)$reservation['id'])->update([
            'released_base_qty' => $released, 'consumed_base_qty' => $consumed,
            'status' => bccomp($active, '0.00', self::SCALE) === 0 ? 'released' : 'reserved', 'update_time' => time(),
        ]);
    }

    /** @param array<string,mixed> $reservation */
    private static function activeReserved(array $reservation): string
    {
        $used = bcadd((string)$reservation['consumed_base_qty'], (string)$reservation['released_base_qty'], self::SCALE);
        $active = bcsub((string)$reservation['reserved_base_qty'], $used, self::SCALE);
        return bccomp($active, '0.00', self::SCALE) < 0 ? '0.00' : self::decimal($active);
    }

    private static function refreshReport(int $reportId): void
    {
        $items = Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('report_id', $reportId)
            ->whereNull('delete_time')->select()->toArray();
        $total = $reserved = $shortage = '0.00';
        foreach ($items as $item) {
            $total = bcadd($total, (string)$item['expected_base_qty'], self::SCALE);
            $reserved = bcadd($reserved, (string)$item['reserved_base_qty'], self::SCALE);
            $shortage = bcadd($shortage, (string)$item['shortage_base_qty'], self::SCALE);
        }
        Db::name('customer_report')->where('tenant_id', self::tenantId())->where('id', $reportId)->inc('version')->update([
            'total_base_qty' => $total, 'reserved_base_qty' => $reserved, 'shortage_base_qty' => $shortage,
            'status' => bccomp($shortage, '0.00', self::SCALE) > 0 ? 'submitted_shortage' : 'submitted_ready',
            'update_time' => time(),
        ]);
    }

    /** @param array<string,mixed> $data */
    private static function insertChange(array $data): int
    {
        return (int)Db::name('fulfillment_item_change')->insertGetId([
            'tenant_id' => self::tenantId(), 'report_id' => $data['report_id'], 'report_item_id' => $data['report_item_id'],
            'action_type' => $data['action_type'], 'idempotency_key' => $data['idempotency_key'],
            'request_fingerprint' => $data['request_fingerprint'], 'quantity' => $data['quantity'],
            'processed_quantity' => $data['processed_quantity'], 'disposition' => $data['disposition'],
            'reason_code' => $data['reason_code'], 'reason' => $data['reason'],
            'before_data' => json_encode($data['before_data'], JSON_UNESCAPED_UNICODE),
            'after_data' => json_encode($data['after_data'], JSON_UNESCAPED_UNICODE),
            'operator_id' => self::operatorId(), 'create_time' => time(),
        ]);
    }

    /** @return array<string,mixed>|false|null */
    private static function replay(string $key, string $fingerprint): array|false|null
    {
        $change = Db::name('fulfillment_item_change')->where('tenant_id', self::tenantId())
            ->where('idempotency_key', $key)->find();
        if (!$change) {
            return null;
        }
        if (!hash_equals((string)$change['request_fingerprint'], $fingerprint)) {
            self::setError('幂等键已用于不同的履约变更');
            return false;
        }
        return self::result((int)$change['id']);
    }

    /** @param array<int,array<string,mixed>> $controls @return array<string,mixed> */
    private static function result(int $changeId, array $controls = []): array
    {
        $change = Db::name('fulfillment_item_change')->where('tenant_id', self::tenantId())->where('id', $changeId)->find();
        $item = Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', (int)$change['report_item_id'])->find();
        if ($controls === []) {
            $controls = Db::name('fulfillment_ticket_control')->where('tenant_id', self::tenantId())
                ->where('item_change_id', $changeId)
                ->order('id')->select()->toArray();
        }
        return ['change' => $change, 'item' => $item, 'ticket_controls' => $controls];
    }

    /** @param array<string,mixed> $data */
    private static function fingerprint(array $data): string
    {
        return hash('sha256', (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function quantity(string $value): string|false
    {
        $value = trim($value);
        return preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1 ? self::decimal($value) : false;
    }

    private static function decimal(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function operatorId(): int
    {
        return (int)(request()->adminId ?? request()->userId ?? 0);
    }

    /** @template T @param callable():T $operation @return T */
    private static function transactionWithRetry(callable $operation): mixed
    {
        $lastException = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return Db::transaction($operation);
            } catch (\Throwable $exception) {
                $lastException = $exception;
                if (!self::retryable($exception) || $attempt === 2) {
                    throw $exception;
                }
                self::clearError();
                usleep(($attempt + 1) * 20_000);
            }
        }
        throw $lastException ?? new \RuntimeException('transaction_failed');
    }

    private static function retryable(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), '1213') || str_contains($exception->getMessage(), '1205');
    }
}
