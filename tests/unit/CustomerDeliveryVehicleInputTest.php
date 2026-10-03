<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerDeliveryVehicleLogic;
use app\api\jxc\validate\CustomerValidate;
use PHPUnit\Framework\TestCase;

final class CustomerDeliveryVehicleInputTest extends TestCase
{
    public function test_independent_vehicle_create_requires_only_plate_and_valid_optional_phone(): void
    {
        self::assertTrue((new CustomerValidate())->scene('deliveryVehicleCreate')->check(['plate_number' => '粤A12345']));
        self::assertFalse((new CustomerValidate())->scene('deliveryVehicleCreate')->check([]));
        self::assertFalse(CustomerDeliveryVehicleLogic::createVehicle(['plate_number' => ' ']));
        self::assertSame('请输入车辆车牌号', CustomerDeliveryVehicleLogic::getError());
        self::assertFalse(CustomerDeliveryVehicleLogic::createVehicle(['plate_number' => '粤A12345', 'driver_phone' => '123']));
        self::assertSame('请输入正确的司机电话', CustomerDeliveryVehicleLogic::getError());
    }

    public function test_http_save_accepts_new_binding_without_ids_but_rejects_zero_identity_fields(): void
    {
        $payload = [
            'customer_id' => 42,
            'earliest_delivery_time' => '05:00',
            'plate_number' => '粤A12345',
            'vehicle_location' => '东门停车区',
            'driver_phone' => '',
        ];
        self::assertTrue((new CustomerValidate())->scene('deliveryVehicleSave')->check($payload));
        self::assertTrue((new CustomerValidate())->scene('deliveryVehicleSave')->check(
            $payload + ['vehicle_id' => 7, 'vehicle_version' => 2]
        ));
        foreach (['id', 'version', 'vehicle_id', 'vehicle_version'] as $field) {
            self::assertFalse((new CustomerValidate())->scene('deliveryVehicleSave')->check(
                $payload + [$field => 0]
            ), $field);
        }
    }

    public function test_candidate_vehicle_input_is_normalized_at_the_public_logic_boundary(): void
    {
        $normalized = CustomerDeliveryVehicleLogic::normalizeInput([
            'customer_id' => '42',
            'vehicle_id' => '7',
            'version' => '3',
            'vehicle_version' => '5',
            'earliest_delivery_time' => ' 05:30 ',
            'plate_number' => ' 粤a12345 ',
            'vehicle_location' => ' 海鲜市场东门停车区 ',
            'driver_phone' => ' 138 0000 0001 ',
            'sort' => '8',
            'is_enabled' => '1',
            'vehicle_is_enabled' => '1',
        ]);

        self::assertSame([
            'customer_id' => 42,
            'vehicle_id' => 7,
            'version' => 3,
            'vehicle_version' => 5,
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '海鲜市场东门停车区',
            'driver_phone' => '138 0000 0001',
            'sort' => 8,
            'is_enabled' => 1,
            'vehicle_is_enabled' => 1,
        ], $normalized);
    }

    public function test_candidate_vehicle_rejects_an_invalid_earliest_delivery_time(): void
    {
        $validated = CustomerDeliveryVehicleLogic::validateInput([
            'customer_id' => 42,
            'earliest_delivery_time' => '24:00',
            'plate_number' => '粤A12345',
            'vehicle_location' => '海鲜市场东门停车区',
        ]);

        self::assertFalse($validated);
        self::assertSame('最早送货时间格式应为 HH:MM', CustomerDeliveryVehicleLogic::getError());
    }

    public function test_missing_master_status_is_kept_as_unspecified_for_legacy_clients(): void
    {
        $normalized = CustomerDeliveryVehicleLogic::normalizeInput([
            'customer_id' => 42,
            'is_enabled' => 0,
        ]);

        self::assertSame(0, $normalized['is_enabled']);
        self::assertSame(-1, $normalized['vehicle_is_enabled']);
    }

    /**
     * @dataProvider requiredVehicleFieldProvider
     */
    public function test_candidate_vehicle_requires_customer_plate_and_location(string $field, mixed $value, string $message): void
    {
        $payload = [
            'customer_id' => 42,
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '海鲜市场东门停车区',
        ];
        $payload[$field] = $value;

        self::assertFalse(CustomerDeliveryVehicleLogic::validateInput($payload));
        self::assertSame($message, CustomerDeliveryVehicleLogic::getError());
    }

    public static function requiredVehicleFieldProvider(): array
    {
        return [
            'customer' => ['customer_id', 0, '客户不存在'],
            'plate' => ['plate_number', ' ', '请输入车辆车牌号'],
            'location' => ['vehicle_location', ' ', '请输入车辆地点'],
        ];
    }
}
