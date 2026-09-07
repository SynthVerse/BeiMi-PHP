<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 正式结算累计金额加尚未处理实收量的暂估，共同构成本来源的当前成本。 */
final class FinancePurchaseSettlement
{
    public static function costValue(string $actual, ?string $estimate, string $covered, string $formalAmount): array
    {
        $actual = self::quantity($actual); $covered = self::quantity($covered, true);
        if (bccomp($covered, $actual, 4) > 0) { throw new \DomainException('累计处理量不能超过实际到货量'); }
        $formalAmount = FinanceValue::money($formalAmount, true);
        $remaining = bcsub($actual, $covered, 4);
        $remainingAmount = '0.00';
        if (bccomp($remaining, '0', 4) > 0) {
            $remainingAmount = $estimate === null ? null : bcadd(bcdiv(bcmul(FinanceValue::money($estimate, true), $remaining, 6), $actual, 6), '0.005', 2);
        }
        return ['pending_quantity' => $remaining, 'remaining_estimated_amount' => $remainingAmount,
            'known_amount' => FinanceValue::money(bcadd($formalAmount, $remainingAmount ?? '0', 2), true), 'cost_pending' => $remainingAmount === null];
    }

    public static function quantity(mixed $value, bool $allowZero = false): string
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,4})?$/D', $value) || (!$allowZero && bccomp($value, '0', 4) <= 0)) {
            throw new \DomainException('采购重量须为有效的四位以内小数，处理量必须大于零');
        }
        return bcadd($value, '0', 4);
    }
}
