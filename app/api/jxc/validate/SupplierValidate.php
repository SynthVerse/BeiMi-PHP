<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class SupplierValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'supplier_name' => 'require|max:100',
        'contact' => 'max:50',
        'phone' => 'max:20',
        'address' => 'max:255',
        'remark' => 'max:500',
        'is_disabled' => 'integer|in:0,1',
        'supplier_id' => 'integer|gt:0',
        'money' => 'float|egt:0',
        'amount' => 'float|egt:0',
    ];

    protected $field = [
        'id' => '供应商ID',
        'supplier_name' => '供应商名称',
        'contact' => '联系人',
        'phone' => '联系电话',
        'address' => '地址',
        'remark' => '备注',
        'is_disabled' => '禁用状态',
        'supplier_id' => '供应商ID',
        'money' => '付款金额',
        'amount' => '付款金额',
    ];

    public function sceneAdd()
    {
        return $this->only(['supplier_name', 'contact', 'phone', 'address', 'remark', 'is_disabled']);
    }

    public function sceneEdit()
    {
        return $this->only(['id', 'supplier_name', 'contact', 'phone', 'address', 'remark', 'is_disabled']);
    }

    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    public function scenePaymoney()
    {
        return $this->only(['id', 'supplier_id', 'money', 'amount', 'remark'])
            ->remove('id', 'require');
    }
}
