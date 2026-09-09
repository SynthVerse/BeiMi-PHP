<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 真实补录先保留独立业务事实，再以关联反向抵消原盘点重复份额。 */
final class FinanceInventoryCountCorrections
{
    public static function present(array $document): array
    {
        if ($document['status'] === 'confirmed') { return $document; }
        $input = $document['payload'];
        if ($document['type'] === 'inventory_count_correction_cancel') {
            $document['payload']['selected_correction'] = empty($input['correction_document_id']) ? null : self::cancellationOptions($input)['selected_correction'];
        } else {
            $options = !empty($input['count_result_document_id']) && !empty($input['sku_id']) ? self::options($input) : [];
            $document['payload']['selected_difference'] = $options['selected_difference'] ?? null;
            $document['payload']['selected_business'] = $options['selected_business'] ?? null;
        }
        return $document;
    }

    /** 价格、结算重量仍可独立更正；实重不能低于尚未撤回的盘点关联份额。 */
    public static function assertSalesCorrections(array $order, array $corrections): void
    {
        if (!FinanceIntegration::active()) { return; }
        $deltas = [];
        foreach ($corrections as $line) { $deltas[$line['sku_id']] = bcadd($deltas[$line['sku_id']] ?? '0', $line['actual_delivery_delta'], 4); }
        foreach ($deltas as $sku => $delta) {
            $current = (string)Db::name('order_goods')->where('tenant_id', FinanceAccess::tenant())->where('order_id', $order['id'])->where('order_type', 'sales')->where('sku_id', $sku)->sum('base_quantity');
            $effective = self::salesQuantities((int)$order['id'], (int)$sku, bcadd($current, $delta, 4));
            foreach ($effective as $flow => $quantity) {
                $used = (string)Db::name('finance_inventory_count_correction')->where('tenant_id', FinanceAccess::tenant())->where('stock_flow_id', $flow)->sum('quantity');
                if (bccomp($quantity, $used, 4) < 0) { throw new \DomainException('实重低于已关联的盘点反向数量，请先撤回相应盘点关联，再更正真实业务'); }
            }
        }
    }

    /** 实重减少依次冲回最近的交付；后来的新交付独立保留，不复活已冲回的旧流水。 */
    private static function salesQuantities(int $order, int $sku, string $total): array
    {
        $flows = Db::name('stock_flow')->where('tenant_id', FinanceAccess::tenant())->where('order_id', $order)->where('sku_id', $sku)
            ->whereIn('order_type', ['sales', 'sales_delivery', 'sales_delivery_correction'])->order('id')->lock(true)->select()->toArray();
        $quantities = [];
        foreach ($flows as $flow) {
            if ((int)$flow['flow_type'] === 2) { $quantities[(int)$flow['id']] = (string)$flow['quantity']; }
            elseif ($flow['order_type'] === 'sales_delivery_correction') { self::reduceSalesQuantities($quantities, (string)$flow['quantity']); }
        }
        $sum = '0'; foreach ($quantities as $quantity) { $sum = bcadd($sum, $quantity, 4); }
        if (bccomp($sum, $total, 4) > 0) { self::reduceSalesQuantities($quantities, bcsub($sum, $total, 4)); }
        return $quantities;
    }

    private static function reduceSalesQuantities(array &$quantities, string $remaining): void
    {
        foreach (array_reverse(array_keys($quantities)) as $id) {
            $take = bccomp($quantities[$id], $remaining, 4) < 0 ? $quantities[$id] : $remaining;
            $quantities[$id] = bcsub($quantities[$id], $take, 4); $remaining = bcsub($remaining, $take, 4);
            if (bccomp($remaining, '0', 4) === 0) { break; }
        }
    }

    public static function cancelledGainOrigin(string $origin): bool
    {
        if (!preg_match('/^inventory-count-gain:(\d+):(\d+):(\d+)$/D', $origin, $match)) { return false; }
        $quantity = Db::name('finance_cost_origin')->where('tenant_id', FinanceAccess::tenant())->where('origin_key', $origin)->value('quantity');
        return $quantity !== null && bccomp(self::state((int)$match[1], (int)$match[3])['reversed_quantity'], $quantity, 4) === 0;
    }

    public static function state(int $document, int $sku): array
    {
        $query = Db::name('finance_inventory_count_correction')->where('tenant_id', FinanceAccess::tenant())->where('count_result_document_id', $document)->where('sku_id', $sku);
        $rows = $query->order('id')->lock(true)->select()->toArray(); $quantity = '0.0000'; $latest = 0;
        foreach ($rows as $row) { $quantity = bcadd($quantity, $row['quantity'], 4); $latest = (int)$row['id']; }
        return ['reversed_quantity' => $quantity, 'expected_correction_id' => $latest];
    }

    public static function options(array $params): array
    {
        return self::readConsistently(static function () use ($params): array {
            (new FinanceLedger(FinanceAccess::tenant()))->lockBook();
            $query = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('type', 'inventory_count')->where('status', 'confirmed');
            if (!empty($params['count_result_document_id'])) { $query->where('id', FinanceValue::id($params['count_result_document_id'])); }
            $lines = []; $selected = null;
            foreach ($query->order('id desc')->select()->toArray() as $document) {
                foreach (FinanceInventoryCountReviews::lines((int)$document['id'], FinanceValue::decode($document['confirmed_result'])) as $line) {
                    if (!empty($params['count_result_document_id']) && (int)($params['sku_id'] ?? 0) === $line['sku_id']) { $selected = $line; }
                    if (bccomp($line['remaining_correction_quantity'], '0', 4) > 0) { $lines[] = $line; }
                }
            }
            $page = FinanceValue::id($params['page'] ?? 1);
            $business = $selected ? self::businessChoices($selected, $params) : ['business_choices' => [], 'business_has_more' => false, 'selected_business' => null];
            return ['differences' => array_slice($lines, ($page - 1) * 20, 20), 'difference_has_more' => count($lines) > $page * 20, 'selected_difference' => $selected] + $business;
        });
    }

    private static function businessChoices(array $line, array $params): array
    {
        $query = Db::name('stock_flow')->where('tenant_id', FinanceAccess::tenant())->where('warehouse_id', $line['warehouse_id'])->where('sku_id', $line['sku_id'])
            ->where('id', '>', $line['last_stock_flow_id'])->where('flow_type', bccomp($line['difference_quantity'], '0', 4) > 0 ? 1 : 2);
        $choices = []; $selected = null;
        foreach ($query->order('id desc')->select()->toArray() as $flow) {
            $kind = match ($flow['order_type']) {
                'sales', 'sales_delivery' => 'omitted_sale', 'finance_purchase_arrival', 'supply' => 'omitted_purchase',
                'finance_purchase_return', 'finance_purchase_return_back', 'finance_customer_return', 'sales-return' => 'omitted_return', default => 'omitted_transfer',
            };
            if (!empty($params['reason_kind']) && $params['reason_kind'] !== $kind) { continue; }
            try { $real = self::realFlow((int)$flow['id'], $line, $kind); }
            catch (\DomainException) { continue; }
            $used = (string)Db::name('finance_inventory_count_correction')->where('tenant_id', FinanceAccess::tenant())->where('stock_flow_id', $flow['id'])->sum('quantity');
            $real['available_quantity'] = bcsub($real['quantity'], $used, 4); $real['reason_kind'] = $kind;
            if ((int)($params['stock_flow_id'] ?? 0) === (int)$flow['id']) { $selected = $real; }
            if (bccomp($real['available_quantity'], '0', 4) > 0) { $choices[] = $real; }
        }
        $page = FinanceValue::id($params['business_page'] ?? 1);
        return ['business_choices' => array_slice($choices, ($page - 1) * 20, 20), 'business_has_more' => count($choices) > $page * 20, 'selected_business' => $selected];
    }

    public static function cancellationOptions(array $params): array
    {
        return self::readConsistently(static function () use ($params): array {
            (new FinanceLedger(FinanceAccess::tenant()))->lockBook();
            $query = Db::name('finance_inventory_count_correction')->where('tenant_id', FinanceAccess::tenant())->where('quantity', '>', 0);
            if (!empty($params['correction_document_id'])) { $query->where('document_id', FinanceValue::id($params['correction_document_id'])); }
            $rows = []; $selected = null;
            foreach ($query->order('id desc')->select()->toArray() as $row) {
                $remaining = self::cancellable($row);
                $snapshot = FinanceValue::decode($row['snapshot']) + ['correction_document_id' => (int)$row['document_id'], 'cancellable_quantity' => $remaining];
                $snapshot = array_replace($snapshot, self::state((int)$row['count_result_document_id'], (int)$row['sku_id']));
                $snapshot['expected_cost_event_id'] = (int)Db::name('finance_cost_event')->where('tenant_id', FinanceAccess::tenant())->where('sku_id', $row['sku_id'])->order('id desc')->value('id');
                if (!empty($params['correction_document_id'])) { $selected = $snapshot; }
                if (bccomp($remaining, '0', 4) > 0) { $rows[] = $snapshot; }
            }
            $page = FinanceValue::id($params['page'] ?? 1);
            return ['corrections' => array_slice($rows, ($page - 1) * 20, 20), 'correction_has_more' => count($rows) > $page * 20, 'selected_correction' => $selected];
        });
    }

    private static function cancellable(array $original): string
    {
        $cancelled = (string)Db::name('finance_inventory_count_correction')->where('tenant_id', FinanceAccess::tenant())->where('quantity', '<', 0)
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.cancel_of_document_id')) AS UNSIGNED) = ?", [$original['document_id']])->sum('quantity');
        return bcadd($original['quantity'], $cancelled, 4);
    }

    private static function readConsistently(callable $read): array
    {
        $pdo = Db::connect()->getPdo();
        return $pdo && $pdo->inTransaction() ? $read() : Db::transaction($read);
    }

    public static function cancel(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('finance.inventory.confirm'); $tenant = FinanceAccess::tenant();
        $id = FinanceValue::id($data['correction_document_id'] ?? null);
        $original = Db::name('finance_inventory_count_correction')->where('tenant_id', $tenant)->where('document_id', $id)->where('quantity', '>', 0)->lock(true)->find();
        if (!$original) { throw new \DomainException('请选择本门店已确认的盘点关联反向记录'); }
        $quantity = FinancePurchaseSettlement::quantity($data['quantity'] ?? null); $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        if (bccomp($quantity, self::cancellable($original), 4) > 0) { throw new \DomainException('撤回数量超过本次关联尚未撤回的数量'); }
        $count = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $original['count_result_document_id'])->where('type', 'inventory_count')->where('status', 'confirmed')->lock(true)->find();
        if (!$count) { throw new \DomainException('原盘点结果缺失'); }
        $lines = array_column(FinanceInventoryCountReviews::lines((int)$count['id'], FinanceValue::decode($count['confirmed_result'])), null, 'sku_id'); $line = $lines[$original['sku_id']];
        if (FinanceValue::id($data['expected_correction_id'] ?? null, true) !== $line['expected_correction_id'] || FinanceValue::id($data['expected_cost_event_id'] ?? null, true) !== $line['expected_cost_event_id']) { throw new \DomainException('盘点关联或成本已有变化，请重新核对撤回依据'); }
        $date = $line['actual_date']; $month = $ledger->postingMonth($date);
        $delta = bccomp($line['difference_quantity'], '0', 4) > 0 ? $quantity : bcsub('0', $quantity, 4);
        $flow = StockService::financeInventoryCountReverseWithinTransaction($line['warehouse_id'], $line, $delta, (int)$document['id'], $date, true);
        $snapshot = array_replace(FinanceValue::decode($original['snapshot']), ['type' => 'inventory_count_correction_cancel', 'cancel_of_document_id' => $id, 'cancel_quantity' => $quantity,
            'quantity' => bcsub('0', $quantity, 4), 'reverse_quantity' => bcadd($delta, '0', 4), 'reverse_stock_flow_id' => $flow, 'posting_month' => $month,
            'remaining_correction_quantity' => bcadd($line['remaining_correction_quantity'], $quantity, 4), 'reason' => $reason, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()]);
        $snapshot = self::withCostImpacts($snapshot, (int)$document['id'], $line);
        Db::name('finance_inventory_count_correction')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'count_result_document_id' => $count['id'], 'sku_id' => $line['sku_id'],
            'stock_flow_id' => $original['stock_flow_id'], 'quantity' => bcsub('0', $quantity, 4), 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot;
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('finance.inventory.confirm'); $tenant = FinanceAccess::tenant();
        $id = FinanceValue::id($data['count_result_document_id'] ?? null); $sku = FinanceValue::id($data['sku_id'] ?? null);
        $original = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $id)->where('type', 'inventory_count')->where('status', 'confirmed')->lock(true)->find();
        if (!$original) { throw new \DomainException('请选择本门店原盘点结果'); }
        $lines = array_column(FinanceInventoryCountReviews::lines($id, FinanceValue::decode($original['confirmed_result'])), null, 'sku_id'); $line = $lines[$sku] ?? null;
        if (!$line || bccomp($line['remaining_correction_quantity'], '0', 4) <= 0) { throw new \DomainException('原差额没有尚可关联反向的数量'); }
        if (FinanceValue::id($data['expected_correction_id'] ?? null, true) !== $line['expected_correction_id']
            || FinanceValue::id($data['expected_cost_event_id'] ?? null, true) !== $line['expected_cost_event_id']) { throw new \DomainException('原差额或成本已有变化，请重新核对关联纠正依据'); }
        $quantity = FinancePurchaseSettlement::quantity($data['quantity'] ?? null);
        if (bccomp($quantity, $line['remaining_correction_quantity'], 4) > 0) { throw new \DomainException('反向数量超过原差额尚未纠正的部分'); }
        $kind = FinanceValue::text($data['reason_kind'] ?? null, 32); $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        if (($data['occurred_before_cutoff'] ?? null) !== 1) { throw new \DomainException('须人工确认关联业务确实发生在原盘点截止之前'); }
        $flowId = FinanceValue::id($data['stock_flow_id'] ?? null);
        $real = self::realFlow($flowId, $line, $kind);
        $used = (string)Db::name('finance_inventory_count_correction')->where('tenant_id', $tenant)->where('stock_flow_id', $flowId)->sum('quantity');
        if (bccomp($quantity, bcsub($real['quantity'], $used, 4), 4) > 0) { throw new \DomainException('关联实物流水的数量已经被其他盘点纠正使用'); }
        $date = $line['actual_date']; $month = $ledger->postingMonth($date);
        $reverse = bccomp($line['difference_quantity'], '0', 4) > 0 ? bcsub('0', $quantity, 4) : bcadd($quantity, '0', 4);
        $reverseFlow = StockService::financeInventoryCountReverseWithinTransaction($line['warehouse_id'], $line, $reverse, (int)$document['id'], $date);
        $snapshot = ['type' => 'inventory_count_correction', 'count_document_id' => $line['count_document_id'], 'count_result_document_id' => $id, 'sku_id' => $sku,
            'warehouse_id' => $line['warehouse_id'], 'warehouse_name' => $line['warehouse_name'], 'goods_name' => $line['goods_name'], 'sku_name' => $line['sku_name'], 'base_unit_name' => $line['base_unit_name'],
            'cutoff_at' => $line['cutoff_at'], 'actual_date' => $date, 'posting_month' => $month, 'quantity' => $quantity, 'reverse_quantity' => $reverse,
            'original_difference_quantity' => $line['difference_quantity'], 'stock_flow_id' => $flowId, 'reverse_stock_flow_id' => $reverseFlow, 'real_business' => $real, 'real_business_document_id' => $real['document_id'] ?: null,
            'remaining_correction_quantity' => bcsub($line['remaining_correction_quantity'], $quantity, 4), 'reason_kind' => $kind, 'reason' => $reason, 'occurred_before_cutoff' => true,
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(), 'created_sources' => []];
        $snapshot = self::withCostImpacts($snapshot, (int)$document['id'], $line);
        Db::name('finance_inventory_count_correction')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'count_result_document_id' => $id, 'sku_id' => $sku,
            'stock_flow_id' => $flowId, 'quantity' => $quantity, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot;
    }

    private static function realFlow(int $id, array $line, string $kind): array
    {
        $tenant = FinanceAccess::tenant();
        $flow = Db::name('stock_flow')->where('tenant_id', $tenant)->where('id', $id)->where('warehouse_id', $line['warehouse_id'])->where('sku_id', $line['sku_id'])->lock(true)->find();
        if (!$flow || $id <= (int)$line['last_stock_flow_id']) { throw new \DomainException('请选择原截止之后补录的本仓同SKU实物流水'); }
        $gain = bccomp($line['difference_quantity'], '0', 4) > 0;
        if ((int)$flow['flow_type'] !== ($gain ? 1 : 2)) { throw new \DomainException('补录业务方向须与原盘点差额相同'); }
        $types = ['omitted_sale' => ['sales', 'sales_delivery'], 'omitted_purchase' => ['finance_purchase_arrival', 'supply'],
            'omitted_return' => ['sales-return', 'finance_purchase_return', 'finance_purchase_return_back', 'finance_customer_return']];
        $reference = 'stock:' . $id;
        if ($kind === 'omitted_transfer') {
            $pair = Db::name('finance_stock_transfer_pair')->where('tenant_id', $tenant)->where($gain ? 'inbound_flow_id' : 'outbound_flow_id', $id)->lock(true)->find();
            if (!$pair) { throw new \DomainException('调拨须关联完整的原出入库配对'); }
            $pairFlows = Db::name('stock_flow')->where('tenant_id', $tenant)->whereIn('id', [$pair['outbound_flow_id'], $pair['inbound_flow_id']])->lock(true)->select()->toArray();
            FinanceStockTransferPairs::forFlows($tenant, $pairFlows);
            $reference = 'stock-transfer:' . $pair['outbound_flow_id'];
        } elseif (!isset($types[$kind]) || !in_array($flow['order_type'], $types[$kind], true)) { throw new \DomainException('所选实物流水与漏记业务类型不符'); }
        $event = Db::name('finance_cost_event')->where('tenant_id', $tenant)->where('sku_id', $line['sku_id'])->where('reference', $reference)->lock(true)->find();
        if (!$event || (int)$event['id'] <= (int)$line['last_cost_event_id'] || $event['business_date'] > $line['actual_date']) { throw new \DomainException('关联业务须有原截止日期以内且后来补录的真实成本事件'); }
        $effective = (string)$flow['quantity'];
        if ($kind === 'omitted_sale') {
            $order = Db::name('sales_order')->where('tenant_id', $tenant)->where('id', $flow['order_id'])->where('warehouse_id', $line['warehouse_id'])->lock(true)->find();
            $goods = Db::name('order_goods')->where('tenant_id', $tenant)->where('order_id', $flow['order_id'])->where('order_type', 'sales')->where('sku_id', $line['sku_id'])->lock(true)->select()->toArray();
            if (!$order || !$goods) { throw new \DomainException('关联销售须有本仓同SKU真实销售单及交付明细，孤立库存流水不能代替业务'); }
            $total = '0'; foreach ($goods as $row) { $total = bcadd($total, $row['base_quantity'], 4); }
            $effective = self::salesQuantities((int)$order['id'], (int)$line['sku_id'], $total)[$id] ?? '0.0000';
        } elseif ($flow['order_type'] === 'finance_purchase_arrival') {
            $document = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $flow['order_id'])->where('type', 'purchase_arrival')->where('status', 'confirmed')->lock(true)->find();
            $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('document_id', $flow['order_id'])->where('warehouse_id', $line['warehouse_id'])->where('sku_id', $line['sku_id'])->lock(true)->find();
            if (!$document || !$arrival) { throw new \DomainException('关联到货须有已确认的本门店真实采购明细'); }
            if ((int)$event['document_id'] !== (int)$document['id'] || bccomp($arrival['actual_quantity'], $flow['quantity'], 4) !== 0) { throw new \DomainException('采购入库数量或成本与原单不一致'); }
        } elseif ($flow['order_type'] === 'finance_purchase_return') {
            $document = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $flow['order_id'])->where('type', 'purchase_return_actual')->where('status', 'confirmed')->lock(true)->find();
            $costFact = FinanceValue::decode($event['snapshot']);
            $returnId = preg_match('/^purchase-return:(\d+)$/D', $costFact['target_reference'] ?? '', $match) ? (int)$match[1] : 0;
            $returned = Db::name('finance_purchase_return_line')->where('tenant_id', $tenant)->where('id', $returnId)->where('document_id', $flow['order_id'])->where('warehouse_id', $line['warehouse_id'])->where('sku_id', $line['sku_id'])->lock(true)->find();
            if (!$document || !$returned || (int)$event['document_id'] !== (int)$document['id'] || bccomp($returned['quantity'], $flow['quantity'], 4) !== 0) { throw new \DomainException('实际退离须关联已确认的原退货明细'); }
        } elseif ($flow['order_type'] === 'finance_customer_return') {
            $document = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $flow['order_id'])->where('type', 'customer_return_actual')->where('status', 'confirmed')->lock(true)->find();
            $returned = Db::name('finance_customer_return')->where('tenant_id', $tenant)->where('document_id', $flow['order_id'])->where('warehouse_id', $line['warehouse_id'])->where('sku_id', $line['sku_id'])->lock(true)->find();
            $fact = $returned ? FinanceValue::decode($returned['snapshot']) : [];
            if (!$document || !$returned || (int)$event['document_id'] !== (int)$document['id'] || (int)($fact['stock_flow_id'] ?? 0) !== $id
                || bccomp($returned['quantity'], $flow['quantity'], 4) !== 0) { throw new \DomainException('客户退回须关联已确认的原实物验收记录'); }
        } elseif ($flow['order_type'] === 'finance_purchase_return_back') {
            $document = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $flow['order_id'])->where('type', 'purchase_return_resolution')->where('status', 'confirmed')->lock(true)->find();
            $resolution = Db::name('finance_purchase_return_resolution')->where('tenant_id', $tenant)->where('document_id', $flow['order_id'])->where('kind', 'returned')->lock(true)->find();
            $fact = $resolution ? FinanceValue::decode($resolution['snapshot']) : [];
            if (!$document || !$resolution || (int)$event['document_id'] !== (int)$document['id'] || (int)($fact['warehouse_id'] ?? 0) !== (int)$line['warehouse_id']
                || (int)($fact['sku_id'] ?? 0) !== (int)$line['sku_id'] || bccomp($resolution['quantity'], $flow['quantity'], 4) !== 0) { throw new \DomainException('退货返回须关联已确认的原返回明细'); }
        } elseif (in_array($flow['order_type'], ['supply', 'sales-return'], true)) {
            $table = $flow['order_type'] === 'supply' ? 'supply_order' : 'sales_return_order';
            $order = Db::name($table)->where('tenant_id', $tenant)->where('id', $flow['order_id'])->where('warehouse_id', $line['warehouse_id'])->lock(true)->find();
            $quantity = (string)Db::name('order_goods')->where('tenant_id', $tenant)->where('order_id', $flow['order_id'])->where('order_type', $flow['order_type'])->where('sku_id', $line['sku_id'])->sum('base_quantity');
            if (!$order || bccomp($quantity, $flow['quantity'], 4) < 0) { throw new \DomainException('历史实物来源缺少本仓同SKU原单明细'); }
        }
        return ['stock_flow_id' => $id, 'order_type' => $flow['order_type'], 'order_id' => (int)$flow['order_id'], 'order_sn' => $flow['order_sn'],
            'quantity' => $effective, 'original_flow_quantity' => (string)$flow['quantity'], 'business_date' => $event['business_date'], 'cost_event_id' => (int)$event['id'], 'document_id' => (int)$event['document_id']];
    }

    private static function withCostImpacts(array $snapshot, int $document, array $line): array
    {
        $cost = new FinanceCostLedger(FinanceAccess::tenant()); $sku = (int)$line['sku_id'];
        $pending = $cost->balance((int)$line['warehouse_id'], $sku)['pending'] || ($line['current_cost'] === null && bccomp($snapshot['remaining_correction_quantity'], '0', 4) > 0);
        $snapshot['cost_pending'] = $pending;
        $snapshot['cost_impacts'] = $cost->documentImpacts($document, $pending ? [$sku => true] : []);
        return $snapshot;
    }
}
