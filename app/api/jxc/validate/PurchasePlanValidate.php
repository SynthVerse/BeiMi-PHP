<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class PurchasePlanValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'task_ids' => 'require|array|min:1',
        'purchase_batch_id' => 'require|integer|gt:0',
        'allocations' => 'array',
        'surplus_qty' => 'require',
        'reason' => 'require|max:500',
        'page_no' => 'integer|egt:1',
        'page_size' => 'integer|between:1,100',
        'status' => 'in:pending,partial,complete,terminated',
    ];

    public function sceneLists() { return $this->only(['page_no', 'page_size', 'status']); }
    public function sceneDetail() { return $this->only(['id']); }
    public function sceneArrivalPreview() { return $this->only(['id', 'purchase_batch_id']); }
    public function sceneCreate() { return $this->only(['task_ids']); }
    public function sceneAttachArrival() { return $this->only(['id', 'purchase_batch_id', 'allocations', 'surplus_qty']); }
    public function sceneTerminate() { return $this->only(['id', 'reason']); }
}
