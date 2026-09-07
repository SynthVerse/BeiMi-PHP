<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinancePurchaseEstimate;
use PHPUnit\Framework\TestCase;

final class FinancePurchaseEstimateTest extends TestCase
{
    public function test_blank_optional_inputs_remain_unknown_instead_of_zero(): void
    {
        $result = FinancePurchaseEstimate::line(['actual_quantity' => '10', 'reported_quantity' => '', 'agreed_quantity' => '',
            'estimated_quantity' => '', 'agreed_price' => '', 'estimated_price' => '']);
        self::assertSame('10.0000', $result['estimated_quantity']);
        self::assertNull($result['estimated_amount']); self::assertNull($result['reported_quantity']);
        self::assertTrue($result['cost_pending']);
    }

    public function test_manual_estimate_weight_keeps_default_weight_and_requires_a_reason(): void
    {
        $input = ['actual_quantity' => '100', 'reported_quantity' => '102', 'estimated_quantity' => '101',
            'agreed_price' => '2.50', 'quantity_reason' => '双方核对后扣除包装一斤'];
        $result = FinancePurchaseEstimate::line($input);
        self::assertSame('252.50', $result['estimated_amount']);
        self::assertSame('102.0000', $result['default_estimated_quantity']);
        self::assertSame('101.0000', $result['estimated_quantity']);
        self::assertSame('双方核对后扣除包装一斤', $result['quantity_reason']);
        unset($input['quantity_reason']);
        $this->expectException(\DomainException::class);
        FinancePurchaseEstimate::line($input);
    }

    public function test_agreed_price_and_weight_determine_estimate_while_actual_arrival_remains_separate(): void
    {
        $result = FinancePurchaseEstimate::line(['actual_quantity' => '499', 'reported_quantity' => '500',
            'agreed_quantity' => '500', 'agreed_price' => '1.23'], ['price' => '9.00', 'reference' => 'previous-settlement']);
        self::assertSame('499.0000', $result['actual_quantity']); self::assertSame('500.0000', $result['estimated_quantity']);
        self::assertSame('615.00', $result['estimated_amount']); self::assertSame('agreed', $result['price_basis']);
        self::assertSame('-1.0000', $result['arrival_difference']); self::assertFalse($result['cost_pending']);
    }

    public function test_estimate_price_falls_back_to_last_formal_supplier_sku_price_then_evidenced_manual_price_or_unknown(): void
    {
        $last = FinancePurchaseEstimate::line(['actual_quantity' => '100', 'reported_quantity' => '101'], ['price' => '1.01', 'reference' => 'supplier-confirmation:7']);
        self::assertSame('102.01', $last['estimated_amount']); self::assertSame('last_formal', $last['price_basis']);
        self::assertSame('supplier-confirmation:7', $last['price_reference']);
        $manual = FinancePurchaseEstimate::line(['actual_quantity' => '3.3333', 'estimated_price' => '1.01', 'estimate_reason' => '供应商电话报价截图已核对']);
        self::assertSame('3.37', $manual['estimated_amount']); self::assertSame('manual', $manual['price_basis']);
        $unknown = FinancePurchaseEstimate::line(['actual_quantity' => '100']);
        self::assertTrue($unknown['cost_pending']); self::assertNull($unknown['estimated_price']); self::assertNull($unknown['estimated_amount']);
        $this->expectException(\DomainException::class);
        FinancePurchaseEstimate::line(['actual_quantity' => '100', 'estimated_price' => '5.00']);
    }
}
