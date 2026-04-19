<?php
namespace app\tenantapi\controller\user;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\user\UserOrderLists;
use app\tenantapi\logic\user\UserOrderLogic;
use app\tenantapi\validate\user\UserOrderValidate;


/**
 * UserOrder控制器
 * Class UserOrderController
 * @package app\tenantapi\controller\user
 */
class UserOrderController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function lists()
    {
        return $this->dataLists(new UserOrderLists());
    }


    /**
     * @notes 添加
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function add()
    {
        $params = (new UserOrderValidate())->post()->goCheck('add');
        foreach ($params['goods'] as $k => $val) {
            (new UserOrderValidate())->post()->goCheck('addpar', $val);
        }
        $result = UserOrderLogic::add($params,$this->adminId);
        if (true === $result) {
            return $this->success('添加成功', [], 1, 1);
        }
        return $this->fail(UserOrderLogic::getError());
    }


    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function delete()
    {
        $params = (new UserOrderValidate())->post()->goCheck('delete');
        UserOrderLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 获取详情
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function detail()
    {
        $params = (new UserOrderValidate())->goCheck('detail');
        $result = UserOrderLogic::detail($params);
        if (count($result) == 0) {
            return $this->fail(UserOrderLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 订单支付
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function pay()
    {
        $params = (new UserOrderValidate())->post()->goCheck('pays');
        $result = UserOrderLogic::pay($params,$this->adminId);
        if (!$result) {
            return $this->fail(UserOrderLogic::getError());
        }
        return $this->success('支付成功', [], 1, 1);
    }



}