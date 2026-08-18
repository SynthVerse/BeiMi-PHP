<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\FulfillmentClock;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\logic\LineVehicleLogic;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use PHPUnit\Framework\TestCase;
use tests\unit\WarehouseSkuBalanceForGoodsTestAdapter as WarehouseGoodsBalanceService;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class LineVehicleWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        $this->createCustomerReportUnit('件');
        FulfillmentClock::freezeForTesting(strtotime('2026-08-20 07:00:00'));
    }

    protected function tearDown(): void
    {
        FulfillmentClock::freezeForTesting(null);
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_line_vehicle_migration_is_safe_to_replay(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = $this->prepareMigration((string)file_get_contents(
            $root . '/database/migrations/20260819_000001_line_vehicle_trip.sql'
        ));
        $this->runStatements($migration);
        $this->runStatements($migration);

        foreach (['la_line_vehicle_schedule', 'la_line_vehicle_trip', 'la_line_vehicle_trip_report'] as $table) {
            self::assertNotEmpty(Db::query("SHOW TABLES LIKE '{$table}'"));
        }
        foreach (['trip_id', 'trip_report_id', 'line_schedule_id'] as $column) {
            self::assertNotEmpty(Db::query("SHOW COLUMNS FROM `la_fulfillment_delivery_event` LIKE '{$column}'"));
        }
        foreach ([
            'active_report_id', 'packed_exception_note', 'loaded_exception_note', 'handoff_exception_note',
            'reroute_method', 'reroute_reason', 'rerouted_time',
        ] as $column) {
            self::assertNotEmpty(Db::query("SHOW COLUMNS FROM `la_line_vehicle_trip_report` LIKE '{$column}'"));
        }
        self::assertNotEmpty(Db::query("SHOW INDEX FROM `la_fulfillment_delivery_event` WHERE Key_name = 'idx_tenant_delivery_event_trip'"));
    }

    public function test_schedule_trip_deadline_risk_and_loading_manifest_use_frozen_paper_fields(): void
    {
        $fixture = $this->fixedLineFixture('line-manifest', '2.00', '1.00', true);
        $schedule = LineVehicleLogic::saveSchedule([
            'name' => '鞍山一班',
            'handoff_location' => '北站货运口',
            'departure_time' => '06:00',
            'buffer_minutes' => 30,
            'is_enabled' => 1,
        ]);
        self::assertNotFalse($schedule, LineVehicleLogic::getError());

        self::assertFalse(LineVehicleLogic::createTrip([
            'trip_date' => '2026-08-20',
            'planned_store_departure_time' => '2026-08-20 05:20:00',
            'idempotency_key' => 'line-trip-malformed-reports',
            'reports' => [1],
        ]));
        self::assertSame('每张送站报货单的班次、报货单和包数格式必须完整', LineVehicleLogic::getError());

        $tripPayload = [
            'trip_date' => '2026-08-20',
            'planned_store_departure_time' => '2026-08-20 05:20:00',
            'idempotency_key' => 'line-trip-manifest',
            'reports' => [[
                'schedule_id' => (int)$schedule['id'],
                'report_id' => $fixture['report_id'],
                'expected_package_count' => 3,
            ]],
        ];
        $trip = LineVehicleLogic::createTrip($tripPayload);
        self::assertNotFalse($trip, LineVehicleLogic::getError());
        self::assertSame((int)$trip['id'], (int)LineVehicleLogic::createTrip($tripPayload)['id']);
        $conflictingReplay = $tripPayload;
        $conflictingReplay['reports'][0]['expected_package_count'] = 4;
        self::assertFalse(LineVehicleLogic::createTrip($conflictingReplay));
        self::assertSame('同一幂等键不能创建不同的送站趟次', LineVehicleLogic::getError());
        $duplicateAssignment = $tripPayload;
        $duplicateAssignment['idempotency_key'] = 'line-trip-manifest-duplicate-report';
        self::assertFalse(LineVehicleLogic::createTrip($duplicateAssignment));
        self::assertSame('报货单已在其他未完成送站趟次中', LineVehicleLogic::getError());
        self::assertSame(strtotime('2026-08-20 05:30:00'), (int)$trip['items'][0]['handoff_deadline']);
        self::assertSame(strtotime('2026-08-20 06:00:00'), (int)$trip['items'][0]['line_departure_time']);
        self::assertSame(strtotime('2026-08-20 05:20:00'), (int)$trip['planned_store_departure_time']);
        self::assertSame('on_time', LineVehicleLogic::calculateRiskStatus(1000, 1200, 900, false));
        self::assertSame('at_risk', LineVehicleLogic::calculateRiskStatus(1000, 1200, 1000, false));
        self::assertSame('at_risk', LineVehicleLogic::calculateRiskStatus(1300, 1200, 900, false));
        self::assertSame('at_risk', LineVehicleLogic::calculateRiskStatus(1000, 1200, 1200, false));
        self::assertSame('missed', LineVehicleLogic::calculateRiskStatus(1000, 1200, 1201, false));
        self::assertSame('completed', LineVehicleLogic::calculateRiskStatus(1000, 1200, 1300, true));

        $manifest = LineVehicleLogic::loadingManifest(['trip_id' => (int)$trip['id']]);
        self::assertNotFalse($manifest, LineVehicleLogic::getError());
        self::assertSame($trip['trip_no'], $manifest['trip_no']);
        self::assertSame('鞍山一班', $manifest['lines'][0]['line_name']);
        self::assertSame('北站货运口', $manifest['lines'][0]['handoff_location']);
        self::assertSame('门店送站装车清单', $manifest['document_type']);
        self::assertTrue((bool)$manifest['lines'][0]['main_customer']['emphasis']);
        self::assertFalse((bool)$manifest['lines'][0]['delivery_customer']['emphasis']);
        self::assertSame(3, (int)$manifest['lines'][0]['expected_package_count']);

        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['price', 'arrears', 'debt', 'sku', 'sales_amount', 'order_money'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $manifestJson);
        }

        $wrongDateFixture = $this->fixedLineFixture('line-wrong-date', '1.00', '1.00');
        $wrongDate = $tripPayload;
        $wrongDate['trip_date'] = '2026-08-21';
        $wrongDate['planned_store_departure_time'] = '2026-08-21 05:20:00';
        $wrongDate['idempotency_key'] = 'line-trip-wrong-date';
        $wrongDate['reports'][0]['report_id'] = $wrongDateFixture['report_id'];
        self::assertFalse(LineVehicleLogic::createTrip($wrongDate));
        self::assertSame('报货单送货日期必须与送站趟次日期一致', LineVehicleLogic::getError());
    }

    public function test_store_departure_does_not_outbound_and_line_handoff_is_idempotent(): void
    {
        $fixture = $this->fixedLineFixture('line-handoff', '2.00', '1.00');
        [$schedule, $trip, $tripReport] = $this->createTripForFixture($fixture, 'line-handoff-trip', 3);

        self::assertNotFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'packed',
            'actual_package_count' => 3,
            'exception_note' => '',
        ]), LineVehicleLogic::getError());
        self::assertFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'loaded',
            'actual_package_count' => 2,
            'exception_note' => '',
        ]));
        self::assertSame('实际包数与应装包数不一致时必须填写异常原因', LineVehicleLogic::getError());
        self::assertNotFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'loaded',
            'actual_package_count' => 2,
            'exception_note' => '一包破损，现场重新加固后并入另一包',
        ]), LineVehicleLogic::getError());

        self::assertFalse(LineVehicleLogic::departTrip([
            'trip_id' => (int)$trip['id'],
            'actual_store_departure_time' => '2026-08-20 07:01:00',
        ]));
        self::assertSame('门店实际出车时间不能晚于当前时间', LineVehicleLogic::getError());
        self::assertSame('loading', (string)Db::name('line_vehicle_trip')->where('id', (int)$trip['id'])->value('status'));

        $departed = LineVehicleLogic::departTrip([
            'trip_id' => (int)$trip['id'],
            'actual_store_departure_time' => '2026-08-20 05:35:00',
        ]);
        self::assertNotFalse($departed, LineVehicleLogic::getError());
        self::assertSame('departed', $departed['status']);
        self::assertFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'loaded',
            'actual_package_count' => 3,
            'exception_note' => '出车后不允许再改纸面装车事实',
        ]));
        self::assertSame('当前送站报货单不能回录包数', LineVehicleLogic::getError());
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('2.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));

        self::assertFalse(LineVehicleLogic::confirmHandoff([
            'trip_report_id' => (int)$tripReport['id'],
            'actual_handoff_packages' => 2,
            'actual_handoff_time' => '2026-08-20 07:01:00',
            'exception_note' => '未来交接不得提前出库',
            'idempotency_key' => 'line-future-handoff-event',
        ]));
        self::assertSame('实际线车交接时间不能晚于当前时间', LineVehicleLogic::getError());
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('2.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));

        self::assertFalse(LineVehicleLogic::confirmHandoff([
            'trip_report_id' => (int)$tripReport['id'],
            'actual_handoff_packages' => 3,
            'actual_handoff_time' => '2026-08-20 05:40:00',
            'exception_note' => '',
            'idempotency_key' => 'line-adjacent-package-mismatch',
        ]));
        self::assertSame('交接包数与应装或实际装车包数不一致时必须填写异常原因', LineVehicleLogic::getError());

        $handoff = [
            'trip_report_id' => (int)$tripReport['id'],
            'actual_handoff_packages' => 3,
            'actual_handoff_time' => '2026-08-20 05:40:00',
            'exception_note' => '装车后找回加固包，交接时恢复三包',
            'idempotency_key' => 'line-handoff-event',
        ];
        $first = LineVehicleLogic::confirmHandoff($handoff);
        self::assertNotFalse($first, LineVehicleLogic::getError());
        self::assertSame('fixed_line_vehicle', $first['delivery_event']['delivery_method']);
        self::assertSame('line_vehicle_handoff', $first['delivery_event']['event_type']);
        self::assertSame((int)$trip['id'], (int)$first['delivery_event']['trip_id']);
        self::assertSame((int)$schedule['id'], (int)$first['delivery_event']['line_schedule_id']);
        self::assertSame('1.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame(1, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());

        $replayed = LineVehicleLogic::confirmHandoff($handoff);
        self::assertNotFalse($replayed, LineVehicleLogic::getError());
        self::assertSame((int)$first['delivery_event']['id'], (int)$replayed['delivery_event']['id']);
        self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)
            ->where('order_type', 'sales_delivery')->count());
        self::assertSame('completed', (string)Db::name('line_vehicle_trip')->where('id', (int)$trip['id'])->value('status'));
        self::assertSame('handed_over', (string)Db::name('line_vehicle_trip_report')
            ->where('id', (int)$tripReport['id'])->value('status'));
        self::assertNull(Db::name('line_vehicle_trip_report')
            ->where('id', (int)$tripReport['id'])->value('active_report_id'));
        $manifest = LineVehicleLogic::loadingManifest(['trip_id' => (int)$trip['id']]);
        self::assertNotFalse($manifest, LineVehicleLogic::getError());
        self::assertSame(2, (int)$manifest['lines'][0]['actual_loaded_package_count']);
        self::assertSame(3, (int)$manifest['lines'][0]['actual_handoff_package_count']);
        self::assertStringContainsString('装车：一包破损', (string)$manifest['lines'][0]['exception_note']);
        self::assertStringContainsString('交接：装车后找回加固包', (string)$manifest['lines'][0]['exception_note']);
    }

    public function test_line_schedule_and_trip_ids_are_tenant_scoped_and_permission_guarded(): void
    {
        $fixture = $this->fixedLineFixture('line-tenant', '1.00', '1.00');
        [$schedule, $trip, $tripReport] = $this->createTripForFixture($fixture, 'line-tenant-trip', 1);

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(LineVehicleLogic::tripDetail(['trip_id' => (int)$trip['id']]));
        self::assertSame('门店送站趟次不存在', LineVehicleLogic::getError());
        self::assertFalse(LineVehicleLogic::reroute([
            'trip_report_id' => (int)$tripReport['id'],
            'reroute_method' => 'fixed_line_vehicle',
            'reroute_reason' => '跨租户猜测改派身份',
        ]));
        self::assertSame('送站报货单不存在', LineVehicleLogic::getError());
        self::assertFalse(LineVehicleLogic::createTrip([
            'trip_date' => '2026-08-20',
            'planned_store_departure_time' => '2026-08-20 05:20:00',
            'idempotency_key' => 'line-cross-tenant-trip',
            'reports' => [[
                'schedule_id' => (int)$schedule['id'],
                'report_id' => $fixture['report_id'],
                'expected_package_count' => 1,
            ]],
        ]));

        $this->prepareCustomerReportRequestContext();
        request()->adminInfo = ['root' => 0];
        request()->adminId = 0;
        request()->userId = 0;
        self::assertFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'loaded',
            'actual_package_count' => 1,
        ]));
        self::assertSame('没有执行该操作的电子权限', LineVehicleLogic::getError());
        $this->prepareCustomerReportRequestContext();
    }

    public function test_concurrent_same_line_handoff_key_outbounds_once(): void
    {
        $fixture = $this->fixedLineFixture('line-concurrent-handoff', '1.00', '1.00');
        [, $trip, $tripReport] = $this->createTripForFixture(
            $fixture,
            'line-concurrent-handoff-trip',
            1
        );
        self::assertNotFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'packed',
            'actual_package_count' => 1,
        ]), LineVehicleLogic::getError());
        self::assertNotFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'loaded',
            'actual_package_count' => 1,
        ]), LineVehicleLogic::getError());
        self::assertNotFalse(LineVehicleLogic::departTrip([
            'trip_id' => (int)$trip['id'],
            'actual_store_departure_time' => '2026-08-20 05:30:00',
        ]), LineVehicleLogic::getError());

        $handoff = [
            'trip_report_id' => (int)$tripReport['id'],
            'actual_handoff_packages' => 1,
            'actual_handoff_time' => '2026-08-20 05:35:00',
            'idempotency_key' => 'line-concurrent-handoff-event',
        ];
        $startPath = tempnam(sys_get_temp_dir(), 'line-handoff-start-');
        unlink($startPath);
        $paths = [];
        try {
            $processes = [];
            for ($worker = 0; $worker < 2; $worker++) {
                $inputPath = tempnam(sys_get_temp_dir(), 'line-handoff-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'line-handoff-output-');
                $paths[] = $inputPath;
                $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode([
                    'tenant_id' => self::TENANT_ID,
                    'admin_id' => self::ADMIN_ID,
                    'clock_time' => strtotime('2026-08-20 07:00:00'),
                    'handoff' => $handoff,
                ], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(dirname(__DIR__) . '/fixtures/line_vehicle_handoff_worker.php') . ' '
                    . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' '
                    . escapeshellarg($startPath);
                $processes[] = [
                    proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes),
                    $pipes,
                    $outputPath,
                ];
            }
            usleep(100_000);
            touch($startPath);
            foreach ($processes as $index => [$process, $pipes]) {
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $processes[$index][3] = $stdout;
                $processes[$index][4] = $stderr;
                self::assertSame(
                    0,
                    proc_close($process),
                    '并发线车交接进程失败：stdout=' . $stdout . '; stderr=' . $stderr
                );
            }
            $responses = array_map(static function (array $process): array {
                $response = json_decode((string)file_get_contents($process[2]), true) ?: [];
                $response['_stdout'] = (string)($process[3] ?? '');
                $response['_stderr'] = (string)($process[4] ?? '');
                return $response;
            }, $processes);
            $diagnostic = json_encode($responses, JSON_UNESCAPED_UNICODE);
            self::assertNotEmpty($responses[0]['result'], $diagnostic);
            self::assertNotEmpty($responses[1]['result'], $diagnostic);
            self::assertSame(
                (int)$responses[0]['result']['delivery_event']['id'],
                (int)$responses[1]['result']['delivery_event']['id'],
                $diagnostic
            );
            self::assertSame(1, Db::name('fulfillment_delivery_event')
                ->where('tenant_id', self::TENANT_ID)->count());
            self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)
                ->where('order_type', 'sales_delivery')->count());
            self::assertSame('0.0000', WarehouseSkuBalanceService::onHand(
                $fixture['warehouse_id'],
                $fixture['sku_id']
            ));
        } finally {
            if (is_file($startPath)) {
                unlink($startPath);
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_missed_line_can_be_auditably_rerouted_without_delivery_or_outbound(): void
    {
        $fixture = $this->fixedLineFixture('line-reroute', '1.00', '1.00');
        [, $trip, $tripReport] = $this->createTripForFixture($fixture, 'line-reroute-trip', 1);
        self::assertNotFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'packed',
            'actual_package_count' => 1,
        ]), LineVehicleLogic::getError());
        self::assertNotFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'loaded',
            'actual_package_count' => 1,
        ]), LineVehicleLogic::getError());
        self::assertFalse(LineVehicleLogic::reroute([
            'trip_report_id' => (int)$tripReport['id'],
            'reroute_method' => 'fixed_line_vehicle',
            'reroute_reason' => '尚未实际出车不能记成错过线车',
        ]));
        self::assertSame('只有已装车、已实际出车且已错过截止的货物才能记为改派', LineVehicleLogic::getError());
        self::assertNotFalse(LineVehicleLogic::departTrip([
            'trip_id' => (int)$trip['id'],
            'actual_store_departure_time' => '2026-08-20 05:30:00',
        ]), LineVehicleLogic::getError());

        FulfillmentClock::freezeForTesting(strtotime('2026-08-20 05:40:00'));
        self::assertFalse(LineVehicleLogic::reroute([
            'trip_report_id' => (int)$tripReport['id'],
            'reroute_method' => 'fixed_line_vehicle',
            'reroute_reason' => '截止时刻仍可交接，不能提前记为错过',
        ]));
        self::assertSame('只有已装车、已实际出车且已错过截止的货物才能记为改派', LineVehicleLogic::getError());
        FulfillmentClock::freezeForTesting(strtotime('2026-08-20 07:00:00'));

        self::assertFalse(LineVehicleLogic::confirmHandoff([
            'trip_report_id' => (int)$tripReport['id'],
            'actual_handoff_packages' => 1,
            'actual_handoff_time' => '2026-08-20 05:41:00',
            'idempotency_key' => 'line-missed-handoff',
        ]));
        self::assertSame('已错过线车交接截止时间，不能标记交接完成，必须改派', LineVehicleLogic::getError());
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());

        $rerouted = LineVehicleLogic::reroute([
            'trip_report_id' => (int)$tripReport['id'],
            'reroute_method' => 'fixed_line_vehicle',
            'reroute_reason' => '原线车已过截止时间，改派下一班',
        ]);
        self::assertNotFalse($rerouted, LineVehicleLogic::getError());
        self::assertSame('rerouted', (string)$rerouted['status']);
        self::assertSame('fixed_line_vehicle', (string)$rerouted['reroute_method']);
        self::assertNull($rerouted['active_report_id']);
        self::assertSame('closed', (string)Db::name('line_vehicle_trip')->where('id', (int)$trip['id'])->value('status'));
        $oldManifest = LineVehicleLogic::loadingManifest(['trip_id' => (int)$trip['id']]);
        self::assertNotFalse($oldManifest, LineVehicleLogic::getError());
        self::assertSame('rerouted', (string)$oldManifest['lines'][0]['status']);
        self::assertStringContainsString('改派：原线车已过截止时间', (string)$oldManifest['lines'][0]['exception_note']);
        self::assertFalse(LineVehicleLogic::departTrip([
            'trip_id' => (int)$trip['id'],
            'actual_store_departure_time' => '2026-08-20 05:30:00',
        ]));
        self::assertSame('已关闭或已完成趟次不能重新出车', LineVehicleLogic::getError());
        self::assertSame('closed', (string)Db::name('line_vehicle_trip')->where('id', (int)$trip['id'])->value('status'));
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)
            ->where('order_type', 'sales_delivery')->count());

        $replacementSchedule = LineVehicleLogic::saveSchedule([
            'name' => '改派后续班-' . $fixture['report_id'],
            'handoff_location' => '后续班交接点',
            'departure_time' => '08:00',
            'buffer_minutes' => 20,
            'is_enabled' => 1,
        ]);
        self::assertNotFalse($replacementSchedule, LineVehicleLogic::getError());
        $replacement = LineVehicleLogic::createTrip([
            'trip_date' => '2026-08-20',
            'planned_store_departure_time' => '2026-08-20 07:20:00',
            'idempotency_key' => 'line-reroute-replacement-trip',
            'reports' => [[
                'schedule_id' => (int)$replacementSchedule['id'],
                'report_id' => $fixture['report_id'],
                'expected_package_count' => 1,
            ]],
        ]);
        self::assertNotFalse($replacement, LineVehicleLogic::getError());
        self::assertSame($fixture['report_id'], (int)$replacement['items'][0]['active_report_id']);
    }

    public function test_concurrent_reroute_and_old_handoff_commit_only_one_terminal_fact(): void
    {
        $fixture = $this->fixedLineFixture('line-reroute-race', '1.00', '1.00');
        [, $trip, $tripReport] = $this->createTripForFixture($fixture, 'line-reroute-race-trip', 1);
        foreach (['packed', 'loaded'] as $stage) {
            self::assertNotFalse(LineVehicleLogic::recordPackages([
                'trip_report_id' => (int)$tripReport['id'],
                'stage' => $stage,
                'actual_package_count' => 1,
            ]), LineVehicleLogic::getError());
        }
        self::assertNotFalse(LineVehicleLogic::departTrip([
            'trip_id' => (int)$trip['id'],
            'actual_store_departure_time' => '2026-08-20 05:30:00',
        ]), LineVehicleLogic::getError());

        $responses = $this->runConcurrentLineActions([[
            'action' => 'handoff',
            'params' => [
                'trip_report_id' => (int)$tripReport['id'],
                'actual_handoff_packages' => 1,
                'actual_handoff_time' => '2026-08-20 05:35:00',
                'idempotency_key' => 'line-reroute-race-handoff',
            ],
        ], [
            'action' => 'reroute',
            'params' => [
                'trip_report_id' => (int)$tripReport['id'],
                'reroute_method' => 'fixed_line_vehicle',
                'reroute_reason' => '并发时改派后续班次',
            ],
        ]]);
        $diagnostic = json_encode($responses, JSON_UNESCAPED_UNICODE);
        $successCount = count(array_filter($responses, static fn(array $response): bool =>
            is_array($response['result'] ?? null)));
        self::assertSame(1, $successCount, $diagnostic);
        $item = Db::name('line_vehicle_trip_report')->where('tenant_id', self::TENANT_ID)
            ->where('id', (int)$tripReport['id'])->find();
        self::assertContains((string)$item['status'], ['handed_over', 'rerouted']);
        if ((string)$item['status'] === 'handed_over') {
            self::assertSame(1, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
            self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        } else {
            self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
            self::assertSame('1.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        }
    }

    public function test_rerouted_report_cannot_handoff_from_an_old_multi_report_trip(): void
    {
        $first = $this->fixedLineFixture('line-old-trip-a', '1.00', '1.00');
        $second = $this->fixedLineFixture('line-old-trip-b', '1.00', '1.00');
        $schedule = LineVehicleLogic::saveSchedule([
            'name' => '多客户原班次',
            'handoff_location' => '原班次交接点',
            'departure_time' => '06:00',
            'buffer_minutes' => 20,
            'is_enabled' => 1,
        ]);
        self::assertNotFalse($schedule, LineVehicleLogic::getError());
        $trip = LineVehicleLogic::createTrip([
            'trip_date' => '2026-08-20',
            'planned_store_departure_time' => '2026-08-20 05:20:00',
            'idempotency_key' => 'line-old-multi-trip',
            'reports' => [[
                'schedule_id' => (int)$schedule['id'],
                'report_id' => $first['report_id'],
                'expected_package_count' => 1,
            ], [
                'schedule_id' => (int)$schedule['id'],
                'report_id' => $second['report_id'],
                'expected_package_count' => 1,
            ]],
        ]);
        self::assertNotFalse($trip, LineVehicleLogic::getError());
        foreach ($trip['items'] as $item) {
            self::assertNotFalse(LineVehicleLogic::recordPackages([
                'trip_report_id' => (int)$item['id'],
                'stage' => 'packed',
                'actual_package_count' => 1,
            ]), LineVehicleLogic::getError());
            self::assertNotFalse(LineVehicleLogic::recordPackages([
                'trip_report_id' => (int)$item['id'],
                'stage' => 'loaded',
                'actual_package_count' => 1,
            ]), LineVehicleLogic::getError());
        }
        self::assertNotFalse(LineVehicleLogic::departTrip([
            'trip_id' => (int)$trip['id'],
            'actual_store_departure_time' => '2026-08-20 05:30:00',
        ]), LineVehicleLogic::getError());
        $firstItem = $trip['items'][0]['report_id'] === $first['report_id'] ? $trip['items'][0] : $trip['items'][1];
        self::assertNotFalse(LineVehicleLogic::reroute([
            'trip_report_id' => (int)$firstItem['id'],
            'reroute_method' => 'fixed_line_vehicle',
            'reroute_reason' => '第一位客户改派后续班次',
        ]), LineVehicleLogic::getError());

        $replacementSchedule = LineVehicleLogic::saveSchedule([
            'name' => '多客户后续班次',
            'handoff_location' => '后续班次交接点',
            'departure_time' => '08:00',
            'buffer_minutes' => 20,
            'is_enabled' => 1,
        ]);
        self::assertNotFalse($replacementSchedule, LineVehicleLogic::getError());
        self::assertNotFalse(LineVehicleLogic::createTrip([
            'trip_date' => '2026-08-20',
            'planned_store_departure_time' => '2026-08-20 07:20:00',
            'idempotency_key' => 'line-old-trip-a-replacement',
            'reports' => [[
                'schedule_id' => (int)$replacementSchedule['id'],
                'report_id' => $first['report_id'],
                'expected_package_count' => 1,
            ]],
        ]), LineVehicleLogic::getError());

        self::assertFalse(LineVehicleLogic::confirmHandoff([
            'trip_report_id' => (int)$firstItem['id'],
            'actual_handoff_packages' => 1,
            'actual_handoff_time' => '2026-08-20 05:35:00',
            'idempotency_key' => 'line-old-trip-a-handoff',
        ]));
        self::assertSame('已改派或不再活动的旧趟次货物不能交接', LineVehicleLogic::getError());
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('1.0000', WarehouseSkuBalanceService::onHand($first['warehouse_id'], $first['sku_id']));
        self::assertSame('departed', (string)Db::name('line_vehicle_trip')->where('id', (int)$trip['id'])->value('status'));
    }

    public function test_handoff_business_failure_rolls_back_trip_delivery_and_inventory_facts(): void
    {
        $fixture = $this->fixedLineFixture('line-rollback', '1.00', '1.00');
        [, $trip, $tripReport] = $this->createTripForFixture($fixture, 'line-rollback-trip', 1);
        self::assertNotFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'packed',
            'actual_package_count' => 1,
        ]), LineVehicleLogic::getError());
        self::assertNotFalse(LineVehicleLogic::recordPackages([
            'trip_report_id' => (int)$tripReport['id'],
            'stage' => 'loaded',
            'actual_package_count' => 1,
        ]), LineVehicleLogic::getError());
        self::assertNotFalse(LineVehicleLogic::departTrip([
            'trip_id' => (int)$trip['id'],
            'actual_store_departure_time' => '2026-08-20 05:30:00',
        ]), LineVehicleLogic::getError());

        $report = Db::name('customer_report')->where('id', $fixture['report_id'])->find();
        $now = time();
        Db::name('sales_order')->insert([
            'tenant_id' => self::TENANT_ID,
            'order_sn' => 'SO-FORMAL-BEFORE-LINE-HANDOFF',
            'customer_id' => (int)$report['main_customer_id'],
            'customer_name' => (string)$report['main_customer_name'],
            'warehouse_id' => $fixture['warehouse_id'],
            'order_money' => '10.00',
            'order_pay_money' => '0.00',
            'order_arrears_money' => '10.00',
            'datetimesingle' => $now,
            'source_type' => 'customer_report',
            'source_id' => $fixture['report_id'],
            'source_version' => 1,
            'settlement_status' => 'formal',
            'cost_status' => 'confirmed',
            'profit_status' => 'accurate',
            'status' => 1,
            'admin_id' => self::ADMIN_ID,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        self::assertFalse(LineVehicleLogic::confirmHandoff([
            'trip_report_id' => (int)$tripReport['id'],
            'actual_handoff_packages' => 1,
            'actual_handoff_time' => '2026-08-20 05:35:00',
            'idempotency_key' => 'line-rollback-handoff',
        ]));
        self::assertSame('该报货单已存在正式销售单，不能重复交付出库', LineVehicleLogic::getError());
        self::assertSame(0, Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('fulfillment_delivery_item')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('loading', (string)Db::name('line_vehicle_trip_report')
            ->where('id', (int)$tripReport['id'])->value('status'));
        self::assertSame(0, (int)Db::name('line_vehicle_trip_report')
            ->where('id', (int)$tripReport['id'])->value('actual_handoff_time'));
        self::assertSame('1.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('1.0000', WarehouseSkuBalanceService::reserved($fixture['warehouse_id'], $fixture['sku_id']));
        self::assertSame('departed', (string)Db::name('line_vehicle_trip')->where('id', (int)$trip['id'])->value('status'));
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>} */
    private function createTripForFixture(array $fixture, string $key, int $expectedPackages): array
    {
        $schedule = LineVehicleLogic::saveSchedule([
            'name' => '大连线-' . $key,
            'handoff_location' => '南站-' . $key,
            'departure_time' => '06:00',
            'buffer_minutes' => 20,
            'is_enabled' => 1,
        ]);
        self::assertNotFalse($schedule, LineVehicleLogic::getError());
        $trip = LineVehicleLogic::createTrip([
            'trip_date' => '2026-08-20',
            'planned_store_departure_time' => '2026-08-20 05:20:00',
            'idempotency_key' => $key,
            'reports' => [[
                'schedule_id' => (int)$schedule['id'],
                'report_id' => $fixture['report_id'],
                'expected_package_count' => $expectedPackages,
            ]],
        ]);
        self::assertNotFalse($trip, LineVehicleLogic::getError());
        return [$schedule, $trip, $trip['items'][0]];
    }

    /** @param array<int,array{action:string,params:array<string,mixed>}> $actions */
    private function runConcurrentLineActions(array $actions): array
    {
        $startPath = tempnam(sys_get_temp_dir(), 'line-action-start-');
        unlink($startPath);
        $paths = [];
        try {
            $processes = [];
            foreach ($actions as $action) {
                $inputPath = tempnam(sys_get_temp_dir(), 'line-action-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'line-action-output-');
                $paths[] = $inputPath;
                $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode([
                    'tenant_id' => self::TENANT_ID,
                    'admin_id' => self::ADMIN_ID,
                    'clock_time' => strtotime('2026-08-20 07:00:00'),
                    'action' => $action['action'],
                    'params' => $action['params'],
                ], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(dirname(__DIR__) . '/fixtures/line_vehicle_handoff_worker.php') . ' '
                    . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' '
                    . escapeshellarg($startPath);
                $processes[] = [
                    proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes),
                    $pipes,
                    $outputPath,
                ];
            }
            usleep(100_000);
            touch($startPath);
            foreach ($processes as $index => [$process, $pipes]) {
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $processes[$index][3] = $stdout;
                $processes[$index][4] = $stderr;
                self::assertSame(
                    0,
                    proc_close($process),
                    '并发改派/交接进程失败：stdout=' . $stdout . '; stderr=' . $stderr
                );
            }
            return array_map(static function (array $process): array {
                $response = json_decode((string)file_get_contents($process[2]), true) ?: [];
                $response['_stdout'] = (string)($process[3] ?? '');
                $response['_stderr'] = (string)($process[4] ?? '');
                return $response;
            }, $processes);
        } finally {
            if (is_file($startPath)) {
                unlink($startPath);
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @return array{report_id:int,delivery_task_id:int,warehouse_id:int,goods_id:int,sku_id:int} */
    private function fixedLineFixture(string $key, string $stock, string $finalWeight, bool $useChild = false): array
    {
        $mainCustomerId = $this->createCustomer('固定线主客户-' . $key);
        $deliveryCustomerId = $useChild
            ? $this->createCustomer('固定线子客户-' . $key, $mainCustomerId)
            : $mainCustomerId;
        $goodsId = $this->createCustomerReportGoods('固定线商品-' . $key, 'LINE-' . $key);
        $warehouseId = $this->createCustomerReportWarehouse('固定线仓-' . $key);
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, $stock));
        $payload = [
            'main_customer_id' => $mainCustomerId,
            'delivery_date' => '2026-08-20',
            'is_supplement' => 0,
            'idempotency_key' => $key,
            'remark' => '',
            'items' => [[
                'delivery_customer_id' => $deliveryCustomerId,
                'goods_id' => $goodsId,
                'warehouse_id' => $warehouseId,
                'unit_id' => 0,
                'unit_name' => '件',
                'order_qty' => '1',
                'piece_weight_confirmed' => 1,
                'piece_weight_min' => '1.00',
                'piece_weight_max' => '1.00',
                'price_status' => 'unpriced',
                'processing_requirement' => '杀好',
                'processing' => '杀好',
                'line_remark' => '杀好',
            ]],
        ];
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $item = $report['items'][0];
        $finalTask = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_item_id', (int)$item['id'])->where('is_settlement_task', 1)->find();
        self::assertNotEmpty($finalTask);
        Db::name('fulfillment_task')->where('id', (int)$finalTask['id'])->update([
            'status' => 'recovered',
            'actual_weight' => $finalWeight,
            'process_weight' => $finalWeight,
            'actual_price' => '20.00',
            'recovered_time' => time(),
            'update_time' => time(),
        ]);
        Db::name('customer_report_item')->where('id', (int)$item['id'])->update([
            'final_actual_weight' => $finalWeight,
            'final_weight_task_id' => (int)$finalTask['id'],
            'fulfillment_status' => 'final_weight_recorded',
            'update_time' => time(),
        ]);
        FulfillmentTaskLogic::refreshGroupForItem((int)$item['id']);
        $deliveryTask = Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$report['id'])
            ->where('source_key', 'report:' . (int)$report['id'] . ':delivery')->find();
        self::assertNotEmpty($deliveryTask);
        return [
            'report_id' => (int)$report['id'],
            'delivery_task_id' => (int)$deliveryTask['id'],
            'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId,
            'sku_id' => (int)$item['sku_id'],
        ];
    }
}
