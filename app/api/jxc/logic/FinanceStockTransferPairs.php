<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 原调拨事务保存事实配对，财务启用承接只读配对，不猜测两条独立流水的关系。 */
final class FinanceStockTransferPairs
{
    private static function installed(): bool
    {
        return Db::query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1',
            [Db::name('finance_stock_transfer_pair')->getTable()]) !== [];
    }

    public static function recordWithinTransaction(int $out, int $in, int $from, int $to, int $sku, string $quantity): void
    {
        if (!self::installed()) { return; }
        $pdo = Db::connect()->getPdo();
        if (!$pdo || !$pdo->inTransaction()) { throw new \DomainException('调拨配对须与实物出入库在同一事务内保存'); }
        $tenant = FinanceAccess::tenant();
        $rows = Db::name('stock_flow')->where('tenant_id', $tenant)->whereIn('id', [$out, $in])->order('id')->lock(true)->select()->toArray();
        $flows = array_column($rows, null, 'id');
        $pair = ['tenant_id' => $tenant, 'outbound_flow_id' => $out, 'inbound_flow_id' => $in,
            'from_warehouse_id' => $from, 'to_warehouse_id' => $to, 'sku_id' => $sku, 'quantity' => FinancePurchaseSettlement::quantity($quantity),
            'occurred_at' => (int)($flows[$out]['create_time'] ?? 0)];
        self::validate($pair, $flows);
        Db::name('finance_stock_transfer_pair')->insert($pair + ['snapshot' => FinanceValue::json($rows), 'actor' => FinanceValue::json(FinanceAccess::actor())]);
    }

    public static function forFlows(int $tenant, array $flows): array
    {
        if (!$flows || !self::installed()) { return []; }
        $ids = array_column($flows, 'id'); $result = []; $byId = array_column($flows, null, 'id');
        $rows = Db::name('finance_stock_transfer_pair')->where('tenant_id', $tenant)
            ->where(function ($query) use ($ids): void { $query->whereIn('outbound_flow_id', $ids)->whereOr('inbound_flow_id', 'in', $ids); })
            ->order('id')->lock(true)->select()->toArray();
        foreach ($rows as $pair) {
            self::validate($pair, $byId);
            $result[(int)$pair['outbound_flow_id']] = $pair; $result[(int)$pair['inbound_flow_id']] = $pair;
        }
        return $result;
    }

    private static function validate(array $pair, array $flows): void
    {
        $out = $flows[$pair['outbound_flow_id']] ?? null; $in = $flows[$pair['inbound_flow_id']] ?? null;
        if (!$out || !$in || (int)$pair['from_warehouse_id'] === (int)$pair['to_warehouse_id']) { throw new \DomainException('调拨两侧实物来源不完整，须核实后承接'); }
        foreach ([[$out, 'from_warehouse_id', '-'], [$in, 'to_warehouse_id', '']] as [$flow, $warehouseKey, $sign]) {
            if ((int)$flow['tenant_id'] !== (int)$pair['tenant_id'] || (int)$flow['warehouse_id'] !== (int)$pair[$warehouseKey]
                || (int)$flow['sku_id'] !== (int)$pair['sku_id'] || (int)$flow['create_time'] !== (int)$pair['occurred_at']
                || bccomp(bcsub($flow['after_stock'], $flow['before_stock'], 4), $sign . $pair['quantity'], 4) !== 0) {
                throw new \DomainException('调拨配对与实际仓库、商品、数量或日期不一致，须核实后承接');
            }
        }
    }
}
