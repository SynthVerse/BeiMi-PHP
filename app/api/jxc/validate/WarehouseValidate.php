<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class WarehouseValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'name' => 'require|max:100',
        'province' => 'max:50',
        'city' => 'max:50',
        'district' => 'max:50',
        'address' => 'max:255',
        'address_detail' => 'max:255',
        'contact' => 'max:50',
        'phone' => 'max:20',
        'sort' => 'integer',
        'status' => 'integer|in:0,1',
    ];

    protected $field = [
        'id' => '仓库ID',
        'name' => '仓库名称',
        'province' => '省份',
        'city' => '城市',
        'district' => '区县',
        'address' => '仓库地址',
        'address_detail' => '详细地址',
        'contact' => '联系人',
        'phone' => '联系电话',
        'sort' => '排序',
        'status' => '状态',
    ];

    public function sceneAdd()
    {
        return $this->only(['name', 'province', 'city', 'district', 'address', 'address_detail', 'contact', 'phone', 'sort', 'status']);
    }

    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'province', 'city', 'district', 'address', 'address_detail', 'contact', 'phone', 'sort', 'status']);
    }

    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    public function sceneStatus()
    {
        return $this->only(['id']);
    }
}
