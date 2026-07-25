<?php
declare (strict_types=1);

namespace app\api\http\middleware;


use app\common\exception\ControllerExtendException;
use app\api\controller\BaseApiController;
use think\exception\ClassNotFoundException;
use think\exception\HttpException;
use think\route\RuleItem;


class InitMiddleware
{

    /**
     * @notes 初始化
     * @param $request
     * @param \Closure $next
     * @return mixed
     * @throws ControllerExtendException
     * @author 段誉
     * @date 2022/9/6 18:17
     */
    public function handle($request, \Closure $next)
    {
        $controllerName = strtolower((string)$request->controller());
        $actionKey = $controllerName . '/' . strtolower((string)$request->action());
        $retiredCustomerActions = [
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
        if (in_array($actionKey, $retiredCustomerActions, true)) {
            return json([
                'code' => 0,
                'show' => 1,
                'msg' => '客户赊销订单与客户收款功能已下线',
                'data' => ['retirement_marker' => 'LEGACY_CUSTOMER_ORDER_RETIRED'],
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
        if (in_array($actionKey, $retiredSupplierActions, true)) {
            return json([
                'code' => 0,
                'show' => 1,
                'msg' => '历史供应商采购与付款功能已下线',
                'data' => ['retirement_marker' => 'LEGACY_SUPPLIER_ORDER_RETIRED'],
            ], 410);
        }

        if ($controllerName === 'jxc' || str_starts_with($controllerName, 'jxc.')) {
            $rule = $request->rule();
            $isExplicitRule = $rule instanceof RuleItem
                && !$rule->isMiss()
                && $rule->getName() !== '__think_auto_route__'
                && trim((string)$rule->getRule()) !== '';
            if (!$isExplicitRule) {
                throw new HttpException(404, 'route not found');
            }
        }

        //获取控制器
        try {
            $controller = str_replace('.', '\\', $request->controller());
            $controller = '\\app\\api\\controller\\' . $controller . 'Controller';
            $controllerClass = invoke($controller);
            if (($controllerClass instanceof BaseApiController) === false) {
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
