<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

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
            "/if\\s*\\(\\s*!\\s*FinanceService::addReceivable\\s*\\(.+?\\)\\s*\\)\\s*\\{\\s*self::throwFailure\\s*\\(\\s*'旧应收回滚失败'\\s*,\\s*'RETURN_FINANCE_FAILED'\\s*\\)\\s*;/s",
            $body
        );
        self::assertLessThan(
            strpos($body, '$order->save'),
            strpos($body, 'FinanceService::addReceivable'),
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
            "/if\\s*\\(\\s*!\\s*FinanceService::addReceivable\\s*\\(.+?\\)\\s*\\)\\s*\\{\\s*self::throwFailure\\s*\\(\\s*'旧应收回滚失败'\\s*,\\s*'RETURN_FINANCE_FAILED'\\s*\\)\\s*;/s",
            $body
        );
        self::assertLessThan(
            strpos($body, 'OrderGoods::where'),
            strpos($body, 'FinanceService::addReceivable'),
            'old receivable rollback guard must run before deleting return data'
        );
    }

    private static function methodBody(string $method): string
    {
        $source = (string)file_get_contents(self::LOGIC_FILE);
        $start = strpos($source, 'public static function ' . $method);
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
