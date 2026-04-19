<?php
namespace app\tenantapi\controller\user;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\user\UserLists;
use app\tenantapi\logic\user\UserLogic;
use app\tenantapi\logic\user\UserOrderLogic;
use app\tenantapi\validate\user\AdjustUserMoney;
use app\tenantapi\validate\user\UserValidate;

/**
 * 用户控制器
 * Class TenantController
 * @package app\tenantapi\controller\user
 */
class UserController extends BaseAdminController
{

    /**
     * @notes 用户列表
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:16
     */
    public function lists()
    {
        return $this->dataLists(new UserLists());
    }

    /**
     * @notes 用户搜索
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:16
     */
    public function search()
    {
        return $this->dataLists(new UserLists());
    }


    /**
     * @notes 获取用户详情
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function detail()
    {
        $params = (new UserValidate())->goCheck('detail');
        $detail = UserLogic::detail($params['id']);
        return $this->success('', $detail);
    }


    /**
     * @notes 编辑用户信息
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function edit()
    {
        $params = (new UserValidate())->post()->goCheck('setInfo');
        UserLogic::setUserInfo($params);
        return $this->success('操作成功', [], 1, 1);
    }


    /**
     * @notes 添加用户信息
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function add()
    {
        $params = (new UserValidate())->post()->goCheck('addInfo');
        UserLogic::addUserInfo($params);
        return $this->success('操作成功', [], 1, 1);
    }

    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/08 10:57
     */
    public function delete()
    {
        $params = (new UserValidate())->post()->goCheck('delete');
        UserLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 调整用户余额
     * @return \think\response\Json
     * @author 段誉
     * @date 2023/2/23 14:33
     */
    public function adjustMoney()
    {
        $params = (new AdjustUserMoney())->post()->goCheck();
        $res = UserLogic::adjustUserMoney($params);
        if (true === $res) {
            return $this->success('操作成功', [], 1, 1);
        }
        return $this->fail($res);
    }

    /**
     * @notes 订单支付
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function pay()
    {
        $params = (new UserValidate())->post()->goCheck('pays');
        $result = UserLogic::pay($params,$this->adminId);
        if (!$result) {
            return $this->fail(UserLogic::getError());
        }
        UserOrderLogic::userStatic($params["id"], 3);
        return $this->success('删除成功', [], 1, 1);
    }
}