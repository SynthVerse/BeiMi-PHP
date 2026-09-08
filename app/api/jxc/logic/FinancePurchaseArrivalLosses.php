<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 确认已从实收到货数量排除的异常损失；保留实收数量，采购总成本拆分至完好商品和损失。 */
final class FinancePurchaseArrivalLosses
{
    public static function composition(array $arrival): array
    {
        $losses = Db::name('finance_purchase_arrival_loss')->where('tenant_id', FinanceAccess::tenant())->where('arrival_line_id', $arrival['id'])->order('id')->lock(true)->select()->toArray();
        $quantity = '0.0000';
        foreach ($losses as &$loss) { $quantity = bcadd($quantity, $loss['quantity'], 4); $loss['snapshot'] = FinanceValue::decode($loss['snapshot']); } unset($loss);
        $assessment = FinancePurchaseReviews::assessment($arrival);
        return ['confirmed_loss_quantity' => $quantity, 'remaining_quantity' => bcsub($assessment['absolute'], $quantity, 4), 'losses' => $losses];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $vendor = FinanceValue::id($data['subject_id'] ?? null);
        $id = FinanceValue::id($data['arrival_line_id'] ?? null);
        $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('vendor_id', $vendor)->where('id', $id)->lock(true)->find();
        if (!$arrival) { throw new \DomainException('到货来源不存在或不属于本供应商'); }
        $review = FinancePurchaseReviews::latest($id);
        if (!$review || (bool)$review['resolved'] || !in_array($review['classification'], ['loss', 'dispute'], true)) { throw new \DomainException('请先从到货差待办核实异常损耗或争议来源'); }
        if (FinanceValue::id($data['expected_review_id'] ?? null, true) !== (int)$review['id']) { throw new \DomainException('到货差已被再次复核，请读取最新待办'); }
        $assessment = FinancePurchaseReviews::assessment($arrival); $quantity = FinancePurchaseSettlement::quantity($data['quantity'] ?? null);
        $composition = self::composition($arrival);
        if (bccomp($assessment['quantity'], '0', 4) >= 0 || bccomp($quantity, $composition['remaining_quantity'], 4) > 0) { throw new \DomainException('本次损失不能超过原实收已排除且尚未核实的短缺量，不能重复扣库存'); }
        if (($data['excluded_from_received_confirmed'] ?? null) !== 1 || ($data['loss_confirmed'] ?? null) !== 1) { throw new \DomainException('请明确核实原实收量已排除此损失，并确认由门店承担'); }
        $sourceReference = FinanceValue::text($data['source_reference'] ?? null, 160);
        foreach ($composition['losses'] as $loss) { if (($loss['snapshot']['source_reference'] ?? '') === $sourceReference) { throw new \DomainException('本到货已使用该损失核实记录，请查看原凭据，不能重复登记'); } }
        $basis = FinanceValue::decode($arrival['snapshot']);
        $snapshot = array_merge($basis, ['type' => 'purchase_arrival_loss', 'arrival_line_id' => $id, 'subject_id' => $vendor,
            'subject_name' => Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name'),
            'warehouse_id' => (int)$arrival['warehouse_id'], 'actual_date' => $arrival['business_date'], 'quantity' => $quantity, 'arrival_source_reference' => $basis['source_reference'],
            'warehouse_name' => $basis['warehouse_name'] ?? (Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $arrival['warehouse_id'])->value('name') ?: '名称未留存'),
            'source_reference' => $sourceReference, 'reason' => FinanceValue::text($data['reason'] ?? null, 1000),
            'responsibility' => FinanceValue::text($data['responsibility'] ?? null, 1000), 'excluded_from_received_confirmed' => true,
            'previous_review_id' => (int)$review['id'], 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(),
            'confirmed_loss_quantity' => bcadd($composition['confirmed_loss_quantity'], $quantity, 4), 'remaining_quantity' => bcsub($composition['remaining_quantity'], $quantity, 4)]);
        Db::name('finance_purchase_arrival_loss')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'arrival_line_id' => $id,
            'review_id' => $review['id'], 'quantity' => $quantity, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        $closed = FinancePurchaseReviews::append($arrival, $document, $assessment + ['classification' => 'loss', 'reason' => $snapshot['reason'],
            'responsibility' => $snapshot['responsibility'], 'loss_document_id' => (int)$document['id'], 'actor' => FinanceAccess::actor(), 'confirmed_at' => time(),
            'reviewed_quantity' => $quantity, 'confirmed_loss_quantity' => $snapshot['confirmed_loss_quantity'], 'remaining_quantity' => $snapshot['remaining_quantity']], true);
        $cost = FinancePurchaseCosts::revalue($arrival, $document, $snapshot);
        $impacts = (new FinanceCostLedger($tenant))->documentImpacts((int)$document['id'], $cost['cost_pending'] ? [(int)$arrival['sku_id'] => true] : []);
        $months = array_values(array_unique(array_merge([$ledger->postingMonth($arrival['business_date'])], array_column($impacts, 'posting_month')))); sort($months);
        $line = array_merge($snapshot, $cost, ['review_id' => $closed['review_id'], 'resolved' => $closed['resolved'], 'cost_impacts' => $impacts, 'posting_months' => $months]);
        return $line + ['created_sources' => [], 'lines' => [$line]];
    }
}
