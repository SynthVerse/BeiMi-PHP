<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class LineVehicleRetirementContractTest extends TestCase
{
    public function test_public_schedule_and_trip_creation_endpoints_are_explicitly_retired(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/controller/LineVehicleController.php');
        $scheduleSave = self::methodSource($controller, 'scheduleSave', 'schedules');
        $tripCreate = self::methodSource($controller, 'tripCreate', 'trips');

        foreach ([$scheduleSave, $tripCreate] as $source) {
            self::assertStringContainsString('legacyCreationRetired()', $source);
            self::assertStringNotContainsString('LineVehicleValidate', $source);
            self::assertStringNotContainsString('LineVehicleLogic', $source);
        }
        self::assertStringContainsString("'retirement_marker' => 'LEGACY_LINE_VEHICLE_RETIRED'", $controller);
        self::assertStringContainsString("'msg' => '旧固定线车调度已退役，不能新建或修改班次和送站趟次'", $controller);
        self::assertStringContainsString('], 410);', $controller);
    }

    public function test_legacy_read_and_completion_routes_are_preserved_as_the_only_public_workflow(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/route/jxc.php');
        foreach ([
            'schedules', 'schedule_save', 'trips', 'trip_create', 'trip_detail',
            'package_record', 'trip_depart', 'reroute', 'return_pending',
            'handoff_confirm', 'loading_manifest',
        ] as $route) {
            self::assertStringContainsString("jxc/line_vehicle/{$route}", $routes);
        }
    }

    public function test_reroute_whitelist_excludes_new_fixed_line_assignments(): void
    {
        $validator = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/validate/LineVehicleValidate.php');
        $logic = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/logic/LineVehicleLogic.php');

        self::assertStringContainsString('customer_vehicle,third_party,self_delivery', $validator);
        self::assertStringNotContainsString('in:fixed_line_vehicle,third_party,self_delivery', $validator);
        self::assertStringContainsString("['customer_vehicle', 'third_party', 'self_delivery']", $logic);
    }

    private static function methodSource(string $source, string $method, string $nextMethod): string
    {
        $start = strpos($source, "public function {$method}");
        $end = strpos($source, "public function {$nextMethod}", (int)$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        return substr($source, (int)$start, (int)$end - (int)$start);
    }
}
