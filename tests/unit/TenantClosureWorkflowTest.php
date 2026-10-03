<?php

declare(strict_types=1);

namespace tests\unit;

use app\common\cache\UserTokenCache;
use app\api\jxc\logic\StoreLogic;
use app\common\service\jxc\TenantClosureService;
use PHPUnit\Framework\TestCase;
use tests\support\IsolatedDatabaseGuard;
use think\facade\Db;

/** Physical deletion is verified in the guarded test database with owned fixtures only. */
final class TenantClosureWorkflowTest extends TestCase
{
    private static array $createdTables = [];
    private static bool $addedTerminal = false;
    private array $tenantIds = [];
    private array $userIds = [];
    private array $roleIds = [];
    private array $tokens = [];
    private array $requestState = [];

    public static function setUpBeforeClass(): void
    {
        if (!IsolatedDatabaseGuard::acceptsConnection(Db::connect()->getConfig())) {
            throw new \RuntimeException('isolated_database_required');
        }
        $tables = array_map(static fn ($row) => (string)array_values($row)[0], Db::query('SHOW TABLES'));
        if (!in_array('la_tenant_closure_receipt', $tables, true)) {
            $sql = file_get_contents('database/migrations/20261003_000001_create_tenant_closure_receipt.sql');
            Db::execute(str_replace('{{prefix}}', 'la_', explode(';', $sql)[0]));
            self::$createdTables[] = 'la_tenant_closure_receipt';
        }
        $baseline = file_get_contents('public/install/db/like.sql');
        foreach (['tenant_system_role', 'tenant_system_role_menu'] as $name) {
            if (in_array('la_' . $name, $tables, true)) {
                continue;
            }
            preg_match('/CREATE TABLE `\{\{prefix\}\}' . $name . '`\s*\(.*?;/s', $baseline, $match);
            Db::execute(str_replace('{{prefix}}', 'la_', $match[0]));
            self::$createdTables[] = 'la_' . $name;
        }
        $columns = array_column(Db::query('SHOW COLUMNS FROM la_user_session'), 'Field');
        if (!in_array('terminal', $columns, true)) {
            Db::execute('ALTER TABLE la_user_session ADD terminal int NOT NULL DEFAULT 1');
            self::$addedTerminal = true;
        }
    }

    protected function setUp(): void
    {
        foreach (['tenantId', 'userId', 'adminInfo', 'jxcFromUserToken', 'controllerObject', 'userInfo'] as $key) {
            $this->requestState[$key] = request()->$key ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tokens as $token) {
            (new UserTokenCache())->deleteUserInfo($token);
        }
        if ($this->roleIds) {
            Db::name('tenant_system_role_menu')->whereIn('role_id', $this->roleIds)->delete();
        }
        foreach (['tenant_member', 'tenant_file', 'tenant_closure_receipt', 'tenant_system_role', 'customer_report'] as $table) {
            if ($this->tenantIds) {
                Db::name($table)->whereIn('tenant_id', $this->tenantIds)->delete();
            }
        }
        foreach (['user_auth', 'user_session'] as $table) {
            if ($this->userIds) {
                Db::name($table)->whereIn('user_id', $this->userIds)->delete();
            }
        }
        if ($this->userIds) {
            Db::name('user')->whereIn('id', $this->userIds)->delete();
        }
        if ($this->tenantIds) {
            Db::name('tenant')->whereIn('id', $this->tenantIds)->delete();
        }
        foreach ($this->requestState as $key => $value) {
            request()->$key = $value;
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (array_reverse(self::$createdTables) as $table) {
            Db::execute('DROP TABLE `' . $table . '`');
        }
        if (self::$addedTerminal) {
            Db::execute('ALTER TABLE la_user_session DROP terminal');
        }
        self::$createdTables = [];
        self::$addedTerminal = false;
    }

    public function test_closure_removes_role_menu_links_and_preserves_other_tenants(): void
    {
        [$tenant, $user] = $this->fixture();
        [$other] = $this->fixture();
        $role = $this->role($tenant);
        $otherRole = $this->role($other);
        $this->context($tenant, $user);
        $params = $this->params($tenant, $user);

        $receipt = TenantClosureService::close($user, $tenant, $params);

        self::assertSame('backup_retention', $receipt['status']);
        self::assertSame(0, Db::name('tenant_system_role_menu')->where('role_id', $role)->count());
        self::assertSame(0, Db::name('tenant_system_role')->where('id', $role)->count());
        self::assertSame(1, Db::name('tenant_system_role_menu')->where('role_id', $otherRole)->count());
        self::assertSame(1, Db::name('tenant')->where('id', $other)->count());
    }

    private function fixture(): array
    {
        $suffix = bin2hex(random_bytes(6));
        $tenant = (int)Db::name('tenant')->insertGetId([
            'sn' => $suffix, 'name' => '注销回归' . $suffix, 'create_time' => time(), 'update_time' => time(),
        ]);
        $this->tenantIds[] = $tenant;
        $user = (int)Db::name('user')->insertGetId([
            'tenant_id' => $tenant, 'sn' => random_int(100000000, 2000000000), 'account' => $suffix,
        ]);
        $this->userIds[] = $user;
        Db::name('tenant_member')->insert(['tenant_id' => $tenant, 'user_id' => $user, 'role' => 'owner']);
        $token = bin2hex(random_bytes(16));
        $this->tokens[] = $token;
        Db::name('user_session')->insert([
            'user_id' => $user, 'token' => $token, 'terminal' => 1, 'expire_time' => time() + 86400 * 365,
        ]);
        $this->context($tenant, $user);
        return [$tenant, $user, $token];
    }

    /** @dataProvider replacementTenantProvider */
    public function test_original_request_replays_after_current_tenant_changes(bool $hasOther): void
    {
        [$tenant, $user, $token] = $this->fixture();
        $other = 0;
        if ($hasOther) {
            [$other] = $this->fixture();
            Db::name('tenant_member')->insert(['tenant_id' => $other, 'user_id' => $user, 'role' => 'member']);
        }
        $this->context($tenant, $user);
        $params = $this->params($tenant, $user);
        $receipt = StoreLogic::confirmTenantPermanentClosure($params);
        self::assertIsArray($receipt);
        $session = (new UserTokenCache())->getUserInfo($token);
        self::assertSame($other, $session['tenant_id']);
        $this->context($other, $user);
        $replayed = StoreLogic::confirmTenantPermanentClosure($params);
        self::assertIsArray($replayed, StoreLogic::getError());
        self::assertSame($receipt['receipt_id'], $replayed['receipt_id']);
        self::assertSame(1, Db::name('tenant_closure_receipt')->where('tenant_id', $tenant)->count());
        if ($other > 0) {
            self::assertSame(0, (int)Db::name('tenant')->where('id', $other)->value('disable'));
        }
    }

    public static function replacementTenantProvider(): array
    {
        return ['last store' => [false], 'another store' => [true]];
    }

    public function test_authenticated_route_replays_after_last_store_closes_and_rejects_anonymous(): void
    {
        [$tenant, $user, $token] = $this->fixture();
        $params = $this->params($tenant, $user);
        $first = $this->postClosure($token, $params);
        self::assertSame(1, $first['code'], $first['msg']);
        $replayed = $this->postClosure($token, $params);
        self::assertSame(1, $replayed['code'], $replayed['msg']);
        self::assertSame($first['data']['receipt_id'], $replayed['data']['receipt_id']);
        self::assertNotSame(1, $this->postClosure('', $params)['code']);
    }

    private function postClosure(string $token, array $params): array
    {
        $previousRequest = request();
        $previousRoute = app()->route;
        $request = clone $previousRequest;
        $request->withHeader(['token' => $token])->withPost($params)->setMethod('POST');
        $request->tenantId = 0;
        $request->userId = 0;
        $request->adminInfo = [];
        $request->userInfo = [];
        app()->instance('request', $request);
        app()->instance('route', new \think\Route(app()));
        try {
            require 'app/api/route/jxc.php';
            $rules = array_values(array_filter(app()->route->getRuleList(), static fn ($rule) =>
                $rule['rule'] === 'user/store/closure' && $rule['method'] === 'post'));
            self::assertCount(1, $rules);
            $controller = new \app\api\jxc\controller\StoreController(app());
            $request->controllerObject = $controller;
            [$middleware, $arguments] = $rules[0]['option']['middleware'][0];
            return (new $middleware())->handle($request, static fn () =>
                $controller->confirmTenantPermanentClosure(), ...$arguments)->getData();
        } finally {
            app()->instance('request', $previousRequest);
            app()->instance('route', $previousRoute);
        }
    }

    public function test_report_edit_with_unchanged_timestamp_invalidates_preview(): void
    {
        [$tenant, $user] = $this->fixture();
        $report = (int)Db::name('customer_report')->insertGetId([
            'tenant_id' => $tenant,
            'remark' => '原始要求', 'version' => 1, 'create_time' => 100, 'update_time' => 100,
        ]);
        $params = $this->params($tenant, $user);
        Db::name('customer_report')->where('id', $report)->update(['remark' => '修改后的要求', 'version' => 2]);
        self::assertSame(100, (int)Db::name('customer_report')->where('id', $report)->value('update_time'));

        self::assertFalse(StoreLogic::confirmTenantPermanentClosure($params));
        self::assertSame('店铺数据已变化，请重新预览后再确认', StoreLogic::getError());
        self::assertSame(0, (int)Db::name('tenant')->where('id', $tenant)->value('disable'));
        self::assertSame(0, Db::name('tenant_closure_receipt')->where('tenant_id', $tenant)->count());
        self::assertSame('修改后的要求', Db::name('customer_report')->where('id', $report)->value('remark'));
    }

    public function test_switching_before_first_submission_does_not_close_either_store(): void
    {
        [$tenant, $user] = $this->fixture();
        [$other] = $this->fixture();
        Db::name('tenant_member')->insert(['tenant_id' => $other, 'user_id' => $user, 'role' => 'owner']);
        $this->context($tenant, $user);
        $params = $this->params($tenant, $user);
        $this->context($other, $user);
        self::assertFalse(StoreLogic::confirmTenantPermanentClosure($params));
        self::assertSame('当前店铺已变化，请重新预览后再确认', StoreLogic::getError());
        self::assertSame(2, Db::name('tenant')->whereIn('id', [$tenant, $other])->where('disable', 0)->count());
        self::assertSame(0, Db::name('tenant_closure_receipt')->whereIn('tenant_id', [$tenant, $other])->count());
    }

    public function test_revoked_owner_cannot_submit_a_previous_preview(): void
    {
        [$tenant, $user] = $this->fixture();
        $params = $this->params($tenant, $user);
        Db::name('tenant_member')->where('tenant_id', $tenant)->where('user_id', $user)->update(['role' => 'admin']);
        self::assertFalse(StoreLogic::confirmTenantPermanentClosure($params));
        self::assertSame('仅店铺老板可以注销店铺', StoreLogic::getError());
        self::assertSame(0, Db::name('tenant_closure_receipt')->where('tenant_id', $tenant)->count());
    }

    public function test_replay_is_bound_to_original_user_target_and_key(): void
    {
        [$tenant, $user, $token] = $this->fixture();
        $params = $this->params($tenant, $user);
        $receipt = $this->postClosure($token, $params);
        self::assertSame(1, $receipt['code']);
        $changed = $params;
        $changed['idempotency_key'] .= '-different';
        $wrongKey = $this->postClosure($token, $changed);
        self::assertNotSame(1, $wrongKey['code']);
        self::assertSame('注销请求标识不一致，请使用原请求重试', $wrongKey['msg']);
        [, , $otherToken] = $this->fixture();
        self::assertNotSame(1, $this->postClosure($otherToken, $params)['code']);
        $tampered = $params;
        $tampered['preview_token'] .= 'x';
        self::assertNotSame(1, $this->postClosure($token, $tampered)['code']);
        $replayed = $this->postClosure($token, $params);
        self::assertSame($receipt['data']['receipt_id'], $replayed['data']['receipt_id']);
        self::assertSame(1, Db::name('tenant_closure_receipt')->where('tenant_id', $tenant)->count());
    }

    public function test_preserved_personal_profile_changes_do_not_invalidate_business_preview(): void
    {
        [$tenant, $user] = $this->fixture();
        $params = $this->params($tenant, $user);
        Db::name('user')->where('id', $user)->update(['nickname' => '新昵称']);
        $receipt = StoreLogic::confirmTenantPermanentClosure($params);
        self::assertIsArray($receipt, StoreLogic::getError());
        self::assertSame('backup_retention', $receipt['status']);
        self::assertSame('新昵称', Db::name('user')->where('id', $user)->value('nickname'));
    }

    private function context(int $tenant, int $user): void
    {
        request()->tenantId = $tenant;
        request()->userId = $user;
        request()->adminInfo = ['user_id' => $user];
        request()->jxcFromUserToken = true;
    }

    private function params(int $tenant, int $user): array
    {
        $preview = TenantClosureService::preview($user, $tenant);
        return [
            'store_name' => $preview['store_name'], 'preview_token' => $preview['preview_token'],
            'idempotency_key' => bin2hex(random_bytes(16)),
        ];
    }

    private function role(int $tenant): int
    {
        $id = (int)Db::name('tenant_system_role')->insertGetId(['tenant_id' => $tenant, 'name' => '验收角色']);
        $this->roleIds[] = $id;
        Db::name('tenant_system_role_menu')->insert(['role_id' => $id, 'menu_id' => 1]);
        return $id;
    }
}
