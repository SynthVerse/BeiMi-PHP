<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class ReceivableFlow extends BaseModel
{
    protected $name = 'receivable_flow';

    // 流水类型常量
    const TYPE_SALES_ADD    = 1; // 应收增加-销售
    const TYPE_PAYMENT      = 2; // 应收减少-收款
    const TYPE_RETURN_REDUCE = 3; // 应收减少-退货
    const TYPE_ORDER_ROLLBACK = 4; // 销售单回滚
    const TYPE_RETURN_ROLLBACK = 5; // 销售退货单回滚
}
