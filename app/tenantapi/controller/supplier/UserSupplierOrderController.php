<?php

namespace app\tenantapi\controller\supplier;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\supplier\UserSupplierOrderLists;
use app\tenantapi\logic\supplier\UserSupplierOrderLogic;
use app\tenantapi\validate\supplier\UserSupplierOrderValidate;


/**
 * UserSupplierOrder控制器
 * Class UserSupplierOrderController
 * @package app\tenantapi\controller\supplier
 */
class UserSupplierOrderController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function lists()
    {
        return $this->dataLists(new UserSupplierOrderLists());
    }


    /**
     * @notes 添加供应商订单
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function add()
    {
        $params = (new UserSupplierOrderValidate())->post()->goCheck('add');
        foreach ($params['goods'] as $k => $val) {
            (new UserSupplierOrderValidate())->post()->goCheck('addpar', $val);
        }
        $result = UserSupplierOrderLogic::add($params);
        if (true === $result) {
            return $this->success('添加成功', [], 1, 1);
        }
        return $this->fail(UserSupplierOrderLogic::getError());
    }


    /**
     * @notes 编辑
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function edit()
    {
        $params = (new UserSupplierOrderValidate())->post()->goCheck('edit');
        $result = UserSupplierOrderLogic::edit($params);
        if (true === $result) {
            return $this->success('编辑成功', [], 1, 1);
        }
        return $this->fail(UserSupplierOrderLogic::getError());
    }


    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function delete()
    {
        $params = (new UserSupplierOrderValidate())->post()->goCheck('delete');
        UserSupplierOrderLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 获取详情
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function detail()
    {
        $params = (new UserSupplierOrderValidate())->goCheck('detail');
        $result = UserSupplierOrderLogic::detail($params);
        if (count($result) == 0) {
            return $this->fail(UserSupplierOrderLogic::getError());
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
        $params = (new UserSupplierOrderValidate())->post()->goCheck('pays');
        $result = UserSupplierOrderLogic::pay($params,$this->adminId);
        if (!$result) {
            return $this->fail(UserSupplierOrderLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

}