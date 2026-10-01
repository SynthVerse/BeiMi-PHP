<?php

declare(strict_types=1);

namespace tests\unit;

use app\common\service\jxc\DefaultDataInitService;
use app\tenantapi\logic\goods\TenantGoodscatLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use tests\support\GoodsCategoryTestSchema;

require_once dirname(__DIR__) . '/support/GoodsCategoryTestSchema.php';

final class TenantDefaultGoodsCategoryTest extends TestCase
{
    private int $tenantId = 0;

    protected function setUp(): void
    {
        GoodsCategoryTestSchema::ensure();
        Db::startTrans();
        $this->tenantId = $this->createTestTenant();
        request()->tenantId = $this->tenantId;
        DefaultDataInitService::initForTenant($this->tenantId);
    }

    protected function tearDown(): void
    {
        request()->tenantId = 0;
        Db::rollback();
    }

    public function testDefaultGoodsCategoryCannotBeRenamedOrHidden(): void
    {
        $category = $this->defaultCategory();

        $result = TenantGoodscatLogic::edit([
            'id' => (int)$category['id'],
            'name' => '水产',
            'sort' => 10,
            'is_show' => 1,
        ]);

        self::assertFalse($result);
        self::assertSame('默认商品分类不可改名或隐藏', TenantGoodscatLogic::getError());
        self::assertSame(
            DefaultDataInitService::DEFAULT_GOODS_CATEGORY_NAME,
            (string)$this->defaultCategory()['name']
        );
    }

    public function testDefaultGoodsCategoryCannotBeDeleted(): void
    {
        $category = $this->defaultCategory();

        self::assertFalse(TenantGoodscatLogic::delete(['id' => (int)$category['id']]));
        self::assertSame('默认商品分类不可删除', TenantGoodscatLogic::getError());
        self::assertNotEmpty($this->defaultCategory());
    }

    public function testReservedDefaultGoodsCategoryNameCannotBeCreatedAgain(): void
    {
        self::assertFalse(TenantGoodscatLogic::add([
            'name' => DefaultDataInitService::DEFAULT_GOODS_CATEGORY_NAME,
            'sort' => 0,
            'is_show' => 0,
        ]));
        self::assertSame('默认商品分类由系统维护', TenantGoodscatLogic::getError());
    }

    public function testCustomGoodsCategoryCannotBeRenamedToReservedDefaultName(): void
    {
        self::assertTrue(TenantGoodscatLogic::add([
            'name' => '水产',
            'sort' => 5,
            'is_show' => 0,
        ]));
        $categoryId = (int)Db::name('tenant_goodscat')
            ->where('tenant_id', $this->tenantId)
            ->where('name', '水产')
            ->value('id');

        self::assertFalse(TenantGoodscatLogic::edit([
            'id' => $categoryId,
            'name' => DefaultDataInitService::DEFAULT_GOODS_CATEGORY_NAME,
            'sort' => 5,
            'is_show' => 0,
        ]));
        self::assertSame('默认商品分类由系统维护', TenantGoodscatLogic::getError());
    }

    public function testCustomGoodsCategoryRemainsEditableAndDeletable(): void
    {
        self::assertTrue(TenantGoodscatLogic::add([
            'name' => '水产',
            'sort' => 5,
            'is_show' => 0,
        ]));
        $categoryId = (int)Db::name('tenant_goodscat')
            ->where('tenant_id', $this->tenantId)
            ->where('name', '水产')
            ->value('id');

        self::assertTrue(TenantGoodscatLogic::edit([
            'id' => $categoryId,
            'name' => '鲜活水产',
            'sort' => 6,
            'is_show' => 0,
        ]));
        self::assertTrue(TenantGoodscatLogic::delete(['id' => $categoryId]));
    }

    /** @return array<string, mixed> */
    private function defaultCategory(): array
    {
        return (array)Db::name('tenant_goodscat')
            ->where('tenant_id', $this->tenantId)
            ->where('is_default', 1)
            ->whereNull('delete_time')
            ->find();
    }

    private function createTestTenant(): int
    {
        $time = time();
        $sn = 'cat' . mt_rand(10000, 99999);

        return (int)Db::name('tenant')->insertGetId([
            'sn' => $sn,
            'name' => 'CategoryTenant_' . $sn,
            'avatar' => '',
            'tel' => '',
            'domain_alias' => '',
            'domain_alias_enable' => 0,
            'disable' => 0,
            'notes' => '默认商品分类测试',
            'tactics' => 0,
            'create_time' => $time,
            'update_time' => $time,
        ]);
    }
}
