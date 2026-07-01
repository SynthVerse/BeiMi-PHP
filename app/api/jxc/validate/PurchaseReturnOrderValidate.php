<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class PurchaseReturnOrderValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'original_order_id' => 'integer|egt:0',
        'original_order_sn' => 'max:64',
        'supplier_id' => 'integer|egt:0',
        'warehouse_id' => 'integer|egt:0',
        'goods' => 'require|array',
        'order_sn' => 'max:64',
        'datetimesingle' => 'integer|egt:0',
        'return_reason' => 'max:500',
        'remarks' => 'max:500',
        'remark' => 'max:500',
        'idempotent_key' => 'max:64',
        'start_time' => 'integer|egt:0',
        'end_time' => 'integer|egt:0',
    ];

    protected $field = [
        'id' => '采购退货单ID',
        'original_order_id' => '原进货单ID',
        'original_order_sn' => '原进货单号',
        'supplier_id' => '供应商ID',
        'warehouse_id' => '仓库ID',
        'goods' => '商品明细',
        'order_sn' => '采购退货单号',
        'datetimesingle' => '单据日期',
        'return_reason' => '退货原因',
        'remarks' => '备注',
        'remark' => '备注',
        'idempotent_key' => '幂等键',
        'start_time' => '开始时间',
        'end_time' => '结束时间',
    ];

    public function scenePublish()
    {
        return $this->only([
            'original_order_id',
            'original_order_sn',
            'supplier_id',
            'warehouse_id',
            'goods',
            'order_sn',
            'datetimesingle',
            'return_reason',
            'remarks',
            'remark',
            'idempotent_key',
        ]);
    }

    public function sceneEdit()
    {
        return $this->only([
            'id',
            'original_order_id',
            'original_order_sn',
            'supplier_id',
            'warehouse_id',
            'goods',
            'order_sn',
            'datetimesingle',
            'return_reason',
            'remarks',
            'remark',
            'idempotent_key',
        ]);
    }

    public function sceneRemove()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    public function sceneLists()
    {
        return $this->only(['start_time', 'end_time']);
    }
}
