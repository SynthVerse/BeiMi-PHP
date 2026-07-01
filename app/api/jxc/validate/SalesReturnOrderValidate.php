<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class SalesReturnOrderValidate extends BaseValidate
{
    protected $rule = [
        'id'                    => 'require|integer|gt:0',
        'customer_id'           => 'require|integer|gt:0',
        'warehouse_id'          => 'require',
        'original_order_id'     => 'integer|egt:0',
        'original_order_sn'     => 'max:64',
        'goods'                 => 'require|array',
        'order_sn'              => 'max:64',
        'datetimesingle'        => 'integer|egt:0',
        'order_money'           => 'float|egt:0',
        'return_reason'         => 'max:500',
        'remarks'               => 'max:500',
        'remark'                => 'max:500',
        'start_time'            => 'integer|egt:0',
        'end_time'              => 'integer|egt:0',
    ];

    protected $field = [
        'id'                    => '退货单ID',
        'customer_id'           => '客户ID',
        'warehouse_id'          => '仓库ID',
        'original_order_id'     => '原销售单ID',
        'original_order_sn'     => '原销售单号',
        'goods'                 => '商品明细',
        'order_sn'              => '退货单号',
        'datetimesingle'        => '单据日期',
        'order_money'           => '退货金额',
        'return_reason'         => '退货原因',
        'remarks'               => '备注',
        'remark'                => '备注',
        'start_time'            => '开始时间',
        'end_time'              => '结束时间',
    ];

    public function scenePublish()
    {
        return $this->only([
            'customer_id',
            'warehouse_id',
            'original_order_id',
            'original_order_sn',
            'goods',
            'order_sn',
            'datetimesingle',
            'order_money',
            'return_reason',
            'remarks',
            'remark',
        ]);
    }

    public function sceneEdit()
    {
        return $this->only([
            'id',
            'customer_id',
            'warehouse_id',
            'original_order_id',
            'original_order_sn',
            'goods',
            'order_sn',
            'datetimesingle',
            'order_money',
            'return_reason',
            'remarks',
            'remark',
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

    public function sceneStatistics()
    {
        return $this->only(['start_time', 'end_time']);
    }
}
