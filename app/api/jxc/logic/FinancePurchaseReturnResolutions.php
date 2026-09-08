<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 对尚未认可的实物退货追加返回入库或门店损失，保留全部原始退离事实。 */
final class FinancePurchaseReturnResolutions
{
    public static function options(int $vendor, array $params): array
    {
        return FinancePurchaseReturnAcceptances::options($vendor, $params) + ['warehouses' => Db::name('warehouse')->where('tenant_id', FinanceAccess::tenant())->field('id,name')->order('id')->select()->toArray()];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $vendor = FinanceValue::id($data['subject_id'] ?? null);
        $line = FinanceValue::id($data['return_line_id'] ?? null); $state = FinancePurchaseReturnAcceptances::current($line, $vendor); $returned = $state['row'];
        if (FinanceValue::id($data['expected_resolution_id'] ?? null, true) !== $state['expected_resolution_id']) { throw new \DomainException('退货已有后续处理，请重新核对剩余争议'); }
        $kind = FinanceValue::text($data['kind'] ?? null, 20);
        if (!in_array($kind, ['returned', 'loss'], true)) { throw new \DomainException('请选择已实际返回入库或人工确认门店损失'); }
        $quantity = FinancePurchaseSettlement::quantity($data['quantity'] ?? null);
        if (bccomp($quantity, $state['remaining_quantity'], 4) > 0) { throw new \DomainException('处理量不能超过尚未认可、返回或确认为损失的退货余量'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $month = $ledger->postingMonth($date);
        if ($date < $returned['business_date']) { throw new \DomainException('争议处理日期不能早于实际退离'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $reference = FinanceValue::text($data['source_reference'] ?? null, 160);
        if ($kind === 'loss' && ($data['loss_confirmed'] ?? null) !== 1) { throw new \DomainException('请由最高权限人员明确确认本次门店损失，不能自动认定争议损失'); }
        $basis = FinanceValue::decode($returned['snapshot']); $warehouse = (int)$returned['warehouse_id']; $target = 0; $targetName = '';
        $costLedger = new FinanceCostLedger($tenant); $sku = (int)$returned['sku_id'];
        if ($kind === 'returned') {
            $target = FinanceValue::id($data['warehouse_id'] ?? null);
            $targetName = Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $target)->value('name');
            if ($targetName === null) { throw new \DomainException('请选择本门店实际接收退回商品的仓库'); }
            $before = $costLedger->destination($warehouse, $sku, 'return', 'purchase-return:' . $line);
            StockService::inboundFinancePurchaseReturnWithinTransaction($target, (int)$basis['goods_id'], $sku, $quantity,
                (int)$document['id'], $returned, $date);
            $after = $costLedger->destination($warehouse, $sku, 'return', 'purchase-return:' . $line);
            if (bccomp(bcsub($before['quantity'], $after['quantity'], 12), $quantity, 12) !== 0) { throw new \DomainException('返回入库缺少对应原退货成本'); }
            // 历史实物流重放先计算事件、后补价，确认快照必须读取整个重放完成后的去向差额。
            $known = bcsub($before['known_cost'], $after['known_cost'], 6); $pending = $before['pending'] || $after['pending'];
            $cost = ['known_cost' => $known, 'cost' => $pending ? null : $known, 'pending' => $pending];
        } else {
            $costLedger->recordWithinTransaction(['reference' => 'return-resolution:' . $document['id'], 'type' => 'reclassify',
                'warehouse_id' => $warehouse, 'sku_id' => (int)$returned['sku_id'], 'quantity' => $quantity, 'business_date' => $date, 'document_id' => (int)$document['id'],
                'bucket' => 'return', 'target_reference' => 'purchase-return:' . $line, 'to_bucket' => 'loss', 'to_reference' => 'purchase-return-loss:' . $document['id'],
                'snapshot' => ['return_line_id' => $line, 'reason' => $reason, 'source_reference' => $reference]]);
            $cost = $costLedger->destination($warehouse, $sku, 'loss', 'purchase-return-loss:' . $document['id']);
        }
        $impacts = $costLedger->documentImpacts((int)$document['id'], $cost['pending'] ? [$sku => true] : []);
        $months = array_values(array_unique(array_merge([$month], array_column($impacts, 'posting_month')))); sort($months);
        $snapshot = array_merge($basis, ['type' => 'purchase_return_resolution', 'kind' => $kind, 'return_line_id' => $line,
            'return_document_id' => (int)$returned['document_id'], 'subject_id' => $vendor,
            'subject_name' => Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name'),
            'actual_date' => $date, 'quantity' => $quantity, 'warehouse_id' => $target, 'warehouse_name' => $targetName,
            'remaining_quantity' => bcsub($state['remaining_quantity'], $quantity, 4), 'resolved_cost' => $cost['cost'], 'known_resolved_cost' => $cost['known_cost'],
            'cost_pending' => $cost['pending'], 'cost_impacts' => $impacts, 'posting_months' => $months, 'reason' => $reason, 'source_reference' => $reference,
            'previous_resolution_id' => $state['expected_resolution_id'], 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()]);
        $id = (int)Db::name('finance_purchase_return_resolution')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'],
            'return_line_id' => $line, 'arrival_line_id' => $returned['arrival_line_id'], 'kind' => $kind, 'business_date' => $date, 'quantity' => $quantity,
            'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot + ['resolution_id' => $id, 'created_sources' => [], 'lines' => [$snapshot]];
    }
}
