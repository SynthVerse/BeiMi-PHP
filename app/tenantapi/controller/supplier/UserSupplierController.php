<?php

namespace app\tenantapi\controller\supplier;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\supplier\UserSupplierLists;
use app\tenantapi\logic\supplier\UserSupplierLogic;
use app\tenantapi\logic\supplier\UserSupplierOrderLogic;
use app\tenantapi\validate\supplier\UserSupplierValidate;


/**
 * UserSupplier控制器
 * Class UserSupplierController
 * @package app\tenantapi\controller\supplier
 */
class UserSupplierController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function lists()
    {
        return $this->dataLists(new UserSupplierLists());
    }

    /**
     * @notes 用户搜索
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:16
     */
    public function search()
    {
        return $this->dataLists(new UserSupplierLists());
    }

    /**
     * @notes 添加
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function add()
    {
        $params = (new UserSupplierValidate())->post()->goCheck('add');
        $result = UserSupplierLogic::add($params);
        if (true === $result) {
            return $this->success('添加成功', [], 1, 1);
        }
        return $this->fail(UserSupplierLogic::getError());
    }


    /**
     * @notes 编辑
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function edit()
    {
        $params = (new UserSupplierValidate())->post()->goCheck('edit');
        $result = UserSupplierLogic::edit($params);
        if (true === $result) {
            return $this->success('编辑成功', [], 1, 1);
        }
        return $this->fail(UserSupplierLogic::getError());
    }


    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function delete()
    {
        $params = (new UserSupplierValidate())->post()->goCheck('delete');
        UserSupplierLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 获取详情
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function detail()
    {
        $params = (new UserSupplierValidate())->goCheck('detail');
        $result = UserSupplierLogic::detail($params);
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
        $params = (new UserSupplierValidate())->post()->goCheck('pays');
        $result = UserSupplierLogic::pay($params,$this->adminId);
        if (!$result) {
            return $this->fail(UserSupplierOrderLogic::getError());
        }
        UserSupplierOrderLogic::supplierStatic($params["id"], 3);
        return $this->success('删除成功', [], 1, 1);
    }
}