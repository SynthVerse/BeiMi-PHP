<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class TaskRole extends BaseModel
{
    protected $name = 'task_role';

    public const MANAGER = 'manager';
    public const PROCUREMENT = 'procurement';
    public const DELIVERY = 'delivery';
    public const PACKING = 'packing';
}
