<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 固定线车班次、门店送站趟次与纸质装车清单。 */
final class LineVehicleLogic extends BaseLogic
{
    /** @return array<string,mixed>|false */
    public static function saveSchedule(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.line.manage')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 120);
        $location = mb_substr(trim((string)($params['handoff_location'] ?? '')), 0, 255);
        $departureTime = trim((string)($params['departure_time'] ?? ''));
        $bufferMinutes = (int)($params['buffer_minutes'] ?? -1);
        $enabled = (int)($params['is_enabled'] ?? 1) === 1 ? 1 : 0;
        if ($name === '' || $location === '') {
            self::setError('线车名称和交接地点不能为空');
            return false;
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $departureTime)) {
            self::setError('线车发车时间必须使用 HH:MM');
            return false;
        }
        if ($bufferMinutes < 0 || $bufferMinutes > 1440) {
            self::setError('交接缓冲时间必须在 0 到 1440 分钟之间');
            return false;
        }
        $duplicate = Db::name('line_vehicle_schedule')->where('tenant_id', self::tenantId())
            ->where('name', $name);
        if ($id > 0) {
            $duplicate->where('id', '<>', $id);
        }
        if ($duplicate->find()) {
            self::setError('同名线车班次已存在');
            return false;
        }

        $now = time();
        if ($id <= 0) {
            $id = (int)Db::name('line_vehicle_schedule')->insertGetId([
                'tenant_id' => self::tenantId(),
                'name' => $name,
                'handoff_location' => $location,
                'departure_time' => $departureTime,
                'buffer_minutes' => $bufferMinutes,
                'is_enabled' => $enabled,
                'version' => 1,
                'operator_id' => self::operatorId(),
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } else {
            $schedule = Db::name('line_vehicle_schedule')->where('tenant_id', self::tenantId())
                ->where('id', $id)->find();
            if (!$schedule) {
                self::setError('线车班次不存在');
                return false;
            }
            $version = (int)($params['version'] ?? 0);
            if ($version <= 0 || $version !== (int)$schedule['version']) {
                self::setError('线车班次已被修改，请重新加载');
                return false;
            }
            $updated = Db::name('line_vehicle_schedule')->where('tenant_id', self::tenantId())
                ->where('id', $id)->where('version', $version)->update([
                    'name' => $name,
                    'handoff_location' => $location,
                    'departure_time' => $departureTime,
                    'buffer_minutes' => $bufferMinutes,
                    'is_enabled' => $enabled,
                    'version' => $version + 1,
                    'operator_id' => self::operatorId(),
                    'update_time' => $now,
                ]);
            if ($updated !== 1) {
                self::setError('线车班次已被修改，请重新加载');
                return false;
            }
        }
        return Db::name('line_vehicle_schedule')->where('tenant_id', self::tenantId())->where('id', $id)->find();
    }

    /** @return array{lists:array<int,array<string,mixed>>}|false */
    public static function schedules(array $params): array|false
    {
        self::clearError();
        if (!self::requireViewPermission()) {
            return false;
        }
        $query = Db::name('line_vehicle_schedule')->where('tenant_id', self::tenantId());
        if (isset($params['is_enabled']) && $params['is_enabled'] !== '') {
            $query->where('is_enabled', (int)$params['is_enabled'] === 1 ? 1 : 0);
        }
        return ['lists' => $query->order(['is_enabled' => 'desc', 'departure_time' => 'asc', 'id' => 'asc'])
            ->select()->toArray()];
    }

    /** @return array<string,mixed>|false */
    public static function createTrip(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.line.manage')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $tripDate = self::dateValue((string)($params['trip_date'] ?? ''));
        $plannedDeparture = self::dateTimeValue((string)($params['planned_store_departure_time'] ?? ''));
        $key = trim((string)($params['idempotency_key'] ?? ''));
        $reports = is_array($params['reports'] ?? null) ? array_values($params['reports']) : [];
        if ($tripDate === false || $plannedDeparture === false) {
            self::setError('趟次日期和门店计划出车时间格式不正确');
            return false;
        }
        if (date('Y-m-d', $plannedDeparture) !== $tripDate) {
            self::setError('门店计划出车时间必须属于趟次日期');
            return false;
        }
        if ($key === '' || mb_strlen($key) > 96 || $reports === []) {
            self::setError('趟次幂等键和报货单不能为空');
            return false;
        }
        foreach ($reports as $entry) {
            if (!is_array($entry)) {
                self::setError('每张送站报货单的班次、报货单和包数格式必须完整');
                return false;
            }
        }
        usort($reports, static fn(array $left, array $right): int => [
            (int)($left['schedule_id'] ?? 0), (int)($left['report_id'] ?? 0),
        ] <=> [
            (int)($right['schedule_id'] ?? 0), (int)($right['report_id'] ?? 0),
        ]);
        $normalized = [];
        $reportIds = [];
        foreach ($reports as $entry) {
            $scheduleId = (int)($entry['schedule_id'] ?? 0);
            $reportId = (int)($entry['report_id'] ?? 0);
            $packages = (int)($entry['expected_package_count'] ?? 0);
            if ($scheduleId <= 0 || $reportId <= 0 || $packages <= 0 || isset($reportIds[$reportId])) {
                self::setError('每张报货单必须指定唯一班次和大于零的应装包数');
                return false;
            }
            $reportIds[$reportId] = true;
            $normalized[] = [
                'schedule_id' => $scheduleId,
                'report_id' => $reportId,
                'expected_package_count' => $packages,
            ];
        }
        $fingerprint = hash('sha256', json_encode([
            'trip_date' => $tripDate,
            'planned_store_departure_time' => $plannedDeparture,
            'reports' => $normalized,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = Db::transaction(static function () use (
                $tripDate,
                $plannedDeparture,
                $key,
                $fingerprint,
                $normalized
            ) {
                $existing = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                    ->where('idempotency_key', $key)->find();
                if ($existing) {
                    if (!hash_equals((string)$existing['request_fingerprint'], $fingerprint)) {
                        self::setError('同一幂等键不能创建不同的送站趟次');
                        return false;
                    }
                    return self::tripDetailWithinTransaction((int)$existing['id']);
                }

                $scheduleIds = array_values(array_unique(array_column($normalized, 'schedule_id')));
                sort($scheduleIds, SORT_NUMERIC);
                $schedules = [];
                foreach ($scheduleIds as $scheduleId) {
                    $schedule = Db::name('line_vehicle_schedule')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$scheduleId)->where('is_enabled', 1)->lock(true)->find();
                    if (!$schedule) {
                        self::setError('线车班次不存在或已停用');
                        return false;
                    }
                    $schedules[(int)$scheduleId] = $schedule;
                }
                $reportEntries = $normalized;
                usort($reportEntries, static fn(array $left, array $right): int =>
                    (int)$left['report_id'] <=> (int)$right['report_id']);
                $snapshots = [];
                foreach ($reportEntries as $entry) {
                    $schedule = $schedules[(int)$entry['schedule_id']];
                    $report = Db::name('customer_report')->where('tenant_id', self::tenantId())
                        ->where('id', $entry['report_id'])->whereNull('delete_time')->lock(true)->find();
                    if (!$report || in_array((string)$report['status'], [
                        'cancelled', 'completed', 'delivered_pending_settlement', 'partial_pending_settlement',
                    ], true)) {
                        self::setError('报货单不存在或当前不能加入送站趟次');
                        return false;
                    }
                    if ((string)$report['delivery_date'] !== $tripDate) {
                        self::setError('报货单送货日期必须与送站趟次日期一致');
                        return false;
                    }
                    $activeAssignment = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                        ->where('active_report_id', (int)$report['id'])->find();
                    if ($activeAssignment) {
                        self::setError('报货单已在其他未完成送站趟次中');
                        return false;
                    }
                    $task = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                        ->where('report_id', (int)$report['id'])
                        ->where('source_key', 'report:' . (int)$report['id'] . ':delivery')->lock(true)->find();
                    if (!$task || in_array((string)$task['status'], ['blocked', 'cancelled', 'completed'], true)) {
                        self::setError('报货单尚未具备送站条件');
                        return false;
                    }
                    $deliveryItem = Db::name('customer_report_item')->where('tenant_id', self::tenantId())
                        ->where('report_id', (int)$report['id'])->whereNull('delete_time')->order('id')->lock(true)->find();
                    if (!$deliveryItem) {
                        self::setError('报货单没有可送站明细');
                        return false;
                    }
                    $lineDeparture = self::scheduleTimestamp($tripDate, (string)$schedule['departure_time']);
                    $deadline = $lineDeparture - ((int)$schedule['buffer_minutes'] * 60);
                    $snapshots[] = compact('entry', 'schedule', 'report', 'task', 'deliveryItem', 'lineDeparture', 'deadline');
                }

                // 无记录的幂等键不能被锁定；取得班次与报货单稳定业务锁后必须再次重放。
                $replayed = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                    ->where('idempotency_key', $key)->find();
                if ($replayed) {
                    if (!hash_equals((string)$replayed['request_fingerprint'], $fingerprint)) {
                        self::setError('同一幂等键不能创建不同的送站趟次');
                        return false;
                    }
                    return self::tripDetailWithinTransaction((int)$replayed['id']);
                }

                $now = time();
                $tripNo = 'ST' . str_replace('-', '', $tripDate) . '-' . strtoupper(substr(hash('sha256', $key), 0, 10));
                $tripId = (int)Db::name('line_vehicle_trip')->insertGetId([
                    'tenant_id' => self::tenantId(),
                    'trip_no' => $tripNo,
                    'trip_date' => $tripDate,
                    'planned_store_departure_time' => $plannedDeparture,
                    'actual_store_departure_time' => 0,
                    'status' => 'planned',
                    'idempotency_key' => $key,
                    'request_fingerprint' => $fingerprint,
                    'operator_id' => self::operatorId(),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                if ($tripId <= 0) {
                    throw new \RuntimeException('line_vehicle_trip_insert_failed');
                }
                foreach ($snapshots as $snapshot) {
                    $entry = $snapshot['entry'];
                    $schedule = $snapshot['schedule'];
                    $report = $snapshot['report'];
                    $task = $snapshot['task'];
                    $deliveryItem = $snapshot['deliveryItem'];
                    $inserted = Db::name('line_vehicle_trip_report')->insert([
                        'tenant_id' => self::tenantId(),
                        'trip_id' => $tripId,
                        'schedule_id' => (int)$schedule['id'],
                        'report_id' => (int)$report['id'],
                        'active_report_id' => (int)$report['id'],
                        'delivery_task_id' => (int)$task['id'],
                        'line_name_snapshot' => (string)$schedule['name'],
                        'handoff_location_snapshot' => (string)$schedule['handoff_location'],
                        'line_departure_time' => (int)$snapshot['lineDeparture'],
                        'handoff_deadline' => (int)$snapshot['deadline'],
                        'main_customer_id' => (int)$report['main_customer_id'],
                        'main_customer_name' => (string)$report['main_customer_name'],
                        'delivery_customer_id' => (int)$deliveryItem['delivery_customer_id'],
                        'delivery_customer_name' => (string)$deliveryItem['delivery_customer_name'],
                        'report_sn' => (string)$report['sn'],
                        'expected_package_count' => (int)$entry['expected_package_count'],
                        'status' => 'planned',
                        'operator_id' => self::operatorId(),
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    if ($inserted !== 1) {
                        throw new \RuntimeException('line_vehicle_trip_report_insert_failed');
                    }
                }
                AuditService::logWithinTransaction('line_vehicle', 'trip_create', $tripId, $key, null, [
                    'trip_no' => $tripNo,
                    'trip_date' => $tripDate,
                    'planned_store_departure_time' => $plannedDeparture,
                    'report_count' => count($snapshots),
                ], '创建门店送站趟次');
                return self::tripDetailWithinTransaction($tripId);
                });
                if ($result === false) {
                    return false;
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
                $replayed = self::replayTripAfterConcurrentCommit($key, $fingerprint);
                if ($replayed !== false) {
                    self::clearError();
                    return $replayed;
                }
                if (str_contains($exception->getMessage(), 'uk_tenant_line_vehicle_active_report')) {
                    self::setError('报货单已在其他未完成送站趟次中');
                    return false;
                }
                if (self::getError() === '') {
                    self::setError('门店送站趟次创建失败');
                }
                return false;
            }
        }
        self::setError('门店送站趟次创建失败');
        return false;
    }

    /** @return array{lists:array<int,array<string,mixed>>}|false */
    public static function trips(array $params): array|false
    {
        self::clearError();
        if (!self::requireViewPermission()) {
            return false;
        }
        $query = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId());
        if (trim((string)($params['trip_date'] ?? '')) !== '') {
            $query->where('trip_date', trim((string)$params['trip_date']));
        }
        if (trim((string)($params['status'] ?? '')) !== '') {
            $query->where('status', trim((string)$params['status']));
        }
        $rows = $query->order(['trip_date' => 'desc', 'planned_store_departure_time' => 'asc', 'id' => 'desc'])
            ->select()->toArray();
        foreach ($rows as &$row) {
            $row['report_count'] = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('trip_id', (int)$row['id'])->count();
            $row['handoff_count'] = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('trip_id', (int)$row['id'])->where('status', 'handed_over')->count();
        }
        unset($row);
        return ['lists' => $rows];
    }

    /** @return array<string,mixed>|false */
    public static function tripDetail(array $params): array|false
    {
        self::clearError();
        if (!self::requireViewPermission()) {
            return false;
        }
        $trip = self::tripDetailWithinTransaction((int)($params['trip_id'] ?? 0));
        if ($trip === false) {
            self::setError('门店送站趟次不存在');
        }
        return $trip;
    }

    /** @return array<string,mixed>|false */
    public static function recordPackages(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.line.manage')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $id = (int)($params['trip_report_id'] ?? 0);
        $stage = trim((string)($params['stage'] ?? ''));
        $count = (int)($params['actual_package_count'] ?? -1);
        $note = mb_substr(trim((string)($params['exception_note'] ?? '')), 0, 500);
        if ($id <= 0 || !in_array($stage, ['packed', 'loaded'], true) || $count < 0) {
            self::setError('包数回录参数不完整');
            return false;
        }
        $reference = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('id', $id)->field('id,trip_id')->find();
        if (!$reference) {
            self::setError('送站报货单不存在');
            return false;
        }
        $result = Db::transaction(static function () use ($reference, $id, $stage, $count, $note) {
            $trip = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                ->where('id', (int)$reference['trip_id'])->lock(true)->find();
            $item = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('id', $id)->lock(true)->find();
            if (!$trip || !$item || !in_array((string)$trip['status'], ['planned', 'loading'], true)
                || in_array((string)$item['status'], ['handed_over', 'rerouted'], true)) {
                self::setError('当前送站报货单不能回录包数');
                return false;
            }
            if ($stage === 'loaded' && (int)$item['packed_checked'] !== 1) {
                self::setError('必须先完成打包包数核对');
                return false;
            }
            if ($count !== (int)$item['expected_package_count'] && $note === '') {
                self::setError('实际包数与应装包数不一致时必须填写异常原因');
                return false;
            }
            if ($stage === 'loaded' && (int)$item['packed_checked'] === 1
                && $count !== (int)$item['actual_packed_package_count'] && $note === '') {
                self::setError('实际装车包数与打包包数不一致时必须填写异常原因');
                return false;
            }
            $before = $item;
            $now = time();
            $packedNote = $stage === 'packed' ? $note : (string)($item['packed_exception_note'] ?? '');
            $loadedNote = $stage === 'loaded' ? $note : (string)($item['loaded_exception_note'] ?? '');
            $update = [
                'packed_exception_note' => $packedNote,
                'loaded_exception_note' => $loadedNote,
                'package_exception_note' => mb_substr(
                    self::packageExceptionSummary($packedNote, $loadedNote, (string)($item['handoff_exception_note'] ?? '')),
                    0,
                    500
                ),
                'status' => 'loading',
                'operator_id' => self::operatorId(),
                'update_time' => $now,
            ];
            if ($stage === 'packed') {
                $update['actual_packed_package_count'] = $count;
                $update['packed_checked'] = 1;
            } else {
                $update['actual_loaded_package_count'] = $count;
                $update['loaded_checked'] = 1;
            }
            Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())->where('id', $id)->update($update);
            if ((string)$trip['status'] === 'planned') {
                Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                    ->where('id', (int)$trip['id'])->update(['status' => 'loading', 'update_time' => $now]);
            }
            $after = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())->where('id', $id)->find();
            AuditService::logWithinTransaction('line_vehicle', 'package_' . $stage, $id, '', $before, $after, '回录纸质装车清单包数');
            return $after;
        });
        return $result === false ? false : $result;
    }

    /** @return array<string,mixed>|false */
    public static function returnReroutedToPending(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.line.manage')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $id = (int)($params['trip_report_id'] ?? 0);
        $reason = mb_substr(trim((string)($params['return_reason'] ?? '')), 0, 500);
        $key = trim((string)($params['idempotency_key'] ?? ''));
        if ($id <= 0 || $reason === '' || $key === '' || mb_strlen($key) > 96) {
            self::setError('返回门店原因和幂等键不能为空');
            return false;
        }
        $fingerprint = hash('sha256', json_encode([
            'trip_report_id' => $id, 'return_reason' => $reason,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = Db::transaction(static function () use ($id, $reason, $key, $fingerprint) {
                    $existing = Db::name('line_vehicle_return_event')->where('tenant_id', self::tenantId())
                        ->where('idempotency_key', $key)->find();
                    if ($existing) {
                        return self::replayReturnEvent($existing, $fingerprint);
                    }
                    $reference = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                        ->where('id', $id)->field('id,trip_id')->find();
                    if (!$reference) {
                        self::setError('送站报货单不存在');
                        return false;
                    }
                    $trip = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$reference['trip_id'])->lock(true)->find();
                    $item = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                        ->where('id', $id)->lock(true)->find();
                    if (!$trip || !$item || (string)$item['status'] !== 'rerouted') {
                        self::setError('只有已改派且尚未交付的货物才能返回门店待配送');
                        return false;
                    }
                    $replayed = Db::name('line_vehicle_return_event')->where('tenant_id', self::tenantId())
                        ->where('idempotency_key', $key)->find();
                    if ($replayed) {
                        return self::replayReturnEvent($replayed, $fingerprint);
                    }
                    if ((int)($item['return_event_id'] ?? 0) > 0) {
                        self::setError('该货物已经记录返回门店，不能覆盖原事实');
                        return false;
                    }
                    $now = FulfillmentClock::now();
                    $eventId = (int)Db::name('line_vehicle_return_event')->insertGetId([
                        'tenant_id' => self::tenantId(), 'trip_id' => (int)$trip['id'],
                        'trip_report_id' => $id, 'report_id' => (int)$item['report_id'],
                        'status' => 'returned_to_pending', 'reason' => $reason,
                        'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                        'operator_id' => self::operatorId(), 'returned_time' => $now, 'create_time' => $now,
                    ]);
                    if ($eventId <= 0) {
                        throw new \RuntimeException('line_vehicle_return_insert_failed');
                    }
                    Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                        'return_event_id' => $eventId, 'return_status' => 'returned_to_pending',
                        'return_reason' => $reason, 'returned_to_store_time' => $now,
                        'operator_id' => self::operatorId(), 'update_time' => $now,
                    ]);
                    Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                        ->where('id', (int)$item['delivery_task_id'])->whereNotIn('status', ['cancelled', 'completed'])
                        ->update(['status' => 'printable', 'update_time' => $now]);
                    $after = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                        ->where('id', $id)->find();
                    AuditService::logWithinTransaction(
                        'line_vehicle', 'return_to_pending', $eventId, $key,
                        ['trip_report_id' => $id, 'status' => 'rerouted'], $after, $reason
                    );
                    return $after;
                });
                if ($result === false) {
                    $replayed = self::replayReturnAfterCommit($key, $fingerprint);
                    if ($replayed !== false) {
                        self::clearError();
                        return $replayed;
                    }
                }
                return $result;
            } catch (\Throwable $exception) {
                $retryable = in_array((int)$exception->getCode(), [1205, 1213], true)
                    || str_contains($exception->getMessage(), '1205')
                    || str_contains($exception->getMessage(), '1213');
                if ($retryable && $attempt < 2) {
                    usleep(20_000 * ($attempt + 1));
                    continue;
                }
                $replayed = self::replayReturnAfterCommit($key, $fingerprint);
                if ($replayed !== false) {
                    self::clearError();
                    return $replayed;
                }
                if (!self::hasError()) {
                    self::setError('返回门店待配送保存失败');
                }
                return false;
            }
        }
        self::setError('返回门店待配送保存失败');
        return false;
    }

    /** @return array<string,mixed>|false */
    public static function departTrip(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.line.manage')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $id = (int)($params['trip_id'] ?? 0);
        $actualTime = self::dateTimeValue((string)($params['actual_store_departure_time'] ?? ''));
        if ($id <= 0 || $actualTime === false) {
            self::setError('门店实际出车时间格式不正确');
            return false;
        }
        if ($actualTime > FulfillmentClock::now()) {
            self::setError('门店实际出车时间不能晚于当前时间');
            return false;
        }
        $result = Db::transaction(static function () use ($id, $actualTime) {
            $trip = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                ->where('id', $id)->lock(true)->find();
            if (!$trip) {
                self::setError('门店送站趟次不存在');
                return false;
            }
            if (in_array((string)$trip['status'], ['completed', 'closed'], true)) {
                self::setError('已关闭或已完成趟次不能重新出车');
                return false;
            }
            if ((int)$trip['actual_store_departure_time'] > 0) {
                if ((int)$trip['actual_store_departure_time'] !== $actualTime) {
                    self::setError('门店实际出车时间已回录，不能覆盖');
                    return false;
                }
                return self::tripDetailWithinTransaction($id);
            }
            $notLoaded = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('trip_id', $id)->where('loaded_checked', '<>', 1)->lock(true)->count();
            if ($notLoaded > 0) {
                self::setError('本趟所有货物必须先完成装车包数核对');
                return false;
            }
            Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                'actual_store_departure_time' => $actualTime,
                'status' => 'departed',
                'operator_id' => self::operatorId(),
                'update_time' => time(),
            ]);
            AuditService::logWithinTransaction('line_vehicle', 'store_depart', $id, '', [
                'actual_store_departure_time' => 0,
            ], [
                'actual_store_departure_time' => $actualTime,
            ], '门店车辆出发不触发库存出库');
            return self::tripDetailWithinTransaction($id);
        });
        return $result === false ? false : $result;
    }

    /** @return array<string,mixed>|false */
    public static function reroute(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.line.manage')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $id = (int)($params['trip_report_id'] ?? 0);
        $method = trim((string)($params['reroute_method'] ?? ''));
        $reason = mb_substr(trim((string)($params['reroute_reason'] ?? '')), 0, 500);
        if ($id <= 0 || !in_array($method, ['fixed_line_vehicle', 'third_party', 'self_delivery'], true)
            || $reason === '') {
            self::setError('改派方式和原因不能为空');
            return false;
        }
        $reference = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('id', $id)->field('id,trip_id')->find();
        if (!$reference) {
            self::setError('送站报货单不存在');
            return false;
        }
        $result = Db::transaction(static function () use ($reference, $id, $method, $reason) {
            $trip = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                ->where('id', (int)$reference['trip_id'])->lock(true)->find();
            $item = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('id', $id)->lock(true)->find();
            if (!$trip || !$item) {
                self::setError('送站报货单不存在');
                return false;
            }
            if ((string)$item['status'] === 'rerouted') {
                if ((string)$item['reroute_method'] !== $method || (string)$item['reroute_reason'] !== $reason) {
                    self::setError('该货物已改派，不能覆盖改派事实');
                    return false;
                }
                return $item;
            }
            if ((string)$item['status'] === 'handed_over' || $item['active_report_id'] === null) {
                self::setError('已完成交接的货物不能改派');
                return false;
            }
            if ((string)$item['status'] !== 'loading' || (int)$item['loaded_checked'] !== 1
                || (string)$trip['status'] !== 'departed' || (int)$trip['actual_store_departure_time'] <= 0
                || FulfillmentClock::now() <= (int)$item['handoff_deadline']) {
                self::setError('只有已装车、已实际出车且已错过截止的货物才能记为改派');
                return false;
            }
            $now = FulfillmentClock::now();
            Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('id', $id)->update([
                    'active_report_id' => null,
                    'reroute_method' => $method,
                    'reroute_reason' => $reason,
                    'rerouted_time' => $now,
                    'status' => 'rerouted',
                    'operator_id' => self::operatorId(),
                    'update_time' => $now,
                ]);
            $after = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('id', $id)->find();
            $active = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
                ->where('trip_id', (int)$trip['id'])->whereNotNull('active_report_id')->count();
            if ($active === 0) {
                Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
                    ->where('id', (int)$trip['id'])->update([
                        'status' => 'closed',
                        'operator_id' => self::operatorId(),
                        'update_time' => $now,
                    ]);
            }
            AuditService::logWithinTransaction(
                'line_vehicle',
                'reroute',
                $id,
                '',
                $item,
                $after,
                $reason
            );
            return $after;
        });
        return $result === false ? false : $result;
    }

    /** @return array<string,mixed>|false */
    public static function confirmHandoff(array $params): array|false
    {
        self::clearError();
        $event = DeliveryInventoryLogic::confirmFixedLineHandoff($params);
        if ($event === false) {
            self::setError(DeliveryInventoryLogic::getError());
            return false;
        }
        $trip = self::tripDetailWithinTransaction((int)$event['trip_id']);
        return ['delivery_event' => $event, 'trip' => $trip];
    }

    /** @return array<string,mixed>|false */
    public static function loadingManifest(array $params): array|false
    {
        self::clearError();
        if (!self::requireViewPermission()) {
            return false;
        }
        $trip = self::tripDetailWithinTransaction((int)($params['trip_id'] ?? 0));
        if ($trip === false) {
            self::setError('门店送站趟次不存在');
            return false;
        }
        $lines = [];
        foreach ($trip['items'] as $item) {
            $isChild = (int)$item['delivery_customer_id'] !== (int)$item['main_customer_id'];
            $exceptionNote = self::packageExceptionSummary(
                (string)($item['packed_exception_note'] ?? ''),
                (string)($item['loaded_exception_note'] ?? ''),
                (string)($item['handoff_exception_note'] ?? '')
            );
            if (trim((string)($item['reroute_reason'] ?? '')) !== '') {
                $exceptionNote .= ($exceptionNote === '' ? '' : '；') . '改派：' . (string)$item['reroute_reason'];
            }
            $lines[] = [
                'line_name' => (string)$item['line_name_snapshot'],
                'handoff_location' => (string)$item['handoff_location_snapshot'],
                'handoff_deadline' => (int)$item['handoff_deadline'],
                'main_customer' => [
                    'id' => (int)$item['main_customer_id'],
                    'name' => (string)$item['main_customer_name'],
                    'emphasis' => true,
                ],
                'delivery_customer' => $isChild ? [
                    'id' => (int)$item['delivery_customer_id'],
                    'name' => (string)$item['delivery_customer_name'],
                    'emphasis' => false,
                    'is_child' => true,
                ] : null,
                'report_sn' => (string)$item['report_sn'],
                'expected_package_count' => (int)$item['expected_package_count'],
                'actual_loaded_package_count' => (int)$item['actual_loaded_package_count'],
                'actual_handoff_package_count' => (int)$item['actual_handoff_package_count'],
                'checklist' => [
                    'packed' => (int)$item['packed_checked'] === 1,
                    'loaded' => (int)$item['loaded_checked'] === 1,
                    'handed_over' => (int)$item['handoff_checked'] === 1,
                ],
                'actual_handoff_time' => (int)$item['actual_handoff_time'],
                'exception_note' => $exceptionNote,
                'status' => (string)$item['status'],
                'reroute_method' => (string)($item['reroute_method'] ?? ''),
                'rerouted_time' => (int)($item['rerouted_time'] ?? 0),
            ];
        }
        return [
            'document_type' => '门店送站装车清单',
            'trip_no' => (string)$trip['trip_no'],
            'trip_date' => (string)$trip['trip_date'],
            'planned_store_departure_time' => (int)$trip['planned_store_departure_time'],
            'lines' => $lines,
        ];
    }

    public static function calculateRiskStatus(
        int $plannedStoreDepartureTime,
        int $handoffDeadline,
        int $now,
        bool $completed
    ): string {
        if ($completed) {
            return 'completed';
        }
        if ($now > $handoffDeadline) {
            return 'missed';
        }
        if ($plannedStoreDepartureTime >= $handoffDeadline || $now >= $plannedStoreDepartureTime) {
            return 'at_risk';
        }
        return 'on_time';
    }

    /** @return array<string,mixed>|false */
    private static function tripDetailWithinTransaction(int $tripId): array|false
    {
        $trip = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())->where('id', $tripId)->find();
        if (!$trip) {
            return false;
        }
        $items = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('trip_id', $tripId)->order(['line_departure_time' => 'asc', 'schedule_id' => 'asc', 'id' => 'asc'])
            ->select()->toArray();
        foreach ($items as &$item) {
            $item['risk_status'] = self::calculateRiskStatus(
                (int)$trip['planned_store_departure_time'],
                (int)$item['handoff_deadline'],
                FulfillmentClock::now(),
                (string)$item['status'] === 'handed_over'
            );
        }
        unset($item);
        $trip['items'] = $items;
        return $trip;
    }

    private static function requireViewPermission(): bool
    {
        if (!WorkforceLogic::requireAnyPermission(['delivery.line.manage', 'delivery.confirm', 'task.view'])) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        return true;
    }

    /** @return array<string,mixed>|false */
    private static function replayTripAfterConcurrentCommit(string $key, string $fingerprint): array|false
    {
        $trip = Db::name('line_vehicle_trip')->where('tenant_id', self::tenantId())
            ->where('idempotency_key', $key)->find();
        if (!$trip || !hash_equals((string)$trip['request_fingerprint'], $fingerprint)) {
            return false;
        }
        return self::tripDetailWithinTransaction((int)$trip['id']);
    }

    /** @param array<string,mixed> $event @return array<string,mixed>|false */
    private static function replayReturnEvent(array $event, string $fingerprint): array|false
    {
        if (!hash_equals((string)$event['request_fingerprint'], $fingerprint)) {
            self::setError('同一幂等键不能提交不同的返回门店事实');
            return false;
        }
        $item = Db::name('line_vehicle_trip_report')->where('tenant_id', self::tenantId())
            ->where('id', (int)$event['trip_report_id'])->find();
        if (!$item) {
            self::setError('返回门店事实关联货物不存在');
            return false;
        }
        return $item;
    }

    /** @return array<string,mixed>|false */
    private static function replayReturnAfterCommit(string $key, string $fingerprint): array|false
    {
        $event = Db::name('line_vehicle_return_event')->where('tenant_id', self::tenantId())
            ->where('idempotency_key', $key)->find();
        return $event ? self::replayReturnEvent($event, $fingerprint) : false;
    }

    private static function scheduleTimestamp(string $tripDate, string $departureTime): int
    {
        $value = self::dateTimeValue($tripDate . ' ' . $departureTime . ':00');
        if ($value === false) {
            throw new \InvalidArgumentException('invalid_line_departure_time');
        }
        return $value;
    }

    private static function dateValue(string $value): string|false
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : false;
    }

    private static function dateTimeValue(string $value): int|false
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
        return (int)(request()->adminInfo['tenant_id'] ?? request()->tenantId ?? 0);
    }

    private static function operatorId(): int
    {
        return (int)(request()->adminInfo['admin_id'] ?? request()->adminId ?? request()->userId ?? 0);
    }
}
