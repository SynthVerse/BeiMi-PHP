<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class PayableFlow extends BaseModel
{
    protected $name = 'payable_flow';

    // 流水类型常量
    const TYPE_SUPPLY_ADD    = 1; // 应付增加-进货
    const TYPE_PAYMENT       = 2; // 应付减少-付款
    const TYPE_RETURN_REDUCE = 3; // 应付减少-采购退货
}
