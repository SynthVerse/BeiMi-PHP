<?php
namespace app\common\model\supplier;


use app\common\model\BaseModel;



/**
 * UserSupplier模型
 * Class UserSupplier
 * @package app\common\model\supplier
 */
class UserSupplier extends BaseModel
{

    protected $name = 'user_supplier';

    /**
     * @notes 搜索器-用户信息
     * @param $query
     * @param $value
     * @param $data
     * @author 段誉
     * @date 2022/9/22 16:12
     */
    public function searchKeywordAttr($query, $value, $data)
    {
        if ($value) {
            $query->where('name', 'like', '%' . $value . '%');
        }
    }

}