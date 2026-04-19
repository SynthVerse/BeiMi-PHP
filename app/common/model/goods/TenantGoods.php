<?php
namespace app\common\model\goods;


use app\common\model\BaseModel;



/**
 * TenantGoods模型
 * Class TenantGoods
 * @package app\common\model
 */

class TenantGoods extends BaseModel
{

    protected $name = 'tenant_goods';

    public function getIsShowDescAttr($value, $data)
    {
        return $data['is_show'] ? '停用' : '启用';
    }



    public function goodsCate()
    {
        return $this->hasOne(TenantGoodscat::class, 'id','cate_id')->field(['id','name']);
    }

    /**
     * @notes 搜索器-用户信息
     * @param $query
     * @param $value
     * @param $data
     * @author 段誉
     * @date 2022/9/22 16:12
     */
    public function searchNameAttr($query, $value, $data)
    {
        if ($value) {
            $query->where('name|short_name', 'like', '%' . $value . '%');
        }
    }

    public function searchCateIdAttr($query, $value, $data)
    {
        if ($value) {
            $query->where('cate_id', '=', $value);
        }
    }

}