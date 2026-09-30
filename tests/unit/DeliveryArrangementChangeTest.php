<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class DeliveryArrangementChangeTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        $this->createCustomerReportUnit('件');
    }

    protected function tearDown(): void
    {
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_change_updates_only_current_remainder_and_creates_complete_audit_and_paper_diff(): void
    {
        $fixture = $this->createPrintedArrangementFixture();
        $oldArrangement = $fixture['report']['delivery_arrangement'];
        $eventId = (int)Db::name('fulfillment_delivery_event')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'report_id' => $fixture['report_id'],
            'task_id' => $fixture['task_id'],
            'delivery_method' => 'customer_vehicle',
            'delivery_arrangement_snapshot' => json_encode($oldArrangement, JSON_UNESCAPED_UNICODE),
            'event_type' => 'customer_handoff',
            'status' => 'completed',
            'idempotency_key' => 'arrangement-change-delivered-fact',
            'request_fingerprint' => hash('sha256', 'arrangement-change-delivered-fact'),
            'handoff_note' => '首批已按原车交付',
            'exception_reason' => '',
            'second_confirmed' => 0,
            'operator_id' => self::ADMIN_ID,
            'delivered_time' => time(),
            'create_time' => time(),
            'update_time' => time(),
        ]);
        Db::name('customer_report_item')->where('id', $fixture['item_id'])->update([
            'final_actual_weight' => '10.00',
            'fulfilled_base_qty' => '4.00',
            'fulfillment_status' => 'partially_delivered_pending',
            'update_time' => time(),
        ]);
        Db::name('customer_report')->where('id', $fixture['report_id'])->update([
            'status' => 'partially_delivered_pending',
            'update_time' => time(),
        ]);

        $result = FulfillmentTaskLogic::changeDeliveryArrangement([
            'id' => $fixture['task_id'],
            'version' => $fixture['version'],
            'delivery_date' => '2026-08-11',
            'delivery_arrangement' => [
                'delivery_method' => 'customer_vehicle',
                'earliest_delivery_time' => '07:15',
                'plate_number' => '粤B67890',
                'vehicle_location' => '南门二号位',
                'driver_phone' => '13900000002',
            ],
            'reason' => '原车临时故障，剩余六件改由备用车配送',
        ]);

        self::assertNotFalse($result, FulfillmentTaskLogic::getError());
        self::assertSame($fixture['version'] + 1, (int)$result['report']['version']);
        self::assertSame('2026-08-11', $result['report']['delivery_arrangement']['delivery_date']);
        self::assertSame('粤B67890', $result['report']['delivery_arrangement']['plate_number']);

        $eventSnapshot = json_decode((string)Db::name('fulfillment_delivery_event')
            ->where('id', $eventId)->value('delivery_arrangement_snapshot'), true);
        self::assertSame('粤A12345', $eventSnapshot['plate_number']);
        self::assertSame('2026-08-10', $eventSnapshot['delivery_date']);

        $control = Db::name('fulfillment_ticket_control')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', $fixture['report_id'])->where('action_type', 'change')->find();
        self::assertNotEmpty($control);
        self::assertSame('pending_recovery', $control['status']);
        $before = json_decode((string)$control['before_snapshot'], true);
        $after = json_decode((string)$control['after_snapshot'], true);
        self::assertSame('粤A12345', $before['delivery_arrangement']['plate_number']);
        self::assertSame('粤B67890', $after['delivery_arrangement']['plate_number']);
        self::assertSame('2026-08-10', $before['delivery_arrangement']['delivery_date']);
        self::assertSame('2026-08-11', $after['delivery_arrangement']['delivery_date']);

        $audit = Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'customer_report')->where('action', 'delivery_arrangement_change')
            ->where('target_id', $fixture['report_id'])->find();
        self::assertNotEmpty($audit);
        self::assertSame(self::ADMIN_ID, (int)$audit['admin_id']);
        self::assertSame('原车临时故障，剩余六件改由备用车配送', $audit['remark']);
        self::assertSame('粤A12345', json_decode((string)$audit['before_data'], true)['delivery_arrangement']['plate_number']);
        self::assertSame('粤B67890', json_decode((string)$audit['after_data'], true)['delivery_arrangement']['plate_number']);

        $detail = FulfillmentTaskLogic::detail(['id' => $fixture['task_id']]);
        self::assertNotFalse($detail, FulfillmentTaskLogic::getError());
        self::assertSame('粤B67890', $detail['delivery_plate_number']);
        self::assertSame('2026-08-11', $detail['delivery_date']);
        self::assertSame($fixture['version'] + 1, (int)$detail['report_version']);
        self::assertSame('change', $detail['paper_controls'][0]['action_type']);
        self::assertSame('粤A12345', $detail['paper_controls'][0]['before']['delivery_arrangement']['plate_number']);
        self::assertSame('粤B67890', $detail['paper_controls'][0]['after']['delivery_arrangement']['plate_number']);
    }

    public function test_change_rejects_stale_version_no_diff_and_missing_reason_without_fake_audit(): void
    {
        $fixture = $this->createPrintedArrangementFixture(false);
        $changed = FulfillmentTaskLogic::changeDeliveryArrangement([
            'id' => $fixture['task_id'],
            'version' => $fixture['version'],
            'delivery_date' => '2026-08-10',
            'delivery_arrangement' => [
                'delivery_method' => 'self_delivery',
            ],
            'reason' => '客户改为门店自配送',
        ]);
        self::assertNotFalse($changed, FulfillmentTaskLogic::getError());
        $auditCount = Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'customer_report')->where('action', 'delivery_arrangement_change')->count();

        self::assertFalse(FulfillmentTaskLogic::changeDeliveryArrangement([
            'id' => $fixture['task_id'],
            'version' => $fixture['version'],
            'delivery_date' => '2026-08-10',
            'delivery_arrangement' => ['delivery_method' => 'third_party'],
            'reason' => '并发旧版本',
        ]));
        self::assertSame('报货单版本已变化，请刷新后重试', FulfillmentTaskLogic::getError());

        self::assertFalse(FulfillmentTaskLogic::changeDeliveryArrangement([
            'id' => $fixture['task_id'],
            'version' => $fixture['version'] + 1,
            'delivery_date' => '2026-08-10',
            'delivery_arrangement' => ['delivery_method' => 'self_delivery'],
            'reason' => '没有实际变化',
        ]));
        self::assertSame('送货安排没有实际变化', FulfillmentTaskLogic::getError());

        self::assertFalse(FulfillmentTaskLogic::changeDeliveryArrangement([
            'id' => $fixture['task_id'],
            'version' => $fixture['version'] + 1,
            'delivery_date' => '2026-08-11',
            'delivery_arrangement' => ['delivery_method' => 'third_party'],
            'reason' => '   ',
        ]));
        self::assertSame('请填写本次送货安排变更原因', FulfillmentTaskLogic::getError());
        self::assertSame($auditCount, Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'customer_report')->where('action', 'delivery_arrangement_change')->count());
    }

    public function test_change_requires_control_permission_and_rejects_completed_or_cancelled_facts(): void
    {
        $fixture = $this->createPrintedArrangementFixture(false);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->adminId = 0;
        request()->userId = 0;
        self::assertFalse(FulfillmentTaskLogic::changeDeliveryArrangement($this->changePayload($fixture)));
        self::assertSame('没有执行该操作的电子权限', FulfillmentTaskLogic::getError());

        $this->prepareCustomerReportRequestContext();
        foreach (['completed', 'cancelled'] as $status) {
            Db::name('customer_report')->where('id', $fixture['report_id'])->update(['status' => $status]);
            self::assertFalse(FulfillmentTaskLogic::changeDeliveryArrangement($this->changePayload($fixture)));
            self::assertSame('已完成或已取消的报货单不能变更送货安排', FulfillmentTaskLogic::getError());
        }
    }

    public function test_change_rejects_report_without_undelivered_remainder(): void
    {
        $fixture = $this->createPrintedArrangementFixture(false);
        Db::name('customer_report_item')->where('id', $fixture['item_id'])->update([
            'final_actual_weight' => '10.00',
            'fulfilled_base_qty' => '10.00',
            'fulfillment_status' => 'delivered',
        ]);

        self::assertFalse(FulfillmentTaskLogic::changeDeliveryArrangement($this->changePayload($fixture)));
        self::assertSame('当前没有可变更安排的未交付余量', FulfillmentTaskLogic::getError());
    }

    public function test_change_waits_for_an_in_flight_print_receipt_instead_of_invalidating_an_unknown_paper_copy(): void
    {
        $fixture = $this->createPrintedArrangementFixture(false);
        Db::name('fulfillment_task')->where('id', $fixture['task_id'])->update(['status' => 'printable']);
        $prepared = FulfillmentTaskLogic::printData(['id' => $fixture['task_id']]);
        self::assertNotFalse($prepared, FulfillmentTaskLogic::getError());

        self::assertFalse(FulfillmentTaskLogic::changeDeliveryArrangement($this->changePayload($fixture)));
        self::assertSame(
            '任务纸票正在等待打印回执，请确认打印结果后再变更送货安排',
            FulfillmentTaskLogic::getError()
        );
        self::assertSame($fixture['version'], (int)Db::name('customer_report')
            ->where('id', $fixture['report_id'])->value('version'));
        self::assertSame('pending', (string)Db::name('fulfillment_print_log')
            ->where('id', (int)$prepared['print_log_id'])->value('status'));
        self::assertSame(0, Db::name('fulfillment_ticket_control')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', $fixture['report_id'])->count());
        self::assertNotFalse(FulfillmentTaskLogic::printResult([
            'id' => $fixture['task_id'],
            'print_log_id' => (int)$prepared['print_log_id'],
            'success' => 1,
        ]), FulfillmentTaskLogic::getError());
    }

    public function test_migration_backfills_existing_delivery_events_with_the_pre_change_arrangement(): void
    {
        $fixture = $this->createPrintedArrangementFixture(false);
        $eventId = (int)Db::name('fulfillment_delivery_event')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'report_id' => $fixture['report_id'],
            'task_id' => $fixture['task_id'],
            'delivery_method' => 'customer_vehicle',
            'delivery_arrangement_snapshot' => null,
            'event_type' => 'customer_handoff',
            'status' => 'completed',
            'idempotency_key' => 'arrangement-migration-' . uniqid(),
            'request_fingerprint' => hash('sha256', uniqid('migration', true)),
            'handoff_note' => '',
            'exception_reason' => '',
            'second_confirmed' => 0,
            'operator_id' => self::ADMIN_ID,
            'delivered_time' => time(),
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $migration = dirname(__DIR__, 2) . '/database/migrations/20260930_000005_delivery_arrangement_change.sql';
        $this->runStatements($this->prepareMigration((string)file_get_contents($migration)));

        $snapshot = json_decode((string)Db::name('fulfillment_delivery_event')
            ->where('id', $eventId)->value('delivery_arrangement_snapshot'), true);
        self::assertSame('customer_vehicle', $snapshot['delivery_method']);
        self::assertSame('2026-08-10', $snapshot['delivery_date']);
        self::assertSame('粤A12345', $snapshot['plate_number']);
    }

    public function test_public_route_controller_and_validation_scene_are_exposed(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertStringContainsString(
            "Route::post('jxc/tasks/change_delivery_arrangement', 'jxc.FulfillmentTask/changeDeliveryArrangement')",
            (string)file_get_contents($root . '/app/api/route/jxc.php')
        );
        self::assertStringContainsString(
            'public function changeDeliveryArrangement()',
            (string)file_get_contents($root . '/app/api/jxc/controller/FulfillmentTaskController.php')
        );
        self::assertStringContainsString(
            'sceneChangeDeliveryArrangement',
            (string)file_get_contents($root . '/app/api/jxc/validate/FulfillmentTaskValidate.php')
        );
    }

    /** @return array<string,mixed> */
    private function createPrintedArrangementFixture(bool $print = true): array
    {
        $customerId = $this->createCustomer('换车测试客户');
        $goodsId = $this->createCustomerReportGoods('换车测试鱼', 'ARRANGE-CHANGE');
        $warehouseId = $this->createCustomerReportWarehouse('换车测试仓');
        $payload = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'arrangement-change-' . uniqid(), '10', '分拣');
        $payload['delivery_arrangement'] = [
            'delivery_method' => 'customer_vehicle',
            'earliest_delivery_time' => '05:30',
            'plate_number' => '粤A12345',
            'vehicle_location' => '北门一号位',
            'driver_phone' => '13800000001',
        ];
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $task = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$report['id'])->whereLike('source_key', '%:process:%')->order('id')->find();
        self::assertNotEmpty($task);
        $taskId = (int)$task['id'];
        if ($print) {
            Db::name('fulfillment_task')->where('id', $taskId)->update(['status' => 'printable']);
            $prepared = FulfillmentTaskLogic::printData(['id' => $taskId]);
            self::assertNotFalse($prepared, FulfillmentTaskLogic::getError());
            self::assertNotFalse(FulfillmentTaskLogic::printResult([
                'id' => $taskId, 'print_log_id' => (int)$prepared['print_log_id'], 'success' => 1,
            ]), FulfillmentTaskLogic::getError());
        }
        return [
            'report' => $report,
            'report_id' => (int)$report['id'],
            'item_id' => (int)$report['items'][0]['id'],
            'task_id' => $taskId,
            'version' => (int)$report['version'],
        ];
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function changePayload(array $fixture): array
    {
        return [
            'id' => (int)$fixture['task_id'],
            'version' => (int)$fixture['version'],
            'delivery_date' => '2026-08-11',
            'delivery_arrangement' => ['delivery_method' => 'third_party'],
            'reason' => '测试不可变边界',
        ];
    }
}
