<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 确认已从实收到货数量排除的异常损失；保留实收数量，采购总成本拆分至完好商品和损失。 */
final class FinancePurchaseArrivalLosses
{
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
        if (bccomp($assessment['quantity'], '0', 4) >= 0 || bccomp($quantity, $assessment['absolute'], 4) !== 0) { throw new \DomainException('本次只确认原实收短缺中已排除的全部异常损失，不能虚增损失或重复扣库存'); }
        if (($data['excluded_from_received_confirmed'] ?? null) !== 1 || ($data['loss_confirmed'] ?? null) !== 1) { throw new \DomainException('请明确核实原实收量已排除此损失，并确认由门店承担'); }
        if (Db::name('finance_purchase_arrival_loss')->where('tenant_id', $tenant)->where('arrival_line_id', $id)->lock(true)->find()) { throw new \DomainException('本到货异常损失已确认，不能重复登记'); }
        $basis = FinanceValue::decode($arrival['snapshot']);
        $snapshot = array_merge($basis, ['type' => 'purchase_arrival_loss', 'arrival_line_id' => $id, 'subject_id' => $vendor,
            'subject_name' => Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name'),
            'warehouse_id' => (int)$arrival['warehouse_id'], 'actual_date' => $arrival['business_date'], 'quantity' => $quantity, 'arrival_source_reference' => $basis['source_reference'],
            'source_reference' => FinanceValue::text($data['source_reference'] ?? null, 160), 'reason' => FinanceValue::text($data['reason'] ?? null, 1000),
            'responsibility' => FinanceValue::text($data['responsibility'] ?? null, 1000), 'excluded_from_received_confirmed' => true,
            'previous_review_id' => (int)$review['id'], 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()]);
        Db::name('finance_purchase_arrival_loss')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'arrival_line_id' => $id,
            'review_id' => $review['id'], 'quantity' => $quantity, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        $closed = FinancePurchaseReviews::append($arrival, $document, $assessment + ['classification' => 'loss', 'reason' => $snapshot['reason'],
            'responsibility' => $snapshot['responsibility'], 'loss_document_id' => (int)$document['id'], 'actor' => FinanceAccess::actor(), 'confirmed_at' => time()], true);
        $cost = FinancePurchaseCosts::revalue($arrival, $document, $snapshot);
        $impacts = (new FinanceCostLedger($tenant))->documentImpacts((int)$document['id'], $cost['cost_pending'] ? [(int)$arrival['sku_id'] => true] : []);
        $months = array_values(array_unique(array_merge([$ledger->postingMonth($arrival['business_date'])], array_column($impacts, 'posting_month')))); sort($months);
        $line = array_merge($snapshot, $cost, ['review_id' => $closed['review_id'], 'resolved' => true, 'cost_impacts' => $impacts, 'posting_months' => $months]);
        return $line + ['created_sources' => [], 'lines' => [$line]];
    }
}
