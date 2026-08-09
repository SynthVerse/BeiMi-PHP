<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class GoodsCreationControllerContractTest extends TestCase
{
    public function test_goods_controller_exposes_permission_conflict_and_recovery_messages(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/app/api/jxc/controller/GoodsController.php');
        $logic = (string)file_get_contents($root . '/app/api/jxc/logic/GoodsLogic.php');
        $aliasService = (string)file_get_contents($root . '/app/common/service/goods/GoodsAliasService.php');

        self::assertStringContainsString("return \$this->fail(GoodsLogic::getError());", $controller);
        self::assertStringContainsString("'商品已存在'", $controller);
        self::assertStringContainsString("'商品已归档，请先恢复'", $controller);
        self::assertStringContainsString("'商品已停用，请先启用'", $controller);
        self::assertStringContainsString("'当前账号没有商品维护权限'", $logic);
        self::assertStringContainsString("'商品名称或别名同时命中多个既有商品，请选择已有商品'", $logic);
        self::assertStringContainsString("'requires_activation' => !\$reusable", $logic);
        self::assertStringContainsString('public static function edit(array $params): bool', $logic);
        self::assertGreaterThanOrEqual(2, substr_count($logic, "Db::name('tenant')"));
        self::assertStringContainsString('assertMasterDataInTenant($saveData)', $logic);
        self::assertStringContainsString('resolveTenantCreateConflict(', $logic);
        self::assertStringContainsString("'绑定单位不可用'", $logic);
        self::assertStringContainsString("->whereNull('delete_time')", $logic);
        self::assertStringContainsString("'normalized_name' => GoodsAliasService::normalize(\$name)", $logic);
        self::assertStringContainsString("->whereIn('normalized_name', array_keys(\$tokens))", $aliasService);
    }

    public function test_cloud_goods_download_uses_the_same_permission_and_tenant_creation_lock(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string)file_get_contents($root . '/app/common/service/cloud/CloudGoodsService.php');

        self::assertStringContainsString('GoodsMaintenancePermissionService::canMaintain()', $service);
        self::assertStringContainsString("'当前账号没有商品维护权限'", $service);
        self::assertStringContainsString("Db::name('tenant')", $service);
        self::assertStringContainsString('->lock(true)', $service);
        self::assertStringContainsString('GoodsAliasService::resolveTenantCreateConflict', $service);
        self::assertStringContainsString("->whereNull('delete_time')", $service);
        self::assertStringContainsString("'normalized_name' => GoodsAliasService::normalize", $service);
        self::assertStringContainsString("'existing_state' => \$state", $service);
        self::assertStringContainsString("'requires_activation' => !\$reusable", $service);
    }

    public function test_customer_report_controller_returns_recovery_data_on_quick_create_failure(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/app/api/jxc/controller/CustomerReportController.php');
        $logic = (string)file_get_contents($root . '/app/api/jxc/logic/CustomerReportCandidateLogic.php');

        self::assertStringContainsString(
            "\$this->fail(CustomerReportCandidateLogic::getError(), CustomerReportCandidateLogic::getReturnData() ?: [])",
            $controller
        );
        self::assertStringContainsString("'商品已存在'", $controller);
        self::assertStringContainsString("'同名商品已归档，请先恢复'", $logic);
        self::assertStringContainsString("'同名商品已停用，请先启用'", $logic);
        self::assertStringContainsString("'reusable' => false", $logic);
        self::assertStringContainsString("'requires_activation' => true", $logic);
    }
}
