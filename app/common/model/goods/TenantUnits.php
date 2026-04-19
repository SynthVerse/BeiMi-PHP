<?php
namespace app\common\model\goods;


use app\common\model\BaseModel;



/**
 * TenantUnits模型
 * Class TenantUnits
 * @package app\common\model
 */
class TenantUnits extends BaseModel
{
    
    protected $name = 'tenant_units';

    public function getIsShowDescAttr($value, $data)
    {
        return $data['is_show'] ? '停用' : '启用';
    }

    /**
     * @notes 搜索器-租户id
     * @param $query
     * @param $value
     * @param $data
     * @return void
     * @author JXDN
     * @date 2024/09/06 11:25
     */
    public function searchTenantIdAttr($query, $value, $data)
    {
        if ($value) {
            $query->where('tenant_id', '=', $value);
        }
    }
}