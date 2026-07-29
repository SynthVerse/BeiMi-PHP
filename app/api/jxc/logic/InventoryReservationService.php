<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\InventoryReservation;
use app\common\model\jxc\SalesReservationItem;

class InventoryReservationService
{
    public static function availableForGoods(int $goodsId, int $warehouseId): string
    {
        return WarehouseGoodsBalanceService::available($warehouseId, $goodsId);
    }

    public static function reserve(array $item, string $num): ?InventoryReservation
    {
        $warehouseId = (int)($item['warehouse_id'] ?? 0);
        $goodsId = (int)($item['goods_id'] ?? 0);
        if ($warehouseId <= 0 || $goodsId <= 0 || bccomp($num, '0', 4) <= 0) {
            return null;
        }
        if (WarehouseGoodsBalanceService::reserve($warehouseId, $goodsId, self::qty($num)) === false) {
            return null;
        }

        return InventoryReservation::create([
            'tenant_id' => self::tenantId(),
            'reservation_id' => (int)$item['reservation_id'],
            'reservation_item_id' => (int)$item['id'],
            'goods_id' => $goodsId,
            'warehouse_id' => $warehouseId,
            'sku_id' => (int)($item['sku_id'] ?? 0),
            'spec_id' => (int)($item['spec_id'] ?? 0),
            'reserved_num' => self::qty($num),
            'consumed_num' => '0.0000',
            'released_num' => '0.0000',
            'status' => InventoryReservation::STATUS_ACTIVE,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    public static function releaseReservation(int $reservationId): void
    {
        $rows = InventoryReservation::where('reservation_id', $reservationId)
            ->where('tenant_id', self::tenantId())
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->order(['id' => 'asc'])
            ->lock(true)
            ->select();

        foreach ($rows as $row) {
            $remaining = bcsub((string)$row->reserved_num, bcadd((string)$row->consumed_num, (string)$row->released_num, 4), 4);
            if (bccomp($remaining, '0', 4) > 0 && WarehouseGoodsBalanceService::release((int)$row->warehouse_id, (int)$row->goods_id, self::qty($remaining)) === false) {
                throw new \RuntimeException('Unable to release warehouse reservation.');
            }
            $row->save([
                'released_num' => self::qty(bcadd((string)$row->released_num, $remaining, 4)),
                'status' => InventoryReservation::STATUS_RELEASED,
                'update_time' => time(),
            ]);
        }
    }

    public static function releaseReservationItem(int $reservationItemId): void
    {
        $rows = InventoryReservation::where('tenant_id', self::tenantId())
            ->where('reservation_item_id', $reservationItemId)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->order(['id' => 'asc'])
            ->lock(true)
            ->select();
        foreach ($rows as $row) {
            $remaining = bcsub((string)$row->reserved_num, bcadd((string)$row->consumed_num, (string)$row->released_num, 4), 4);
            if (bccomp($remaining, '0', 4) > 0 && WarehouseGoodsBalanceService::release((int)$row->warehouse_id, (int)$row->goods_id, self::qty($remaining)) === false) {
                throw new \RuntimeException('Unable to release warehouse reservation.');
            }
            $row->save(['released_num' => self::qty(bcadd((string)$row->released_num, $remaining, 4)), 'status' => InventoryReservation::STATUS_RELEASED, 'update_time' => time()]);
        }
    }

    public static function consumeReservation(int $reservationId): void
    {
        $rows = InventoryReservation::where('reservation_id', $reservationId)
            ->where('tenant_id', self::tenantId())
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->order(['id' => 'asc'])
            ->lock(true)
            ->select();

        foreach ($rows as $row) {
            $remaining = bcsub((string)$row->reserved_num, bcadd((string)$row->consumed_num, (string)$row->released_num, 4), 4);
            if (bccomp($remaining, '0', 4) > 0 && WarehouseGoodsBalanceService::release((int)$row->warehouse_id, (int)$row->goods_id, self::qty($remaining)) === false) {
                throw new \RuntimeException('Unable to consume warehouse reservation.');
            }
            $row->save([
                'consumed_num' => self::qty(bcadd((string)$row->consumed_num, $remaining, 4)),
                'status' => InventoryReservation::STATUS_CONSUMED,
                'update_time' => time(),
            ]);
        }

        SalesReservationItem::where('reservation_id', $reservationId)
            ->where('tenant_id', self::tenantId())
            ->where('status', '<>', SalesReservationItem::STATUS_GAP_CLOSED)
            ->update([
                'status' => SalesReservationItem::STATUS_CONVERTED,
                'update_time' => time(),
            ]);
    }

    public static function qty(mixed $value): string
    {
        return number_format((float)$value, 4, '.', '');
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
