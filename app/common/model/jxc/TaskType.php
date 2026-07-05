<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class TaskType extends BaseModel
{
    protected $name = 'task_type';

    public const PROCUREMENT = 'procurement';
    public const DELIVERY = 'delivery';
    public const PACKING = 'packing';
    public const SALES_CONVERT = 'sales_convert';
}
