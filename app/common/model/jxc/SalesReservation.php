<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class SalesReservation extends BaseModel
{
    protected $name = 'sales_reservation';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SHORTAGE = 'shortage';
    public const STATUS_READY = 'ready';
    public const STATUS_GAP_CLOSED = 'gap_closed';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_CANCELLED = 'cancelled';
}
