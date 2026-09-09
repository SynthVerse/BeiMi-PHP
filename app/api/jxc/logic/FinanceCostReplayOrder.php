<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 盘点保存当时已知事件的截止点；之后补录的旧日期事实仍留在截止之后。 */
final class FinanceCostReplayOrder
{
    public static function ordered(array $events): array
    {
        $voids = []; $origins = [];
        foreach ($events as $event) {
            if ($event['type'] !== 'customer_return_void') { continue; }
            $reference = $event['return_reference'];
            if (isset($voids[$reference])) { throw new \DomainException('同一退货成本不能重复撤销'); }
            $voids[$reference] = true;
        }
        foreach ($events as $event) {
            if (!isset($voids[$event['reference']])) { continue; }
            if (($event['snapshot']['order_type'] ?? '') !== 'finance_customer_return' || !in_array($event['type'], ['receive', 'restore'], true)) { throw new \DomainException('只能关联冲回客户实物验收成本'); }
            if ($event['type'] === 'receive') { $origins[$event['origin']] = true; }
            $voids[$event['reference']] = false;
        }
        if (in_array(true, $voids, true)) { throw new \DomainException('撤销缺少原客户退货成本事件'); }
        $events = array_values(array_filter($events, static fn(array $event): bool => !array_key_exists($event['reference'], $voids)
            && !(in_array($event['type'], ['adjust', 'reestimate'], true) && isset($origins[$event['origin']]))));
        $reversals = []; $targets = [];
        foreach ($events as $event) {
            if ($event['type'] === 'count_reverse') { $reversals[$event['count_reference']] = bcadd($reversals[$event['count_reference']] ?? '0', $event['quantity'], 12); }
        }
        foreach ($events as &$event) {
            if ($event['type'] !== 'count') { continue; }
            $event['reversed_quantity'] = $reversals[$event['reference']] ?? '0.000000000000';
            if (bccomp($event['reversed_quantity'], '0', 12) < 0 || bccomp($event['reversed_quantity'], $event['quantity'], 12) > 0) { throw new \DomainException('累计反向盘点数量超过原差额'); }
            $targets[$event['target_reference']] = bcsub($event['quantity'], $event['reversed_quantity'], 4); unset($reversals[$event['reference']]);
        } unset($event);
        if ($reversals) { throw new \DomainException('关联反向缺少原盘点成本事件'); }
        foreach ($events as &$event) {
            if ($event['type'] === 'reclassify' && isset($targets[$event['target_reference']])) {
                $available = $targets[$event['target_reference']];
                if (bccomp($event['quantity'], $available, 4) > 0) { $event['quantity'] = $available; }
                $event['quantity'] = bcadd($event['quantity'], '0', 4);
                $targets[$event['target_reference']] = bcsub($available, $event['quantity'], 4);
                $event['skip_count_reclassification'] = bccomp($event['quantity'], '0', 12) === 0;
            }
        } unset($event);
        $counts = []; $remaining = [];
        foreach ($events as $event) {
            if ($event['type'] === 'count') { $counts[] = $event; }
            else { $remaining[] = $event; }
        }
        usort($counts, static fn(array $a, array $b): int => $a['count_cutoff_event_id'] <=> $b['count_cutoff_event_id']);
        $ordered = [];
        foreach ($counts as $count) {
            $segment = []; $later = [];
            foreach ($remaining as $event) {
                if ($event['_journal_id'] <= $count['count_cutoff_event_id']) { $segment[] = $event; }
                else { $later[] = $event; }
            }
            $ordered = array_merge($ordered, self::segment($segment), [$count]);
            $remaining = $later;
        }
        return array_merge($ordered, self::segment($remaining));
    }

    private static function segment(array $events): array
    {
        $physical = []; $adjustments = [];
        foreach ($events as $event) {
            if (in_array($event['type'], ['adjust', 'reestimate'], true)) { $adjustments[] = $event; }
            else { $physical[] = $event; }
        }
        // 稳定排序保留同日确认顺序；补价沿本段已经形成的来源去向分摊。
        usort($physical, static fn(array $a, array $b): int => strcmp($a['business_date'], $b['business_date']));
        return array_merge($physical, $adjustments);
    }
}
