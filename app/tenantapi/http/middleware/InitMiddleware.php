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
declare (strict_types=1);

namespace app\tenantapi\http\middleware;

use app\tenantapi\controller\BaseAdminController;
use app\common\exception\ControllerExtendException;
use think\exception\ClassNotFoundException;
use think\exception\HttpException;

/**
 * 初始化验证中间件
 * Class InitMiddleware
 * @package app\tenantapi\http\middleware
 */
class InitMiddleware
{
    /**
     * @notes 初始化
     * @param $request
     * @param \Closure $next
     * @return mixed
     * @author 令狐冲
     * @date 2021/7/2 19:29
     */
    public function handle($request, \Closure $next)
    {
        $retiredActions = [
            'user.userorder/lists',
            'user.userorder/add',
            'user.userorder/pay',
            'user.userorder/delete',
            'user.userorder/detail',
            'user.userordergoods/lists',
            'user.userordergoods/add',
            'user.userordergoods/edit',
            'user.userordergoods/delete',
            'user.userordergoods/detail',
            'user.usermoney/lists',
            'user.usermoney/delete',
            'user.usermoney/detail',
            'user.user/pay',
        ];
        if (in_array(strtolower($request->controller() . '/' . $request->action()), $retiredActions, true)) {
            return json([
                'code' => 0,
                'show' => 1,
                'msg' => '客户赊销订单与客户收款功能已下线',
                'data' => [
                    'retirement_marker' => 'LEGACY_CUSTOMER_ORDER_RETIRED',
                ],
            ], 410);
        }

        $retiredSupplierActions = [
            'supplier.usersupplier/lists',
            'supplier.usersupplier/add',
            'supplier.usersupplier/edit',
            'supplier.usersupplier/delete',
            'supplier.usersupplier/detail',
            'supplier.usersupplier/pay',
            'supplier.usersupplier/search',
            'supplier.usersupplierorder/lists',
            'supplier.usersupplierorder/add',
            'supplier.usersupplierorder/edit',
            'supplier.usersupplierorder/delete',
            'supplier.usersupplierorder/detail',
            'supplier.usersupplierorder/pay',
            'supplier.usersuppliermoney/lists',
            'supplier.usersuppliermoney/delete',
            'supplier.usersuppliermoney/detail',
        ];
        if (in_array(strtolower($request->controller() . '/' . $request->action()), $retiredSupplierActions, true)) {
            return json([
                'code' => 0,
                'show' => 1,
                'msg' => '历史供应商采购与付款功能已下线',
                'data' => [
                    'retirement_marker' => 'LEGACY_SUPPLIER_ORDER_RETIRED',
                ],
            ], 410);
        }

        //获取控制器
        try {
            $controller = str_replace('.', '\\', $request->controller());
            $controller = '\\app\\tenantapi\\controller\\' . $controller . 'Controller';
            $controllerClass = invoke($controller);
            if (($controllerClass instanceof BaseAdminController) === false) {
                throw new ControllerExtendException($controller, '404');
            }
        } catch (ClassNotFoundException $e) {
            throw new HttpException(404, 'controller not exists:' . $e->getClass());
        }

        //创建控制器对象
        $request->controllerObject = invoke($controller);

        return $next($request);
    }
}
