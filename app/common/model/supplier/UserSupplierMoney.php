<?php

namespace app\common\model\supplier;


use app\common\model\auth\TenantAdmin;
use app\common\model\BaseModel;


/**
 * UserSupplierMoney模型
 * Class UserSupplierMoney
 * @package app\common\model\supplier
 */
class UserSupplierMoney extends BaseModel
{

    protected $name = 'user_supplier_money';


    public function supplier()
    {
        return $this->hasOne(UserSupplier::class, 'id', 'supplier_id')->field(["id", "name"]);
    }

    public function admin()
    {
        return $this->hasOne(TenantAdmin::class, 'id', 'admin_id')->field(["id", "name"]);
    }


}