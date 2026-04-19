<?php
namespace app\common\model\user;


use app\common\model\auth\TenantAdmin;
use app\common\model\BaseModel;



/**
 * UserMoney模型
 * Class UserMoney
 * @package app\common\model\user
 */
class UserMoney extends BaseModel
{

    protected $name = 'user_money';

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id')->field(["id", "real_name"]);
    }


    public function admin()
    {
        return $this->hasOne(TenantAdmin::class, 'id', 'admin_id')->field(["id", "name"]);
    }
}