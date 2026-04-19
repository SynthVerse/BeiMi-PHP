<?php

namespace app\tenantapi\validate\goods;

use app\common\validate\BaseValidate;


/**
 * TenantGoods验证器
 * Class TenantGoodsValidate
 * @package app\platform\validate
 */
class TenantGoodsValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
        'name' => 'require',
        'units' => 'require',
        'moneys' => 'require',
        'sort' => 'require',
        'is_show' => 'require',
        'cate_id' => 'require',
    ];


    /**
     * 参数描述
     * @var string[]
     */
    protected $field = [
        'id' => 'id',
        'name' => '商品名称',
        'units' => '单位',
        'moneys' => '单价',
        'sort' => '排序',
        'is_show' => '是否显示',
        'cate_id' => '商品分类'
    ];


    /**
     * @notes 添加场景
     * @return TenantGoodsValidate
     * @author likeadmin
     * @date 2025/12/04 14:21
     */
    public function sceneAdd()
    {
        return $this->only(['cate_id', 'name', 'units', 'moneys', 'sort', 'is_show']);
    }


    /**
     * @notes 编辑场景
     * @return TenantGoodsValidate
     * @author likeadmin
     * @date 2025/12/04 14:21
     */
    public function sceneEdit()
    {
        return $this->only(['id', 'cate_id', 'name', 'units', 'moneys', 'sort', 'is_show']);
    }


    /**
     * @notes 删除场景
     * @return TenantGoodsValidate
     * @author likeadmin
     * @date 2025/12/04 14:21
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return TenantGoodsValidate
     * @author likeadmin
     * @date 2025/12/04 14:21
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

}