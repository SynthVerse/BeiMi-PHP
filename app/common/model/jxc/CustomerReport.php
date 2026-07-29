<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

class CustomerReport extends BaseModel
{
    use SoftDelete;

    protected $name = 'customer_report';
    protected $deleteTime = 'delete_time';
}
