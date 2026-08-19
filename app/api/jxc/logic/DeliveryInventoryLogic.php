<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 三种配送共用的真实交付事件与受控库存出库。 */
final class DeliveryInventoryLogic extends BaseLogic
{
    private const SCALE = 4;

    /** @return array<string,mixed>|false */
    public static function confirmSelfDelivery(array $params): array|false
    {
        if (array_key_exists('items', $params)) {
            $params['event_type'] = (string)($params['event_type'] ?? 'customer_handoff');
            return self::confirmVariant($params, 'self_delivery', 'customer_handoff');
        }
        return self::confirmDelivery($params, 'self_delivery', 'customer_handoff');
    }

    /** @return array<string,mixed>|false */
    public static function confirmFixedLineHandoff(array $params): array|false
    {
        $params['event_type'] = 'line_vehicle_handoff';
        if (array_key_exists('items', $params)) {
            return self::confirmVariant($params, 'fixed_line_vehicle', 'line_vehicle_handoff');
        }
        return self::confirmDelivery($params, 'fixed_line_vehicle', 'line_vehicle_handoff');
    }

    /** @return array<string,mixed>|false */
    public static function confirmThirdPartyDelivery(array $params): array|false
    {
        $params['event_type'] = 'third_party_driver_handoff';
        return self::confirmVariant($params, 'third_party', 'third_party_driver_handoff');
    }

    /** @return array<string,mixed>|false */
    private static function confirmVariant(array $params, string $method, string $eventType): array|false
    {
        $result = DeliveryVariantLogic::confirm($params, $method, $eventType);
        if ($result === false) {
            self::setError(DeliveryVariantLogic::getError());
        } else {
            self::clearError();
        }
        return $result;
    }

    /** @return array<string,mixed>|false */
    private static function confirmDelivery(
        array $params,
        string $deliveryMethod,
        string $requiredEventType
    ): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.confirm')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $tripReportId = $deliveryMethod === 'fixed_line_vehicle' ? (int)($params['trip_report_id'] ?? 0) : 0;
        $taskId = (int)($params['task_id'] ?? 0);
        $eventType = trim((string)($params['event_type'] ?? ''));
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        $handoffNote = mb_substr(trim((string)($params[
            $deliveryMethod === 'fixed_line_vehicle' ? 'exception_note' : 'handoff_note'
        ] ?? '')), 0, 500);
        $exceptionReason = mb_substr(trim((string)($params['exception_reason'] ?? '')), 0, 500);
        $secondConfirmed = (int)($params['second_confirmed'] ?? 0) === 1 ? 1 : 0;
        $actualHandoffPackages = $deliveryMethod === 'fixed_line_vehicle'
            ? (int)($params['actual_handoff_packages'] ?? 0)
            : 0;
        $actualHandoffTime = $deliveryMethod === 'fixed_line_vehicle'
            ? self::timestamp((string)($params['actual_handoff_time'] ?? ''))
            : 0;
        if ($deliveryMethod === 'fixed_line_vehicle') {
            $tripReference = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('id', $tripReportId)->field('id,delivery_task_id')->find();
            if (!$tripReference) {
                self::setError('送站报货单不存在');
                return false;
            }
            $taskId = (int)$tripReference['delivery_task_id'];
            if ($actualHandoffPackages <= 0 || $actualHandoffTime === false) {
                self::setError('实际交接包数和时间不能为空');
                return false;
            }
            if ($actualHandoffTime > FulfillmentClock::now()) {
                self::setError('实际线车交接时间不能晚于当前时间');
                return false;
            }
        }
        if ($taskId <= 0 || $idempotencyKey === '' || mb_strlen($idempotencyKey) > 96) {
            self::setError('送货任务和交付幂等键不能为空');
            return false;
        }
        if ($eventType !== $requiredEventType) {
            self::setError($deliveryMethod === 'self_delivery'
                ? '自配送只有实际交给客户才是交付事件，车辆离店不能出库'
                : '固定线车只有实际交给指定线车才是交付事件，门店出车不能出库');
            return false;
        }
        $fingerprintValues = [
            'task_id' => $taskId,
            'delivery_method' => $deliveryMethod,
            'event_type' => $eventType,
            'handoff_note' => $handoffNote,
            'exception_reason' => $exceptionReason,
            'second_confirmed' => $secondConfirmed,
        ];
        if ($deliveryMethod === 'fixed_line_vehicle') {
            $fingerprintValues += [
                'trip_report_id' => $tripReportId,
                'actual_handoff_packages' => $actualHandoffPackages,
                'actual_handoff_time' => $actualHandoffTime,
            ];
        }
        $fingerprint = hash('sha256', json_encode(
            $fingerprintValues,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = Db::transaction(static function () use (
                    $taskId,
                    $eventType,
                    $idempotencyKey,
                    $fingerprint,
                    $handoffNote,
                    $exceptionReason,
                    $secondConfirmed,
                    $deliveryMethod,
                    $tripReportId,
                    $actualHandoffPackages,
                    $actualHandoffTime
                ) {
                    $existing = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
                        ->where('idempotency_key', $idempotencyKey)->find();
                    if ($existing) {
                        if (!hash_equals((string)$existing['request_fingerprint'], $fingerprint)) {
                            self::setError('同一幂等键不能提交不同的交付事实');
                            return false;
                        }
                        return self::detailWithinTransaction((int)$existing['id']);
                    }

                    $trip = null;
                    $tripReport = null;
                    if ($deliveryMethod === 'fixed_line_vehicle') {
                        $tripReference = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                            ->where('id', $tripReportId)->field('id,trip_id')->find();
                        if (!$tripReference) {
                            self::setError('送站报货单不存在');
                            return false;
                        }
                        $trip = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                            ->where('id', (int)$tripReference['trip_id'])->lock(true)->find();
                        $tripReport = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                            ->where('id', $tripReportId)->lock(true)->find();
                        if (!$trip || !$tripReport) {
                            self::setError('门店送站趟次不存在');
                            return false;
                        }
                        if ((int)$trip['actual_store_departure_time'] <= 0 || (string)$trip['status'] !== 'departed') {
                            self::setError('必须先回录门店实际出车时间');
                            return false;
                        }
                        if ((string)$tripReport['status'] === 'handed_over') {
                            self::setError('该客户货物已经完成线车交接');
                            return false;
                        }
                        if ((string)$tripReport['status'] !== 'loading'
                            || $tripReport['active_report_id'] === null
                            || (int)$tripReport['active_report_id'] !== (int)$tripReport['report_id']) {
                            self::setError('已改派或不再活动的旧趟次货物不能交接');
                            return false;
                        }
                        if ((int)$tripReport['loaded_checked'] !== 1) {
                            self::setError('必须先完成装车包数核对');
                            return false;
                        }
                        if (($actualHandoffPackages !== (int)$tripReport['expected_package_count']
                            || $actualHandoffPackages !== (int)$tripReport['actual_loaded_package_count'])
                            && $handoffNote === '') {
                            self::setError('交接包数与应装或实际装车包数不一致时必须填写异常原因');
                            return false;
                        }
                        if ($actualHandoffTime < (int)$trip['actual_store_departure_time']) {
                            self::setError('实际线车交接时间不能早于门店出车时间');
                            return false;
                        }
                        if ($actualHandoffTime > (int)$tripReport['handoff_deadline']) {
                            self::setError('已错过线车交接截止时间，不能标记交接完成，必须改派');
                            return false;
                        }
                        $taskId = (int)$tripReport['delivery_task_id'];
                    }

                    $taskRef = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                        ->where('id', $taskId)->field('id,report_id')->find();
                    if (!$taskRef) {
                        self::setError('送货任务不存在');
                        return false;
                    }
                    $report = Db::name('customer_report')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$taskRef['report_id'])->whereNull('delete_time')->lock(true)->find();
                    if (!$report) {
                        self::setError('关联报货单不存在');
                        return false;
                    }
                    $allItems = Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                        ->where('report_id', (int)$report['id'])->whereNull('delete_time')
                        ->order(['sku_id' => 'asc', 'goods_id' => 'asc', 'warehouse_id' => 'asc', 'id' => 'asc'])
                        ->lock(true)->select()->toArray();
                    $items = [];
                    $hasUndelivered = false;
                    foreach ($allItems as $item) {
                        if ((string)$item['fulfillment_status'] === 'undelivered') {
                            $hasUndelivered = true;
                            continue;
                        }
                        $items[] = $item;
                    }
                    $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                        ->where('id', $taskId)->lock(true)->find();
                    if (!$task || (string)$task['source_key'] !== 'report:' . (int)$report['id'] . ':delivery') {
                        self::setError('送货任务不存在');
                        return false;
                    }
                    // 首次无记录的并发请求可能在 report 锁处等待；取得稳定业务锁后必须再次重放查询。
                    $replayed = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
                        ->where('idempotency_key', $idempotencyKey)->lock(true)->find();
                    if ($replayed) {
                        if (!hash_equals((string)$replayed['request_fingerprint'], $fingerprint)) {
                            self::setError('同一幂等键不能提交不同的交付事实');
                            return false;
                        }
                        return self::detailWithinTransaction((int)$replayed['id']);
                    }
                    if ((string)$task['status'] === 'blocked') {
                        self::setError('前置工序尚未完成，不能确认交付');
                        return false;
                    }
                    if (in_array((string)$task['status'], ['cancelled', 'completed'], true)) {
                        self::setError('送货任务当前不能确认交付');
                        return false;
                    }
                    $previousEvent = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
                        ->where('task_id', $taskId)->where('status', 'completed')->lock(true)->find();
                    if ($previousEvent) {
                        self::setError('该送货任务已经由其他交付事件完成');
                        return false;
                    }
                    if ($items === []) {
                        self::setError('报货单没有可交付明细');
                        return false;
                    }
                    foreach ($items as $item) {
                        if (bccomp((string)$item['final_actual_weight'], '0.0000', self::SCALE) <= 0
                            || (int)$item['final_weight_task_id'] <= 0
                            || (string)$item['fulfillment_status'] !== 'final_weight_recorded') {
                            self::setError('每条交付明细必须先完成最终称重');
                            return false;
                        }
                    }

                    $now = FulfillmentClock::now();
                    $deliveredTime = $deliveryMethod === 'fixed_line_vehicle' ? (int)$actualHandoffTime : $now;
                    $eventId = (int)Db::name('fulfillment_delivery_event')->insertGetId([
                        'tenant_id' => self::tenantId(),
                        'report_id' => (int)$report['id'],
                        'task_id' => $taskId,
                        'trip_id' => $trip ? (int)$trip['id'] : 0,
                        'trip_report_id' => $tripReport ? (int)$tripReport['id'] : 0,
                        'line_schedule_id' => $tripReport ? (int)$tripReport['schedule_id'] : 0,
                        'delivery_method' => $deliveryMethod,
                        'event_type' => $eventType,
                        'status' => 'completed',
                        'idempotency_key' => $idempotencyKey,
                        'request_fingerprint' => $fingerprint,
                        'handoff_note' => $handoffNote,
                        'exception_reason' => $exceptionReason,
                        'second_confirmed' => $secondConfirmed,
                        'operator_id' => self::operatorId(),
                        'delivered_time' => $deliveredTime,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    if ($eventId <= 0) {
                        throw new \RuntimeException('delivery_event_insert_failed');
                    }

                    $orders = [];
                    $negativeRows = [];
                    $totalDelivered = '0.0000';
                    foreach ($items as $item) {
                        $itemId = (int)$item['id'];
                        $reservation = Db::name('customer_report_reservation')->where('tenant_id', self::tenantId())
                            ->where('report_item_id', $itemId)->lock(true)->find();
                        if (!$reservation) {
                            throw new \RuntimeException('delivery_reservation_missing');
                        }
                        $warehouseId = (int)$item['warehouse_id'];
                        if (!isset($orders[$warehouseId])) {
                            $orders[$warehouseId] = SalesOrderLogic::ensurePendingDeliveryOrderWithinTransaction($report, $warehouseId);
                            if ($orders[$warehouseId] === false) {
                                self::setError(SalesOrderLogic::getError());
                                throw new \RuntimeException('delivery_sales_order_identity_failed');
                            }
                        }
                        $order = $orders[$warehouseId];
                        $actual = self::decimal((string)$item['final_actual_weight']);
                        $reserved = self::decimal((string)$reservation['reserved_base_qty']);
                        $movement = StockService::outboundDeliveryWithinTransaction(
                            $warehouseId,
                            (int)$item['goods_id'],
                            (int)$item['sku_id'],
                            $actual,
                            $reserved,
                            (int)$order['id'],
                            (string)$order['order_sn'],
                            $eventId
                        );
                        if ($movement === false) {
                            throw new \RuntimeException('delivery_stock_failed');
                        }
                        if (!SalesOrderLogic::recordDeliveredItemWithinTransaction((int)$order['id'], $item, $actual)) {
                            throw new \RuntimeException('delivery_order_item_failed');
                        }
                        $deliveryItemId = (int)Db::name('fulfillment_delivery_item')->insertGetId([
                            'tenant_id' => self::tenantId(),
                            'delivery_event_id' => $eventId,
                            'sales_order_id' => (int)$order['id'],
                            'report_id' => (int)$report['id'],
                            'report_item_id' => $itemId,
                            'warehouse_id' => $warehouseId,
                            'goods_id' => (int)$item['goods_id'],
                            'sku_id' => (int)$item['sku_id'],
                            'actual_delivery_weight' => $actual,
                            'reservation_consumed_qty' => (string)$movement['reservation_consumed_qty'],
                            'reservation_released_qty' => (string)$movement['reservation_released_qty'],
                            'negative_qty' => (string)$movement['negative_qty'],
                            'create_time' => $now,
                        ]);
                        if ($deliveryItemId <= 0) {
                            throw new \RuntimeException('delivery_item_insert_failed');
                        }
                        Db::name('customer_report_reservation')->where('tenant_id', self::tenantId())
                            ->where('id', (int)$reservation['id'])->update([
                                'reserved_base_qty' => '0.00',
                                'consumed_base_qty' => bcadd((string)$reservation['consumed_base_qty'], (string)$movement['reservation_consumed_qty'], self::SCALE),
                                'released_base_qty' => bcadd((string)$reservation['released_base_qty'], (string)$movement['reservation_released_qty'], self::SCALE),
                                'status' => 'fulfilled',
                                'update_time' => $now,
                            ]);
                        Db::name('customer_report_item')->where('tenant_id', self::tenantId())->where('id', $itemId)->update([
                            'reserved_base_qty' => '0.00',
                            'shortage_base_qty' => '0.00',
                            'fulfilled_base_qty' => bcadd((string)$item['fulfilled_base_qty'], $actual, self::SCALE),
                            'status' => 'delivered',
                            'fulfillment_status' => 'delivered',
                            'update_time' => $now,
                        ]);
                        $negativeQty = self::decimal((string)$movement['negative_qty']);
                        if (bccomp($negativeQty, '0.0000', self::SCALE) > 0) {
                            $unitCost = bcadd((string)Db::name('goods')->where('tenant_id', self::tenantId())
                                ->where('id', (int)$item['goods_id'])->value('cost'), '0', 2);
                            $negativeAmount = bcmul($negativeQty, $unitCost, 2);
                            $costStatus = bccomp($unitCost, '0.00', 2) > 0 ? 'confirmed' : 'pending';
                            $attributionId = (int)Db::name('negative_inventory_attribution')->insertGetId([
                                'tenant_id' => self::tenantId(),
                                'delivery_event_id' => $eventId,
                                'delivery_item_id' => $deliveryItemId,
                                'sales_order_id' => (int)$order['id'],
                                'report_id' => (int)$report['id'],
                                'report_item_id' => (int)$item['id'],
                                'warehouse_id' => (int)$item['warehouse_id'],
                                'goods_id' => (int)$item['goods_id'],
                                'sku_id' => (int)$item['sku_id'],
                                'negative_qty' => $negativeQty,
                                'negative_amount' => $negativeAmount,
                                'remaining_qty' => $negativeQty,
                                'reason' => '最终实重超过可用库存',
                                'threshold_explanation' => $exceptionReason,
                                'threshold_confirmed' => $secondConfirmed,
                                'resolution_status' => 'open',
                                'cost_status' => $costStatus,
                                'operator_id' => self::operatorId(),
                                'occurred_time' => $now,
                                'resolved_time' => 0,
                                'update_time' => $now,
                            ]);
                            if ($attributionId <= 0) {
                                throw new \RuntimeException('negative_attribution_insert_failed');
                            }
                            $todoInserted = Db::name('negative_inventory_todo')->insert([
                                'tenant_id' => self::tenantId(),
                                'attribution_id' => $attributionId,
                                'status' => 'open',
                                'severity' => 'red',
                                'assignee_scope' => 'highest_privilege',
                                'create_time' => $now,
                                'update_time' => $now,
                            ]);
                            if ($todoInserted !== 1) {
                                throw new \RuntimeException('negative_inventory_todo_insert_failed');
                            }
                            if ($costStatus === 'pending') {
                                SalesOrderLogic::markCostPendingWithinTransaction((int)$order['id']);
                            }
                            $negativeRows[] = [
                                'attribution_id' => $attributionId,
                                'negative_qty' => $negativeQty,
                                'unit_cost' => $unitCost,
                            ];
                        }
                        $totalDelivered = bcadd($totalDelivered, $actual, self::SCALE);
                    }

                    $setting = Db::name('negative_inventory_setting')->where('tenant_id', self::tenantId())->lock(true)->find();
                    $quantityThreshold = self::decimal((string)($setting['quantity_threshold'] ?? '999999999.0000'));
                    $amountThreshold = bcadd((string)($setting['amount_threshold'] ?? '999999999.00'), '0', 2);
                    $totalNegativeQty = '0.0000';
                    $totalNegativeAmount = '0.00';
                    $openNegativeCount = 0;
                    foreach ($negativeRows as $negativeRow) {
                        $remainingQty = self::decimal((string)Db::name('negative_inventory_attribution')
                            ->where('tenant_id', self::tenantId())
                            ->where('id', (int)$negativeRow['attribution_id'])->value('remaining_qty'));
                        if (bccomp($remainingQty, '0.0000', self::SCALE) <= 0) {
                            continue;
                        }
                        $openNegativeCount++;
                        $totalNegativeQty = bcadd($totalNegativeQty, $remainingQty, self::SCALE);
                        $totalNegativeAmount = bcadd(
                            $totalNegativeAmount,
                            bcmul($remainingQty, (string)$negativeRow['unit_cost'], 2),
                            2
                        );
                    }
                    $thresholdExceeded = bccomp($totalNegativeQty, $quantityThreshold, self::SCALE) > 0
                        || bccomp($totalNegativeAmount, $amountThreshold, 2) > 0;
                    if ($thresholdExceeded && ($exceptionReason === '' || $secondConfirmed !== 1)) {
                        self::setError('负库存超过配置阈值，必须填写说明并二次确认');
                        throw new \RuntimeException('negative_threshold_confirmation_required');
                    }

                    Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $taskId)->update([
                        'status' => 'recovered',
                        'actual_weight' => $totalDelivered,
                        'process_weight' => $totalDelivered,
                        'recovery_note' => $handoffNote,
                        'recovered_by' => self::operatorId(),
                        'recovered_time' => $now,
                        'update_time' => $now,
                    ]);
                    Db::name('customer_report')->where('tenant_id', self::tenantId())->where('id', (int)$report['id'])->update([
                        'status' => $hasUndelivered
                            ? 'partially_delivered_pending'
                            : 'delivered_pending_settlement',
                        'reserved_base_qty' => '0.00',
                        'shortage_base_qty' => '0.00',
                        'update_time' => $now,
                    ]);
                    if ($deliveryMethod === 'fixed_line_vehicle' && $trip && $tripReport) {
                        $packedException = (string)($tripReport['packed_exception_note'] ?? '');
                        $loadedException = (string)($tripReport['loaded_exception_note'] ?? '');
                        $exceptionSummary = self::packageExceptionSummary(
                            $packedException,
                            $loadedException,
                            $handoffNote
                        );
                        Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                            ->where('id', (int)$tripReport['id'])->update([
                                'active_report_id' => null,
                                'actual_handoff_package_count' => $actualHandoffPackages,
                                'handoff_checked' => 1,
                                'actual_handoff_time' => (int)$actualHandoffTime,
                                'handoff_exception_note' => $handoffNote,
                                'package_exception_note' => mb_substr($exceptionSummary, 0, 500),
                                'status' => 'handed_over',
                                'delivery_event_id' => $eventId,
                                'operator_id' => self::operatorId(),
                                'update_time' => $now,
                            ]);
                        $remaining = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                            ->where('trip_id', (int)$trip['id'])->whereNotNull('active_report_id')->count();
                        if ($remaining === 0) {
                            $rerouted = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                                ->where('trip_id', (int)$trip['id'])->where('status', 'rerouted')->count();
                            Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                                ->where('id', (int)$trip['id'])->update([
                                    'status' => $rerouted > 0 ? 'closed' : 'completed',
                                    'operator_id' => self::operatorId(),
                                    'update_time' => $now,
                                ]);
                        }
                    }
                    AuditService::logWithinTransaction(
                        'fulfillment_delivery',
                        $deliveryMethod === 'fixed_line_vehicle' ? 'fixed_line_handoff' : 'self_customer_handoff',
                        $eventId,
                        $idempotencyKey,
                        null,
                        [
                            'report_id' => (int)$report['id'],
                            'task_id' => $taskId,
                            'actual_delivery_weight' => $totalDelivered,
                            'negative_count' => $openNegativeCount,
                            'trip_id' => $trip ? (int)$trip['id'] : 0,
                            'trip_report_id' => $tripReport ? (int)$tripReport['id'] : 0,
                            'actual_handoff_packages' => $deliveryMethod === 'fixed_line_vehicle'
                                ? $actualHandoffPackages
                                : 0,
                            'operator_id' => self::operatorId(),
                            'delivered_time' => $deliveredTime,
                        ],
                        $handoffNote
                    );
                    FulfillmentTaskLogic::refreshGroupForItem((int)$items[0]['id']);
                    return self::detailWithinTransaction($eventId);
                });
                if ($result === false) {
                    $committedReplay = self::replayAfterConcurrentCommit($idempotencyKey, $fingerprint);
                    if ($committedReplay !== false) {
                        self::clearError();
                        return $committedReplay;
                    }
                }
                return $result;
            } catch (\Throwable $exception) {
                $retryable = str_contains($exception->getMessage(), '1213')
                    || str_contains($exception->getMessage(), '1205');
                if ($retryable && $attempt < 2) {
                    self::clearError();
                    usleep(20000 * ($attempt + 1));
                    continue;
                }
                $committedReplay = self::replayAfterConcurrentCommit($idempotencyKey, $fingerprint);
                if ($committedReplay !== false) {
                    self::clearError();
                    return $committedReplay;
                }
                if (!self::hasError()) {
                    self::setError($deliveryMethod === 'fixed_line_vehicle'
                        ? '固定线车交接确认失败'
                        : '自配送交付确认失败');
                }
                return false;
            }
        }
        self::setError($deliveryMethod === 'fixed_line_vehicle'
            ? '固定线车交接确认失败'
            : '自配送交付确认失败');
        return false;
    }

    public static function hasCompletedDelivery(int $reportId): bool
    {
        return Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
            ->where('report_id', $reportId)->where('status', 'completed')->count() > 0;
    }

    /** @return array<string,mixed>|false */
    public static function detail(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.confirm')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $result = self::detailWithinTransaction((int)($params['id'] ?? 0));
        if ($result === false) {
            self::setError('交付事件不存在');
        }
        return $result;
    }

    /** @return array<string,mixed>|false */
    private static function detailWithinTransaction(int $eventId): array|false
    {
        $event = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
            ->where('id', $eventId)->find();
        if (!$event) {
            return false;
        }
        $event['id'] = (int)$event['id'];
        $event['report_id'] = (int)$event['report_id'];
        $event['task_id'] = (int)$event['task_id'];
        $event['trip_id'] = (int)($event['trip_id'] ?? 0);
        $event['trip_report_id'] = (int)($event['trip_report_id'] ?? 0);
        $event['line_schedule_id'] = (int)($event['line_schedule_id'] ?? 0);
        $event['driver_id'] = (int)($event['driver_id'] ?? 0);
        $event['actual_handoff_time'] = (int)($event['actual_handoff_time'] ?? 0);
        $event['driver'] = !empty($event['driver_snapshot'])
            ? (json_decode((string)$event['driver_snapshot'], true) ?: null)
            : null;
        unset($event['driver_snapshot']);
        $event['items'] = Db::name('fulfillment_delivery_item')->where('tenant_id', self::tenantId())
            ->where('delivery_event_id', $eventId)->order('id')->select()->toArray();
        foreach ($event['items'] as &$item) {
            $item['id'] = (int)$item['id'];
            $item['sales_order_id'] = (int)$item['sales_order_id'];
            $item['report_item_id'] = (int)$item['report_item_id'];
            foreach (['actual_delivery_weight', 'loss_weight', 'undelivered_weight', 'reservation_consumed_qty', 'reservation_released_qty', 'negative_qty'] as $field) {
                $item[$field] = self::decimal((string)($item[$field] ?? '0'));
            }
        }
        unset($item);
        return $event;
    }

    /** @return array<string,mixed>|false */
    private static function replayAfterConcurrentCommit(string $idempotencyKey, string $fingerprint): array|false
    {
        $event = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
            ->where('idempotency_key', $idempotencyKey)->find();
        if (!$event || !hash_equals((string)$event['request_fingerprint'], $fingerprint)) {
            return false;
        }
        return self::detailWithinTransaction((int)$event['id']);
    }

    private static function decimal(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }

    private static function timestamp(string $value): int|false
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date && $date->format('Y-m-d H:i:s') === $value ? $date->getTimestamp() : false;
    }

    private static function packageExceptionSummary(string $packed, string $loaded, string $handoff): string
    {
        $parts = [];
        foreach (['打包' => $packed, '装车' => $loaded, '交接' => $handoff] as $stage => $note) {
            $note = trim($note);
            if ($note !== '') {
                $parts[] = $stage . '：' . $note;
            }
        }
        return implode('；', $parts);
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
