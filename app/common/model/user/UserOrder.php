<?php

namespace app\common\model\user;


use app\common\model\auth\TenantAdmin;
use app\common\model\BaseModel;
use app\common\model\supplier\UserSupplier;


/**
 * UserOrder模型
 * Class UserOrder
 * @package app\common\model\user
 */
class UserOrder extends BaseModel
{

    protected $name = 'user_order';

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id')->field(["id", "real_name"]);
    }

    public function admin()
    {
        return $this->hasOne(TenantAdmin::class, 'id', 'admin_id')->field(["id", "name"]);
    }
}