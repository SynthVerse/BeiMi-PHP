<?php

namespace app\tenantapi\validate\goods;

use app\common\validate\BaseValidate;

class CloudGoodsValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require',
        'cloud_goods_id' => 'require|integer|gt:0',
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
        'scope' => 'max:20',
        'unit_id' => 'integer|gt:0',
        'units_id' => 'integer|gt:0',
        'category_id' => 'integer|egt:0',
        'primary_supplier_id' => 'integer|egt:0',
        'supplier_id' => 'integer|egt:0',
    ];

    protected $field = [
        'id' => '云端商品ID',
        'cloud_goods_id' => '云端商品ID',
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
        'scope' => '商品库类型',
        'unit_id' => '单位ID',
        'units_id' => '单位ID',
        'category_id' => '分类ID',
        'primary_supplier_id' => '供应商ID',
        'supplier_id' => '供应商ID',
    ];

    public function sceneLists()
    {
        return $this->only(['keyword', 'status', 'scope']);
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

    public function sceneLoad()
    {
        return $this->only(['cloud_goods_id', 'unit_id', 'units_id', 'category_id', 'primary_supplier_id', 'supplier_id'])
            ->remove('unit_id', 'require');
    }
}
