<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 旧正式版本只拥有原覆盖量；后来交付通过独立结算继续处理。 */
final class FinanceLegacySales
{
    public static function context(array $order): array
    {
        $tenant = FinanceAccess::tenant(); $version = (int)($order['settlement_version'] ?? 0); $id = (int)$order['id'];
        $finance = $version ? Db::name('finance_sales_version')->where('tenant_id', $tenant)->where('order_id', $id)->where('version', $version)->find() : null;
        $raw = $version ? Db::name('sales_order_version')->where('tenant_id', $tenant)->where('order_id', $id)->where('version', $version)->value('snapshot_json') : null;
        $lines = [];
        foreach ($raw ? FinanceValue::decode($raw)['lines'] ?? [] : [] as $line) { $lines[(int)$line['order_goods_id']] = $line; }
        $dates = [];
        $times = Db::name('fulfillment_delivery_item')->alias('d')->join('fulfillment_delivery_event e', "e.id=d.delivery_event_id AND e.tenant_id=d.tenant_id AND e.status='completed'")
            ->where('d.tenant_id', $tenant)->where('d.sales_order_id', $id)->group('e.delivered_time')->column('e.delivered_time');
        foreach ($times as $time) { $dates[date('Y-m-d', (int)$time)] = true; } $dates = array_keys($dates); sort($dates);
        $explicit = Db::name('finance_sales_coverage')->where('tenant_id', $tenant)->where('order_id', $id)->count() > 0;
        $preserve = $version > 0 && $explicit;
        if ($lines) {
            $current = Db::name('order_goods')->where('tenant_id', $tenant)->where('order_id', $id)->where('order_type', 'sales')->column('base_quantity', 'id');
            if (count($current) !== count($lines)) { $preserve = true; }
            foreach ($current as $lineId => $quantity) { if (!isset($lines[$lineId]) || bccomp((string)$quantity, (string)$lines[$lineId]['actual_delivery_weight'], 4) !== 0) { $preserve = true; } }
        }
        return ['business_date' => $finance['business_date'] ?? ($version ? date('Y-m-d', (int)$order['datetimesingle']) : (count($dates) === 1 ? $dates[0] : null)),
            'delivery_dates' => $dates, 'requires_delivery_batches' => !$version && ($explicit || count($dates) !== 1),
            'preserves_legacy_coverage' => $preserve, 'legacy_lines' => $lines];
    }

    public static function assertSnapshot(array $context, array $snapshot): void
    {
        if ($context['requires_delivery_batches']) { throw new \DomainException('该订单须按真实交付逐项结算，请进入交付分次结算'); }
        if (!$context['preserves_legacy_coverage']) { return; }
        if (count($snapshot['lines']) !== count($context['legacy_lines']) || !$context['legacy_lines']) { throw new \DomainException('原正式销售覆盖明细不完整，请先核对'); }
        foreach ($snapshot['lines'] as $line) {
            $old = $context['legacy_lines'][(int)$line['order_goods_id']] ?? null;
            if (!$old || bccomp((string)$line['actual_delivery_weight'], (string)$old['actual_delivery_weight'], 4) !== 0 || bccomp((string)$line['actual_delivery_delta'], '0', 4) !== 0) {
                throw new \DomainException('该订单已有后续交付，旧版金额更正须保留原覆盖量；实际交付更正应从原实物来源核对');
            }
        }
    }
}
