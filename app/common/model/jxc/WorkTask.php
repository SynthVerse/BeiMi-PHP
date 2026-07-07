<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class WorkTask extends BaseModel
{
    protected $name = 'work_task';

    public const KIND_FULFILLMENT = 'fulfillment';
    public const KIND_PROCUREMENT = 'procurement';

    public const ROLE_PACKING = 'packing';
    public const ROLE_FISH_KILL = 'fish_kill';
    public const ROLE_PROCUREMENT = 'procurement';
    public const ROLE_MANAGER = 'manager';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ASSIGNED,
        self::STATUS_PROCESSING,
        self::STATUS_BLOCKED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const STOCK_ENOUGH = 'enough';
    public const STOCK_SHORTAGE = 'shortage';
    public const STOCK_PROCUREMENT_DONE = 'procurement_done';
}
