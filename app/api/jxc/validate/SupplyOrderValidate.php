<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class SupplyOrderValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'supplier_id' => 'require|integer|gt:0',
        'warehouse_id' => 'require',
        'goods' => 'require|array',
        'order_sn' => 'max:64',
        'datetimesingle' => 'integer|egt:0',
        'order_money' => 'float|egt:0',
        'order_pay_money' => 'float|egt:0',
        'remarks' => 'max:500',
        'remark' => 'max:500',
        'purpose' => 'max:50',
        'purpose_type' => 'max:50',
        'start_time' => 'integer|egt:0',
        'end_time' => 'integer|egt:0',
    ];

    protected $field = [
        'id' => '进货单ID',
        'supplier_id' => '供应商ID',
        'warehouse_id' => '仓库ID',
        'goods' => '商品明细',
        'order_sn' => '进货单号',
        'datetimesingle' => '单据日期',
        'order_money' => '订单金额',
        'order_pay_money' => '已付金额',
        'remarks' => '备注',
        'remark' => '备注',
        'purpose' => '入库目的',
        'purpose_type' => '入库目的类型',
        'start_time' => '开始时间',
        'end_time' => '结束时间',
    ];

    public function scenePublish()
    {
        return $this->only([
            'supplier_id',
            'warehouse_id',
            'goods',
            'order_sn',
            'datetimesingle',
            'order_money',
            'order_pay_money',
            'remarks',
            'remark',
            'purpose',
            'purpose_type',
        ]);
    }

    public function sceneEdit()
    {
        return $this->only([
            'id',
            'supplier_id',
            'warehouse_id',
            'goods',
            'order_sn',
            'datetimesingle',
            'order_money',
            'order_pay_money',
            'remarks',
            'remark',
            'purpose',
            'purpose_type',
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
