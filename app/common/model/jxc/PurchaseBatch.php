<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

/** 一次多供应商采购录入的聚合父记录，不承载入库、应付或退货事实。 */
class PurchaseBatch extends BaseModel
{
    protected $name = 'purchase_batch';
}
