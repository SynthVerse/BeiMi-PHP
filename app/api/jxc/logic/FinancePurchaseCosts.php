<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 原暂估、正式结算与附加成本各自留痕，统一重建来源当前成本。 */
final class FinancePurchaseCosts
{
    public static function value(array $arrival, string $newCoverage = '0', string $newFormal = '0'): array
    {
        $coverage = FinancePurchaseCoverage::totals((int)$arrival['id']);
        $covered = bcadd($newCoverage, $coverage['covered_quantity'], 4); $formal = bcadd($newFormal, $coverage['formal_amount'], 2); $extra = '0.00';
        foreach (Db::name('finance_purchase_cost_change')->where('tenant_id', FinanceAccess::tenant())->where('arrival_line_id', $arrival['id'])->order('id')->lock(true)->select()->toArray() as $row) {
            $extra = bcadd($extra, $row['amount'], 2);
        }
        $value = FinancePurchaseSettlement::costValue($arrival['actual_quantity'], $arrival['estimated_amount'], $covered, $formal);
        $value['known_amount'] = FinanceValue::money(bcadd($value['known_amount'], $extra, 2), true);
        $value['cost_adjustments'] = $extra;
        $value['difference_pending'] = FinancePurchaseReviews::pending($arrival);
        $value['cost_pending'] = $value['cost_pending'] || $value['difference_pending'];
        $loss = Db::name('finance_purchase_arrival_loss')->where('tenant_id', FinanceAccess::tenant())->where('arrival_line_id', $arrival['id'])->lock(true)->find();
        $value['gross_known_amount'] = $value['known_amount']; $value['loss_known_amount'] = '0.00';
        $value['loss_document_id'] = (int)($loss['document_id'] ?? 0); $value['loss_quantity'] = $loss['quantity'] ?? '0.0000';
        if ($loss) {
            $basis = bcadd($arrival['actual_quantity'], $loss['quantity'], 4);
            $value['loss_known_amount'] = bcadd(bcdiv(bcmul($value['known_amount'], $loss['quantity'], 6), $basis, 6), '0.005', 2);
            $value['known_amount'] = bcsub($value['known_amount'], $value['loss_known_amount'], 2);
        }
        return $value;
    }

    public static function revalue(array $arrival, array $document, array $snapshot): array
    {
        $value = self::value($arrival);
        return self::recordValue($arrival, $document, $snapshot, $value, 'purchase-cost:' . $document['id'] . ':' . $arrival['id']);
    }

    public static function recordValue(array $arrival, array $document, array $snapshot, array $value, string $reference): array
    {
        $tenant = FinanceAccess::tenant(); $cost = new FinanceCostLedger($tenant);
        $event = $cost->recordWithinTransaction([
            'reference' => $reference, 'type' => 'reestimate',
            'sku_id' => (int)$arrival['sku_id'], 'warehouse_id' => (int)$arrival['warehouse_id'], 'document_id' => (int)$document['id'],
            'business_date' => $arrival['business_date'], 'origin' => 'purchase-arrival:' . $arrival['id'],
            'amount' => $value['known_amount'], 'pending' => $value['cost_pending'], 'snapshot' => $snapshot]);
        if ($value['loss_document_id']) {
            $origin = 'purchase-arrival-loss:' . $value['loss_document_id'];
            if (!Db::name('finance_cost_origin')->where('tenant_id', $tenant)->where('origin_key', $origin)->lock(true)->find()) {
                $cost->recordWithinTransaction(['reference' => $origin, 'type' => 'excluded_loss', 'origin' => $origin, 'target_reference' => $origin,
                    'sku_id' => (int)$arrival['sku_id'], 'warehouse_id' => (int)$arrival['warehouse_id'], 'document_id' => (int)$document['id'],
                    'business_date' => $arrival['business_date'], 'quantity' => $value['loss_quantity'], 'amount' => null, 'snapshot' => $snapshot]);
            }
            $lossEvent = $cost->recordWithinTransaction(['reference' => $reference . ':loss', 'type' => 'reestimate', 'origin' => $origin,
                'sku_id' => (int)$arrival['sku_id'], 'warehouse_id' => (int)$arrival['warehouse_id'], 'document_id' => (int)$document['id'],
                'business_date' => $arrival['business_date'], 'amount' => $value['loss_known_amount'], 'pending' => $value['cost_pending'], 'snapshot' => $snapshot]);
            foreach ($lossEvent['changes'] as $bucket => $amount) { $event['changes'][$bucket] = bcadd($event['changes'][$bucket] ?? '0', $amount, 6); }
        }
        return $value + ['cost_changes' => $event['changes']];
    }
}
