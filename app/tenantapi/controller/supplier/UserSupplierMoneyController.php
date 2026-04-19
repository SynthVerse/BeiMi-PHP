<?php
namespace app\tenantapi\controller\supplier;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\supplier\UserSupplierMoneyLists;
use app\tenantapi\logic\supplier\UserSupplierMoneyLogic;
use app\tenantapi\validate\supplier\UserSupplierMoneyValidate;


/**
 * UserSupplierMoney控制器
 * Class UserSupplierMoneyController
 * @package app\tenantapi\controller\supplier
 */
class UserSupplierMoneyController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function lists()
    {
        return $this->dataLists(new UserSupplierMoneyLists());
    }


    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function delete()
    {
        $params = (new UserSupplierMoneyValidate())->post()->goCheck('delete');
        UserSupplierMoneyLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 获取详情
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function detail()
    {
        $params = (new UserSupplierMoneyValidate())->goCheck('detail');
        $result = UserSupplierMoneyLogic::detail($params);
        return $this->data($result);
    }


}