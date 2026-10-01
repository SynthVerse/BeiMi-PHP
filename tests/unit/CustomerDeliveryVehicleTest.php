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
        $this->cleanCrossTenantVehicleFixtures();
        $this->cleanCustomerReportData();
    }

    protected function tearDown(): void
    {
        $this->cleanCrossTenantVehicleFixtures();
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

    public function test_same_plate_is_one_vehicle_bound_to_multiple_customers(): void
    {
        $firstCustomerId = $this->createCustomer('海鲜城东门店');
        $secondCustomerId = $this->createCustomer('海鲜城西门店');
        $params = [
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '市场停车区',
        ];

        $first = CustomerDeliveryVehicleLogic::save($params + ['customer_id' => $firstCustomerId]);
        self::assertNotFalse($first);
        self::assertFalse(CustomerDeliveryVehicleLogic::save($params + ['customer_id' => $firstCustomerId]));
        self::assertStringContainsString('已绑定', CustomerDeliveryVehicleLogic::getError());
        $second = CustomerDeliveryVehicleLogic::save($params + ['customer_id' => $secondCustomerId]);
        self::assertNotFalse($second);
        self::assertSame((int)$first['vehicle_id'], (int)$second['vehicle_id']);

        $detail = CustomerDeliveryVehicleLogic::vehicleCustomers(['id' => $first['vehicle_id']]);
        self::assertNotFalse($detail);
        self::assertSame('粤A12345', $detail['vehicle']['plate_number']);
        self::assertCount(2, $detail['customers']);
        self::assertSame(
            [$firstCustomerId, $secondCustomerId],
            array_column($detail['customers'], 'customer_id')
        );
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
            'vehicle_is_enabled' => 0,
        ]);

        self::assertNotFalse($vehicle);
        self::assertCount(1, CustomerDeliveryVehicleLogic::lists(['customer_id' => $customerId]));
        self::assertSame([], CustomerDeliveryVehicleLogic::lists([
            'customer_id' => $customerId,
            'enabled_only' => 1,
        ]));
    }

    public function test_candidate_vehicles_are_sorted_by_maintained_order_before_delivery_time(): void
    {
        $customerId = $this->createCustomer('海鲜城东门店');
        foreach ([
            ['plate_number' => '粤A00002', 'earliest_delivery_time' => '04:30', 'sort' => 20],
            ['plate_number' => '粤A00001', 'earliest_delivery_time' => '06:30', 'sort' => 10],
        ] as $row) {
            self::assertNotFalse(CustomerDeliveryVehicleLogic::save([
                'customer_id' => $customerId,
                'vehicle_location' => '市场停车区',
                ...$row,
            ]));
        }

        self::assertSame(
            ['粤A00001', '粤A00002'],
            array_column(CustomerDeliveryVehicleLogic::lists(['customer_id' => $customerId]), 'plate_number')
        );
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

    public function test_cross_customer_vehicle_list_is_searchable_paginated_and_tenant_scoped(): void
    {
        $eastCustomerId = $this->createCustomer('海鲜城东门店');
        $westCustomerId = $this->createCustomer('海鲜城西门店');
        foreach ([
            [$eastCustomerId, '粤A00001', '05:30', 1],
            [$westCustomerId, '粤A00002', '04:30', 1],
            [$eastCustomerId, '粤A00003', '03:30', 0],
        ] as [$customerId, $plateNumber, $time, $enabled]) {
            self::assertNotFalse(CustomerDeliveryVehicleLogic::save([
                'customer_id' => $customerId,
                'earliest_delivery_time' => $time,
                'plate_number' => $plateNumber,
                'vehicle_location' => $customerId === $eastCustomerId ? '东门停车区' : '西门停车区',
                'is_enabled' => $enabled,
                'vehicle_is_enabled' => $enabled,
            ]));
        }

        $firstPage = CustomerDeliveryVehicleLogic::crossCustomerLists(['page' => 1, 'pagesize' => 2]);
        self::assertSame(3, $firstPage['total']);
        self::assertCount(2, $firstPage['data']);
        self::assertSame(['粤A00002', '粤A00001'], array_column($firstPage['data'], 'plate_number'));
        self::assertSame(['海鲜城西门店', '海鲜城东门店'], array_column($firstPage['data'], 'customer_name'));

        $secondPage = CustomerDeliveryVehicleLogic::crossCustomerLists(['page' => 2, 'pagesize' => 2]);
        self::assertSame(['粤A00003'], array_column($secondPage['data'], 'plate_number'));
        $disabled = CustomerDeliveryVehicleLogic::crossCustomerLists(['status' => 'disabled']);
        self::assertSame(1, $disabled['total']);
        self::assertSame('粤A00003', $disabled['data'][0]['plate_number']);
        $search = CustomerDeliveryVehicleLogic::crossCustomerLists(['keyword' => '西门']);
        self::assertSame(1, $search['total']);
        self::assertSame($westCustomerId, (int)$search['data'][0]['customer_id']);

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        $otherCustomerId = (int)Db::name('customer')->insertGetId([
            'tenant_id' => self::OTHER_TENANT_ID,
            'customer_name' => '外部租户客户',
            'parent_id' => 0,
            'phone' => '',
            'address' => '',
            'is_disabled' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        self::assertNotFalse(CustomerDeliveryVehicleLogic::save([
            'customer_id' => $otherCustomerId,
            'earliest_delivery_time' => '06:00',
            'plate_number' => '粤B00001',
            'vehicle_location' => '外部停车区',
        ]));
        $otherTenantList = CustomerDeliveryVehicleLogic::crossCustomerLists([]);
        self::assertSame(1, $otherTenantList['total']);
        self::assertSame(['粤B00001'], array_column($otherTenantList['data'], 'plate_number'));
        $this->prepareCustomerReportRequestContext();
    }

    public function test_binding_edit_cannot_change_shared_vehicle_master_and_legacy_disable_only_changes_binding(): void
    {
        $firstCustomerId = $this->createCustomer('共享车辆客户甲');
        $secondCustomerId = $this->createCustomer('共享车辆客户乙');
        $first = CustomerDeliveryVehicleLogic::save([
            'customer_id' => $firstCustomerId,
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A88888',
            'vehicle_location' => '东门停车区',
            'driver_phone' => '13800000001',
        ]);
        self::assertNotFalse($first);
        $second = CustomerDeliveryVehicleLogic::save([
            'customer_id' => $secondCustomerId,
            'vehicle_id' => $first['vehicle_id'],
            'earliest_delivery_time' => '06:00',
            'plate_number' => '粤A88888',
            'vehicle_location' => '西门停车区',
            'driver_phone' => '13800000001',
        ]);
        self::assertNotFalse($second);

        $updated = CustomerDeliveryVehicleLogic::save([
            'id' => $second['id'],
            'version' => $second['version'],
            'customer_id' => $secondCustomerId,
            'vehicle_id' => $first['vehicle_id'],
            'earliest_delivery_time' => '06:10',
            'plate_number' => '粤B99999',
            'vehicle_location' => '西门新停车区',
            'driver_phone' => '13999999999',
            'is_enabled' => 0,
        ]);
        self::assertNotFalse($updated, CustomerDeliveryVehicleLogic::getError());
        self::assertSame('粤A88888', $updated['plate_number']);
        self::assertSame('13800000001', $updated['driver_phone']);
        self::assertSame(0, (int)$updated['is_enabled']);
        self::assertSame(1, (int)$updated['vehicle_is_enabled']);

        $master = Db::name('delivery_vehicle')->where('id', $first['vehicle_id'])->find();
        self::assertSame('粤A88888', $master['plate_number']);
        self::assertSame('13800000001', $master['driver_phone']);
        self::assertSame(1, (int)$master['is_enabled']);
        self::assertSame(1, (int)CustomerDeliveryVehicleLogic::lists([
            'customer_id' => $firstCustomerId,
        ])[0]['is_enabled']);
    }

    public function test_migration_keeps_conflicting_legacy_phones_on_bindings_and_records_conflict(): void
    {
        $firstCustomerId = $this->createCustomer('迁移客户甲');
        $secondCustomerId = $this->createCustomer('迁移客户乙');
        $now = time();
        foreach ([
            [$firstCustomerId, '13800000001'],
            [$secondCustomerId, '13900000002'],
        ] as [$customerId, $phone]) {
            Db::name('customer_delivery_vehicle')->insert([
                'tenant_id' => self::TENANT_ID,
                'vehicle_id' => 0,
                'customer_id' => $customerId,
                'earliest_delivery_time' => '05:30',
                'plate_number' => '粤Z99999',
                'vehicle_location' => '历史停车区',
                'driver_phone' => $phone,
                'sort' => 0,
                'is_enabled' => 1,
                'operator_id' => 0,
                'version' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        $migration = (string)file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20261001_000001_split_delivery_vehicle_binding.sql'
        );
        $this->runStatements($this->prepareMigration($migration));

        $master = Db::name('delivery_vehicle')
            ->where('tenant_id', self::TENANT_ID)
            ->where('plate_number', '粤Z99999')
            ->find();
        self::assertNotEmpty($master);
        self::assertSame('', $master['driver_phone']);
        self::assertSame(2, (int)Db::name('customer_delivery_vehicle')
            ->where('tenant_id', self::TENANT_ID)
            ->where('vehicle_id', $master['id'])
            ->count());
        $conflict = Db::name('delivery_vehicle_migration_conflict')
            ->where('tenant_id', self::TENANT_ID)
            ->where('plate_number', '粤Z99999')
            ->where('conflict_type', 'driver_phone')
            ->find();
        self::assertNotEmpty($conflict);
        self::assertSame('13800000001 | 13900000002', $conflict['values_snapshot']);
        self::assertSame('13800000001', CustomerDeliveryVehicleLogic::lists([
            'customer_id' => $firstCustomerId,
        ])[0]['driver_phone']);
        self::assertSame('13900000002', CustomerDeliveryVehicleLogic::lists([
            'customer_id' => $secondCustomerId,
        ])[0]['driver_phone']);
    }

    private function ensureCustomerDeliveryVehicleTable(): void
    {
        $migration = (string)file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20260930_000001_create_customer_delivery_vehicle.sql'
        );
        Db::execute($this->authoritativeCreateTable($migration, 'customer_delivery_vehicle'));

        $upgrade = (string)file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20261001_000001_split_delivery_vehicle_binding.sql'
        );
        $this->runStatements($this->prepareMigration($upgrade));
    }

    private function cleanCrossTenantVehicleFixtures(): void
    {
        Db::name('customer_delivery_vehicle')
            ->where('tenant_id', self::OTHER_TENANT_ID)
            ->where('plate_number', '粤B00001')
            ->delete();
        Db::name('delivery_vehicle')
            ->where('tenant_id', self::OTHER_TENANT_ID)
            ->where('plate_number', '粤B00001')
            ->delete();
        Db::name('customer')
            ->where('tenant_id', self::OTHER_TENANT_ID)
            ->where('customer_name', '外部租户客户')
            ->delete();
        $this->prepareCustomerReportRequestContext();
    }
}
