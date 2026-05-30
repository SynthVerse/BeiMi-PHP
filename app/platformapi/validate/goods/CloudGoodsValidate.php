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
        'units' => 'requireWithout:unit|max:50',
        'unit' => 'requireWithout:units|max:50',
        'price' => 'float|egt:0',
        'units_money' => 'float|egt:0',
        'cost' => 'float|egt:0',
        'purchase_price' => 'float|egt:0',
        'stock' => 'float|egt:0',
        'category_name' => 'max:100',
        'supplier_name' => 'max:100',
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
        'units' => '单位',
        'unit' => '单位',
        'price' => '销售价格',
        'units_money' => '销售价格',
        'cost' => '成本价',
        'purchase_price' => '成本价',
        'stock' => '库存',
        'category_name' => '分类名称',
        'supplier_name' => '供应商名称',
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
            'units',
            'unit',
            'price',
            'units_money',
            'cost',
            'purchase_price',
            'stock',
            'category_name',
            'supplier_name',
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
            'units',
            'unit',
            'price',
            'units_money',
            'cost',
            'purchase_price',
            'stock',
            'category_name',
            'supplier_name',
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
