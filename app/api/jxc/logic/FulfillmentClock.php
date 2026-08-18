<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 履约事实使用的统一服务端时钟；测试可在隔离环境冻结。 */
final class FulfillmentClock
{
    private static ?int $frozenNow = null;

    public static function now(): int
    {
        return self::$frozenNow ?? time();
    }

    public static function freezeForTesting(?int $timestamp): void
    {
        if (($_SERVER['JXC_PHPUNIT_ENV'] ?? '') !== 'testing') {
            throw new \LogicException('fulfillment_clock_can_only_be_frozen_in_tests');
        }
        self::$frozenNow = $timestamp;
    }
}
