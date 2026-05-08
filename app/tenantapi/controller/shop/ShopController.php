<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------

namespace app\tenantapi\controller\shop;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\logic\shop\ShopLogic;
use app\tenantapi\validate\shop\ShopValidate;

/**
 * 店铺管理控制器
 * Class ShopController
 * @package app\tenantapi\controller\shop
 */
class ShopController extends BaseAdminController
{

    /**
     * @notes 获取子店铺列表（分页）
     * @return \think\response\Json
     */
    public function lists()
    {
        $params = $this->request->get();
        $params['page_no'] = $params['page_no'] ?? 1;
        $params['page_size'] = $params['page_size'] ?? 20;
        $result = ShopLogic::lists($params);
        return $this->success('', $result);
    }


    /**
     * @notes 获取店铺详情
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new ShopValidate())->goCheck('detail');
        $result = ShopLogic::detail($params);
        return $this->data($result);
    }


    /**
     * @notes 添加子店铺
     * @return \think\response\Json
     */
    public function add()
    {
        $params = (new ShopValidate())->post()->goCheck('add');
        ShopLogic::add($params);
        return $this->success('添加成功', [], 1, 1);
    }


    /**
     * @notes 编辑店铺信息
     * @return \think\response\Json
     */
    public function edit()
    {
        $params = (new ShopValidate())->post()->goCheck('edit');
        $result = ShopLogic::edit($params);
        if (true === $result) {
            return $this->success('编辑成功', [], 1, 1);
        }
        return $this->fail(ShopLogic::getError());
    }


    /**
     * @notes 删除店铺（软删除）
     * @return \think\response\Json
     */
    public function delete()
    {
        $params = (new ShopValidate())->post()->goCheck('delete');
        ShopLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }

}
