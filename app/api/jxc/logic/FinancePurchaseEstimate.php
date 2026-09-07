<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 暂估只计成本；报重、实际到货量和暂估计费量分别保留。 */
final class FinancePurchaseEstimate
{
    public static function line(array $data, ?array $lastFormalPrice = null): array
    {
        foreach (['reported_quantity', 'agreed_quantity', 'estimated_quantity', 'agreed_price', 'estimated_price'] as $optional) {
            if (($data[$optional] ?? null) === '') { unset($data[$optional]); }
        }
        $actual = self::quantity($data['actual_quantity'] ?? null);
        $reported = isset($data['reported_quantity']) ? self::quantity($data['reported_quantity']) : null;
        $agreed = isset($data['agreed_quantity']) ? self::quantity($data['agreed_quantity']) : null;
        $defaultQuantity = $agreed ?? $reported ?? $actual;
        $quantity = isset($data['estimated_quantity']) ? self::quantity($data['estimated_quantity']) : $defaultQuantity;
        $quantityReason = bccomp($quantity, $defaultQuantity, 4) !== 0 ? FinanceValue::text($data['quantity_reason'] ?? null, 1000) : '';
        $reference = ''; $reason = '';
        if (isset($data['agreed_price'])) { $price = FinanceValue::money($data['agreed_price'], true); $basis = 'agreed'; }
        elseif ($lastFormalPrice !== null) {
            $price = FinanceValue::money($lastFormalPrice['price'] ?? null, true); $basis = 'last_formal';
            $reference = FinanceValue::text($lastFormalPrice['reference'] ?? null, 160);
        } elseif (isset($data['estimated_price'])) {
            $price = FinanceValue::money($data['estimated_price'], true); $basis = 'manual'; $reason = FinanceValue::text($data['estimate_reason'] ?? null, 1000);
        } else { $price = null; $basis = 'unknown'; }
        $amount = $price === null ? null : FinanceValue::money(bcadd(bcmul($quantity, $price, 6), '0.005', 2), true);
        return ['actual_quantity' => $actual, 'reported_quantity' => $reported, 'agreed_quantity' => $agreed,
            'agreed_price' => isset($data['agreed_price']) ? FinanceValue::money($data['agreed_price'], true) : null,
            'default_estimated_quantity' => $defaultQuantity, 'quantity_reason' => $quantityReason,
            'estimated_quantity' => $quantity, 'estimated_price' => $price, 'estimated_amount' => $amount, 'price_basis' => $basis,
            'price_reference' => $reference, 'estimate_reason' => $reason,
            'arrival_difference' => $reported === null ? null : bcsub($actual, $reported, 4), 'cost_pending' => $price === null];
    }

    private static function quantity(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,4})?$/D', $value) || bccomp($value, '0', 4) <= 0) { throw new \DomainException('重量须为大于零且最多四位小数的数字'); }
        return bcadd($value, '0', 4);
    }
}
