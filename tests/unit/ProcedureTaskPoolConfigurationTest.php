<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class ProcedureTaskPoolConfigurationTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
    }

    protected function tearDown(): void
    {
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_new_store_starts_with_an_empty_procedure_catalog(): void
    {
        WorkforceLogic::ensureInitialProcesses();

        $catalog = WorkforceLogic::processes([]);

        self::assertNotFalse($catalog, WorkforceLogic::getError());
        self::assertSame([], $catalog['lists']);
        self::assertSame(0, $catalog['count']);
    }

    public function test_admin_configures_generation_modes_and_only_one_enabled_automatic_procedure_per_mode(): void
    {
        $packing = WorkforceLogic::saveProcess([
            'name' => '活鱼打包',
            'trigger_type' => 'report_selection',
            'is_enabled' => 1,
            'sort' => 20,
        ]);
        $purchase = WorkforceLogic::saveProcess([
            'name' => '采购',
            'trigger_type' => 'inventory_shortage',
            'is_enabled' => 1,
            'sort' => 10,
        ]);
        $delivery = WorkforceLogic::saveProcess([
            'name' => '送货',
            'trigger_type' => 'all_processing_completed',
            'is_enabled' => 1,
            'sort' => 30,
        ]);

        self::assertNotFalse($packing, WorkforceLogic::getError());
        self::assertNotFalse($purchase, WorkforceLogic::getError());
        self::assertNotFalse($delivery, WorkforceLogic::getError());
        self::assertSame('report_selection', $packing['trigger_type']);
        self::assertSame('inventory_shortage', $purchase['trigger_type']);
        self::assertSame('all_processing_completed', $delivery['trigger_type']);

        self::assertFalse(WorkforceLogic::saveProcess([
            'name' => '补货采购',
            'trigger_type' => 'inventory_shortage',
            'is_enabled' => 1,
            'sort' => 40,
        ]));
        self::assertSame('库存不足自动产生只能启用一个工序', WorkforceLogic::getError());

        self::assertNotFalse(WorkforceLogic::saveProcess([
            'name' => '备用采购',
            'trigger_type' => 'inventory_shortage',
            'is_enabled' => 0,
            'sort' => 40,
        ]), WorkforceLogic::getError());
    }

    public function test_referenced_procedure_can_be_disabled_but_not_deleted(): void
    {
        $process = WorkforceLogic::saveProcess([
            'name' => '杀鱼',
            'trigger_type' => 'report_selection',
            'is_enabled' => 1,
            'sort' => 10,
        ]);
        self::assertNotFalse($process, WorkforceLogic::getError());

        Db::name('fulfillment_task')->insert([
            'tenant_id' => self::TENANT_ID,
            'group_id' => 1,
            'report_id' => 1,
            'report_item_id' => 1,
            'process_id' => (int)$process['id'],
            'process_name_snapshot' => '杀鱼',
            'task_type' => 'process',
            'source_key' => 'configuration-test:1',
            'status' => 'unassigned',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        self::assertNotFalse(WorkforceLogic::statusProcess([
            'id' => (int)$process['id'],
            'is_enabled' => 0,
        ]), WorkforceLogic::getError());
        self::assertFalse(WorkforceLogic::deleteProcess(['id' => (int)$process['id']]));
        self::assertSame('工序已有历史任务，只能停用', WorkforceLogic::getError());
    }
}
