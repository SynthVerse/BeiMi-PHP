<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class ProcurementTask extends BaseModel
{
    protected $name = 'procurement_task';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PURCHASING = 'purchasing';
    public const STATUS_PARTIAL_ARRIVED = 'partial_arrived';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';
}
