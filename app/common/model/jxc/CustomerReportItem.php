<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

class CustomerReportItem extends BaseModel
{
    use SoftDelete;

    protected $name = 'customer_report_item';
    protected $deleteTime = 'delete_time';
}
