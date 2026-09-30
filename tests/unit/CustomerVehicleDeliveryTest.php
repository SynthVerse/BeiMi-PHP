<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\DeliveryInventoryLogic;
use app\api\jxc\logic\FulfillmentClock;
use app\api\jxc\logic\ThirdPartyDriverLogic;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use tests\unit\WarehouseSkuBalanceForGoodsTestAdapter as WarehouseGoodsBalanceService;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class CustomerVehicleDeliveryTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        $this->createCustomerReportUnit('件');
        self::assertNotFalse(WorkforceLogic::saveProcess([
            'name' => '测试送货',
            'trigger_type' => 'all_processing_completed',
            'is_enabled' => 1,
            'sort' => 900,
        ]), WorkforceLogic::getError());
        FulfillmentClock::freezeForTesting(strtotime('2026-08-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        FulfillmentClock::freezeForTesting(null);
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_customer_vehicle_delivery_is_idempotent_and_only_one_public_entry_can_ship_the_same_remainder(): void
    {
        $fixture = $this->deliveryFixture('customer-vehicle-complete', '2.0000', '2.0000', [
            'delivery_method' => 'customer_vehicle',
            'earliest_delivery_time' => '08:00',
            'plate_number' => '粤A12345',
            'vehicle_location' => '北门一号位',
            'driver_phone' => '13800000001',
        ]);
        $payload = [
            'task_id' => $fixture['delivery_task_id'],
            'actual_handoff_time' => '2026-08-10 08:30:00',
            'handoff_note' => '客户车辆现场签收',
            'idempotency_key' => 'customer-vehicle-complete-event',
            'items' => [[
                'report_item_id' => $fixture['report_item_id'],
                'actual_delivery_weight' => '2.0000',
                'loss_weight' => '0.0000',
                'remaining_action' => 'none',
            ]],
        ];

        $first = DeliveryInventoryLogic::confirmCustomerVehicleDelivery($payload);
        self::assertNotFalse($first, DeliveryInventoryLogic::getError());
        self::assertSame('customer_vehicle', $first['delivery_method']);
        self::assertSame('customer_vehicle_handoff', $first['event_type']);
        self::assertSame(strtotime('2026-08-10 08:30:00'), (int)$first['actual_handoff_time']);
        self::assertSame('粤A12345', $first['delivery_arrangement']['plate_number']);
        self::assertSame((int)$first['id'], (int)DeliveryInventoryLogic::confirmCustomerVehicleDelivery($payload)['id']);
        self::assertSame(1, Db::name('fulfillment_delivery_event')->where('report_id', $fixture['report_id'])->count());
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));

        $duplicate = $payload;
        $duplicate['idempotency_key'] = 'customer-vehicle-wrong-second-entry';
        $duplicate['event_type'] = 'customer_handoff';
        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery($duplicate));
        self::assertSame(1, Db::name('fulfillment_delivery_event')->where('report_id', $fixture['report_id'])->count());
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
    }

    public function test_early_customer_vehicle_delivery_requires_explicit_confirmation_and_reason(): void
    {
        $fixture = $this->deliveryFixture('customer-vehicle-early', '1.0000', '1.0000', [
            'delivery_method' => 'customer_vehicle',
            'earliest_delivery_time' => '08:00',
            'plate_number' => '粤B23456',
            'vehicle_location' => '南门二号位',
        ]);
        $payload = [
            'task_id' => $fixture['delivery_task_id'],
            'actual_handoff_time' => '2026-08-10 07:30:00',
            'idempotency_key' => 'customer-vehicle-early-event',
            'items' => [[
                'report_item_id' => $fixture['report_item_id'],
                'actual_delivery_weight' => '1.0000',
                'loss_weight' => '0.0000',
                'remaining_action' => 'none',
            ]],
        ];

        self::assertFalse(DeliveryInventoryLogic::confirmCustomerVehicleDelivery($payload));
        self::assertSame('实际交接早于计划时间，必须明确确认并填写提前交付原因', DeliveryInventoryLogic::getError());
        $payload['early_delivery_confirmed'] = 1;
        self::assertFalse(DeliveryInventoryLogic::confirmCustomerVehicleDelivery($payload));
        self::assertSame('实际交接早于计划时间，必须明确确认并填写提前交付原因', DeliveryInventoryLogic::getError());
        $payload['early_delivery_reason'] = '客户提前到店，现场核对车辆后交付';
        $event = DeliveryInventoryLogic::confirmCustomerVehicleDelivery($payload);
        self::assertNotFalse($event, DeliveryInventoryLogic::getError());
        self::assertSame(1, (int)$event['early_delivery_confirmed']);
        self::assertSame('客户提前到店，现场核对车辆后交付', $event['early_delivery_reason']);
    }

    public function test_customer_vehicle_partial_delivery_keeps_only_the_real_remainder_pending(): void
    {
        $fixture = $this->deliveryFixture('customer-vehicle-partial', '3.0000', '3.0000', [
            'delivery_method' => 'customer_vehicle',
            'earliest_delivery_time' => '08:00',
            'plate_number' => '粤C34567',
            'vehicle_location' => '东门三号位',
        ]);
        $firstPayload = [
            'task_id' => $fixture['delivery_task_id'],
            'actual_handoff_time' => '2026-08-10 08:15:00',
            'idempotency_key' => 'customer-vehicle-partial-first',
            'items' => [[
                'report_item_id' => $fixture['report_item_id'],
                'actual_delivery_weight' => '1.0000',
                'loss_weight' => '0.0000',
                'remaining_action' => 'pending',
            ]],
        ];
        $first = DeliveryInventoryLogic::confirmCustomerVehicleDelivery($firstPayload);
        self::assertNotFalse($first, DeliveryInventoryLogic::getError());
        self::assertSame('partial', $first['delivery_outcome']);
        self::assertSame('partially_delivered_pending', (string)Db::name('customer_report')
            ->where('id', $fixture['report_id'])->value('status'));
        self::assertSame('2.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame((int)$first['id'], (int)DeliveryInventoryLogic::confirmCustomerVehicleDelivery($firstPayload)['id']);

        $secondPayload = $firstPayload;
        $secondPayload['actual_handoff_time'] = '2026-08-10 08:40:00';
        $secondPayload['idempotency_key'] = 'customer-vehicle-partial-second';
        $secondPayload['items'][0]['actual_delivery_weight'] = '2.0000';
        $secondPayload['items'][0]['remaining_action'] = 'none';
        $second = DeliveryInventoryLogic::confirmCustomerVehicleDelivery($secondPayload);
        self::assertNotFalse($second, DeliveryInventoryLogic::getError());
        self::assertSame('partial_completed', $second['delivery_outcome']);
        self::assertSame('partial_pending_settlement', (string)Db::name('customer_report')
            ->where('id', $fixture['report_id'])->value('status'));
        self::assertSame(2, Db::name('fulfillment_delivery_event')->where('report_id', $fixture['report_id'])->count());
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
    }

    public function test_customer_vehicle_delivery_rejects_future_time_and_incomplete_vehicle_facts(): void
    {
        $future = $this->deliveryFixture('customer-vehicle-future', '1.0000', '1.0000', [
            'delivery_method' => 'customer_vehicle',
            'earliest_delivery_time' => '08:00',
            'plate_number' => '粤D45678',
            'vehicle_location' => '西门四号位',
        ]);
        $futurePayload = $this->completePayload($future, 'customer-vehicle-future-event', '2026-08-10 09:01:00');
        self::assertFalse(DeliveryInventoryLogic::confirmCustomerVehicleDelivery($futurePayload));
        self::assertSame('实际交接时间不能晚于当前时间', DeliveryInventoryLogic::getError());
        self::assertSame('1.0000', WarehouseSkuBalanceService::onHand($future['warehouse_id'], $future['sku_id']));

        $incomplete = $this->deliveryFixture('customer-vehicle-incomplete', '1.0000', '1.0000', [
            'delivery_method' => 'customer_vehicle',
        ]);
        self::assertFalse(DeliveryInventoryLogic::confirmCustomerVehicleDelivery(
            $this->completePayload($incomplete, 'customer-vehicle-incomplete-event', '2026-08-10 08:30:00')
        ));
        self::assertSame('客户车辆资料待完善，不能确认实际交付', DeliveryInventoryLogic::getError());
        $alternate = $this->completePayload($incomplete, 'customer-vehicle-incomplete-self-bypass', '');
        $alternate['event_type'] = 'customer_handoff';
        unset($alternate['actual_handoff_time']);
        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery($alternate));
        self::assertSame('本次送货安排不是自配送，请先变更送货安排', DeliveryInventoryLogic::getError());
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('report_id', $incomplete['report_id'])->count());
    }

    public function test_missing_delivery_arrangement_cannot_fall_back_to_self_delivery(): void
    {
        $fixture = $this->deliveryFixture('missing-arrangement', '1.0000', '1.0000', [
            'delivery_method' => 'self_delivery',
        ]);
        Db::name('customer_report')->where('id', $fixture['report_id'])->update([
            'delivery_method' => '',
            'delivery_arrangement_status' => 'missing',
            'delivery_arrangement_snapshot' => '',
        ]);
        $payload = $this->completePayload($fixture, 'missing-arrangement-event', '');
        $payload['event_type'] = 'customer_handoff';
        unset($payload['actual_handoff_time']);

        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery($payload));
        self::assertSame('本次送货安排缺失，请先明确送货安排', DeliveryInventoryLogic::getError());
        self::assertSame(0, Db::name('fulfillment_delivery_event')
            ->where('report_id', $fixture['report_id'])->count());
        self::assertSame('1.0000', WarehouseSkuBalanceService::onHand(
            $fixture['warehouse_id'],
            $fixture['sku_id']
        ));
    }

    public function test_customer_vehicle_delivery_keeps_loss_and_undelivered_quantities_out_of_sales(): void
    {
        $fixture = $this->deliveryFixture('customer-vehicle-exception-split', '3.0000', '3.0000', [
            'delivery_method' => 'customer_vehicle',
            'earliest_delivery_time' => '08:00',
            'plate_number' => '粤E56789',
            'vehicle_location' => '装卸区五号位',
        ]);
        $event = DeliveryInventoryLogic::confirmCustomerVehicleDelivery([
            'task_id' => $fixture['delivery_task_id'],
            'actual_handoff_time' => '2026-08-10 08:30:00',
            'idempotency_key' => 'customer-vehicle-exception-split-event',
            'items' => [[
                'report_item_id' => $fixture['report_item_id'],
                'actual_delivery_weight' => '1.0000',
                'loss_weight' => '1.0000',
                'loss_package_count' => 1,
                'loss_reason' => '运输途中破损一件',
                'remaining_action' => 'undelivered',
                'undelivered_reason_code' => 'customer_cancelled',
                'undelivered_reason' => '客户现场取消剩余一件',
            ]],
        ]);

        self::assertNotFalse($event, DeliveryInventoryLogic::getError());
        self::assertSame('1.0000', $event['items'][0]['actual_delivery_weight']);
        self::assertSame('1.0000', $event['items'][0]['loss_weight']);
        self::assertSame('1.0000', $event['items'][0]['undelivered_weight']);
        self::assertSame('1.0000', (string)Db::name('order_goods')
            ->where('source_line_id', $fixture['report_item_id'])->value('base_quantity'));
        self::assertSame('1.0000', (string)Db::name('fulfillment_delivery_loss')
            ->where('report_item_id', $fixture['report_item_id'])->value('loss_weight'));
        self::assertSame('1.0000', (string)Db::name('fulfillment_delivery_remainder')
            ->where('report_item_id', $fixture['report_item_id'])->value('quantity'));
        self::assertSame('1.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
    }

    public function test_self_and_third_party_delivery_remain_available_for_their_planned_methods(): void
    {
        $self = $this->deliveryFixture('self-delivery-regression', '1.0000', '1.0000', [
            'delivery_method' => 'self_delivery',
        ]);
        $selfPayload = $this->completePayload($self, 'self-delivery-regression-event', '');
        $selfPayload['event_type'] = 'customer_handoff';
        unset($selfPayload['actual_handoff_time']);
        $selfEvent = DeliveryInventoryLogic::confirmSelfDelivery($selfPayload);
        self::assertNotFalse($selfEvent, DeliveryInventoryLogic::getError());
        self::assertSame('self_delivery', $selfEvent['delivery_method']);

        $third = $this->deliveryFixture('third-party-regression', '1.0000', '1.0000', [
            'delivery_method' => 'third_party',
        ]);
        $driver = ThirdPartyDriverLogic::save([
            'name' => '第三方测试司机',
            'mobile' => '13800000088',
            'platform' => '货拉拉',
            'vehicle_no' => '粤F67890',
            'is_enabled' => 1,
        ]);
        self::assertNotFalse($driver, ThirdPartyDriverLogic::getError());
        $thirdPayload = $this->completePayload(
            $third,
            'third-party-regression-event',
            '2026-08-10 08:30:00'
        );
        $thirdPayload['driver_id'] = (int)$driver['id'];
        $thirdEvent = DeliveryInventoryLogic::confirmThirdPartyDelivery($thirdPayload);
        self::assertNotFalse($thirdEvent, DeliveryInventoryLogic::getError());
        self::assertSame('third_party', $thirdEvent['delivery_method']);
        self::assertSame((int)$driver['id'], (int)$thirdEvent['driver_id']);
    }

    public function test_customer_vehicle_delivery_is_exposed_only_through_the_validated_public_endpoint(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertStringContainsString(
            "Route::post('jxc/delivery/customer_vehicle_confirm', 'jxc.DeliveryInventory/confirmCustomerVehicle')",
            (string)file_get_contents($root . '/app/api/route/jxc.php')
        );
        self::assertStringContainsString(
            'public function confirmCustomerVehicle()',
            (string)file_get_contents($root . '/app/api/jxc/controller/DeliveryInventoryController.php')
        );
        $validator = (string)file_get_contents($root . '/app/api/jxc/validate/DeliveryInventoryValidate.php');
        self::assertStringContainsString('sceneConfirmCustomerVehicle', $validator);
        self::assertStringContainsString("'early_delivery_reason' => 'max:500'", $validator);
        self::assertStringContainsString("'early_delivery_confirmed' => 'in:0,1'", $validator);
    }

    /** @param array<string,mixed> $arrangement @return array<string,int> */
    private function deliveryFixture(string $key, string $stock, string $finalWeight, array $arrangement): array
    {
        $customerId = $this->createCustomer('车辆交付客户-' . $key);
        $goodsId = $this->createCustomerReportGoods('车辆交付商品-' . $key, 'CUSTOMER-VEHICLE-' . $key);
        $warehouseId = $this->createCustomerReportWarehouse('车辆交付仓-' . $key);
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, $stock));
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, $key, '1', '杀好');
        $payload['delivery_arrangement'] = $arrangement;
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $item = $report['items'][0];
        $this->finishSingleGroupProcessing($report, $finalWeight);
        $deliveryTask = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$report['id'])
            ->where('source_key', 'report:' . (int)$report['id'] . ':delivery')->find();
        self::assertNotEmpty($deliveryTask);
        return [
            'report_id' => (int)$report['id'],
            'report_item_id' => (int)$item['id'],
            'delivery_task_id' => (int)$deliveryTask['id'],
            'warehouse_id' => $warehouseId,
            'sku_id' => (int)$item['sku_id'],
        ];
    }

    /** @param array<string,int> $fixture @return array<string,mixed> */
    private function completePayload(array $fixture, string $key, string $actualHandoffTime): array
    {
        return [
            'task_id' => $fixture['delivery_task_id'],
            'actual_handoff_time' => $actualHandoffTime,
            'idempotency_key' => $key,
            'items' => [[
                'report_item_id' => $fixture['report_item_id'],
                'actual_delivery_weight' => '1.0000',
                'loss_weight' => '0.0000',
                'remaining_action' => 'none',
            ]],
        ];
    }
}
