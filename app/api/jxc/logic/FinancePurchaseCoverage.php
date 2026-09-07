<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 正式结算与未结算退货认可共同关闭到货量，认可金额只用于还原原到货毛成本。 */
final class FinancePurchaseCoverage
{
    public static function totals(int $arrival): array
    {
        $tenant = FinanceAccess::tenant(); $settled = '0.0000'; $formal = '0.00'; $closed = '0.0000'; $returnedAmount = '0.00'; $creditedQuantity = '0.0000';
        foreach (Db::name('finance_purchase_settlement_line')->where('tenant_id', $tenant)->where('arrival_line_id', $arrival)->order('id')->lock(true)->select()->toArray() as $row) {
            $settled = bcadd($settled, $row['covered_quantity'], 4); $formal = bcadd($formal, $row['amount'], 2);
        }
        foreach (Db::name('finance_purchase_return_resolution')->where('tenant_id', $tenant)->where('arrival_line_id', $arrival)->where('kind', 'accepted')->order('id')->lock(true)->select()->toArray() as $row) {
            $closed = bcadd($closed, $row['unsettled_quantity'], 4); $returnedAmount = bcadd($returnedAmount, $row['unsettled_amount'], 2);
            $creditedQuantity = bcadd($creditedQuantity, bcsub($row['quantity'], $row['unsettled_quantity'], 4), 4);
        }
        return ['settled_quantity' => $settled, 'settled_return_quantity' => $creditedQuantity,
            'covered_quantity' => bcadd($settled, $closed, 4), 'formal_amount' => bcadd($formal, $returnedAmount, 2)];
    }

    public static function sql(?string $through = null): string
    {
        $tenant = FinanceAccess::tenant();
        $settled = Db::name('finance_purchase_settlement_line')->where('tenant_id', $tenant)->field('arrival_line_id,covered_quantity AS quantity')->buildSql(false);
        $returns = Db::name('finance_purchase_return_resolution')->where('tenant_id', $tenant)->where('kind', 'accepted');
        if ($through !== null) { $returns->where('business_date', '<=', FinanceValue::date($through)); }
        $accepted = $returns->field('arrival_line_id,unsettled_quantity AS quantity')->buildSql(false);
        return '(SELECT arrival_line_id,SUM(quantity) AS quantity FROM (' . $settled . ' UNION ALL ' . $accepted . ') AS covered_parts GROUP BY arrival_line_id)';
    }
}
