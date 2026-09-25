<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CustomerReportWeightRequirementContractTest extends TestCase
{
    public function test_weight_requirement_migration_contains_required_fields(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260922_000001_customer_report_weight_requirements.sql');
        foreach ([
            'acceptable_base_qty_min', 'acceptable_base_qty_max', 'specification_verification_status',
            'verified_piece_count', 'verified_piece_weight_min', 'verified_piece_weight_max',
        ] as $field) {
            self::assertStringContainsString($field, $sql);
        }
        self::assertStringContainsString('{{prefix}}customer_report_item', $sql);
    }

    public function test_legacy_specification_shortage_endpoint_is_not_exposed(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertStringNotContainsString(
            "Route::post('jxc/tasks/specification_shortage', 'jxc.FulfillmentTask/specificationShortage')",
            (string)file_get_contents($root . '/app/api/route/jxc.php')
        );
        $logic = (string)file_get_contents($root . '/app/api/jxc/logic/FulfillmentTaskLogic.php');
        self::assertStringContainsString("\$specificationShortagePrefix . ':' . \$round", $logic);
        self::assertStringContainsString("where('id', (int)\$copy['id'])->update", $logic);
        self::assertStringContainsString("'paper_status' => 'void_required'", $logic);
        self::assertStringContainsString("Db::name('fulfillment_ticket_control')->insert", $logic);
    }
}
