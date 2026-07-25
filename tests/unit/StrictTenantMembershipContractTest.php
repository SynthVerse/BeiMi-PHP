<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class StrictTenantMembershipContractTest extends TestCase
{
    public function test_runtime_membership_fallbacks_are_removed_and_active_tenant_is_required(): void
    {
        $service = self::source('app/common/service/jxc/StoreMembershipService.php');
        self::assertStringNotContainsString('ensureLegacyMembership', $service);
        self::assertStringNotContainsString('ensureLegacyDefaultMembership', $service);
        self::assertStringContainsString("->where('m.status', self::STATUS_ACTIVE)", $service);
        self::assertStringContainsString("->where('t.disable', 0)", $service);
        self::assertStringContainsString('UserTokenCache::revokeUserSessions($userId);', $service);
    }

    public function test_backfill_command_and_registration_are_removed(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/app/common/command/TenantMembershipBackfill.php');
        $console = self::source('config/console.php');
        self::assertStringNotContainsString('tenant-membership:backfill', $console);
        self::assertStringNotContainsString('TenantMembershipBackfill', $console);
    }

    public function test_jxc_default_initialization_never_provisions_historical_users(): void
    {
        $console = self::source('config/console.php');
        self::assertStringContainsString("'jxc:init-defaults' => 'app\\common\\command\\JxcInitDefaults'", $console);

        $command = self::source('app/common/command/JxcInitDefaults.php');
        self::assertStringContainsString("setName('jxc:init-defaults')", $command);
        self::assertStringContainsString('DefaultDataInitService::initForTenant($tid);', $command);

        foreach ([
            'with-wechat-users',
            'processWechatUsers',
            'TenantProvisionService',
            "User::where('tenant_id', 0)",
            "Db::name('user_auth')",
            'provisionForWechatUser',
        ] as $forbiddenPath) {
            self::assertStringNotContainsString($forbiddenPath, $command);
        }
    }

    public function test_automatic_wechat_provisioning_service_is_absent_and_manual_flows_remain(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/app/common/service/jxc/TenantProvisionService.php');

        $service = self::source('app/common/service/jxc/StoreMembershipService.php');
        self::assertStringContainsString('public static function createStore', $service);
        self::assertStringContainsString('public static function joinByInviteCode', $service);

        $routes = self::source('app/api/route/jxc.php');
        self::assertStringContainsString("Route::post('user/store/create', 'jxc.Store/createStore');", $routes);
        self::assertStringContainsString("Route::post('store/invite/accept', 'jxc.Store/join');", $routes);
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);
        return $source;
    }
}
