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
        return $value;
    }

    public static function revalue(array $arrival, array $document, array $snapshot): array
    {
        $value = self::value($arrival);
        $event = (new FinanceCostLedger(FinanceAccess::tenant()))->recordWithinTransaction([
            'reference' => 'purchase-cost:' . $document['id'] . ':' . $arrival['id'], 'type' => 'reestimate',
            'sku_id' => (int)$arrival['sku_id'], 'warehouse_id' => (int)$arrival['warehouse_id'], 'document_id' => (int)$document['id'],
            'business_date' => $arrival['business_date'], 'origin' => 'purchase-arrival:' . $arrival['id'],
            'amount' => $value['known_amount'], 'pending' => $value['cost_pending'], 'snapshot' => $snapshot]);
        return $value + ['cost_changes' => $event['changes']];
    }
}
