<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CustomerDeliveryVehicleContractTest extends TestCase
{
    public function test_customer_delivery_vehicle_api_and_customer_detail_contract_are_exposed(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string)file_get_contents($root . '/app/api/route/jxc.php');
        $controller = (string)file_get_contents($root . '/app/api/jxc/controller/CustomerController.php');
        $customerLogic = (string)file_get_contents($root . '/app/api/jxc/logic/CustomerLogic.php');
        $vehicleLogic = (string)file_get_contents($root . '/app/api/jxc/logic/CustomerDeliveryVehicleLogic.php');

        foreach ([
            "Route::get('customer/deliveryVehicleIndex', 'jxc.Customer/deliveryVehicleIndex');",
            "Route::get('customer/deliveryVehicles', 'jxc.Customer/deliveryVehicles');",
            "Route::post('customer/deliveryVehicleSave', 'jxc.Customer/deliveryVehicleSave');",
            "Route::post('customer/deliveryVehicleDelete', 'jxc.Customer/deliveryVehicleDelete');",
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        foreach (['deliveryVehicleIndex', 'deliveryVehicles', 'deliveryVehicleSave', 'deliveryVehicleDelete'] as $method) {
            self::assertStringContainsString('public function ' . $method . '()', $controller);
        }
        self::assertStringContainsString("'delivery_vehicles' => CustomerDeliveryVehicleLogic::lists", $customerLogic);
        self::assertStringContainsString("where('version'", $vehicleLogic);
        self::assertStringContainsString('客户候选车辆已被修改，请重新加载', $vehicleLogic);
        self::assertStringContainsString(
            "order(['sort' => 'asc', 'earliest_delivery_time' => 'asc', 'id' => 'asc'])",
            $vehicleLogic
        );
    }

    public function test_customer_delivery_vehicle_migration_keeps_tenant_customer_and_schedule_fields(): void
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20260930_000001_create_customer_delivery_vehicle.sql'
        );

        foreach ([
            '{{prefix}}customer_delivery_vehicle',
            '`tenant_id`',
            '`customer_id`',
            '`earliest_delivery_time`',
            '`plate_number`',
            '`vehicle_location`',
            '`driver_phone`',
            '`is_enabled`',
            '`version`',
            '`delete_time`',
        ] as $field) {
            self::assertStringContainsString($field, $sql);
        }
    }
}
