<?php

declare(strict_types=1);

namespace tests\unit;

use app\common\model\user\User;
use app\common\service\jxc\DefaultDataInitService;
use app\common\service\jxc\TenantProvisionService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * TenantProvisionService 单元测试
 *
 * 覆盖场景：
 *  - 新用户首次调用 provisionForWechatUser 时正确创建租户
 *  - 已有 tenant_id 用户调用时幂等返回
 *  - 租户名称生成逻辑
 *  - provision 成功后自动调用 DefaultDataInitService
 */
final class TenantProvisionServiceTest extends TestCase
{
    /** @var int|null 测试中创建的 tenant_id，用于清理 */
    private ?int $createdTenantId = null;

    /** @var int|null 测试中创建的 user id，用于清理 */
    private ?int $createdUserId = null;

    protected function setUp(): void
    {
        // 开启事务用于测试隔离
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        // 回滚所有数据库操作
        Db::rollback();
    }

    /**
     * 新用户（tenant_id=0）首次调用时，正确创建租户、超管账号、回写 tenant_id
     */
    public function testProvisionNewUser(): void
    {
        $user = $this->createTestUser(0);
        $openid = 'oTestOpenid12345678';

        $tenantId = TenantProvisionService::provisionForWechatUser($user, $openid);

        // 返回的 tenantId 必须大于 0
        self::assertGreaterThan(0, $tenantId);

        // user.tenant_id 已被回写
        $dbUser = Db::name('user')->where('id', $user->id)->find();
        self::assertEquals($tenantId, (int)$dbUser['tenant_id']);

        // la_tenant 表存在该记录
        $tenant = Db::name('tenant')->where('id', $tenantId)->find();
        self::assertNotNull($tenant);
        self::assertStringContainsString('WX_', $tenant['name']);

        // expired_time 字段已正确写入
        self::assertGreaterThan(0, (int)$tenant['expired_time']);
        self::assertEqualsWithDelta(time(), (int)$tenant['expired_time'], 5);

        // la_tenant_admin 表存在超管记录（disable=1）
        $admin = Db::name('tenant_admin')->where('tenant_id', $tenantId)->where('root', 1)->find();
        self::assertNotNull($admin);
        self::assertEquals(1, (int)$admin['disable']);
    }

    /**
     * 已有 tenant_id > 0 的用户调用时，幂等返回现有 tenant_id（不重复创建租户）
     */
    public function testProvisionExistingTenantUser(): void
    {
        // 先创建一个拥有 tenant_id 的用户
        $existingTenantId = $this->createTestTenant();
        $user = $this->createTestUser($existingTenantId);

        // 记录当前租户总数
        $tenantCountBefore = Db::name('tenant')->count();

        $result = TenantProvisionService::provisionForWechatUser($user, 'oSomeOpenid');

        // 返回值应等于已有 tenant_id
        self::assertSame($existingTenantId, $result);

        // 没有新创建租户
        $tenantCountAfter = Db::name('tenant')->count();
        self::assertSame($tenantCountBefore, $tenantCountAfter);
    }

    /**
     * 验证租户名称生成逻辑：有 openid 时使用 WX_ + openid后8位
     */
    public function testTenantNameGeneration(): void
    {
        $openid = 'oAbcdefghijklmnopqrst';
        $expectedSuffix = substr($openid, -8); // 'nopqrst' + last char

        $user = $this->createTestUser(0);
        $tenantId = TenantProvisionService::provisionForWechatUser($user, $openid);

        $tenant = Db::name('tenant')->where('id', $tenantId)->find();
        self::assertSame('WX_' . $expectedSuffix, $tenant['name']);
    }

    /**
     * 验证 provisionForWechatUser 成功后自动调用 DefaultDataInitService
     * 通过检查默认数据是否存在来间接验证
     */
    public function testProvisionTriggersDefaultDataInit(): void
    {
        $user = $this->createTestUser(0);
        $openid = 'oTriggerInit12345678';

        $tenantId = TenantProvisionService::provisionForWechatUser($user, $openid);

        // 验证 DefaultDataInitService 已执行：检查默认仓库是否存在
        $warehouse = Db::name('warehouse')
            ->where('tenant_id', $tenantId)
            ->where('name', DefaultDataInitService::DEFAULT_WAREHOUSE_NAME)
            ->find();
        self::assertNotNull($warehouse, '默认仓库应已创建');

        // 验证默认客户是否存在
        $customer = Db::name('customer')
            ->where('tenant_id', $tenantId)
            ->where('customer_name', DefaultDataInitService::DEFAULT_CUSTOMER_NAME)
            ->find();
        self::assertNotNull($customer, '默认客户应已创建');

        // 验证默认供应商是否存在
        $vendor = Db::name('vendor')
            ->where('tenant_id', $tenantId)
            ->where('supplier_name', DefaultDataInitService::DEFAULT_VENDOR_NAME)
            ->find();
        self::assertNotNull($vendor, '默认供应商应已创建');

        // 验证 hasInitialized 返回 true
        self::assertTrue(DefaultDataInitService::hasInitialized($tenantId));
    }

    // ──────────────────────────── Helpers ────────────────────────────

    /**
     * 创建测试用户（直接操作 Db，不经过 BaseModel 钩子）
     */
    private function createTestUser(int $tenantId): User
    {
        $time = time();
        $sn = mt_rand(10000000, 99999999);

        $userId = (int)Db::name('user')->insertGetId([
            'sn'          => $sn,
            'nickname'    => 'TestUser_' . $sn,
            'avatar'      => '',
            'account'     => '',
            'password'    => '',
            'mobile'      => '',
            'channel'     => 0,
            'sex'         => 0,
            'is_disable'  => 0,
            'login_ip'    => '',
            'login_time'  => 0,
            'is_new_user' => 1,
            'tenant_id'   => $tenantId,
            'create_time' => $time,
            'update_time' => $time,
        ]);

        $user = new User();
        $user->id = $userId;
        $user->sn = (string)$sn;
        $user->nickname = 'TestUser_' . $sn;
        $user->tenant_id = $tenantId;

        return $user;
    }

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
