<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\PurchasePlanLogic;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use tests\support\IsolatedDatabaseGuard;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

/** 真实 HTTP 请求、路由、登录中间件、控制器和采购逻辑；只使用隔离测试库。 */
final class PurchaseReadHttpContractTest extends TestCase
{
    use CustomerReportTestSupport;

    private array $userIds = [];
    private array $createdTenantIds = [];
    private array $employeeIds = [];
    private string $ownerToken;
    private array $fixture;

    protected function setUp(): void
    {
        self::assertTrue(IsolatedDatabaseGuard::acceptsConnection(Db::connect()->getConfig()));
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        foreach ([self::TENANT_ID, self::OTHER_TENANT_ID] as $tenantId) {
            if (!Db::name('tenant')->where('id', $tenantId)->find()) {
                Db::name('tenant')->insert(['id' => $tenantId, 'sn' => 'purchase-http-' . $tenantId,
                    'name' => '采购读取测试门店', 'disable' => 0, 'create_time' => time()]);
                $this->createdTenantIds[] = $tenantId;
            }
        }
        $this->ownerToken = $this->session(self::TENANT_ID, 'owner');
        $this->fixture = $this->createDemand();
    }

    protected function tearDown(): void
    {
        if ($this->employeeIds) {
            Db::name('employee_permission')->whereIn('employee_id', $this->employeeIds)->delete();
            Db::name('employee')->whereIn('id', $this->employeeIds)->delete();
        }
        foreach (['user_session', 'tenant_member'] as $table) {
            if ($this->userIds) { Db::name($table)->whereIn('user_id', $this->userIds)->delete(); }
        }
        if ($this->userIds) { Db::name('user')->whereIn('id', $this->userIds)->delete(); }
        $this->cleanCustomerReportData();
        if ($this->createdTenantIds) { Db::name('tenant')->whereIn('id', $this->createdTenantIds)->delete(); }
    }

    public function test_report_entry_query_reaches_demands_and_zero_warehouse_means_all(): void
    {
        $query = ['delivery_date' => '2026-11-17', 'report_id' => (string)$this->fixture['report']['id'],
            'warehouse_id' => '0', 'keyword' => '采购读取鲈鱼', 'page_no' => '1', 'page_size' => '1'];
        $body = $this->get('demands', $query);
        self::assertSame(1, $body['code'], $body['msg']);
        self::assertSame(1, $body['data']['count']);
        self::assertSame(1, $body['data']['page_size']);
        self::assertSame($this->fixture['report']['items'][0]['id'], $body['data']['lists'][0]['report_item_id']);
        self::assertSame('100.0000', $body['data']['lists'][0]['remaining_qty']);
        foreach ([['delivery_date' => '2026-11-18'], ['report_id' => '2147483647'],
            ['warehouse_id' => '2147483647'], ['keyword' => '不存在的采购需求']] as $filter) {
            $empty = $this->get('demands', array_replace($query, $filter));
            self::assertSame(1, $empty['code'], $empty['msg']);
            self::assertSame(0, $empty['data']['count']);
        }
        self::assertSame([], $this->get('demands', array_replace($query, ['page_no' => '2']))['data']['lists']);
    }

    public function test_plan_list_get_query_controls_status_date_and_pagination(): void
    {
        $plan = $this->createPlan();
        $query = ['delivery_date' => '2026-11-17', 'status' => 'pending', 'page_no' => '1', 'page_size' => '1'];
        $body = $this->get('lists', $query);
        self::assertSame(1, $body['code'], $body['msg']);
        self::assertSame(1, $body['data']['count']);
        self::assertSame($plan['id'], $body['data']['lists'][0]['id']);
        self::assertSame([], $this->get('lists', array_replace($query, ['page_no' => '2']))['data']['lists']);
        self::assertSame(0, $this->get('lists', array_replace($query, ['status' => 'complete']))['data']['count']);
        self::assertSame(0, $this->get('lists', array_replace($query, ['delivery_date' => '2026-11-18']))['data']['count']);
    }

    public function test_reads_require_login_and_electronic_permission_reject_non_owner_with_a_view_grant(): void
    {
        $memberToken = $this->session(self::TENANT_ID, 'member');
        $memberId = $this->userIds[array_key_last($this->userIds)];
        foreach (['demands', 'lists'] as $action) {
            self::assertSame('请求参数缺token', $this->get($action, [], '')['msg']);
            $denied = $this->get($action, [], $memberToken);
            self::assertSame(0, $denied['code']);
        }
        $employeeId = $this->employee(self::TENANT_ID, '仅查看采购', 1, $memberId);
        Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID,
            'employee_id' => $employeeId, 'permission_key' => 'purchase.plan.view', 'create_time' => time()]);
        foreach (['demands', 'lists'] as $action) {
            $denied = $this->get($action, [], $memberToken);
            self::assertSame(0, $denied['code']);
        }
    }

    public function test_query_cannot_override_the_authenticated_tenant(): void
    {
        $this->createPlan();
        $otherToken = $this->session(self::OTHER_TENANT_ID, 'owner');
        $query = ['tenant_id' => self::TENANT_ID, 'report_id' => $this->fixture['report']['id']];
        foreach (['demands', 'lists'] as $action) {
            $body = $this->get($action, $query, $otherToken);
            self::assertSame(1, $body['code'], $body['msg']);
            self::assertSame([], $body['data']['lists']);
        }
    }

    public function test_revision_preserves_original_paper_and_releases_only_unbought_quantity(): void
    {
        $fixture = $this->createDemand([
            ['桂鱼', 'RECALL-COUNT', '200', '条'],
            ['鲈鱼', 'RECALL-WEIGHT', '110', '斤'],
        ], 'purchase-http-revise-demand');
        $plan = $this->createPlan($fixture, ['条' => '200', '斤' => '110']);
        $lineIds = array_column($plan['lines'], 'id', 'unit_name');
        // 准备已核实的采购进度。
        Db::name('purchase_plan_source')->where('id', $lineIds['条'])->update([
            'bought_qty' => '50',
            'fulfilled_qty' => '50',
            'status' => 'partial',
        ]);
        $this->markPlanPrinted($plan);
        $query = ['report_id' => $fixture['report']['id']];
        self::assertSame([], $this->get('demands', $query)['data']['lists']);
        $payload = $this->revisionPayload($plan, [$lineIds['条'] => '50', $lineIds['斤'] => '0']);

        $body = $this->request('POST', 'revise', $payload);

        self::assertSame(1, $body['code'], $body['msg']);
        self::assertSame($body, $this->request('POST', 'revise', $payload));

        $detail = $this->get('detail', ['id' => $plan['id']])['data'];
        self::assertSame('complete', $detail['status']);
        self::assertSame($plan['version'] + 1, $detail['version']);
        self::assertSame(['printed'], array_column($detail['sheets'], 'status'));
        $lines = array_column($detail['lines'], null, 'unit_name');
        foreach (['planned_qty', 'bought_qty', 'fulfilled_qty'] as $field) {
            self::assertSame('50.0000', $lines['条'][$field]);
            self::assertSame('0.0000', $lines['斤'][$field]);
        }
        $demands = $this->get('demands', $query)['data']['lists'];
        self::assertCount(2, $demands);
        $released = array_column($demands, 'remaining_qty', 'unit_name');
        self::assertSame('150.0000', $released['条']);
        self::assertSame('110.0000', $released['斤']);
    }

    private function session(int $tenantId, string $role): string
    {
        $suffix = bin2hex(random_bytes(6));
        $userId = (int)Db::name('user')->insertGetId(['tenant_id' => $tenantId,
            'sn' => random_int(100000000, 2000000000), 'account' => 'purchase-' . $suffix]);
        $this->userIds[] = $userId;
        Db::name('tenant_member')->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'role' => $role, 'status' => 1]);
        $token = bin2hex(random_bytes(16));
        Db::name('user_session')->insert(['user_id' => $userId, 'token' => $token,
            'terminal' => 1, 'expire_time' => time() + 86400 * 365]);
        return $token;
    }

    private function employee(int $tenantId, string $name, int $enabled = 1, int $userId = 0): int
    {
        $id = (int)Db::name('employee')->insertGetId(['tenant_id' => $tenantId, 'name' => $name,
            'is_enabled' => $enabled, 'bind_user_id' => $userId > 0 ? $userId : null,
            'create_time' => time(), 'update_time' => time()]);
        $this->employeeIds[] = $id;
        return $id;
    }

    private function createDemand(
        array $products = [['采购读取鲈鱼', 'PURCHASE-HTTP', '100', '斤']],
        string $key = 'purchase-http-demand'
    ): array {
        WorkforceLogic::saveProcess(['name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1]);
        $customer = $this->createCustomer('采购读取客户');
        $warehouse = $this->createCustomerReportWarehouse('采购读取仓');
        $baseUnit = $this->createCustomerReportUnit('斤');
        $payload = [
            'main_customer_id' => $customer,
            'delivery_date' => '2026-11-17',
            'is_supplement' => 0,
            'idempotency_key' => $key,
            'remark' => '',
            'items' => [],
        ];
        foreach ($products as [$name, $code, $quantity, $unitName]) {
            $goods = $this->createCustomerReportGoods($name, $code);
            $sku = $this->customerReportSkuId($goods);
            Db::name('goods')->where('id', $goods)->update(['unit_id' => $baseUnit]);
            Db::name('goods_sku')->where('id', $sku)->update(['base_unit_id' => $baseUnit, 'base_unit_name' => '斤']);
            Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $goods,
                'unit_id' => $baseUnit, 'unit_name' => '斤', 'is_base_unit' => 1, 'status' => 1]);
            $unit = $baseUnit;
            if ($unitName !== '斤') {
                $unit = $this->createCustomerReportUnit($unitName);
                Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $goods,
                    'unit_id' => $unit, 'unit_name' => $unitName, 'is_base_unit' => 0, 'status' => 1]);
            }
            $item = $this->fulfillmentPayload($customer, $goods, $warehouse, $key, $quantity, '')['items'][0];
            $payload['items'][] = array_replace($item, ['unit_id' => $unit, 'unit_name' => $unitName,
                'piece_weight_confirmed' => 0, 'sku_id' => $sku]);
        }
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        return compact('warehouse', 'report');
    }

    private function createPlan(?array $fixture = null, array $quantitiesByUnit = ['斤' => '60']): array
    {
        $f = $fixture ?? $this->fixture;
        $lines = [];
        foreach ($f['report']['items'] as $item) {
            $lines[] = ['report_item_id' => $item['id'],
                'report_version' => $f['report']['version'], 'quantity' => $quantitiesByUnit[$item['unit_name']]];
        }
        $plan = PurchasePlanLogic::create(['warehouse_id' => $f['warehouse'],
            'delivery_date' => '2026-11-17', 'idempotency_key' => 'purchase-http-plan',
            'lines' => $lines]);
        self::assertNotFalse($plan, PurchasePlanLogic::getError());
        return $plan;
    }

    private function markPlanPrinted(array $plan): void
    {
        $prepared = $this->request('POST', 'prepare_print', [
            'id' => $plan['id'],
            'version' => $plan['version'],
            'mode' => 'merged',
            'idempotency_key' => 'purchase-http-prepare-print',
        ]);
        self::assertSame(1, $prepared['code'], $prepared['msg']);
        $printed = $this->request('POST', 'print_result', [
            'sheet_id' => $prepared['data']['sheets'][0]['id'],
            'success' => 1,
        ]);
        self::assertSame(1, $printed['code'], $printed['msg']);
        self::assertSame('printed', $printed['data']['status']);
    }

    private function revisionPayload(array $plan, array $boughtQuantitiesByLine): array
    {
        $lines = [];
        foreach ($boughtQuantitiesByLine as $lineId => $quantity) {
            $lines[] = ['line_id' => $lineId, 'planned_qty' => $quantity, 'bought_qty' => $quantity];
        }
        return [
            'id' => $plan['id'],
            'version' => $plan['version'],
            'idempotency_key' => 'purchase-http-recall',
            'lines' => $lines,
            'reason' => '',
        ];
    }

    private function get(string $action, array $query = [], ?string $token = null): array
    {
        return $this->request('GET', $action, $query, $token);
    }

    private function request(string $method, string $action, array $params, ?string $token = null): array
    {
        $container = \think\Container::getInstance();
        $modelDb = $container->make('db');
        $app = (new \think\App(dirname(__DIR__, 2)))->setEnvName('testing');
        \think\Container::setInstance($app);
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = $app->getRootPath() . 'public/index.php';
        try {
            $app->initialize();
            self::assertTrue(IsolatedDatabaseGuard::acceptsConnection($app->db->connect()->getConfig()));
            $request = $app->make('request', [], true);
            $path = 'api/jxc/purchase_plans/' . $action;
            $request->setMethod($method)->setPathinfo($path)->setHost('localhost');
            $headers = ['token' => $token ?? $this->ownerToken];
            if ($method === 'POST') {
                $headers['content-type'] = 'application/json';
                $request->setUrl('/' . $path);
                $request->withHeader($headers)->withInput(json_encode($params, JSON_THROW_ON_ERROR));
            } else {
                $request->setUrl('/' . $path . '?' . http_build_query($params));
                $request->withGet($params)->withHeader($headers);
            }
            $app->instance('request', $request);
            $response = (new \think\app\MultiApp($app))->handle($request, function ($request) use ($app) {
                return $app->route->dispatch($request, function () use ($app): void {
                    foreach (glob($app->http->getRoutePath() . '*.php') as $file) { include $file; }
                });
            });
            self::assertSame(200, $response->getCode(), '采购路由必须返回 JSON');
            $body = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            return $body;
        } finally {
            \think\Container::setInstance($container);
            \think\Model::setDb($modelDb);
            \think\Model::setInvoker([$container, 'invoke']);
            if ($scriptFilename === null) { unset($_SERVER['SCRIPT_FILENAME']); }
            else { $_SERVER['SCRIPT_FILENAME'] = $scriptFilename; }
        }
    }
}
