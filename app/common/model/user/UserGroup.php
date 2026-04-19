<?php
namespace app\common\model\user;


use app\common\model\BaseModel;
use think\model\concern\SoftDelete;


/**
 * UserGroup模型
 * Class UserGroup
 * @package app\common\model
 */
class UserGroup extends BaseModel
{
    protected $name = 'user_group';

    public function getIsShowDescAttr($value, $data)
    {
        return $data['is_show'] ? '停用' : '启用';
    }
}