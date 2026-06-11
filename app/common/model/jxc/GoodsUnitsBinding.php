<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class GoodsUnitsBinding extends BaseModel
{
    protected $name = 'goods_units_binding';

    public function unit()
    {
        return $this->belongsTo(GoodsUnit::class, 'unit_id', 'id');
    }
}
