<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class SalesReservationItem extends BaseModel
{
    protected $name = 'sales_reservation_item';

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_SHORTAGE = 'shortage';
    public const STATUS_GAP_CLOSED = 'gap_closed';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_RELEASED = 'released';
}
