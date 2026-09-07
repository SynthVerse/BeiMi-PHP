<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinancePurchaseSettlement;
use PHPUnit\Framework\TestCase;

final class FinancePurchaseSettlementTest extends TestCase
{
    public function test_partial_formal_amount_keeps_remaining_estimate_and_does_not_change_actual_quantity(): void
    {
        $first = FinancePurchaseSettlement::costValue('100', '100.00', '40', '82.00');
        self::assertSame('60.0000', $first['pending_quantity']);
        self::assertSame('60.00', $first['remaining_estimated_amount']);
        self::assertSame('142.00', $first['known_amount']); self::assertFalse($first['cost_pending']);
        $last = FinancePurchaseSettlement::costValue('100', '100.00', '100', '200.00');
        self::assertSame('0.0000', $last['pending_quantity']); self::assertSame('200.00', $last['known_amount']);
        $unknown = FinancePurchaseSettlement::costValue('100', null, '40', '82.00');
        self::assertSame('82.00', $unknown['known_amount']); self::assertTrue($unknown['cost_pending']);
        $this->expectException(\DomainException::class);
        FinancePurchaseSettlement::costValue('100', '100.00', '101', '200.00');
    }
}
