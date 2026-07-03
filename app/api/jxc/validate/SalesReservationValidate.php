<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class SalesReservationValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'customer_id' => 'integer|egt:0',
        'customer_name' => 'max:100',
        'items' => 'array',
        'goods' => 'array',
        'remark' => 'max:500',
        'keyword' => 'max:100',
        'status' => 'max:32',
    ];

    public function sceneSubmit()
    {
        return $this->only(['customer_id', 'customer_name', 'items', 'goods', 'remark']);
    }

    public function sceneCancel()
    {
        return $this->only(['id']);
    }

    public function sceneConvertSales()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }
}
