<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerLogic;
use app\api\jxc\logic\MasterCreationReceipt;
use app\api\jxc\logic\SupplierLogic;
use app\api\jxc\logic\WorkforceLogic;
use app\common\enum\AdminTerminalEnum;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

/** bootstrap 在任何连接前校验隔离库；仅清理本套件专属租户。 */
final class MasterCreationReceiptWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;

    private const RECEIPT_TENANT = 882531;
    private const RECEIPT_OTHER_TENANT = 882532;
    private const RECEIPT_ACTOR = 992531;
    private const RECEIPT_OTHER_ACTOR = 992532;

    protected function setUp(): void
    {
        $this->context();
        $root = dirname(__DIR__, 2);
        $this->runStatements($this->prepareMigration((string)file_get_contents(
            $root . '/database/migrations/20261023_000001_master_creation_receipt.sql'
        )));
        Db::execute('CREATE TABLE IF NOT EXISTS la_master_receipt_test_effect (
            id int unsigned AUTO_INCREMENT PRIMARY KEY, tenant_id int unsigned NOT NULL,
            label varchar(100) NOT NULL, payload longtext NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $schema = (string)file_get_contents($root . '/database/sql/jxc_phase1_schema.sql');
        foreach (['customer', 'vendor'] as $table) {
            $this->runStatements($this->authoritativeCreateTable($schema, $table));
        }
        // Earlier workflow fixtures deliberately use reduced master schemas.
        // Add only current adapter fields; never drop or rewrite other tables.
        $this->ensureMasterColumns('customer', [
            'contact' => "varchar(50) NOT NULL DEFAULT ''", 'remark' => "varchar(500) NOT NULL DEFAULT ''",
            'is_store' => 'tinyint NOT NULL DEFAULT 0',
            'children_count' => 'int unsigned NOT NULL DEFAULT 0', 'order_pay_money' => 'decimal(12,2) NOT NULL DEFAULT 0.00',
        ]);
        $this->ensureMasterColumns('vendor', [
            'contact' => "varchar(50) NOT NULL DEFAULT ''", 'phone' => "varchar(20) NOT NULL DEFAULT ''",
            'address' => "varchar(255) NOT NULL DEFAULT ''", 'remark' => "varchar(500) NOT NULL DEFAULT ''",
            'is_disabled' => 'tinyint NOT NULL DEFAULT 0', 'order_money' => 'decimal(12,2) NOT NULL DEFAULT 0.00',
            'create_time' => 'int unsigned NOT NULL DEFAULT 0', 'update_time' => 'int unsigned NOT NULL DEFAULT 0',
        ]);
        $vehicleSchema = (string)file_get_contents($root . '/database/migrations/20260930_000001_create_customer_delivery_vehicle.sql');
        foreach (['delivery_vehicle', 'customer_delivery_vehicle'] as $table) {
            $this->runStatements($this->authoritativeCreateTable($vehicleSchema, $table));
        }
        $workforceSchema = (string)file_get_contents($root . '/database/migrations/20260810_000001_create_fulfillment_workflow.sql');
        foreach (['employee', 'employee_permission', 'employee_process', 'work_process'] as $table) {
            $this->runStatements($this->authoritativeCreateTable($workforceSchema, $table));
        }
        // Only run the historical-owner migration when its columns are absent.
        if (!in_array('creation_key', array_column(Db::query('SHOW COLUMNS FROM la_employee'), 'Field'), true)) {
            $this->runStatements($this->prepareMigration((string)file_get_contents(
                $root . '/database/migrations/20261015_000001_opening_owner_employee.sql'
            )));
        }
        $this->cleanReceiptFixtures();
    }

    protected function tearDown(): void
    {
        $this->context();
        $this->cleanReceiptFixtures();
    }

    public function test_same_key_same_complete_normalized_payload_replays_original_result_once(): void
    {
        $calls = 0;
        $params = $this->params('replay') + ['name' => ' 客户甲 ', 'nested' => ['b' => ' 乙 ', 'a' => 1],
            'process_ids' => [3, 1], 'permission_keys' => ['employee.manage', 'finance.read']];
        $create = function () use (&$calls): array { $calls++; return $this->effect('replay'); };
        $first = MasterCreationReceipt::run('customer', $params, $create);
        $reordered = ['permission_keys' => ['finance.read', 'employee.manage'], 'process_ids' => [1, 3],
            'nested' => ['a' => 1, 'b' => '乙'], 'name' => '客户甲'] + $this->params('replay');
        self::assertSame($first, MasterCreationReceipt::run('customer', $reordered, $create));
        self::assertSame(1, $calls);
        self::assertSame(1, $this->effectCount());
        self::assertSame(1, $this->receiptCount());
    }

    public function test_changed_nested_non_name_payload_rejects_replay_without_creating_again(): void
    {
        $params = $this->params('fingerprint') + ['name' => '同名', 'address' => ['city' => '杭州'], 'is_enabled' => 1];
        $result = MasterCreationReceipt::run('customer', $params, fn(): array => $this->effect('first'));
        $changed = $params;
        $changed['address']['city'] = '绍兴';
        $this->assertDomainRejected(fn() => MasterCreationReceipt::run('customer', $changed, fn(): array => $this->effect('must-not-run')));
        self::assertSame(1, $this->effectCount());
        self::assertSame($result, MasterCreationReceipt::lookup($this->lookup('customer', 'fingerprint'))['result']);
    }

    public function test_business_failure_rolls_back_all_callback_effects_and_receipt_then_allows_retry(): void
    {
        $params = $this->params('rollback');
        try {
            MasterCreationReceipt::run('customer', $params, function (): array {
                $this->effect('first-related-write');
                $this->effect('second-related-write');
                throw new \DomainException('fixture_business_failure');
            });
            self::fail('Expected the business failure to leave the transaction.');
        } catch (\DomainException $exception) {
            self::assertSame('fixture_business_failure', $exception->getMessage());
        }
        self::assertSame(0, $this->effectCount());
        self::assertSame(0, $this->receiptCount());
        self::assertSame('unknown', MasterCreationReceipt::lookup($this->lookup('customer', 'rollback'))['state']);
        $saved = MasterCreationReceipt::run('customer', $params, fn(): array => $this->effect('retry'));
        self::assertGreaterThan(0, $saved['id']);
        self::assertSame(1, $this->effectCount());
    }

    public function test_unserializable_success_receipt_rolls_back_real_callback_writes(): void
    {
        $stream = fopen('php://memory', 'r');
        try {
            $caught = null;
            try {
                MasterCreationReceipt::run('customer', $this->params('bad-result'), function () use ($stream): array {
                    return $this->effect('must-rollback') + ['unsupported_resource' => $stream];
                });
            } catch (\Throwable $exception) {
                $caught = $exception;
            }
            self::assertNotNull($caught, 'Unencodable receipts must never commit business writes.');
            self::assertSame(0, $this->effectCount());
            self::assertSame(0, $this->receiptCount());
        } finally {
            fclose($stream);
        }
    }

    public function test_database_receipt_update_failure_rolls_back_business_transaction(): void
    {
        $constraint = 'chk_master_receipt_failure_' . bin2hex(random_bytes(5));
        Db::execute("ALTER TABLE la_master_creation_receipt ADD CONSTRAINT `{$constraint}`
            CHECK (tenant_id <> " . self::RECEIPT_TENANT . " OR idempotency_key <> 'receipt-write-failure' OR result_data = '')");
        try {
            $caught = null;
            try {
                MasterCreationReceipt::run('customer', $this->params('receipt-write-failure'), fn(): array => $this->effect('must-rollback'));
            } catch (\Throwable $exception) {
                $caught = $exception;
            }
            self::assertNotNull($caught, 'The real MySQL receipt write must fail.');
            self::assertStringContainsString($constraint, $caught->getMessage());
            self::assertSame(0, $this->effectCount());
            self::assertSame(0, $this->receiptCount());
        } finally {
            Db::execute("ALTER TABLE la_master_creation_receipt DROP CHECK `{$constraint}`");
        }
    }

    /** @dataProvider realAdapters */
    public function test_receipt_sql_failure_rolls_back_real_master_and_related_rows(string $kind): void
    {
        $constraint = 'chk_master_adapter_failure_' . bin2hex(random_bytes(5));
        Db::execute("ALTER TABLE la_master_creation_receipt ADD CONSTRAINT `{$constraint}`
            CHECK (tenant_id <> " . self::RECEIPT_TENANT . " OR idempotency_key <> 'adapter-write-failure' OR result_data = '')");
        try {
            $caught = null;
            try {
                $params = $this->params('adapter-write-failure');
                if ($kind === 'customer') {
                    CustomerLogic::add($params + ['customer_name' => '必须回滚的客户', 'customer_type' => 'customer', 'parent_id' => 0]);
                } elseif ($kind === 'supplier') {
                    SupplierLogic::add($params + ['supplier_name' => '必须回滚的供应商']);
                } else {
                    WorkforceLogic::saveEmployee($params + ['creation_key' => 'adapter-write-failure',
                        'name' => '必须回滚的员工', 'mobile' => '13800005313', 'bind_user_id' => 0,
                        'permission_keys' => ['employee.manage'], 'is_enabled' => 1]);
                }
            } catch (\Throwable $exception) {
                $caught = $exception;
            }
            self::assertNotNull($caught, 'A successful business adapter must reach the injected receipt write failure.');
            self::assertStringContainsString($constraint, $caught->getMessage());
            foreach (['customer', 'vendor', 'employee', 'employee_permission'] as $table) {
                self::assertSame(0, Db::name($table)->where('tenant_id', self::RECEIPT_TENANT)->count(), $table);
            }
            self::assertSame(0, $this->receiptCount());
        } finally {
            Db::execute("ALTER TABLE la_master_creation_receipt DROP CHECK `{$constraint}`");
        }
    }

    public static function realAdapters(): array
    {
        return [['customer'], ['supplier'], ['employee']];
    }

    public function test_lost_success_response_can_be_read_without_reexecuting_business_creation(): void
    {
        MasterCreationReceipt::run('supplier', $this->params('lost-response'), fn(): array => $this->effect('saved'));
        // Deliberately discard the return value, as a connection loss would.
        $receipt = MasterCreationReceipt::lookup($this->lookup('supplier', 'lost-response'));
        self::assertIsArray($receipt);
        self::assertSame('succeeded', $receipt['state']);
        self::assertSame((int)Db::name('master_receipt_test_effect')->where('tenant_id', self::RECEIPT_TENANT)->value('id'), $receipt['result']['id']);
        self::assertSame(1, $this->effectCount());
        self::assertSame(1, $this->receiptCount());
    }

    public function test_unknown_lookup_is_read_only_and_does_not_claim_creation_failed(): void
    {
        $receipt = MasterCreationReceipt::lookup($this->lookup('customer', 'never-sent'));
        self::assertIsArray($receipt);
        self::assertSame('unknown', $receipt['state']);
        self::assertNull($receipt['result']);
        self::assertSame(0, $this->effectCount());
        self::assertSame(0, $this->receiptCount());
    }

    public function test_tenant_actor_and_kind_are_independent_receipt_scopes_and_foreign_lookup_does_not_leak(): void
    {
        $first = MasterCreationReceipt::run('customer', $this->params('scope'), fn(): array => $this->effect('owner'));
        $this->context(self::RECEIPT_TENANT, self::RECEIPT_OTHER_ACTOR);
        self::assertSame('unknown', MasterCreationReceipt::lookup($this->lookup('customer', 'scope'))['state']);
        $otherActor = MasterCreationReceipt::run('customer', $this->params('scope'), fn(): array => $this->effect('other-actor'));
        $this->context(self::RECEIPT_OTHER_TENANT);
        self::assertSame('unknown', MasterCreationReceipt::lookup($this->lookup('customer', 'scope'))['state']);
        $otherTenant = MasterCreationReceipt::run('customer', $this->params('scope'), fn(): array => $this->effect('other-tenant'));
        $this->context();
        self::assertSame('unknown', MasterCreationReceipt::lookup($this->lookup('supplier', 'scope'))['state']);
        $otherKind = MasterCreationReceipt::run('supplier', $this->params('scope'), fn(): array => $this->effect('other-kind'));
        request()->jxcFromUserToken = true;
        self::assertSame('unknown', MasterCreationReceipt::lookup($this->lookup('customer', 'scope'))['state']);
        $otherIdentitySpace = MasterCreationReceipt::run('customer', $this->params('scope'), fn(): array => $this->effect('user-identity'));
        self::assertCount(5, array_unique([$first['id'], $otherActor['id'], $otherTenant['id'], $otherKind['id'], $otherIdentitySpace['id']]));
        self::assertSame(5, $this->receiptCount());
        $this->context();
        self::assertSame($first, MasterCreationReceipt::lookup($this->lookup('customer', 'scope'))['result']);
    }

    public function test_expected_tenant_mismatch_prevents_creation_and_readback(): void
    {
        $bad = ['idempotency_key' => 'wrong-tenant', 'expected_tenant_id' => self::RECEIPT_OTHER_TENANT];
        $this->assertDomainRejected(fn() => MasterCreationReceipt::run('customer', $bad, fn(): array => $this->effect('forbidden')));
        self::assertFalse(MasterCreationReceipt::lookup(['kind' => 'customer'] + $bad));
        self::assertSame(0, $this->receiptCount());
        self::assertSame(0, $this->effectCount());
    }

    public function test_original_creation_fact_survives_actual_supplier_disabling_and_deletion(): void
    {
        $params = $this->params('real-supplier') + ['supplier_name' => '回执供应商', 'contact' => '原联系人', 'phone' => '13800005310',
            'address' => '原地址', 'remark' => '完整原业务字段', 'is_disabled' => 0];
        $first = SupplierLogic::add($params);
        self::assertNotFalse($first, SupplierLogic::getError());
        $id = (int)$first['id'];
        self::assertGreaterThan(0, $id);
        self::assertSame('完整原业务字段', Db::name('vendor')->where('id', $id)->value('remark'));
        Db::name('vendor')->where('id', $id)->update(['is_disabled' => 1]);
        self::assertSame($first, SupplierLogic::add($params), SupplierLogic::getError());
        self::assertSame(1, (int)Db::name('vendor')->where('id', $id)->value('is_disabled'));
        Db::name('vendor')->where('id', $id)->delete();
        self::assertSame('succeeded', MasterCreationReceipt::lookup($this->lookup('supplier', 'real-supplier'))['state']);
        self::assertSame($first, SupplierLogic::add($params), SupplierLogic::getError());
        self::assertSame(0, Db::name('vendor')->where('tenant_id', self::RECEIPT_TENANT)->count());
    }

    public function test_customer_adapter_replays_full_response_without_customer_group_fields(): void
    {
        $params = $this->params('real-customer') + ['customer_name' => '回执客户', 'customer_type' => 'customer', 'parent_id' => 0,
            'contact' => '原客户联系人', 'phone' => '13800005311', 'address' => '客户地址', 'remark' => '客户完整说明'];
        $first = CustomerLogic::add($params);
        self::assertNotFalse($first, CustomerLogic::getError());
        self::assertSame($first, CustomerLogic::add($params), CustomerLogic::getError());
        self::assertSame(1, Db::name('customer')->where('tenant_id', self::RECEIPT_TENANT)->count());
        self::assertArrayNotHasKey('group_id', $first);
        self::assertArrayNotHasKey('group_name', $first);
        self::assertSame('客户完整说明', Db::name('customer')->where('id', (int)$first['id'])->value('remark'));
        $changed = $params;
        $changed['remark'] = '不允许悄悄替换的说明';
        self::assertFalse(CustomerLogic::add($changed));
        self::assertSame($first, MasterCreationReceipt::lookup($this->lookup('customer', 'real-customer'))['result']);
    }

    public function test_employee_creation_key_replays_full_result_but_changed_permission_or_binding_is_rejected(): void
    {
        $params = ['creation_key' => 'employee-full-payload', 'expected_tenant_id' => self::RECEIPT_TENANT,
            'name' => '回执员工', 'mobile' => '13800005312', 'mobile_unknown' => 0, 'bind_user_id' => 0,
            'permission_keys' => ['employee.manage'], 'is_enabled' => 1, 'process_ids' => []];
        $first = WorkforceLogic::saveEmployee($params);
        self::assertNotFalse($first, WorkforceLogic::getError());
        self::assertSame($first, WorkforceLogic::saveEmployee($params), WorkforceLogic::getError());
        self::assertSame(['employee.manage'], $first['permission_keys']);
        foreach ([['permission_keys' => []], ['bind_user_id' => 987654], ['is_enabled' => 0], ['process_ids' => [17]]] as $change) {
            self::assertFalse(WorkforceLogic::saveEmployee(array_replace($params, $change)), json_encode($change));
        }
        self::assertSame(1, Db::name('employee')->where('tenant_id', self::RECEIPT_TENANT)->count());
        self::assertSame(['employee.manage'], Db::name('employee_permission')->where('tenant_id', self::RECEIPT_TENANT)->column('permission_key'));
        self::assertSame($first, MasterCreationReceipt::lookup(['kind' => 'employee', 'creation_key' => 'employee-full-payload',
            'expected_tenant_id' => self::RECEIPT_TENANT])['result']);
    }

    public function test_employee_receipts_require_manage_permission_for_creation_and_lookup(): void
    {
        $first = MasterCreationReceipt::run('employee', $this->params('employee-protected'), fn(): array => $this->effect('employee'));
        // Keep the same authenticated actor but revoke the required permission.
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::RECEIPT_TENANT, 'admin_id' => self::RECEIPT_ACTOR];
        self::assertFalse(MasterCreationReceipt::lookup($this->lookup('employee', 'employee-protected')));
        $this->assertDomainRejected(fn() => MasterCreationReceipt::run('employee', $this->params('employee-protected'), fn(): array => $this->effect('forbidden')));
        self::assertSame(1, $this->receiptCount());
        self::assertSame(1, $this->effectCount());
        $this->context();
        self::assertSame($first, MasterCreationReceipt::lookup($this->lookup('employee', 'employee-protected'))['result']);
    }

    public function test_two_real_processes_same_key_same_payload_create_exactly_once(): void
    {
        $responses = $this->concurrent([$this->params('race') + ['name' => '并发客户'], $this->params('race') + ['name' => '并发客户']]);
        self::assertTrue($responses[0]['ok'], json_encode($responses, JSON_UNESCAPED_UNICODE));
        self::assertTrue($responses[1]['ok'], json_encode($responses, JSON_UNESCAPED_UNICODE));
        self::assertNotSame($responses[0]['connection_id'], $responses[1]['connection_id']);
        self::assertSame($responses[0]['result'], $responses[1]['result']);
        self::assertSame(1, $this->effectCount());
        self::assertSame(1, $this->receiptCount());
    }

    public function test_two_real_processes_same_key_different_payload_reject_one_without_second_creation(): void
    {
        $responses = $this->concurrent([$this->params('race-change') + ['name' => '原稿'], $this->params('race-change') + ['name' => '改稿']]);
        self::assertCount(1, array_filter($responses, static fn(array $r): bool => $r['ok']));
        self::assertCount(1, array_filter($responses, static fn(array $r): bool => !$r['ok']));
        self::assertNotSame($responses[0]['connection_id'], $responses[1]['connection_id']);
        self::assertSame(1, $this->effectCount());
        self::assertSame(1, $this->receiptCount());
    }

    private function context(int $tenantId = self::RECEIPT_TENANT, int $actorId = self::RECEIPT_ACTOR): void
    {
        $this->prepareCustomerReportRequestContext($tenantId);
        request()->source = AdminTerminalEnum::TENANT;
        request()->adminId = $actorId;
        request()->userId = $actorId;
        request()->adminInfo = ['admin_id' => $actorId, 'tenant_id' => $tenantId, 'root' => 1];
    }

    private function params(string $key): array
    {
        return ['idempotency_key' => $key, 'expected_tenant_id' => (int)request()->tenantId];
    }

    private function lookup(string $kind, string $key): array
    {
        return ['kind' => $kind] + $this->params($key);
    }

    private function effect(string $label): array
    {
        return ['id' => (int)Db::name('master_receipt_test_effect')->insertGetId([
            'tenant_id' => (int)request()->tenantId, 'label' => $label, 'payload' => '{}',
        ]), 'label' => $label];
    }

    private function effectCount(): int
    {
        return Db::name('master_receipt_test_effect')->whereIn('tenant_id', [self::RECEIPT_TENANT, self::RECEIPT_OTHER_TENANT])->count();
    }

    private function receiptCount(): int
    {
        return Db::name('master_creation_receipt')->whereIn('tenant_id', [self::RECEIPT_TENANT, self::RECEIPT_OTHER_TENANT])->count();
    }

    private function cleanReceiptFixtures(): void
    {
        foreach (['master_creation_receipt', 'master_receipt_test_effect', 'employee_permission', 'employee_process',
            'employee', 'customer', 'vendor'] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::RECEIPT_TENANT, self::RECEIPT_OTHER_TENANT])->delete();
        }
    }

    private function ensureMasterColumns(string $table, array $columns): void
    {
        $existing = array_column(Db::query('SHOW COLUMNS FROM `la_' . $table . '`'), 'Field');
        foreach ($columns as $name => $definition) {
            if (!in_array($name, $existing, true)) {
                Db::execute('ALTER TABLE `la_' . $table . '` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
    }

    private function assertDomainRejected(callable $action): void
    {
        try {
            $action();
        } catch (\DomainException $exception) {
            self::assertNotSame('', $exception->getMessage());
            return;
        }
        self::fail('Expected a domain rejection before business creation.');
    }

    private function concurrent(array $payloads): array
    {
        self::assertTrue(function_exists('proc_open'), 'Two-process MySQL verification requires proc_open.');
        $paths = [];
        $processes = [];
        $startPath = tempnam(sys_get_temp_dir(), 'master-receipt-start-');
        unlink($startPath);
        try {
            foreach ($payloads as $params) {
                $inputPath = tempnam(sys_get_temp_dir(), 'master-receipt-in-');
                $outputPath = tempnam(sys_get_temp_dir(), 'master-receipt-out-');
                array_push($paths, $inputPath, $outputPath, $outputPath . '.ready');
                file_put_contents($inputPath, json_encode(['tenant_id' => self::RECEIPT_TENANT,
                    'actor_id' => self::RECEIPT_ACTOR, 'params' => $params], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/master_creation_receipt_worker.php',
                    $inputPath, $outputPath, $startPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes, $outputPath];
            }
            $ready = false;
            for ($attempt = 0; $attempt < 500; $attempt++) {
                clearstatcache();
                $ready = count(array_filter($processes, static fn(array $p): bool => is_file($p[2] . '.ready'))) === count($payloads);
                if ($ready) { break; }
                usleep(10_000);
            }
            self::assertTrue($ready, 'Both separately bootstrapped MySQL connections must reach the barrier.');
            touch($startPath);
            $responses = [];
            foreach ($processes as [$process, $pipes, $outputPath]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $stdout . $stderr);
                $responses[] = json_decode((string)file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
            }
            return $responses;
        } finally {
            foreach ($processes as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) { fclose($pipe); }
                }
                if (is_resource($process)) {
                    if (proc_get_status($process)['running']) { proc_terminate($process); }
                    proc_close($process);
                }
            }
            foreach (array_merge($paths, [$startPath]) as $path) {
                if (is_file($path)) { unlink($path); }
            }
        }
    }
}
