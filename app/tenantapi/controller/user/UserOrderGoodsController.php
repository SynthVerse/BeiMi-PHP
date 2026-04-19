<?php
namespace app\tenantapi\controller\user;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\user\UserOrderGoodsLists;
use app\tenantapi\logic\user\UserOrderGoodsLogic;
use app\tenantapi\validate\user\UserOrderGoodsValidate;


/**
 * UserOrderGoods控制器
 * Class UserOrderGoodsController
 * @package app\tenantapi\controller\user
 */
class UserOrderGoodsController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function lists()
    {
        return $this->dataLists(new UserOrderGoodsLists());
    }


    /**
     * @notes 添加
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function add()
    {
        $params = (new UserOrderGoodsValidate())->post()->goCheck('add');
        $result = UserOrderGoodsLogic::add($params);
        if (true === $result) {
            return $this->success('添加成功', [], 1, 1);
        }
        return $this->fail(UserOrderGoodsLogic::getError());
    }


    /**
     * @notes 编辑
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function edit()
    {
        $params = (new UserOrderGoodsValidate())->post()->goCheck('edit');
        $result = UserOrderGoodsLogic::edit($params);
        if (true === $result) {
            return $this->success('编辑成功', [], 1, 1);
        }
        return $this->fail(UserOrderGoodsLogic::getError());
    }


    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function delete()
    {
        $params = (new UserOrderGoodsValidate())->post()->goCheck('delete');
        UserOrderGoodsLogic::delete($params);
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
        $params = (new UserOrderGoodsValidate())->goCheck('detail');
        $result = UserOrderGoodsLogic::detail($params);
        return $this->data($result);
    }


}