<?php
namespace app\tenantapi\controller\user;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\user\UserMoneyLists;
use app\tenantapi\logic\user\UserMoneyLogic;
use app\tenantapi\validate\user\UserMoneyValidate;


/**
 * UserMoney控制器
 * Class UserMoneyController
 * @package app\tenantapi\controller\user
 */
class UserMoneyController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function lists()
    {
        return $this->dataLists(new UserMoneyLists());
    }

    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function delete()
    {
        $params = (new UserMoneyValidate())->post()->goCheck('delete');
        UserMoneyLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 获取详情
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function detail()
    {
        $params = (new UserMoneyValidate())->goCheck('detail');
        $result = UserMoneyLogic::detail($params);
        return $this->data($result);
    }


}