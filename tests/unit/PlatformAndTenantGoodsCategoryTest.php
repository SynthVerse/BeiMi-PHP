<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\GoodsCategoryLogic;
use app\common\model\goods\TenantGoodscat;
use app\platformapi\logic\goods\TenantGoodscatLogic as PlatformCategoryLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class PlatformAndTenantGoodsCategoryTest extends TestCase
{
    private int $tenantId = 0;
    private int $adminId = 910001;

    protected function setUp(): void
    {
        parent::setUp();
        Db::startTrans();
        $this->tenantId = $this->createTenant();
        $this->setRootAdminContext();
    }

    protected function tearDown(): void
    {
        request()->tenantId = 0;
        request()->adminId = 0;
        request()->adminInfo = [];
        request()->jxcFromUserToken = false;
        Db::rollback();
        parent::tearDown();
    }

    public function testPlatformDefaultCategoryIsVisibleFirstAndCannotBeMaintained(): void
    {
        request()->tenantId = 0;
        request()->adminId = 0;
        request()->adminInfo = [];

        $ordinary = TenantGoodscat::create([
            'tenant_id' => 0,
            'name' => '平台水产',
            'sort' => 999,
            'is_show' => 0,
            'is_default' => null,
        ]);
        $default = TenantGoodscat::where('tenant_id', 0)
            ->where('is_default', 1)
            ->whereNull('delete_time')
            ->findOrEmpty();
        if ($default->isEmpty()) {
            $default = TenantGoodscat::create([
                'tenant_id' => 0,
                'name' => '默认分类',
                'sort' => 0,
                'is_show' => 0,
                'is_default' => 1,
            ]);
        }

        $categories = PlatformCategoryLogic::all();
        self::assertSame((int)$default->id, (int)$categories[0]['id']);
        self::assertSame(1, (int)$categories[0]['is_default']);

        self::assertFalse(PlatformCategoryLogic::edit([
            'id' => (int)$default->id,
            'name' => '改名',
            'sort' => 10,
            'is_show' => 1,
        ]));
        self::assertSame('平台默认分类由系统维护', PlatformCategoryLogic::getError());
        self::assertFalse(PlatformCategoryLogic::delete([
            'id' => [(int)$ordinary->id, (int)$default->id],
        ]));
        self::assertSame('平台默认分类由系统维护', PlatformCategoryLogic::getError());
    }

    public function testMobileCategoryCreationUsesOnlyTheAuthenticatedTenant(): void
    {
        $result = GoodsCategoryLogic::add([
            'name' => '鲜活水产',
            'tenant_id' => 999999,
        ]);

        self::assertNotFalse($result, GoodsCategoryLogic::getError());
        self::assertSame(
            $this->tenantId,
            (int)Db::name('tenant_goodscat')->where('id', (int)$result['id'])->value('tenant_id')
        );
        self::assertFalse(GoodsCategoryLogic::add(['name' => '鲜活水产']));
        self::assertSame('分类名称已存在', GoodsCategoryLogic::getError());
        self::assertFalse(GoodsCategoryLogic::add(['name' => '默认分类']));
        self::assertSame('默认商品分类由系统维护', GoodsCategoryLogic::getError());
    }

    public function testMobileCategoryCreationRequiresGoodsMaintenancePermission(): void
    {
        request()->adminInfo = [];

        self::assertFalse(GoodsCategoryLogic::add(['name' => '无权分类']));
        self::assertSame('当前账号没有商品维护权限', GoodsCategoryLogic::getError());
    }

    private function setRootAdminContext(): void
    {
        request()->tenantId = $this->tenantId;
        request()->adminId = $this->adminId;
        request()->jxcFromUserToken = false;
        request()->adminInfo = [
            'admin_id' => $this->adminId,
            'tenant_id' => $this->tenantId,
            'root' => 1,
        ];
    }

    private function createTenant(): int
    {
        $time = time();
        $sn = 'category-' . bin2hex(random_bytes(4));

        return (int)Db::name('tenant')->insertGetId([
            'sn' => $sn,
            'name' => '商品分类测试租户',
            'avatar' => '',
            'tel' => '',
            'domain_alias' => '',
            'domain_alias_enable' => 0,
            'disable' => 0,
            'notes' => '商品分类测试',
            'tactics' => 0,
            'create_time' => $time,
            'update_time' => $time,
        ]);
    }
}
