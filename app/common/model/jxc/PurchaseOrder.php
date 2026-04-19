<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class PurchaseOrder extends BaseModel
{
    protected $name = 'purchase_order';

    // 状态常量
    const STATUS_DRAFT     = 1;
    const STATUS_SENT      = 2;
    const STATUS_RECEIVED  = 3;
    const STATUS_DELIVERED = 4;
    const STATUS_COMPLETED = 5;
    const STATUS_CANCELLED = 6;

    // 状态文本映射
    const STATUS_MAP = [
        self::STATUS_DRAFT     => 'draft',
        self::STATUS_SENT      => 'sent',
        self::STATUS_RECEIVED  => 'received',
        self::STATUS_DELIVERED => 'delivered',
        self::STATUS_COMPLETED => 'completed',
        self::STATUS_CANCELLED => 'cancelled',
    ];

    // 允许的状态转移
    const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT     => [self::STATUS_SENT, self::STATUS_CANCELLED],
        self::STATUS_SENT      => [self::STATUS_RECEIVED, self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_RECEIVED  => [self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_DELIVERED => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    public static function getStatusText(int $status): string
    {
        return self::STATUS_MAP[$status] ?? 'unknown';
    }

    public static function canTransitionTo(int $currentStatus, int $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$currentStatus] ?? []);
    }
}
