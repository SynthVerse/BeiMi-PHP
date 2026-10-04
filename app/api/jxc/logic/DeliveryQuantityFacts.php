<?php
declare(strict_types=1);
namespace app\api\jxc\logic;
use think\facade\Db;

/** Bridges physical subgroup identities into existing delivery/stock transactions. */
final class DeliveryQuantityFacts
{
    public static function baseQuantity(array $item): string
    {
        $facts = TradeQuantityService::snapshot($item);
        return $facts ? (string)$facts['inventory_base_quantity'] : (string)($item['final_actual_weight'] ?? '0');
    }

    public static function prepare(array $params): array
    {
        $tenant = (int)request()->tenantId;
        if (!isset($params['items']) && (int)($params['task_id'] ?? 0) > 0) {
            $report = Db::name('fulfillment_task')->where('tenant_id', $tenant)->where('id', $params['task_id'])->value('report_id');
            $rows = Db::name('customer_report_item')->where('tenant_id', $tenant)->where('report_id', $report)->whereNull('delete_time')->select()->toArray();
            if ($rows !== [] && count(array_filter($rows, static fn(array $row): bool => (int)($row['quantity_contract_version'] ?? 1) === 2)) === count($rows)) {
                $params['items'] = array_map(static fn(array $row): array => ['report_item_id' => $row['id'], 'facts_version' => (int)($params['facts_version'] ?? 0)], $rows);
            }
        }
        if (!isset($params['items'])) { return $params; }
        foreach ($params['items'] as &$request) {
            $item = Db::name('customer_report_item')->where('tenant_id', $tenant)->where('id', (int)($request['report_item_id'] ?? 0))->whereNull('delete_time')->find();
            if (!$item || (int)($item['quantity_contract_version'] ?? 1) !== 2) { continue; }
            if ((int)($request['facts_version'] ?? 0) <= 0) { throw new \DomainException('交付需要当前数量事实版本，请更新客户端并核对最新称重'); }
            $selectedIds = (array)($request['delivery_group_ids'] ?? []);
            if ($selectedIds === [] && trim((string)($params['idempotency_key'] ?? '')) !== '') {
                $existingEvent = Db::name('fulfillment_delivery_event')->where('tenant_id', $tenant)->where('idempotency_key', $params['idempotency_key'])->value('id');
                if ($existingEvent) {
                    $selectedIds = Db::name('fulfillment_delivery_item')->where('tenant_id', $tenant)->where('delivery_event_id', $existingEvent)
                        ->where('report_item_id', $item['id'])->where('fact_version', 2)->column('processing_group_id');
                }
            }
            $groups = self::selectedGroups($item, $selectedIds, false);
            $base = '0.0000';
            foreach ($groups as $group) { $base = bcadd($base, $group['inventory_base_quantity'], 4); }
            $request['delivery_group_ids'] = array_column($groups, 'processing_group_id');
            $request['fact_version'] = 2;
            // This compatibility key is only the private input to the existing stock primitive.
            // Persisted weight is always restored from the authoritative physical snapshot.
            $request['actual_delivery_weight'] = $base;
            $request['facts_version'] = (int)$request['facts_version'];
            if (bccomp((string)($request['loss_weight'] ?? '0'), '0', 4) !== 0 || ($request['remaining_action'] ?? 'none') === 'undelivered') {
                throw new \DomainException('V2 交付子组不能用重量覆盖作废；运输异常请先核清该组实际去向');
            }
        }
        unset($request);
        return $params;
    }

    public static function selectedGroups(array $item, array $ids, bool $lock = true): array
    {
        $tenant = (int)request()->tenantId;
        $query = Db::name('customer_report_processing_group')->where('tenant_id', $tenant)->where('report_item_id', (int)$item['id'])->order('id');
        if ($lock) { $query->lock(true); }
        $rows = $query->select()->toArray();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $selected = []; $deliveredFacts = [];
        foreach ($rows as $row) {
            if ($ids !== [] && !in_array((int)$row['id'], $ids, true)) { continue; }
            $facts = TradeQuantityService::snapshot($row);
            if (!$facts) {
                if ($ids === []) { continue; }
                throw new \DomainException('交付组还没有核实实际数量及库存基础量');
            }
            $delivered = Db::name('fulfillment_delivery_item')->where('tenant_id', $tenant)->where('delivery_group_key', $facts['delivery_group_key'])->count() > 0;
            if ($delivered && $ids === []) { $deliveredFacts[] = $facts; continue; }
            if ($lock && $delivered) {
                throw new \DomainException('该批实物已经确认交付，不能换请求编号重复出库');
            }
            if ($lock && isset($facts['packaging'])) { GoodsPackagingSourceService::verify($facts['packaging'], $item); }
            $selected[] = $facts;
        }
        // The existing event idempotency path still needs its original payload before the locked write check.
        if ($selected === [] && !$lock && $ids === []) { $selected = $deliveredFacts; }
        if ($selected === [] || ($ids !== [] && count($ids) !== count($selected))) { throw new \DomainException('交付子组不属于当前报货行'); }
        return $selected;
    }

    public static function insert(array $row, array $groups): int
    {
        if ($groups === []) { return (int)Db::name('fulfillment_delivery_item')->insertGetId($row); }
        $first = 0; $consumed = $row['reservation_consumed_qty']; $negative = $row['negative_qty'];
        foreach ($groups as $index => $group) {
            $base = $group['inventory_base_quantity'];
            $used = bccomp($consumed, $base, 4) > 0 ? $base : $consumed;
            $negativePart = bccomp($negative, $base, 4) > 0 ? $base : $negative;
            $id = (int)Db::name('fulfillment_delivery_item')->insertGetId(array_merge($row, [
                'fact_version' => 2, 'delivery_group_key' => $group['delivery_group_key'], 'processing_group_id' => $group['processing_group_id'],
                'inventory_base_quantity' => $base, 'inventory_base_unit_id' => $group['inventory_base_unit_id'],
                'inventory_base_unit_name' => $group['inventory_base_unit_name'], 'actual_delivery_weight' => $group['actual_delivery_weight'],
                'quantity_facts' => json_encode($group['quantity_facts'], JSON_UNESCAPED_UNICODE),
                'quantity_facts_snapshot' => json_encode($group, JSON_UNESCAPED_UNICODE), 'reservation_consumed_qty' => $used,
                'reservation_released_qty' => $index === 0 ? $row['reservation_released_qty'] : '0.0000', 'negative_qty' => $negativePart,
            ]));
            $first = $first ?: $id; $consumed = bcsub($consumed, $used, 4); $negative = bcsub($negative, $negativePart, 4);
        }
        return $first;
    }
}
