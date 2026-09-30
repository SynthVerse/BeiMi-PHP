<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 分批交付、第三方司机交接和运输损耗的统一原子事实。 */
final class DeliveryVariantLogic extends BaseLogic
{
    private const SCALE = 4;

    /** @return array<string,mixed>|false */
    public static function confirm(array $params, string $deliveryMethod, string $requiredEventType): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.confirm')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $items = self::normalizeRequestedItems($params['items'] ?? null);
        if ($items === false) {
            return false;
        }
        $requestedDelivered = '0.0000';
        foreach ($items as $requestedItem) {
            $requestedDelivered = bcadd(
                $requestedDelivered,
                (string)$requestedItem['actual_delivery_weight'],
                self::SCALE
            );
        }
        $hasActualDelivery = bccomp($requestedDelivered, '0.0000', self::SCALE) > 0;
        $taskId = (int)($params['task_id'] ?? 0);
        $tripReportId = $deliveryMethod === 'fixed_line_vehicle' ? (int)($params['trip_report_id'] ?? 0) : 0;
        $driverId = $deliveryMethod === 'third_party' ? (int)($params['driver_id'] ?? 0) : 0;
        $eventType = $hasActualDelivery
            ? trim((string)($params['event_type'] ?? ''))
            : 'delivery_exception';
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        $handoffNote = mb_substr(trim((string)($params[
            $deliveryMethod === 'fixed_line_vehicle' ? 'exception_note' : 'handoff_note'
        ] ?? '')), 0, 500);
        $exceptionReason = mb_substr(trim((string)($params['exception_reason'] ?? '')), 0, 500);
        $secondConfirmed = (int)($params['second_confirmed'] ?? 0) === 1 ? 1 : 0;
        $actualPackages = $deliveryMethod === 'fixed_line_vehicle' && $hasActualDelivery
            ? (int)($params['actual_handoff_packages'] ?? 0) : 0;
        $handoffTime = $hasActualDelivery
            && in_array($deliveryMethod, ['fixed_line_vehicle', 'third_party'], true)
            ? self::timestamp((string)($params['actual_handoff_time'] ?? '')) : FulfillmentClock::now();

        if ($deliveryMethod === 'fixed_line_vehicle') {
            $reference = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('id', $tripReportId)->field('id,delivery_task_id')->find();
            if (!$reference) {
                self::setError('送站报货单不存在');
                return false;
            }
            $taskId = (int)$reference['delivery_task_id'];
            if ($hasActualDelivery && ($actualPackages <= 0 || $handoffTime === false)) {
                self::setError('实际交接包数和时间不能为空');
                return false;
            }
        }
        if ($deliveryMethod === 'third_party'
            && ($driverId <= 0 || ($hasActualDelivery && $handoffTime === false))) {
            self::setError($hasActualDelivery ? '第三方司机和实际交接时间不能为空' : '第三方司机不能为空');
            return false;
        }
        if ($hasActualDelivery && ($handoffTime === false || $handoffTime > FulfillmentClock::now())) {
            self::setError('实际交接时间不能晚于当前时间');
            return false;
        }
        if ($taskId <= 0 || $idempotencyKey === '' || mb_strlen($idempotencyKey) > 96) {
            self::setError('送货任务和交付幂等键不能为空');
            return false;
        }
        if ($hasActualDelivery && $eventType !== $requiredEventType) {
            self::setError(match ($deliveryMethod) {
                'fixed_line_vehicle' => '固定线车只有实际交给指定线车才是交付事件，门店出车不能出库',
                'third_party' => '第三方即时配送只有交给已登记司机才是交付事件',
                default => '自配送只有实际交给客户才是交付事件，车辆离店不能出库',
            });
            return false;
        }
        $fingerprint = hash('sha256', json_encode([
            'task_id' => $taskId,
            'trip_report_id' => $tripReportId,
            'driver_id' => $driverId,
            'delivery_method' => $deliveryMethod,
            'event_type' => $eventType,
            'actual_handoff_packages' => $actualPackages,
            // Self-delivery handoff and zero-delivery exception clocks are generated
            // by the server. They must not make an idempotency fingerprint vary when
            // concurrent retries cross a second boundary.
            'actual_handoff_time' => $hasActualDelivery
                && in_array($deliveryMethod, ['fixed_line_vehicle', 'third_party'], true)
                ? $handoffTime : 0,
            'handoff_note' => $handoffNote,
            'exception_reason' => $exceptionReason,
            'second_confirmed' => $secondConfirmed,
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = Db::transaction(static function () use (
                    $taskId, $tripReportId, $driverId, $deliveryMethod, $eventType, $actualPackages,
                    $handoffTime, $handoffNote, $exceptionReason, $secondConfirmed,
                    $idempotencyKey, $fingerprint, $items, $hasActualDelivery
                ) {
                    FinanceIntegration::lock();
                    $existing = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
                        ->where('idempotency_key', $idempotencyKey)->find();
                    if ($existing) {
                        return self::replay($existing, $fingerprint);
                    }

                    $trip = null;
                    $tripReport = null;
                    if ($deliveryMethod === 'fixed_line_vehicle') {
                        $locked = $hasActualDelivery
                            ? self::lockFixedLineHandoff(
                                $tripReportId,
                                $actualPackages,
                                (int)$handoffTime,
                                $handoffNote
                            )
                            : self::lockFixedLineException($tripReportId);
                        if ($locked === false) {
                            return false;
                        }
                        [$trip, $tripReport] = $locked;
                    }

                    $driver = null;
                    if ($deliveryMethod === 'third_party') {
                        $driver = Db::name('third_party_driver')->where('tenant_id', self::tenantId())
                            ->where('id', $driverId)->where('is_enabled', 1)->lock(true)->find();
                        if (!$driver) {
                            self::setError('第三方司机不存在或已停用');
                            return false;
                        }
                    }

                    $resolvedTaskId = $tripReport ? (int)$tripReport['delivery_task_id'] : $taskId;
                    $taskRef = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                        ->where('id', $resolvedTaskId)->field('id,report_id')->find();
                    if (!$taskRef) {
                        self::setError('送货任务不存在');
                        return false;
                    }
                    if ($deliveryMethod !== 'fixed_line_vehicle'
                        && !self::lockAlternateDeliveryAssignment((int)$taskRef['report_id'])) {
                        return false;
                    }
                    $report = Db::name('customer_report')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$taskRef['report_id'])->whereNull('delete_time')->lock(true)->find();
                    if (!$report) {
                        self::setError('关联报货单不存在');
                        return false;
                    }
                    if ($deliveryMethod !== 'fixed_line_vehicle'
                        && self::hasActiveLineAssignment((int)$report['id'])) {
                        self::setError('报货单仍在活动的固定线趟次中，必须先记录改派');
                        return false;
                    }
                    $allItems = Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                        ->where('report_id', (int)$report['id'])->whereNull('delete_time')
                        ->order(['sku_id' => 'asc', 'goods_id' => 'asc', 'warehouse_id' => 'asc', 'id' => 'asc'])
                        ->lock(true)->select()->toArray();
                    $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                        ->where('id', $resolvedTaskId)->lock(true)->find();
                    if (!$task || (string)$task['source_key'] !== 'report:' . (int)$report['id'] . ':delivery') {
                        self::setError('送货任务不存在');
                        return false;
                    }
                    $replayed = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
                        ->where('idempotency_key', $idempotencyKey)->find();
                    if ($replayed) {
                        return self::replay($replayed, $fingerprint);
                    }
                    if ((string)$task['status'] === 'blocked') {
                        self::setError('前置工序尚未完成，不能确认交付');
                        return false;
                    }
                    if (in_array((string)$task['status'], ['cancelled', 'completed'], true)) {
                        self::setError('送货任务当前不能确认交付');
                        return false;
                    }

                    $work = self::buildWorkItems($allItems, $items);
                    if ($work === false) {
                        return false;
                    }
                    $eventDelivered = '0.0000';
                    $eventLoss = '0.0000';
                    $eventUndelivered = '0.0000';
                    $eventLossPackages = 0;
                    foreach ($work as $row) {
                        $eventDelivered = bcadd($eventDelivered, (string)$row['actual_delivery_weight'], self::SCALE);
                        $eventLoss = bcadd($eventLoss, (string)$row['loss_weight'], self::SCALE);
                        $eventUndelivered = bcadd($eventUndelivered, (string)$row['undelivered_weight'], self::SCALE);
                        $eventLossPackages += (int)$row['loss_package_count'];
                    }
                    if (bccomp($eventDelivered, '0.0000', self::SCALE) <= 0
                        && bccomp($eventLoss, '0.0000', self::SCALE) <= 0
                        && bccomp($eventUndelivered, '0.0000', self::SCALE) <= 0
                        && $eventLossPackages <= 0) {
                        self::setError('交付确认必须包含实际交付、运输损耗或明确未交货事实');
                        return false;
                    }
                    $hasActualDelivery = bccomp($eventDelivered, '0.0000', self::SCALE) > 0;

                    $now = FulfillmentClock::now();
                    $factTime = $hasActualDelivery ? (int)$handoffTime : $now;
                    $driverSnapshot = $driver ? json_encode([
                        'id' => (int)$driver['id'], 'name' => (string)$driver['name'],
                        'mobile' => (string)$driver['mobile'], 'platform' => (string)$driver['platform'],
                        'vehicle_no' => (string)$driver['vehicle_no'], 'version' => (int)$driver['version'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                    $eventId = (int)Db::name('fulfillment_delivery_event')->insertGetId([
                        'tenant_id' => self::tenantId(), 'report_id' => (int)$report['id'],
                        'task_id' => $resolvedTaskId, 'trip_id' => $trip ? (int)$trip['id'] : 0,
                        'trip_report_id' => $tripReport ? (int)$tripReport['id'] : 0,
                        'line_schedule_id' => $tripReport ? (int)$tripReport['schedule_id'] : 0,
                        'driver_id' => $driver ? (int)$driver['id'] : 0, 'driver_snapshot' => $driverSnapshot,
                        'actual_handoff_time' => $hasActualDelivery ? (int)$handoffTime : 0,
                        'delivery_outcome' => $hasActualDelivery ? 'partial' : 'handled_without_delivery',
                        'delivery_method' => $deliveryMethod,
                        'delivery_arrangement_snapshot' => (string)($report['delivery_arrangement_snapshot'] ?? ''),
                        'event_type' => $hasActualDelivery ? $eventType : 'delivery_exception',
                        'status' => $hasActualDelivery ? 'completed' : 'handled_without_delivery',
                        'idempotency_key' => $idempotencyKey, 'request_fingerprint' => $fingerprint,
                        'handoff_note' => $handoffNote, 'exception_reason' => $exceptionReason,
                        'second_confirmed' => $secondConfirmed, 'operator_id' => self::operatorId(),
                        'delivered_time' => $hasActualDelivery ? (int)$handoffTime : 0,
                        'create_time' => $now, 'update_time' => $now,
                    ]);
                    if ($eventId <= 0) {
                        throw new \RuntimeException('delivery_variant_event_insert_failed');
                    }

                    $orders = [];
                    $negativeRows = [];
                    foreach ($work as $row) {
                        $item = $row['item'];
                        $itemId = (int)$item['id'];
                        $reservation = Db::name('customer_report_reservation')->where('tenant_id', self::tenantId())
                            ->where('report_item_id', $itemId)->lock(true)->find();
                        if (!$reservation) {
                            throw new \RuntimeException('delivery_variant_reservation_missing');
                        }
                        $warehouseId = (int)$item['warehouse_id'];
                        $order = null;
                        $currentReserved = self::decimal((string)$reservation['reserved_base_qty']);
                        $consumed = '0.0000';
                        $released = '0.0000';
                        $beforeAvailable = null;
                        $afterAvailable = null;

                        $actual = (string)$row['actual_delivery_weight'];
                        if (bccomp($actual, '0.0000', self::SCALE) > 0) {
                            if (!isset($orders[$warehouseId])) {
                                $orders[$warehouseId] = SalesOrderLogic::ensurePendingDeliveryOrderWithinTransaction(
                                    $report,
                                    $warehouseId
                                );
                                if ($orders[$warehouseId] === false) {
                                    self::setError(SalesOrderLogic::getError());
                                    throw new \RuntimeException('delivery_variant_sales_order_failed');
                                }
                            }
                            $order = $orders[$warehouseId];
                            $reserveForActual = self::minimum($currentReserved, $actual);
                            $movement = StockService::outboundDeliveryWithinTransaction(
                                $warehouseId, (int)$item['goods_id'], (int)$item['sku_id'],
                                $actual, $reserveForActual, (int)$order['id'], (string)$order['order_sn'], $eventId
                            );
                            if ($movement === false) {
                                throw new \RuntimeException('delivery_variant_stock_failed');
                            }
                            $consumed = bcadd($consumed, (string)$movement['reservation_consumed_qty'], self::SCALE);
                            $released = bcadd($released, (string)$movement['reservation_released_qty'], self::SCALE);
                            $beforeAvailable ??= (string)$movement['before_available_qty'];
                            $afterAvailable = (string)$movement['after_available_qty'];
                            $currentReserved = bcsub($currentReserved, $reserveForActual, self::SCALE);
                            if (!SalesOrderLogic::recordDeliveredItemWithinTransaction((int)$order['id'], $item, $actual)) {
                                throw new \RuntimeException('delivery_variant_order_item_failed');
                            }
                        }

                        $loss = (string)$row['loss_weight'];
                        if (bccomp($loss, '0.0000', self::SCALE) > 0) {
                            $reserveForLoss = self::minimum($currentReserved, $loss);
                            $lossMovement = StockService::outboundTransportLossWithinTransaction(
                                $warehouseId, (int)$item['goods_id'], (int)$item['sku_id'],
                                $loss, $reserveForLoss, $eventId
                            );
                            if ($lossMovement === false) {
                                throw new \RuntimeException('delivery_variant_loss_stock_failed');
                            }
                            $consumed = bcadd($consumed, (string)$lossMovement['reservation_consumed_qty'], self::SCALE);
                            $released = bcadd($released, (string)$lossMovement['reservation_released_qty'], self::SCALE);
                            $beforeAvailable ??= (string)$lossMovement['before_available_qty'];
                            $afterAvailable = (string)$lossMovement['after_available_qty'];
                            $currentReserved = bcsub($currentReserved, $reserveForLoss, self::SCALE);
                        }

                        if ((string)$row['remaining_action'] !== 'pending'
                            && bccomp($currentReserved, '0.0000', self::SCALE) > 0) {
                            $releaseMovement = StockService::releaseDeliveryReservationWithinTransaction(
                                $warehouseId, (int)$item['sku_id'], $currentReserved,
                                $order ? (int)$order['id'] : 0,
                                $order ? (string)$order['order_sn'] : 'DELIVERY-EXCEPTION-' . $eventId,
                                $eventId
                            );
                            if ($releaseMovement === false) {
                                throw new \RuntimeException('delivery_variant_release_failed');
                            }
                            $beforeAvailable ??= (string)$releaseMovement['before_available_qty'];
                            $afterAvailable = (string)$releaseMovement['after_available_qty'];
                            $released = bcadd($released, $currentReserved, self::SCALE);
                            $currentReserved = '0.0000';
                        }
                        $negativeQty = self::negativeDelta(
                            (string)($beforeAvailable ?? '0.0000'),
                            (string)($afterAvailable ?? $beforeAvailable ?? '0.0000')
                        );

                        $deliveryItemId = (int)Db::name('fulfillment_delivery_item')->insertGetId([
                            'tenant_id' => self::tenantId(), 'delivery_event_id' => $eventId,
                            'sales_order_id' => $order ? (int)$order['id'] : 0,
                            'report_id' => (int)$report['id'],
                            'report_item_id' => $itemId, 'warehouse_id' => $warehouseId,
                            'goods_id' => (int)$item['goods_id'], 'sku_id' => (int)$item['sku_id'],
                            'actual_delivery_weight' => $actual, 'loss_weight' => $loss,
                            'undelivered_weight' => (string)$row['undelivered_weight'],
                            'remaining_action' => (string)$row['remaining_action'],
                            'reservation_consumed_qty' => $consumed, 'reservation_released_qty' => $released,
                            'negative_qty' => $negativeQty, 'create_time' => $now,
                        ]);
                        if ($deliveryItemId <= 0) {
                            throw new \RuntimeException('delivery_variant_item_insert_failed');
                        }

                        $hasLoss = bccomp($loss, '0.0000', self::SCALE) > 0
                            || (int)$row['loss_package_count'] > 0;
                        if ($hasLoss) {
                            if (Db::name('fulfillment_delivery_loss')->insert([
                                'tenant_id' => self::tenantId(), 'delivery_event_id' => $eventId,
                                'delivery_item_id' => $deliveryItemId, 'report_id' => (int)$report['id'],
                                'report_item_id' => $itemId, 'warehouse_id' => $warehouseId,
                                'goods_id' => (int)$item['goods_id'], 'sku_id' => (int)$item['sku_id'],
                                'loss_weight' => $loss, 'loss_package_count' => (int)$row['loss_package_count'],
                                'reason' => (string)$row['loss_reason'], 'operator_id' => self::operatorId(),
                                'occurred_time' => $factTime, 'create_time' => $now,
                            ]) !== 1) {
                                throw new \RuntimeException('delivery_variant_loss_insert_failed');
                            }
                        }
                        $undelivered = (string)$row['undelivered_weight'];
                        if (bccomp($undelivered, '0.0000', self::SCALE) > 0) {
                            if (Db::name('fulfillment_delivery_remainder')->insert([
                                'tenant_id' => self::tenantId(), 'delivery_event_id' => $eventId,
                                'delivery_item_id' => $deliveryItemId, 'report_id' => (int)$report['id'],
                                'report_item_id' => $itemId, 'quantity' => $undelivered,
                                'action' => 'undelivered', 'reason_code' => (string)$row['undelivered_reason_code'],
                                'reason' => (string)$row['undelivered_reason'], 'operator_id' => self::operatorId(),
                                'action_time' => $factTime, 'create_time' => $now,
                            ]) !== 1) {
                                throw new \RuntimeException('delivery_variant_remainder_insert_failed');
                            }
                        }

                        Db::name('customer_report_reservation')->where('tenant_id', self::tenantId())
                            ->where('id', (int)$reservation['id'])->update([
                                'reserved_base_qty' => $currentReserved,
                                'consumed_base_qty' => bcadd((string)$reservation['consumed_base_qty'], $consumed, self::SCALE),
                                'released_base_qty' => bcadd((string)$reservation['released_base_qty'], $released, self::SCALE),
                                'status' => bccomp($currentReserved, '0.0000', self::SCALE) > 0 ? 'reserved' : 'fulfilled',
                                'update_time' => $now,
                            ]);

                        $nextDelivered = bcadd((string)$item['fulfilled_base_qty'], $actual, self::SCALE);
                        $nextLoss = bcadd((string)($item['delivery_loss_total_qty'] ?? '0'), $loss, self::SCALE);
                        $nextUndelivered = bcadd((string)($item['undelivered_total_qty'] ?? '0'), $undelivered, self::SCALE);
                        $remainingAfter = bcsub((string)$row['remaining_before'], bcadd($actual, bcadd($loss, $undelivered, self::SCALE), self::SCALE), self::SCALE);
                        $partial = (int)($item['has_partial_delivery'] ?? 0) === 1
                            || (string)$row['remaining_action'] !== 'none'
                            || $hasLoss
                            || bccomp($undelivered, '0.0000', self::SCALE) > 0
                            || bccomp((string)$item['fulfilled_base_qty'], '0.0000', self::SCALE) > 0;
                        if (bccomp($remainingAfter, '0.0000', self::SCALE) > 0) {
                            $itemStatus = 'partially_delivered_pending';
                        } elseif (bccomp($nextDelivered, '0.0000', self::SCALE) <= 0 && $hasLoss) {
                            $itemStatus = 'delivery_exception_completed';
                        } elseif (bccomp($nextDelivered, '0.0000', self::SCALE) <= 0
                            && bccomp($nextUndelivered, '0.0000', self::SCALE) > 0) {
                            $itemStatus = 'undelivered';
                        } else {
                            $itemStatus = $partial ? 'partially_delivered_completed' : 'delivered';
                        }
                        $itemUpdate = [
                            'reserved_base_qty' => $currentReserved, 'fulfilled_base_qty' => $nextDelivered,
                            'delivery_loss_total_qty' => $nextLoss, 'undelivered_total_qty' => $nextUndelivered,
                            'has_partial_delivery' => $partial ? 1 : 0, 'status' => $itemStatus,
                            'fulfillment_status' => $itemStatus, 'update_time' => $now,
                        ];
                        if (bccomp($undelivered, '0.0000', self::SCALE) > 0) {
                            $itemUpdate['undelivered_reason_code'] = (string)$row['undelivered_reason_code'];
                            $itemUpdate['undelivered_reason'] = (string)$row['undelivered_reason'];
                        }
                        Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                            ->where('id', $itemId)->update($itemUpdate);

                        if (bccomp($negativeQty, '0.0000', self::SCALE) > 0) {
                            $negativeRows[] = self::createNegativeAttribution(
                                $eventId, $deliveryItemId, $order, $item, $negativeQty,
                                $exceptionReason, $secondConfirmed, $now
                            );
                        }
                    }

                    self::assertNegativeThreshold($negativeRows, $exceptionReason, $secondConfirmed);

                    $freshItems = Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                        ->where('report_id', (int)$report['id'])->whereNull('delete_time')->order('id')->select()->toArray();
                    $unresolved = false;
                    $hasPartial = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
                        ->where('report_id', (int)$report['id'])->where('id', '<>', $eventId)->count() > 0;
                    $totalDelivered = '0.0000';
                    $totalLoss = '0.0000';
                    foreach ($freshItems as $fresh) {
                        $totalDelivered = bcadd($totalDelivered, (string)$fresh['fulfilled_base_qty'], self::SCALE);
                        $totalLoss = bcadd($totalLoss, (string)($fresh['delivery_loss_total_qty'] ?? '0'), self::SCALE);
                        $remaining = bcsub(
                            (string)$fresh['final_actual_weight'],
                            bcadd((string)$fresh['fulfilled_base_qty'], bcadd(
                                (string)($fresh['delivery_loss_total_qty'] ?? '0'),
                                (string)($fresh['undelivered_total_qty'] ?? '0'), self::SCALE
                            ), self::SCALE), self::SCALE
                        );
                        if ((string)$fresh['fulfillment_status'] !== 'undelivered'
                            && bccomp($remaining, '0.0000', self::SCALE) > 0) {
                            $unresolved = true;
                        }
                        if ((int)($fresh['has_partial_delivery'] ?? 0) === 1
                            || (string)$fresh['fulfillment_status'] === 'undelivered') {
                            $hasPartial = true;
                        }
                    }
                    $outcome = !$hasActualDelivery
                        ? 'handled_without_delivery'
                        : ($unresolved ? 'partial' : ($hasPartial ? 'partial_completed' : 'complete'));
                    Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())->where('id', $eventId)
                        ->update(['delivery_outcome' => $outcome, 'update_time' => $now]);
                    Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('id', $resolvedTaskId)->update([
                        'status' => $unresolved ? 'printable' : 'recovered',
                        'actual_weight' => $totalDelivered, 'process_weight' => $totalDelivered,
                        'recovery_note' => $handoffNote, 'recovered_by' => $unresolved ? 0 : self::operatorId(),
                        'recovered_time' => $unresolved ? 0 : $now, 'update_time' => $now,
                    ]);
                    $activeReserved = self::decimal((string)Db::name('customer_report_item')
                        ->where('tenant_id', self::tenantId())->where('report_id', (int)$report['id'])
                        ->whereNull('delete_time')->sum('reserved_base_qty'));
                    if ($unresolved) {
                        $reportStatus = 'partially_delivered_pending';
                    } elseif (bccomp($totalDelivered, '0.0000', self::SCALE) <= 0) {
                        $hasRecordedLoss = bccomp($totalLoss, '0.0000', self::SCALE) > 0
                            || Db::name('fulfillment_delivery_loss')->where('tenant_id', self::tenantId())
                                ->where('report_id', (int)$report['id'])->count() > 0;
                        $reportStatus = $hasRecordedLoss ? 'delivery_exception_completed' : 'undelivered';
                    } else {
                        $reportStatus = $hasPartial ? 'partial_pending_settlement' : 'delivered_pending_settlement';
                    }
                    Db::name('customer_report')->where('tenant_id', self::tenantId())->where('id', (int)$report['id'])->update([
                        'status' => $reportStatus,
                        'reserved_base_qty' => $activeReserved,
                        'shortage_base_qty' => $unresolved ? (string)$report['shortage_base_qty'] : '0.00',
                        'update_time' => $now,
                    ]);

                    if ($deliveryMethod === 'fixed_line_vehicle' && $trip && $tripReport) {
                        self::completeFixedLineProjection(
                            $trip, $tripReport, $eventId, $actualPackages,
                            $hasActualDelivery ? (int)$handoffTime : 0,
                            $handoffNote, $hasActualDelivery, $now
                        );
                    }
                    $auditAction = $hasActualDelivery
                        ? $deliveryMethod . '_handoff'
                        : ($deliveryMethod === 'fixed_line_vehicle'
                            ? 'line_vehicle_delivery_exception'
                            : ($deliveryMethod === 'third_party'
                                ? 'third_party_delivery_exception'
                                : 'self_delivery_exception'));
                    AuditService::logWithinTransaction(
                        'fulfillment_delivery',
                        $auditAction,
                        $eventId,
                        $idempotencyKey,
                        null, ['report_id' => (int)$report['id'], 'task_id' => $resolvedTaskId,
                            'delivery_outcome' => $outcome, 'actual_delivery_weight' => $eventDelivered,
                            'driver_id' => $driver ? (int)$driver['id'] : 0,
                            'trip_report_id' => $tripReport ? (int)$tripReport['id'] : 0,
                            'operator_id' => self::operatorId(),
                            'delivered_time' => $hasActualDelivery ? (int)$handoffTime : 0],
                        $handoffNote
                    );
                    FulfillmentTaskLogic::refreshGroupForItem((int)$work[0]['item']['id']);
                    return self::detailEvent($eventId);
                });
                if ($result === false) {
                    $committed = self::replayAfterCommit($idempotencyKey, $fingerprint);
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
                $committed = self::replayAfterCommit($idempotencyKey, $fingerprint);
                if ($committed !== false) {
                    self::clearError();
                    return $committed;
                }
                if (!self::hasError()) {
                    self::setError(isset($_SERVER['JXC_PHPUNIT_ENV'])
                        ? '交付确认失败，事务已回滚：' . $exception->getMessage()
                        : '交付确认失败，事务已回滚');
                }
                return false;
            }
        }
        self::setError('交付确认失败，事务已回滚');
        return false;
    }

    /** @return array<int,array<string,mixed>>|false */
    private static function normalizeRequestedItems(mixed $raw): array|false
    {
        if (!is_array($raw) || $raw === []) {
            self::setError('分批交付必须提交至少一条交付明细');
            return false;
        }
        $normalized = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                self::setError('交付明细格式不正确');
                return false;
            }
            $itemId = self::positiveInteger($row['report_item_id'] ?? null);
            $actual = self::inputDecimal($row['actual_delivery_weight'] ?? null);
            $loss = self::inputDecimal($row['loss_weight'] ?? '0');
            $action = trim((string)($row['remaining_action'] ?? 'none'));
            $lossPackages = self::nonNegativeInteger($row['loss_package_count'] ?? 0);
            $lossReason = mb_substr(trim((string)($row['loss_reason'] ?? '')), 0, 500);
            $undeliveredCode = trim((string)($row['undelivered_reason_code'] ?? ''));
            $undeliveredReason = mb_substr(trim((string)($row['undelivered_reason'] ?? '')), 0, 500);
            if ($itemId === false || $lossPackages === false || isset($seen[$itemId])
                || $actual === false || $loss === false
                || !in_array($action, ['none', 'pending', 'undelivered'], true)
            ) {
                self::setError('交付明细数量、余量处理或身份格式不正确');
                return false;
            }
            if ((bccomp($loss, '0.0000', self::SCALE) > 0 || $lossPackages > 0)
                && $lossReason === '') {
                self::setError('运输损耗必须填写损耗原因');
                return false;
            }
            if ($action === 'undelivered'
                && (!in_array($undeliveredCode, ['shortage', 'damaged', 'customer_cancelled'], true)
                    || $undeliveredReason === '')) {
                self::setError('剩余未交货必须选择原因并填写说明');
                return false;
            }
            $seen[$itemId] = true;
            $normalized[] = [
                'report_item_id' => $itemId, 'actual_delivery_weight' => $actual,
                'loss_weight' => $loss, 'loss_package_count' => $lossPackages,
                'loss_reason' => $lossReason, 'remaining_action' => $action,
                'undelivered_reason_code' => $undeliveredCode,
                'undelivered_reason' => $undeliveredReason,
            ];
        }
        usort($normalized, static fn(array $left, array $right): int =>
            $left['report_item_id'] <=> $right['report_item_id']
        );
        return $normalized;
    }

    /** @param array<int,array<string,mixed>> $allItems @param array<int,array<string,mixed>> $requested */
    private static function buildWorkItems(array $allItems, array $requested): array|false
    {
        $byId = [];
        foreach ($allItems as $item) {
            $byId[(int)$item['id']] = $item;
        }
        $work = [];
        foreach ($requested as $requestItem) {
            $itemId = (int)$requestItem['report_item_id'];
            $item = $byId[$itemId] ?? null;
            if (!$item || (string)$item['fulfillment_status'] === 'undelivered') {
                self::setError('交付明细不存在或已经明确未交货');
                return false;
            }
            if (!in_array((string)$item['fulfillment_status'], [
                'final_weight_recorded', 'partially_delivered_pending',
            ], true) || (int)$item['final_weight_task_id'] <= 0) {
                self::setError('每条交付明细必须先完成最终称重');
                return false;
            }
            $remaining = bcsub(
                self::decimal((string)$item['final_actual_weight']),
                bcadd(self::decimal((string)$item['fulfilled_base_qty']), bcadd(
                    self::decimal((string)($item['delivery_loss_total_qty'] ?? '0')),
                    self::decimal((string)($item['undelivered_total_qty'] ?? '0')), self::SCALE
                ), self::SCALE), self::SCALE
            );
            $handled = bcadd((string)$requestItem['actual_delivery_weight'], (string)$requestItem['loss_weight'], self::SCALE);
            if (bccomp($remaining, '0.0000', self::SCALE) <= 0
                || bccomp($handled, $remaining, self::SCALE) > 0) {
                self::setError('本次交付、损耗和未交货数量不能超过该明细剩余数量');
                return false;
            }
            $action = (string)$requestItem['remaining_action'];
            if ($action === 'none' && bccomp($handled, $remaining, self::SCALE) !== 0) {
                self::setError('未处理的剩余数量必须继续待配送或明确未交货');
                return false;
            }
            if ($action === 'pending' && bccomp($handled, $remaining, self::SCALE) >= 0) {
                self::setError('已全部处理的明细不能继续标记待配送');
                return false;
            }
            $undelivered = $action === 'undelivered' ? bcsub($remaining, $handled, self::SCALE) : '0.0000';
            if ($action === 'undelivered' && bccomp($undelivered, '0.0000', self::SCALE) <= 0) {
                self::setError('没有剩余数量时不能标记未交货');
                return false;
            }
            if (bccomp($handled, '0.0000', self::SCALE) <= 0
                && (int)$requestItem['loss_package_count'] <= 0
                && $action !== 'undelivered') {
                self::setError('每条交付明细必须包含实际交付、运输损耗或明确未交货事实');
                return false;
            }
            $work[] = $requestItem + ['item' => $item, 'remaining_before' => $remaining, 'undelivered_weight' => $undelivered];
        }
        usort($work, [self::class, 'compareWorkItems']);
        return $work;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function compareWorkItems(array $left, array $right): int
    {
        foreach (['sku_id', 'goods_id', 'warehouse_id', 'id'] as $field) {
            $comparison = (int)$left['item'][$field] <=> (int)$right['item'][$field];
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return 0;
    }

    private static function lockAlternateDeliveryAssignment(int $reportId): bool
    {
        $reference = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('active_report_id', $reportId)->field('id,trip_id')->find();
        if (!$reference) {
            return true;
        }
        Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
            ->where('id', (int)$reference['trip_id'])->lock(true)->find();
        $assignment = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('id', (int)$reference['id'])->lock(true)->find();
        if ($assignment && (int)($assignment['active_report_id'] ?? 0) === $reportId) {
            self::setError('报货单仍在活动的固定线趟次中，必须先记录改派');
            return false;
        }
        return true;
    }

    private static function hasActiveLineAssignment(int $reportId): bool
    {
        // The report row is already locked. A current read is required here because
        // the transaction may have opened an older repeatable-read snapshot while
        // checking its idempotency key.
        return Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('active_report_id', $reportId)->lock(true)->find() !== null;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|false */
    private static function lockFixedLineException(int $tripReportId): array|false
    {
        $reference = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('id', $tripReportId)->field('id,trip_id')->find();
        if (!$reference) {
            self::setError('送站报货单不存在');
            return false;
        }
        $trip = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
            ->where('id', (int)$reference['trip_id'])->lock(true)->find();
        $item = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('id', $tripReportId)->lock(true)->find();
        if (!$trip || !$item
            || !in_array((string)$trip['status'], ['planned', 'loading', 'departed'], true)
            || !in_array((string)$item['status'], ['planned', 'loading'], true)
            || $item['active_report_id'] === null
            || (int)$item['active_report_id'] !== (int)$item['report_id']) {
            self::setError('已改派或不再活动的旧趟次货物不能登记配送异常');
            return false;
        }
        return [$trip, $item];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|false */
    private static function lockFixedLineHandoff(int $tripReportId, int $packages, int $handoffTime, string $note): array|false
    {
        $reference = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('id', $tripReportId)->field('id,trip_id')->find();
        if (!$reference) {
            self::setError('送站报货单不存在');
            return false;
        }
        $trip = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
            ->where('id', (int)$reference['trip_id'])->lock(true)->find();
        $item = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('id', $tripReportId)->lock(true)->find();
        if (!$trip || !$item || (string)$trip['status'] !== 'departed'
            || (int)$trip['actual_store_departure_time'] <= 0) {
            self::setError('必须先回录门店实际出车时间');
            return false;
        }
        if ((string)$item['status'] !== 'loading' || $item['active_report_id'] === null
            || (int)$item['active_report_id'] !== (int)$item['report_id']) {
            self::setError('已改派或不再活动的旧趟次货物不能交接');
            return false;
        }
        if ((int)$item['loaded_checked'] !== 1) {
            self::setError('必须先完成装车包数核对');
            return false;
        }
        if (($packages !== (int)$item['expected_package_count']
            || $packages !== (int)$item['actual_loaded_package_count']) && $note === '') {
            self::setError('交接包数与应装或实际装车包数不一致时必须填写异常原因');
            return false;
        }
        if ($handoffTime < (int)$trip['actual_store_departure_time']) {
            self::setError('实际线车交接时间不能早于门店出车时间');
            return false;
        }
        if ($handoffTime > (int)$item['handoff_deadline']) {
            self::setError('已错过线车交接截止时间，不能标记交接完成，必须改派');
            return false;
        }
        return [$trip, $item];
    }

    /** @param array<string,mixed>|null $order @param array<string,mixed> $item @return array<string,mixed> */
    private static function createNegativeAttribution(
        int $eventId, int $deliveryItemId, ?array $order, array $item, string $negativeQty,
        string $explanation, int $confirmed, int $now
    ): array {
        $unitCost = bcadd((string)Db::name('goods')->where('tenant_id', self::tenantId())
            ->where('id', (int)$item['goods_id'])->value('cost'), '0', 2);
        $negativeAmount = bcmul($negativeQty, $unitCost, 2);
        $costStatus = bccomp($unitCost, '0.00', 2) > 0 ? 'confirmed' : 'pending';
        $id = (int)Db::name('negative_inventory_attribution')->insertGetId([
            'tenant_id' => self::tenantId(), 'delivery_event_id' => $eventId,
            'delivery_item_id' => $deliveryItemId,
            'sales_order_id' => $order ? (int)$order['id'] : 0,
            'report_id' => (int)$item['report_id'], 'report_item_id' => (int)$item['id'],
            'warehouse_id' => (int)$item['warehouse_id'], 'goods_id' => (int)$item['goods_id'],
            'sku_id' => (int)$item['sku_id'], 'negative_qty' => $negativeQty,
            'negative_amount' => $negativeAmount, 'remaining_qty' => $negativeQty,
            'reason' => $order
                ? '实际交付或运输损耗超过可用库存'
                : '运输损耗超过可用库存',
            'threshold_explanation' => $explanation,
            'threshold_confirmed' => $confirmed, 'resolution_status' => 'open',
            'cost_status' => $costStatus, 'operator_id' => self::operatorId(),
            'occurred_time' => $now, 'resolved_time' => 0, 'update_time' => $now,
        ]);
        if ($id <= 0 || Db::name('negative_inventory_todo')->insert([
            'tenant_id' => self::tenantId(), 'attribution_id' => $id, 'status' => 'open',
            'severity' => 'red', 'assignee_scope' => 'highest_privilege',
            'create_time' => $now, 'update_time' => $now,
        ]) !== 1) {
            throw new \RuntimeException('delivery_variant_negative_source_failed');
        }
        if ($costStatus === 'pending' && $order) {
            SalesOrderLogic::markCostPendingWithinTransaction((int)$order['id']);
        }
        return ['attribution_id' => $id, 'unit_cost' => $unitCost];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function assertNegativeThreshold(array $rows, string $explanation, int $confirmed): void
    {
        $setting = Db::name('negative_inventory_setting')->where('tenant_id', self::tenantId())->lock(true)->find();
        $quantityThreshold = self::decimal((string)($setting['quantity_threshold'] ?? '999999999.0000'));
        $amountThreshold = bcadd((string)($setting['amount_threshold'] ?? '999999999.00'), '0', 2);
        $quantity = '0.0000';
        $amount = '0.00';
        foreach ($rows as $row) {
            $remaining = self::decimal((string)Db::name('negative_inventory_attribution')
                ->where('tenant_id', self::tenantId())->where('id', (int)$row['attribution_id'])->value('remaining_qty'));
            if (bccomp($remaining, '0.0000', self::SCALE) <= 0) {
                continue;
            }
            $quantity = bcadd($quantity, $remaining, self::SCALE);
            $amount = bcadd($amount, bcmul($remaining, (string)$row['unit_cost'], 2), 2);
        }
        if ((bccomp($quantity, $quantityThreshold, self::SCALE) > 0
                || bccomp($amount, $amountThreshold, 2) > 0)
            && ($explanation === '' || $confirmed !== 1)) {
            self::setError('负库存超过配置阈值，必须填写说明并二次确认');
            throw new \RuntimeException('delivery_variant_negative_threshold');
        }
    }

    /** @param array<string,mixed> $trip @param array<string,mixed> $item */
    private static function completeFixedLineProjection(
        array $trip,
        array $item,
        int $eventId,
        int $packages,
        int $handoffTime,
        string $note,
        bool $hasActualDelivery,
        int $now
    ): void {
        if (!$hasActualDelivery) {
            $exceptionNote = $note !== '' ? $note : '未发生实际线车交接，已按损耗或未交货关闭';
            Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('id', (int)$item['id'])->update([
                    'active_report_id' => null,
                    'actual_handoff_package_count' => 0,
                    'handoff_checked' => 0,
                    'actual_handoff_time' => 0,
                    'handoff_exception_note' => $exceptionNote,
                    'package_exception_note' => mb_substr(self::packageExceptionSummary(
                        (string)($item['packed_exception_note'] ?? ''),
                        (string)($item['loaded_exception_note'] ?? ''),
                        $exceptionNote
                    ), 0, 500),
                    'status' => 'delivery_exception',
                    'delivery_event_id' => $eventId,
                    'operator_id' => self::operatorId(),
                    'update_time' => $now,
                ]);
            $remaining = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('trip_id', (int)$trip['id'])->whereNotNull('active_report_id')->count();
            if ($remaining === 0) {
                Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                    ->where('id', (int)$trip['id'])->update([
                        'status' => 'closed',
                        'operator_id' => self::operatorId(),
                        'update_time' => $now,
                    ]);
            }
            return;
        }
        Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())->where('id', (int)$item['id'])->update([
            'active_report_id' => null, 'actual_handoff_package_count' => $packages,
            'handoff_checked' => 1, 'actual_handoff_time' => $handoffTime,
            'handoff_exception_note' => $note,
            'package_exception_note' => mb_substr(self::packageExceptionSummary(
                (string)($item['packed_exception_note'] ?? ''),
                (string)($item['loaded_exception_note'] ?? ''), $note
            ), 0, 500),
            'status' => 'handed_over', 'delivery_event_id' => $eventId,
            'operator_id' => self::operatorId(), 'update_time' => $now,
        ]);
        $remaining = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('trip_id', (int)$trip['id'])->whereNotNull('active_report_id')->count();
        if ($remaining === 0) {
            $rerouted = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('trip_id', (int)$trip['id'])->where('status', 'rerouted')->count();
            Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())->where('id', (int)$trip['id'])->update([
                'status' => $rerouted > 0 ? 'closed' : 'completed',
                'operator_id' => self::operatorId(), 'update_time' => $now,
            ]);
        }
    }

    /** @param array<string,mixed> $existing @return array<string,mixed>|false */
    private static function replay(array $existing, string $fingerprint): array|false
    {
        if (!hash_equals((string)$existing['request_fingerprint'], $fingerprint)) {
            self::setError('同一幂等键不能提交不同的交付事实');
            return false;
        }
        return self::detailEvent((int)$existing['id']);
    }

    /** @return array<string,mixed>|false */
    private static function replayAfterCommit(string $key, string $fingerprint): array|false
    {
        $event = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
            ->where('idempotency_key', $key)->find();
        return $event ? self::replay($event, $fingerprint) : false;
    }

    /** @return array<string,mixed>|false */
    private static function detailEvent(int $eventId): array|false
    {
        $event = Db::name('fulfillment_delivery_event')->where('tenant_id', self::tenantId())
            ->where('id', $eventId)->find();
        if (!$event) {
            return false;
        }
        foreach (['id', 'report_id', 'task_id', 'trip_id', 'trip_report_id', 'line_schedule_id',
            'driver_id', 'actual_handoff_time', 'operator_id', 'delivered_time'] as $field) {
            $event[$field] = (int)($event[$field] ?? 0);
        }
        $event['driver'] = $event['driver_snapshot']
            ? (json_decode((string)$event['driver_snapshot'], true) ?: null) : null;
        unset($event['driver_snapshot']);
        $event['items'] = Db::name('fulfillment_delivery_item')->where('tenant_id', self::tenantId())
            ->where('delivery_event_id', $eventId)->order('id')->select()->toArray();
        foreach ($event['items'] as &$item) {
            foreach (['id', 'sales_order_id', 'report_item_id'] as $field) {
                $item[$field] = (int)$item[$field];
            }
            foreach (['actual_delivery_weight', 'loss_weight', 'undelivered_weight',
                'reservation_consumed_qty', 'reservation_released_qty', 'negative_qty'] as $field) {
                $item[$field] = self::decimal((string)($item[$field] ?? '0'));
            }
        }
        unset($item);
        return $event;
    }

    private static function inputDecimal(mixed $value): string|false
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 20 || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            return false;
        }
        return self::decimal($value);
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
        if (!is_string($value)
            || !preg_match($minimum > 0 ? '/^[1-9]\d*$/' : '/^(?:0|[1-9]\d*)$/', $value)) {
            return false;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => 4_294_967_295],
        ]);
        return $validated === false ? false : (int)$validated;
    }

    private static function decimal(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }

    private static function minimum(string $left, string $right): string
    {
        return bccomp($left, $right, self::SCALE) <= 0 ? $left : $right;
    }

    private static function negativeDelta(string $beforeAvailable, string $afterAvailable): string
    {
        $beforeNegative = bccomp($beforeAvailable, '0.0000', self::SCALE) < 0
            ? ltrim($beforeAvailable, '-') : '0.0000';
        $afterNegative = bccomp($afterAvailable, '0.0000', self::SCALE) < 0
            ? ltrim($afterAvailable, '-') : '0.0000';
        $delta = bcsub($afterNegative, $beforeNegative, self::SCALE);
        return bccomp($delta, '0.0000', self::SCALE) > 0 ? $delta : '0.0000';
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
            if (trim($note) !== '') {
                $parts[] = $stage . '：' . trim($note);
            }
        }
        return implode('；', $parts);
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
