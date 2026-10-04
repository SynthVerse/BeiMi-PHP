<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 从真实库存来源重建截止依据；晚补录不沿用旧差额，正常后续移动不覆盖余额。 */
final class InventoryCountBasis
{
    public static function current(array $basis, array $line): array
    {
        $tenant = FinanceAccess::tenant(); $cutoff = strtotime($basis['cutoff_at']);
        $flows = Db::name('stock_flow')->where('tenant_id', $tenant)->where('warehouse_id', $basis['warehouse_id'])
            ->where('sku_id', $line['sku_id'])->where('id', '>', $line['last_stock_flow_id'])->order('id')->select()->toArray();
        $events = Db::name('finance_cost_event')->where('tenant_id', $tenant)->where('sku_id', $line['sku_id'])
            ->where('id', '>', $line['last_cost_event_id'])->order('id')->select()->toArray();
        $byFlow = []; $costBasis = [];
        foreach ($events as $event) {
            $snapshot = FinanceValue::decode($event['snapshot']);
            if (preg_match('/^stock:(\d+)$/D', $event['reference'], $match)) { $byFlow[(int)$match[1]] = $event; }
            if ($event['business_date'] < substr($basis['cutoff_at'], 0, 10)) { $costBasis[] = [$event['id'], $event['fingerprint']]; }
        }
        $late = '0.0000'; $lateFacts = []; $movements = [];
        foreach ($flows as $flow) {
            $id = (int)$flow['id']; $date = $byFlow[$id]['business_date'] ?? date('Y-m-d', (int)$flow['create_time']);
            $costSnapshot = isset($byFlow[$id]) ? FinanceValue::decode($byFlow[$id]['snapshot']) : [];
            $at = (int)($costSnapshot['occurred_at'] ?? $flow['create_time']);
            if ($flow['order_type'] === 'sales_delivery' && !empty($costSnapshot['delivery_event_id'])) {
                $delivery = Db::name('fulfillment_delivery_event')->where('tenant_id', $tenant)->where('id', $costSnapshot['delivery_event_id'])->find();
                if ($delivery) { $at = (int)(($delivery['actual_handoff_time'] ?? 0) ?: $delivery['delivered_time']); }
            }
            $before = $date < substr($basis['cutoff_at'], 0, 10) || $at < $cutoff;
            $delta = (int)$flow['flow_type'] === 1 ? bcadd($flow['quantity'], '0', 4) : bcsub('0', $flow['quantity'], 4);
            $fact = ['stock_flow_id' => $id, 'quantity_delta' => $delta, 'occurred_at' => $at,
                'business_date' => $date, 'order_type' => $flow['order_type'], 'order_sn' => $flow['order_sn'], 'before_cutoff' => $before];
            if ($before) { $late = bcadd($late, $delta, 4); $lateFacts[] = $fact; }
            else { $movements[] = $fact; }
        }
        return ['basis_token' => hash('sha256', FinanceValue::json([$line, $lateFacts, $costBasis])),
            'book_quantity' => bcadd($line['book_quantity'], $late, 4), 'original_book_quantity' => $line['book_quantity'],
            'late_quantity' => $late, 'late_movements' => $lateFacts, 'movements' => $movements,
            'cost_basis_changed' => (bool)($lateFacts || $costBasis)];
    }

    public static function reconcile(array $raw, array $facts, array $current): array
    {
        if (!hash_equals($current['basis_token'], (string)($raw['basis_token'] ?? ''))) {
            throw new \DomainException('盘点截止前来源已有变化，请重新读取依据并核对实盘；原输入保留');
        }
        $review = $raw['movement_review'] ?? []; $movements = $current['movements'];
        $ids = $review['included_flow_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count(array_unique($ids)) !== count($ids)) { throw new \DomainException('期间移动不能重复选择'); }
        $last = $movements ? (int)end($movements)['stock_flow_id'] : 0;
        if ($movements && (($review['reviewed'] ?? false) !== true || (int)($review['last_stock_flow_id'] ?? 0) !== $last)) {
            throw new \DomainException('盘点期间有出入库，请核清哪些移动已计入本次实物，再提交');
        }
        $selected = array_column($movements, null, 'stock_flow_id'); $delta = '0.0000';
        foreach ($ids as $id) {
            $id = FinanceValue::id($id);
            if (!isset($selected[$id])) { throw new \DomainException('期间移动来源不属于本仓本项或已变化'); }
            $delta = bcadd($delta, $selected[$id]['quantity_delta'], 4);
        }
        $quantity = bcsub($facts['observed_quantity'], $delta, 4);
        if (bccomp($quantity, '0', 4) < 0) { throw new \DomainException('归回截止后实盘不能为负，请重新核清移动归属'); }
        return array_replace($facts, ['counted_quantity' => $quantity, 'basis_token' => $current['basis_token'],
            'movement_review' => ['last_stock_flow_id' => $last, 'included_flow_ids' => $ids, 'quantity_delta' => $delta,
                'reason' => FinanceValue::text($review['reason'] ?? '', 1000, (bool)$movements), 'movements' => $movements]]);
    }
}
