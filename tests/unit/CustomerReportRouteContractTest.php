<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CustomerReportRouteContractTest extends TestCase
{
    public function test_canonical_conversion_side_effects_do_not_use_model_create_inside_the_outer_transaction(): void
    {
        $root = dirname(__DIR__, 2);
        $salesOrderLogic = (string)file_get_contents($root . '/app/api/jxc/logic/SalesOrderLogic.php');
        $stockService = (string)file_get_contents($root . '/app/api/jxc/logic/StockService.php');
        $financeService = (string)file_get_contents($root . '/app/api/jxc/logic/FinanceService.php');

        self::assertStringNotContainsString('SalesOrder::create(', $salesOrderLogic);
        self::assertStringNotContainsString('OrderGoods::create(', $salesOrderLogic);
        self::assertStringNotContainsString('StockFlow::create(', $stockService);
        self::assertStringNotContainsString('ReceivableFlow::create(', $financeService);
        self::assertStringNotContainsString('PayableFlow::create(', $financeService);
    }

    public function test_conversion_prelocks_goods_in_the_canonical_order_before_publishing_sales_orders(): void
    {
        $root = dirname(__DIR__, 2);
        $logic = (string)file_get_contents($root . '/app/api/jxc/logic/CustomerReportLogic.php');

        $lockPosition = strpos($logic, 'self::lockGoodsForConversion($items);');
        $publishPosition = strpos($logic, 'SalesOrderLogic::publishReservedWithinTransaction([');

        self::assertNotFalse($lockPosition, '客户报货转换必须显式预锁全部商品');
        self::assertNotFalse($publishPosition, '客户报货转换必须发布标准销售单');
        self::assertLessThan($publishPosition, $lockPosition, '商品预锁必须发生在任何销售单发布前');
        self::assertStringContainsString("sort(\$goodsIds, SORT_NUMERIC);", $logic);
        self::assertStringContainsString("->where('id', \$goodsId)->lock(true)->value('id')", $logic);
    }

    public function test_fulfillment_change_idempotency_never_locks_an_absent_key_and_retries_deadlocks(): void
    {
        $root = dirname(__DIR__, 2);
        $logic = (string)file_get_contents($root . '/app/api/jxc/logic/FulfillmentChangeLogic.php');

        self::assertStringContainsString('self::transactionWithRetry(', $logic);
        self::assertStringContainsString("str_contains(\$exception->getMessage(), '1213')", $logic);
        self::assertStringContainsString("str_contains(\$exception->getMessage(), '1205')", $logic);
        self::assertStringNotContainsString('self::replay($key, $fingerprint, true)', $logic);
        self::assertStringNotContainsString('$query->lock(true)', $logic);
        self::assertStringContainsString("->where('item_change_id', \$changeId)", $logic);
    }

    public function test_customer_report_sales_source_is_visible_while_standard_sales_returns_remain_legal(): void
    {
        $root = dirname(__DIR__, 2);
        $salesLists = (string)file_get_contents($root . '/app/api/jxc/lists/SalesOrderLists.php');
        $returnLogic = (string)file_get_contents($root . '/app/api/jxc/logic/SalesReturnOrderLogic.php');

        foreach (["'source_type'", "'source_id'", "'source_version'"] as $sourceField) {
            self::assertStringContainsString($sourceField, $salesLists);
        }
        self::assertStringContainsString('$originalOrder = SalesOrder::where', $returnLogic);
        self::assertStringNotContainsString('isCustomerReportSource', $returnLogic);
        self::assertStringNotContainsString("where('source_type'", $returnLogic);
    }

    public function test_customer_report_workflow_has_explicit_authenticated_routes(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string)file_get_contents($root . '/app/api/route/jxc.php');
        foreach ([
            "Route::post('jxc/customer_report/recognize', 'jxc.CustomerReport/recognize');",
            "Route::post('jxc/customer_report/quick_create_goods', 'jxc.CustomerReport/quickCreateGoods');",
            "Route::get('jxc/customer_report/lists', 'jxc.CustomerReport/lists');",
            "Route::post('jxc/customer_report/submit', 'jxc.CustomerReport/submit');",
            "Route::get('jxc/customer_report/detail', 'jxc.CustomerReport/detail');",
            "Route::get('jxc/customer_report/availability', 'jxc.CustomerReport/availability');",
            "Route::post('jxc/customer_report/edit', 'jxc.CustomerReport/edit');",
            "Route::post('jxc/customer_report/retry', 'jxc.CustomerReport/retry');",
            "Route::post('jxc/customer_report/convert', 'jxc.CustomerReport/convert');",
            "Route::post('jxc/customer_report/cancel', 'jxc.CustomerReport/cancel');",
            "Route::post('jxc/customer_report/batch_start', 'jxc.CustomerReport/batchStart');",
            "Route::post('jxc/customer_report/batch_process', 'jxc.CustomerReport/batchProcess');",
            "Route::post('jxc/customer_report/batch_end', 'jxc.CustomerReport/batchEnd');",
            "Route::get('jxc/customer_report/batch_detail', 'jxc.CustomerReport/batchDetail');",
            "Route::post('jxc/tasks/recover_exception', 'jxc.FulfillmentTask/recoverException');",
            "Route::post('jxc/tasks/paper_control', 'jxc.FulfillmentTask/paperControl');",
            "Route::post('jxc/tasks/control_print_data', 'jxc.FulfillmentTask/controlPrintData');",
            "Route::post('jxc/tasks/control_print_result', 'jxc.FulfillmentTask/controlPrintResult');",
            "Route::post('jxc/tasks/reduce_item', 'jxc.FulfillmentTask/reduceItem');",
            "Route::post('jxc/tasks/mark_undelivered', 'jxc.FulfillmentTask/markUndelivered');",
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        foreach ([
            'jxc/customer_report/fulfill',
            'jxc/sales_reservation/',
            'jxc/task/',
            'purchase/lists',
            'purchase/publish',
        ] as $retiredRoute) {
            self::assertStringNotContainsString($retiredRoute, $routes);
        }

        foreach ([
            'app/api/jxc/controller/PurchaseOrderController.php',
            'app/api/jxc/controller/SalesReservationController.php',
            'app/api/jxc/controller/TaskController.php',
            'app/api/jxc/logic/PurchaseOrderLogic.php',
            'app/api/jxc/logic/SalesReservationLogic.php',
            'app/api/jxc/logic/InventoryReservationService.php',
            'app/api/jxc/logic/TaskCenterService.php',
            'app/common/model/jxc/PurchaseOrder.php',
            'app/common/model/jxc/SalesReservation.php',
            'app/common/model/jxc/InventoryReservation.php',
            'app/common/model/jxc/WorkTask.php',
            'database/migrations/20260420_000001_add_from_purchase_order_id.sql',
            'database/migrations/20260703_000001_create_sales_reservation_procurement_task.sql',
            'database/migrations/20260704_000001_create_task_management.sql',
            'database/migrations/20260704_000002_drop_legacy_procurement_task.sql',
            'database/migrations/20260705_000001_rebuild_task_center.sql',
        ] as $retiredPath) {
            self::assertFileDoesNotExist($root . '/' . $retiredPath);
        }

        $workflowMigration = (string)file_get_contents(
            $root . '/database/migrations/20260729_000002_create_customer_report_workflow.sql'
        );
        self::assertStringNotContainsString('customer_report_sale', $workflowMigration);
        self::assertStringNotContainsString('fulfillment_key', $workflowMigration);
        self::assertStringNotContainsString('fulfilling', $workflowMigration);

        $bridgeMigration = (string)file_get_contents(
            $root . '/database/migrations/20260730_000001_customer_report_sales_order_bridge.sql'
        );
        self::assertStringContainsString('AFTER `datetimesingle`', $bridgeMigration);
        self::assertStringNotContainsString('from_purchase_order_id', $bridgeMigration);
    }
}
