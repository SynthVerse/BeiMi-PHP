<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerDeliveryVehicleLogic;
use app\api\jxc\logic\CustomerLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class CustomerDeliveryVehicleTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->ensureCustomerDeliveryVehicleTable();
        $this->cleanCustomerReportData();
    }

    protected function tearDown(): void
    {
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_candidate_vehicle_can_be_saved_and_returned_from_customer_detail(): void
    {
        $customerId = $this->createCustomer('海鲜城东门店');

        $vehicle = CustomerDeliveryVehicleLogic::save([
            'customer_id' => $customerId,
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '海鲜市场东门停车区',
            'driver_phone' => '13800000001',
        ]);

        self::assertNotFalse($vehicle, CustomerDeliveryVehicleLogic::getError());
        self::assertSame($customerId, (int)$vehicle['customer_id']);
        self::assertSame('05:30', $vehicle['earliest_delivery_time']);
        self::assertSame('粤A12345', $vehicle['plate_number']);
        self::assertSame('海鲜市场东门停车区', $vehicle['vehicle_location']);
        self::assertSame('13800000001', $vehicle['driver_phone']);
        self::assertSame(1, (int)$vehicle['version']);

        $detail = CustomerLogic::detail(['id' => $customerId]);
        self::assertCount(1, $detail['delivery_vehicles']);
        self::assertSame((int)$vehicle['id'], (int)$detail['delivery_vehicles'][0]['id']);
    }

    public function test_same_plate_can_be_used_by_different_customers_but_not_duplicated_within_one_customer(): void
    {
        $firstCustomerId = $this->createCustomer('海鲜城东门店');
        $secondCustomerId = $this->createCustomer('海鲜城西门店');
        $params = [
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '市场停车区',
        ];

        self::assertNotFalse(CustomerDeliveryVehicleLogic::save($params + ['customer_id' => $firstCustomerId]));
        self::assertFalse(CustomerDeliveryVehicleLogic::save($params + ['customer_id' => $firstCustomerId]));
        self::assertStringContainsString('相同车牌', CustomerDeliveryVehicleLogic::getError());
        self::assertNotFalse(CustomerDeliveryVehicleLogic::save($params + ['customer_id' => $secondCustomerId]));
    }

    public function test_stale_version_cannot_overwrite_or_delete_a_candidate_vehicle(): void
    {
        $customerId = $this->createCustomer('海鲜城东门店');
        $vehicle = CustomerDeliveryVehicleLogic::save([
            'customer_id' => $customerId,
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '市场停车区',
        ]);
        self::assertNotFalse($vehicle);

        $updated = CustomerDeliveryVehicleLogic::save([
            ...$vehicle,
            'vehicle_location' => '市场南门停车区',
        ]);
        self::assertNotFalse($updated);
        self::assertSame(2, (int)$updated['version']);

        self::assertFalse(CustomerDeliveryVehicleLogic::save([
            ...$vehicle,
            'vehicle_location' => '过期页面覆盖值',
        ]));
        self::assertStringContainsString('已被修改', CustomerDeliveryVehicleLogic::getError());
        self::assertFalse(CustomerDeliveryVehicleLogic::delete([
            'id' => $vehicle['id'],
            'version' => $vehicle['version'],
        ]));
        self::assertStringContainsString('已被修改', CustomerDeliveryVehicleLogic::getError());
    }

    public function test_disabled_vehicle_is_excluded_from_enabled_only_list(): void
    {
        $customerId = $this->createCustomer('海鲜城东门店');
        $vehicle = CustomerDeliveryVehicleLogic::save([
            'customer_id' => $customerId,
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '市场停车区',
            'is_enabled' => 0,
        ]);

        self::assertNotFalse($vehicle);
        self::assertCount(1, CustomerDeliveryVehicleLogic::lists(['customer_id' => $customerId]));
        self::assertSame([], CustomerDeliveryVehicleLogic::lists([
            'customer_id' => $customerId,
            'enabled_only' => 1,
        ]));
    }

    public function test_candidate_vehicles_are_isolated_by_tenant(): void
    {
        $customerId = $this->createCustomer('海鲜城东门店');
        $vehicle = CustomerDeliveryVehicleLogic::save([
            'customer_id' => $customerId,
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '市场停车区',
        ]);
        self::assertNotFalse($vehicle);

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertSame([], CustomerDeliveryVehicleLogic::lists(['customer_id' => $customerId]));
        self::assertFalse(CustomerDeliveryVehicleLogic::save([
            ...$vehicle,
            'vehicle_location' => '越权修改',
        ]));
        self::assertSame('客户不存在', CustomerDeliveryVehicleLogic::getError());
        $this->prepareCustomerReportRequestContext();
    }

    private function ensureCustomerDeliveryVehicleTable(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `la_customer_delivery_vehicle` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `customer_id` int unsigned NOT NULL DEFAULT 0,
  `earliest_delivery_time` char(5) NOT NULL DEFAULT '',
  `plate_number` varchar(32) NOT NULL DEFAULT '',
  `vehicle_location` varchar(255) NOT NULL DEFAULT '',
  `driver_phone` varchar(20) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `is_enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `operator_id` int unsigned NOT NULL DEFAULT 0,
  `version` int unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer_delivery_vehicle_customer` (`tenant_id`,`customer_id`,`is_enabled`,`sort`,`earliest_delivery_time`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }
}
