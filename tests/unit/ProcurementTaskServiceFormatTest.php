<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\ProcurementTaskService;
use PHPUnit\Framework\TestCase;

final class ProcurementTaskServiceFormatTest extends TestCase
{
    /**
     * @dataProvider actionableStatusProvider
     */
    public function test_format_exposes_expected_actions_for_open_statuses(string $status, array $expectedActions): void
    {
        $formatted = ProcurementTaskService::format([
            'id' => 1,
            'source_type' => 'sales_reservation',
            'source_key' => 'reservation_item:10',
            'source_reservation_id' => 20,
            'source_reservation_item_id' => 10,
            'status' => $status,
        ]);

        self::assertSame($expectedActions, $formatted['actions']);
    }

    public static function actionableStatusProvider(): array
    {
        return [
            'pending' => ['pending', [
                'can_start' => true,
                'can_close' => true,
                'can_cancel' => true,
                'can_view_source' => true,
            ]],
            'purchasing' => ['purchasing', [
                'can_start' => false,
                'can_close' => true,
                'can_cancel' => false,
                'can_view_source' => true,
            ]],
            'partial_arrived' => ['partial_arrived', [
                'can_start' => false,
                'can_close' => true,
                'can_cancel' => false,
                'can_view_source' => true,
            ]],
        ];
    }

    public function test_format_hides_mutation_actions_for_terminal_statuses(): void
    {
        foreach (['fulfilled', 'closed', 'cancelled'] as $status) {
            $formatted = ProcurementTaskService::format([
                'id' => 1,
                'source_type' => 'sales_reservation',
                'source_key' => 'reservation_item:10',
                'source_reservation_id' => 20,
                'source_reservation_item_id' => 10,
                'status' => $status,
            ]);

            self::assertSame(['can_view_source' => true], $formatted['actions']);
        }
    }

    public function test_format_marks_manual_task_as_no_viewable_source(): void
    {
        $formatted = ProcurementTaskService::format([
            'id' => 1,
            'source_type' => 'manual',
            'source_key' => 'manual:test',
            'status' => 'pending',
        ]);

        self::assertSame([
            'can_start' => true,
            'can_close' => true,
            'can_cancel' => true,
            'can_view_source' => false,
        ], $formatted['actions']);
    }
}
