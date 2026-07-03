<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class InventoryReservation extends BaseModel
{
    protected $name = 'inventory_reservation';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_RELEASED = 'released';
}
