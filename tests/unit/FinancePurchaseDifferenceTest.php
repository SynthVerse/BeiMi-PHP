<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinancePurchaseDifference;
use PHPUnit\Framework\TestCase;

final class FinancePurchaseDifferenceTest extends TestCase
{
    public function test_review_threshold_uses_exact_ratio_before_display_rounding(): void
    {
        $rule = ['absolute_limit' => '20000.0000', 'percent_limit' => '1.0000'];
        self::assertFalse(FinancePurchaseDifference::assess('10000', '1000000', $rule)['requires_owner']);
        $above = FinancePurchaseDifference::assess('10000.0001', '1000000', $rule);
        self::assertSame('1.000000', $above['percent']);
        self::assertTrue($above['requires_owner']);
    }

    public function test_every_nonzero_difference_needs_a_person_and_either_limit_can_escalate_review(): void
    {
        $rule = ['version' => 7, 'scope' => 'vendor_sku', 'absolute_limit' => '2.0000', 'percent_limit' => '1.0000'];
        $small = FinancePurchaseDifference::assess('-0.5', '100', $rule);
        self::assertTrue($small['requires_confirmation']); self::assertFalse($small['requires_owner']);
        self::assertSame('0.500000', $small['percent']); self::assertSame(7, $small['rule']['version']);
        self::assertTrue(FinancePurchaseDifference::assess('1.5', '100', $rule)['requires_owner']);
        self::assertTrue(FinancePurchaseDifference::assess('3', '1000', $rule)['requires_owner']);
        self::assertTrue(FinancePurchaseDifference::assess('0.0001', '100', null)['requires_owner']);
        self::assertFalse(FinancePurchaseDifference::assess('0', '100', null)['requires_confirmation']);
    }
}
