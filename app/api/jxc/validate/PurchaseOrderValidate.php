<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class PurchaseOrderValidate extends BaseValidate
{
    protected $rule = [
        'id'             => 'require|integer|gt:0',
        'customer_id'    => 'require|integer|gt:0',
        'goods'          => 'require|array',
        'order_sn'       => 'max:64',
        'warehouse_id'   => 'integer|egt:0',
        'datetimesingle' => 'integer|egt:0',
        'predicted_date' => 'integer|egt:0',
        'order_money'    => 'float|egt:0',
        'order_pay_money'=> 'float|egt:0',
        'remarks'        => 'max:500',
        'remark'         => 'max:500',
        'cancel_reason'  => 'max:500',
        'target_status'  => 'integer|gt:0',
        'start_time'     => 'integer|egt:0',
        'end_time'       => 'integer|egt:0',
        'pastedText'     => 'require|max:5000',
    ];

    protected $field = [
        'id'             => '订货单ID',
        'customer_id'    => '客户ID',
        'goods'          => '商品明细',
        'order_sn'       => '订货单号',
        'warehouse_id'   => '仓库ID',
        'datetimesingle' => '单据日期',
        'predicted_date' => '预计交货日期',
        'order_money'    => '订单金额',
        'order_pay_money'=> '已付金额',
        'remarks'        => '备注',
        'remark'         => '备注',
        'cancel_reason'  => '取消原因',
        'target_status'  => '目标状态',
        'start_time'     => '开始时间',
        'end_time'       => '结束时间',
        'pastedText'     => '粘贴文本',
    ];

    public function scenePublish()
    {
        return $this->only([
            'customer_id',
            'goods',
            'order_sn',
            'warehouse_id',
            'datetimesingle',
            'predicted_date',
            'order_pay_money',
            'remarks',
            'remark',
        ]);
    }

    public function sceneEdit()
    {
        return $this->only([
            'id',
            'customer_id',
            'goods',
            'order_sn',
            'warehouse_id',
            'datetimesingle',
            'predicted_date',
            'order_pay_money',
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

    public function sceneConfirm()
    {
        return $this->only(['id', 'target_status']);
    }

    public function sceneCancel()
    {
        return $this->only(['id', 'cancel_reason']);
    }

    public function sceneConvertToSalesOrder()
    {
        return $this->only(['id', 'warehouse_id']);
    }

    public function sceneParsePastedText()
    {
        return $this->only(['pastedText']);
    }

    public function sceneStatistics()
    {
        return $this->only(['start_time', 'end_time']);
    }
}
