<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 盘点先固定范围、实物和成本截止依据；正常出入库不受持续占用阻断。 */
final class FinanceInventoryCounts
{
    public static function input(string $type, array $payload): array
    {
        if (!in_array($type, ['inventory_count_start', 'inventory_count', 'inventory_count_cancel'], true)) { return $payload; }
        if ($type === 'inventory_count_start') { return array_intersect_key($payload, array_flip(['warehouse_id', 'scope', 'sku_ids', 'reason'])); }
        $input = array_intersect_key($payload, array_flip(['count_document_id', 'reason']));
        if ($type === 'inventory_count_cancel') { return $input; }
        if (!is_array($payload['lines'] ?? [])) { throw new \DomainException('实盘明细格式无效'); }
        $input['lines'] = array_map(static function ($line): array {
            if (!is_array($line)) { throw new \DomainException('实盘明细格式无效'); }
            return array_intersect_key($line, array_flip(['sku_id', 'counted_quantity', 'reason', 'reason_verified']));
        }, $payload['lines'] ?? []);
        return $input;
    }

    /** 共享草稿只存输入；返回页面时从原截止依据补齐展示，不能信任客户端回传的成本和商品名。 */
    public static function present(array $document): array
    {
        if ($document['status'] === 'confirmed' || !in_array($document['type'], ['inventory_count_start', 'inventory_count', 'inventory_count_cancel'], true)) { return $document; }
        $payload = $document['payload']; $tenant = FinanceAccess::tenant();
        if ($document['type'] === 'inventory_count_start') {
            $ids = is_array($payload['sku_ids'] ?? null) ? $payload['sku_ids'] : [];
            $skus = $ids ? Db::name('goods_sku')->alias('s')->join('goods g', 'g.tenant_id=s.tenant_id AND g.id=s.goods_id')->where('s.tenant_id', $tenant)->whereIn('s.id', $ids)
                ->field('s.id AS sku_id,s.sku_name,s.base_unit_name,g.name AS goods_name')->order('s.id')->select()->toArray() : [];
            $payload['selected_skus'] = array_map(static fn(array $row): array => $row + ['row_key' => (string)$row['sku_id']], $skus);
        } elseif (!empty($payload['count_document_id'])) {
            $count = Db::name('finance_inventory_count')->where('tenant_id', $tenant)->where('document_id', $payload['count_document_id'])->find();
            if ($count) {
                $basis = FinanceValue::decode($count['snapshot']); $lines = array_column($basis['lines'], null, 'sku_id'); unset($basis['lines']);
                $payload['selected_count'] = $basis + ['status' => $count['status'], 'result_document_id' => (int)($count['result_document_id'] ?? 0)];
                $payload['warehouse_id'] = (int)$count['warehouse_id'];
                $payload['lines'] = array_map(static fn(array $line): array => ($lines[$line['sku_id'] ?? 0] ?? []) + $line + ['row_key' => (string)($line['sku_id'] ?? '')], $payload['lines'] ?? []);
            }
        }
        $document['payload'] = $payload;
        return $document;
    }

    public static function prepare(array $document, string $action): void
    {
        if ($document['type'] !== 'inventory_count' || !in_array($action, ['save', 'prepare', 'submit', 'record', 'reopen'], true)) { return; }
        FinanceAccess::require('finance.inventory.count'); $tenant = FinanceAccess::tenant(); $data = FinanceValue::decode($document['payload']);
        $id = FinanceValue::id($data['count_document_id'] ?? null);
        $count = Db::name('finance_inventory_count')->where('tenant_id', $tenant)->where('document_id', $id)->lock(true)->find();
        if (!$count || !in_array($count['status'], ['counting', 'pending'], true) || (!empty($count['result_document_id']) && (int)$count['result_document_id'] !== (int)$document['id'])) {
            throw new \DomainException('原盘点已结束或已有另一份实盘结果，请读取原记录');
        }
        $pending = in_array($action, ['prepare', 'submit', 'record'], true);
        if ($pending) {
            $basis = FinanceValue::decode($count['snapshot']); $rawLines = $data['lines'] ?? null;
            if (!is_array($rawLines) || count($rawLines) !== count($basis['lines'])) { throw new \DomainException('请逐项录入所选盘点范围，空白数量不等于零'); }
            $selected = [];
            foreach ($rawLines as $raw) {
                if (!is_array($raw)) { throw new \DomainException('实盘明细格式无效'); }
                $sku = FinanceValue::id($raw['sku_id'] ?? null);
                if (isset($selected[$sku])) { throw new \DomainException('实盘明细不能重复SKU'); }
                $selected[$sku] = $raw;
            }
            $lines = [];
            foreach ($basis['lines'] as $line) {
                $raw = $selected[$line['sku_id']] ?? throw new \DomainException('实盘SKU必须与原盘点范围一致');
                $quantity = FinancePurchaseSettlement::quantity($raw['counted_quantity'] ?? null, true);
                $difference = bcsub($quantity, $line['book_quantity'], 4); $changed = bccomp($difference, '0', 4) !== 0;
                $reason = FinanceValue::text($raw['reason'] ?? '', 1000, $changed);
                $verified = $raw['reason_verified'] ?? 0;
                if (!in_array($verified, [0, 1], true)) { throw new \DomainException('请明确盘点差异原因是否已核实'); }
                $lines[] = $line + ['counted_quantity' => $quantity, 'difference_quantity' => $difference,
                    'difference_amount' => !$changed ? '0.000000' : ($line['unit_cost'] === null ? null : bcmul($difference, $line['unit_cost'], 6)),
                    'reason' => $reason, 'reason_verified' => !$changed || $verified === 1];
            }
            $measurement = ['count_document_id' => $id, 'cutoff_at' => $basis['cutoff_at'], 'warehouse_id' => (int)$count['warehouse_id'], 'warehouse_name' => $basis['warehouse_name'],
                'reason' => FinanceValue::text($data['reason'] ?? null, 1000), 'counted_by' => FinanceAccess::actor(), 'counted_at' => time(), 'lines' => $lines];
            Db::name('finance_inventory_count_measurement')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'count_document_id' => $id,
                'snapshot' => FinanceValue::json($measurement), 'create_time' => time()]);
        }
        Db::name('finance_inventory_count')->where('tenant_id', $tenant)->where('id', $count['id'])->update(['status' => $pending ? 'pending' : 'counting', 'result_document_id' => $document['id']]);
    }

    public static function options(array $params): array
    {
        $tenant = FinanceAccess::tenant(); $query = Db::name('finance_inventory_count')->where('tenant_id', $tenant);
        if (!empty($params['count_document_id'])) { $query->where('document_id', FinanceValue::id($params['count_document_id'])); }
        else { $query->whereIn('status', ['counting', 'pending']); }
        $page = FinanceValue::id($params['page'] ?? 1);
        $rows = $query->order('id desc')->page($page, 20)->select()->toArray();
        $counts = [];
        foreach ($rows as $row) {
            $snapshot = FinanceValue::decode($row['snapshot']);
            $counts[] = $snapshot + ['status' => $row['status'], 'result_document_id' => (int)($row['result_document_id'] ?? 0)];
        }
        return ['counts' => $counts, 'count_has_more' => count($rows) === 20];
    }

    public static function start(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('finance.inventory.count'); $tenant = FinanceAccess::tenant();
        $warehouse = FinanceValue::id($data['warehouse_id'] ?? null);
        $store = Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $warehouse)->find();
        if (!$store) { throw new \DomainException('请选择当前门店的核算仓库'); }
        $scope = FinanceValue::text($data['scope'] ?? null, 16);
        if (!in_array($scope, ['all', 'selected'], true)) { throw new \DomainException('请选择全部SKU或指定SKU盘点范围'); }
        $query = Db::name('goods_sku')->where('tenant_id', $tenant);
        if ($scope === 'selected') {
            $ids = $data['sku_ids'] ?? null;
            if (!is_array($ids) || !$ids) { throw new \DomainException('请明确选择本次盘点的SKU'); }
            $ids = array_map(static fn ($id): int => FinanceValue::id($id), $ids);
            if (count(array_unique($ids)) !== count($ids)) { throw new \DomainException('盘点范围不能重复选择同一SKU'); }
            $query->whereIn('id', $ids);
        }
        $skus = $query->order('id')->select()->toArray();
        if (!$skus || ($scope === 'selected' && count($skus) !== count($ids))) { throw new \DomainException('所选盘点SKU不存在或不属于本门店'); }
        $skuIds = array_column($skus, 'id');
        // 在占用前确认每项合法数量和简短原因都能提交；超大范围明确要求分批，不能悄悄省略SKU。
        $minimumInput = ['count_document_id' => PHP_INT_MAX, 'reason' => str_repeat('盘', 1000),
            'lines' => array_map(static fn($sku): array => ['sku_id' => (int)$sku, 'counted_quantity' => '999999999999.9999', 'reason' => '差异待核实', 'reason_verified' => 0], $skuIds)];
        if (strlen(FinanceValue::json($minimumInput)) > 65536) { throw new \DomainException('本次盘点范围超出单据输入容量，请选择部分SKU分批盘点；尚未占用本次范围'); }
        if (Db::name('finance_inventory_count_line')->where('tenant_id', $tenant)->where('warehouse_id', $warehouse)->whereIn('sku_id', $skuIds)->where('active_slot', 1)->lock(true)->find()) {
            throw new \DomainException('所选仓库SKU已有未结束盘点，请先完成或取消原盘点');
        }
        $balances = array_column(Db::name('warehouse_sku_balance')->where('tenant_id', $tenant)->where('warehouse_id', $warehouse)->whereIn('sku_id', $skuIds)->order('sku_id')->lock(true)->select()->toArray(), null, 'sku_id');
        $goods = Db::name('goods')->where('tenant_id', $tenant)->whereIn('id', array_column($skus, 'goods_id'))->column('name', 'id');
        $cost = new FinanceCostLedger($tenant); $lines = [];
        foreach ($skus as $sku) {
            $quantity = bcadd($balances[$sku['id']]['on_hand_qty'] ?? '0', '0', 4);
            $value = $cost->balance($warehouse, (int)$sku['id']);
            $unit = !$value['pending'] && bccomp($quantity, '0', 4) > 0 ? bcdiv($value['value'], $quantity, 6) : null;
            $line = ['sku_id' => (int)$sku['id'], 'goods_id' => (int)$sku['goods_id'], 'goods_name' => $goods[$sku['goods_id']] ?? '原商品',
                'sku_name' => $sku['sku_name'], 'base_unit_name' => $sku['base_unit_name'], 'book_quantity' => $quantity, 'unit_cost' => $unit,
                'known_cost' => $value['known_value'], 'cost_pending' => $unit === null,
                'last_cost_event_id' => (int)(Db::name('finance_cost_event')->where('tenant_id', $tenant)->where('sku_id', $sku['id'])->order('id desc')->lock(true)->value('id') ?? 0),
                'last_stock_flow_id' => (int)(Db::name('stock_flow')->where('tenant_id', $tenant)->where('warehouse_id', $warehouse)->where('sku_id', $sku['id'])->max('id') ?? 0)];
            $lines[] = $line;
            Db::name('finance_inventory_count_line')->insert(['tenant_id' => $tenant, 'count_document_id' => $document['id'], 'warehouse_id' => $warehouse,
                'sku_id' => $sku['id'], 'active_slot' => 1, 'snapshot' => FinanceValue::json($line)]);
        }
        $snapshot = ['type' => 'inventory_count_start', 'count_document_id' => (int)$document['id'], 'warehouse_id' => $warehouse, 'warehouse_name' => $store['name'],
            'scope' => $scope, 'cutoff_at' => date('Y-m-d H:i:s'), 'actual_date' => date('Y-m-d'), 'reason' => FinanceValue::text($data['reason'] ?? null, 1000),
            'counted_by' => FinanceAccess::actor(), 'lines' => $lines, 'created_sources' => []];
        $ledger->postingMonth($snapshot['actual_date']);
        Db::name('finance_inventory_count')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'warehouse_id' => $warehouse,
            'status' => 'counting', 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot;
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('finance.inventory.confirm'); $tenant = FinanceAccess::tenant();
        $id = FinanceValue::id($data['count_document_id'] ?? null);
        $count = Db::name('finance_inventory_count')->where('tenant_id', $tenant)->where('document_id', $id)->lock(true)->find();
        if (!$count || $count['status'] !== 'pending' || (int)$count['result_document_id'] !== (int)$document['id']) {
            throw new \DomainException('请先提交本次实盘结果，已结束盘点不能重复调整');
        }
        $row = Db::name('finance_inventory_count_measurement')->where('tenant_id', $tenant)->where('document_id', $document['id'])->order('id desc')->lock(true)->find();
        if (!$row) { throw new \DomainException('缺少已提交的实盘明细'); }
        $measurement = FinanceValue::decode($row['snapshot']); $date = substr($measurement['cutoff_at'], 0, 10);
        $month = $ledger->postingMonth($date); $lines = [];
        foreach ($measurement['lines'] as $line) {
            $flow = 0;
            if (bccomp($line['difference_quantity'], '0', 4) !== 0) {
                $flow = StockService::financeInventoryCountWithinTransaction((int)$count['warehouse_id'], $line, (int)$document['id'], $date);
            }
            $movement = $flow ? Db::name('stock_flow')->where('tenant_id', $tenant)->where('id', $flow)->find() : null;
            $current = WarehouseSkuBalanceService::onHand((int)$count['warehouse_id'], $line['sku_id']);
            $lines[] = $line + ['stock_flow_id' => $flow, 'current_before_quantity' => $movement['before_stock'] ?? $current,
                'current_after_quantity' => $movement['after_stock'] ?? $current,
                'reserved_quantity' => WarehouseSkuBalanceService::reserved((int)$count['warehouse_id'], $line['sku_id'])];
        }
        Db::name('finance_inventory_count')->where('tenant_id', $tenant)->where('id', $count['id'])->update(['status' => 'confirmed']);
        Db::name('finance_inventory_count_line')->where('tenant_id', $tenant)->where('count_document_id', $id)->update(['active_slot' => null]);
        $pendingSkus = [];
        foreach ($lines as $line) { if ($line['cost_pending'] && bccomp($line['difference_quantity'], '0', 4) !== 0) { $pendingSkus[$line['sku_id']] = true; } }
        $impacts = (new FinanceCostLedger($tenant))->documentImpacts((int)$document['id'], $pendingSkus);
        return array_replace($measurement, ['type' => 'inventory_count', 'actual_date' => $date, 'posting_month' => $month, 'lines' => $lines,
            'cost_impacts' => $impacts, 'posting_months' => array_values(array_unique(array_merge([$month], array_column($impacts, 'posting_month')))),
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(), 'created_sources' => []]);
    }

    public static function unresolved(string $cutoff): array
    {
        $rows = Db::name('finance_inventory_count')->alias('c')->join('finance_document d', 'd.tenant_id=c.tenant_id AND d.id=c.result_document_id')
            ->where('c.tenant_id', FinanceAccess::tenant())->where('c.status', 'confirmed')->field('d.id,d.confirmed_result')->order('d.id')->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $result = FinanceValue::decode($row['confirmed_result']);
            if ($result['actual_date'] > $cutoff) { continue; }
            $lines = array_values(array_filter($result['lines'], static fn(array $line): bool => bccomp($line['difference_quantity'], '0', 4) !== 0 && (!$line['reason_verified'] || $line['cost_pending'])));
            if ($lines) { $items[] = array_replace($result, ['document_id' => (int)$row['id'], 'lines' => $lines]); }
        }
        return $items;
    }

    public static function cancel(array $document, array $data): array
    {
        FinanceAccess::require('finance.inventory.count'); $tenant = FinanceAccess::tenant();
        $id = FinanceValue::id($data['count_document_id'] ?? null);
        $count = Db::name('finance_inventory_count')->where('tenant_id', $tenant)->where('document_id', $id)->lock(true)->find();
        if (!$count || $count['status'] !== 'counting') { throw new \DomainException('原盘点不存在或已结束，不能取消或覆盖确认结果'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        $basis = FinanceValue::decode($count['snapshot']);
        Db::name('finance_inventory_count')->where('tenant_id', $tenant)->where('id', $count['id'])->update(['status' => 'cancelled', 'result_document_id' => $document['id']]);
        Db::name('finance_inventory_count_line')->where('tenant_id', $tenant)->where('count_document_id', $id)->update(['active_slot' => null]);
        return ['type' => 'inventory_count_cancel', 'count_document_id' => $id, 'warehouse_id' => (int)$count['warehouse_id'], 'warehouse_name' => $basis['warehouse_name'],
            'cutoff_at' => $basis['cutoff_at'], 'reason' => $reason, 'cancelled_by' => FinanceAccess::actor(), 'cancelled_at' => time(), 'created_sources' => []];
    }
}
