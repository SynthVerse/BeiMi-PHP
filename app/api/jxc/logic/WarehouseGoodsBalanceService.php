<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\Goods;
use app\common\model\jxc\WarehouseGoodsBalance;
use think\facade\Db;

/**
 * 仓库商品库存的唯一写入入口。
 *
 * Goods.stock 仅保存所有仓库现存量的汇总展示；可用库存以本表的
 * on_hand_qty - reserved_qty 为准。
 */
class WarehouseGoodsBalanceService
{
    private const SCALE = 4;

    public static function inbound(int $warehouseId, int $goodsId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false) {
            return false;
        }

        return self::change($warehouseId, $goodsId, $quantity, '0.0000');
    }

    public static function outbound(int $warehouseId, int $goodsId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false) {
            return false;
        }

        return self::change($warehouseId, $goodsId, '-' . $quantity, '0.0000');
    }

    public static function reserve(int $warehouseId, int $goodsId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false) {
            return false;
        }

        return self::change($warehouseId, $goodsId, '0.0000', $quantity);
    }

    /**
     * 在调用方已开启的数据库事务中执行预留，避免嵌套事务保存点冲突。
     */
    public static function reserveWithinTransaction(int $warehouseId, int $goodsId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::changeWithinTransaction($warehouseId, $goodsId, '0.0000', $quantity);
    }

    /**
     * 在同一行锁内预留当前可用库存与请求量的较小值。
     *
     * 返回实际预留的基础单位数量；返回 false 表示参数或租户/仓库/商品无效。
     * 该方法供新客户报货链路处理缺货，不读取 Goods.stock。
     */
    public static function reserveUpTo(int $warehouseId, int $goodsId, string $quantity): string|false
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false || $warehouseId <= 0 || $goodsId <= 0) {
            return false;
        }

        try {
            return Db::transaction(static fn() => self::reserveUpToWithinTransaction($warehouseId, $goodsId, $quantity));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 在调用方已开启的数据库事务中尽量预留可用量；不再建立嵌套事务。
     */
    public static function reserveUpToWithinTransaction(int $warehouseId, int $goodsId, string $quantity): string|false
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false || $warehouseId <= 0 || $goodsId <= 0) {
            return false;
        }
        $tenantId = self::tenantId();
        if ($tenantId <= 0) {
            return false;
        }
        $goods = Goods::where('id', $goodsId)->where('tenant_id', $tenantId)->lock(true)->find();
        if (!$goods || !Db::name('warehouse')->where('id', $warehouseId)->where('tenant_id', $tenantId)->lock(true)->find()) {
            return false;
        }
        $balance = WarehouseGoodsBalance::where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)->where('goods_id', $goodsId)
            ->where('base_unit_id', (int)($goods->unit_id ?? 0))->lock(true)->find();
        $available = $balance ? self::decimal((string)$balance->available_qty) : '0.0000';
        $reserved = bccomp($available, $quantity, self::SCALE) < 0 ? $available : $quantity;
        if (bccomp($reserved, '0.0000', self::SCALE) <= 0) {
            return '0.0000';
        }
        return self::changeWithinTransaction($warehouseId, $goodsId, '0.0000', $reserved) === false ? false : $reserved;
    }

    public static function release(int $warehouseId, int $goodsId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false) {
            return false;
        }

        return self::change($warehouseId, $goodsId, '0.0000', '-' . $quantity);
    }

    /** 在调用方已开启的数据库事务中释放预留。 */
    public static function releaseWithinTransaction(int $warehouseId, int $goodsId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::changeWithinTransaction($warehouseId, $goodsId, '0.0000', '-' . $quantity);
    }

    public static function consumeReserved(int $warehouseId, int $goodsId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false) {
            return false;
        }

        return self::change($warehouseId, $goodsId, '-' . $quantity, '-' . $quantity);
    }

    /** 在调用方已开启的数据库事务中消耗预留并出库。 */
    public static function consumeReservedWithinTransaction(int $warehouseId, int $goodsId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        return $quantity === false ? false : self::changeWithinTransaction($warehouseId, $goodsId, '-' . $quantity, '-' . $quantity);
    }

    public static function transfer(int $fromWarehouseId, int $toWarehouseId, int $goodsId, string $quantity)
    {
        $quantity = self::normalizeQuantity($quantity);
        if ($quantity === false || $fromWarehouseId <= 0 || $toWarehouseId <= 0 || $fromWarehouseId === $toWarehouseId) {
            return false;
        }

        try {
            return Db::transaction(static function () use ($fromWarehouseId, $toWarehouseId, $goodsId, $quantity) {
                $outbound = self::changeWithinTransaction($fromWarehouseId, $goodsId, '-' . $quantity, '0.0000');
                if ($outbound === false) {
                    throw new \RuntimeException('Insufficient warehouse stock for transfer.');
                }

                $inbound = self::changeWithinTransaction($toWarehouseId, $goodsId, $quantity, '0.0000');
                if ($inbound === false) {
                    throw new \RuntimeException('Unable to receive warehouse transfer.');
                }

                return ['outbound' => $outbound, 'inbound' => $inbound];
            });
        } catch (\Throwable) {
            return false;
        }
    }

    public static function available(int $warehouseId, int $goodsId): string
    {
        $balance = self::findBalance($warehouseId, $goodsId);
        if (!$balance) {
            return '0.0000';
        }

        return self::decimal((string)$balance->available_qty);
    }

    public static function onHand(int $warehouseId, int $goodsId): string
    {
        $balance = self::findBalance($warehouseId, $goodsId);
        return $balance ? self::decimal((string)$balance->on_hand_qty) : '0.0000';
    }

    public static function reserved(int $warehouseId, int $goodsId): string
    {
        $balance = self::findBalance($warehouseId, $goodsId);
        return $balance ? self::decimal((string)$balance->reserved_qty) : '0.0000';
    }

    private static function change(int $warehouseId, int $goodsId, string $onHandDelta, string $reservedDelta)
    {
        if ($warehouseId <= 0 || $goodsId <= 0) {
            return false;
        }

        try {
            return Db::transaction(static function () use ($warehouseId, $goodsId, $onHandDelta, $reservedDelta) {
                return self::changeWithinTransaction($warehouseId, $goodsId, $onHandDelta, $reservedDelta);
            });
        } catch (\Throwable) {
            return false;
        }
    }

    private static function changeWithinTransaction(int $warehouseId, int $goodsId, string $onHandDelta, string $reservedDelta)
    {
        $tenantId = self::tenantId();
        if ($tenantId <= 0 || !self::isSignedDecimal($onHandDelta) || !self::isSignedDecimal($reservedDelta)) {
            return false;
        }

        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', $tenantId)
            ->lock(true)
            ->find();
        if (!$goods || !Db::name('warehouse')->where('id', $warehouseId)->where('tenant_id', $tenantId)->lock(true)->find()) {
            return false;
        }

        $baseUnitId = (int)($goods->unit_id ?? 0);
        $baseUnitName = (string)($goods->units ?? '');
        $balance = WarehouseGoodsBalance::where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('goods_id', $goodsId)
            ->lock(true)
            ->find();
        if ($balance && (int)$balance->base_unit_id !== $baseUnitId) {
            return false;
        }

        $beforeOnHand = $balance ? self::decimal((string)$balance->on_hand_qty) : '0.0000';
        $beforeReserved = $balance ? self::decimal((string)$balance->reserved_qty) : '0.0000';
        $beforeAvailable = $balance ? self::decimal((string)$balance->available_qty) : '0.0000';
        $afterOnHand = bcadd($beforeOnHand, $onHandDelta, self::SCALE);
        $afterReserved = bcadd($beforeReserved, $reservedDelta, self::SCALE);
        $afterAvailable = bcsub($afterOnHand, $afterReserved, self::SCALE);

        if (bccomp($afterOnHand, '0.0000', self::SCALE) < 0
            || bccomp($afterReserved, '0.0000', self::SCALE) < 0
            || bccomp($afterAvailable, '0.0000', self::SCALE) < 0) {
            return false;
        }

        if (!$balance) {
            WarehouseGoodsBalance::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'goods_id' => $goodsId,
                'base_unit_id' => $baseUnitId,
                'base_unit_name' => $baseUnitName,
                'on_hand_qty' => $afterOnHand,
                'reserved_qty' => $afterReserved,
                'available_qty' => $afterAvailable,
                'version' => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        } else {
            $updated = WarehouseGoodsBalance::where('id', (int)$balance->id)->update([
                'on_hand_qty' => $afterOnHand,
                'reserved_qty' => $afterReserved,
                'available_qty' => $afterAvailable,
                'version' => (int)$balance->version + 1,
                'update_time' => time(),
            ]);
            if ($updated === false) {
                throw new \RuntimeException('Unable to update warehouse balance.');
            }
        }

        $total = WarehouseGoodsBalance::where('tenant_id', $tenantId)
            ->where('goods_id', $goodsId)
            ->sum('on_hand_qty');
        $goodsStock = self::decimal($total === null ? '0.0000' : (string)$total);
        Goods::where('id', $goodsId)->where('tenant_id', $tenantId)->update([
            'stock' => $goodsStock,
            'update_time' => time(),
        ]);

        return [
            'before_on_hand_qty' => $beforeOnHand,
            'after_on_hand_qty' => $afterOnHand,
            'before_reserved_qty' => $beforeReserved,
            'after_reserved_qty' => $afterReserved,
            'before_available_qty' => $beforeAvailable,
            'after_available_qty' => $afterAvailable,
            'goods_stock' => $goodsStock,
        ];
    }

    private static function findBalance(int $warehouseId, int $goodsId)
    {
        $tenantId = self::tenantId();
        if ($tenantId <= 0 || $warehouseId <= 0 || $goodsId <= 0) {
            return null;
        }

        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', $tenantId)
            ->find();
        if (!$goods) {
            return null;
        }

        return WarehouseGoodsBalance::where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('goods_id', $goodsId)
            ->where('base_unit_id', (int)($goods->unit_id ?? 0))
            ->find();
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

    private static function isSignedDecimal(string $value): bool
    {
        return preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value) === 1;
    }

    private static function decimal(string $value): string
    {
        return bcadd($value, '0', self::SCALE);
    }
}
