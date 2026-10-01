<?php

declare(strict_types=1);

namespace app\common\model\jxc;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

final class DeliveryVehicle extends BaseModel
{
    use SoftDelete;

    protected $name = 'delivery_vehicle';
    protected $deleteTime = 'delete_time';
}
