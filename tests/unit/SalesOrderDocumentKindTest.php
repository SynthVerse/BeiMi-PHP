<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\SalesOrderLogic;
use PHPUnit\Framework\TestCase;

final class SalesOrderDocumentKindTest extends TestCase
{
    public function test_customer_report_sales_order_is_a_customer_settlement_document(): void
    {
        self::assertSame('customer_settlement', SalesOrderLogic::salesDocumentKind([
            'source_type' => 'customer_report',
            'settlement_status' => 'pending',
        ]));
        self::assertSame('customer_settlement', SalesOrderLogic::salesDocumentKind([
            'source_type' => 'customer_report',
            'settlement_status' => 'formal',
        ]));
    }

    public function test_direct_sales_order_is_a_direct_receipt_document(): void
    {
        self::assertSame('direct_receipt', SalesOrderLogic::salesDocumentKind([
            'source_type' => '',
            'settlement_status' => 'formal',
        ]));
    }
}
