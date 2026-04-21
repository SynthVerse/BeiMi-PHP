<?php

declare(strict_types=1);

namespace tests\unit;

use app\common\model\jxc\PurchaseOrder;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderStateTest extends TestCase
{
    public function testKnownStatusTextsRemainStable(): void
    {
        self::assertSame('draft', PurchaseOrder::getStatusText(PurchaseOrder::STATUS_DRAFT));
        self::assertSame('sent', PurchaseOrder::getStatusText(PurchaseOrder::STATUS_SENT));
        self::assertSame('received', PurchaseOrder::getStatusText(PurchaseOrder::STATUS_RECEIVED));
        self::assertSame('delivered', PurchaseOrder::getStatusText(PurchaseOrder::STATUS_DELIVERED));
        self::assertSame('completed', PurchaseOrder::getStatusText(PurchaseOrder::STATUS_COMPLETED));
        self::assertSame('cancelled', PurchaseOrder::getStatusText(PurchaseOrder::STATUS_CANCELLED));
        self::assertSame('unknown', PurchaseOrder::getStatusText(999));
    }

    /**
     * @dataProvider allowedTransitionProvider
     */
    public function testAllowedTransitions(int $currentStatus, int $newStatus): void
    {
        self::assertTrue(PurchaseOrder::canTransitionTo($currentStatus, $newStatus));
    }

    public static function allowedTransitionProvider(): array
    {
        return [
            'draft to sent' => [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT],
            'draft to cancelled' => [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CANCELLED],
            'sent to received' => [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_RECEIVED],
            'sent to delivered' => [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_DELIVERED],
            'sent to cancelled' => [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_CANCELLED],
            'received to delivered' => [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_DELIVERED],
            'received to cancelled' => [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELLED],
            'delivered to completed' => [PurchaseOrder::STATUS_DELIVERED, PurchaseOrder::STATUS_COMPLETED],
            'delivered to cancelled' => [PurchaseOrder::STATUS_DELIVERED, PurchaseOrder::STATUS_CANCELLED],
        ];
    }

    /**
     * @dataProvider blockedTransitionProvider
     */
    public function testBlockedTransitions(int $currentStatus, int $newStatus): void
    {
        self::assertFalse(PurchaseOrder::canTransitionTo($currentStatus, $newStatus));
    }

    public static function blockedTransitionProvider(): array
    {
        return [
            'draft cannot complete directly' => [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_COMPLETED],
            'sent cannot go back to draft' => [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_DRAFT],
            'received cannot go back to sent' => [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_SENT],
            'completed cannot move again' => [PurchaseOrder::STATUS_COMPLETED, PurchaseOrder::STATUS_CANCELLED],
            'cancelled cannot move again' => [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_SENT],
            'unknown current status is blocked' => [999, PurchaseOrder::STATUS_SENT],
        ];
    }
}
