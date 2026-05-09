<?php
namespace app\common\model\goods;


use app\common\model\BaseModel;


/**
 * 商品单位换算配置模型
 * Class TenantProductUnitConversion
 * @package app\common\model\goods
 */
class TenantProductUnitConversion extends BaseModel
{

    protected $name = 'tenant_product_unit_conversion';

    // 不使用软删除
    protected $deleteTime = false;
}
