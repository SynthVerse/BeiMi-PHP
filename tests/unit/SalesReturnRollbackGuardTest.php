<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\PurchaseReturnOrderLogic;
use app\api\jxc\logic\SalesReturnOrderLogic;
use app\api\jxc\logic\SupplyOrderLogic;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\facade\Db;
use think\facade\Log;

final class SalesReturnRollbackGuardTest extends TestCase
{
    private const LOGIC_FILE = __DIR__ . '/../../app/api/jxc/logic/SalesReturnOrderLogic.php';

    public function testEditStopsBeforeNewWritesWhenOldStockRollbackFails(): void
    {
        $body = self::methodBody('edit');

        self::assertMatchesRegularExpression(
            "/if\\s*\\(\\s*!\\s*StockService::rollback\\s*\\(\\s*\\(int\\)\\s*\\\$order->id\\s*,\\s*'sales-return'\\s*\\)\\s*\\)\\s*\\{\\s*self::throwFailure\\s*\\(\\s*'旧库存回滚失败'\\s*,\\s*'RETURN_STOCK_FAILED'\\s*\\)\\s*;/s",
            $body
        );
        self::assertLessThan(
            strpos($body, '$order->save'),
            strpos($body, 'StockService::rollback'),
            'old stock rollback guard must run before saving replacement return data'
        );
    }

    public function testEditStopsBeforeNewWritesWhenOldReceivableRollbackFails(): void
    {
        $body = self::methodBody('edit');

        self::assertMatchesRegularExpression(
            "/if\\s*\\(\\s*!\\s*FinanceService::rollbackReceivable\\s*\\(\\s*\\(int\\)\\s*\\\$order->id\\s*,\\s*'sales-return'\\s*\\)\\s*\\)\\s*\\{\\s*self::throwFailure\\s*\\(\\s*'旧应收回滚失败'\\s*,\\s*'RETURN_FINANCE_FAILED'\\s*\\)\\s*;/s",
            $body
        );
        self::assertLessThan(
            strpos($body, '$order->save'),
            strpos($body, 'FinanceService::rollbackReceivable'),
            'old receivable rollback guard must run before saving replacement return data'
        );
    }

    public function testRemoveStopsBeforeDeleteWhenOldStockRollbackFails(): void
    {
        $body = self::methodBody('remove');

        self::assertMatchesRegularExpression(
            "/if\\s*\\(\\s*!\\s*StockService::rollback\\s*\\(\\s*\\(int\\)\\s*\\\$order->id\\s*,\\s*'sales-return'\\s*\\)\\s*\\)\\s*\\{\\s*self::throwFailure\\s*\\(\\s*'旧库存回滚失败'\\s*,\\s*'RETURN_STOCK_FAILED'\\s*\\)\\s*;/s",
            $body
        );
        self::assertLessThan(
            strpos($body, 'OrderGoods::where'),
            strpos($body, 'StockService::rollback'),
            'old stock rollback guard must run before deleting return data'
        );
    }

    public function testRemoveStopsBeforeDeleteWhenOldReceivableRollbackFails(): void
    {
        $body = self::methodBody('remove');

        self::assertMatchesRegularExpression(
            "/if\\s*\\(\\s*!\\s*FinanceService::rollbackReceivable\\s*\\(\\s*\\(int\\)\\s*\\\$order->id\\s*,\\s*'sales-return'\\s*\\)\\s*\\)\\s*\\{\\s*self::throwFailure\\s*\\(\\s*'旧应收回滚失败'\\s*,\\s*'RETURN_FINANCE_FAILED'\\s*\\)\\s*;/s",
            $body
        );
        self::assertLessThan(
            strpos($body, 'OrderGoods::where'),
            strpos($body, 'FinanceService::rollbackReceivable'),
            'old receivable rollback guard must run before deleting return data'
        );
    }

    public function testAllFourEditAndRemovePathsRereadTenantOrderWithLockInsideTransaction(): void
    {
        foreach ([
            'SalesOrderLogic.php' => 'SalesOrder',
            'SupplyOrderLogic.php' => 'SupplyOrder',
            'SalesReturnOrderLogic.php' => 'SalesReturnOrder',
            'PurchaseReturnOrderLogic.php' => 'PurchaseReturnOrder',
        ] as $file => $model) {
            $source = (string)file_get_contents(dirname(self::LOGIC_FILE) . '/' . $file);
            foreach (['edit', 'remove'] as $method) {
                $body = self::methodBodyFromSource($source, $method);
                $transaction = strpos($body, 'Db::startTrans()');
                $lockedRead = strpos($body, $model . "::where('id', (int)\$params['id'])", $transaction);
                $lock = strpos($body, '->lock(true)', $lockedRead);
                self::assertNotFalse($transaction, $file . ' ' . $method . ' starts a transaction');
                self::assertNotFalse($lockedRead, $file . ' ' . $method . ' rereads the order');
                self::assertNotFalse($lock, $file . ' ' . $method . ' locks the order');
                self::assertGreaterThan($transaction, $lockedRead);
                self::assertGreaterThan($lockedRead, $lock);
            }
        }
    }

    public function testStockAndFinanceRollbackUseFrozenNetCompensationContracts(): void
    {
        $stock = (string)file_get_contents(dirname(self::LOGIC_FILE) . '/StockService.php');
        foreach (['tenant_id', 'warehouse_id', 'goods_id', 'sku_id', 'batch_id', 'order_id', 'order_type'] as $dimension) {
            self::assertStringContainsString($dimension, $stock);
        }
        self::assertStringContainsString('uasort($netByDimension', $stock);
        self::assertStringContainsString('sort($goodsIds, SORT_NUMERIC)', $stock);
        self::assertStringContainsString("bccomp(\$net, '0', 2) === 0", $stock);

        $finance = (string)file_get_contents(dirname(self::LOGIC_FILE) . '/FinanceService.php');
        self::assertStringContainsString('TYPE_ORDER_ROLLBACK', $finance);
        self::assertStringContainsString('TYPE_RETURN_ROLLBACK', $finance);
        self::assertStringContainsString("throw new \\RuntimeException('应收回滚补偿超过原始流水')", $finance);
        self::assertStringContainsString("throw new \\RuntimeException('应付回滚补偿超过原始流水')", $finance);
        self::assertStringNotContainsString('=== PayableFlow::TYPE_PAYMENT', self::methodBodyFromSource($finance, 'rollbackPayable'));
    }

    public function testPayableRollbackFlowReceivesAuthoritativeTenantId(): void
    {
        $finance = (string)file_get_contents(dirname(self::LOGIC_FILE) . '/FinanceService.php');
        $rollback = self::methodBodyFromSource($finance, 'rollbackPayable');
        $createFlow = self::methodBodyFromSource($finance, 'createPayableRollbackFlow');

        self::assertStringContainsString('$tenantId = (int)(request()->tenantId ?? 0);', $rollback);
        self::assertMatchesRegularExpression(
            '/self::createPayableRollbackFlow\\s*\\(\\s*\\$tenantId\\s*,/s',
            $rollback
        );
        self::assertMatchesRegularExpression(
            '/protected static function createPayableRollbackFlow\\s*\\(\\s*int \\$tenantId\\s*,/s',
            $finance
        );
        self::assertStringContainsString("'tenant_id'     => \$tenantId", $createFlow);
        self::assertStringNotContainsString('request()->tenantId', $createFlow);
    }

    public function testReturnStockWritesPreserveAuthoritativeSkuAndBatchDimensions(): void
    {
        $sales = (string)file_get_contents(self::LOGIC_FILE);
        foreach (['publish', 'edit'] as $method) {
            $body = self::methodBodyFromSource($sales, $method);
            self::assertStringContainsString("StockService::inbound(", $body);
            self::assertStringContainsString("(int)(\$row['sku_id'] ?? 0),", $body);
            self::assertStringContainsString("(int)(\$row['batch_id'] ?? 0)", $body);
        }
        $salesBuild = self::methodBodyFromSource($sales, 'buildGoodsRows');
        $salesDimensionKey = self::methodBodyFromSource($sales, 'goodsSkuSalesReturnKey');
        self::assertStringContainsString("\$batchId = (int)(\$origin['batch_id'] ?? 0);", $salesBuild);
        self::assertStringContainsString("'batch_id' => \$batchId", $salesBuild);
        self::assertStringContainsString("\$row['sku_id']", $salesDimensionKey);
        self::assertStringContainsString("\$row['batch_id']", $salesDimensionKey);

        $purchase = (string)file_get_contents(dirname(self::LOGIC_FILE) . '/PurchaseReturnOrderLogic.php');
        foreach (['publish', 'edit'] as $method) {
            $body = self::methodBodyFromSource($purchase, $method);
            self::assertStringContainsString("StockService::outbound(", $body);
            self::assertStringContainsString("(int)(\$row['sku_id'] ?? 0),", $body);
            self::assertStringContainsString("(int)(\$row['batch_id'] ?? 0)", $body);
        }
        $purchaseBuild = self::methodBodyFromSource($purchase, 'buildDetailRows');
        self::assertStringContainsString('self::goodsSkuPurchaseReturnKey($row)', $purchaseBuild);
        self::assertStringContainsString("'batch_id' => (int)(\$origin['batch_id'] ?? 0)", $purchaseBuild);
        self::assertStringContainsString("\$originKey = self::goodsSkuPurchaseReturnKey([", $purchaseBuild);
        $replaceDetails = self::methodBodyFromSource($purchase, 'replaceDetails');
        self::assertStringContainsString("unset(\$row['batch_id']);", $replaceDetails);
    }

    public function testReturnGoodsSkuKeysKeepLegacyZeroBatchAndSeparateNonZeroBatches(): void
    {
        foreach ([
            SalesReturnOrderLogic::class => 'goodsSkuSalesReturnKey',
            PurchaseReturnOrderLogic::class => 'goodsSkuPurchaseReturnKey',
        ] as $class => $methodName) {
            $method = (new ReflectionClass($class))->getMethod($methodName);
            $method->setAccessible(true);

            self::assertSame('goods:12:34', $method->invoke(null, [
                'goods_id' => 12,
                'sku_id' => 34,
            ]));
            self::assertSame('goods:12:34', $method->invoke(null, [
                'goods_id' => 12,
                'sku_id' => 34,
                'batch_id' => 0,
            ]));
            self::assertSame('goods:12:34:56', $method->invoke(null, [
                'goods_id' => 12,
                'sku_id' => 34,
                'batch_id' => 56,
            ]));
            self::assertNotSame(
                $method->invoke(null, ['goods_id' => 12, 'sku_id' => 34, 'batch_id' => 56]),
                $method->invoke(null, ['goods_id' => 12, 'sku_id' => 34, 'batch_id' => 57])
            );
        }

        $returnedQty = (new ReflectionClass(SalesReturnOrderLogic::class))
            ->getMethod('salesReturnReturnedQtyForOrigin');
        $returnedQty->setAccessible(true);
        self::assertSame('5.0000', $returnedQty->invoke(null, [
            'goods_id' => 12,
            'sku_id' => 34,
        ], ['goods:12:34' => '5.0000']));
        self::assertSame('3.0000', $returnedQty->invoke(null, [
            'goods_id' => 12,
            'sku_id' => 34,
            'batch_id' => 56,
        ], ['goods:12:34:56' => '3.0000']));
        self::assertSame('0.0000', $returnedQty->invoke(null, [
            'goods_id' => 12,
            'sku_id' => 34,
            'batch_id' => 57,
        ], ['goods:12:34:56' => '3.0000']));
    }

    public function testConcurrentSameKeyPublishesAreIdempotentWithoutDuplicateSideEffects(): void
    {
        self::requireIsolatedDatabase();
        $fixture = self::createBehaviorFixture('publish');
        $tenantId = $fixture['tenant_id'];
        self::setRequestTenant($tenantId);

        try {
            self::ensureEmptyWorkTaskFixture($tenantId);

            $supplyParams = [
                'supplier_id' => $fixture['vendor_id'],
                'warehouse_id' => $fixture['warehouse_id'],
                'order_sn' => 'SUP-C-' . $fixture['suffix'],
                'idempotent_key' => 'supply-' . $fixture['suffix'],
                'goods' => [[
                    'goods_id' => $fixture['goods_id'],
                    'number' => 1,
                    'actual_base_qty' => 1,
                    'units' => 'kg',
                    'price' => 0,
                ]],
            ];
            $stockBeforeSupply = self::goodsStock($fixture['goods_id']);
            $supplyResults = self::runConcurrentLogic(
                SupplyOrderLogic::class,
                'publish',
                $tenantId,
                $supplyParams,
                $supplyParams
            );
            self::assertConcurrentSuccessWithSameIdentity($supplyResults, 'supply publish');
            $supplyId = (int)$supplyResults[0]['result']['id'];
            self::assertSame(1, Db::name('supply_order')->where('tenant_id', $tenantId)
                ->where('idempotent_key', $supplyParams['idempotent_key'])->count());
            self::assertSame(1, self::orderGoodsCount($tenantId, $supplyId, 'supply'));
            self::assertSame(1, self::stockFlowCount($tenantId, $supplyId, 'supply'));
            self::assertSame(1, Db::name('purchase_arrival')->where('tenant_id', $tenantId)
                ->where('supply_order_id', $supplyId)->count());
            self::assertSame(1, Db::name('purchase_arrival_detail')->where('tenant_id', $tenantId)
                ->where('supply_order_id', $supplyId)->count());
            self::assertSame(1, Db::name('goods_batch')->where('tenant_id', $tenantId)
                ->where('supply_order_id', $supplyId)->count());
            self::assertSame(0, Db::name('payable_flow')->where('tenant_id', $tenantId)
                ->where('order_type', 'supply')->where('order_id', $supplyId)->count());
            self::assertSame(1, self::auditCount($tenantId, $supplyId, 'supply_order', 'create'));
            self::assertSame('1.00', bcsub(self::goodsStock($fixture['goods_id']), $stockBeforeSupply, 2));
            self::assertSame(0, self::workTaskCount($tenantId));

            $salesParams = [
                'customer_id' => $fixture['customer_id'],
                'warehouse_id' => $fixture['warehouse_id'],
                'original_order_id' => $fixture['sales_order_id'],
                'order_sn' => 'SRT-C-' . $fixture['suffix'],
                'idempotent_key' => 'sales-return-' . $fixture['suffix'],
                'goods' => [[
                    'original_sales_order_list_id' => $fixture['sales_line_id'],
                    'goods_id' => $fixture['goods_id'],
                    'return_num' => 1,
                    'price' => 0,
                ]],
            ];
            $stockBeforeSalesReturn = self::goodsStock($fixture['goods_id']);
            $salesResults = self::runConcurrentLogic(
                SalesReturnOrderLogic::class,
                'publish',
                $tenantId,
                $salesParams,
                $salesParams
            );
            self::assertConcurrentSuccessWithSameIdentity($salesResults, 'sales return publish');
            $salesReturnId = (int)$salesResults[0]['result']['id'];
            self::assertSame(1, Db::name('sales_return_order')->where('tenant_id', $tenantId)
                ->where('idempotent_key', $salesParams['idempotent_key'])->count());
            self::assertSame(1, self::orderGoodsCount($tenantId, $salesReturnId, 'sales-return'));
            self::assertSame(1, self::stockFlowCount($tenantId, $salesReturnId, 'sales-return'));
            self::assertSame(0, Db::name('receivable_flow')->where('tenant_id', $tenantId)
                ->where('order_type', 'sales-return')->where('order_id', $salesReturnId)->count());
            self::assertSame(1, self::auditCount($tenantId, $salesReturnId, 'return_order', 'create'));
            self::assertSame('1.00', bcsub(self::goodsStock($fixture['goods_id']), $stockBeforeSalesReturn, 2));

            $purchaseParams = [
                'original_order_id' => $fixture['supply_order_id'],
                'order_sn' => 'PRT-C-' . $fixture['suffix'],
                'idempotent_key' => 'purchase-return-' . $fixture['suffix'],
                'goods' => [[
                    'original_supply_order_list_id' => $fixture['supply_line_id'],
                    'goods_id' => $fixture['goods_id'],
                    'return_num' => 1,
                    'price' => 0,
                ]],
            ];
            $stockBeforePurchaseReturn = self::goodsStock($fixture['goods_id']);
            $purchaseResults = self::runConcurrentLogic(
                PurchaseReturnOrderLogic::class,
                'publish',
                $tenantId,
                $purchaseParams,
                $purchaseParams
            );
            self::assertConcurrentSuccessWithSameIdentity($purchaseResults, 'purchase return publish');
            $purchaseReturnId = (int)$purchaseResults[0]['result']['id'];
            self::assertSame(1, Db::name('purchase_return_order')->where('tenant_id', $tenantId)
                ->where('idempotent_key', $purchaseParams['idempotent_key'])->count());
            self::assertSame(1, Db::name('purchase_return_order_lists')->where('tenant_id', $tenantId)
                ->where('purchase_return_order_id', $purchaseReturnId)->count());
            self::assertSame(1, self::stockFlowCount($tenantId, $purchaseReturnId, 'purchase-return'));
            self::assertSame(0, Db::name('payable_flow')->where('tenant_id', $tenantId)
                ->where('order_type', 'purchase-return')->where('order_id', $purchaseReturnId)->count());
            self::assertSame(1, self::auditCount($tenantId, $purchaseReturnId, 'purchase_return_order', 'create'));
            self::assertSame('-1.00', bcsub(self::goodsStock($fixture['goods_id']), $stockBeforePurchaseReturn, 2));
            self::assertSame(0, self::workTaskCount($tenantId));
        } finally {
            self::cleanBehaviorFixture($tenantId);
        }
    }

    public function testConcurrentReturnEditsRejectOneOverReturnWithoutPartialWrites(): void
    {
        self::requireIsolatedDatabase();

        $salesFixture = self::createBehaviorFixture('sales-edit');
        self::setRequestTenant($salesFixture['tenant_id']);
        try {
            $first = SalesReturnOrderLogic::publish(self::salesReturnParams($salesFixture, 'SE1', 3, 0));
            $second = SalesReturnOrderLogic::publish(self::salesReturnParams($salesFixture, 'SE2', 3, 0));
            self::assertIsArray($first, SalesReturnOrderLogic::getError());
            self::assertIsArray($second, SalesReturnOrderLogic::getError());
            $stockBefore = self::goodsStock($salesFixture['goods_id']);

            $firstParams = self::salesReturnParams($salesFixture, 'SE1', 6, 0);
            $firstParams['id'] = (int)$first['id'];
            $secondParams = self::salesReturnParams($salesFixture, 'SE2', 6, 0);
            $secondParams['id'] = (int)$second['id'];
            $results = self::runConcurrentLogic(
                SalesReturnOrderLogic::class,
                'edit',
                $salesFixture['tenant_id'],
                $firstParams,
                $secondParams
            );
            self::assertOneSuccessAndOneQuantityFailure($results, 'sales return edit');
            $numbers = Db::name('order_goods')
                ->where('tenant_id', $salesFixture['tenant_id'])
                ->where('order_type', 'sales-return')
                ->whereIn('order_id', [(int)$first['id'], (int)$second['id']])
                ->order('number')
                ->column('number');
            self::assertSame(['3.0000', '6.0000'], array_values($numbers));
            self::assertSame('3.00', bcsub(self::goodsStock($salesFixture['goods_id']), $stockBefore, 2));
            self::assertSame(4, Db::name('stock_flow')->where('tenant_id', $salesFixture['tenant_id'])
                ->where('order_type', 'sales-return')->count());
            self::assertSame(3, Db::name('audit_log')->where('tenant_id', $salesFixture['tenant_id'])
                ->where('module', 'return_order')->count());
            self::assertSame(2, (int)Db::name('sales_order')->where('tenant_id', $salesFixture['tenant_id'])
                ->where('id', $salesFixture['sales_order_id'])->value('status'));
        } finally {
            self::cleanBehaviorFixture($salesFixture['tenant_id']);
        }

        $purchaseFixture = self::createBehaviorFixture('purchase-edit');
        self::setRequestTenant($purchaseFixture['tenant_id']);
        try {
            $first = PurchaseReturnOrderLogic::publish(self::purchaseReturnParams($purchaseFixture, 'PE1', 3, 0));
            $second = PurchaseReturnOrderLogic::publish(self::purchaseReturnParams($purchaseFixture, 'PE2', 3, 0));
            self::assertIsArray($first, PurchaseReturnOrderLogic::getError());
            self::assertIsArray($second, PurchaseReturnOrderLogic::getError());
            $stockBefore = self::goodsStock($purchaseFixture['goods_id']);

            $firstParams = self::purchaseReturnParams($purchaseFixture, 'PE1', 6, 0);
            $firstParams['id'] = (int)$first['id'];
            $secondParams = self::purchaseReturnParams($purchaseFixture, 'PE2', 6, 0);
            $secondParams['id'] = (int)$second['id'];
            $results = self::runConcurrentLogic(
                PurchaseReturnOrderLogic::class,
                'edit',
                $purchaseFixture['tenant_id'],
                $firstParams,
                $secondParams
            );
            self::assertOneSuccessAndOneQuantityFailure($results, 'purchase return edit');
            $numbers = Db::name('purchase_return_order_lists')
                ->where('tenant_id', $purchaseFixture['tenant_id'])
                ->whereIn('purchase_return_order_id', [(int)$first['id'], (int)$second['id']])
                ->order('return_num')
                ->column('return_num');
            self::assertSame(['3.0000', '6.0000'], array_values($numbers));
            self::assertSame('-3.00', bcsub(self::goodsStock($purchaseFixture['goods_id']), $stockBefore, 2));
            self::assertSame(4, Db::name('stock_flow')->where('tenant_id', $purchaseFixture['tenant_id'])
                ->where('order_type', 'purchase-return')->count());
            self::assertSame(3, Db::name('audit_log')->where('tenant_id', $purchaseFixture['tenant_id'])
                ->where('module', 'purchase_return_order')->count());
            self::assertSame(1, (int)Db::name('supply_order')->where('tenant_id', $purchaseFixture['tenant_id'])
                ->where('id', $purchaseFixture['supply_order_id'])->value('return_status'));
        } finally {
            self::cleanBehaviorFixture($purchaseFixture['tenant_id']);
        }
    }

    public function testFinanceFlowTriggerFailuresRollbackReturnPublishesCompletely(): void
    {
        self::requireIsolatedDatabase();
        Log::channel()->close();

        $salesFixture = self::createBehaviorFixture('sales-flow');
        self::setRequestTenant($salesFixture['tenant_id']);
        $salesTrigger = self::safeTriggerName('sales_flow');
        try {
            $params = self::salesReturnParams($salesFixture, 'SF', 2, 10);
            $before = self::salesRollbackSnapshot($salesFixture, $params['idempotent_key']);
            self::createFailureTrigger($salesTrigger, 'receivable_flow');
            try {
                self::assertFalse(SalesReturnOrderLogic::publish($params));
                self::assertSame('RETURN_FINANCE_FAILED', self::returnErrorCode(SalesReturnOrderLogic::getReturnData()));
                self::assertSame($before, self::salesRollbackSnapshot($salesFixture, $params['idempotent_key']));
            } finally {
                self::dropFailureTrigger($salesTrigger);
                self::assertFalse(self::triggerExists($salesTrigger));
            }
            self::assertIsArray(
                SalesReturnOrderLogic::publish($params),
                'The rolled-back sales-return idempotency key must be reusable.'
            );
        } finally {
            self::dropFailureTrigger($salesTrigger);
            self::cleanBehaviorFixture($salesFixture['tenant_id']);
        }

        $purchaseFixture = self::createBehaviorFixture('purchase-flow');
        self::setRequestTenant($purchaseFixture['tenant_id']);
        $purchaseTrigger = self::safeTriggerName('purchase_flow');
        try {
            $params = self::purchaseReturnParams($purchaseFixture, 'PF', 2, 10);
            $before = self::purchaseRollbackSnapshot($purchaseFixture, $params['idempotent_key']);
            self::createFailureTrigger($purchaseTrigger, 'payable_flow');
            try {
                self::assertFalse(PurchaseReturnOrderLogic::publish($params));
                self::assertSame('RETURN_FINANCE_FAILED', self::returnErrorCode(PurchaseReturnOrderLogic::getReturnData()));
                self::assertSame($before, self::purchaseRollbackSnapshot($purchaseFixture, $params['idempotent_key']));
            } finally {
                self::dropFailureTrigger($purchaseTrigger);
                self::assertFalse(self::triggerExists($purchaseTrigger));
            }
            self::assertIsArray(
                PurchaseReturnOrderLogic::publish($params),
                'The rolled-back purchase-return idempotency key must be reusable.'
            );
        } finally {
            self::dropFailureTrigger($purchaseTrigger);
            self::cleanBehaviorFixture($purchaseFixture['tenant_id']);
        }
    }

    private static function requireIsolatedDatabase(): void
    {
        $default = config('database.default');
        $mysql = config('database.connections.mysql');
        $isolated = $default === 'mysql'
            && is_array($mysql)
            && ($mysql['type'] ?? null) === 'mysql'
            && ($mysql['hostname'] ?? null) === '127.0.0.1'
            && preg_match('/^beimi_full_suite_[a-f0-9]{16,32}$/', (string)($mysql['database'] ?? '')) === 1
            && (int)($mysql['hostport'] ?? 3306) > 0
            && (int)($mysql['hostport'] ?? 3306) !== 3306
            && ($mysql['prefix'] ?? null) === 'la_'
            && is_string($mysql['password'] ?? null)
            && $mysql['password'] !== '';
        if (!$isolated) {
            self::markTestSkipped('Requires the isolated beimi_full_suite database.');
        }
        if (!extension_loaded('pdo_mysql')) {
            self::fail('The isolated behavior gate requires pdo_mysql.');
        }
        if (!function_exists('proc_open')) {
            self::fail('The isolated behavior gate requires proc_open.');
        }
    }

    private static function createBehaviorFixture(string $label): array
    {
        self::assertRequiredBehaviorSchema();
        $suffix = substr($label, 0, 8) . '_' . bin2hex(random_bytes(6));
        $tenantName = 'Fixture ' . $suffix;
        self::assertLessThanOrEqual(32, strlen($tenantName));
        $now = time();
        $tenantId = (int)Db::name('tenant')->insertGetId([
            'sn' => 't_' . $suffix,
            'name' => $tenantName,
            'disable' => 0,
            'create_time' => $now,
        ]);

        try {
            $vendorId = (int)Db::name('vendor')->insertGetId([
                'tenant_id' => $tenantId,
                'supplier_name' => 'Vendor ' . $suffix,
                'is_disabled' => 0,
                'order_money' => '100.00',
                'order_payable' => '100.00',
                'order_paid_money' => '0.00',
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $customerId = (int)Db::name('customer')->insertGetId([
                'tenant_id' => $tenantId,
                'customer_name' => 'Customer ' . $suffix,
                'is_disabled' => 0,
                'order_receivable' => '100.00',
                'order_money' => '100.00',
                'order_pay_money' => '0.00',
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $warehouseId = (int)Db::name('warehouse')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => 'Warehouse ' . $suffix,
                'is_enabled' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $goodsId = (int)Db::name('goods')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => 'Goods ' . $suffix,
                'product_code' => 'G_' . $suffix,
                'units' => 'kg',
                'price' => '10.00',
                'cost' => '10.00',
                'stock' => '100.00',
                'is_disabled' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $salesOrderId = (int)Db::name('sales_order')->insertGetId([
                'tenant_id' => $tenantId,
                'order_sn' => 'SO-' . $suffix,
                'customer_id' => $customerId,
                'customer_name' => 'Customer ' . $suffix,
                'warehouse_id' => $warehouseId,
                'order_money' => '100.00',
                'order_pay_money' => '0.00',
                'order_arrears_money' => '100.00',
                'datetimesingle' => $now,
                'status' => 1,
                'admin_id' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $salesLineId = (int)Db::name('order_goods')->insertGetId(self::orderGoodsFixture(
                $tenantId,
                $salesOrderId,
                'sales',
                $goodsId,
                $suffix,
                $now
            ));
            $supplyOrderId = (int)Db::name('supply_order')->insertGetId([
                'tenant_id' => $tenantId,
                'order_sn' => 'SU-' . $suffix,
                'supplier_id' => $vendorId,
                'supplier_name' => 'Vendor ' . $suffix,
                'warehouse_id' => $warehouseId,
                'order_money' => '100.00',
                'order_pay_money' => '0.00',
                'order_arrears_money' => '100.00',
                'datetimesingle' => $now,
                'status' => 1,
                'return_status' => 0,
                'admin_id' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $supplyLineId = (int)Db::name('order_goods')->insertGetId(self::orderGoodsFixture(
                $tenantId,
                $supplyOrderId,
                'supply',
                $goodsId,
                $suffix,
                $now
            ));

            return [
                'suffix' => $suffix,
                'tenant_id' => $tenantId,
                'vendor_id' => $vendorId,
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'goods_id' => $goodsId,
                'sales_order_id' => $salesOrderId,
                'sales_line_id' => $salesLineId,
                'supply_order_id' => $supplyOrderId,
                'supply_line_id' => $supplyLineId,
            ];
        } catch (\Throwable $e) {
            self::cleanBehaviorFixture($tenantId);
            throw $e;
        }
    }

    private static function orderGoodsFixture(
        int $tenantId,
        int $orderId,
        string $orderType,
        int $goodsId,
        string $suffix,
        int $now
    ): array {
        return [
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'order_type' => $orderType,
            'goods_id' => $goodsId,
            'name' => 'Goods ' . $suffix,
            'units' => 'kg',
            'number' => '10.0000',
            'price' => '10.00',
            'amount' => '100.00',
            'remark' => '',
            'sort' => 0,
            'create_time' => $now,
            'update_time' => $now,
        ];
    }

    private static function assertRequiredBehaviorSchema(): void
    {
        foreach ([
            'la_purchase_return_order',
            'la_purchase_return_order_lists',
            'la_order_goods',
            'la_supply_order',
        ] as $table) {
            $rows = Db::query(
                'SELECT TABLE_NAME FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $table]
            );
            self::assertCount(1, $rows, "Required isolated table {$table} is missing.");
        }
        foreach ([
            ['la_order_goods', 'original_sales_order_list_id'],
            ['la_supply_order', 'return_status'],
        ] as [$table, $column]) {
            $rows = Db::query(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
                ['table' => $table, 'column' => $column]
            );
            self::assertCount(1, $rows, "Required isolated column {$table}.{$column} is missing.");
        }
    }

    private static function ensureEmptyWorkTaskFixture(int $tenantId): void
    {
        $prefix = (string)config('database.connections.mysql.prefix');
        $table = $prefix . 'work_task';
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $table);
        Db::execute(
            "CREATE TABLE IF NOT EXISTS `{$table}` ("
            . '`id` int UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`tenant_id` int UNSIGNED NOT NULL DEFAULT 0,'
            . "`task_kind` varchar(32) NOT NULL DEFAULT '',"
            . '`goods_id` int UNSIGNED NOT NULL DEFAULT 0,'
            . "`status` varchar(32) NOT NULL DEFAULT 'pending',"
            . 'PRIMARY KEY (`id`),'
            . 'KEY `idx_tenant_goods_kind` (`tenant_id`,`goods_id`,`task_kind`,`status`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        self::assertWorkTaskMetadata($table);
        self::assertSame(0, self::workTaskCount($tenantId));
        Db::name('work_task')->where('tenant_id', $tenantId)->delete();
        self::assertSame(0, self::workTaskCount($tenantId));
    }

    private static function assertWorkTaskMetadata(string $table): void
    {
        $tables = Db::query(
            'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => $table]
        );
        self::assertCount(1, $tables);
        self::assertSame('INNODB', strtoupper((string)$tables[0]['ENGINE']));
        self::assertStringStartsWith('utf8mb4_', strtolower((string)$tables[0]['TABLE_COLLATION']));

        $columns = Db::query(
            'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,COLUMN_DEFAULT,IS_NULLABLE,EXTRA '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => $table]
        );
        $byName = [];
        foreach ($columns as $column) {
            $byName[(string)$column['COLUMN_NAME']] = $column;
        }
        foreach ([
            'id' => ['int', null],
            'tenant_id' => ['int', '0'],
            'task_kind' => ['varchar', ''],
            'goods_id' => ['int', '0'],
            'status' => ['varchar', 'pending'],
        ] as $name => [$dataType, $default]) {
            self::assertArrayHasKey($name, $byName);
            self::assertSame($dataType, strtolower((string)$byName[$name]['DATA_TYPE']));
            self::assertSame('NO', (string)$byName[$name]['IS_NULLABLE']);
            if ($default !== null) {
                $actualDefault = $byName[$name]['COLUMN_DEFAULT'];
                self::assertNotNull($actualDefault);
                $actualDefault = (string)$actualDefault;
                $quotedDefault = "'" . str_replace("'", "''", $default) . "'";
                if ($actualDefault === $quotedDefault) {
                    $actualDefault = $default;
                }
                self::assertSame($default, $actualDefault);
            }
        }
        self::assertStringContainsString('unsigned', strtolower((string)$byName['id']['COLUMN_TYPE']));
        self::assertStringContainsString('auto_increment', strtolower((string)$byName['id']['EXTRA']));
        self::assertStringContainsString('unsigned', strtolower((string)$byName['tenant_id']['COLUMN_TYPE']));
        self::assertSame('varchar(32)', strtolower((string)$byName['task_kind']['COLUMN_TYPE']));
        self::assertStringContainsString('unsigned', strtolower((string)$byName['goods_id']['COLUMN_TYPE']));
        self::assertSame('varchar(32)', strtolower((string)$byName['status']['COLUMN_TYPE']));

        $indexes = Db::query("SHOW INDEX FROM `{$table}`");
        $primary = [];
        $required = [];
        foreach ($indexes as $index) {
            if ((string)$index['Key_name'] === 'PRIMARY') {
                $primary[(int)$index['Seq_in_index']] = (string)$index['Column_name'];
            }
            if ((string)$index['Key_name'] === 'idx_tenant_goods_kind') {
                $required[(int)$index['Seq_in_index']] = (string)$index['Column_name'];
            }
        }
        ksort($primary);
        ksort($required);
        self::assertSame(['id'], array_values($primary));
        self::assertSame(['tenant_id', 'goods_id', 'task_kind', 'status'], array_values($required));
    }

    private static function salesReturnParams(array $fixture, string $tag, int $quantity, int $price): array
    {
        return [
            'customer_id' => $fixture['customer_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'original_order_id' => $fixture['sales_order_id'],
            'order_sn' => 'SRT-' . $tag . '-' . $fixture['suffix'],
            'idempotent_key' => 'sales-' . strtolower($tag) . '-' . $fixture['suffix'],
            'goods' => [[
                'original_sales_order_list_id' => $fixture['sales_line_id'],
                'goods_id' => $fixture['goods_id'],
                'return_num' => $quantity,
                'price' => $price,
            ]],
        ];
    }

    private static function purchaseReturnParams(array $fixture, string $tag, int $quantity, int $price): array
    {
        return [
            'original_order_id' => $fixture['supply_order_id'],
            'order_sn' => 'PRT-' . $tag . '-' . $fixture['suffix'],
            'idempotent_key' => 'purchase-' . strtolower($tag) . '-' . $fixture['suffix'],
            'goods' => [[
                'original_supply_order_list_id' => $fixture['supply_line_id'],
                'goods_id' => $fixture['goods_id'],
                'return_num' => $quantity,
                'price' => $price,
            ]],
        ];
    }

    private static function runConcurrentLogic(
        string $class,
        string $method,
        int $tenantId,
        array $firstParams,
        array $secondParams
    ): array {
        $root = realpath(__DIR__ . '/../..');
        self::assertNotFalse($root);
        $code = <<<'PHP'
$root = $argv[1];
$class = $argv[2];
$method = $argv[3];
$tenantId = (int)$argv[4];
$payload = json_decode(base64_decode($argv[5], true), true, 512, JSON_THROW_ON_ERROR);
require $root . '/vendor/autoload.php';
$app = new \think\App($root);
$app->initialize();
\think\facade\Log::channel()->close();
request()->tenantId = $tenantId;
request()->adminId = 0;
try {
    $result = $class::$method($payload);
    $response = [
        'ok' => $result !== false,
        'result' => $result,
        'error' => $class::getError(),
        'return_data' => $class::getReturnData(),
    ];
    echo 'BEIMI_JSON:' . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo 'BEIMI_JSON:' . json_encode([
        'ok' => false,
        'exception' => get_class($e) . ': ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit(1);
}
PHP;
        $payloads = [$firstParams, $secondParams];
        $running = [];
        foreach ($payloads as $params) {
            $command = [
                PHP_BINARY,
                '-d',
                'display_errors=0',
                '-r',
                $code,
                '--',
                $root,
                $class,
                $method,
                (string)$tenantId,
                base64_encode(json_encode($params, JSON_THROW_ON_ERROR)),
            ];
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, $root, null, ['bypass_shell' => true]);
            self::assertIsResource($process, "{$class}::{$method} child process must start.");
            fclose($pipes[0]);
            $running[] = ['process' => $process, 'pipes' => $pipes, 'exit_code' => null];
        }

        $deadline = microtime(true) + 30.0;
        try {
            do {
                $anyRunning = false;
                foreach ($running as $index => $entry) {
                    if ($entry['exit_code'] !== null) {
                        continue;
                    }
                    $status = proc_get_status($entry['process']);
                    if ($status['running']) {
                        $anyRunning = true;
                        continue;
                    }
                    $running[$index]['exit_code'] = (int)$status['exitcode'];
                }
                if ($anyRunning && microtime(true) >= $deadline) {
                    self::fail("Timed out waiting for concurrent {$class}::{$method} calls.");
                }
                if ($anyRunning) {
                    usleep(10000);
                }
            } while ($anyRunning);

            $results = [];
            foreach ($running as $entry) {
                $stdout = stream_get_contents($entry['pipes'][1]);
                $stderr = stream_get_contents($entry['pipes'][2]);
                fclose($entry['pipes'][1]);
                fclose($entry['pipes'][2]);
                proc_close($entry['process']);
                self::assertSame(0, $entry['exit_code'], trim((string)$stderr) ?: trim((string)$stdout));
                $marker = strrpos((string)$stdout, 'BEIMI_JSON:');
                self::assertNotFalse($marker, "Child output did not contain a result marker: {$stdout} {$stderr}");
                $decoded = json_decode(substr((string)$stdout, (int)$marker + 11), true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                $results[] = $decoded;
            }
            $running = [];
            return $results;
        } finally {
            foreach ($running as $entry) {
                foreach ($entry['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                $status = proc_get_status($entry['process']);
                if ($status['running']) {
                    proc_terminate($entry['process']);
                }
                proc_close($entry['process']);
            }
        }
    }

    private static function assertConcurrentSuccessWithSameIdentity(array $results, string $label): void
    {
        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertTrue((bool)($result['ok'] ?? false), $label . ': ' . json_encode($result));
            self::assertIsArray($result['result']);
        }
        self::assertSame((int)$results[0]['result']['id'], (int)$results[1]['result']['id']);
        self::assertSame((string)$results[0]['result']['order_sn'], (string)$results[1]['result']['order_sn']);
    }

    private static function assertOneSuccessAndOneQuantityFailure(array $results, string $label): void
    {
        self::assertCount(2, $results);
        $successes = array_values(array_filter($results, static fn(array $result): bool => (bool)($result['ok'] ?? false)));
        $failures = array_values(array_filter($results, static fn(array $result): bool => !($result['ok'] ?? false)));
        self::assertCount(1, $successes, $label . ': ' . json_encode($results));
        self::assertCount(1, $failures, $label . ': ' . json_encode($results));
        self::assertSame('RETURN_QTY_EXCEEDS_AVAILABLE', self::returnErrorCode($failures[0]['return_data'] ?? null));
    }

    private static function returnErrorCode(mixed $returnData): string
    {
        return is_array($returnData) ? (string)($returnData['error_code'] ?? '') : '';
    }

    private static function setRequestTenant(int $tenantId): void
    {
        request()->tenantId = $tenantId;
        request()->adminId = 0;
    }

    private static function goodsStock(int $goodsId): string
    {
        return number_format((float)Db::name('goods')->where('id', $goodsId)->value('stock'), 2, '.', '');
    }

    private static function orderGoodsCount(int $tenantId, int $orderId, string $orderType): int
    {
        return Db::name('order_goods')
            ->where('tenant_id', $tenantId)
            ->where('order_id', $orderId)
            ->where('order_type', $orderType)
            ->count();
    }

    private static function stockFlowCount(int $tenantId, int $orderId, string $orderType): int
    {
        return Db::name('stock_flow')
            ->where('tenant_id', $tenantId)
            ->where('order_id', $orderId)
            ->where('order_type', $orderType)
            ->count();
    }

    private static function auditCount(
        int $tenantId,
        int $targetId,
        string $module,
        string $action
    ): int {
        return Db::name('audit_log')
            ->where('tenant_id', $tenantId)
            ->where('target_id', $targetId)
            ->where('module', $module)
            ->where('action', $action)
            ->count();
    }

    private static function workTaskCount(int $tenantId): int
    {
        return Db::name('work_task')->where('tenant_id', $tenantId)->count();
    }

    private static function salesRollbackSnapshot(array $fixture, string $idempotentKey): array
    {
        $customer = Db::name('customer')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('id', $fixture['customer_id'])
            ->field('order_receivable,order_money,order_pay_money')
            ->find();
        return [
            'main' => Db::name('sales_return_order')->where('tenant_id', $fixture['tenant_id'])
                ->where('idempotent_key', $idempotentKey)->count(),
            'details' => Db::name('order_goods')->where('tenant_id', $fixture['tenant_id'])
                ->where('order_type', 'sales-return')->count(),
            'stock' => self::goodsStock($fixture['goods_id']),
            'stock_flow' => Db::name('stock_flow')->where('tenant_id', $fixture['tenant_id'])
                ->where('order_type', 'sales-return')->count(),
            'customer' => $customer,
            'finance_flow' => Db::name('receivable_flow')->where('tenant_id', $fixture['tenant_id'])
                ->where('order_type', 'sales-return')->count(),
            'original_status' => (int)Db::name('sales_order')->where('tenant_id', $fixture['tenant_id'])
                ->where('id', $fixture['sales_order_id'])->value('status'),
        ];
    }

    private static function purchaseRollbackSnapshot(array $fixture, string $idempotentKey): array
    {
        $vendor = Db::name('vendor')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('id', $fixture['vendor_id'])
            ->field('order_payable,order_money,order_paid_money')
            ->find();
        return [
            'main' => Db::name('purchase_return_order')->where('tenant_id', $fixture['tenant_id'])
                ->where('idempotent_key', $idempotentKey)->count(),
            'details' => Db::name('purchase_return_order_lists')->where('tenant_id', $fixture['tenant_id'])
                ->count(),
            'stock' => self::goodsStock($fixture['goods_id']),
            'stock_flow' => Db::name('stock_flow')->where('tenant_id', $fixture['tenant_id'])
                ->where('order_type', 'purchase-return')->count(),
            'vendor' => $vendor,
            'finance_flow' => Db::name('payable_flow')->where('tenant_id', $fixture['tenant_id'])
                ->where('order_type', 'purchase-return')->count(),
            'original_status' => (int)Db::name('supply_order')->where('tenant_id', $fixture['tenant_id'])
                ->where('id', $fixture['supply_order_id'])->value('return_status'),
        ];
    }

    private static function safeTriggerName(string $label): string
    {
        $name = 'tr_beimi_' . $label . '_' . bin2hex(random_bytes(8));
        self::assertLessThanOrEqual(64, strlen($name));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $name);
        return $name;
    }

    private static function createFailureTrigger(string $trigger, string $logicalTable): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $trigger);
        self::assertContains($logicalTable, ['receivable_flow', 'payable_flow']);
        $prefix = (string)config('database.connections.mysql.prefix');
        $table = $prefix . $logicalTable;
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $table);
        Db::execute(
            "CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$table}` "
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced finance rollback'"
        );
        self::assertTrue(self::triggerExists($trigger));
    }

    private static function dropFailureTrigger(string $trigger): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $trigger);
        Db::execute("DROP TRIGGER IF EXISTS `{$trigger}`");
    }

    private static function triggerExists(string $trigger): bool
    {
        $rows = Db::query(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :trigger',
            ['trigger' => $trigger]
        );
        return count($rows) > 0;
    }

    private static function cleanBehaviorFixture(int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }
        if (self::tableExists('la_work_task')) {
            Db::name('work_task')->where('tenant_id', $tenantId)->delete();
            self::assertSame(0, self::workTaskCount($tenantId));
        }
        foreach ([
            'audit_log',
            'purchase_return_order_lists',
            'purchase_return_order',
            'purchase_arrival_detail',
            'purchase_arrival',
            'goods_loss_record',
            'goods_batch',
            'stock_flow',
            'receivable_flow',
            'payable_flow',
            'order_goods',
            'sales_return_order',
            'sales_order',
            'supply_order',
            'goods_supplier',
            'goods',
            'customer',
            'vendor',
            'warehouse',
        ] as $table) {
            if (self::tableExists('la_' . $table)) {
                Db::name($table)->where('tenant_id', $tenantId)->delete();
            }
        }
        Db::name('tenant')->where('id', $tenantId)->delete();
        request()->tenantId = 0;
        request()->adminId = 0;
    }

    private static function tableExists(string $table): bool
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $table);
        $rows = Db::query(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => $table]
        );
        return count($rows) > 0;
    }

    private static function methodBody(string $method): string
    {
        $source = (string)file_get_contents(self::LOGIC_FILE);
        return self::methodBodyFromSource($source, $method);
    }

    private static function methodBodyFromSource(string $source, string $method): string
    {
        $start = strpos($source, 'public static function ' . $method);
        if ($start === false) {
            $start = strpos($source, 'protected static function ' . $method);
        }
        self::assertNotFalse($start, "method {$method} exists");

        $open = strpos($source, '{', (int)$start);
        self::assertNotFalse($open, "method {$method} has a body");

        $depth = 0;
        $length = strlen($source);
        for ($i = (int)$open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, (int)$open, $i - (int)$open + 1);
                }
            }
        }

        self::fail("method {$method} body is not closed");
    }
}
