<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use tests\support\IsolatedDatabaseGuard;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

/** 经真实 TCP、生产路由、JSON 过滤器及数据库会话鉴权；只写专属合成租户。 */
final class PreparationHttpWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;

    private const HTTP_TENANTS = [882641, 882642];
    private const HTTP_ACTORS = [992641, 992642, 992643];
    private static $server;
    private static $log;
    private static string $baseUrl;
    private static array $httpObservations = [];
    private static $scopeLock;
    private static bool $bridgeMode = false;
    private static bool $nativeRuntimeMode = false;
    private static array $nativeRuntimePaths = [];
    private array $tokens = [];
    private int $sequence = 0;

    public static function setUpBeforeClass(): void
    {
        if (!IsolatedDatabaseGuard::acceptsConnection(Db::getConfig('connections.mysql'))) {
            throw new \RuntimeException('unsafe_preparation_http_database');
        }
        self::$scopeLock = fopen(dirname(__DIR__, 2) . '/.local/preparation-http-workflow.lock', 'c');
        if (!is_resource(self::$scopeLock) || !flock(self::$scopeLock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('bridge_synthetic_scope_busy');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        if ($socket === false) { throw new \RuntimeException('http_test_port_unavailable'); }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$baseUrl = 'http://' . $address . '/api/';
        self::$log = self::$bridgeMode ? fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w') : tmpfile();
        $serverCommand = [PHP_BINARY];
        if (self::$bridgeMode) { array_push($serverCommand, '-d', 'display_errors=0', '-d', 'log_errors=0'); }
        if (self::$nativeRuntimeMode) { array_push($serverCommand, '-d', 'sys_temp_dir=' . sys_get_temp_dir()); }
        array_push($serverCommand, '-S', $address, dirname(__DIR__) . '/fixtures/preparation_http_router.php');
        self::$server = proc_open($serverCommand,
            [0 => ['pipe', 'r'], 1 => self::$log, 2 => self::$log], $pipes, dirname(__DIR__, 2),
            array_merge(getenv(), ['JXC_PREPARATION_HTTP_TEST' => '1', 'JXC_PREPARATION_HTTP_BRIDGE' => self::$bridgeMode ? '1' : '0']));
        if (!is_resource(self::$server)) { throw new \RuntimeException('http_test_server_unavailable'); }
        if (self::$nativeRuntimeMode) { self::$nativeRuntimePaths[] = sys_get_temp_dir() . '/beimi-preparation-http-' . proc_get_status(self::$server)['pid'] . '/'; }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $ready = @stream_socket_client('tcp://' . $address, $errorCode, $error, 0.1);
            if ($ready !== false) { fclose($ready); return; }
            usleep(50000);
        }
        self::tearDownAfterClass();
        throw new \RuntimeException('http_test_server_start_timeout');
    }

    public static function tearDownAfterClass(): void
    {
        self::stopHttpServer();
        if (is_resource(self::$scopeLock)) { flock(self::$scopeLock, LOCK_UN); fclose(self::$scopeLock); }
        $evidence = getenv('JXC_PREPARATION_HTTP_EVIDENCE');
        if (!self::$bridgeMode && $evidence !== false && $evidence !== '') {
            file_put_contents($evidence, json_encode(['transport' => '真实本机 TCP HTTP',
                'base_url' => self::$baseUrl, 'authentication' => '生产 JxcLoginMiddleware + 数据库合成会话',
                'database' => Db::getConfig('connections.mysql.database'), 'observations' => self::$httpObservations],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
    }

    private static function stopHttpServer(): void
    {
        if (is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
        if (is_resource(self::$log)) { fclose(self::$log); }
        self::$server = null; self::$log = null;
    }

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext(self::HTTP_TENANTS[0]);
        $this->ensureCustomerReportTables();
        $root = dirname(__DIR__, 2);
        foreach (['20260907_000001_finance_preparation.sql', '20260907_000002_finance_opening.sql',
            '20260907_000003_finance_opening_details.sql', '20260907_000004_finance_business.sql',
            '20260907_000014_finance_cost.sql', '20261012_000002_finance_purchase_legacy_source.sql',
            '20261023_000001_master_creation_receipt.sql'] as $migration) {
            $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/' . $migration)));
        }
        $schema = (string)file_get_contents($root . '/database/sql/jxc_phase1_schema.sql');
        foreach (['customer', 'customer_group', 'vendor'] as $table) {
            $this->runStatements($this->authoritativeCreateTable($schema, $table));
        }
        $this->clean();
        foreach (self::HTTP_TENANTS as $tenant) {
            Db::name('tenant')->insert(['id' => $tenant, 'sn' => 'HTTP' . $tenant, 'name' => '合成 HTTP 测试门店',
                'disable' => 0, 'expired_time' => time() + 86400, 'create_time' => time(), 'update_time' => time()]);
        }
        foreach (self::HTTP_ACTORS as $offset => $actor) {
            $tenant = $offset === 2 ? self::HTTP_TENANTS[1] : self::HTTP_TENANTS[0];
            $token = bin2hex(random_bytes(16));
            $this->tokens[$actor] = $token;
            Db::name('user')->insert(['id' => $actor, 'tenant_id' => $tenant, 'sn' => $actor,
                'nickname' => '合成测试用户', 'account' => 'http-' . $actor, 'mobile' => '1390000' . substr((string)$actor, -4),
                'create_time' => time(), 'update_time' => time()]);
            Db::name('user_session')->insert(['user_id' => $actor, 'token' => $token, 'terminal' => 1,
                'expire_time' => time() + 86400, 'update_time' => time()]);
            Db::name('tenant_member')->insert(['tenant_id' => $tenant, 'user_id' => $actor, 'role' => 'owner',
                'status' => 1, 'create_time' => time(), 'update_time' => time()]);
        }
        // 与用户相同数字 ID，但拥有独立的管理员会话及身份空间。
        Db::name('tenant_admin')->insert(['id' => self::HTTP_ACTORS[0], 'tenant_id' => self::HTTP_TENANTS[0],
            'root' => 1, 'name' => '合成管理员', 'account' => 'http-admin', 'password' => 'synthetic-not-loginable',
            'login_ip' => '127.0.0.1', 'disable' => 0, 'create_time' => time(), 'update_time' => time()]);
        $this->tokens['admin'] = bin2hex(random_bytes(16));
        Db::name('tenant_admin_session')->insert(['admin_id' => self::HTTP_ACTORS[0], 'token' => $this->tokens['admin'],
            'terminal' => 1, 'expire_time' => time() + 86400, 'update_time' => time()]);
    }

    protected function tearDown(): void { $this->clean(); }

    /** 仅供显式 CLI 驱动调用，不是默认 PHPUnit 用例。 */
    public static function runFrontendHttpBridge(string $clientPath, string $nodeBinary, string $target = 'payable', array &$fixtureEvidence = []): array
    {
        if (!in_array($target, ['payable', 'customer_refund', 'advance', 'supplier_refund', 'salary', 'reimbursement', 'expense_payable', 'deferred'], true)) {
            throw new \RuntimeException('bridge_invalid_fixture_target');
        }
        require_once dirname(__DIR__) . '/support/PreparationHttpBridgeProcess.php';
        $fixture = new self('runFrontendHttpBridge');
        $seedStarted = false;
        $bridgePeer = 0; $fixtureEvidence = [];
        self::$bridgeMode = true;
        try {
            self::setUpBeforeClass();
            // 不接管旧运行遗留或其他测试正在使用的合成资料。
            $tables = Db::query("SELECT TABLE_NAME AS table_name FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='tenant_id'");
            foreach ($tables as $table) {
                if (Db::table($table['table_name'])->whereIn('tenant_id', self::HTTP_TENANTS)->count() > 0) {
                    throw new \RuntimeException('bridge_synthetic_scope_not_empty');
                }
            }
            if (Db::name('tenant')->whereIn('id', self::HTTP_TENANTS)->count() > 0) {
                throw new \RuntimeException('bridge_synthetic_scope_not_empty');
            }
            foreach (['user_session' => 'user_id', 'tenant_admin_session' => 'admin_id'] as $table => $column) {
                if (Db::name($table)->whereIn($column, self::HTTP_ACTORS)->count() > 0) {
                    throw new \RuntimeException('bridge_synthetic_scope_not_empty');
                }
            }
            $seedStarted = true;
            $fixture->setUp();
            $fixture->start('2026-10-17');
            if (in_array($target, ['salary', 'reimbursement', 'expense_payable', 'deferred'], true)) {
                $bridgePeer = $fixture->seedBridgePeer($target);
                $fixtureEvidence = ['before' => $fixture->bridgePeerState($bridgePeer), 'verified' => false];
            }
            $actors = [];
            foreach (['owner' => self::HTTP_ACTORS[0], 'peer' => self::HTTP_ACTORS[1],
                'otherStore' => self::HTTP_ACTORS[2], 'admin' => 'admin'] as $name => $actor) {
                $actors[$name] = ['actorType' => $name === 'admin' ? 'tenant_admin' : 'user',
                    'actorId' => $name === 'admin' ? self::HTTP_ACTORS[0] : $actor,
                    'tenantId' => $name === 'otherStore' ? self::HTTP_TENANTS[1] : self::HTTP_TENANTS[0],
                    'token' => $fixture->tokens[$actor]];
            }
            return \tests\support\PreparationHttpBridgeProcess::run([$nodeBinary, $clientPath], [
                'protocol' => \tests\support\PreparationHttpBridgeProcess::PROTOCOL,
                'runId' => 'preparation-development-20261024-01', 'baseUrl' => self::$baseUrl,
                'cutoffDate' => '2026-10-16', 'actors' => $actors], 120000, $nodeBinary);
        } finally {
            try {
                // 客户端已退出后先停止自己 HTTP 进程，防止迟到写入发生在清理之后。
                self::stopHttpServer();
                try {
                    if ($bridgePeer > 0) {
                        $fixtureEvidence['after'] = $fixture->bridgePeerState($bridgePeer);
                        $fixtureEvidence['verified'] = $fixtureEvidence['before'] === $fixtureEvidence['after'];
                    }
                } finally { if ($seedStarted) { $fixture->tearDown(); } }
            }
            finally { self::tearDownAfterClass(); self::$bridgeMode = false; }
        }
    }

    /** 固定原生验收CLI专用；callback与凭据仅在父进程内存中，不经argv/env/日志。 */
    public static function runNativeRuntimeFixture(callable $duringRuntime, array &$evidence = []): void
    {
        $fixture = new self('runNativeRuntimeFixture'); $seedStarted = false; $peer = 0;
        $employee = []; $member = []; $permissions = []; $privacyPaths = []; $actors = []; self::$bridgeMode = true;
        self::$nativeRuntimeMode = true; self::$nativeRuntimePaths = [app()->getRuntimePath()];
        $hash = static fn(array $row): string => hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $directory = dirname(__DIR__, 2) . '/.local/final-runtime-backend-20261026/service-attempt-03';
        try {
            self::setUpBeforeClass();
            $tables = Db::query("SELECT TABLE_NAME AS table_name FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='tenant_id'");
            foreach ($tables as $table) {
                if (Db::table($table['table_name'])->whereIn('tenant_id', self::HTTP_TENANTS)->count() > 0) {
                    throw new \RuntimeException('native_runtime_scope_not_empty');
                }
            }
            if (Db::name('tenant')->whereIn('id', self::HTTP_TENANTS)->count() > 0
                || Db::name('user_session')->whereIn('user_id', self::HTTP_ACTORS)->count() > 0
                || Db::name('tenant_admin_session')->whereIn('admin_id', self::HTTP_ACTORS)->count() > 0) {
                throw new \RuntimeException('native_runtime_scope_not_empty');
            }
            $seedStarted = true; $fixture->setUp(); $fixture->start('2026-10-17');
            $peer = $fixture->seedBridgePeer('salary');
            $employee = Db::name('employee')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $peer)->find();
            $member = Db::name('tenant_member')->where('tenant_id', self::HTTP_TENANTS[0])->where('user_id', self::HTTP_ACTORS[1])->find();
            $permissions = Db::name('employee_permission')->where('tenant_id', self::HTTP_TENANTS[0])->where('employee_id', $peer)->order('id')->select()->toArray();
            $evidence = ['peerBefore' => $fixture->bridgePeerState($peer), 'employeeAllColumnsBeforeSha256' => $hash($employee),
                'memberAllColumnsBeforeSha256' => $hash($member), 'permissionRowsBeforeSha256' => $hash($permissions), 'peerRestored' => false];
            self::writeNativeRuntimeJson($directory . '/fixture-baseline.json', $evidence + ['runId' => 'preparation-development-20261024-01',
                'dispatchId' => 'takeover-20261027-native-lease-03', 'parentProcessId' => getmypid(), 'recordedAt' => gmdate('c')]);

            // 首先换成仅本轮可用的双重闸门与内存缓存router，再做只读连接自检。
            self::stopHttpServer();
            $hex = bin2hex(random_bytes(16));
            $shared = ['runId' => 'preparation-development-20261024-01', 'dispatchId' => 'takeover-20261027-native-lease-03',
                'leaseId' => substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20),
                'expiresAt' => gmdate('c', time() + 2700), 'phase' => 'prepared'];
            self::writeNativeRuntimeJson($directory . '/control.json', $shared + ['sequence' => 0]);
            self::writeNativeRuntimeJson($directory . '/state.json', $shared + ['heartbeatAt' => gmdate('c'), 'controlSequence' => 0, 'claimed' => false]);
            self::startNativeRuntimeServer();
            $actors = [];
            foreach (['owner' => self::HTTP_ACTORS[0], 'peer' => self::HTTP_ACTORS[1], 'otherStore' => self::HTTP_ACTORS[2], 'admin' => 'admin'] as $name => $actor) {
                $actors[$name] = ['actorType' => $name === 'admin' ? 'tenant_admin' : 'user',
                    'actorId' => $name === 'admin' ? self::HTTP_ACTORS[0] : $actor,
                    'tenantId' => $name === 'otherStore' ? self::HTTP_TENANTS[1] : self::HTTP_TENANTS[0], 'token' => $fixture->tokens[$actor]];
            }
            $checks = [];
            foreach ($actors as $name => $identity) {
                self::writeNativeRuntimeJson($directory . '/state.json', $shared + ['heartbeatAt' => gmdate('c'), 'controlSequence' => 0, 'claimed' => false]);
                $actor = $name === 'admin' ? 'admin' : $identity['actorId'];
                if ($name !== 'admin') {
                    $status = $fixture->ok($fixture->http('GET', 'user/store/status', [], $actor));
                    if (($status['has_store'] ?? null) !== true || (int)($status['current_store_id'] ?? 0) !== $identity['tenantId']
                        || (int)($status['current_store']['id'] ?? 0) !== $identity['tenantId']
                        || ($status['current_store']['role'] ?? null) !== ($name === 'peer' ? 'member' : 'owner')) {
                        throw new \RuntimeException('native_runtime_store_selfcheck_failed');
                    }
                    $checks[] = ['id' => $name . '-store-status', 'passed' => true];
                }
                $guide = $fixture->ok($fixture->http('GET', 'finance/opening/guide', [], $actor));
                if ((int)$guide['tenant_id'] !== $identity['tenantId'] || (int)$guide['operator_id'] !== $identity['actorId']
                    || $guide['actor_type'] !== $identity['actorType']) { throw new \RuntimeException('native_runtime_identity_selfcheck_failed'); }
                if (in_array($name, ['owner', 'peer'], true)
                    && (($guide['mode'] ?? '') !== 'carryover' || ($guide['opening']['activation_date'] ?? '') !== '2026-10-17')) {
                    throw new \RuntimeException('native_runtime_opening_selfcheck_failed');
                }
                $checks[] = ['id' => $name . '-guide-identity', 'passed' => true];
            }
            $blocked = $fixture->http('POST', 'finance/opening/guide/evidence/save', [], self::HTTP_ACTORS[0]);
            if (($blocked['code'] ?? null) !== 0 || ($blocked['msg'] ?? '') !== 'runtime_gate_closed') { throw new \RuntimeException('native_runtime_gate_selfcheck_failed'); }
            $checks[] = ['id' => 'direct-php-write-gate-closed', 'passed' => true];
            $book = Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find();
            if ((int)($book['tenant_id'] ?? 0) !== self::HTTP_TENANTS[0] || (int)($book['version'] ?? 0) < 1 || ($book['status'] ?? '') !== 'draft') {
                throw new \RuntimeException('native_runtime_book_scope_failed');
            }
            $checks[] = ['id' => 'opening-book-tenant-primary-key', 'passed' => true];
            $evidence['readOnlyChecks'] = $checks;
            $evidence['runtimeScope'] = ['primaryTenantId' => self::HTTP_TENANTS[0], 'boundaryTenantId' => self::HTTP_TENANTS[1],
                'openingBookTenantId' => (int)$book['tenant_id'], 'initialOpeningVersion' => (int)$book['version'],
                'openingStatus' => $book['status'] ?? '', 'activationDate' => '2026-10-17', 'cutoffDate' => '2026-10-16',
                'preparationCategories' => ['payable', 'customer_refund', 'advance', 'supplier_refund', 'salary', 'reimbursement', 'expense_payable', 'deferred']];
            $privacyPaths = self::$nativeRuntimePaths;
            $duringRuntime(['protocol' => 'beimi-preparation-native-runtime/v1', 'runId' => $shared['runId'], 'dispatchId' => $shared['dispatchId'],
                'upstreamBaseUrl' => self::$baseUrl, 'actors' => $actors], $checks, $evidence['peerBefore'], $evidence['runtimeScope'],
                $privacyPaths);
        } finally {
            // callback先关闭Node及在途代理；随后停PHP，最后恢复资料、清理和释放锁。
            self::stopHttpServer();
            try {
                if ($peer > 0 && $employee !== []) {
                    Db::name('employee')->where('tenant_id', self::HTTP_TENANTS[0])->where('bind_user_id', self::HTTP_ACTORS[1])->where('id', '<>', $peer)->update(['bind_user_id' => 0]);
                    $query = Db::name('employee')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $peer);
                    if ($query->find()) { $query->update($employee); } else { Db::name('employee')->insert($employee); }
                    $query = Db::name('tenant_member')->where('tenant_id', self::HTTP_TENANTS[0])->where('user_id', self::HTTP_ACTORS[1]);
                    if ($query->find()) { $query->update($member); } else { Db::name('tenant_member')->insert($member); }
                    Db::name('employee_permission')->where('tenant_id', self::HTTP_TENANTS[0])->where('employee_id', $peer)->delete();
                    if ($permissions !== []) { Db::name('employee_permission')->insertAll($permissions); }
                    $evidence['peerAfter'] = $fixture->bridgePeerState($peer);
                    $evidence['employeeAllColumnsAfterSha256'] = $hash(Db::name('employee')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $peer)->find());
                    $evidence['memberAllColumnsAfterSha256'] = $hash(Db::name('tenant_member')->where('tenant_id', self::HTTP_TENANTS[0])->where('user_id', self::HTTP_ACTORS[1])->find());
                    $evidence['permissionRowsAfterSha256'] = $hash(Db::name('employee_permission')->where('tenant_id', self::HTTP_TENANTS[0])->where('employee_id', $peer)->order('id')->select()->toArray());
                    $evidence['peerRestored'] = $evidence['peerBefore'] === $evidence['peerAfter']
                        && $evidence['employeeAllColumnsBeforeSha256'] === $evidence['employeeAllColumnsAfterSha256']
                        && $evidence['memberAllColumnsBeforeSha256'] === $evidence['memberAllColumnsAfterSha256']
                        && $evidence['permissionRowsBeforeSha256'] === $evidence['permissionRowsAfterSha256'];
                }
            } finally {
                try {
                    $evidence['credentialFilesAfterStopChecked'] = \tests\support\PreparationNativeRuntimePrivacy::assertNoCredentialFiles(self::$nativeRuntimePaths, array_values($fixture->tokens));
                } finally {
                    try { if ($seedStarted) { $fixture->tearDown(); } }
                    finally { self::tearDownAfterClass(); self::$bridgeMode = false; self::$nativeRuntimeMode = false; self::$nativeRuntimePaths = []; }
                }
            }
        }
    }

    public static function writeNativeRuntimeJson(string $path, array $value): void
    {
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false
            || !rename($temporary, $path)) { throw new \RuntimeException('native_runtime_state_write_failed'); }
    }

    private static function startNativeRuntimeServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) { throw new \RuntimeException('native_runtime_port_failed'); }
        $address = stream_socket_get_name($socket, false); fclose($socket);
        self::$baseUrl = 'http://' . $address . '/api/';
        self::$log = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w');
        self::$server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'sys_temp_dir=' . sys_get_temp_dir(), '-S', $address,
            dirname(__DIR__) . '/fixtures/preparation_native_runtime_router.php'],
            [0 => ['pipe', 'r'], 1 => self::$log, 2 => self::$log], $pipes, dirname(__DIR__, 2),
            array_merge(getenv(), ['JXC_PREPARATION_HTTP_TEST' => '1', 'JXC_PREPARATION_HTTP_BRIDGE' => '1']));
        if (!is_resource(self::$server)) { throw new \RuntimeException('native_runtime_server_failed'); }
        self::$nativeRuntimePaths[] = sys_get_temp_dir() . '/beimi-preparation-native-' . proc_get_status(self::$server)['pid'] . '/';
        fclose($pipes[0]);
        for ($i = 0; $i < 100; $i++) {
            $connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
            if ($connection !== false) { fclose($connection); return; }
            usleep(50000);
        }
        throw new \RuntimeException('native_runtime_server_timeout');
    }

    public function test_missing_expired_and_removed_member_sessions_cannot_read_receipts(): void
    {
        $query = ['kind' => 'customer', 'idempotency_key' => 'never-sent'];
        self::assertSame(0, $this->http('GET', 'master-data/creation/result', $query, null)['code']);
        Db::name('user_session')->where('user_id', self::HTTP_ACTORS[0])->update(['expire_time' => time() - 1]);
        self::assertSame(-1, $this->http('GET', 'master-data/creation/result', $query)['code']);
        Db::name('tenant_member')->where('user_id', self::HTTP_ACTORS[1])->update(['status' => 0]);
        $removed = $this->http('GET', 'master-data/creation/result', $query, self::HTTP_ACTORS[1]);
        self::assertSame(0, $removed['code']);
        self::assertSame('STORE_REQUIRED', $removed['error_code']);
    }

    public function test_customer_full_form_replays_and_its_receipt_isolated_by_actor_type_actor_and_store(): void
    {
        $payload = $this->customer('customer-full-form');
        $first = $this->ok($this->http('POST', 'customer/add', $payload));
        self::assertSame($first, $this->ok($this->http('POST', 'customer/add', $payload)));
        self::assertSame('完整备注', $first['remark']);
        $query = ['kind' => 'customer', 'idempotency_key' => $payload['idempotency_key']];
        self::assertSame($first, $this->ok($this->http('GET', 'master-data/creation/result', $query))['result']);
        foreach ([self::HTTP_ACTORS[1], self::HTTP_ACTORS[2], 'admin'] as $actor) {
            $unknown = $this->ok($this->http('GET', 'master-data/creation/result', $query, $actor));
            self::assertSame('unknown', $unknown['state']); self::assertNull($unknown['result']);
        }
        self::assertSame(0, $this->http('POST', 'customer/add', array_replace($payload, ['remark' => '变更备注']))['code']);
        self::assertSame(1, Db::name('customer')->where('tenant_id', self::HTTP_TENANTS[0])->count());
        self::assertSame(1, (int)Db::name('customer_group')->where('tenant_id', self::HTTP_TENANTS[0])->value('customer_count'));
    }

    public function test_unknown_lookup_is_read_only_and_changed_store_cannot_replay_original_creation(): void
    {
        $query = ['kind' => 'supplier', 'idempotency_key' => 'unsent-supplier', 'expected_tenant_id' => self::HTTP_TENANTS[0]];
        self::assertSame('unknown', $this->ok($this->http('GET', 'master-data/creation/result', $query))['state']);
        self::assertSame(0, Db::name('master_creation_receipt')->whereIn('tenant_id', self::HTTP_TENANTS)->count());
        self::assertSame(0, $this->http('GET', 'master-data/creation/result', $query, self::HTTP_ACTORS[2])['code']);
        self::assertSame(0, $this->http('POST', 'customer/add', $this->customer('wrong-store'), self::HTTP_ACTORS[2])['code']);
    }

    public function test_customer_add_detail_and_creation_result_expose_current_tenant_without_cross_store_reads(): void
    {
        $payload = $this->customer('customer-tenant-identity');
        $created = $this->ok($this->http('POST', 'customer/add', $payload));
        $detail = $this->ok($this->http('GET', 'customer/detail', [
            'id' => $created['id'], 'expected_tenant_id' => self::HTTP_TENANTS[0],
        ]));
        $query = ['kind' => 'customer', 'idempotency_key' => $payload['idempotency_key'],
            'expected_tenant_id' => self::HTTP_TENANTS[0]];
        $receipt = $this->ok($this->http('GET', 'master-data/creation/result', $query));
        self::assertSame('succeeded', $receipt['state']);
        foreach ([$created, $detail, $receipt['result']] as $customer) {
            self::assertSame(self::HTTP_TENANTS[0], $customer['tenant_id'] ?? null);
            self::assertSame($created['id'], $customer['id']);
            self::assertSame('customer', $customer['customer_type']);
            self::assertSame('enabled', $customer['status']);
        }
        self::assertSame([], $this->ok($this->http('GET', 'customer/detail', [
            'id' => $created['id'], 'expected_tenant_id' => self::HTTP_TENANTS[1],
        ], self::HTTP_ACTORS[2])));
        $otherReceipt = $this->ok($this->http('GET', 'master-data/creation/result',
            array_replace($query, ['expected_tenant_id' => self::HTTP_TENANTS[1]]), self::HTTP_ACTORS[2]));
        self::assertSame('unknown', $otherReceipt['state']);
        self::assertNull($otherReceipt['result']);
    }

    public function test_supplier_original_success_survives_disable_delete_and_rejects_changed_full_payload(): void
    {
        $payload = ['idempotency_key' => 'http-supplier', 'expected_tenant_id' => self::HTTP_TENANTS[0],
            'supplier_name' => 'HTTP 供应商', 'contact' => '供应商联系人', 'phone' => '13800006412',
            'address' => '原地址', 'remark' => '完整供应商说明', 'is_disabled' => 0];
        $first = $this->ok($this->http('POST', 'supplier/add', $payload));
        self::assertSame($first, $this->ok($this->http('POST', 'supplier/add', $payload)));
        self::assertSame(0, $this->http('POST', 'supplier/add', array_replace($payload, ['address' => '另一地址']))['code']);
        $this->ok($this->http('POST', 'supplier/edit', array_replace($payload, ['id' => $first['id'], 'is_disabled' => 1])));
        self::assertSame($first, $this->ok($this->http('POST', 'supplier/add', $payload)));
        $this->ok($this->http('DELETE', 'supplier/del', ['id' => $first['id']]));
        $receipt = $this->ok($this->http('GET', 'master-data/creation/result', ['kind' => 'supplier', 'idempotency_key' => 'http-supplier']));
        self::assertSame('succeeded', $receipt['state']); self::assertSame($first, $receipt['result']);
        self::assertSame($first, $this->ok($this->http('POST', 'supplier/add', $payload)));
        self::assertSame(0, Db::name('vendor')->where('tenant_id', self::HTTP_TENANTS[0])->count());
    }

    public function test_historical_employee_json_flag_and_original_creation_payload_reach_real_controller(): void
    {
        $payload = ['creation_key' => 'http-historical-employee', 'expected_tenant_id' => self::HTTP_TENANTS[0],
            'name' => '历史员工', 'mobile' => '', 'mobile_unknown' => 1, 'bind_user_id' => 0,
            'permission_keys' => [], 'is_enabled' => 1, 'process_ids' => []];
        $first = $this->ok($this->http('POST', 'jxc/workforce/employee/save', $payload));
        self::assertSame($first, $this->ok($this->http('POST', 'jxc/workforce/employee/save', $payload)));
        self::assertSame([], $first['permission_keys']);
        $receipt = $this->ok($this->http('GET', 'master-data/creation/result', ['kind' => 'employee', 'creation_key' => $payload['creation_key']]));
        self::assertSame($first, $receipt['result']);
        self::assertSame(0, $this->http('POST', 'jxc/workforce/employee/save', array_replace($payload, ['permission_keys' => ['employee.manage']]))['code']);
        self::assertSame(0, $this->http('POST', 'jxc/workforce/employee/save', array_replace($payload,
            ['creation_key' => 'http-invalid-employee', 'mobile_unknown' => '1']))['code']);
    }

    public function test_employee_manage_revocation_blocks_receipt_lookup_and_replay_under_real_token(): void
    {
        $actor = self::HTTP_ACTORS[1];
        Db::name('tenant_member')->where('user_id', $actor)->update(['role' => 'member']);
        $manager = $this->grant($actor, ['employee.manage']);
        $payload = ['idempotency_key' => 'http-permission-employee', 'expected_tenant_id' => self::HTTP_TENANTS[0],
            'name' => '权限测试人员', 'mobile' => '13800006413', 'bind_user_id' => 0,
            'permission_keys' => [], 'is_enabled' => 1, 'process_ids' => []];
        $first = $this->ok($this->http('POST', 'jxc/workforce/employee/save', $payload, $actor));
        Db::name('employee_permission')->where('tenant_id', self::HTTP_TENANTS[0])->where('employee_id', $manager)->delete();
        $query = ['kind' => 'employee', 'idempotency_key' => $payload['idempotency_key']];
        self::assertSame(0, $this->http('GET', 'master-data/creation/result', $query, $actor)['code']);
        self::assertSame(0, $this->http('POST', 'jxc/workforce/employee/save', $payload, $actor)['code']);
        Db::name('employee_permission')->insert(['tenant_id' => self::HTTP_TENANTS[0], 'employee_id' => $manager,
            'permission_key' => 'employee.manage', 'create_time' => time()]);
        self::assertSame($first, $this->ok($this->http('GET', 'master-data/creation/result', $query, $actor))['result']);
    }

    public function test_note_is_independent_and_original_receipt_replay_does_not_overwrite_later_note(): void
    {
        $start = $this->start();
        $payload = $this->command($start['opening']['version']) + ['category' => 'customer_refund', 'evidence' => '独立事项说明',
            'choice' => 'none', 'items' => [], 'current_card' => 'salary'];
        $first = $this->ok($this->http('POST', 'finance/opening/guide/evidence/save', $payload));
        self::assertSame($start['progress'], $first['progress']);
        self::assertSame($start['current_card'], $first['current_card']);
        self::assertSame($start['opening']['items'], $first['opening']['items']);
        $second = $this->ok($this->http('POST', 'finance/opening/guide/evidence/save',
            $this->command($first['opening']['version']) + ['category' => 'customer_refund', 'evidence' => '后来更新说明']));
        $replay = $this->ok($this->http('POST', 'finance/opening/guide/evidence/save', $payload));
        self::assertSame($first['opening']['version'], $replay['opening']['version']);
        $current = $this->ok($this->http('GET', 'finance/opening/guide'));
        self::assertSame($second['opening']['version'], $current['opening']['version']);
        $category = array_values(array_filter($current['opening']['categories'], static fn(array $row): bool => $row['key'] === 'customer_refund'))[0];
        self::assertSame('后来更新说明', $category['review']['evidence']);
        self::assertSame('unknown', $category['review']['state']);
        self::assertSame(0, $this->http('POST', 'finance/opening/guide/evidence/save', array_replace($payload, ['evidence' => '同键改载荷']))['code']);
        self::assertSame(0, $this->http('POST', 'finance/opening/guide/evidence/save',
            $this->command($first['opening']['version']) + ['category' => 'customer_refund', 'evidence' => '旧版本新键'])['code']);
    }

    public function test_customer_refund_unknown_amount_editor_and_primary_customer_rules_over_http(): void
    {
        $start = $this->start();
        $customer = $this->ok($this->http('POST', 'customer/add', $this->customer('refund-primary')));
        $child = $this->ok($this->http('POST', 'customer/add', array_replace($this->customer('refund-child'),
            ['customer_name' => 'HTTP 子客户', 'customer_type' => 'store', 'parent_id' => $customer['id']])));
        $version = $start['opening']['version'];
        $payload = ['entry_contract' => 2, 'category' => 'customer_refund', 'subject_id' => $customer['id'],
            'amount' => '', 'details' => ['origin_reference' => '', 'refund_confirmed' => '']];
        foreach ([['amount' => '0'], ['amount' => '-1'], ['source_mode' => 'summary'], ['subject_id' => $child['id']]] as $invalid) {
            self::assertSame(0, $this->http('POST', 'finance/opening/item/save',
                $this->command($version) + array_replace($payload, $invalid))['code']);
        }
        $saved = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($version) + $payload));
        self::assertNull($saved['items'][0]['amount']);
        $version = $saved['version'];
        self::assertSame(0, $this->http('POST', 'finance/opening/guide/card/save',
            $this->command($version) + ['category' => 'customer_refund', 'choice' => 'complete'])['code']);
        $form = ['id' => $saved['items'][0]['id'], 'entry_contract' => 2, 'subject_id' => $customer['id'],
            'amount' => '25', 'details' => ['origin_reference' => '未保存原稿']];
        $draft = $this->ok($this->http('POST', 'finance/opening/item/save',
            $this->command($version) + ['editing_draft' => true, 'category' => 'customer_refund', 'form' => $form]));
        self::assertSame($version, $draft['editor_drafts']['customer_refund']['base_version']);
        self::assertNull($draft['editor_drafts']['customer_refund']['base_item']['amount']);
        $readback = $this->ok($this->http('GET', 'finance/opening'));
        self::assertSame($draft['editor_drafts'], $readback['editor_drafts']);
    }

    public function test_note_salary_and_prepare_permissions_are_checked_on_write_and_replay(): void
    {
        $start = $this->start();
        $actor = self::HTTP_ACTORS[1];
        Db::name('tenant_member')->where('user_id', $actor)->update(['role' => 'member']);
        $employee = $this->grant($actor, ['finance.opening.prepare']);
        $salary = $this->command($start['opening']['version']) + ['category' => 'salary', 'evidence' => 'SALARY-HTTP-SECRET'];
        self::assertSame(0, $this->http('POST', 'finance/opening/guide/evidence/save', $salary, $actor)['code']);
        $payload = $this->command($start['opening']['version']) + ['category' => 'customer_refund', 'evidence' => '普通事项说明'];
        $this->ok($this->http('POST', 'finance/opening/guide/evidence/save', $payload, $actor));
        Db::name('employee_permission')->where('tenant_id', self::HTTP_TENANTS[0])->where('employee_id', $employee)->delete();
        self::assertSame(0, $this->http('POST', 'finance/opening/guide/evidence/save', $payload, $actor)['code']);
        $summary = $this->ok($this->http('GET', 'finance/opening/guide', [], $actor));
        self::assertStringNotContainsString('SALARY-HTTP-SECRET', json_encode($summary));
        self::assertNull($summary['preparation']);
    }

    public function test_all_eight_main_amounts_allow_unknown_but_reject_zero_and_negative_without_writes(): void
    {
        $start = $this->start();
        $customer = $this->ok($this->http('POST', 'customer/add', $this->customer('eight-customer')));
        $vendor = $this->ok($this->http('POST', 'supplier/add', ['supplier_name' => '八项金额收款方',
            'idempotency_key' => 'eight-supplier', 'expected_tenant_id' => self::HTTP_TENANTS[0]]));
        $employee = $this->ok($this->http('POST', 'jxc/workforce/employee/save', ['name' => '八项金额历史员工',
            'mobile' => '', 'mobile_unknown' => 1, 'permission_keys' => [], 'creation_key' => 'eight-employee']));
        $version = $start['opening']['version'];
        foreach (['payable', 'customer_refund', 'advance', 'supplier_refund', 'salary', 'reimbursement', 'expense_payable', 'deferred'] as $category) {
            $subject = in_array($category, ['customer_refund', 'advance'], true) ? $customer['id'] :
                (in_array($category, ['salary', 'reimbursement'], true) ? $employee['id'] : $vendor['id']);
            $form = ['entry_contract' => 2, 'category' => $category, 'subject_id' => $subject, 'details' => []];
            foreach (['0', '-1'] as $amount) {
                self::assertSame(0, $this->http('POST', 'finance/opening/item/save', $this->command($version) + $form + ['amount' => $amount])['code']);
            }
            $saved = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($version) + $form));
            $version = $saved['version'];
            $item = array_values(array_filter($saved['items'], static fn(array $item): bool => $item['category'] === $category))[0];
            self::assertNull($item['amount']);
            if ($category === 'deferred') {
                $updated = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($version) + $form +
                    ['id' => $item['id'], 'amount' => '20', 'details' => []]));
                // 辅助组成的0有业务意义，与主金额的>0规则分开验证。
                $updated = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($updated['version']) +
                    array_replace($form, ['id' => $item['id'], 'amount' => '20', 'details' => ['unpaid_amount' => '0', 'paid_amount' => '0']])));
                $deferred = array_values(array_filter($updated['items'], static fn(array $item): bool => $item['category'] === 'deferred'))[0];
                self::assertSame('0.00', $deferred['details']['unpaid_amount']);
                self::assertSame('0.00', $deferred['details']['paid_amount']);
            }
        }
    }

    public function test_remote_change_and_delete_preserve_original_draft_and_never_resurrect_deleted_item(): void
    {
        $start = $this->start();
        $customer = $this->ok($this->http('POST', 'customer/add', $this->customer('restore-customer')));
        $form = ['entry_contract' => 2, 'category' => 'customer_refund', 'subject_id' => $customer['id'], 'amount' => '20',
            'details' => ['origin_reference' => '原凭据']];
        $first = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($start['opening']['version']) + $form));
        $id = $first['items'][0]['id'];
        $draftForm = array_replace($form, ['id' => $id, 'amount' => '25']); unset($draftForm['category']);
        $draft = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($first['version']) +
            ['editing_draft' => true, 'category' => 'customer_refund', 'form' => $draftForm]));
        $latest = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($draft['version']) +
            array_replace($form, ['id' => $id, 'amount' => '30']), self::HTTP_ACTORS[1]));
        $stale = $this->http('POST', 'finance/opening/item/save', $this->command($draft['version']) +
            ['editing_draft' => true, 'category' => 'customer_refund', 'form' => $draftForm]);
        self::assertSame(0, $stale['code']);
        $read = $this->ok($this->http('GET', 'finance/opening'));
        self::assertSame('25', $read['editor_drafts']['customer_refund']['form']['amount']);
        self::assertSame('20.00', $read['editor_drafts']['customer_refund']['base_item']['amount']);
        self::assertSame('30.00', $read['items'][0]['amount']);
        $removed = $this->ok($this->http('POST', 'finance/opening/item/remove', $this->command($latest['version']) + ['id' => $id], self::HTTP_ACTORS[1]));
        $read = $this->ok($this->http('GET', 'finance/opening'));
        self::assertSame($id, (int)$read['editor_drafts']['customer_refund']['form']['id']);
        self::assertSame(0, $this->http('POST', 'finance/opening/item/save', $this->command($removed['version']) +
            array_replace($form, ['id' => $id, 'amount' => '25']))['code']);
        self::assertSame([], $this->ok($this->http('GET', 'finance/opening'))['items']);
        $new = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($removed['version']) +
            ['editing_draft' => true, 'category' => 'customer_refund', 'form' => array_replace($draftForm, ['id' => 0])]));
        self::assertNull($new['editor_drafts']['customer_refund']['base_item']);
    }

    /** @dataProvider sourceBindingCategories */
    public function test_source_binding_unlinked_is_authoritative_and_manual_records_can_change_subject(string $category): void
    {
        $start = $this->start();
        $first = $this->sourceBindingSubject($category, 'binding-first', '手工来源对象甲');
        $second = $this->sourceBindingSubject($category, 'binding-second', '手工来源对象乙');
        $form = ['entry_contract' => 2, 'category' => $category, 'subject_id' => $first['id'], 'amount' => '20',
            'source_reference' => '人工原凭据不是来源外键', 'details' => $this->sourceBindingDetails($category, '原备注')];
        $saved = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($start['opening']['version']) + $form));
        $item = $saved['items'][0]; $id = (int)$item['id'];
        self::assertSame(['linked' => false, 'subject_id' => (int)$first['id'], 'source_reference' => $item['source_reference'],
            'source_id' => null, 'source_ref' => null], $item['source_binding'] ?? null);
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->count());
        $change = $this->command($saved['version']) + array_replace($form, ['id' => $id, 'subject_id' => $second['id'],
            'source_binding' => ['linked' => true, 'subject_id' => $first['id'], 'source_id' => 987654, 'source_ref' => 'o:987654']]);
        $changed = $this->ok($this->http('POST', 'finance/opening/item/save', $change));
        self::assertSame((int)$second['id'], (int)$changed['items'][0]['subject_id']);
        self::assertFalse($changed['items'][0]['source_binding']['linked']);
        self::assertSame($item['source_reference'], $changed['items'][0]['source_binding']['source_reference']);
        self::assertSame($changed, $this->ok($this->http('POST', 'finance/opening/item/save', $change)));
        $guide = $this->ok($this->http('GET', 'finance/opening/guide'));
        $candidate = array_column($guide['cards'], null, 'category')[$category]['candidates'][0];
        self::assertSame('opening_item', $candidate['entity_type']);
        self::assertSame($id, (int)$candidate['entity_id']);
        self::assertSame($changed['items'][0]['source_binding'], $candidate['source_binding']);
        self::assertSame($changed['items'][0]['source_binding'], $guide['opening']['items'][0]['source_binding']);
        $draftForm = array_replace($form, ['id' => $id, 'subject_id' => $first['id']]); unset($draftForm['category']);
        $draft = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($changed['version']) +
            ['editing_draft' => true, 'category' => $category, 'form' => $draftForm]));
        self::assertSame((int)$first['id'], (int)$draft['editor_drafts'][$category]['form']['subject_id']);
        self::assertSame($changed['items'][0]['source_binding'], $draft['editor_drafts'][$category]['base_item']['source_binding'] ?? null);
        self::assertSame($draft['editor_drafts'][$category]['base_item']['source_binding'],
            $this->ok($this->http('GET', 'finance/opening/guide'))['opening']['editor_drafts'][$category]['base_item']['source_binding']);
        $rebased = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($draft['version']) +
            ['editing_draft' => true, 'category' => $category, 'rebase_version' => $draft['version'],
                'form' => array_replace($draftForm, ['subject_id' => $second['id']])]));
        self::assertSame((int)$second['id'], (int)$rebased['editor_drafts'][$category]['form']['subject_id']);
        $otherStore = $this->ok($this->http('GET', 'finance/opening', [], self::HTTP_ACTORS[2]));
        self::assertSame([], $otherStore['items']);
        self::assertSame(0, $this->http('POST', 'finance/opening/item/save', $this->command($rebased['version']) +
            array_replace($form, ['id' => $id, 'subject_id' => $first['id']]), self::HTTP_ACTORS[2])['code']);
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->count());
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::HTTP_TENANTS[0])->count());
    }

    /** @dataProvider sourceBindingCategories */
    public function test_source_binding_confirmed_projection_and_fault_injected_identity_guards(string $category): void
    {
        $guide = $this->start();
        $first = $this->sourceBindingSubject($category, 'linked-first', '正式来源对象甲');
        $second = $this->sourceBindingSubject($category, 'linked-second', '正式来源对象乙');
        $form = ['entry_contract' => 2, 'category' => $category, 'subject_id' => $first['id'], 'amount' => '20',
            'source_reference' => '原预存人工凭据', 'details' => $this->sourceBindingDetails($category, '原备注')];
        $originalCommand = $this->command($guide['opening']['version']) + $form;
        $saved = $this->ok($this->http('POST', 'finance/opening/item/save', $originalCommand));
        $originalResult = $saved; $id = (int)$saved['items'][0]['id'];
        foreach (array_keys(\app\api\jxc\logic\FinanceOpeningGuide::CARDS) as $cardCategory) {
            $guide = $this->ok($this->http('POST', 'finance/opening/guide/card/save', $this->command($saved['version']) +
                ['category' => $cardCategory, 'choice' => $cardCategory === $category ? 'complete' : 'none']));
            $saved = $guide['opening'];
        }
        $pending = $this->ok($this->http('POST', 'finance/opening/submit', $this->command($saved['version'])));
        $active = $this->ok($this->http('POST', 'finance/opening/confirm', $this->command($pending['version'])));
        self::assertSame('active', $active['status']);
        $source = Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->where('opening_item_id', $id)->find();
        $frozen = Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->value('confirmed_snapshot');
        $binding = ['linked' => true, 'subject_id' => (int)$first['id'], 'source_reference' => '原预存人工凭据',
            'source_id' => (int)$source['id'], 'source_ref' => 'o:' . $source['id']];
        self::assertSame($binding, $active['items'][0]['source_binding'] ?? null);
        $read = $this->ok($this->http('GET', 'finance/opening'));
        self::assertSame($binding, $read['items'][0]['source_binding']);
        self::assertSame($binding, $this->ok($this->http('GET', 'finance/opening/guide'))['opening']['items'][0]['source_binding']);
        self::assertSame($frozen, Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->value('confirmed_snapshot'));
        // 故障注入：冻结身份缺失、矛盾或数值尾随字符均是unknown，不能通过强转伪装一致。
        $sourceSnapshot = json_decode($source['source_snapshot'], true);
        $missingReference = $sourceSnapshot; unset($missingReference['source_reference']);
        foreach ([array_replace($sourceSnapshot, ['tenant_id' => self::HTTP_TENANTS[1]]),
            array_replace($sourceSnapshot, ['category' => $category === 'advance' ? 'supplier_refund' : 'advance']),
            array_replace($sourceSnapshot, ['subject_id' => $second['id']]),
            array_replace($sourceSnapshot, ['source_reference' => '另一人工来源']),
            array_replace($sourceSnapshot, ['subject_id' => $first['id'] . 'junk']),
            array_replace($sourceSnapshot, ['id' => $id . 'junk']), $missingReference] as $invalidSnapshot) {
            Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $source['id'])
                ->update(['source_snapshot' => json_encode($invalidSnapshot, JSON_THROW_ON_ERROR)]);
            self::assertNull($this->ok($this->http('GET', 'finance/opening'))['items'][0]['source_binding']);
        }
        Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $source['id'])
            ->update(['source_snapshot' => $source['source_snapshot']]);
        foreach (['category' => $category === 'advance' ? 'supplier_refund' : 'advance', 'subject_id' => $second['id']] as $field => $value) {
            Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $source['id'])->update([$field => $value]);
            self::assertNull($this->ok($this->http('GET', 'finance/opening'))['items'][0]['source_binding']);
            Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $source['id'])->update([$field => $source[$field]]);
        }
        self::assertSame($originalResult, $this->ok($this->http('POST', 'finance/opening/item/save', $originalCommand)));
        $changed = array_replace($form, ['id' => $id, 'subject_id' => $second['id'], 'source_binding' => ['linked' => false]]);
        $deniedActive = $this->http('POST', 'finance/opening/item/save', $this->command($read['version']) + $changed);
        self::assertSame(0, $deniedActive['code']); self::assertStringContainsString('期初已确认', $deniedActive['msg']);

        // 以下是异常状态注入：真实confirm已建立来源后，仅为验证额外身份守卫将账本状态改为draft。
        // 正常公开业务没有这一状态；不能以该夹具声称active账本可编辑。
        Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->update(['status' => 'draft']);
        $ownDraft = array_replace($form, ['id' => $id, 'amount' => '21']); unset($ownDraft['category']);
        $draft = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($read['version']) +
            ['editing_draft' => true, 'category' => $category, 'form' => $ownDraft]));
        self::assertSame($binding, $draft['editor_drafts'][$category]['base_item']['source_binding'] ?? null);
        // 旧基线可能带过时false；新鲜GET只派生关系，不重写原稿、基线版本或已存reviews。
        $draftReviews = json_decode(Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->value('reviews'), true);
        $draftReviews['_editors']['user:' . self::HTTP_ACTORS[0]][$category]['base_item']['source_binding'] = array_replace($binding,
            ['linked' => false, 'source_id' => null, 'source_ref' => null]);
        $storedReviews = json_encode($draftReviews, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->update(['reviews' => $storedReviews]);
        $freshDraft = $this->ok($this->http('GET', 'finance/opening'))['editor_drafts'][$category];
        self::assertSame($binding, $freshDraft['base_item']['source_binding']);
        self::assertSame($draft['editor_drafts'][$category]['form'], $freshDraft['form']);
        self::assertSame($draft['editor_drafts'][$category]['base_version'], $freshDraft['base_version']);
        $expectedBase = $draft['editor_drafts'][$category]['base_item']; $actualBase = $freshDraft['base_item'];
        unset($expectedBase['source_binding'], $actualBase['source_binding']);
        self::assertSame($expectedBase, $actualBase);
        Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $source['id'])->update(['source_snapshot' => '{}']);
        $unknownDraft = $this->ok($this->http('GET', 'finance/opening/guide'))['opening']['editor_drafts'][$category];
        self::assertNull($unknownDraft['base_item']['source_binding']);
        self::assertSame($freshDraft['form'], $unknownDraft['form']);
        self::assertSame($freshDraft['base_version'], $unknownDraft['base_version']);
        self::assertSame($storedReviews, Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->value('reviews'));
        Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $source['id'])->update(['source_snapshot' => $source['source_snapshot']]);
        $before = $this->ok($this->http('GET', 'finance/opening'));
        $book = Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find();
        foreach (['item', 'editor', 'rebase'] as $operation) {
            $data = $operation === 'item' ? array_replace($changed, ['source_reference' => '']) : ['editing_draft' => true, 'category' => $category,
                'form' => array_replace($ownDraft, ['subject_id' => $second['id'], 'source_reference' => '', 'source_binding' => ['linked' => false]])];
            if ($operation === 'rebase') { $data['rebase_version'] = $draft['version']; }
            self::assertSame(0, $this->http('POST', 'finance/opening/item/save', $this->command($draft['version']) + $data)['code']);
            self::assertSame($before, $this->ok($this->http('GET', 'finance/opening')));
            self::assertSame($book, Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find());
        }
        $legal = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($draft['version']) +
            array_replace($form, ['id' => $id, 'amount' => '22', 'details' => $this->sourceBindingDetails($category, '同对象金额备注可编辑'), 'source_reference' => ''])));
        self::assertSame('22.00', $legal['items'][0]['amount']); self::assertSame($binding, $legal['items'][0]['source_binding']);
        self::assertSame('同对象金额备注可编辑', $legal['items'][0]['details']['owner_note']);
        self::assertSame($source, Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $source['id'])->find());

        // 来源冻结身份损坏也仅按未知处理；不把关系矛盾降为unlinked来允许换客。
        Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $source['id'])->update(['source_snapshot' => '{}']);
        $unknown = $this->ok($this->http('GET', 'finance/opening'));
        self::assertNull($unknown['items'][0]['source_binding']);
        foreach (['item', 'editor', 'rebase'] as $operation) {
            $data = $operation === 'item' ? array_replace($changed, ['source_reference' => '']) : ['editing_draft' => true, 'category' => $category,
                'form' => array_replace($ownDraft, ['subject_id' => $second['id'], 'source_reference' => '', 'source_binding' => ['linked' => false]])];
            if ($operation === 'rebase') { $data['rebase_version'] = $legal['version']; }
            self::assertSame(0, $this->http('POST', 'finance/opening/item/save', $this->command($legal['version']) + $data)['code']);
            self::assertSame($unknown, $this->ok($this->http('GET', 'finance/opening')));
        }
        $kept = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($legal['version']) +
            array_replace($form, ['id' => $id, 'amount' => '23', 'details' => $this->sourceBindingDetails($category, '未知关系保留原对象')])));
        self::assertSame((int)$first['id'], (int)$kept['items'][0]['subject_id']); self::assertSame('23.00', $kept['items'][0]['amount']);
        self::assertNull($kept['items'][0]['source_binding']);
        self::assertSame($frozen, Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->value('confirmed_snapshot'));
        self::assertSame(1, Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->count());
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::HTTP_TENANTS[0])->count());
    }

    public static function sourceBindingCategories(): array
    {
        return ['advance' => ['advance'], 'supplier_refund' => ['supplier_refund']];
    }

    public function test_supplier_refund_field_contract_keeps_unconfirmed_and_unknown_separate_from_review(): void
    {
        $guide = $this->start();
        $supplier = $this->sourceBindingSubject('supplier_refund', 'field-contract', '退款字段契约供应商');
        $metadata = array_column($guide['opening']['categories'], null, 'key')['supplier_refund'];
        self::assertSame(2, $metadata['entry_contract']); self::assertFalse($metadata['allows_summary']);
        self::assertSame('detail', $metadata['default_source_mode']); self::assertFalse($metadata['has_due_date']);
        $form = ['entry_contract' => 2, 'category' => 'supplier_refund', 'subject_id' => $supplier['id'],
            'amount' => '20', 'source_mode' => 'detail', 'source_reference' => '退款事项人工标识',
            'details' => $this->sourceBindingDetails('supplier_refund', '字段契约原备注')];
        $before = $this->ok($this->http('GET', 'finance/opening'));
        foreach ([['source_mode' => 'summary'], ['due_date' => '2026-08-31']] as $invalid) {
            self::assertSame(0, $this->http('POST', 'finance/opening/item/save', $this->command($before['version']) + array_replace($form, $invalid))['code']);
            self::assertSame($before, $this->ok($this->http('GET', 'finance/opening')));
        }
        foreach ([['amount' => null, 'refund_confirmed' => ''], ['amount' => null, 'refund_confirmed' => 'confirmed'],
            ['amount' => '20', 'refund_confirmed' => '']] as $incomplete) {
            $form['amount'] = $incomplete['amount'];
            $form['details']['refund_confirmed'] = $incomplete['refund_confirmed'];
            $saved = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($before['version']) + $form));
            $item = $saved['items'][0]; $form['id'] = $item['id'];
            self::assertSame($incomplete['amount'] === null ? null : '20.00', $item['amount']);
            self::assertSame('detail', $item['source_mode']); self::assertNull($item['due_date']);
            self::assertSame($incomplete['refund_confirmed'], $item['details']['refund_confirmed']);
            self::assertSame(0, $this->http('POST', 'finance/opening/guide/card/save', $this->command($saved['version']) +
                ['category' => 'supplier_refund', 'choice' => 'complete'])['code']);
            $before = $this->ok($this->http('GET', 'finance/opening'));
            self::assertSame($saved, $before);
        }
        $form['details']['refund_confirmed'] = 'confirmed';
        $complete = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($before['version']) + $form));
        $reviewed = $this->ok($this->http('POST', 'finance/opening/guide/card/save', $this->command($complete['version']) +
            ['category' => 'supplier_refund', 'choice' => 'complete']));
        self::assertSame('complete', array_column($reviewed['cards'], null, 'category')['supplier_refund']['state']);
        self::assertSame(1, Db::name('finance_opening_item')->where('tenant_id', self::HTTP_TENANTS[0])->where('category', 'supplier_refund')->count());
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->count());
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::HTTP_TENANTS[0])->count());
    }

    public function test_supplier_refund_revocation_blocks_new_writes_and_original_replays_without_losing_saved_facts(): void
    {
        $start = $this->start(); $actor = self::HTTP_ACTORS[1]; $tenant = self::HTTP_TENANTS[0];
        Db::name('tenant_member')->where('tenant_id', $tenant)->where('user_id', $actor)->update(['role' => 'member']);
        $employeeId = $this->grant($actor, ['finance.opening.prepare']);
        $mobile = Db::name('user')->where('tenant_id', $tenant)->where('id', $actor)->value('mobile');
        Db::name('employee')->where('tenant_id', $tenant)->where('id', $employeeId)->update(['mobile' => $mobile]);
        $employee = Db::name('employee')->where('tenant_id', $tenant)->where('id', $employeeId)->find();
        $permissionBefore = $this->bridgePeerState($employeeId);
        $permissionPayload = ['id' => $employeeId, 'name' => $employee['name'], 'mobile' => $employee['mobile'],
            'mobile_unknown' => 0, 'bind_user_id' => $actor, 'is_enabled' => 1, 'expected_tenant_id' => $tenant,
            'permission_keys' => $permissionBefore['permissionKeys']];
        $supplier = $this->sourceBindingSubject('supplier_refund', 'p04-revocation-supplier', 'P04撤权验收供应商');
        $form = ['entry_contract' => 2, 'category' => 'supplier_refund', 'subject_id' => $supplier['id'], 'amount' => '20',
            'source_mode' => 'detail', 'source_reference' => 'P04-PRIVATE-ITEM',
            'details' => $this->sourceBindingDetails('supplier_refund', 'P04-PRIVATE-ITEM')];
        $itemCommand = $this->command($start['opening']['version']) + $form;
        $savedItem = $this->ok($this->http('POST', 'finance/opening/item/save', $itemCommand, $actor));
        $noteCommand = $this->command($savedItem['version']) + ['category' => 'supplier_refund', 'evidence' => 'P04-PRIVATE-NOTE'];
        $savedNote = $this->ok($this->http('POST', 'finance/opening/guide/evidence/save', $noteCommand, $actor));
        $editorForm = array_replace($form, ['id' => $savedItem['items'][0]['id'], 'amount' => '25',
            'details' => $this->sourceBindingDetails('supplier_refund', 'P04-PRIVATE-EDITOR')]);
        unset($editorForm['category']);
        $editorCommand = $this->command($savedNote['opening']['version']) +
            ['editing_draft' => true, 'category' => 'supplier_refund', 'form' => $editorForm];
        $savedEditor = $this->ok($this->http('POST', 'finance/opening/item/save', $editorCommand, $actor));
        $peerBefore = $this->ok($this->http('GET', 'finance/opening', [], $actor));
        self::assertSame('P04-PRIVATE-EDITOR', $peerBefore['editor_drafts']['supplier_refund']['form']['details']['owner_note']);
        self::assertSame('20.00', $peerBefore['items'][0]['amount']);
        self::assertSame('P04-PRIVATE-NOTE', array_column($peerBefore['categories'], null, 'key')['supplier_refund']['review']['evidence']);
        $ownerBefore = $this->ok($this->http('GET', 'finance/opening'));
        $facts = static function () use ($tenant): array {
            $rows = [];
            foreach (['finance_opening_book', 'finance_opening_item', 'finance_opening_source', 'finance_entry',
                'finance_preparation', 'finance_setup_action'] as $table) {
                $key = in_array($table, ['finance_opening_book', 'finance_preparation'], true) ? 'tenant_id' : 'id';
                $rows[$table] = Db::name($table)->where('tenant_id', $tenant)->order($key)->select()->toArray();
            }
            return $rows;
        };
        $factsBefore = $facts();
        $originals = [['finance/opening/item/save', $itemCommand, $savedItem],
            ['finance/opening/guide/evidence/save', $noteCommand, $savedNote],
            ['finance/opening/item/save', $editorCommand, $savedEditor]];
        try {
            $this->ok($this->http('POST', 'jxc/workforce/employee/save', array_replace($permissionPayload, ['permission_keys' => []])));
            self::assertSame([], $this->bridgePeerState($employeeId)['permissionKeys']);
            $newWrites = [['finance/opening/item/save', $this->command($peerBefore['version']) +
                    array_replace($form, ['id' => $savedItem['items'][0]['id'], 'amount' => '99'])],
                ['finance/opening/guide/evidence/save', $this->command($peerBefore['version']) +
                    ['category' => 'supplier_refund', 'evidence' => 'P04-PRIVATE-NOTE-CHANGED']],
                ['finance/opening/item/save', $this->command($peerBefore['version']) +
                    ['editing_draft' => true, 'category' => 'supplier_refund', 'form' => array_replace($editorForm, ['amount' => '99'])]]];
            foreach (array_merge($originals, $newWrites) as [$path, $command]) {
                $denied = $this->http('POST', $path, $command, $actor);
                self::assertSame(0, $denied['code']); self::assertSame([], $denied['data']);
                foreach (['P04-PRIVATE-ITEM', 'P04-PRIVATE-NOTE', 'P04-PRIVATE-EDITOR'] as $secret) {
                    self::assertStringNotContainsString($secret, json_encode($denied, JSON_UNESCAPED_UNICODE));
                }
            }
            $restricted = $this->ok($this->http('GET', 'finance/opening/guide', [], $actor));
            self::assertFalse($restricted['capabilities']['prepare_opening']);
            self::assertSame([], $restricted['cards']); self::assertNull($restricted['preparation']);
            foreach (['items', 'categories', 'editor_drafts'] as $field) { self::assertArrayNotHasKey($field, $restricted['opening']); }
            foreach (['P04-PRIVATE-ITEM', 'P04-PRIVATE-NOTE', 'P04-PRIVATE-EDITOR'] as $secret) {
                self::assertStringNotContainsString($secret, json_encode($restricted, JSON_UNESCAPED_UNICODE));
            }
            self::assertSame(0, $this->http('GET', 'finance/opening', [], $actor)['code']);
            self::assertSame($ownerBefore, $this->ok($this->http('GET', 'finance/opening')));
            self::assertSame($factsBefore, $facts());
        } finally {
            $this->ok($this->http('POST', 'jxc/workforce/employee/save', $permissionPayload));
        }
        self::assertSame($permissionBefore, $this->bridgePeerState($employeeId));
        self::assertSame($peerBefore, $this->ok($this->http('GET', 'finance/opening', [], $actor)));
        foreach ($originals as [$path, $command, $result]) {
            self::assertSame($result, $this->ok($this->http('POST', $path, $command, $actor)));
        }
        self::assertSame($factsBefore, $facts());
    }

    private function sourceBindingSubject(string $category, string $key, string $name): array
    {
        if ($category === 'advance') {
            return $this->ok($this->http('POST', 'customer/add', array_replace($this->customer($key), ['customer_name' => $name])));
        }
        return $this->ok($this->http('POST', 'supplier/add', ['idempotency_key' => $key,
            'expected_tenant_id' => self::HTTP_TENANTS[0], 'supplier_name' => $name, 'contact' => '供应商联系人',
            'phone' => '13800006412', 'address' => '供应商完整地址', 'remark' => '供应商完整备注', 'is_disabled' => 0]));
    }

    private function sourceBindingDetails(string $category, string $note): array
    {
        $details = ['origin_reference' => '原凭据', 'owner_note' => $note];
        if ($category === 'supplier_refund') {
            $details += ['business_description' => '采购退货差额待返还', 'refund_confirmed' => 'confirmed'];
        }
        return $details;
    }

    private function start(string $activationDate = '2026-09-01'): array
    {
        return $this->ok($this->http('POST', 'finance/opening/start', $this->command(0) +
            ['mode' => 'carryover', 'activation_date' => $activationDate]));
    }

    /** @dataProvider noneDraftHttpCases */
    public function test_none_http_uses_all_same_category_drafts_and_preserves_state($draftActor, ?string $draftCategory, bool $reviewEntry, bool $refused): void
    {
        $start = $this->start();
        $version = $start['opening']['version'];
        if ($draftCategory !== null) {
            $draft = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($version) + [
                'editing_draft' => true, 'category' => $draftCategory, 'form' => ['entry_contract' => 2, 'amount' => '123.45',
                    'details' => ['origin_reference' => 'HTTP-PRIVATE-DRAFT-CONTENT']],
            ], $draftActor));
            $version = $draft['version'];
        }
        $beforeBook = Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find();
        $beforePreparation = Db::name('finance_preparation')->where('tenant_id', self::HTTP_TENANTS[0])->find();
        $beforeActions = Db::name('finance_setup_action')->where('tenant_id', self::HTTP_TENANTS[0])->count();
        $payload = $this->command($version) + ['category' => 'customer_refund'];
        $result = $reviewEntry
            ? $this->http('POST', 'finance/opening/review/save', $payload + ['state' => 'none', 'evidence' => '核对没有'])
            : $this->http('POST', 'finance/opening/guide/card/save', $payload + ['choice' => 'none']);
        $afterBook = Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find();
        if ($refused) {
            self::assertSame(0, $result['code']);
            self::assertSame([], $result['data']);
            self::assertSame('本项还有未完成输入，请先保存为准备资料或明确放弃', $result['msg']);
            self::assertStringNotContainsString('HTTP-PRIVATE-DRAFT-CONTENT', json_encode($result));
            self::assertStringNotContainsString('123.45', json_encode($result));
            self::assertSame($beforeBook, $afterBook);
            self::assertSame($beforePreparation, Db::name('finance_preparation')->where('tenant_id', self::HTTP_TENANTS[0])->find());
            self::assertSame($beforeActions, Db::name('finance_setup_action')->where('tenant_id', self::HTTP_TENANTS[0])->count());
        } else {
            $saved = $this->ok($result);
            self::assertSame('none', array_column($saved['cards'], null, 'key')['customer_refund']['state']);
            self::assertSame((int)$beforeBook['version'] + 1, (int)$afterBook['version']);
            self::assertSame(json_decode($beforeBook['reviews'], true)['_editors'] ?? [],
                json_decode($afterBook['reviews'], true)['_editors'] ?? []);
        }
    }

    public static function noneDraftHttpCases(): array
    {
        return ['本人稿' => [self::HTTP_ACTORS[0], 'customer_refund', false, true],
            '他人稿' => [self::HTTP_ACTORS[1], 'customer_refund', false, true],
            '同数字ID不同身份稿' => ['admin', 'customer_refund', false, true],
            '他人稿/核对入口' => [self::HTTP_ACTORS[1], 'customer_refund', true, true],
            '真正空态' => [self::HTTP_ACTORS[0], null, false, false],
            '不同事项稿' => [self::HTTP_ACTORS[1], 'advance', false, false]];
    }

    public function test_none_success_replay_keeps_original_fact_and_current_permission_after_another_actor_drafts(): void
    {
        $start = $this->start();
        $payload = $this->command($start['opening']['version']) + ['category' => 'customer_refund', 'choice' => 'none'];
        $first = $this->ok($this->http('POST', 'finance/opening/guide/card/save', $payload));
        $draft = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($first['opening']['version']) +
            ['editing_draft' => true, 'category' => 'customer_refund', 'form' => ['entry_contract' => 2,
                'amount' => '123.45', 'details' => ['origin_reference' => 'PRIVATE-REPLAY-DRAFT']]], self::HTTP_ACTORS[1]));
        $book = Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find();
        $actions = Db::name('finance_setup_action')->where('tenant_id', self::HTTP_TENANTS[0])->count();
        $replay = $this->ok($this->http('POST', 'finance/opening/guide/card/save', $payload));
        self::assertSame($first['opening']['version'], $replay['opening']['version']);
        self::assertSame('none', array_column($replay['cards'], null, 'key')['customer_refund']['state']);
        self::assertSame($book, Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find());
        self::assertSame(0, $this->http('POST', 'finance/opening/guide/card/save', $this->command($draft['version']) +
            ['category' => 'customer_refund', 'choice' => 'none'])['code']);
        Db::name('tenant_member')->where('tenant_id', self::HTTP_TENANTS[0])->where('user_id', self::HTTP_ACTORS[0])->update(['role' => 'member']);
        $denied = $this->http('POST', 'finance/opening/guide/card/save', $payload);
        self::assertSame(0, $denied['code']); self::assertSame([], $denied['data']);
        self::assertSame($book, Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find());
        self::assertSame($actions, Db::name('finance_setup_action')->where('tenant_id', self::HTTP_TENANTS[0])->count());
    }

    /** @dataProvider clearedEditorFacts */
    public function test_editor_http_preserves_cleared_review_evidence_and_progress(string $choice, $editorActor, bool $editExisting): void
    {
        $guide = $this->start();
        $form = ['entry_contract' => 2, 'amount' => '12.50', 'details' => ['origin_reference' => 'PRIVATE-EDITOR-FACT',
            'business_description' => '原退款事项', 'refund_confirmed' => 'confirmed']];
        if ($choice === 'complete') {
            $customer = $this->ok($this->http('POST', 'customer/add', $this->customer('editor-cleared-customer')));
            $form['subject_id'] = $customer['id'];
            $saved = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($guide['opening']['version']) +
                ['category' => 'customer_refund'] + $form));
            $guide = $this->ok($this->http('GET', 'finance/opening/guide'));
            if ($editExisting) { $form['id'] = $saved['items'][0]['id']; }
        }
        $confirmation = $this->command($guide['opening']['version']) + ['category' => 'customer_refund',
            'choice' => $choice, 'evidence' => '原核清说明'];
        $confirmed = $this->ok($this->http('POST', 'finance/opening/guide/card/save', $confirmation));
        $baseline = $this->ok($this->http('POST', 'finance/opening/guide/evidence/save',
            $this->command($confirmed['opening']['version']) + ['category' => 'customer_refund', 'evidence' => '独立补充说明']));
        $opening = $this->ok($this->http('GET', 'finance/opening'));
        $categories = $opening['categories'];
        $preparation = Db::name('finance_preparation')->where('tenant_id', self::HTTP_TENANTS[0])->find();
        $version = $opening['version'];
        foreach (['new', 'change', 'clear'] as $step) {
            if ($step === 'change') { $form['amount'] = '37.80'; }
            $payload = $this->command($version) + ['editing_draft' => true, 'category' => 'customer_refund',
                'form' => $step === 'clear' ? [] : $form];
            if ($step === 'clear') { $payload['clear'] = true; }
            $snapshot = $this->ok($this->http('POST', 'finance/opening/item/save', $payload, $editorActor));
            self::assertSame($version + 1, $snapshot['version']);
            $version = $snapshot['version'];
            self::assertSame($opening['items'], $snapshot['items']);
            self::assertSame($categories, $snapshot['categories']);
            self::assertSame($opening['guide'], $snapshot['guide']);
            self::assertSame($preparation, Db::name('finance_preparation')->where('tenant_id', self::HTTP_TENANTS[0])->find());
            $current = $this->ok($this->http('GET', 'finance/opening/guide'));
            $card = array_column($current['cards'], null, 'key')['customer_refund'];
            self::assertSame($choice, $card['state']);
            self::assertSame('独立补充说明', $card['evidence']);
            self::assertSame($baseline['progress'], $current['progress']);
            self::assertSame($baseline['current_card'], $current['current_card']);
            self::assertSame($step === 'clear' ? 0 : 1, (int)($snapshot['unfinished_editor_counts']['customer_refund'] ?? 0));
            if ($step === 'clear') { continue; }
            $book = Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find();
            $actions = Db::name('finance_setup_action')->where('tenant_id', self::HTTP_TENANTS[0])->count();
            foreach (['finance/opening/guide/card/save' => ['choice' => $choice],
                'finance/opening/review/save' => ['state' => $choice]] as $path => $decision) {
                $refused = $this->http('POST', $path, $this->command($version) + ['category' => 'customer_refund'] + $decision);
                self::assertSame(0, $refused['code']);
                self::assertSame([], $refused['data']);
                self::assertStringNotContainsString('PRIVATE-EDITOR-FACT', json_encode($refused));
            }
            self::assertSame($confirmed, $this->ok($this->http('POST', 'finance/opening/guide/card/save', $confirmation)));
            $otherActor = $editorActor === self::HTTP_ACTORS[0] ? 'admin' : self::HTTP_ACTORS[0];
            $other = $this->ok($this->http('GET', 'finance/opening', [], $otherActor));
            self::assertArrayNotHasKey('customer_refund', $other['editor_drafts']);
            $wrongStore = $this->http('POST', 'finance/opening/item/save', $this->command($version) +
                ['editing_draft' => true, 'category' => 'customer_refund', 'form' => $form], self::HTTP_ACTORS[2]);
            self::assertSame(0, $wrongStore['code']);
            self::assertSame([], $wrongStore['data']);
            self::assertStringNotContainsString('PRIVATE-EDITOR-FACT', json_encode($wrongStore));
            self::assertSame($book, Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find());
            self::assertSame($actions, Db::name('finance_setup_action')->where('tenant_id', self::HTTP_TENANTS[0])->count());
        }
    }

    public static function clearedEditorFacts(): array
    {
        $cases = [];
        foreach (['none', 'complete'] as $choice) {
            foreach (['owner' => self::HTTP_ACTORS[0], 'peer' => self::HTTP_ACTORS[1], 'admin' => 'admin'] as $name => $actor) {
                $cases[$choice . '/' . $name . '/new'] = [$choice, $actor, false];
                if ($choice === 'complete') { $cases[$choice . '/' . $name . '/existing'] = [$choice, $actor, true]; }
            }
        }
        return $cases;
    }

    public function test_editor_http_keeps_all_cleared_cards_but_still_blocks_final_submission(): void
    {
        $guide = $this->start();
        foreach (array_keys(\app\api\jxc\logic\FinanceOpeningGuide::CARDS) as $category) {
            $guide = $this->ok($this->http('POST', 'finance/opening/guide/card/save',
                $this->command($guide['opening']['version']) + ['category' => $category, 'choice' => 'none']));
        }
        self::assertSame(21, $guide['progress']['completed']);
        self::assertSame([], $guide['opening']['blockers']);
        $ownerDraft = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($guide['opening']['version']) +
            ['editing_draft' => true, 'category' => 'customer_refund', 'form' => ['entry_contract' => 2,
                'details' => ['origin_reference' => 'PRIVATE-OWNER-FINAL-EDITOR']]]));
        $draft = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($ownerDraft['version']) +
            ['editing_draft' => true, 'category' => 'customer_refund', 'form' => ['entry_contract' => 2,
                'details' => ['origin_reference' => 'PRIVATE-FINAL-EDITOR']]], self::HTTP_ACTORS[1]));
        $current = $this->ok($this->http('GET', 'finance/opening/guide'));
        self::assertSame(21, $current['progress']['completed']);
        self::assertSame(['opening_editor_unfinished'], array_column($draft['blocker_details'], 'code'));
        $book = Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find();
        $refused = $this->http('POST', 'finance/opening/submit', $this->command($draft['version']));
        self::assertSame(0, $refused['code']); self::assertSame([], $refused['data']);
        self::assertStringNotContainsString('PRIVATE-FINAL-EDITOR', json_encode($refused));
        self::assertSame($book, Db::name('finance_opening_book')->where('tenant_id', self::HTTP_TENANTS[0])->find());
        $ownCleared = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($draft['version']) +
            ['editing_draft' => true, 'category' => 'customer_refund', 'form' => [], 'clear' => true]));
        self::assertSame(1, $ownCleared['unfinished_editor_counts']['customer_refund']);
        self::assertArrayNotHasKey('customer_refund', $ownCleared['editor_drafts']);
        self::assertStringNotContainsString('PRIVATE-FINAL-EDITOR', json_encode($ownCleared));
        $peer = $this->ok($this->http('GET', 'finance/opening', [], self::HTTP_ACTORS[1]));
        self::assertSame('PRIVATE-FINAL-EDITOR', $peer['editor_drafts']['customer_refund']['form']['details']['origin_reference']);
        $cleared = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($ownCleared['version']) +
            ['editing_draft' => true, 'category' => 'customer_refund', 'form' => [], 'clear' => true], self::HTTP_ACTORS[1]));
        self::assertSame([], $cleared['blockers']);
        $submitted = $this->ok($this->http('POST', 'finance/opening/submit', $this->command($cleared['version'])));
        self::assertSame('pending', $submitted['status']);
    }

    public function test_editor_http_does_not_mask_a_real_candidate_change_after_confirmation(): void
    {
        $guide = $this->start();
        $customer = $this->ok($this->http('POST', 'customer/add', $this->customer('editor-candidate-change')));
        $saved = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($guide['opening']['version']) +
            ['entry_contract' => 2, 'category' => 'receivable', 'subject_id' => $customer['id'], 'amount' => '20.00',
                'details' => ['origin_reference' => '原欠款已核对']]));
        $confirmed = $this->ok($this->http('POST', 'finance/opening/guide/card/save', $this->command($saved['version']) +
            ['category' => 'receivable', 'choice' => 'complete', 'evidence' => '核清原说明']));
        $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($confirmed['opening']['version']) +
            ['editing_draft' => true, 'category' => 'receivable', 'form' => ['entry_contract' => 2, 'amount' => '99.00']], 'admin'));
        Db::name('customer')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $customer['id'])->update(['order_receivable' => '50.00']);
        $changed = $this->ok($this->http('GET', 'finance/opening/guide'));
        $card = array_column($changed['cards'], null, 'key')['receivable'];
        self::assertSame('pending_review', $card['state']);
        self::assertContains('opening_guide_candidate_changed', array_column($card['blocker_details'], 'code'));
        self::assertContains('opening_editor_unfinished', array_column($card['blocker_details'], 'code'));
        self::assertSame('核清原说明', $card['evidence']);
    }

    public function test_deferred_matching_text_source_stays_incomplete_until_real_id_is_selected(): void
    {
        $start = $this->start();
        $vendor = $this->ok($this->http('POST', 'supplier/add', ['supplier_name' => '分月真实来源收款方',
            'idempotency_key' => 'deferred-real-source', 'expected_tenant_id' => self::HTTP_TENANTS[0]]));
        $payable = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($start['opening']['version']) +
            ['entry_contract' => 2, 'category' => 'expense_payable', 'subject_id' => $vendor['id'], 'amount' => '300',
                'details' => ['origin_reference' => '已核实费用待付']]));
        $source = $payable['items'][0];
        $form = ['entry_contract' => 2, 'category' => 'deferred', 'subject_id' => $vendor['id'], 'amount' => '100',
            'details' => ['expense_name' => '场租', 'origin_reference' => '原服务合同', 'remaining_only' => 'confirmed',
                'unpaid_amount' => '100', 'payable_reference' => $source['source_reference'], 'service_start' => '2026-09',
                'service_end' => '2026-10', 'schedule' => [['month' => '2026-09', 'amount' => '50'], ['month' => '2026-10', 'amount' => '50']]]];
        $saved = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($payable['version']) + $form));
        $refused = $this->http('POST', 'finance/opening/guide/card/save', $this->command($saved['version']) +
            ['category' => 'deferred', 'choice' => 'complete']);
        self::assertSame(0, $refused['code']);
        self::assertStringContainsString('真实费用待付款', $refused['msg']);
        $form['id'] = $saved['items'][1]['id'];
        $form['details']['payable_item_id'] = (string)$source['id'];
        $linked = $this->ok($this->http('POST', 'finance/opening/item/save', $this->command($saved['version']) + $form));
        $confirmed = $this->ok($this->http('POST', 'finance/opening/guide/card/save', $this->command($linked['version']) +
            ['category' => 'deferred', 'choice' => 'complete']));
        self::assertSame('complete', array_column($confirmed['cards'], null, 'key')['deferred']['state']);
        self::assertSame(0, Db::name('finance_opening_source')->where('tenant_id', self::HTTP_TENANTS[0])->count());
    }

    /** @dataProvider permissionBridgeTargets */
    public function test_bridge_peer_fixture_uses_real_bound_employee_and_restores_original_permissions_via_http(string $target): void
    {
        $this->start('2026-10-17');
        $id = $this->seedBridgePeer($target); $before = $this->bridgePeerState($id);
        self::assertSame(self::HTTP_TENANTS[0], $before['tenantId']);
        self::assertSame(self::HTTP_ACTORS[1], $before['boundActorId']);
        self::assertSame('member', $before['memberRole']); self::assertTrue($before['mobilePresent']);
        $employee = Db::name('employee')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $id)->find();
        $payload = ['id' => $id, 'name' => $employee['name'], 'mobile' => $employee['mobile'], 'mobile_unknown' => 0,
            'bind_user_id' => self::HTTP_ACTORS[1], 'is_enabled' => 1, 'expected_tenant_id' => self::HTTP_TENANTS[0],
            'permission_keys' => ['finance.opening.prepare']];
        try {
            $this->ok($this->http('POST', 'jxc/workforce/employee/save', $payload));
            $guide = $this->ok($this->http('GET', 'finance/opening/guide', [], self::HTTP_ACTORS[1]));
            $salary = array_column($guide['cards'], null, 'category')['salary'];
            self::assertFalse($salary['can_view']); self::assertFalse($salary['can_edit']);
            self::assertSame(['finance.opening.prepare'], $this->bridgePeerState($id)['permissionKeys']);
        } finally {
            $this->ok($this->http('POST', 'jxc/workforce/employee/save', array_replace($payload, ['permission_keys' => $before['permissionKeys']])));
        }
        self::assertSame($before, $this->bridgePeerState($id));
        $guide = $this->ok($this->http('GET', 'finance/opening/guide', [], self::HTTP_ACTORS[1]));
        $salary = array_column($guide['cards'], null, 'category')['salary'];
        self::assertSame($target === 'salary', $salary['can_view']); self::assertSame($target === 'salary', $salary['can_edit']);
        if (in_array($target, ['reimbursement', 'expense_payable', 'deferred'], true)) {
            self::assertSame(['finance.opening.prepare'], $before['permissionKeys']);
            $card = array_column($guide['cards'], null, 'category')[$target];
            self::assertTrue($card['can_view']); self::assertTrue($card['can_edit']);
            self::assertSame(in_array($target, ['expense_payable', 'deferred'], true), array_column($guide['opening']['categories'], null, 'key')[$target]['can_create']);
        }
    }

    public static function permissionBridgeTargets(): array
    {
        return ['salary' => ['salary'], 'reimbursement' => ['reimbursement'], 'expense_payable' => ['expense_payable'], 'deferred' => ['deferred']];
    }

    private function seedBridgePeer(string $target): int
    {
        $peer = self::HTTP_ACTORS[1];
        Db::name('tenant_member')->where('tenant_id', self::HTTP_TENANTS[0])->where('user_id', $peer)->update(['role' => 'member']);
        $permissions = match ($target) {
            'salary' => ['finance.opening.prepare', 'finance.salary.view', 'finance.opening.salary.prepare'],
            'reimbursement', 'expense_payable', 'deferred' => ['finance.opening.prepare'],
            default => throw new \RuntimeException('bridge_invalid_fixture_target'),
        };
        $id = $this->grant($peer, $permissions);
        $mobile = Db::name('user')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $peer)->value('mobile');
        if (!is_string($mobile) || !preg_match('/^1[0-9]{10}$/D', $mobile)) { throw new \RuntimeException('bridge_peer_fixture_invalid'); }
        Db::name('employee')->where('tenant_id', self::HTTP_TENANTS[0])->where('id', $id)->update(['mobile' => $mobile]);
        return $id;
    }

    private function bridgePeerState(int $id): array
    {
        $tenant = self::HTTP_TENANTS[0]; $peer = self::HTTP_ACTORS[1];
        $employee = Db::name('employee')->where('tenant_id', $tenant)->where('id', $id)->find();
        $bound = Db::name('employee')->where('tenant_id', $tenant)->where('bind_user_id', $peer)->count();
        $role = Db::name('tenant_member')->where('tenant_id', $tenant)->where('user_id', $peer)->value('role');
        if (!$employee || (int)$employee['bind_user_id'] !== $peer || $bound !== 1 || $role !== 'member'
            || !is_string($employee['mobile']) || !preg_match('/^1[0-9]{10}$/D', $employee['mobile'])) {
            throw new \RuntimeException('bridge_peer_fixture_invalid');
        }
        $permissions = Db::name('employee_permission')->where('tenant_id', $tenant)->where('employee_id', $id)->column('permission_key');
        sort($permissions); unset($employee['update_time']); ksort($employee);
        return ['tenantId' => $tenant, 'employeeId' => $id, 'boundActorId' => $peer, 'memberRole' => $role,
            'mobilePresent' => true, 'employeeDataSha256' => hash('sha256', json_encode($employee, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'permissionKeys' => $permissions];
    }

    private function grant(int $actor, array $permissions): int
    {
        $employee = (int)Db::name('employee')->insertGetId(['tenant_id' => self::HTTP_TENANTS[0], 'name' => '合成员工',
            'mobile' => '13900006414', 'bind_user_id' => $actor, 'is_enabled' => 1, 'create_time' => time(), 'update_time' => time()]);
        foreach ($permissions as $permission) {
            Db::name('employee_permission')->insert(['tenant_id' => self::HTTP_TENANTS[0], 'employee_id' => $employee,
                'permission_key' => $permission, 'create_time' => time()]);
        }
        return $employee;
    }

    private function customer(string $key): array
    {
        return ['idempotency_key' => $key, 'expected_tenant_id' => self::HTTP_TENANTS[0], 'customer_name' => 'HTTP 主客户',
            'customer_type' => 'customer', 'parent_id' => 0, 'contact' => '联系人', 'phone' => '13800006411',
            'address' => '合成地址', 'remark' => '完整备注', 'is_disabled' => 0];
    }

    private function command(int $version): array
    {
        return ['expected_tenant_id' => self::HTTP_TENANTS[0], 'expected_version' => $version,
            'idempotency_key' => 'preparation-http-command-' . ++$this->sequence];
    }

    private function ok(array $response): array
    {
        self::assertSame(1, $response['code'] ?? null, json_encode($response, JSON_UNESCAPED_UNICODE));
        return $response['data'];
    }

    private function http(string $method, string $path, array $payload = [], $actor = self::HTTP_ACTORS[0]): array
    {
        $url = self::$baseUrl . $path;
        $headers = ['Content-Type: application/json'];
        if ($actor !== null) { $headers[] = 'token: ' . $this->tokens[$actor]; }
        $queryRequest = in_array($method, ['GET', 'DELETE'], true);
        if ($queryRequest) { $url .= '?' . http_build_query($payload); }
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['method' => $method,
            'header' => implode("\r\n", $headers), 'content' => $queryRequest ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true, 'timeout' => 15]]));
        self::assertNotFalse($body, 'HTTP 请求未返回');
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'HTTP 返回非 JSON，状态：' . ($http_response_header[0] ?? 'unknown'));
        self::$httpObservations[] = ['test' => $this->getName(false), 'method' => $method, 'path' => '/api/' . $path,
            'status' => $http_response_header[0] ?? 'unknown', 'code' => $decoded['code'] ?? null,
            'actor' => $actor === null ? 'anonymous' : ($actor === 'admin' ? 'tenant_admin:' . self::HTTP_ACTORS[0] : 'user:' . $actor),
            'expected_tenant_id' => $payload['expected_tenant_id'] ?? null, 'expected_version' => $payload['expected_version'] ?? null];
        return $decoded;
    }

    private function clean(): void
    {
        $tables = Db::query("SELECT TABLE_NAME AS table_name FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='tenant_id'");
        foreach ($tables as $table) { Db::table($table['table_name'])->whereIn('tenant_id', self::HTTP_TENANTS)->delete(); }
        foreach (['user_session', 'tenant_admin_session'] as $table) {
            Db::name($table)->whereIn($table === 'user_session' ? 'user_id' : 'admin_id', self::HTTP_ACTORS)->delete();
        }
        Db::name('tenant')->whereIn('id', self::HTTP_TENANTS)->delete();
    }
}
