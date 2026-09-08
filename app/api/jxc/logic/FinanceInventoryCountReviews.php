<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 核实原盘点差额的原因和截止成本，不再次产生实物库存流水。 */
final class FinanceInventoryCountReviews
{
    public static function present(array $document): array
    {
        if ($document['type'] !== 'inventory_count_review' || $document['status'] === 'confirmed') { return $document; }
        $data = $document['payload']; $document['payload']['selected_difference'] = null;
        $original = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('id', (int)($data['count_result_document_id'] ?? 0))->where('type', 'inventory_count')->where('status', 'confirmed')->find();
        if ($original) {
            foreach (self::lines((int)$original['id'], FinanceValue::decode($original['confirmed_result'])) as $line) {
                if ((int)$line['sku_id'] === (int)($data['sku_id'] ?? 0)) { $document['payload']['selected_difference'] = $line; break; }
            }
        }
        return $document;
    }

    public static function options(array $params): array
    {
        return Db::transaction(static function () use ($params): array {
            (new FinanceLedger(FinanceAccess::tenant()))->lockBook();
            $query = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('type', 'inventory_count')->where('status', 'confirmed');
            if (!empty($params['count_result_document_id'])) { $query->where('id', FinanceValue::id($params['count_result_document_id'])); }
            $differences = []; $selected = null;
            foreach ($query->order('id desc')->select()->toArray() as $document) {
                foreach (self::lines((int)$document['id'], FinanceValue::decode($document['confirmed_result'])) as $line) {
                    if (!empty($params['count_result_document_id']) && (int)($params['sku_id'] ?? 0) === (int)$line['sku_id']) { $selected = $line; }
                    if (!$line['resolved']) { $differences[] = $line; }
                }
            }
            $page = FinanceValue::id($params['page'] ?? 1);
            return ['differences' => array_slice($differences, ($page - 1) * 20, 20), 'difference_has_more' => count($differences) > $page * 20, 'selected_difference' => $selected];
        });
    }

    public static function lines(int $document, array $result): array
    {
        $lines = [];
        foreach ($result['lines'] as $line) {
            if (bccomp($line['difference_quantity'], '0', 4) === 0) { continue; }
            $lines[] = self::current($document, $result, $line);
        }
        return $lines;
    }

    private static function current(int $document, array $result, array $line): array
    {
        $tenant = FinanceAccess::tenant(); $sku = (int)$line['sku_id']; $warehouse = (int)$result['warehouse_id'];
        $review = Db::name('finance_inventory_count_review')->where('tenant_id', $tenant)->where('count_result_document_id', $document)->where('sku_id', $sku)->order('id desc')->lock(true)->find();
        $latest = $review ? FinanceValue::decode($review['snapshot']) : [];
        $reasonVerified = (bool)($latest['reason_verified'] ?? $line['reason_verified']);
        $costVerified = (bool)($latest['cost_verified'] ?? !$line['cost_pending']);
        $gain = bccomp($line['difference_quantity'], '0', 4) > 0;
        $reference = 'inventory-count:' . $document . ':' . $sku;
        if ($gain) {
            $value = Db::name('finance_cost_origin')->where('tenant_id', $tenant)->where('origin_key', 'inventory-count-gain:' . $document . ':' . $warehouse . ':' . $sku)->lock(true)->value('current_amount');
            $cost = $value === null ? null : bcadd($value, '0', 6);
        } else { $cost = (new FinanceCostLedger($tenant))->destination($warehouse, $sku, $reasonVerified ? 'loss' : 'pending', $reference)['cost']; }
        return array_replace($line, ['count_document_id' => (int)$result['count_document_id'], 'count_result_document_id' => $document, 'warehouse_id' => $warehouse,
            'warehouse_name' => $result['warehouse_name'], 'cutoff_at' => $result['cutoff_at'], 'actual_date' => $result['actual_date'],
            'expected_review_id' => (int)($review['id'] ?? 0), 'expected_cost_event_id' => (int)(Db::name('finance_cost_event')->where('tenant_id', $tenant)->where('sku_id', $sku)->order('id desc')->lock(true)->value('id') ?? 0),
            'current_cost' => $cost, 'cost_pending' => $cost === null, 'reason_verified' => $reasonVerified, 'cost_verified' => $costVerified,
            'reason_kind' => $latest['reason_kind'] ?? null,
            'resolved' => $reasonVerified && $costVerified && $cost !== null]);
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('finance.inventory.confirm'); $tenant = FinanceAccess::tenant();
        $id = FinanceValue::id($data['count_result_document_id'] ?? null); $sku = FinanceValue::id($data['sku_id'] ?? null);
        $original = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $id)->where('type', 'inventory_count')->where('status', 'confirmed')->lock(true)->find();
        if (!$original) { throw new \DomainException('请选择本门店已确认的原盘点差额'); }
        $result = FinanceValue::decode($original['confirmed_result']); $found = array_column($result['lines'], null, 'sku_id');
        if (!isset($found[$sku]) || bccomp($found[$sku]['difference_quantity'], '0', 4) === 0) { throw new \DomainException('所选SKU没有需要核实的原盘点差额'); }
        $current = self::current($id, $result, $found[$sku]);
        if ($current['resolved'] || FinanceValue::id($data['expected_review_id'] ?? null, true) !== $current['expected_review_id']
            || FinanceValue::id($data['expected_cost_event_id'] ?? null, true) !== $current['expected_cost_event_id']) { throw new \DomainException('原差额或成本已有后续变化，请读取最新核实依据'); }
        $reasonFlag = $data['reason_verified'] ?? null; $costFlag = $data['cost_verified'] ?? null;
        if (!in_array($reasonFlag, [0, 1], true) || !in_array($costFlag, [0, 1], true)) { throw new \DomainException('请分别明确原因与截止成本是否已核实'); }
        $reasonVerified = $current['reason_verified'] || $reasonFlag === 1; $costVerified = $current['cost_verified'] || $costFlag === 1;
        $reasonKind = $current['reason_kind'];
        if (!$current['reason_verified'] && $reasonFlag === 1) {
            $reasonKind = FinanceValue::text($data['reason_kind'] ?? null, 32);
            $required = bccomp($current['difference_quantity'], '0', 4) > 0 ? 'physical_gain' : 'physical_loss';
            if ($reasonKind !== $required) { throw new \DomainException('本入口仅核实真实盘盈或盘亏；漏销售、采购、退货或调拨须关联反向盘点与补录真实业务，继续保留未决'); }
        }
        if ($reasonVerified === $current['reason_verified'] && $costVerified === $current['cost_verified']) { throw new \DomainException('请至少新增核实原因或截止成本中的一项'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $basis = FinanceValue::text($data['cost_basis'] ?? '', 1000, $costFlag === 1);
        $cost = new FinanceCostLedger($tenant); $warehouse = $current['warehouse_id']; $gain = bccomp($current['difference_quantity'], '0', 4) > 0;
        if (array_key_exists('cost_amount', $data) && !($gain && $costFlag === 1 && $current['current_cost'] === null)) { throw new \DomainException('仅原成本未知的盘盈核实可填写有据成本金额，其他差额须沿原来源核对'); }
        if ($costFlag === 1 && $current['current_cost'] === null) {
            if (!$gain) { throw new \DomainException('原盘亏来源成本仍未知，请先补齐原采购或入库成本，不能借用当前平均价'); }
            $amount = FinanceValue::money($data['cost_amount'] ?? null, true);
            $cost->recordWithinTransaction(['reference' => 'inventory-count-review:' . $document['id'], 'type' => 'adjust', 'document_id' => (int)$document['id'],
                'warehouse_id' => $warehouse, 'sku_id' => $sku, 'business_date' => $current['actual_date'], 'origin' => 'inventory-count-gain:' . $id . ':' . $warehouse . ':' . $sku,
                'amount' => $amount, 'snapshot' => ['count_result_document_id' => $id, 'cost_basis' => $basis, 'reason' => $reason]]);
        }
        if (!$gain && !$current['reason_verified'] && $reasonVerified) {
            $reference = 'inventory-count:' . $id . ':' . $sku;
            $cost->recordWithinTransaction(['reference' => 'inventory-count-review:' . $document['id'], 'type' => 'reclassify', 'document_id' => (int)$document['id'],
                'warehouse_id' => $warehouse, 'sku_id' => $sku, 'business_date' => $current['actual_date'], 'quantity' => ltrim($current['difference_quantity'], '-'),
                'bucket' => 'pending', 'target_reference' => $reference, 'to_bucket' => 'loss', 'to_reference' => $reference,
                'snapshot' => ['count_result_document_id' => $id, 'reason' => $reason]]);
        }
        $snapshot = array_replace($current, ['type' => 'inventory_count_review', 'reason_verified' => $reasonVerified, 'cost_verified' => $costVerified,
            'reason_kind' => $reasonKind,
            'reason' => $reason, 'cost_basis' => $basis, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()]);
        $review = (int)Db::name('finance_inventory_count_review')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'], 'count_result_document_id' => $id,
            'sku_id' => $sku, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        $after = self::current($id, $result, $found[$sku]);
        $snapshot = array_replace($snapshot, ['current_cost' => $after['current_cost'], 'cost_pending' => $after['cost_pending'], 'resolved' => $after['resolved'], 'review_id' => $review,
            'cost_impacts' => $cost->documentImpacts((int)$document['id'], $after['cost_pending'] ? [$sku => true] : []), 'posting_month' => $ledger->postingMonth($current['actual_date'])]);
        Db::name('finance_inventory_count_review')->where('tenant_id', $tenant)->where('id', $review)->update(['snapshot' => FinanceValue::json($snapshot)]);
        return $snapshot + ['lines' => [$snapshot], 'created_sources' => []];
    }
}
