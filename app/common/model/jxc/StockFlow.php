<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class StockFlow extends BaseModel
{
    protected $name = 'stock_flow';

    // 流向常量
    const FLOW_IN  = 1; // 入库
    const FLOW_OUT = 2; // 出库
}
