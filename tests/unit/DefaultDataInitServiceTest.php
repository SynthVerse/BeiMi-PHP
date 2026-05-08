<?php

declare(strict_types=1);

namespace tests\unit;

use app\common\service\jxc\DefaultDataInitService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * DefaultDataInitService 单元测试
 *
 * 覆盖场景：
 *  - initForTenant() 正确初始化四类默认数据
 *  - 重复调用幂等不重复创建
 *  - tenant_id <= 0 时返回 false
 *  - hasInitialized() 在初始化前后的返回值
 *  - 默认计量单位名称验证
 */
final class DefaultDataInitServiceTest extends TestCase
{
    /** @var int 测试用租户 ID */
    private int $testTenantId = 0;

    protected function setUp(): void
    {
        // 开启事务用于测试隔离
        Db::startTrans();

        // 创建一个用于测试的租户
        $this->testTenantId = $this->createTestTenant();
    }

    protected function tearDown(): void
    {
        // 回滚所有数据库操作
        Db::rollback();
    }

    /**
     * initForTenant() 正确初始化四类默认数据（仓库、客户、供应商、5个计量单位）
     */
    public function testInitForTenantCreatesAllDefaults(): void
    {
        $result = DefaultDataInitService::initForTenant($this->testTenantId);

        self::assertTrue($result);

        // 验证默认仓库
        $warehouse = Db::name('warehouse')
            ->where('tenant_id', $this->testTenantId)
            ->where('name', DefaultDataInitService::DEFAULT_WAREHOUSE_NAME)
            ->find();
        self::assertNotNull($warehouse, '应创建默认仓库');
        self::assertSame('默认仓库', $warehouse['name']);

        // 验证默认客户
        $customer = Db::name('customer')
            ->where('tenant_id', $this->testTenantId)
            ->where('customer_name', DefaultDataInitService::DEFAULT_CUSTOMER_NAME)
            ->find();
        self::assertNotNull($customer, '应创建默认客户');
        self::assertSame('默认客户', $customer['customer_name']);

        // 验证默认供应商
        $vendor = Db::name('vendor')
            ->where('tenant_id', $this->testTenantId)
            ->where('supplier_name', DefaultDataInitService::DEFAULT_VENDOR_NAME)
            ->find();
        self::assertNotNull($vendor, '应创建默认供应商');
        self::assertSame('默认供应商', $vendor['supplier_name']);

        // 验证5个计量单位
        $units = Db::name('goods_unit')
            ->where('tenant_id', $this->testTenantId)
            ->whereIn('name', DefaultDataInitService::DEFAULT_GOODS_UNITS)
            ->column('name');
        self::assertCount(5, $units, '应创建5个默认计量单位');
    }

    /**
     * 重复调用 initForTenant() 不重复创建
     */
    public function testInitForTenantIsIdempotent(): void
    {
        // 第一次调用
        DefaultDataInitService::initForTenant($this->testTenantId);

        // 记录各表数量
        $warehouseCount1 = Db::name('warehouse')->where('tenant_id', $this->testTenantId)->count();
        $customerCount1 = Db::name('customer')->where('tenant_id', $this->testTenantId)->count();
        $vendorCount1 = Db::name('vendor')->where('tenant_id', $this->testTenantId)->count();
        $unitCount1 = Db::name('goods_unit')->where('tenant_id', $this->testTenantId)->count();

        // 第二次调用（幂等）
        $result = DefaultDataInitService::initForTenant($this->testTenantId);
        self::assertTrue($result);

        // 验证数量不变
        $warehouseCount2 = Db::name('warehouse')->where('tenant_id', $this->testTenantId)->count();
        $customerCount2 = Db::name('customer')->where('tenant_id', $this->testTenantId)->count();
        $vendorCount2 = Db::name('vendor')->where('tenant_id', $this->testTenantId)->count();
        $unitCount2 = Db::name('goods_unit')->where('tenant_id', $this->testTenantId)->count();

        self::assertSame($warehouseCount1, $warehouseCount2, '仓库不应重复创建');
        self::assertSame($customerCount1, $customerCount2, '客户不应重复创建');
        self::assertSame($vendorCount1, $vendorCount2, '供应商不应重复创建');
        self::assertSame($unitCount1, $unitCount2, '计量单位不应重复创建');
    }

    /**
     * tenant_id <= 0 时返回 false
     */
    public function testInitForInvalidTenantId(): void
    {
        self::assertFalse(DefaultDataInitService::initForTenant(0));
        self::assertFalse(DefaultDataInitService::initForTenant(-1));
        self::assertFalse(DefaultDataInitService::initForTenant(-999));
    }

    /**
     * 初始化前 hasInitialized() 返回 false
     */
    public function testHasInitializedReturnsFalseBeforeInit(): void
    {
        // 新租户尚未初始化
        self::assertFalse(DefaultDataInitService::hasInitialized($this->testTenantId));
    }

    /**
     * 初始化后 hasInitialized() 返回 true
     */
    public function testHasInitializedReturnsTrueAfterInit(): void
    {
        DefaultDataInitService::initForTenant($this->testTenantId);

        self::assertTrue(DefaultDataInitService::hasInitialized($this->testTenantId));
    }

    /**
     * 验证 5 个基础计量单位名称正确（个、件、箱、千克、升）
     */
    public function testDefaultUnitNames(): void
    {
        $expectedNames = ['个', '件', '箱', '千克', '升'];

        // 验证常量定义
        self::assertSame($expectedNames, DefaultDataInitService::DEFAULT_GOODS_UNITS);

        // 执行初始化后验证数据库中的实际名称
        DefaultDataInitService::initForTenant($this->testTenantId);

        $dbUnits = Db::name('goods_unit')
            ->where('tenant_id', $this->testTenantId)
            ->whereIn('name', $expectedNames)
            ->column('name');

        sort($dbUnits);
        sort($expectedNames);
        self::assertSame($expectedNames, $dbUnits, '数据库中的计量单位名称应完全匹配');
    }

    // ──────────────────────────── Helpers ────────────────────────────

    /**
     * 创建测试租户（直接操作 Db）
     */
    private function createTestTenant(): int
    {
        $time = time();
        $sn = 'tst' . mt_rand(10000, 99999);

        return (int)Db::name('tenant')->insertGetId([
            'sn'                  => $sn,
            'name'                => 'TestTenant_' . $sn,
            'avatar'              => '',
            'tel'                 => '',
            'domain_alias'        => '',
            'domain_alias_enable' => 0,
            'disable'             => 0,
            'notes'               => '单元测试创建',
            'tactics'             => 0,
            'create_time'         => $time,
            'update_time'         => $time,
        ]);
    }
}
