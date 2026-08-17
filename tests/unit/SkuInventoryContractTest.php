<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class SkuInventoryContractTest extends TestCase
{
    public function test_customer_report_and_stock_flows_use_the_sku_balance_module(): void
    {
        $root = dirname(__DIR__, 2);
        $customerReport = (string)file_get_contents($root . '/app/api/jxc/logic/CustomerReportLogic.php');
        $stock = (string)file_get_contents($root . '/app/api/jxc/logic/StockService.php');
        $sales = (string)file_get_contents($root . '/app/api/jxc/logic/SalesOrderLogic.php');
        $supply = (string)file_get_contents($root . '/app/api/jxc/logic/SupplyOrderLogic.php');
        $purchaseReturn = (string)file_get_contents($root . '/app/api/jxc/logic/PurchaseReturnOrderLogic.php');
        $salesReturn = (string)file_get_contents($root . '/app/api/jxc/logic/SalesReturnOrderLogic.php');
        $cloudGoods = (string)file_get_contents($root . '/app/common/service/cloud/CloudGoodsService.php');
        $baseSku = (string)file_get_contents($root . '/app/common/service/goods/GoodsBaseSkuService.php');
        $goodsLogic = (string)file_get_contents($root . '/app/api/jxc/logic/GoodsLogic.php');
        $skuReferences = (string)file_get_contents($root . '/app/common/service/goods/GoodsSkuReferenceService.php');
        $customerReportWorkflow = (string)file_get_contents($root . '/tests/unit/CustomerReportWorkflowTest.php');

        self::assertStringContainsString('WarehouseSkuBalanceService::', $customerReport);
        self::assertStringNotContainsString('WarehouseGoodsBalanceService::', $customerReport);
        self::assertStringContainsString("(int)\$item['sku_id']", $customerReport);
        self::assertStringContainsString('WarehouseSkuBalanceService::', $stock);
        self::assertStringNotContainsString('WarehouseGoodsBalanceService::', $stock);
        self::assertStringContainsString("\$movement['sku_id']", $stock);
        self::assertStringContainsString('WarehouseSkuBalanceService::transfer', $stock);
        self::assertSame(2, substr_count($this->methodSource(\app\api\jxc\logic\StockService::class, 'transfer'), 'self::writeFlow('));
        self::assertStringContainsString('GoodsSkuSelectionService::forSale', $sales);
        self::assertStringContainsString('GoodsSkuSelectionService::forPurchase', $supply);
        self::assertStringContainsString("(int)(\$row['sku_id'] ?? 0)", $purchaseReturn);
        self::assertStringContainsString("(int)(\$row['sku_id'] ?? 0)", $salesReturn);
        self::assertStringContainsString('GoodsBaseSkuService::ensure', $cloudGoods);
        self::assertStringContainsString("'dimension_disabled_snapshot' => 0", $baseSku);
        self::assertStringContainsString("Db::name('warehouse_sku_balance')", $goodsLogic);
        self::assertStringContainsString("'warehouse_sku_balance'", $skuReferences);
        self::assertStringContainsString("'customer_report_reservation'", $skuReferences);
        self::assertDoesNotMatchRegularExpression(
            '/WarehouseGoodsBalanceService::(?:inbound|available|onHand|reserved)\([^;]*customerReportSkuId/s',
            $customerReportWorkflow
        );
    }

    public function test_schema_keys_authoritative_balance_by_warehouse_and_sku(): void
    {
        $root = dirname(__DIR__, 2);
        $balanceMigration = (string)file_get_contents(
            $root . '/database/migrations/20260817_000002_create_warehouse_sku_balance.sql'
        );
        $dimensionMigration = (string)file_get_contents(
            $root . '/database/migrations/20260817_000001_create_goods_dimension_setting.sql'
        );

        self::assertStringContainsString(
            'UNIQUE KEY `uk_tenant_warehouse_sku_unit` (`tenant_id`, `warehouse_id`, `sku_id`, `base_unit_id`)',
            $balanceMigration
        );
        self::assertStringContainsString('`goods_id` int(11) UNSIGNED', $balanceMigration);
        self::assertStringContainsString('`sku_id` int(11) UNSIGNED', $balanceMigration);
        self::assertStringContainsString('`usage_mode` varchar(20)', $dimensionMigration);
        self::assertStringContainsString('UNIQUE KEY `uk_tenant_goods_spec`', $dimensionMigration);
        self::assertStringContainsString("CONCAT('SKU-', goods.`id`, '-BASE')", $dimensionMigration);
    }

    public function test_goods_fixture_keeps_base_sku_creation_inside_the_goods_method(): void
    {
        $goodsMethod = $this->methodSource(CustomerReportTestSupport::class, 'createCustomerReportGoods');
        $unitMethod = $this->methodSource(CustomerReportTestSupport::class, 'createCustomerReportUnit');

        self::assertStringContainsString('GoodsBaseSkuService::ensure', $goodsMethod);
        self::assertStringContainsString('return $goodsId;', $goodsMethod);
        self::assertLessThan(
            strpos($goodsMethod, 'return $goodsId;'),
            strpos($goodsMethod, 'GoodsBaseSkuService::ensure')
        );
        self::assertStringNotContainsString('GoodsBaseSkuService::ensure', $unitMethod);
        self::assertStringNotContainsString('$goodsId', $unitMethod);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file((string)$reflection->getFileName());
        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
