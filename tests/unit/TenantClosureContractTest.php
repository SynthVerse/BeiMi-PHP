<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class TenantClosureContractTest extends TestCase
{
    public function test_store_closure_public_api_has_preview_confirm_and_status_routes(): void
    {
        $routes = self::source('app/api/route/jxc.php');
        $controller = self::source('app/api/jxc/controller/StoreController.php');

        self::assertStringContainsString(
            "Route::get('user/store/closure/preview', 'jxc.Store/closurePreview');",
            $routes
        );
        self::assertStringContainsString(
            "Route::post('user/store/closure', 'jxc.Store/confirmTenantPermanentClosure');",
            $routes
        );
        self::assertStringContainsString(
            "Route::get('user/store/closure/status', 'jxc.Store/closureStatus');",
            $routes
        );
        self::assertStringContainsString('public function closurePreview()', $controller);
        self::assertStringContainsString('public function confirmTenantPermanentClosure()', $controller);
        self::assertStringContainsString('public function closureStatus()', $controller);
        self::assertStringContainsString("'tenant_sn' => (string)\$tenant['sn']", self::source('app/common/service/jxc/TenantClosureService.php'));
        self::assertStringContainsString('部分待办来源暂时无法汇总', self::source('app/common/service/jxc/TenantClosureService.php'));

        self::assertLessThan(
            strpos($routes, "Route::get('user/store',"),
            strpos($routes, "Route::get('user/store/closure/preview',")
        );
    }

    public function test_closure_service_requires_the_exact_owner_role_and_keeps_a_minimal_receipt(): void
    {
        $service = self::source('app/common/service/jxc/TenantClosureService.php');
        $migration = self::source('database/migrations/20261003_000001_create_tenant_closure_receipt.sql');

        self::assertStringContainsString(
            'StoreMembershipService::memberRole($userId, $tenantId) !== StoreMembershipService::ROLE_OWNER',
            $service
        );
        self::assertStringNotContainsString('StoreMembershipService::isTenantAdmin($userId, $tenantId)', $service);
        self::assertStringContainsString('backup_purge_due_at', $migration);
        self::assertStringContainsString('operator_user_id', $migration);
        self::assertStringContainsString('public_id', $migration);
        self::assertStringNotContainsString('business_snapshot', $migration);
    }

    public function test_platform_recycle_restore_cannot_restore_a_permanently_closed_tenant(): void
    {
        $logic = self::source('app/platformapi/logic/tenant/TenantLogic.php');
        $restore = self::methodBody($logic, 'restore');

        self::assertStringContainsString("Db::name('tenant_closure_receipt')", $restore);
        self::assertStringContainsString('已永久注销，不能恢复', $restore);
    }

    public function test_closure_acceptance_is_transactional_idempotent_and_preserves_login_identity(): void
    {
        $service = self::source('app/common/service/jxc/TenantClosureService.php');
        $cache = self::source('app/common/cache/UserTokenCache.php');

        self::assertStringNotContainsString('租户注销执行器尚未安装', $service);
        self::assertStringContainsString("->where('id', \$tenantId)->lock(true)", $service);
        self::assertStringContainsString("->update(['disable' => 1", $service);
        self::assertStringContainsString("'user',", $service);
        self::assertStringContainsString("'user_session',", $service);
        self::assertStringContainsString('reassignCurrentTenant', $service);
        self::assertStringContainsString('refreshUserSessions', $service);
        self::assertStringContainsString('public static function refreshUserSessions', $cache);
    }

    public function test_failed_online_cleanup_has_an_authenticated_retry_route(): void
    {
        $routes = self::source('app/api/route/jxc.php');
        $controller = self::source('app/api/jxc/controller/StoreController.php');
        $service = self::source('app/common/service/jxc/TenantClosureService.php');

        self::assertStringContainsString(
            "Route::post('user/store/closure/retry', 'jxc.Store/retryClosure');",
            $routes
        );
        self::assertStringContainsString('public function retryClosure()', $controller);
        self::assertStringContainsString('public static function retry(', $service);
        self::assertStringContainsString("'status' => 'failed'", $service);
        self::assertStringContainsString("'status' => 'backup_retention'", $service);
    }

    public function test_platform_can_query_minimal_receipts_and_only_attest_after_external_backup_purge(): void
    {
        $controller = self::source('app/platformapi/controller/tenant/TenantController.php');
        $logic = self::source('app/platformapi/logic/tenant/TenantLogic.php');
        $lists = self::source('app/platformapi/lists/tenant/TenantClosureReceiptLists.php');
        $migration = self::source('database/migrations/20261003_000001_create_tenant_closure_receipt.sql');

        self::assertStringContainsString('public function closureReceipts()', $controller);
        self::assertStringContainsString('public function confirmClosureBackupPurged()', $controller);
        self::assertStringContainsString("assertPlatformPermission('tenant.tenant/lists')", $controller);
        self::assertStringContainsString("assertPlatformPermission('tenant.tenant/edit')", $controller);
        self::assertStringContainsString('new AdminAuthCache($this->adminId)', $controller);
        self::assertStringContainsString('$this->adminId,', $controller);
        self::assertStringContainsString('public static function confirmClosureBackupPurged', $logic);
        self::assertStringContainsString('请先确认外部备份已实际清理', $logic);
        self::assertStringContainsString('backup_purged_by_admin_id', $migration);
        self::assertStringContainsString("Db::name('tenant_closure_receipt')", $lists);
        self::assertStringNotContainsString("join('tenant", $lists);
        self::assertStringNotContainsString('mobile', $lists);
    }

    public function test_legacy_isolated_tenant_tables_and_their_files_are_included_in_cleanup(): void
    {
        $service = self::source('app/common/service/jxc/TenantClosureService.php');

        self::assertStringContainsString("['tactics']", $service);
        self::assertStringContainsString('isolatedTenantTables', $service);
        self::assertStringContainsString('dropIsolatedTenantTables', $service);
        self::assertStringContainsString('tenant_file_', $service);
        self::assertStringContainsString("preg_match('/^[A-Za-z0-9_]+$/', \$tenantSn)", $service);
    }

    public function test_cleanup_has_an_idempotent_background_processor_and_marks_overdue_backups(): void
    {
        $service = self::source('app/common/service/jxc/TenantClosureService.php');
        $command = self::source('app/common/command/TenantClosureProcess.php');
        $console = self::source('config/console.php');
        $migration = self::source('database/migrations/20261003_000001_create_tenant_closure_receipt.sql');

        self::assertStringContainsString('public static function processPending', $service);
        self::assertStringContainsString("'phase' => 'backup_overdue'", $service);
        self::assertStringContainsString('TenantClosureService::processPending', $command);
        self::assertStringContainsString("'tenant-closure:process'", $console);
        self::assertStringContainsString("`command`='tenant-closure:process'", $migration);
        self::assertStringContainsString("'*/5 * * * *'", $migration);
        self::assertStringContainsString('verifyNoTenantDataRemains', $service);
        self::assertStringNotContainsString("return \$error === '' ||", $service);
    }

    public function test_preview_fingerprint_tracks_row_updates_without_count_changes(): void
    {
        $service = self::source('app/common/service/jxc/TenantClosureService.php');

        self::assertStringContainsString('tenantDataVersion', $service);
        self::assertStringContainsString('MAX(`update_time`)', $service);
        self::assertStringContainsString('MAX(`create_time`)', $service);
        self::assertStringContainsString('MAX(`id`)', $service);
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Missing source: ' . $path);
        return $source;
    }

    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method);
        self::assertNotFalse($start);
        $open = strpos($source, '{', $start);
        self::assertNotFalse($open);
        $depth = 0;
        for ($index = $open, $length = strlen($source); $index < $length; $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}' && --$depth === 0) {
                return substr($source, $open, $index - $open + 1);
            }
        }
        self::fail('Method is not closed.');
    }
}
