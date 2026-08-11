<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class CustomerReportValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'version' => 'require|integer|gt:0',
        'main_customer_id' => 'require|integer|gt:0',
        'items' => 'require|array|min:1',
        'remark' => 'max:500',
        'idempotency_key' => 'require|max:96',
        'text' => 'require|max:5000',
        'name' => 'require|max:200',
        'category_id' => 'require|integer|gt:0',
        'unit_id' => 'require|integer|gt:0',
        'warehouse_id' => 'require|integer|gt:0',
        'goods_id' => 'require|integer|gt:0',
        'page_no' => 'integer|egt:1',
        'page_size' => 'integer|between:1,100',
        'status_scope' => 'in:pending,completed,cancelled',
        'delivery_date' => 'dateFormat:Y-m-d',
        'is_supplement' => 'in:0,1',
        'dimensions' => 'array',
        'combinations' => 'array',
    ];

    public function sceneRecognize() { return $this->only(['text']); }
    public function sceneQuickCreateGoods() { return $this->only(['name','category_id','unit_id','dimensions','combinations']); }
    public function sceneSubmit() { return $this->only(['main_customer_id','items','remark','idempotency_key','delivery_date','is_supplement']); }
    public function sceneDetail() { return $this->only(['id']); }
    public function sceneLists() { return $this->only(['page_no','page_size','status_scope']); }
    public function sceneAvailability() { return $this->only(['warehouse_id','goods_id']); }
    public function sceneEdit() { return $this->only(['id','version','main_customer_id','items','remark','delivery_date','is_supplement']); }
    public function sceneRetry() { return $this->only(['id','version']); }
    public function sceneConvert() { return $this->only(['id','version']); }
    public function sceneCancel() { return $this->only(['id','version']); }
}
