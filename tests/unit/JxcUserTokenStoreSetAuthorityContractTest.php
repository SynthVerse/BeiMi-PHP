<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class JxcUserTokenStoreSetAuthorityContractTest extends TestCase
{
    public function test_login_middleware_sets_the_user_token_marker_only_for_current_members(): void
    {
        $middleware = self::source('app/api/http/middleware/LoginMiddleware.php');
        $marker = '$request->jxcFromUserToken = true;';

        $nonEnforceStart = strpos($middleware, "if (\$isJxc && \$mode !== 'enforce' && !\$isOnboarding) {");
        $enforceStart = strpos($middleware, '// JXC 控制器 enforce 模式');
        $helperStart = strpos($middleware, 'private function setJxcUserTokenContext');

        self::assertNotFalse($nonEnforceStart);
        self::assertNotFalse($enforceStart);
        self::assertNotFalse($helperStart);

        $nonEnforce = substr($middleware, $nonEnforceStart, $enforceStart - $nonEnforceStart);
        $enforce = substr($middleware, $enforceStart, $helperStart - $enforceStart);
        $helper = substr($middleware, $helperStart);

        self::assertSame(1, substr_count($middleware, $marker));
        self::assertStringContainsString(
            '$this->setJxcUserTokenContext($request, $userInfo, $token, $candidateTenantId);',
            $nonEnforce,
        );
        self::assertStringNotContainsString($marker, $nonEnforce);
        self::assertStringNotContainsString($marker, $helper);
        self::assertStringContainsString('$request->adminInfo = [', $helper);
        self::assertStringContainsString('$request->adminId = $userId;', $helper);
        self::assertStringContainsString('$request->tenantId = $tenantId;', $helper);

        self::assertStringContainsString("\$isOnboarding = \$mode === 'enforce-onboarding';", $middleware);
        self::assertStringContainsString("if (\$isJxc && \$mode !== 'enforce' && !\$isOnboarding) {", $middleware);
        self::assertStringContainsString('if (!$isOnboarding) {', $enforce);
        self::assertStringContainsString("return JsonService::fail('登录超时，请重新登录', [], -1, 0);", $enforce);
        self::assertStringContainsString('} else {', $enforce);
        self::assertStringContainsString('$this->setJxcUserTokenContext($request, $userInfo, $token, $candidateTenantId);', $enforce);
        self::assertStringContainsString($marker, $enforce);
    }

    public function test_user_token_storeset_requires_membership_then_owner_or_admin_before_tenant_write(): void
    {
        $logic = self::source('app/api/jxc/logic/StoreLogic.php');
        $methodStart = strpos($logic, 'public static function setStore');
        $methodEnd = strpos($logic, '    /**', $methodStart + 1);
        $method = substr($logic, $methodStart, $methodEnd - $methodStart);

        self::assertNotFalse($methodStart);
        self::assertNotFalse($methodEnd);
        self::assertStringContainsString('if ($fromUserToken) {', $method);
        self::assertStringContainsString('StoreMembershipService::requireCurrentMembership($userId, $tenantId)', $method);
        self::assertStringContainsString('StoreMembershipService::isTenantAdmin($userId, $tenantId)', $method);
        self::assertStringContainsString("self::setError('无权访问该店铺');", $method);
        self::assertStringContainsString("self::setError('需要店铺管理员权限');", $method);

        $membership = strpos($method, 'StoreMembershipService::requireCurrentMembership($userId, $tenantId)');
        $admin = strpos($method, 'StoreMembershipService::isTenantAdmin($userId, $tenantId)');
        $tenantUpdate = strpos(
            $method,
            "Db::name('tenant')->where('id', \$tenantId)->update(\$updateData);",
            $admin,
        );

        self::assertNotFalse($membership);
        self::assertNotFalse($admin);
        self::assertNotFalse($tenantUpdate);
        self::assertLessThan($admin, $membership);
        self::assertLessThan($tenantUpdate, $admin);

        $membershipService = self::source('app/common/service/jxc/StoreMembershipService.php');
        self::assertStringContainsString(
            'return in_array(self::memberRole($userId, $tenantId), [self::ROLE_OWNER, self::ROLE_ADMIN], true);',
            $membershipService,
        );
    }

    public function test_member_switch_route_and_admin_session_authority_contract_remain_unchanged(): void
    {
        $logic = self::source('app/api/jxc/logic/StoreLogic.php');
        $routes = self::source('app/api/route/jxc.php');
        $jxcMiddleware = self::source('app/api/jxc/middleware/JxcLoginMiddleware.php');

        self::assertStringContainsString('StoreMembershipService::switchTenant($userId, $tenantId, request()->header(\'token\'))', $logic);
        self::assertStringContainsString("Route::post('user/store/switch', 'jxc.Store/switchStore')", $routes);
        self::assertStringContainsString('TenantSessionAuthorityService::resolve((string)$token)', $jxcMiddleware);
        self::assertStringContainsString("(\$adminInfo['login_ip'] ?? '') !== (string)\$request->ip()", $jxcMiddleware);
    }

    public function test_onboarding_routes_are_an_exact_method_and_path_whitelist(): void
    {
        $routes = self::source('app/api/route/jxc.php');
        $onboardingStart = strpos($routes, "Route::group('', function () {");
        $onboardingEnd = strpos($routes, "})->middleware(\\app\\api\\http\\middleware\\LoginMiddleware::class, 'enforce-onboarding');");
        $strictStart = strpos($routes, '// 店铺成员操作');

        self::assertNotFalse($onboardingEnd);
        self::assertNotFalse($onboardingStart);
        self::assertNotFalse($strictStart);
        $onboarding = substr($routes, $onboardingStart, $onboardingEnd - $onboardingStart);
        $strict = substr($routes, $strictStart);
        self::assertSame(12, preg_match_all('/Route::(?:get|post)\(/', $onboarding));
        self::assertStringNotContainsString("Route::get('user/info'", $onboarding);
        self::assertMatchesRegularExpression("/Route::get\('user\/info', 'User\/info'\)\\s*->middleware\(\\\\app\\\\api\\\\http\\\\middleware\\\\LoginMiddleware::class\);/", $routes);

        foreach ([
            "Route::get('jxc/auth/info', 'jxc.Auth/info');",
            "Route::post('user/logout', 'jxc.Auth/logout');",
            "Route::get('user/store/status', 'jxc.Store/status');",
            "Route::get('user/store/closure/status', 'jxc.Store/closureStatus');",
            "Route::post('user/store/closure/retry', 'jxc.Store/retryClosure');",
            "Route::get('user/store/current', 'jxc.Store/detail');",
            "Route::get('user/stores',    'jxc.Store/lists');",
            "Route::post('user/open',     'jxc.Store/createStore');",
            "Route::post('user/store/create', 'jxc.Store/createStore');",
            "Route::post('user/store/join',   'jxc.Store/join');",
            "Route::post('store/invite/accept', 'jxc.Store/join');",
            "Route::post('user/store/member-invite/accept', 'jxc.Store/acceptMemberInvite');",
        ] as $route) {
            self::assertStringContainsString($route, $onboarding);
        }

        foreach ([
            "Route::get('user/store',     'jxc.Store/detail');",
            "Route::post('user/storeset', 'jxc.Store/setStore');",
            "Route::post('user/store/switch', 'jxc.Store/switchStore');",
            "Route::get('user/store/member-invite', 'jxc.Store/memberInvite');",
        ] as $route) {
            self::assertStringContainsString($route, $strict);
            self::assertStringNotContainsString($route, $onboarding);
        }

        self::assertStringContainsString("})->middleware(\\app\\api\\http\\middleware\\LoginMiddleware::class, 'enforce');", $strict);
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);
        return $source;
    }
}
