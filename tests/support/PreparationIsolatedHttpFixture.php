<?php

declare(strict_types=1);

namespace tests\support;

use think\facade\Db;

/** P09 与剩余准备页共用的固定空范围夹具；仅使用既有 schema。 */
abstract class PreparationIsolatedHttpFixture
{
    public const TENANTS = [882641, 882642];
    public const ACTORS = [992641, 992642, 992643];
    public const RUN_ID = 'preparation-development-20261024-01';
    public const CUTOFF_DATE = '2026-10-16';
    public const ACTIVATION_DATE = '2026-10-17';

    public static function acceptsConnection(array $connection): bool
    {
        return IsolatedDatabaseGuard::acceptsConnection($connection)
            && ($connection['database'] ?? null) === 'beimi_test_inventory';
    }

    protected static function runIsolated(string $target, string $clientPath, string $nodeBinary, array &$evidence): array
    {
        if (!self::acceptsConnection(Db::getConfig('connections.mysql'))) { throw new \RuntimeException('bridge_expense_refund_unsafe_database'); }
        $remaining = in_array($target, ['equipment', 'equipment_refund', 'transit', 'unclaimed', 'recovery', 'excluded', 'capital', 'loan', 'supplier_advance'], true);
        if (!$remaining && $target !== 'expense_refund') { throw new \RuntimeException('bridge_unapproved_target'); }
        $relativeClient = $remaining
            ? '/BeiMi-uniapp/docs/ui-previews/remaining-preparation-20261102/implementation/remaining-http-client.mjs'
            : '/BeiMi-uniapp/docs/ui-previews/expense-refund-preparation-20261102/implementation/expense-refund-http-client.mjs';
        $expectedClient = dirname(__DIR__, 3) . $relativeClient;
        if (realpath($clientPath) !== realpath($expectedClient) || !is_file($clientPath) || !is_file($nodeBinary)) { throw new \RuntimeException('bridge_expense_refund_invalid_client'); }
        $lock = fopen(dirname(__DIR__, 2) . '/.local/preparation-http-workflow.lock', 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('bridge_synthetic_scope_busy'); }
        $server = null; $log = null; $seedStarted = false; $tokens = []; $peerId = 0;
        $evidence = ['scopeTenants' => self::TENANTS, 'scopeActors' => self::ACTORS, 'schemaUnchanged' => false, 'scopeEmpty' => false, 'verified' => false];
        try {
            $tables = self::tenantTables();
            self::assertSchemaPresent();
            self::assertEmpty($tables);
            $evidence['scopeBefore'] = ['tenantTableCount' => count($tables), 'rows' => 0];
            $schemaBefore = self::schemaHash(); $evidence['schemaBeforeSha256'] = $schemaBefore;
            $evidence['outsideBeforeSha256'] = self::outsideControlHash($tables);
            $seedStarted = true;
            [$tokens, $peerId] = self::seed();
            if ($target === 'recovery') {
                // 本页只验真实客户选择；预置档案不携带应收，也不代造期初记录。
                $now = time();
                $id = (int)Db::name('customer')->insertGetId(['tenant_id' => self::TENANTS[0],
                    'customer_name' => 'recovery验收已有客户甲', 'parent_id' => 0, 'is_store' => 0, 'is_disabled' => 0,
                    'order_receivable' => '0.00', 'order_money' => '0.00', 'order_pay_money' => '0.00',
                    'create_time' => $now, 'update_time' => $now]);
                $evidence['fixtureSubjects'] = [['category' => 'recovery', 'kind' => 'customer', 'id' => $id,
                    'tenantId' => self::TENANTS[0], 'name' => 'recovery验收已有客户甲']];
            }
            $peerBefore = self::peerState($peerId); $evidence['peerBefore'] = $peerBefore;
            [$server, $log, $baseUrl] = self::startServer();
            self::startBook($baseUrl, $tokens[self::ACTORS[0]]);
            if ($remaining) { self::startBook($baseUrl, $tokens[self::ACTORS[2]], self::TENANTS[1]); }
            $businessBefore = $remaining ? self::businessState($tables) : null;
            $actors = [];
            foreach (['owner' => self::ACTORS[0], 'peer' => self::ACTORS[1], 'otherStore' => self::ACTORS[2], 'admin' => 'admin'] as $name => $actor) {
                $actors[$name] = ['actorType' => $name === 'admin' ? 'tenant_admin' : 'user',
                    'actorId' => $name === 'admin' ? self::ACTORS[0] : $actor,
                    'tenantId' => $name === 'otherStore' ? self::TENANTS[1] : self::TENANTS[0], 'token' => $tokens[$actor]];
            }
            $command = [$nodeBinary, $clientPath];
            if ($remaining) { $command[] = '--target=' . $target; }
            $result = PreparationHttpBridgeProcess::run($command, [
                'protocol' => PreparationHttpBridgeProcess::PROTOCOL, 'runId' => self::RUN_ID, 'baseUrl' => $baseUrl,
                'cutoffDate' => self::CUTOFF_DATE, 'actors' => $actors], 120000, $nodeBinary);
            if ($result['passed'] || !$remaining) {
                $evidence['supplementalChecks'] = self::assertPermissionBoundaries($baseUrl, $tokens, $peerId, $target);
            } else { $evidence['supplementalNotExecuted'] = 'client_failed'; }
            if ($remaining) {
                $businessAfter = self::businessState($tables, $businessBefore);
                $beforeHash = hash('sha256', json_encode($businessBefore, JSON_THROW_ON_ERROR));
                $afterHash = hash('sha256', json_encode($businessAfter, JSON_THROW_ON_ERROR));
                $evidence['noBusinessSideEffects'] = ['tableCount' => count($businessBefore['tableHashes']),
                    'masterBalanceTables' => ['customer', 'vendor'], 'tableNames' => array_keys($businessBefore['tableHashes']),
                    'missingLegacyTables' => $businessBefore['missingLegacyTables'],
                    'beforeSha256' => $beforeHash, 'afterSha256' => $afterHash, 'unchanged' => $beforeHash === $afterHash];
                if ($beforeHash !== $afterHash) { throw new \RuntimeException('bridge_unexpected_business_side_effect'); }
            }
            return $result;
        } finally {
            // 先停自己启动的服务，杜绝迟到请求进入已清理范围。
            if (is_resource($server)) { proc_terminate($server); proc_close($server); }
            if (is_resource($log)) { fclose($log); }
            $evidence['ownHttpStopped'] = true;
            try {
                if ($seedStarted) {
                    try {
                        $evidence['peerAfter'] = $peerId > 0 ? self::peerState($peerId) : null;
                        $evidence['peerRestored'] = ($evidence['peerBefore'] ?? null) === $evidence['peerAfter'];
                    } finally {
                        self::clean(self::tenantTables());
                        self::assertEmpty(self::tenantTables());
                        $evidence['scopeEmpty'] = true;
                        $evidence['scopeAfter'] = ['tenantTableCount' => count(self::tenantTables()), 'rows' => 0];
                        $evidence['outsideAfterSha256'] = self::outsideControlHash(self::tenantTables());
                        $evidence['outsidePreserved'] = ($evidence['outsideBeforeSha256'] ?? null) === $evidence['outsideAfterSha256'];
                        $evidence['schemaAfterSha256'] = self::schemaHash();
                        $evidence['schemaUnchanged'] = ($evidence['schemaBeforeSha256'] ?? null) === $evidence['schemaAfterSha256'];
                        $evidence['verified'] = $evidence['scopeEmpty'] && $evidence['schemaUnchanged'] && ($evidence['peerRestored'] ?? false) && ($evidence['outsidePreserved'] ?? false);
                    }
                }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private static function tenantTables(): array
    {
        return array_column(Db::query("SELECT TABLE_NAME AS table_name FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='tenant_id' ORDER BY TABLE_NAME"), 'table_name');
    }

    private static function assertSchemaPresent(): void
    {
        $existing = array_column(Db::query('SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE()'), 'table_name');
        foreach (['tenant', 'tenant_member', 'user', 'user_session', 'tenant_admin', 'tenant_admin_session', 'employee', 'employee_permission',
            'vendor', 'master_creation_receipt', 'finance_preparation', 'finance_setup_action', 'finance_opening_book',
            'finance_opening_item', 'finance_opening_item_detail', 'finance_opening_source'] as $table) {
            if (!in_array('la_' . $table, $existing, true)) { throw new \RuntimeException('bridge_expense_refund_schema_missing'); }
        }
    }

    private static function schemaHash(): string
    {
        $columns = Db::query('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION');
        $indexes = Db::query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX');
        return hash('sha256', json_encode([$columns, $indexes], JSON_THROW_ON_ERROR));
    }

    /** 准备资料可变，真实经营结果不可变；新档案只允许零业务金额。快照不含会话或认证表。 */
    private static function businessState(array $tenantTables, ?array $baseline = null): array
    {
        $allowed = ['finance_preparation', 'finance_setup_action', 'finance_opening_book',
            'finance_opening_item', 'finance_opening_item_detail', 'finance_account'];
        $legacy = ['receivable_flow', 'payable_flow', 'customer_receipt', 'supplier_payment', 'customer_payment',
            'stock_flow', 'warehouse_sku_balance', 'goods_batch', 'goods'];
        $required = ['finance_document', 'finance_command', 'finance_source', 'finance_entry',
            'finance_money_transaction', 'finance_transaction_identity', 'finance_opening_source',
            'finance_equipment_purchase', 'finance_equipment_refund_due', 'finance_expense_bill',
            'finance_account_transfer', 'finance_transfer_settlement', 'customer', 'vendor',
            'receivable_flow', 'payable_flow'];
        foreach ($required as $table) {
            if (!in_array('la_' . $table, $tenantTables, true)) { throw new \RuntimeException('bridge_business_snapshot_schema_missing'); }
        }
        // 2026-11-02 已查明当前隔离库无三张旧收付款表；当前正式资金使用 finance_*。
        // 仍逐张记录所有不存在的旧表，有表时必须加入完整比较，不把缺表写成已验证。
        $missingLegacy = array_values(array_filter($legacy, static fn(string $table): bool => !in_array('la_' . $table, $tenantTables, true)));
        $state = ['tableHashes' => [], 'masterBalances' => [], 'openingBookStates' => [], 'missingLegacyTables' => $missingLegacy];
        foreach ($tenantTables as $table) {
            $name = substr($table, 3);
            if (!(str_starts_with($name, 'finance_') && !in_array($name, $allowed, true)) && !in_array($name, $legacy, true)) { continue; }
            $rows = Db::table($table)->whereIn('tenant_id', self::TENANTS)->select()->toArray();
            $strings = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($strings); $state['tableHashes'][$name] = hash('sha256', json_encode($strings, JSON_THROW_ON_ERROR));
        }
        ksort($state['tableHashes']);
        foreach (['customer' => ['order_receivable', 'order_money', 'order_pay_money'],
            'vendor' => ['order_payable', 'order_money', 'order_paid_money']] as $table => $columns) {
            $state['masterBalances'][$table] = [];
            foreach (Db::name($table)->whereIn('tenant_id', self::TENANTS)->field(array_merge(['id', 'tenant_id'], $columns))->order('id')->select()->toArray() as $row) {
                $key = (string)$row['id']; $zero = true;
                foreach ($columns as $column) { if (bccomp((string)$row[$column], '0', 2) !== 0) { $zero = false; break; } }
                // 比较旧行全部金额；只忽略新建且金额全零的档案，不忽略新建非零金额。
                if ($baseline !== null && !isset($baseline['masterBalances'][$table][$key]) && $zero) { continue; }
                $state['masterBalances'][$table][$key] = $row;
            }
        }
        foreach (Db::name('finance_opening_book')->whereIn('tenant_id', self::TENANTS)->field(['tenant_id', 'status'])->order('tenant_id')->select()->toArray() as $row) {
            if ($row['status'] === 'active') { throw new \RuntimeException('bridge_unexpected_opening_activation'); }
            $state['openingBookStates'][] = $row;
        }
        return $state;
    }

    private static function assertEmpty(array $tables): void
    {
        foreach ($tables as $table) {
            if (Db::table($table)->whereIn('tenant_id', self::TENANTS)->count() !== 0) { throw new \RuntimeException('bridge_synthetic_scope_not_empty'); }
        }
        foreach (['tenant' => ['id', self::TENANTS], 'user' => ['id', self::ACTORS], 'tenant_admin' => ['id', self::ACTORS],
            'user_session' => ['user_id', self::ACTORS], 'tenant_admin_session' => ['admin_id', self::ACTORS]] as $table => [$column, $ids]) {
            if (Db::name($table)->whereIn($column, $ids)->count() !== 0) { throw new \RuntimeException('bridge_synthetic_scope_not_empty'); }
        }
    }

    private static function seed(): array
    {
        $tokens = []; $now = time();
        foreach (self::TENANTS as $tenant) {
            Db::name('tenant')->insert(['id' => $tenant, 'sn' => 'HTTP' . $tenant, 'name' => '费用退款隔离验收门店', 'disable' => 0, 'expired_time' => $now + 86400, 'create_time' => $now, 'update_time' => $now]);
        }
        foreach (self::ACTORS as $offset => $actor) {
            $tenant = $offset === 2 ? self::TENANTS[1] : self::TENANTS[0]; $tokens[$actor] = bin2hex(random_bytes(16));
            Db::name('user')->insert(['id' => $actor, 'tenant_id' => $tenant, 'sn' => $actor, 'nickname' => '费用退款隔离用户', 'account' => 'expense-refund-http-' . $actor,
                'mobile' => '1390000' . substr((string)$actor, -4), 'create_time' => $now, 'update_time' => $now]);
            Db::name('user_session')->insert(['user_id' => $actor, 'token' => $tokens[$actor], 'terminal' => 1, 'expire_time' => $now + 86400, 'update_time' => $now]);
            Db::name('tenant_member')->insert(['tenant_id' => $tenant, 'user_id' => $actor, 'role' => $offset === 1 ? 'member' : 'owner', 'status' => 1, 'create_time' => $now, 'update_time' => $now]);
        }
        Db::name('tenant_admin')->insert(['id' => self::ACTORS[0], 'tenant_id' => self::TENANTS[0], 'root' => 1, 'name' => '费用退款隔离管理员', 'account' => 'expense-refund-http-admin', 'password' => 'synthetic-not-loginable', 'login_ip' => '127.0.0.1', 'disable' => 0, 'create_time' => $now, 'update_time' => $now]);
        $tokens['admin'] = bin2hex(random_bytes(16));
        Db::name('tenant_admin_session')->insert(['admin_id' => self::ACTORS[0], 'token' => $tokens['admin'], 'terminal' => 1, 'expire_time' => $now + 86400, 'update_time' => $now]);
        $peerId = (int)Db::name('employee')->insertGetId(['tenant_id' => self::TENANTS[0], 'name' => '费用退款验收同事', 'mobile' => '13900002642', 'bind_user_id' => self::ACTORS[1], 'is_enabled' => 1, 'create_time' => $now, 'update_time' => $now]);
        Db::name('employee_permission')->insert(['tenant_id' => self::TENANTS[0], 'employee_id' => $peerId, 'permission_key' => 'finance.opening.prepare', 'create_time' => $now]);
        return [$tokens, $peerId];
    }

    private static function peerState(int $id): array
    {
        $employee = Db::name('employee')->where('tenant_id', self::TENANTS[0])->where('id', $id)->find();
        $member = Db::name('tenant_member')->where('tenant_id', self::TENANTS[0])->where('user_id', self::ACTORS[1])->find();
        $permissions = Db::name('employee_permission')->where('tenant_id', self::TENANTS[0])->where('employee_id', $id)->order('permission_key')->column('permission_key');
        unset($employee['update_time'], $member['update_time']);
        return ['employeeSha256' => hash('sha256', json_encode($employee, JSON_THROW_ON_ERROR)), 'memberSha256' => hash('sha256', json_encode($member, JSON_THROW_ON_ERROR)), 'permissions' => $permissions];
    }

    private static function startServer(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) { throw new \RuntimeException('bridge_http_port_unavailable'); }
        $address = stream_socket_get_name($socket, false); fclose($socket);
        $log = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w');
        $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', $address, dirname(__DIR__) . '/fixtures/preparation_http_router.php'],
            [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, dirname(__DIR__, 2),
            array_merge(getenv(), ['JXC_PREPARATION_HTTP_TEST' => '1', 'JXC_PREPARATION_HTTP_BRIDGE' => '1']), ['bypass_shell' => true]);
        if (!is_resource($server)) { fclose($log); throw new \RuntimeException('bridge_http_server_unavailable'); }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $ready = @stream_socket_client('tcp://' . $address, $errorCode, $error, 0.1);
            if ($ready !== false) { fclose($ready); return [$server, $log, 'http://' . $address . '/api/']; }
            usleep(50000);
        }
        proc_terminate($server); proc_close($server); fclose($log); throw new \RuntimeException('bridge_http_server_start_timeout');
    }

    private static function startBook(string $baseUrl, string $token, int $tenant = self::TENANTS[0]): void
    {
        $payload = ['expected_tenant_id' => $tenant, 'expected_version' => 0, 'idempotency_key' => 'expense-refund-fixture-start', 'mode' => 'carryover', 'activation_date' => self::ACTIVATION_DATE];
        $body = @file_get_contents($baseUrl . 'finance/opening/start', false, stream_context_create(['http' => ['method' => 'POST',
            'header' => "Content-Type: application/json\r\ntoken: " . $token, 'content' => json_encode($payload, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'timeout' => 15]]));
        $result = $body === false ? null : json_decode($body, true);
        if (($result['code'] ?? null) !== 1 || (int)($result['data']['tenant_id'] ?? 0) !== $tenant) { throw new \RuntimeException('bridge_expense_refund_start_failed'); }
    }

    private static function request(string $baseUrl, string $token, string $method, string $path, array $payload = []): array
    {
        $body = @file_get_contents($baseUrl . $path, false, stream_context_create(['http' => ['method' => $method,
            'header' => "Content-Type: application/json\r\ntoken: " . $token,
            'content' => $method === 'GET' ? '' : json_encode($payload, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'timeout' => 15]]));
        $result = $body === false ? null : json_decode($body, true);
        if (!is_array($result)) { throw new \RuntimeException('bridge_expense_refund_boundary_http_failed'); }
        return $result;
    }

    private static function assertPermissionBoundaries(string $baseUrl, array $tokens, int $peerId, string $target): array
    {
        $owner = self::request($baseUrl, $tokens[self::ACTORS[0]], 'GET', 'finance/opening/guide');
        $peer = self::request($baseUrl, $tokens[self::ACTORS[1]], 'GET', 'finance/opening/guide');
        if (($owner['code'] ?? null) !== 1 || ($peer['code'] ?? null) !== 1 || ($peer['data']['capabilities']['prepare_opening'] ?? null) !== true) {
            throw new \RuntimeException('bridge_expense_refund_peer_permission_failed');
        }
        $before = $owner['data']['opening']; $version = (int)$before['version'];
        $payload = ['expected_tenant_id' => self::TENANTS[0], 'expected_version' => $version,
            'idempotency_key' => 'expense-refund-permission-boundary', 'category' => $target, 'evidence' => '不应保存的隔离权限负例'];
        $query = static fn() => Db::name('employee_permission')->where('tenant_id', self::TENANTS[0])->where('employee_id', $peerId);
        $permissions = $query()->order('id')->select()->toArray();
        try {
            $query()->delete();
            $refused = self::request($baseUrl, $tokens[self::ACTORS[1]], 'POST', 'finance/opening/guide/evidence/save', $payload);
            if (($refused['code'] ?? null) !== 0) { throw new \RuntimeException('bridge_expense_refund_revocation_not_enforced'); }
        } finally {
            $query()->delete();
            if ($permissions !== []) { Db::name('employee_permission')->insertAll($permissions); }
        }
        $refused = self::request($baseUrl, $tokens[self::ACTORS[2]], 'POST', 'finance/opening/guide/evidence/save', $payload + ['untrusted_scope' => 'negative']);
        if (($refused['code'] ?? null) !== 0) { throw new \RuntimeException('bridge_expense_refund_tenant_not_enforced'); }
        $after = self::request($baseUrl, $tokens[self::ACTORS[0]], 'GET', 'finance/opening/guide');
        if (($after['code'] ?? null) !== 1 || $before !== $after['data']['opening']) { throw new \RuntimeException('bridge_expense_refund_boundary_changed_opening'); }
        if (!in_array($target, ['expense_refund', 'excluded'], true)) { self::assertRemainingRecordBoundaries($baseUrl, $tokens, $peerId, $target); }
        $prefix = $target === 'expense_refund' ? 'ER' : strtoupper($target);
        return [['id' => $prefix . '-PERM-01', 'passed' => true], ['id' => $prefix . '-TENANT-01', 'passed' => true]];
    }

    /** 新目标以真实 HTTP 校验当前类别记录；姓名文本类同样校验跨店记录身份。 */
    private static function assertRemainingRecordBoundaries(string $baseUrl, array $tokens, int $peerId, string $target): void
    {
        $guide = self::request($baseUrl, $tokens[self::ACTORS[0]], 'GET', 'finance/opening/guide');
        $table = match ($target) { 'recovery' => 'customer', 'transit', 'unclaimed' => 'finance_account', 'capital', 'loan' => null, default => 'vendor' };
        $subjectId = $table ? (int)Db::name($table)->where('tenant_id', self::TENANTS[0])->order('id')->value('id') : 0;
        if (($guide['code'] ?? null) !== 1 || ($table !== null && $subjectId < 1)) { throw new \RuntimeException('bridge_remaining_boundary_subject_missing'); }
        $payload = ['expected_tenant_id' => self::TENANTS[0], 'expected_version' => (int)$guide['data']['opening']['version'],
            'idempotency_key' => 'remaining-boundary-create-' . $target, 'entry_contract' => 2, 'category' => $target,
            'subject_id' => $subjectId, 'amount' => null, 'source_mode' => 'detail', 'source_reference' => '隔离补充权限记录-' . $target,
            'evidence' => '', 'historical_date' => null, 'due_date' => null,
            'details' => in_array($target, ['capital', 'loan'], true) ? ['counterparty' => '权限边界真实姓名'] : []];
        $created = self::request($baseUrl, $tokens[self::ACTORS[0]], 'POST', 'finance/opening/item/save', $payload);
        if (($created['code'] ?? null) !== 1) { throw new \RuntimeException('bridge_remaining_boundary_create_failed'); }
        $before = self::request($baseUrl, $tokens[self::ACTORS[0]], 'GET', 'finance/opening/guide');
        $record = null;
        foreach ($before['data']['opening']['items'] ?? [] as $row) {
            if ($row['category'] === $target && $row['source_reference'] === $payload['source_reference']) { $record = $row; break; }
        }
        if (!$record) { throw new \RuntimeException('bridge_remaining_boundary_record_missing'); }
        $payload['id'] = (int)$record['id']; $payload['expected_version'] = (int)$before['data']['opening']['version'];
        $query = static fn() => Db::name('employee_permission')->where('tenant_id', self::TENANTS[0])->where('employee_id', $peerId);
        $permissions = $query()->order('id')->select()->toArray();
        try {
            $query()->delete();
            foreach (['save', 'remove'] as $action) {
                $attempt = array_replace($payload, ['idempotency_key' => 'remaining-revoked-' . $action . '-' . $target]);
                $refused = self::request($baseUrl, $tokens[self::ACTORS[1]], 'POST', 'finance/opening/item/' . $action, $attempt);
                if (($refused['code'] ?? null) !== 0) { throw new \RuntimeException('bridge_remaining_revoked_record_write'); }
            }
        } finally {
            $query()->delete(); if ($permissions !== []) { Db::name('employee_permission')->insertAll($permissions); }
        }
        $other = self::request($baseUrl, $tokens[self::ACTORS[2]], 'GET', 'finance/opening/guide');
        if (($other['code'] ?? null) !== 1 || ($other['data']['capabilities']['prepare_opening'] ?? null) !== true) {
            throw new \RuntimeException('bridge_remaining_other_store_not_writable');
        }
        foreach (['save', 'remove'] as $action) {
            $attempt = array_replace($payload, ['expected_tenant_id' => self::TENANTS[1],
                'expected_version' => (int)$other['data']['opening']['version'], 'idempotency_key' => 'remaining-cross-record-' . $action . '-' . $target]);
            $refused = self::request($baseUrl, $tokens[self::ACTORS[2]], 'POST', 'finance/opening/item/' . $action, $attempt);
            if (($refused['code'] ?? null) !== 0) { throw new \RuntimeException('bridge_remaining_cross_store_record_write'); }
        }
        if ($table !== null) {
            $attempt = array_replace($payload, ['id' => 0, 'expected_tenant_id' => self::TENANTS[1],
                'expected_version' => (int)$other['data']['opening']['version'], 'idempotency_key' => 'remaining-cross-object-' . $target]);
            $refused = self::request($baseUrl, $tokens[self::ACTORS[2]], 'POST', 'finance/opening/item/save', $attempt);
            if (($refused['code'] ?? null) !== 0) { throw new \RuntimeException('bridge_remaining_cross_store_object_write'); }
        }
        $after = self::request($baseUrl, $tokens[self::ACTORS[0]], 'GET', 'finance/opening/guide');
        $otherAfter = self::request($baseUrl, $tokens[self::ACTORS[2]], 'GET', 'finance/opening/guide');
        if (($after['code'] ?? null) !== 1 || $before['data']['opening'] !== $after['data']['opening']
            || ($otherAfter['code'] ?? null) !== 1 || $other['data']['opening'] !== $otherAfter['data']['opening']) {
            throw new \RuntimeException('bridge_remaining_boundary_changed_records');
        }
        $removed = self::request($baseUrl, $tokens[self::ACTORS[0]], 'POST', 'finance/opening/item/remove',
            array_replace($payload, ['idempotency_key' => 'remaining-boundary-remove-' . $target]));
        if (($removed['code'] ?? null) !== 1) { throw new \RuntimeException('bridge_remaining_boundary_remove_failed'); }
    }

    /** 与历史 P03 同一范围外对照；只读既有行，不扩大清理范围。 */
    private static function outsideControlHash(array $tables): string
    {
        $state = [];
        foreach ($tables as $table) {
            $rows = Db::table($table)->whereIn('tenant_id', [882101, 882102])->select()->toArray();
            if (!$rows) { continue; }
            $strings = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($strings); $state[$table] = $strings;
        }
        $state['la_tenant[id]'] = Db::name('tenant')->whereIn('id', [882101, 882102])->order('id')->select()->toArray();
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private static function clean(array $tables): void
    {
        foreach ($tables as $table) { Db::table($table)->whereIn('tenant_id', self::TENANTS)->delete(); }
        Db::name('user_session')->whereIn('user_id', self::ACTORS)->delete();
        Db::name('tenant_admin_session')->whereIn('admin_id', self::ACTORS)->delete();
        Db::name('tenant')->whereIn('id', self::TENANTS)->delete();
    }
}
