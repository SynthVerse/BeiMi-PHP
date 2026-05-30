<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class CloudGoodsValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'cloud_goods_id' => 'require|integer|gt:0',
        'unit_id' => 'require|integer|gt:0',
        'units_id' => 'integer|gt:0',
        'category_id' => 'integer|egt:0',
        'primary_supplier_id' => 'integer|egt:0',
        'supplier_id' => 'integer|egt:0',
        'scope' => 'max:20',
        'keyword' => 'max:100',
        'name' => 'max:100',
    ];

    protected $field = [
        'id' => '云端商品ID',
        'cloud_goods_id' => '云端商品ID',
        'unit_id' => '单位ID',
        'units_id' => '单位ID',
        'category_id' => '分类ID',
        'primary_supplier_id' => '供应商ID',
        'supplier_id' => '供应商ID',
        'scope' => '商品库类型',
        'keyword' => '关键词',
        'name' => '商品名称',
    ];

    public function sceneIndex()
    {
        return $this->only(['scope', 'keyword', 'name']);
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
