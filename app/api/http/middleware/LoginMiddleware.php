<?php
declare (strict_types=1);

namespace app\api\http\middleware;


use app\api\jxc\controller\BaseJxcController;
use app\common\cache\UserTokenCache;
use app\common\service\JsonService;
use app\api\service\UserTokenService;
use think\facade\Config;
use think\facade\Log;

class LoginMiddleware
{
    /**
     * @notes 登录验证
     * @param $request
     * @param \Closure $next
     * @return mixed|\think\response\Json
     * @author 令狐冲
     * @date 2021/7/1 17:33
     */
    public function handle($request, \Closure $next, string $mode = '')
    {
        $isJxc = ($request->controllerObject ?? null) instanceof BaseJxcController;

        // JXC 控制器在模块级（非 enforce 模式）：尝试 UserTokenCache 软验证，不拒绝请求
        if ($isJxc && $mode !== 'enforce') {
            $token = $request->header('token');
            if (!empty($token)) {
                $userInfo = (new UserTokenCache())->getUserInfo($token);
                if (!empty($userInfo)) {
                    $request->userInfo = $userInfo;
                    $request->userId = $userInfo['user_id'] ?? 0;
                    $request->adminInfo = [
                        'admin_id'    => $userInfo['user_id'],
                        'user_id'     => $userInfo['user_id'],
                        'tenant_id'   => $userInfo['tenant_id'] ?? 0,
                        'root'        => 0,
                        'name'        => $userInfo['nickname'] ?? '',
                        'account'     => $userInfo['mobile'] ?? '',
                        'role_name'   => '',
                        'role_id'     => [],
                        'token'       => $userInfo['token'] ?? $token,
                        'terminal'    => $userInfo['terminal'] ?? '',
                        'expire_time' => $userInfo['expire_time'] ?? 0,
                    ];
                    $request->adminId = (int)($userInfo['user_id'] ?? 0);
                    $request->tenantId = (int)($userInfo['tenant_id'] ?? 0);
                }
            }
            return $next($request);
        }

        $token = $request->header('token');
        //判断接口是否免登录
        $isNotNeedLogin = $request->controllerObject->isNotNeedLogin();

        //不直接判断$isNotNeedLogin结果，使不需要登录的接口通过，为了兼容某些接口可以登录或不登录访问
        if (empty($token) && !$isNotNeedLogin) {
            //没有token并且该地址需要登录才能访问, 指定show为0，前端不弹出此报错
            return JsonService::fail('请求参数缺token', [], 0, 0);
        }

        $userInfo = (new UserTokenCache())->getUserInfo($token);

        if (empty($userInfo) && !$isNotNeedLogin) {
            Log::warning('[LoginMiddleware] token验证失败', [
                'token_prefix' => $token ? substr($token, 0, 8) : 'empty',
                'action' => $request->action(),
                'controller' => $request->controller(),
                'source' => $request->source ?? 'unknown',
            ]);
            //token过期无效并且该地址需要登录才能访问
            return JsonService::fail('登录超时，请重新登录', [], -1, 0);
        }

        //token临近过期，自动续期
        if ($userInfo) {
            //获取临近过期自动续期时长
            $beExpireDuration = Config::get('project.user_token.be_expire_duration');
            //token续期
            if (time() > ($userInfo['expire_time'] - $beExpireDuration)) {
                $result = UserTokenService::overtimeToken($token);
                //续期失败（数据表被删除导致）
                if (empty($result)) {
                    return JsonService::fail('登录过期', [], -1);
                }
            }

            if (!$isJxc) {
                $requestTenantId = (int)($request->tenantId ?? 0);
                if ($requestTenantId > 0 && (int)($userInfo['tenant_id'] ?? 0) > 0 && (int)$userInfo['tenant_id'] !== $requestTenantId) {
                    if (!$isNotNeedLogin) {
                        UserTokenService::expireToken($token);
                        return JsonService::fail('非该站点用户禁止访问', [], -1);
                    }
                }
            }
        }

        //给request赋值，用于控制器
        $request->userInfo = $userInfo;
        $request->userId = $userInfo['user_id'] ?? 0;

        // JXC 控制器 enforce 模式：额外映射 adminInfo 供 BaseJxcController 使用
        if ($isJxc && $userInfo) {
            $request->adminInfo = [
                'admin_id'    => $userInfo['user_id'],
                'user_id'     => $userInfo['user_id'],
                'tenant_id'   => $userInfo['tenant_id'] ?? 0,
                'root'        => 0,
                'name'        => $userInfo['nickname'] ?? '',
                'account'     => $userInfo['mobile'] ?? '',
                'role_name'   => '',
                'role_id'     => [],
                'token'       => $userInfo['token'] ?? $token,
                'terminal'    => $userInfo['terminal'] ?? '',
                'expire_time' => $userInfo['expire_time'] ?? 0,
            ];
            $request->adminId = (int)($userInfo['user_id'] ?? 0);
            $request->tenantId = (int)($userInfo['tenant_id'] ?? 0);
        }

        return $next($request);
    }

}
