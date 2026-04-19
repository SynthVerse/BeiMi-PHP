<?php
namespace app\tenantapi\controller\auth;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\logic\auth\MenuLogic;


/**
 * 系统菜单权限
 * Class MenuController
 * @package app\tenantapi\controller\setting\system
 */
class MenuController extends BaseAdminController
{

    /**
     * @notes 获取菜单路由
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/6/29 17:41
     */
    public function route()
    {
        $result = MenuLogic::getMenuByAdminId($this->adminId);
        return $this->data($result);
    }

    /**
     * @notes 获取菜单数据
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/10/13 11:03
     */
    public function all()
    {
        $result = MenuLogic::getAllData();
        return $this->data($result);
    }
}