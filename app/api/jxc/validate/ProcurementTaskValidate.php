<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class ProcurementTaskValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'goods_id' => 'require|integer|gt:0',
        'warehouse_id' => 'integer|egt:0',
        'sku_id' => 'integer|egt:0',
        'spec_id' => 'integer|egt:0',
        'required_num' => 'require|float|gt:0',
        'close_reason' => 'max:500',
        'reason' => 'max:500',
    ];

    public function sceneManualCreate()
    {
        return $this->only(['goods_id', 'warehouse_id', 'sku_id', 'spec_id', 'required_num']);
    }

    public function sceneStart()
    {
        return $this->only(['id']);
    }

    public function sceneClose()
    {
        return $this->only(['id', 'close_reason', 'reason']);
    }

    public function sceneCancel()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }
}
