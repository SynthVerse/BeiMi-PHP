<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceSalesQuantity;
use app\api\jxc\logic\FinanceSalesPrecision;
use app\api\jxc\logic\FinanceCustomerReturns;
use PHPUnit\Framework\TestCase;

final class FinanceSalesQuantityTest extends TestCase
{
    private function source(?string $weight = '8.7000'): array
    {
        return ['source_version' => 'revision-one', 'actual_weight' => $weight, 'pricing_units' => [
            ['id' => 11, 'name' => '斤', 'kind' => 'weight', 'precision' => 4, 'quantity' => $weight, 'jin_factor' => '1'],
            ['id' => 12, 'name' => '瓶', 'kind' => 'count', 'precision' => 0, 'quantity' => '3.0000', 'jin_factor' => null],
            ['id' => 13, 'name' => '只', 'kind' => 'count', 'precision' => 0, 'quantity' => null, 'jin_factor' => null],
        ]];
    }

    private function input(int $unit, string $quantity): array
    {
        return ['fact_version' => 2, 'source_version' => 'revision-one', 'price_unit_id' => $unit, 'settlement_quantity' => $quantity];
    }

    public function test_three_bottles_with_unknown_weight_cost_36_without_manufacturing_weight(): void
    {
        $result = FinanceSalesQuantity::price($this->source(null), $this->input(12, '3'));
        self::assertSame('36.00', FinanceSalesPrecision::amount($result['settlement_quantity'], '12.00', 'cents')['amount']);
        self::assertNull($result['settlement_weight']); self::assertNull($result['covered_weight']);
    }

    public function test_box_count_does_not_invent_piece_count(): void
    {
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('缺少');
        FinanceSalesQuantity::price($this->source(), $this->input(13, '12'));
    }

    public function test_unknown_weight_cannot_price_as_weight(): void
    {
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('待确认');
        FinanceSalesQuantity::price($this->source(null), $this->input(11, '3'));
    }

    public function test_partial_count_cannot_slice_an_unsplit_group(): void
    {
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('完整交付子组');
        FinanceSalesQuantity::price($this->source(), $this->input(12, '1'));
    }

    public function test_whole_bottle_precision_rejects_fraction(): void
    {
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('精度');
        FinanceSalesQuantity::price($this->source(), $this->input(12, '2.5'));
    }

    public function test_stale_facts_cannot_be_reused(): void
    {
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('已变化');
        FinanceSalesQuantity::price($this->source(), array_replace($this->input(12, '3'), ['source_version' => 'old']));
    }

    public function test_weight_price_uses_unrounded_measurement_and_retains_difference(): void
    {
        $result = FinanceSalesQuantity::price($this->source('0.6660'), $this->input(11, '0.666'));
        self::assertSame('13.32', FinanceSalesPrecision::amount($result['settlement_quantity'], '20.00', 'cents')['amount']);
        self::assertSame('0.0000', $result['weight_difference']);
    }

    public function test_return_requires_explicit_saleable_inspection(): void
    {
        self::assertSame('loss', FinanceCustomerReturns::disposition(['disposition' => 'loss']));
        $this->expectException(\DomainException::class);
        FinanceCustomerReturns::disposition(['disposition' => 'resalable', 'received_verified' => 1]);
    }
}
