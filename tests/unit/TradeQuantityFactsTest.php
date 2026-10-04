<?php
declare(strict_types=1);
namespace tests\unit;
use app\api\jxc\logic\TradeQuantityFacts;
use PHPUnit\Framework\TestCase;

final class TradeQuantityFactsTest extends TestCase
{
    private function units(): array
    {
        return [1 => ['unit_name' => '斤', 'measure_type' => 'weight', 'quantity_precision' => 4, 'jin_factor' => '1'],
            2 => ['unit_name' => '把', 'measure_type' => 'count', 'quantity_precision' => 0, 'jin_factor' => null],
            3 => ['unit_name' => '瓶', 'measure_type' => 'count', 'quantity_precision' => 0, 'jin_factor' => null]];
    }
    public function test_original_kilograms_keep_precision_and_count_is_independent(): void
    {
        $facts = TradeQuantityFacts::normalize(['net_weight_input' => ['value' => '0.333', 'unit' => 'kg'],
            'quantity_facts' => [['unit_id' => 2, 'quantity' => '5']]], ['unit_id' => 2, 'base_unit_id' => 1, 'base_unit_name' => '斤'], $this->units());
        self::assertSame('0.6660', $facts['inventory_base_quantity']);
        self::assertSame('5.0000', $facts['quantity_facts'][0]['quantity']);
        self::assertSame('0.333', $facts['net_weight_input']['value']);
        self::assertSame('13.32', bcmul($facts['actual_delivery_weight'], '20', 2));
    }
    public function test_bottle_inventory_does_not_create_a_weight(): void
    {
        $facts = TradeQuantityFacts::normalize(['quantity_facts' => [['unit_id' => 3, 'quantity' => '3']]],
            ['unit_id' => 3, 'base_unit_id' => 3, 'base_unit_name' => '瓶'], $this->units());
        self::assertNull($facts['actual_delivery_weight']);
        self::assertSame('3.0000', $facts['inventory_base_quantity']);
    }
    public function test_processing_preserves_raw_input_delivery_and_actual_loss(): void
    {
        $facts = TradeQuantityFacts::normalize(['net_weight_input' => ['value' => '18', 'unit' => '斤'],
            'processing' => ['mode' => 'direct_loss', 'raw_input_weight' => '20', 'loss_weight' => '2', 'disposition' => 'discarded', 'reason' => '修整废弃']],
            ['unit_id' => 1, 'base_unit_id' => 1, 'base_unit_name' => '斤'], $this->units());
        self::assertSame('18.0000', $facts['inventory_base_quantity']);
        self::assertSame('20.0000', $facts['processing']['raw_input_weight']);
        self::assertSame('2.0000', $facts['processing_loss_weight']);
    }
    public function test_processing_gap_cannot_be_inferred_as_loss(): void
    {
        $this->expectException(\DomainException::class);
        TradeQuantityFacts::normalize(['final_actual_weight' => '18', 'processing' => ['mode' => 'direct_loss', 'raw_input_weight' => '20', 'loss_weight' => '1', 'disposition' => 'discarded', 'reason' => '废弃']],
            ['unit_id' => 1, 'base_unit_id' => 1, 'base_unit_name' => '斤'], $this->units());
    }
    public function test_gross_tare_has_only_one_physical_net_result(): void
    {
        $facts = TradeQuantityFacts::normalize(['gross_weight_input' => ['value' => '1500', 'unit' => 'g'], 'tare_weight_input' => ['value' => '150', 'unit' => 'g']],
            ['unit_id' => 1, 'base_unit_id' => 1, 'base_unit_name' => '斤'], $this->units());
        self::assertSame('2.7000', $facts['actual_delivery_weight']);
    }

    public function test_base_weight_conversion_rejects_truncation(): void
    {
        $units = $this->units(); $units[1]['unit_name'] = '公斤'; $units[1]['jin_factor'] = '2';
        $this->expectException(\DomainException::class);
        TradeQuantityFacts::normalize(['net_weight_input' => ['value' => '0.6661', 'unit' => '斤']],
            ['unit_id' => 1, 'base_unit_id' => 1, 'base_unit_name' => '公斤'], $units);
    }

    public function test_package_fraction_rejects_hidden_fifth_decimal(): void
    {
        self::assertSame('3.3333', TradeQuantityFacts::exactMultiply('10', '0.333330'));
        $this->expectException(\DomainException::class);
        TradeQuantityFacts::exactMultiply('1', '0.333330');
    }
}
