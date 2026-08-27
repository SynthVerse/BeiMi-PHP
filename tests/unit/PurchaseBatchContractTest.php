<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PurchaseBatchContractTest extends TestCase
{
    public function test_purchase_batch_routes_are_additive_and_keep_supply_routes_compatible(): void
    {
        $routes = $this->read('app/api/route/jxc.php');

        self::assertStringContainsString("Route::get('purchase-batch/lists'", $routes);
        self::assertStringContainsString("Route::get('purchase-batch/details'", $routes);
        self::assertStringContainsString("Route::post('purchase-batch/publish'", $routes);
        self::assertStringContainsString("Route::get('supply/lists'", $routes);
        self::assertStringContainsString("Route::post('supply/publish'", $routes);
        self::assertStringContainsString("Route::get('supply/details'", $routes);
    }

    public function test_batch_submission_enforces_supplier_sku_and_uses_one_outer_transaction_for_all_children(): void
    {
        $logic = $this->read('app/api/jxc/logic/PurchaseBatchLogic.php');
        $supply = $this->read('app/api/jxc/logic/SupplyOrderLogic.php');

        self::assertStringContainsString('Db::transaction(', $logic);
        self::assertStringContainsString('GoodsSupplierMatrixLogic::assertCanSupply($supplierId, $goodsId, $skuId)', $logic);
        self::assertStringContainsString('SupplyOrderLogic::publishWithinTransaction([', $logic);
        self::assertStringContainsString("'order_pay_money' => 0", $logic);
        self::assertStringContainsString('ksort($groups, SORT_NUMERIC)', $logic);
        self::assertStringContainsString('throw new BusinessException(self::getError())', $logic);
        self::assertStringContainsString('PurchaseBatchSupplyOrder::create([', $logic);
        self::assertStringContainsString('AuditService::logWithinTransaction(', $logic);
        self::assertStringContainsString('AuditService::MODULE_PURCHASE_BATCH', $logic);
        self::assertStringContainsString('refreshSummaryWithinTransaction', $logic);

        self::assertStringContainsString('public static function publishWithinTransaction(array $params, bool $auditWithinTransaction = true): array|false', $supply);
        self::assertStringContainsString('PurchaseArrivalService::rebuildForSupplyOrder(', $supply);
        self::assertStringContainsString('StockService::inbound(', $supply);
        self::assertStringContainsString('FinanceService::addPayable(', $supply);
        self::assertStringContainsString('AuditService::logWithinTransaction(', $supply);
        self::assertStringContainsString('public static function publish(array $params): array|false', $supply);
        self::assertStringContainsString("'purchase_batch_id' => (int)(\$item['purchase_batch_id'] ?? 0)", $supply);
        self::assertStringContainsString('PurchaseBatchLogic::refreshSummaryWithinTransaction', $supply);
        self::assertStringContainsString('采购批次子进货单不可变更供应商', $supply);
        self::assertStringContainsString('采购批次子进货单不可删除', $supply);
        self::assertStringContainsString('仓库、采购日期和总备注由采购批次统一维护', $supply);
        $matrix = $this->read('app/api/jxc/logic/GoodsSupplierMatrixLogic.php');
        self::assertStringContainsString("available_for_purchase'] ?? 0) === 1", $matrix);
        self::assertStringContainsString("->where('gs.status', 1)->where('v.is_disabled', 0)", $matrix);
    }

    public function test_batch_idempotency_and_parent_child_schema_keep_financial_facts_on_supply_orders(): void
    {
        $logic = $this->read('app/api/jxc/logic/PurchaseBatchLogic.php');
        $migration = $this->read('database/migrations/20260827_000001_create_purchase_batch.sql');

        self::assertStringContainsString('idempotency_key', $logic);
        self::assertStringContainsString('request_fingerprint', $logic);
        self::assertStringContainsString('IDEMPOTENCY_KEY_REUSED', $logic);
        self::assertStringContainsString('isRetryableTransactionError', $logic);
        self::assertStringContainsString('replayAfterConcurrentCommit', $logic);
        self::assertStringNotContainsString("where('idempotency_key', \$idempotencyKey)\n                        ->lock(true)", $logic);

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_batch`', $migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_batch_supply_order`', $migration);
        self::assertStringContainsString('uk_tenant_purchase_batch_idempotency', $migration);
        self::assertStringContainsString('`purchase_batch_id`', $migration);
        self::assertStringNotContainsString('order_pay_money', $migration);
        self::assertStringNotContainsString('order_paid_money', $migration);
    }

    private function read(string $relativePath): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }
}
