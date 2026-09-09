<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CustomerReportRouteContractTest extends TestCase
{
    public function test_inventory_count_source_presentation_reuses_the_callers_transaction(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/logic/FinanceInventoryCountCorrections.php');
        foreach (['options', 'cancellationOptions'] as $name) {
            $start = strpos($source, 'public static function ' . $name . '('); self::assertNotFalse($start);
            $end = strpos($source, "\n    private static function ", $start);
            $method = substr($source, $start, $end === false ? null : $end - $start);
            self::assertStringContainsString('return self::readConsistently(', $method);
        }
        self::assertStringContainsString('$pdo && $pdo->inTransaction() ? $read() : Db::transaction($read)', $source);
        self::assertSame(1, substr_count($source, 'Db::transaction('), '来源展示不能绕过同事务读取入口');
    }

    public function test_finance_transfer_uses_one_business_transaction_and_one_pair_timestamp(): void
    {
        $root = dirname(__DIR__, 2); $source = (string)file_get_contents($root . '/app/api/jxc/logic/StockService.php');
        $start = strpos($source, 'public static function transfer('); $end = strpos($source, 'public static function rollback(', $start);
        $method = substr($source, $start, $end - $start);
        self::assertStringNotContainsString('WarehouseSkuBalanceService::transfer(', $method);
        self::assertStringContainsString('WarehouseSkuBalanceService::transferWithinTransaction(', $method);
        self::assertStringContainsString("'create_time' => time()", $method);
        $source = (string)file_get_contents($root . '/app/api/jxc/logic/WarehouseSkuBalanceService.php');
        $start = strpos($source, 'public static function transferWithinTransaction('); self::assertNotFalse($start);
        $end = strpos($source, 'public static function available(', $start); $method = substr($source, $start, $end - $start);
        self::assertStringContainsString('self::changeWithinTransaction(', $method);
        self::assertStringNotContainsString('Db::transaction(', $method);
    }

    public function test_finance_stock_loss_never_starts_an_inner_stock_transaction(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string)file_get_contents($root . '/app/api/jxc/logic/StockService.php');
        $start = strpos($source, 'public static function outboundFinanceInventoryLossWithinTransaction(');
        $end = strpos($source, 'public static function outboundFinancePurchaseReturnWithinTransaction(', $start);
        $method = substr($source, $start, $end - $start);
        self::assertStringNotContainsString('WarehouseSkuBalanceService::outbound(', $method, '外层财务事务不得再次调用独立事务包装');
        self::assertStringContainsString('WarehouseSkuBalanceService::outboundWithinTransaction(', $method);
        $balance = (string)file_get_contents($root . '/app/api/jxc/logic/WarehouseSkuBalanceService.php');
        $start = strpos($balance, 'public static function outboundWithinTransaction(');
        self::assertNotFalse($start);
        $end = strpos($balance, 'public static function ', $start + 1);
        $primitive = substr($balance, $start, $end - $start);
        self::assertStringContainsString('self::changeWithinTransaction(', $primitive);
        self::assertStringNotContainsString('self::change(', $primitive);
        self::assertStringNotContainsString('Db::transaction(', $primitive);
        self::assertStringNotContainsString(', true)', $primitive, '普通库内减少仍禁止负库存，不能复用退货的负量特许');
    }

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

    public function test_delivery_and_negative_actions_use_canonical_locks_then_replay_the_committed_idempotent_fact(): void
    {
        $root = dirname(__DIR__, 2);
        $delivery = (string)file_get_contents($root . '/app/api/jxc/logic/DeliveryInventoryLogic.php');
        $negative = (string)file_get_contents($root . '/app/api/jxc/logic/NegativeInventoryLogic.php');
        $stock = (string)file_get_contents($root . '/app/api/jxc/logic/StockService.php');
        $balance = (string)file_get_contents($root . '/app/api/jxc/logic/WarehouseSkuBalanceService.php');

        foreach ([$delivery, $negative] as $logic) {
            self::assertStringContainsString('replayAfterConcurrentCommit(', $logic);
            self::assertStringContainsString("->where('idempotency_key', \$idempotencyKey)->find();", $logic);
            self::assertStringContainsString('request_fingerprint', $logic);
        }
        self::assertStringContainsString("->where('id', \$taskId)->lock(true)->find();", $delivery);
        self::assertStringContainsString("->where('id', \$id)->lock(true)->find();", $negative);
        $balanceLock = strpos($negative, 'WarehouseSkuBalanceService::lockBalanceWithinTransaction(');
        $sourceLock = strpos($negative, "->where('id', \$id)->lock(true)->find();");
        self::assertNotFalse($balanceLock);
        self::assertNotFalse($sourceLock);
        self::assertLessThan($sourceLock, $balanceLock, '库存余额锁必须先于负库存来源锁');
        self::assertStringContainsString(
            "->order(['sku_id' => 'asc', 'goods_id' => 'asc', 'warehouse_id' => 'asc', 'id' => 'asc'])",
            $delivery
        );
        self::assertStringNotContainsString('WarehouseSkuBalance::create(', $balance);
        foreach ([$delivery, $negative, $stock] as $transactionOwner) {
            self::assertStringContainsString("str_contains(\$exception->getMessage(), '1213')", $transactionOwner);
            self::assertStringContainsString("str_contains(\$exception->getMessage(), '1205')", $transactionOwner);
        }
        self::assertStringNotContainsString("str_contains(strtolower(\$exception->getMessage()), 'duplicate')", $delivery);
        self::assertStringNotContainsString("str_contains(strtolower(\$exception->getMessage()), 'deadlock')", $delivery);
        self::assertStringContainsString('WarehouseSkuBalanceService::deliverAttributedWithinTransaction(', $stock);
        self::assertStringContainsString('WarehouseSkuBalanceService::inboundWithinTransaction(', $stock);
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

    public function test_delivery_variant_and_return_actions_do_not_lock_absent_idempotency_keys(): void
    {
        $root = dirname(__DIR__, 2);
        $variant = (string)file_get_contents($root . '/app/api/jxc/logic/DeliveryVariantLogic.php');
        $lineVehicle = (string)file_get_contents($root . '/app/api/jxc/logic/LineVehicleLogic.php');
        $validator = (string)file_get_contents($root . '/app/api/jxc/validate/DeliveryInventoryValidate.php');
        $returnStart = strpos($lineVehicle, 'public static function returnReroutedToPending(');
        $returnEnd = strpos($lineVehicle, 'public static function departTrip(', (int)$returnStart);
        self::assertNotFalse($returnStart);
        self::assertNotFalse($returnEnd);
        $returnMethod = substr($lineVehicle, (int)$returnStart, (int)$returnEnd - (int)$returnStart);

        foreach ([$variant, $returnMethod] as $idempotentWriter) {
            self::assertSame(0, preg_match(
                "/where\\('idempotency_key'[^;]+?lock\\(true\\)->find\\(\\)/s",
                $idempotentWriter
            ), '不存在的幂等键只能普通查询并由唯一键兜底');
        }
        self::assertStringContainsString('self::replayReturnAfterCommit($key, $fingerprint)', $returnMethod);
        self::assertStringContainsString("usort(\$work, [self::class, 'compareWorkItems']);", $variant);
        self::assertStringContainsString("public function sceneDetail() { return \$this->only(['id'])->append('id', 'require'); }", $validator);
        self::assertStringContainsString("public function sceneResolveNegative()", $validator);
        self::assertStringContainsString("->append('id', 'require')", $validator);
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
            "Route::post('jxc/delivery/self_confirm', 'jxc.DeliveryInventory/confirmSelf');",
            "Route::post('jxc/delivery/third_party_confirm', 'jxc.DeliveryInventory/confirmThirdParty');",
            "Route::get('jxc/delivery/third_party_drivers', 'jxc.DeliveryInventory/drivers');",
            "Route::post('jxc/delivery/third_party_driver_save', 'jxc.DeliveryInventory/driverSave');",
            "Route::get('jxc/delivery/detail', 'jxc.DeliveryInventory/detail');",
            "Route::get('jxc/inventory/negative_todos', 'jxc.DeliveryInventory/negativeTodos');",
            "Route::post('jxc/inventory/negative_resolve', 'jxc.DeliveryInventory/resolveNegative');",
            "Route::get('jxc/line_vehicle/schedules', 'jxc.LineVehicle/schedules');",
            "Route::post('jxc/line_vehicle/schedule_save', 'jxc.LineVehicle/scheduleSave');",
            "Route::get('jxc/line_vehicle/trips', 'jxc.LineVehicle/trips');",
            "Route::post('jxc/line_vehicle/trip_create', 'jxc.LineVehicle/tripCreate');",
            "Route::get('jxc/line_vehicle/trip_detail', 'jxc.LineVehicle/tripDetail');",
            "Route::post('jxc/line_vehicle/package_record', 'jxc.LineVehicle/packageRecord');",
            "Route::post('jxc/line_vehicle/trip_depart', 'jxc.LineVehicle/tripDepart');",
            "Route::post('jxc/line_vehicle/reroute', 'jxc.LineVehicle/reroute');",
            "Route::post('jxc/line_vehicle/return_pending', 'jxc.LineVehicle/returnPending');",
            "Route::post('jxc/line_vehicle/handoff_confirm', 'jxc.LineVehicle/handoffConfirm');",
            "Route::get('jxc/line_vehicle/loading_manifest', 'jxc.LineVehicle/manifest');",
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

    public function test_sales_settlement_uses_query_builder_receivable_primitives_inside_its_transaction(): void
    {
        $root = dirname(__DIR__, 2);
        $settlement = (string)file_get_contents($root . '/app/api/jxc/logic/SalesSettlementLogic.php');
        $finance = (string)file_get_contents($root . '/app/api/jxc/logic/FinanceService.php');

        self::assertStringContainsString('FinanceService::addReceivableWithinTransaction(', $settlement);
        self::assertStringContainsString('FinanceService::reduceReceivableWithinTransaction(', $settlement);
        self::assertStringNotContainsString('FinanceService::addReceivable(', $settlement);
        self::assertStringNotContainsString('FinanceService::reduceReceivable(', $settlement);
        foreach (['addReceivableWithinTransaction', 'reduceReceivableWithinTransaction'] as $method) {
            $start = strpos($finance, 'public static function ' . $method . '(');
            self::assertNotFalse($start);
            $end = strpos($finance, "\n    public static function ", (int)$start + 1);
            $body = substr($finance, (int)$start, $end === false ? null : $end - (int)$start);
            self::assertStringContainsString("Db::name('customer')", $body);
            self::assertStringContainsString("Db::name('receivable_flow')", $body);
            self::assertStringNotContainsString('Customer::', $body);
            self::assertStringNotContainsString('Db::transaction(', $body);
        }
    }

    public function test_customer_sales_printing_exposes_prepare_and_result_receipt_routes(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string)file_get_contents($root . '/app/api/route/jxc.php');
        $controller = (string)file_get_contents($root . '/app/api/jxc/controller/SalesSettlementController.php');

        self::assertStringContainsString(
            "Route::post('jxc/sales_settlement/print_prepare', 'jxc.SalesSettlement/preparePrint')",
            $routes
        );
        self::assertStringContainsString(
            "Route::post('jxc/sales_settlement/print_result', 'jxc.SalesSettlement/printResult')",
            $routes
        );
        self::assertStringContainsString('public function preparePrint()', $controller);
        self::assertStringContainsString('public function printResult()', $controller);

        $logic = (string)file_get_contents($root . '/app/api/jxc/logic/SalesSettlementLogic.php');
        self::assertStringContainsString('replayPrintPreparation($idempotencyKey, $orderId, $expectedVersion)', $logic);
        preg_match('/public static function preparePrint\b.*?private static function replayPrintPreparation/s', $logic, $match);
        self::assertNotEmpty($match, 'preparePrint method should remain discoverable for lock-discipline checks');
        self::assertDoesNotMatchRegularExpression(
            "/where\\('(idempotency_key|status)'[^;]+?lock\\(true\\)->find\\(\\)/s",
            $match[0]
        );
    }

    public function test_directed_sales_correction_locks_negative_sources_in_global_fifo_order_before_prioritizing_allocation(): void
    {
        $root = dirname(__DIR__, 2);
        $negative = (string)file_get_contents($root . '/app/api/jxc/logic/NegativeInventoryLogic.php');

        $fifoLock = strpos($negative, "->order(['occurred_time' => 'asc', 'id' => 'asc'])->lock(true)->select()->toArray();");
        $allocationPriority = strpos($negative, 'usort($sources, static function');
        self::assertNotFalse($fifoLock);
        self::assertNotFalse($allocationPriority);
        self::assertLessThan($allocationPriority, $fifoLock);
        self::assertStringContainsString('$preferredSalesOrderId', $negative);
        self::assertStringContainsString('$preferredReportItemId', $negative);
    }
}
