<?php

namespace app\platformapi\validate\goods;

use app\common\validate\BaseValidate;

class CloudGoodsValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require',
        'name' => 'require|max:200',
        'product_name' => 'max:200',
        'product_code' => 'max:100',
        'category_id' => 'integer|egt:0',
        'is_disabled' => 'integer|in:0,1',
        'status' => 'integer|in:0,1',
        'sort' => 'integer|egt:0',
        'remark' => 'max:500',
        'keyword' => 'max:100',
    ];

    protected $field = [
        'id' => '云端商品ID',
        'name' => '商品名称',
        'product_name' => '商品名称',
        'product_code' => '商品编码',
        'category_id' => '分类ID',
        'is_disabled' => '停用状态',
        'status' => '状态',
        'sort' => '排序',
        'remark' => '备注',
        'keyword' => '关键词',
    ];

    public function sceneLists()
    {
        return $this->only(['keyword', 'status']);
    }

    public function sceneAdd()
    {
        return $this->only([
            'name',
            'product_name',
            'product_code',
            'category_id',
            'is_disabled',
            'status',
            'sort',
            'remark',
        ]);
    }

    public function sceneEdit()
    {
        return $this->only([
            'id',
            'name',
            'product_name',
            'product_code',
            'category_id',
            'is_disabled',
            'status',
            'sort',
            'remark',
        ]);
    }

    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }
}
