<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class GoodsMaintenanceAuthorizationContractTest extends TestCase
{
    public function test_user_token_goods_maintenance_uses_store_membership_authority(): void
    {
        $service = self::source('app/common/service/goods/GoodsMaintenancePermissionService.php');

        self::assertStringContainsString(
            'use app\\common\\service\\jxc\\StoreMembershipService;',
            $service,
        );
        self::assertStringContainsString(
            'StoreMembershipService::isTenantAdmin($userId, $tenantId)',
            $service,
        );
        self::assertStringNotContainsString(
            "|| (bool)(request()->jxcFromUserToken ?? false)",
            $service,
        );
    }

    public function test_current_permissions_exposes_goods_maintenance_to_the_create_page(): void
    {
        $logic = self::source('app/api/jxc/logic/WorkforceLogic.php');

        self::assertStringContainsString("'key' => 'goods.maintain'", $logic);
        self::assertStringContainsString("'name' => '商品维护'", $logic);
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);
        return $source;
    }
}
