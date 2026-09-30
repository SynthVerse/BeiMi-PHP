<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerDeliveryVehicleLogic;
use PHPUnit\Framework\TestCase;

final class CustomerDeliveryVehicleInputTest extends TestCase
{
    public function test_candidate_vehicle_input_is_normalized_at_the_public_logic_boundary(): void
    {
        $normalized = CustomerDeliveryVehicleLogic::normalizeInput([
            'customer_id' => '42',
            'version' => '3',
            'earliest_delivery_time' => ' 05:30 ',
            'plate_number' => ' 粤a12345 ',
            'vehicle_location' => ' 海鲜市场东门停车区 ',
            'driver_phone' => ' 138 0000 0001 ',
            'sort' => '8',
            'is_enabled' => '1',
        ]);

        self::assertSame([
            'customer_id' => 42,
            'version' => 3,
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '海鲜市场东门停车区',
            'driver_phone' => '138 0000 0001',
            'sort' => 8,
            'is_enabled' => 1,
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
