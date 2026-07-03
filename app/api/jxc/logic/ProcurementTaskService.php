<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\InventoryReservation;
use app\common\model\jxc\ProcurementTask;
use app\common\model\jxc\ProcurementTaskInbound;
use app\common\model\jxc\SalesReservation;
use app\common\model\jxc\SalesReservationItem;

class ProcurementTaskService
{
    public static function createForReservationItem(int $reservationItemId): array|false
    {
        $item = SalesReservationItem::where('id', $reservationItemId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($item->isEmpty() || bccomp((string)$item->shortage_num, '0', 4) <= 0) {
            return false;
        }

        $sourceKey = 'reservation_item:' . (int)$item->id;
        $existing = ProcurementTask::where('tenant_id', self::tenantId())
            ->where('source_type', 'sales_reservation')
            ->where('source_key', $sourceKey)
            ->whereNotIn('status', [ProcurementTask::STATUS_CANCELLED])
            ->find();
        if ($existing) {
            return self::format($existing->toArray());
        }

        $task = ProcurementTask::create([
            'tenant_id' => self::tenantId(),
            'sn' => self::generateSn(),
            'source_type' => 'sales_reservation',
            'source_key' => $sourceKey,
            'source_reservation_id' => (int)$item->reservation_id,
            'source_reservation_item_id' => (int)$item->id,
            'goods_id' => (int)$item->goods_id,
            'goods_name' => (string)$item->goods_name,
            'goods_code' => (string)$item->goods_code,
            'warehouse_id' => (int)$item->warehouse_id,
            'sku_id' => (int)$item->sku_id,
            'spec_id' => (int)$item->spec_id,
            'required_num' => InventoryReservationService::qty($item->shortage_num),
            'arrived_num' => '0.0000',
            'status' => ProcurementTask::STATUS_PENDING,
            'create_by' => self::adminId(),
            'update_by' => self::adminId(),
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $item->save([
            'procurement_task_id' => (int)$task->id,
            'update_time' => time(),
        ]);

        return self::format($task->toArray());
    }

    public static function closeTask(ProcurementTask $task, string $reason): array
    {
        $task->save([
            'status' => ProcurementTask::STATUS_CLOSED,
            'close_reason' => $reason,
            'close_time' => time(),
            'update_by' => self::adminId(),
            'update_time' => time(),
        ]);

        if ((int)$task->source_reservation_id > 0) {
            SalesReservationItem::where('id', (int)$task->source_reservation_item_id)
                ->where('tenant_id', self::tenantId())
                ->update([
                    'status' => SalesReservationItem::STATUS_GAP_CLOSED,
                    'update_time' => time(),
                ]);
            SalesReservation::where('id', (int)$task->source_reservation_id)
                ->where('tenant_id', self::tenantId())
                ->update([
                    'status' => SalesReservation::STATUS_GAP_CLOSED,
                    'update_by' => self::adminId(),
                    'update_time' => time(),
                ]);
        }

        return self::format(ProcurementTask::find((int)$task->id)->toArray());
    }

    public static function cancelOpenTasksForReservation(int $reservationId): void
    {
        ProcurementTask::where('tenant_id', self::tenantId())
            ->where('source_reservation_id', $reservationId)
            ->whereIn('status', [ProcurementTask::STATUS_PENDING, ProcurementTask::STATUS_PURCHASING, ProcurementTask::STATUS_PARTIAL_ARRIVED])
            ->update([
                'status' => ProcurementTask::STATUS_CANCELLED,
                'update_by' => self::adminId(),
                'update_time' => time(),
            ]);
    }

    public static function backfillSupplyInbound(int $supplyOrderId, array $rows): void
    {
        foreach ($rows as $row) {
            $goodsId = (int)($row['goods_id'] ?? 0);
            $remaining = InventoryReservationService::qty($row['number'] ?? $row['inbound_num'] ?? 0);
            if ($goodsId <= 0 || bccomp($remaining, '0', 4) <= 0) {
                continue;
            }

            $tasks = ProcurementTask::where('tenant_id', self::tenantId())
                ->where('goods_id', $goodsId)
                ->whereIn('status', [ProcurementTask::STATUS_PENDING, ProcurementTask::STATUS_PURCHASING, ProcurementTask::STATUS_PARTIAL_ARRIVED])
                ->order(['id' => 'asc'])
                ->select();

            foreach ($tasks as $task) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }

                $need = bcsub((string)$task->required_num, (string)$task->arrived_num, 4);
                if (bccomp($need, '0', 4) <= 0) {
                    continue;
                }

                $inboundNum = bccomp($remaining, $need, 4) > 0 ? $need : $remaining;
                ProcurementTaskInbound::create([
                    'task_id' => (int)$task->id,
                    'goods_id' => $goodsId,
                    'supply_order_id' => $supplyOrderId,
                    'supply_order_item_id' => (int)($row['id'] ?? 0),
                    'inbound_num' => InventoryReservationService::qty($inboundNum),
                    'create_time' => time(),
                ]);

                $arrived = bcadd((string)$task->arrived_num, $inboundNum, 4);
                $fulfilled = bccomp($arrived, (string)$task->required_num, 4) >= 0;
                $task->save([
                    'arrived_num' => InventoryReservationService::qty($arrived),
                    'status' => $fulfilled ? ProcurementTask::STATUS_FULFILLED : ProcurementTask::STATUS_PARTIAL_ARRIVED,
                    'finish_time' => $fulfilled ? time() : (int)$task->finish_time,
                    'update_by' => self::adminId(),
                    'update_time' => time(),
                ]);

                if ($fulfilled) {
                    self::markReservationItemReady($task);
                }

                $remaining = bcsub($remaining, $inboundNum, 4);
            }
        }
    }

    public static function format(array $task): array
    {
        $status = (string)($task['status'] ?? '');

        return [
            'id' => (int)($task['id'] ?? 0),
            'sn' => (string)($task['sn'] ?? ''),
            'source_type' => (string)($task['source_type'] ?? ''),
            'source_key' => (string)($task['source_key'] ?? ''),
            'source_reservation_id' => (int)($task['source_reservation_id'] ?? 0),
            'source_reservation_item_id' => (int)($task['source_reservation_item_id'] ?? 0),
            'goods_id' => (int)($task['goods_id'] ?? 0),
            'goods_name' => (string)($task['goods_name'] ?? ''),
            'goods_code' => (string)($task['goods_code'] ?? ''),
            'warehouse_id' => (int)($task['warehouse_id'] ?? 0),
            'sku_id' => (int)($task['sku_id'] ?? 0),
            'spec_id' => (int)($task['spec_id'] ?? 0),
            'required_num' => InventoryReservationService::qty($task['required_num'] ?? 0),
            'arrived_num' => InventoryReservationService::qty($task['arrived_num'] ?? 0),
            'status' => $status,
            'close_reason' => (string)($task['close_reason'] ?? ''),
            'start_time' => (int)($task['start_time'] ?? 0),
            'finish_time' => (int)($task['finish_time'] ?? 0),
            'close_time' => (int)($task['close_time'] ?? 0),
            'create_time' => $task['create_time'] ?? '',
            'update_time' => $task['update_time'] ?? '',
            'actions' => self::actions($task, $status),
        ];
    }

    private static function actions(array $task, string $status): array
    {
        $actions = [
            'can_view_source' => self::hasViewableSource($task),
        ];

        if ($status === ProcurementTask::STATUS_PENDING) {
            return [
                'can_start' => true,
                'can_close' => true,
                'can_cancel' => true,
                'can_view_source' => $actions['can_view_source'],
            ];
        }

        if (in_array($status, [ProcurementTask::STATUS_PURCHASING, ProcurementTask::STATUS_PARTIAL_ARRIVED], true)) {
            return [
                'can_start' => false,
                'can_close' => true,
                'can_cancel' => false,
                'can_view_source' => $actions['can_view_source'],
            ];
        }

        return $actions;
    }

    private static function hasViewableSource(array $task): bool
    {
        $sourceType = (string)($task['source_type'] ?? '');
        if ($sourceType === '' || $sourceType === 'manual') {
            return false;
        }

        return (int)($task['source_reservation_id'] ?? 0) > 0
            || (int)($task['source_reservation_item_id'] ?? 0) > 0
            || (string)($task['source_key'] ?? '') !== '';
    }

    private static function markReservationItemReady(ProcurementTask $task): void
    {
        $item = SalesReservationItem::where('id', (int)$task->source_reservation_item_id)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($item->isEmpty() || (string)$item->status === SalesReservationItem::STATUS_GAP_CLOSED) {
            return;
        }

        if (bccomp((string)$item->shortage_num, '0', 4) > 0) {
            InventoryReservationService::reserve($item->toArray(), (string)$item->shortage_num);
        }

        $item->save([
            'reserved_num' => InventoryReservationService::qty($item->num),
            'shortage_num' => '0.0000',
            'status' => SalesReservationItem::STATUS_RESERVED,
            'update_time' => time(),
        ]);

        $reservation = SalesReservation::where('id', (int)$task->source_reservation_id)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($reservation->isEmpty() || (string)$reservation->status === SalesReservation::STATUS_GAP_CLOSED) {
            return;
        }

        $openShortage = SalesReservationItem::where('reservation_id', (int)$reservation->id)
            ->where('tenant_id', self::tenantId())
            ->whereIn('status', [SalesReservationItem::STATUS_SHORTAGE, SalesReservationItem::STATUS_GAP_CLOSED])
            ->count();
        if ($openShortage == 0) {
            $reservation->save([
                'status' => SalesReservation::STATUS_READY,
                'reserved_num' => InventoryReservationService::qty($reservation->total_num),
                'shortage_num' => '0.0000',
                'update_by' => self::adminId(),
                'update_time' => time(),
            ]);
        }
    }

    private static function generateSn(): string
    {
        return 'CGT' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function adminId(): int
    {
        return (int)(request()->adminId ?? 0);
    }
}
