<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSku;
use app\common\model\jxc\WarehouseSkuBalance;
use think\facade\Db;

/**
 * 仓库 SKU 库存的权威写入口。
 *
 * 每一笔余额唯一对应租户、仓库、SKU 和 SKU 的基础单位。goods_id 只用于商品级汇总，
 * 调用方不能用商品 ID 代替 SKU ID。
 */
class WarehouseSkuBalanceService
{
    private const SCALE = 4;

    public static function inbound(int $warehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::change($warehouseId, $skuId, $quantity, '0.0000');
    }

    public static function inboundWithinTransaction(int $warehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false
            ? false
            : self::changeWithinTransaction($warehouseId, $skuId, $quantity, '0.0000');
    }

    public static function outbound(int $warehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::change($warehouseId, $skuId, '-' . $quantity, '0.0000');
    }

    /** 普通实物减少的同事务原语，保留可用量与预留约束，不另开事务。 */
    public static function outboundWithinTransaction(int $warehouseId, int $skuId, string $quantity): array|false
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::changeWithinTransaction($warehouseId, $skuId, '-' . $quantity, '0.0000');
    }

    /** 财务已确认的真实退离，调用方须保留原到货、退货单及成本缺口归因。 */
    public static function purchaseReturnWithinTransaction(int $warehouseId, int $skuId, string $quantity): array|false
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::changeWithinTransaction($warehouseId, $skuId, '-' . $quantity, '0.0000', true);
    }

    public static function reserve(int $warehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::change($warehouseId, $skuId, '0.0000', $quantity);
    }

    public static function reserveWithinTransaction(int $warehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false
            ? false
            : self::changeWithinTransaction($warehouseId, $skuId, '0.0000', $quantity);
    }

    public static function reserveUpTo(int $warehouseId, int $skuId, string $quantity): string|false
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false || $warehouseId <= 0 || $skuId <= 0) {
            return false;
        }
        try {
            return Db::transaction(static fn() => self::reserveUpToWithinTransaction($warehouseId, $skuId, $quantity));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function reserveUpToWithinTransaction(int $warehouseId, int $skuId, string $quantity): string|false
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false || $warehouseId <= 0 || $skuId <= 0) {
            return false;
        }
        $context = self::lockedContext($warehouseId, $skuId);
        if ($context === false) {
            return false;
        }
        [$sku] = $context;
        $balance = WarehouseSkuBalance::where('tenant_id', self::tenantId())
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('base_unit_id', (int)($sku->base_unit_id ?? 0))
            ->lock(true)
            ->find();
        $available = $balance ? self::decimal((string)$balance->available_qty) : '0.0000';
        $reserved = bccomp($available, $quantity, self::SCALE) < 0 ? $available : $quantity;
        if (bccomp($reserved, '0.0000', self::SCALE) <= 0) {
            return '0.0000';
        }
        return self::changeWithinTransaction($warehouseId, $skuId, '0.0000', $reserved) === false
            ? false
            : $reserved;
    }

    public static function release(int $warehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::change($warehouseId, $skuId, '0.0000', '-' . $quantity);
    }

    public static function releaseWithinTransaction(int $warehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false
            ? false
            : self::changeWithinTransaction($warehouseId, $skuId, '0.0000', '-' . $quantity);
    }

    public static function consumeReserved(int $warehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::change($warehouseId, $skuId, '-' . $quantity, '-' . $quantity);
    }

    public static function consumeReservedWithinTransaction(int $warehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false
            ? false
            : self::changeWithinTransaction($warehouseId, $skuId, '-' . $quantity, '-' . $quantity);
    }

    /**
     * 交付出库专用原语。只有上层已经建立完整销售单与报货行归因时才能调用。
     * 普通出库、调拨和预留继续禁止制造新的负数。
     */
    public static function deliverAttributedWithinTransaction(
        int $warehouseId,
        int $skuId,
        string $actualQuantity,
        string $reservationQuantity
    ): array|false {
        $actualQuantity = self::normalizeQuantity($actualQuantity);
        $reservationQuantity = self::normalizeNonNegativeQuantity($reservationQuantity);
        if ($actualQuantity === false || $reservationQuantity === false) {
            return false;
        }
        $movement = self::changeWithinTransaction(
            $warehouseId,
            $skuId,
            '-' . $actualQuantity,
            bccomp($reservationQuantity, '0.0000', self::SCALE) > 0 ? '-' . $reservationQuantity : '0.0000',
            true
        );
        if ($movement === false) {
            return false;
        }
        $beforeNegative = bccomp((string)$movement['before_available_qty'], '0.0000', self::SCALE) < 0
            ? ltrim((string)$movement['before_available_qty'], '-') : '0.0000';
        $afterNegative = bccomp((string)$movement['after_available_qty'], '0.0000', self::SCALE) < 0
            ? ltrim((string)$movement['after_available_qty'], '-') : '0.0000';
        $consumed = bccomp($actualQuantity, $reservationQuantity, self::SCALE) < 0
            ? $actualQuantity : $reservationQuantity;
        $released = bccomp($reservationQuantity, $actualQuantity, self::SCALE) > 0
            ? bcsub($reservationQuantity, $actualQuantity, self::SCALE) : '0.0000';
        $negativeDelta = bcsub($afterNegative, $beforeNegative, self::SCALE);
        $healedDelta = bcsub($beforeNegative, $afterNegative, self::SCALE);
        return $movement + [
            'negative_qty' => bccomp($negativeDelta, '0.0000', self::SCALE) > 0
                ? $negativeDelta : '0.0000',
            'healed_negative_qty' => bccomp($healedDelta, '0.0000', self::SCALE) > 0
                ? $healedDelta : '0.0000',
            'reservation_consumed_qty' => $consumed,
            'reservation_released_qty' => $released,
        ];
    }

    public static function transfer(int $fromWarehouseId, int $toWarehouseId, int $skuId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false || $fromWarehouseId <= 0 || $toWarehouseId <= 0 || $fromWarehouseId === $toWarehouseId) {
            return false;
        }
        try {
            return Db::transaction(static function () use ($fromWarehouseId, $toWarehouseId, $skuId, $quantity) {
                return self::transferWithinTransaction($fromWarehouseId, $toWarehouseId, $skuId, $quantity);
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /** 调拨业务的外层事务持有者调用，任一侧失败由外层整体回滚。 */
    public static function transferWithinTransaction(int $fromWarehouseId, int $toWarehouseId, int $skuId, string $quantity): array
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo || !$pdo->inTransaction()) { throw new \DomainException('仓库调拨须在业务事务内执行'); }
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false || $fromWarehouseId <= 0 || $toWarehouseId <= 0 || $fromWarehouseId === $toWarehouseId) { throw new \DomainException('请选择不同的有效仓库及正数调拨数量'); }
        $outbound = self::changeWithinTransaction($fromWarehouseId, $skuId, '-' . $quantity, '0.0000');
        if ($outbound === false) { throw new \RuntimeException('Insufficient warehouse SKU stock for transfer.'); }
        $inbound = self::changeWithinTransaction($toWarehouseId, $skuId, $quantity, '0.0000');
        if ($inbound === false) { throw new \RuntimeException('Unable to receive warehouse SKU transfer.'); }
        return ['outbound' => $outbound, 'inbound' => $inbound];
    }

    public static function available(int $warehouseId, int $skuId): string
    {
        $balance = self::findBalance($warehouseId, $skuId);
        return $balance ? self::decimal((string)$balance->available_qty) : '0.0000';
    }

    public static function onHand(int $warehouseId, int $skuId): string
    {
        $balance = self::findBalance($warehouseId, $skuId);
        return $balance ? self::decimal((string)$balance->on_hand_qty) : '0.0000';
    }

    public static function reserved(int $warehouseId, int $skuId): string
    {
        $balance = self::findBalance($warehouseId, $skuId);
        return $balance ? self::decimal((string)$balance->reserved_qty) : '0.0000';
    }

    /**
     * 让外层业务事务按权威顺序先取得 SKU、商品、仓库和余额锁，再锁业务来源。
     */
    public static function lockBalanceWithinTransaction(int $warehouseId, int $skuId): bool
    {
        $context = self::lockedContext($warehouseId, $skuId);
        if ($context === false) {
            return false;
        }
        WarehouseSkuBalance::where('tenant_id', self::tenantId())
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->lock(true)
            ->find();
        return true;
    }

    private static function change(int $warehouseId, int $skuId, string $onHandDelta, string $reservedDelta)
    {
        if ($warehouseId <= 0 || $skuId <= 0) {
            return false;
        }
        try {
            return Db::transaction(static fn() => self::changeWithinTransaction(
                $warehouseId,
                $skuId,
                $onHandDelta,
                $reservedDelta
            ));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function changeWithinTransaction(
        int $warehouseId,
        int $skuId,
        string $onHandDelta,
        string $reservedDelta,
        bool $allowAttributedNegative = false
    ) {
        if (!self::isSignedDecimal($onHandDelta) || !self::isSignedDecimal($reservedDelta)) {
            return false;
        }
        $context = self::lockedContext($warehouseId, $skuId);
        if ($context === false) {
            return false;
        }
        [$sku, $goods] = $context;
        $tenantId = self::tenantId();
        $goodsId = (int)$sku->goods_id;
        [$baseUnitId, $baseUnitName] = self::baseUnit($sku, $goods);

        $balance = WarehouseSkuBalance::where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->lock(true)
            ->find();
        if ($balance && ((int)$balance->goods_id !== $goodsId || (int)$balance->base_unit_id !== $baseUnitId)) {
            return false;
        }

        $beforeOnHand = $balance ? self::decimal((string)$balance->on_hand_qty) : '0.0000';
        $beforeReserved = $balance ? self::decimal((string)$balance->reserved_qty) : '0.0000';
        $beforeAvailable = $balance ? self::decimal((string)$balance->available_qty) : '0.0000';
        $afterOnHand = bcadd($beforeOnHand, $onHandDelta, self::SCALE);
        $afterReserved = bcadd($beforeReserved, $reservedDelta, self::SCALE);
        $afterAvailable = bcsub($afterOnHand, $afterReserved, self::SCALE);
        if (bccomp($afterReserved, '0.0000', self::SCALE) < 0) {
            return false;
        }
        if (!$allowAttributedNegative) {
            $deepensNegativeAvailability = bccomp($afterAvailable, '0.0000', self::SCALE) < 0
                && bccomp($afterAvailable, $beforeAvailable, self::SCALE) < 0;
            if ($deepensNegativeAvailability) {
                return false;
            }
        }

        if (!$balance) {
            $inserted = Db::name('warehouse_sku_balance')->insert([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'base_unit_id' => $baseUnitId,
                'base_unit_name' => $baseUnitName,
                'on_hand_qty' => $afterOnHand,
                'reserved_qty' => $afterReserved,
                'available_qty' => $afterAvailable,
                'version' => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            if ($inserted !== 1) {
                throw new \RuntimeException('Unable to create warehouse SKU balance.');
            }
        } else {
            $updated = WarehouseSkuBalance::where('id', (int)$balance->id)->update([
                'on_hand_qty' => $afterOnHand,
                'reserved_qty' => $afterReserved,
                'available_qty' => $afterAvailable,
                'version' => (int)$balance->version + 1,
                'update_time' => time(),
            ]);
            if ($updated === false) {
                throw new \RuntimeException('Unable to update warehouse SKU balance.');
            }
        }

        $total = WarehouseSkuBalance::where('tenant_id', $tenantId)
            ->where('goods_id', $goodsId)
            ->sum('on_hand_qty');
        $goodsStock = self::decimal($total === null ? '0.0000' : (string)$total);
        Goods::where('id', $goodsId)->where('tenant_id', $tenantId)->update([
            'stock' => $goodsStock,
            'update_time' => time(),
        ]);

        return [
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'before_on_hand_qty' => $beforeOnHand,
            'after_on_hand_qty' => $afterOnHand,
            'before_reserved_qty' => $beforeReserved,
            'after_reserved_qty' => $afterReserved,
            'before_available_qty' => $beforeAvailable,
            'after_available_qty' => $afterAvailable,
            'goods_stock' => $goodsStock,
        ];
    }

    /** @return array{0:GoodsSku,1:Goods}|false */
    private static function lockedContext(int $warehouseId, int $skuId): array|false
    {
        $tenantId = self::tenantId();
        if ($tenantId <= 0 || $warehouseId <= 0 || $skuId <= 0) {
            return false;
        }
        $sku = GoodsSku::where('id', $skuId)->where('tenant_id', $tenantId)->lock(true)->find();
        if (!$sku) {
            return false;
        }
        $goods = Goods::where('id', (int)$sku->goods_id)->where('tenant_id', $tenantId)->lock(true)->find();
        $warehouse = Db::name('warehouse')->where('id', $warehouseId)->where('tenant_id', $tenantId)->lock(true)->find();
        return (!$goods || !$warehouse) ? false : [$sku, $goods];
    }

    private static function findBalance(int $warehouseId, int $skuId)
    {
        $tenantId = self::tenantId();
        if ($tenantId <= 0 || $warehouseId <= 0 || $skuId <= 0) {
            return null;
        }
        $sku = GoodsSku::where('id', $skuId)->where('tenant_id', $tenantId)->find();
        if (!$sku) {
            return null;
        }
        [$baseUnitId] = self::baseUnit($sku);
        return WarehouseSkuBalance::where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('base_unit_id', $baseUnitId)
            ->find();
    }

    /** @return array{0:int,1:string} */
    private static function baseUnit(GoodsSku $sku, ?Goods $goods = null): array
    {
        if ($goods === null) {
            $goods = Goods::where('id', (int)$sku->goods_id)
                ->where('tenant_id', self::tenantId())
                ->find();
        }
        $unitId = (int)($sku->base_unit_id ?? 0);
        $unitName = trim((string)($sku->base_unit_name ?? ''));
        if ($unitId <= 0 && $goods) {
            $unitId = (int)($goods->unit_id ?? 0);
        }
        if ($unitName === '' && $goods) {
            $unitName = (string)($goods->units ?? '');
        }
        return [$unitId, $unitName];
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function normalizeQuantity(string $quantity)
    {
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $quantity)) {
            return false;
        }
        $quantity = self::decimal($quantity);
        return bccomp($quantity, '0.0000', self::SCALE) > 0 ? $quantity : false;
    }

    private static function normalizeNonNegativeQuantity(string $quantity): string|false
    {
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $quantity)) {
            return false;
        }
        return self::decimal($quantity);
    }

    private static function isSignedDecimal(string $value): bool
    {
        return preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value) === 1;
    }

    private static function decimal(string $value): string
    {
        return bcadd($value, '0', self::SCALE);
    }
}
