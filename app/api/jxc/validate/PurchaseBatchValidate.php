<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class PurchaseBatchValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'warehouse_id' => 'require|integer|gt:0',
        'datetimesingle' => 'require|integer|gt:0',
        'remarks' => 'max:500',
        'items' => 'require|array',
        'idempotency_key' => 'require|string|regex:/^[A-Za-z0-9._:-]{1,64}$/D',
        'purchase_plan_id' => 'integer|gt:0',
    ];

    protected $field = [
        'id' => '采购批次ID',
        'warehouse_id' => '入库仓库',
        'datetimesingle' => '采购日期',
        'remarks' => '总备注',
        'items' => '采购明细',
        'idempotency_key' => '幂等键',
    ];

    public function scenePublish()
    {
        return $this->only(['warehouse_id', 'datetimesingle', 'remarks', 'items', 'idempotency_key', 'purchase_plan_id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }
}
