<?php
declare(strict_types=1);
namespace app\api\jxc\logic;

use think\facade\Db;

/** 报货交付采用的必要事实与显式履约进度；不从需求计数猜测实交。 */
final class CustomerReportQuantityRequirements
{
    public static function pricingUnit(array $item): int
    {
        $tenant = FinanceAccess::tenant();
        $latest = Db::name('customer_report_workspace_save')->where('tenant_id', $tenant)->where('report_id', $item['report_id'])->order('version desc')->find();
        $input = FinanceValue::decode($latest['input'] ?? '{}');
        foreach ($input['sales']['lines'] ?? [] as $line) {
            if ((int)$line['report_item_id'] === (int)$item['id']) return (int)$line['price_unit_id'];
        }
        $doc = Db::name('customer_report_sales_document')->where('tenant_id', $tenant)->where('report_id', $item['report_id'])->find();
        foreach (FinanceValue::decode($doc['snapshot'] ?? '{}')['lines'] ?? [] as $line) {
            if ((int)$line['report_item_id'] === (int)$item['id']) return (int)$line['price_unit_id'];
        }
        return (int)($item['pricing_unit_id'] ?: $item['base_unit_id']);
    }

    public static function forItem(array $item, array $units, ?int $pricingId = null): array
    {
        $baseId = (int)$item['base_unit_id']; $orderId = (int)$item['unit_id'];
        $pricingId ??= (int)(($item['pricing_unit_id'] ?? 0) ?: $baseId);
        $weightOnly = ($units[$baseId]['measure_type'] ?? '') === 'weight' && ($units[$pricingId]['measure_type'] ?? '') === 'weight';
        $required = [];
        foreach (array_unique([$baseId, $pricingId, ...(!$weightOnly || (int)($item['piece_weight_confirmed'] ?? 0) === 1 ? [$orderId] : [])]) as $id) {
            if (($units[$id]['measure_type'] ?? '') === 'count') $required[] = ['id' => $id, 'name' => $units[$id]['unit_name']];
        }
        return ['weight_only_fulfillment' => $weightOnly, 'net_weight_required' => ($units[$baseId]['measure_type'] ?? '') === 'weight' || ($units[$pricingId]['measure_type'] ?? '') === 'weight',
            'required_count_units' => $required, 'pricing_unit_id' => $pricingId];
    }

    public static function present(array $item): array
    {
        return self::forItem($item, TradeQuantityService::boundUnits($item), self::pricingUnit($item));
    }

    public static function continuing(array $item): bool
    {
        return ($item['fulfillment_status'] ?? '') === 'partially_delivered_pending';
    }

    public static function assertCompleteWeight(array $item): void
    {
        $minimum = (string)($item['acceptable_base_qty_min'] ?? '0');
        $maximum = (string)($item['acceptable_base_qty_max'] ?? '0');
        $weight = TradeQuantityService::snapshot($item)['actual_delivery_weight'] ?? null;
        if (bccomp($maximum, '0', 4) > 0 && ($weight === null || bccomp($weight, $minimum, 4) < 0 || bccomp($weight, $maximum, 4) > 0)) {
            throw new \DomainException('实际总重不符合客户明确的总重范围');
        }
    }

    public static function progress(array $item, array $groups, array $requirements): array
    {
        $delivered = bccomp((string)$item['fulfilled_base_qty'], '0', 4) > 0;
        $continuing = self::continuing($item);
        $closed = in_array($item['fulfillment_status'], ['delivered', 'partially_delivered_completed', 'undelivered', 'delivery_exception_completed'], true);
        $pendingGroups = array_filter($groups, static fn($group) => empty($group['delivered']));
        $remaining = null;
        $orderUnit = TradeQuantityService::unit((int)$item['unit_id']);
        $target = ($orderUnit['measure_type'] ?? '') === 'weight' && $orderUnit['jin_factor'] ? bcmul($item['order_qty'], $orderUnit['jin_factor'], 4) : null;
        if ($target === null && bccomp((string)($item['acceptable_base_qty_min'] ?? '0'), '0', 4) > 0 && bccomp($item['acceptable_base_qty_min'], $item['acceptable_base_qty_max'], 4) === 0) $target = $item['acceptable_base_qty_min'];
        if ($target !== null) {
            $fulfilledWeight = '0';
            foreach ($groups as $group) if (!empty($group['delivered']) && $group['actual_delivery_weight'] !== null) $fulfilledWeight = bcadd($fulfilledWeight, $group['actual_delivery_weight'], 4);
            $remaining = bcsub($target, $fulfilledWeight, 4);
            if (bccomp($remaining, '0', 4) < 0) $remaining = '0.0000';
        }
        return ['state' => $closed ? 'completed' : ($continuing ? 'continuing' : 'open'), 'remaining_weight' => $remaining,
            'can_append' => $requirements['weight_only_fulfillment'] && $continuing && $delivered && !$pendingGroups,
            'can_complete' => $requirements['weight_only_fulfillment'] && $continuing && $delivered && !$pendingGroups];
    }
}
