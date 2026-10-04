<?php
declare(strict_types=1);
namespace tests\unit;

use app\api\jxc\logic\InventoryMeasurements;
use app\api\jxc\logic\InventoryCountBasis;
use PHPUnit\Framework\TestCase;

final class InventoryMeasurementsTest extends TestCase
{
    private function weightUnit(): array { return ['measure_type' => 'weight', 'jin_factor' => '1', 'quantity_precision' => 4]; }
    private function measurement(string $id, string $value, string $unit = '斤'): array {
        return ['measurement_id' => $id, 'location' => $id, 'measured_at' => 120, 'net_weight_input' => ['value' => $value, 'unit' => $unit]];
    }
    public function test_containers_add_exact_original_weights(): void {
        $facts = InventoryMeasurements::normalize(['measurements' => [$this->measurement('a', '20'), $this->measurement('b', '9.3', '公斤'), $this->measurement('c', '10000', '克')]], $this->weightUnit(), 100, 130);
        self::assertSame('58.6000', $facts['counted_quantity']);
        self::assertSame('9.3', $facts['measurements'][1]['original']['value']);
    }
    public function test_duplicate_container_is_not_added_twice(): void {
        $this->expectException(\DomainException::class);
        InventoryMeasurements::normalize(['measurements' => [$this->measurement('a', '20'), $this->measurement('a', '20')]], $this->weightUnit(), 100, 130);
    }
    public function test_unknown_is_not_zero(): void {
        $this->expectException(\DomainException::class);
        InventoryMeasurements::normalize(['measurements' => []], $this->weightUnit(), 100, 130);
    }
    public function test_count_stock_has_no_fake_weight(): void {
        $facts = InventoryMeasurements::normalize(['measurements' => [['measurement_id' => 'b', 'location' => '货架', 'measured_at' => 120, 'quantity' => '3']]], ['measure_type' => 'count', 'quantity_precision' => 0], 100, 130);
        self::assertSame('3.0000', $facts['counted_quantity']); self::assertArrayNotHasKey('net_weight_input', $facts['measurements'][0]);
    }
    public function test_gross_and_tare_preserve_precision(): void {
        $fact = $this->measurement('a', '1'); unset($fact['net_weight_input']);
        $fact += ['gross_weight_input' => ['value' => '0.433', 'unit' => '公斤'], 'tare_weight_input' => ['value' => '100', 'unit' => '克']];
        self::assertSame('0.6660', InventoryMeasurements::normalize(['measurements' => [$fact]], $this->weightUnit(), 100, 130)['counted_quantity']);
    }
    public function test_included_receipt_is_removed_to_reach_original_cutoff(): void {
        $facts = ['observed_quantity' => '68.6000'];
        $current = ['basis_token' => 'basis1', 'movements' => [['stock_flow_id' => 31, 'quantity_delta' => '10.0000']]];
        $input = ['basis_token' => 'basis1', 'movement_review' => ['reviewed' => true, 'last_stock_flow_id' => 31, 'included_flow_ids' => [31], 'reason' => '新收十斤已放在第三筐称量']];
        self::assertSame('58.6000', InventoryCountBasis::reconcile($input, $facts, $current)['counted_quantity']);
    }
    public function test_changed_cutoff_rejects_old_preview(): void {
        $this->expectException(\DomainException::class);
        InventoryCountBasis::reconcile(['basis_token' => 'old'], ['observed_quantity' => '58.6000'], ['basis_token' => 'new', 'movements' => []]);
    }
}
