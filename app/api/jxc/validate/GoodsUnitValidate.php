<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class GoodsUnitValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'name' => 'require|max:10',
        'sort' => 'integer',
        'status' => 'integer|in:0,1',
        'goods_id' => 'integer|egt:0',
        'sku_id' => 'integer|egt:0',
        'supplier_id' => 'integer|egt:0',
        'from_unit_id' => 'integer|egt:0',
        'to_unit_id' => 'integer|egt:0',
        'date' => 'max:20',
        'rules' => 'array',
        'conversions' => 'array',
        'scope' => 'in:goods_daily,supplier_sku_daily',
    ];

    protected $field = [
        'id' => '单位ID',
        'name' => '单位名称',
        'sort' => '排序',
        'status' => '状态',
        'goods_id' => '商品ID',
        'sku_id' => 'SKU ID',
        'supplier_id' => '供应商ID',
        'from_unit_id' => '来源单位ID',
        'to_unit_id' => '目标单位ID',
        'date' => '换算日期',
        'rules' => '换算规则',
        'conversions' => '换算规则',
        'scope' => '保存范围',
    ];

    public function sceneAdd()
    {
        return $this->only(['name', 'sort', 'status']);
    }

    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'sort', 'status'])
            ->remove('name', 'require');
    }

    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    public function sceneConversionRules()
    {
        return $this->only(['goods_id', 'sku_id', 'supplier_id', 'from_unit_id', 'to_unit_id', 'status']);
    }

    public function sceneSaveConversionRules()
    {
        return $this->only(['goods_id', 'rules', 'conversions', 'scope']);
    }

    public function sceneDeleteConversionRule()
    {
        return $this->only(['id']);
    }

    public function sceneResolveConversion()
    {
        return $this->only(['goods_id', 'sku_id', 'supplier_id', 'from_unit_id', 'to_unit_id', 'date']);
    }

    public function sceneGoodsBaseUnit()
    {
        return $this->only(['goods_id']);
    }
}
