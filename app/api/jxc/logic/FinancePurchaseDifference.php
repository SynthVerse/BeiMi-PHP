<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 复核门槛不替代人员确认；绝对量或比例任一超限均升级。 */
final class FinancePurchaseDifference
{
    public static function assess(string $difference, string $baseline, ?array $rule): array
    {
        if (!preg_match('/^-?(0|[1-9][0-9]{0,11})(\.[0-9]{1,4})?$/D', $difference)) { throw new \DomainException('采购重量差格式无效'); }
        $baseline = FinancePurchaseSettlement::quantity($baseline, true);
        $absolute = bccomp($difference, '0', 4) < 0 ? bcsub('0', $difference, 4) : bcadd($difference, '0', 4);
        $nonzero = bccomp($absolute, '0', 4) !== 0;
        $percent = bccomp($baseline, '0', 4) > 0 ? bcdiv(bcmul($absolute, '100', 6), $baseline, 6) : ($nonzero ? null : '0.000000');
        // 门槛判断交叉相乘，避免显示比例截断后把略微超限误判为等于阈值。
        $owner = $nonzero && ($rule === null || $percent === null || bccomp($absolute, $rule['absolute_limit'], 4) > 0
            || bccomp(bcmul($absolute, '100', 8), bcmul($baseline, $rule['percent_limit'], 8), 8) > 0);
        return ['quantity' => bcadd($difference, '0', 4), 'absolute' => $absolute, 'percent' => $percent, 'rule' => $rule,
            'requires_confirmation' => $nonzero, 'requires_owner' => $owner];
    }
}
