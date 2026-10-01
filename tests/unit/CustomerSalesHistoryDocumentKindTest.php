<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class CustomerSalesHistoryDocumentKindTest extends TestCase
{
    public function test_sales_history_exposes_the_server_document_identity(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/app/api/jxc/logic/CustomerLogic.php'
        );

        self::assertMatchesRegularExpression(
            "/'source_type'\\s*=>\\s*\\(string\\)\\(\\\$item\\['source_type'\\]/",
            $source
        );
        self::assertMatchesRegularExpression(
            "/'document_kind'\\s*=>\\s*SalesOrderLogic::salesDocumentKind\\(\\\$item\\)/",
            $source
        );
    }
}
