<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceCostAllocation;
use PHPUnit\Framework\TestCase;

final class FinanceCostAllocationTest extends TestCase
{
    public function test_partial_supplier_cost_keeps_unpriced_remainder_pending_and_final_cost_follows_existing_sale(): void
    {
        $state = \app\api\jxc\logic\FinanceCostAllocation::receive(\app\api\jxc\logic\FinanceCostAllocation::empty(), 'arrival-partial', 10, 20, '100', null)['state'];
        $state = \app\api\jxc\logic\FinanceCostAllocation::issue($state, 10, 20, '30', 'sale', 'sale-partial')['state'];
        $partial = \app\api\jxc\logic\FinanceCostAllocation::reviseEstimate($state, 'arrival-partial', '82.00', true);
        self::assertSame('24.600000', $partial['changes']['sale']);
        $balance = \app\api\jxc\logic\FinanceCostAllocation::balance($partial['state'], 10, 20);
        self::assertSame('57.400000', $balance['known_value']); self::assertNull($balance['value']);
        self::assertSame('70.000000000000', $balance['quantity']);
        $final = \app\api\jxc\logic\FinanceCostAllocation::reviseEstimate($partial['state'], 'arrival-partial', '200.00', false);
        self::assertSame('35.400000', $final['changes']['sale']);
        self::assertSame('140.000000', \app\api\jxc\logic\FinanceCostAllocation::balance($final['state'], 10, 20)['value']);
    }

    public function test_physical_return_restores_original_sale_cost_and_later_adjustment_follows_returned_stock(): void
    {
        $state = FinanceCostAllocation::receive(FinanceCostAllocation::empty(), 'arrival:1', 10, 20, '100', '1000.00')['state'];
        $state = FinanceCostAllocation::issue($state, 10, 20, '40', 'sale', 'delivery:1')['state'];
        $state = FinanceCostAllocation::receive($state, 'arrival:2', 10, 20, '100', '2000.00')['state'];
        $restored = FinanceCostAllocation::restore($state, 10, 20, '10', 'sale', 'delivery:1');
        self::assertSame('100.000000', $restored['cost']);
        self::assertSame('170.000000000000', FinanceCostAllocation::balance($restored['state'], 10, 20)['quantity']);
        self::assertSame('2700.000000', FinanceCostAllocation::balance($restored['state'], 10, 20)['value']);
        $adjusted = FinanceCostAllocation::adjust($restored['state'], 'arrival:1', '1100.00');
        self::assertSame('30.000000', $adjusted['changes']['sale']);
        self::assertSame('70.000000', $adjusted['changes']['inventory']);
        $this->expectException(\DomainException::class);
        FinanceCostAllocation::restore($restored['state'], 10, 20, '31', 'sale', 'delivery:1');
    }

    public function test_issue_uses_warehouse_moving_average_and_keeps_origin_shares_for_later_cost_adjustment(): void
    {
        $state = FinanceCostAllocation::empty();
        $state = FinanceCostAllocation::receive($state, 'arrival:1', 10, 20, '100', '1000.00')['state'];
        $state = FinanceCostAllocation::receive($state, 'arrival:2', 10, 20, '100', '2000.00')['state'];
        $issued = FinanceCostAllocation::issue($state, 10, 20, '50', 'sale', 'delivery:1');
        self::assertSame('750.000000', $issued['known_cost']); self::assertFalse($issued['pending']);
        self::assertSame('2250.000000', FinanceCostAllocation::balance($issued['state'], 10, 20)['known_value']);
        $adjusted = FinanceCostAllocation::adjust($issued['state'], 'arrival:1', '1200.00');
        self::assertSame('50.000000', $adjusted['changes']['sale']); self::assertSame('150.000000', $adjusted['changes']['inventory']);
        self::assertSame('2400.000000', FinanceCostAllocation::balance($adjusted['state'], 10, 20)['known_value']);
    }

    public function test_return_cancels_unpriced_negative_sale_and_reallocates_returned_stock_to_other_shortage(): void
    {
        $state = FinanceCostAllocation::receive(FinanceCostAllocation::empty(), 'arrival:1', 10, 20, '10', '100.00')['state'];
        $state = FinanceCostAllocation::issue($state, 10, 20, '20', 'sale', 'delivery:1')['state'];
        $state = FinanceCostAllocation::issue($state, 10, 20, '5', 'sale', 'delivery:2')['state'];
        $restored = FinanceCostAllocation::restore($state, 10, 20, '20', 'sale', 'delivery:1');
        self::assertTrue($restored['pending']); self::assertNull($restored['cost']);
        self::assertSame('100.000000', $restored['known_cost']);
        $balance = FinanceCostAllocation::balance($restored['state'], 10, 20);
        self::assertSame('5.000000000000', $balance['quantity']); self::assertSame('50.000000', $balance['value']);
        self::assertFalse($balance['pending']);
        $adjusted = FinanceCostAllocation::adjust($restored['state'], 'arrival:1', '120.00');
        self::assertSame('10.000000', $adjusted['changes']['inventory']); self::assertSame('10.000000', $adjusted['changes']['sale']);
        $other = FinanceCostAllocation::restore($adjusted['state'], 10, 20, '5', 'sale', 'delivery:2');
        self::assertSame('60.000000', $other['cost']);
        self::assertSame('120.000000', FinanceCostAllocation::balance($other['state'], 10, 20)['value']);
    }

    public function test_return_to_another_actual_warehouse_does_not_fill_unrelated_shortage_in_original_warehouse(): void
    {
        $state = FinanceCostAllocation::receive(FinanceCostAllocation::empty(), 'arrival:1', 10, 20, '10', '100.00')['state'];
        $state = FinanceCostAllocation::issue($state, 10, 20, '10', 'sale', 'sale:1')['state'];
        $state = FinanceCostAllocation::issue($state, 10, 20, '5', 'sale', 'sale:2')['state'];
        $returned = FinanceCostAllocation::restore($state, 10, 20, '10', 'sale', 'sale:1', 11);
        self::assertSame('100.000000', $returned['cost']);
        self::assertSame('-5.000000000000', FinanceCostAllocation::balance($returned['state'], 10, 20)['quantity']);
        self::assertTrue(FinanceCostAllocation::balance($returned['state'], 10, 20)['pending']);
        self::assertSame('10.000000000000', FinanceCostAllocation::balance($returned['state'], 11, 20)['quantity']);
        self::assertSame('100.000000', FinanceCostAllocation::balance($returned['state'], 11, 20)['value']);
    }

    public function test_unpriced_negative_sale_can_return_to_another_warehouse_and_receive_cost_at_its_later_destinations(): void
    {
        $state = FinanceCostAllocation::issue(FinanceCostAllocation::empty(), 10, 20, '10', 'sale', 'sale:1')['state'];
        $returned = FinanceCostAllocation::restore($state, 10, 20, '4', 'sale', 'sale:1', 11);
        self::assertNull($returned['cost']); self::assertSame('-10.000000000000', FinanceCostAllocation::balance($returned['state'], 10, 20)['quantity']);
        self::assertSame('4.000000000000', FinanceCostAllocation::balance($returned['state'], 11, 20)['quantity']); self::assertNull(FinanceCostAllocation::balance($returned['state'], 11, 20)['value']);
        $resold = FinanceCostAllocation::issue($returned['state'], 11, 20, '2', 'sale', 'sale:2'); self::assertNull($resold['cost']);
        $received = FinanceCostAllocation::receive($resold['state'], 'arrival:1', 10, 20, '10', '100.00');
        self::assertSame('0.000000000000', FinanceCostAllocation::balance($received['state'], 10, 20)['quantity']);
        self::assertSame('20.000000', FinanceCostAllocation::balance($received['state'], 11, 20)['value']);
        self::assertSame('60.000000', FinanceCostAllocation::restore($received['state'], 10, 20, '6', 'sale', 'sale:1')['cost']);
        self::assertSame('20.000000', FinanceCostAllocation::restore($received['state'], 11, 20, '2', 'sale', 'sale:2')['cost']);
        $adjusted = FinanceCostAllocation::adjust($received['state'], 'arrival:1', '200.00');
        self::assertSame('80.000000', $adjusted['changes']['sale']); self::assertSame('20.000000', $adjusted['changes']['inventory']);
    }

    public function test_unpriced_return_can_move_back_to_original_shortage_warehouse_without_inventing_an_inbound_cost(): void
    {
        $state = FinanceCostAllocation::issue(FinanceCostAllocation::empty(), 10, 20, '10', 'sale', 'sale:1')['state'];
        $state = FinanceCostAllocation::restore($state, 10, 20, '4', 'sale', 'sale:1', 11)['state'];
        $moved = FinanceCostAllocation::transfer($state, 11, 10, 20, '2', 'move-back');
        self::assertNull($moved['cost']); self::assertSame('-8.000000000000', FinanceCostAllocation::balance($moved['state'], 10, 20)['quantity']);
        self::assertSame('2.000000000000', FinanceCostAllocation::balance($moved['state'], 11, 20)['quantity']);
        $received = FinanceCostAllocation::receive($moved['state'], 'arrival:1', 10, 20, '8', '80.00');
        self::assertSame('0.000000000000', FinanceCostAllocation::balance($received['state'], 10, 20)['quantity']);
        self::assertSame('20.000000', FinanceCostAllocation::balance($received['state'], 11, 20)['value']);
        self::assertSame('60.000000', FinanceCostAllocation::restore($received['state'], 10, 20, '6', 'sale', 'sale:1')['cost']);
    }

    public function test_transfer_preserves_total_value_and_origin_then_cost_adjustment_follows_sale_loss_and_return(): void
    {
        $state = FinanceCostAllocation::receive(FinanceCostAllocation::empty(), 'arrival:1', 10, 20, '100', '1000.00')['state'];
        $state = FinanceCostAllocation::transfer($state, 10, 11, 20, '40', 'transfer:1')['state'];
        $state = FinanceCostAllocation::issue($state, 11, 20, '10', 'sale', 'delivery:2')['state'];
        $state = FinanceCostAllocation::issue($state, 10, 20, '20', 'loss', 'loss:1')['state'];
        $state = FinanceCostAllocation::issue($state, 10, 20, '10', 'return', 'return:1')['state'];
        $adjusted = FinanceCostAllocation::adjust($state, 'arrival:1', '1100.00');
        self::assertSame(['inventory' => '60.000000', 'sale' => '10.000000', 'loss' => '20.000000', 'return' => '10.000000'], $adjusted['changes']);
        self::assertSame('330.000000', FinanceCostAllocation::balance($adjusted['state'], 11, 20)['known_value']);
        self::assertSame('330.000000', FinanceCostAllocation::balance($adjusted['state'], 10, 20)['known_value']);
        $lowered = FinanceCostAllocation::adjust($adjusted['state'], 'arrival:1', '900.00');
        self::assertSame('-20.000000', $lowered['changes']['sale']); self::assertSame('-120.000000', $lowered['changes']['inventory']);
    }

    public function test_unknown_cost_never_appears_as_zero_and_resolution_updates_already_sold_part(): void
    {
        $state = FinanceCostAllocation::receive(FinanceCostAllocation::empty(), 'arrival:1', 10, 20, '100', null)['state'];
        $issued = FinanceCostAllocation::issue($state, 10, 20, '25', 'sale', 'delivery:1');
        self::assertTrue($issued['pending']); self::assertNull($issued['cost']);
        $balance = FinanceCostAllocation::balance($issued['state'], 10, 20); self::assertNull($balance['value']); self::assertSame('75.000000000000', $balance['pending_quantity']);
        $resolved = FinanceCostAllocation::adjust($issued['state'], 'arrival:1', '123.45');
        self::assertSame('30.862500', $resolved['changes']['sale']); self::assertSame('92.587500', $resolved['changes']['inventory']);
        self::assertFalse(FinanceCostAllocation::balance($resolved['state'], 10, 20)['pending']);
    }

    public function test_transfer_into_negative_destination_warehouse_supplies_its_original_sale_without_new_cost(): void
    {
        $state = FinanceCostAllocation::receive(FinanceCostAllocation::empty(), 'arrival:1', 10, 20, '100', '1000.00')['state'];
        $state = FinanceCostAllocation::issue($state, 11, 20, '15', 'sale', 'delivery:1')['state'];
        $transferred = FinanceCostAllocation::transfer($state, 10, 11, 20, '40', 'transfer:1');
        self::assertSame('400.000000', $transferred['cost']);
        $balance = FinanceCostAllocation::balance($transferred['state'], 11, 20);
        self::assertFalse($balance['pending']); self::assertSame('25.000000000000', $balance['quantity']); self::assertSame('250.000000', $balance['value']);
        $adjusted = FinanceCostAllocation::adjust($transferred['state'], 'arrival:1', '1100.00');
        self::assertSame('15.000000', $adjusted['changes']['sale']); self::assertSame('85.000000', $adjusted['changes']['inventory']);
    }

    public function test_negative_inventory_remains_unresolved_until_inbound_cost_is_known_and_offsets_original_destination(): void
    {
        $issued = FinanceCostAllocation::issue(FinanceCostAllocation::empty(), 10, 20, '30', 'sale', 'delivery:1');
        self::assertTrue($issued['pending']); self::assertNull($issued['cost']);
        $received = FinanceCostAllocation::receive($issued['state'], 'arrival:1', 10, 20, '100', '1000.00');
        self::assertSame('300.000000', $received['offsets'][0]['value']); self::assertSame('delivery:1', $received['offsets'][0]['reference']);
        $balance = FinanceCostAllocation::balance($received['state'], 10, 20);
        self::assertSame('70.000000000000', $balance['quantity']); self::assertSame('700.000000', $balance['value']); self::assertFalse($balance['pending']);
        self::assertSame('30.000000', FinanceCostAllocation::adjust($received['state'], 'arrival:1', '1100.00')['changes']['sale']);
    }

    public function test_repeated_fractional_issues_conserve_quantity_and_value_without_losing_rounding_tail(): void
    {
        $state = FinanceCostAllocation::receive(FinanceCostAllocation::empty(), 'arrival:1', 10, 20, '1', '0.01')['state'];
        $state = FinanceCostAllocation::receive($state, 'arrival:2', 10, 20, '2', '0.02')['state'];
        $total = '0.000000';
        for ($i = 0; $i < 30; $i++) { $result = FinanceCostAllocation::issue($state, 10, 20, '0.1', 'sale', 'delivery:' . $i); $state = $result['state']; $total = bcadd($total, $result['known_cost'], 6); }
        $balance = FinanceCostAllocation::balance($state, 10, 20); self::assertSame('0.000000000000', $balance['quantity']); self::assertSame('0.000000', $balance['value']); self::assertSame('0.030000', $total);
    }
}
