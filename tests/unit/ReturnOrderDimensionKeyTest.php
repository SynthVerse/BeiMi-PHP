<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\SalesReturnOrderLogic;
use app\common\model\jxc\SalesOrder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReturnOrderDimensionKeyTest extends TestCase
{
    public function testSalesReturnKeyPrefersOriginalSalesOrderListId(): void
    {
        $method = (new ReflectionClass(SalesReturnOrderLogic::class))->getMethod('salesReturnDimensionKey');
        $method->setAccessible(true);

        $key = $method->invoke(null, [
            'original_sales_order_list_id' => 987,
            'order_goods_id' => 654,
            'goods_id' => 12,
            'sku_id' => 34,
        ]);

        self::assertSame('line:987', $key);
    }

    public function testSalesReturnKeyFallsBackToGoodsAndSku(): void
    {
        $method = (new ReflectionClass(SalesReturnOrderLogic::class))->getMethod('salesReturnDimensionKey');
        $method->setAccessible(true);

        self::assertSame('goods:12:34', $method->invoke(null, [
            'goods_id' => 12,
            'sku_id' => 34,
        ]));
        self::assertSame('goods:12:0', $method->invoke(null, [
            'goods_id' => 12,
        ]));
    }

    public function testSalesReturnKeyDoesNotTreatReturnRowIdAsOriginalLineId(): void
    {
        $method = (new ReflectionClass(SalesReturnOrderLogic::class))->getMethod('salesReturnDimensionKey');
        $method->setAccessible(true);

        self::assertSame('goods:12:34', $method->invoke(null, [
            'order_goods_id' => 987,
            'goods_id' => 12,
            'sku_id' => 34,
        ]));
    }

    public function testSalesReturnReturnedQtyDoesNotMixDifferentSkusForSameGoods(): void
    {
        $method = (new ReflectionClass(SalesReturnOrderLogic::class))->getMethod('salesReturnReturnedQtyForOrigin');
        $method->setAccessible(true);

        $returnedMap = [
            'goods:10:1' => '5.0000',
        ];

        self::assertSame('5.0000', $method->invoke(null, [
            'id' => 101,
            'goods_id' => 10,
            'sku_id' => 1,
        ], $returnedMap));
        self::assertSame('0.0000', $method->invoke(null, [
            'id' => 102,
            'goods_id' => 10,
            'sku_id' => 2,
        ], $returnedMap));
    }

    public function testSalesReturnStatusRecalculationUsesSkuDimension(): void
    {
        $method = (new ReflectionClass(SalesReturnOrderLogic::class))->getMethod('salesReturnStatusFromRows');
        $method->setAccessible(true);

        $originalRows = [
            ['id' => 101, 'goods_id' => 10, 'sku_id' => 1, 'number' => '5.0000'],
            ['id' => 102, 'goods_id' => 10, 'sku_id' => 2, 'number' => '5.0000'],
        ];

        self::assertSame(SalesOrder::STATUS_SOLD, $method->invoke(null, $originalRows, []));
        self::assertSame(SalesOrder::STATUS_PART_RETURNED, $method->invoke(null, $originalRows, [
            'goods:10:1' => '5.0000',
        ]));
        self::assertSame(SalesOrder::STATUS_RETURNED, $method->invoke(null, $originalRows, [
            'goods:10:1' => '5.0000',
            'goods:10:2' => '5.0000',
        ]));
    }

    public function testSalesReturnStatusRecalculationDoesNotMixSameGoodsSkuAcrossOriginalLines(): void
    {
        $reflection = new ReflectionClass(SalesReturnOrderLogic::class);
        $mapMethod = $reflection->getMethod('salesReturnReturnedQtyMapFromRows');
        $mapMethod->setAccessible(true);
        $statusMethod = $reflection->getMethod('salesReturnStatusFromRows');
        $statusMethod->setAccessible(true);

        $originalRows = [
            ['id' => 101, 'goods_id' => 10, 'sku_id' => 1, 'number' => '5.0000'],
            ['id' => 102, 'goods_id' => 10, 'sku_id' => 1, 'number' => '5.0000'],
        ];
        $returnedRows = [
            ['original_sales_order_list_id' => 101, 'goods_id' => 10, 'sku_id' => 1, 'number' => '5.0000'],
        ];

        $returnedMap = $mapMethod->invoke(null, $returnedRows);

        self::assertSame([
            'line:101' => '5.0000',
        ], $returnedMap);
        self::assertSame(SalesOrder::STATUS_PART_RETURNED, $statusMethod->invoke(null, $originalRows, $returnedMap));
    }
}
