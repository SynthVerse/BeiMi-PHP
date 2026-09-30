<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class CustomerValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'customer_id' => 'integer|gt:0',
        'store_id' => 'integer|gt:0',
        'parent_id' => 'integer|egt:0',
        'customer_type' => 'max:30|in:customer,store,parent,master,independent,independent_customer,sub_customer,child,children',
        'customer_type_label' => 'max:20',
        'customer_name' => 'require|max:100',
        'contact' => 'max:50',
        'phone' => 'max:20',
        'customer_mobile' => 'max:20',
        'address' => 'max:255',
        'remark' => 'max:500',
        'group_id' => 'integer|egt:0',
        'group_name' => 'max:100',
        'status' => 'max:20',
        'is_disabled' => 'integer|in:0,1',
        'cascade_children' => 'in:0,1,true,false',
        'cascade_disable_children' => 'in:0,1,true,false',
        'cascade_enable_children' => 'in:0,1,true,false',
        'money' => 'float|egt:0',
        'amount' => 'float|egt:0',
        'pay_type' => 'max:50',
        'page' => 'integer|gt:0',
        'pagesize' => 'integer|gt:0',
        'earliest_delivery_time' => 'max:5',
        'plate_number' => 'max:32',
        'vehicle_location' => 'max:255',
        'driver_phone' => 'max:20',
        'sort' => 'integer',
        'enabled_only' => 'integer|in:0,1',
        'version' => 'integer|gt:0',
    ];

    protected $field = [
        'id' => '客户ID',
        'customer_id' => '客户ID',
        'store_id' => '子客户ID',
        'parent_id' => '父级客户ID',
        'customer_type' => '客户类型',
        'customer_type_label' => '客户类型标签',
        'customer_name' => '客户名称',
        'contact' => '联系人',
        'phone' => '联系电话',
        'customer_mobile' => '联系电话',
        'address' => '地址',
        'remark' => '备注',
        'group_id' => '分组ID',
        'group_name' => '分组名称',
        'status' => '客户状态',
        'is_disabled' => '禁用状态',
        'money' => '付款金额',
        'amount' => '付款金额',
        'pay_type' => '付款方式',
        'earliest_delivery_time' => '最早送货时间',
        'plate_number' => '车辆车牌号',
        'vehicle_location' => '车辆地点',
        'driver_phone' => '司机电话',
    ];

    public function sceneAdd()
    {
        return $this->only([
            'customer_name',
            'contact',
            'phone',
            'customer_mobile',
            'address',
            'remark',
            'group_id',
            'group_name',
            'parent_id',
            'customer_type',
            'customer_type_label',
            'is_disabled',
            'status',
        ]);
    }

    public function sceneEdit()
    {
        return $this->only([
            'id',
            'customer_name',
            'contact',
            'phone',
            'customer_mobile',
            'address',
            'remark',
            'group_id',
            'group_name',
            'parent_id',
            'customer_type',
            'customer_type_label',
            'is_disabled',
            'status',
        ])->remove('customer_name', 'require');
    }

    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    public function sceneChildren()
    {
        return $this->only(['parent_id'])
            ->append('parent_id', 'require');
    }

    public function sceneSummary()
    {
        return $this->only(['parent_id'])
            ->append('parent_id', 'require');
    }

    public function sceneBindStore()
    {
        return $this->only(['parent_id', 'store_id'])
            ->append('parent_id', 'require')
            ->append('store_id', 'require');
    }

    public function sceneUnbindStore()
    {
        return $this->only(['id', 'store_id'])
            ->remove('id', 'require');
    }

    public function sceneAssignGroup()
    {
        return $this->only(['id', 'customer_id', 'group_id', 'group_name'])
            ->remove('id', 'require');
    }

    public function sceneStatus()
    {
        return $this->only([
            'id',
            'status',
            'is_disabled',
            'cascade_children',
            'cascade_disable_children',
            'cascade_enable_children',
        ]);
    }

    public function scenePaymoney()
    {
        return $this->only(['customer_id', 'money', 'amount', 'pay_type', 'remark'])
            ->append('customer_id', 'require');
    }

    public function sceneSalesHistory()
    {
        return $this->only(['customer_id', 'page', 'pagesize'])
            ->append('customer_id', 'require');
    }

    public function sceneReceivableSummary()
    {
        return $this->only(['page', 'pagesize', 'status', 'keyword', 'name']);
    }

    public function sceneDeliveryVehicles()
    {
        return $this->only(['customer_id', 'enabled_only'])
            ->append('customer_id', 'require');
    }

    public function sceneDeliveryVehicleSave()
    {
        return $this->only([
            'id',
            'version',
            'customer_id',
            'earliest_delivery_time',
            'plate_number',
            'vehicle_location',
            'driver_phone',
            'sort',
            'is_enabled',
        ])->remove('id', 'require')
            ->append('customer_id', 'require')
            ->append('earliest_delivery_time', 'require')
            ->append('plate_number', 'require')
            ->append('vehicle_location', 'require');
    }

    public function sceneDeliveryVehicleDelete()
    {
        return $this->only(['id', 'version'])
            ->append('version', 'require');
    }
}
