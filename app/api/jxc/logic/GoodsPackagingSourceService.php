<?php
declare(strict_types=1);
namespace app\api\jxc\logic;
use think\facade\Db;

/** A verified physical receipt, referring to an existing conversion rule; not a second unit system. */
final class GoodsPackagingSourceService
{
    public static function recordInbound(int $stockFlowId, int $ruleId, string $packages, int $unitId, bool $reliableConfirmed = false, ?int $supplierId = null, ?string $businessDate = null): int
    {
        if (!$reliableConfirmed) { throw new \DomainException('固定包装来源需要明确核实完整包装和入出库净重口径'); }
        if (!\app\common\service\goods\GoodsMaintenancePermissionService::canMaintain()) {
            throw new \DomainException('核实新的固定包装来源需要商品维护权限');
        }
        $tenant = (int)request()->tenantId;
        $flow = Db::name('stock_flow')->where('tenant_id', $tenant)->where('id', $stockFlowId)->lock(true)->find();
        if (!$flow || (int)$flow['flow_type'] !== 1) { throw new \DomainException('可靠包装必须关联本门店真实入库流水'); }
        $sku = Db::name('goods_sku')->where('tenant_id', $tenant)->where('id', $flow['sku_id'])->find();
        $rule = Db::name('goods_unit_conversion_rule')->where('tenant_id', $tenant)->where('id', $ruleId)->where('status', 1)->find();
        if (!$rule || !$sku || (int)$rule['from_unit_id'] !== $unitId || (int)$rule['to_unit_id'] !== (int)$sku['base_unit_id']
            || ((int)$rule['goods_id'] > 0 && (int)$rule['goods_id'] !== (int)$flow['goods_id'])
            || ((int)$rule['sku_id'] > 0 && (int)$rule['sku_id'] !== (int)$flow['sku_id'])) {
            throw new \DomainException('包装规则与本次商品、库存项或单位不符');
        }
        $date = $businessDate ?? date('Y-m-d', (int)$flow['create_time']);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new \DomainException('包装来源真实入库日期无效'); }
        if ((!empty($rule['effective_date']) && $date < $rule['effective_date']) || (!empty($rule['expire_date']) && $date > $rule['expire_date'])) {
            throw new \DomainException('包装规则不适用于该入库日期');
        }
        if ((int)$rule['supplier_id'] > 0 && (int)$rule['supplier_id'] !== $supplierId) { throw new \DomainException('该包装规则与本次真实供应商来源不符'); }
        $rule['receipt_context'] = ['supplier_id' => $supplierId, 'business_date' => $date];
        $unit = TradeQuantityService::unit($unitId); $base = TradeQuantityService::unit((int)$sku['base_unit_id']);
        if (!$unit || $unit['measure_type'] !== 'count' || !$base || $base['measure_type'] !== 'weight') { throw new \DomainException('固定净重包装须使用已配置的计数包装单位及重量库存单位'); }
        $count = TradeQuantityFacts::decimal($packages, 0);
        $calculated = bcmul($count, (string)$rule['ratio'], 6);
        if (bccomp($calculated, (string)$flow['quantity'], 6) !== 0) {
            throw new \DomainException('实际入库净重与包装规则不一致，该来源不能自动折重');
        }
        $existing = Db::name('goods_packaging_source')->where('tenant_id', $tenant)->where('stock_flow_id', $stockFlowId)->find();
        if ($existing) {
            if ((int)$existing['rule_id'] !== $ruleId || bccomp($existing['package_quantity'], $count, 4) !== 0) { throw new \DomainException('已核实包装来源不能重定义'); }
            return (int)$existing['id'];
        }
        $peers = self::peers((int)$flow['warehouse_id'], (int)$flow['sku_id']);
        $beforeId = (int)Db::name('stock_flow')->where('tenant_id', $tenant)->where('warehouse_id', $flow['warehouse_id'])
            ->where('sku_id', $flow['sku_id'])->where('id', '<', $stockFlowId)->max('id');
        $remaining = '0.0000';
        foreach ($peers as $peer) {
            if ((int)$peer['last_stock_flow_id'] !== $beforeId || $peer['status'] !== 'verified') { throw new \DomainException('该库存项有无法归属的移动，暂停包装自动折重'); }
            $remaining = bcadd($remaining, $peer['remaining_base_quantity'], 4);
        }
        if (bccomp($remaining, (string)$flow['before_stock'], 4) !== 0) { throw new \DomainException('本仓该库存项混有未识别来源，不能启用固定包装自动折重'); }
        Db::name('goods_packaging_source')->where('tenant_id', $tenant)->where('warehouse_id', $flow['warehouse_id'])->where('sku_id', $flow['sku_id'])
            ->update(['last_stock_flow_id' => $stockFlowId]);
        return (int)Db::name('goods_packaging_source')->insertGetId(['tenant_id' => $tenant, 'warehouse_id' => $flow['warehouse_id'],
            'goods_id' => $flow['goods_id'], 'sku_id' => $flow['sku_id'], 'stock_flow_id' => $stockFlowId, 'rule_id' => $ruleId,
            'rule_snapshot' => json_encode($rule, JSON_UNESCAPED_UNICODE), 'package_unit_id' => $unitId, 'package_quantity' => $count,
            'remaining_package_quantity' => $count, 'remaining_base_quantity' => $flow['quantity'], 'base_unit_id' => $sku['base_unit_id'],
            'last_stock_flow_id' => $stockFlowId, 'status' => 'verified', 'verified_by' => (int)(request()->userId ?? request()->adminId ?? 0), 'verified_at' => time()]);
    }

    public static function verify(array $input, array $item): array
    {
        if (($input['intact'] ?? false) !== true) { throw new \DomainException('包装已开封或状态未核实，请录实际净重'); }
        $sources = self::peers((int)$item['warehouse_id'], (int)$item['sku_id']); $source = null; $total = '0.0000';
        $latest = self::latestFlow((int)$item['warehouse_id'], (int)$item['sku_id']);
        foreach ($sources as $row) {
            if ($row['status'] !== 'verified' || (int)$row['last_stock_flow_id'] !== $latest) { throw new \DomainException('包装来源发生未归属移动或已失效，请改实称并核清来源'); }
            $total = bcadd($total, $row['remaining_base_quantity'], 4);
            if ((int)$row['id'] === (int)($input['source_id'] ?? 0)) { $source = $row; }
        }
        if (!$source || bccomp(WarehouseSkuBalanceService::onHand((int)$item['warehouse_id'], (int)$item['sku_id']), $total, 4) !== 0) {
            throw new \DomainException('包装来源与本仓实际余额不一致，请改实称');
        }
        if ((int)($input['unit_id'] ?? 0) !== (int)$source['package_unit_id']) { throw new \DomainException('包装计数单位与来源不一致'); }
        $unit = TradeQuantityService::unit((int)$source['package_unit_id']);
        $base = TradeQuantityService::unit((int)$source['base_unit_id']);
        if (!$unit || !$base || $unit['measure_type'] !== 'count' || $base['measure_type'] !== 'weight' || !$base['jin_factor']) {
            throw new \DomainException('包装或库存单位已停用或类型改变，请核清来源后继续');
        }
        $binding = Db::name('goods_units_binding')->where('tenant_id', (int)request()->tenantId)->where('goods_id', $item['goods_id'])
            ->where('unit_id', $source['package_unit_id'])->where('status', 1)->find();
        if (!$binding) { throw new \DomainException('该包装单位已从商品停用，不能用于新交付'); }
        $quantity = TradeQuantityFacts::decimal($input['quantity'] ?? null, 0);
        if (bccomp($quantity, $source['remaining_package_quantity'], 4) > 0) { throw new \DomainException('包装来源剩余数量不足'); }
        $rule = json_decode($source['rule_snapshot'], true);
        $baseQty = TradeQuantityFacts::exactMultiply($quantity, $rule['ratio'], (int)$base['quantity_precision']);
        return ['source_id' => (int)$source['id'], 'unit_id' => (int)$source['package_unit_id'], 'quantity' => $quantity,
            'base_quantity' => $baseQty, 'net_weight' => TradeQuantityFacts::exactMultiply($baseQty, $base['jin_factor']), 'rule_snapshot' => $rule,
            'stock_flow_id' => (int)$source['stock_flow_id'], 'intact' => true, 'verified_by' => (int)$source['verified_by'], 'verified_at' => (int)$source['verified_at']];
    }

    public static function consume(array $groups, array $item): void
    {
        $packages = array_values(array_filter($groups, static fn(array $group): bool => isset($group['packaging'])));
        if ($packages === []) { return; }
        if (count($packages) !== count($groups)) { throw new \DomainException('同次交付的包装与散货来源不能混用自动折重'); }
        foreach ($packages as $group) {
            $package = $group['packaging'];
            $source = Db::name('goods_packaging_source')->where('tenant_id', (int)request()->tenantId)->where('id', $package['source_id'])->lock(true)->find();
            if (!$source || bccomp($source['remaining_package_quantity'], $package['quantity'], 4) < 0) { throw new \DomainException('包装来源已被其他交付使用，请重新核实'); }
            Db::name('goods_packaging_source')->where('id', $source['id'])->where('tenant_id', (int)request()->tenantId)->update([
                'remaining_package_quantity' => bcsub($source['remaining_package_quantity'], $package['quantity'], 4),
                'remaining_base_quantity' => bcsub($source['remaining_base_quantity'], $package['base_quantity'], 4)]);
        }
        Db::name('goods_packaging_source')->where('tenant_id', (int)request()->tenantId)->where('warehouse_id', $item['warehouse_id'])->where('sku_id', $item['sku_id'])
            ->update(['last_stock_flow_id' => self::latestFlow((int)$item['warehouse_id'], (int)$item['sku_id'])]);
    }

    public static function sources(int $warehouse, int $sku): array { return self::peers($warehouse, $sku); }
    private static function peers(int $warehouse, int $sku): array
    {
        return Db::name('goods_packaging_source')->where('tenant_id', (int)request()->tenantId)->where('warehouse_id', $warehouse)->where('sku_id', $sku)->order('id')->lock(true)->select()->toArray();
    }
    private static function latestFlow(int $warehouse, int $sku): int
    {
        return (int)Db::name('stock_flow')->where('tenant_id', (int)request()->tenantId)->where('warehouse_id', $warehouse)->where('sku_id', $sku)->max('id');
    }
}
