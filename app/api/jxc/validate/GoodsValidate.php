<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class GoodsValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'goods_id' => 'integer|gt:0',
        'name' => 'requireWithout:product_name|max:200',
        'product_name' => 'requireWithout:name|max:200',
        'product_code' => 'max:100',
        'units' => 'requireWithout:unit|max:50',
        'unit' => 'requireWithout:units|max:50',
        'unit_id' => 'integer|egt:0',
        'units_id' => 'integer|egt:0',
        'price' => 'float|egt:0',
        'units_money' => 'float|egt:0',
        'cost' => 'float|egt:0',
        'stock' => 'float|egt:0',
        'category_id' => 'integer|egt:0',
        'primary_supplier_id' => 'integer|egt:0',
        'supplier_id' => 'integer|gt:0',
        'sku_id' => 'integer|gt:0',
        'skus' => 'array',
        'relations' => 'array',
        'suppliers' => 'array',
        'supplier_relation' => 'max:20',
        'stock_status' => 'max:20',
        'status' => 'max:20',
        'is_disabled' => 'integer|in:0,1',
        'remark' => 'max:500',
    ];

    protected $field = [
        'id' => '商品ID',
        'goods_id' => '商品ID',
        'name' => '商品名称',
        'product_name' => '商品名称',
        'product_code' => '商品编码',
        'units' => '单位',
        'unit' => '单位',
        'unit_id' => '单位ID',
        'units_id' => '单位ID',
        'price' => '销售价格',
        'units_money' => '销售价格',
        'cost' => '成本价',
        'stock' => '库存',
        'category_id' => '分类ID',
        'primary_supplier_id' => '默认供应商ID',
        'supplier_id' => '供应商ID',
        'sku_id' => 'SKU ID',
        'skus' => 'SKU列表',
        'relations' => '供应商SKU矩阵',
        'suppliers' => '供应商列表',
        'supplier_relation' => '供应商关联类型',
        'stock_status' => '库存状态',
        'status' => '商品状态',
        'is_disabled' => '禁用状态',
        'remark' => '备注',
    ];

    public function sceneAdd()
    {
        return $this->only([
            'name',
            'product_name',
            'product_code',
            'units',
            'unit',
            'unit_id',
            'units_id',
            'price',
            'units_money',
            'cost',
            'stock',
            'category_id',
            'primary_supplier_id',
            'is_disabled',
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
            'unit_id',
            'units_id',
            'price',
            'units_money',
            'cost',
            'stock',
            'category_id',
            'primary_supplier_id',
            'is_disabled',
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

    public function sceneSuppliers()
    {
        return $this->only(['id', 'goods_id'])
            ->remove('id', 'require');
    }

    public function sceneSaveSuppliers()
    {
        return $this->only(['id', 'goods_id', 'primary_supplier_id', 'suppliers'])
            ->remove('id', 'require');
    }

    public function sceneSkus()
    {
        return $this->only(['id', 'goods_id'])
            ->remove('id', 'require');
    }

    public function sceneSaveSkus()
    {
        return $this->only(['id', 'goods_id', 'skus'])
            ->remove('id', 'require');
    }

    public function sceneSkuStatus()
    {
        return $this->only(['id', 'status']);
    }

    public function sceneSupplierMatrix()
    {
        return $this->only(['id', 'goods_id', 'sku_id', 'supplier_id', 'status'])
            ->remove('id', 'require');
    }

    public function sceneSaveSupplierMatrix()
    {
        return $this->only(['id', 'goods_id', 'relations', 'suppliers'])
            ->remove('id', 'require');
    }
}
