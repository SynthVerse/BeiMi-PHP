<?php

declare(strict_types=1);

namespace app\common\service\jxc;

use app\api\jxc\logic\TodoQueryLogic;
use app\common\cache\UserTokenCache;
use app\common\service\auth\TenantSessionAuthorityService;
use app\common\service\ConfigService;
use app\common\service\storage\Driver as StorageDriver;
use think\facade\Db;
use think\facade\Log;

class TenantClosureService
{
    private const PREVIEW_TTL_SECONDS = 600;
    private const BACKUP_RETENTION_SECONDS = 2592000;
    private const PRESERVED_TENANT_TABLES = [
        'user',
        'user_auth',
        'user_session',
        'tenant_closure_receipt',
    ];

    public static function preview(int $userId, int $tenantId): array
    {
        self::assertOwner($userId, $tenantId);
        $tenant = self::tenant($tenantId);
        $fingerprint = self::fingerprint($tenantId, $tenant);
        $expiresAt = time() + self::PREVIEW_TTL_SECONDS;

        return [
            'tenant_id' => $tenantId,
            'tenant_sn' => (string)$tenant['sn'],
            'store_name' => (string)$tenant['name'],
            'preview_token' => self::signPreview($userId, $tenantId, $fingerprint, $expiresAt),
            'expires_at' => $expiresAt,
            'warnings' => [
                '注销立即生效，在线业务数据与店铺专属附件将进入删除流程。',
                '未完成业务仅作提醒，注销不会将其标记为已完成或已结算。',
                '系统不生成新的完整导出，请先保存已有导出文件。',
                '备份最长保留30天，保留期间也不能恢复店铺。',
            ],
            'scope' => self::tenantDataSummary($tenantId),
            'unfinished' => self::unfinishedSummary(),
        ];
    }

    public static function close(int $userId, int $tenantId, array $params): array
    {
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new \RuntimeException('缺少幂等键');
        }
        if ($userId <= 0) {
            throw new \RuntimeException('请先登录并选择店铺');
        }
        // Accepted requests belong to their signed target, even after current-store reassignment.
        $preview = self::previewPayload((string)($params['preview_token'] ?? ''), $userId);
        $currentTenantId = $tenantId;
        $tenantId = (int)$preview['t'];
        $hash = hash('sha256', $tenantId . ':' . $userId . ':' . $idempotencyKey);
        $existing = Db::name('tenant_closure_receipt')
            ->where('tenant_id', $tenantId)
            ->where('operator_user_id', $userId)
            ->find();
        if ($existing) {
            if (!hash_equals((string)$existing['idempotency_key_hash'], $hash)) {
                throw new \RuntimeException('注销请求标识不一致，请使用原请求重试');
            }
            return self::formatReceipt((array)$existing);
        }

        if ($currentTenantId !== $tenantId) {
            throw new \RuntimeException('当前店铺已变化，请重新预览后再确认');
        }
        self::assertOwner($userId, $tenantId);
        $tenant = self::tenant($tenantId);
        $confirmedName = (string)($params['store_name'] ?? $params['tenant_name'] ?? '');
        if (!hash_equals((string)$tenant['name'], $confirmedName)) {
            throw new \RuntimeException('请输入完整且完全一致的店铺名称');
        }
        $fingerprint = self::fingerprint($tenantId, $tenant);
        self::verifyPreview(
            (string)($params['preview_token'] ?? ''),
            $userId,
            $tenantId,
            $fingerprint
        );

        $affectedUserIds = [];
        $adminIds = [];
        $adminTokens = [];
        $receiptId = Db::transaction(function () use (
            $userId,
            $tenantId,
            $params,
            $confirmedName,
            $hash,
            &$affectedUserIds,
            &$adminIds,
            &$adminTokens
        ): int {
            $existing = Db::name('tenant_closure_receipt')
                ->where('tenant_id', $tenantId)
                ->lock(true)
                ->find();
            if ($existing) {
                if ((int)$existing['operator_user_id'] !== $userId) {
                    throw new \RuntimeException('该店铺已进入永久注销流程');
                }
                if (!hash_equals((string)$existing['idempotency_key_hash'], $hash)) {
                    throw new \RuntimeException('注销请求标识不一致，请使用原请求重试');
                }
                return (int)$existing['id'];
            }

            $lockedTenant = Db::name('tenant')->where('id', $tenantId)->lock(true)->find();
            if (!$lockedTenant || (int)($lockedTenant['disable'] ?? 0) !== 0 || $lockedTenant['delete_time'] !== null) {
                throw new \RuntimeException('店铺不存在或已不可用');
            }
            if (StoreMembershipService::memberRole($userId, $tenantId) !== StoreMembershipService::ROLE_OWNER) {
                throw new \RuntimeException('仅店铺老板可以注销店铺');
            }
            if (!hash_equals((string)$lockedTenant['name'], $confirmedName)) {
                throw new \RuntimeException('店铺名称已变化，请重新预览');
            }
            self::verifyPreview(
                (string)($params['preview_token'] ?? ''),
                $userId,
                $tenantId,
                self::fingerprint($tenantId, (array)$lockedTenant)
            );

            $time = time();
            $receiptId = (int)Db::name('tenant_closure_receipt')->insertGetId([
                'public_id' => self::uuid(),
                'tenant_id' => $tenantId,
                'operator_user_id' => $userId,
                'idempotency_key_hash' => $hash,
                'status' => 'effective',
                'phase' => 'effective',
                'effective_at' => $time,
                'online_deleted_at' => null,
                'attachments_deleted_at' => null,
                'backup_purge_due_at' => $time + self::BACKUP_RETENTION_SECONDS,
                'backup_deleted_at' => null,
                'retry_count' => 0,
                'last_error_code' => '',
                'last_error_message' => '',
                'create_time' => $time,
                'update_time' => $time,
            ]);

            Db::name('tenant')->where('id', $tenantId)
                ->update(['disable' => 1, 'update_time' => $time]);
            self::disableTenantEntryPoints($tenantId, $time);
            $affectedUserIds = self::reassignCurrentTenant($tenantId, $time);

            if (self::tableExists(self::table('tenant_admin'))) {
                $adminIds = array_map('intval', Db::name('tenant_admin')
                    ->where('tenant_id', $tenantId)
                    ->column('id'));
                if ($adminIds !== []) {
                    Db::name('tenant_admin')->whereIn('id', $adminIds)
                        ->update(['disable' => 1, 'update_time' => $time]);
                    $adminTokens = TenantSessionAuthorityService::expireAdminSessionsByIds($adminIds);
                }
            }

            return $receiptId;
        });

        UserTokenCache::refreshUserSessions($affectedUserIds);
        TenantSessionAuthorityService::clearTokenCaches($adminTokens);
        foreach ($adminIds as $adminId) {
            TenantSessionAuthorityService::clearAuthorizationCache($adminId, $tenantId);
        }
        TenantSessionAuthorityService::clearTenantAuthorizationCache($tenantId);

        self::processReceipt($receiptId);
        return self::receiptById($receiptId);
    }

    public static function status(int $userId, string $publicId): array
    {
        if ($userId <= 0 || trim($publicId) === '') {
            throw new \RuntimeException('注销凭据不能为空');
        }
        $receipt = Db::name('tenant_closure_receipt')
            ->where('public_id', trim($publicId))
            ->where('operator_user_id', $userId)
            ->find();
        if (!$receipt) {
            throw new \RuntimeException('注销凭据不存在');
        }
        return self::formatReceipt((array)$receipt);
    }

    public static function retry(int $userId, string $publicId): array
    {
        if ($userId <= 0 || trim($publicId) === '') {
            throw new \RuntimeException('注销凭据不能为空');
        }
        $receipt = Db::name('tenant_closure_receipt')
            ->where('public_id', trim($publicId))
            ->where('operator_user_id', $userId)
            ->find();
        if (!$receipt) {
            throw new \RuntimeException('注销凭据不存在');
        }
        if (!empty($receipt['online_deleted_at'])) {
            throw new \RuntimeException('在线数据已清理，当前仅等待平台完成备份清理');
        }
        self::processReceipt((int)$receipt['id']);
        return self::receiptById((int)$receipt['id']);
    }

    public static function processPending(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $time = time();
        $overdue = Db::name('tenant_closure_receipt')
            ->whereNull('backup_deleted_at')
            ->whereNotNull('online_deleted_at')
            ->where('backup_purge_due_at', '<=', $time)
            ->where('status', '<>', 'completed')
            ->update([
                'status' => 'failed',
                'phase' => 'backup_overdue',
                'last_error_code' => 'BACKUP_PURGE_OVERDUE',
                'last_error_message' => '备份超过30天仍未确认清理，请平台立即处理',
                'update_time' => $time,
            ]);

        $table = self::table('tenant_closure_receipt');
        $rows = Db::query(
            'SELECT `id` FROM `' . $table . '` '
            . 'WHERE `online_deleted_at` IS NULL '
            . "AND (`status` IN ('effective','failed') OR (`status` = 'cleaning' AND `update_time` <= ?)) "
            . 'ORDER BY `id` ASC LIMIT ' . $limit,
            [$time - 300]
        );
        $processed = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $receiptId = (int)($row['id'] ?? 0);
            if ($receiptId <= 0) {
                continue;
            }
            self::processReceipt($receiptId);
            $status = (string)Db::name('tenant_closure_receipt')
                ->where('id', $receiptId)
                ->value('status');
            $processed++;
            if ($status === 'failed') {
                $failed++;
            }
        }
        return [
            'processed' => $processed,
            'failed' => $failed,
            'backup_overdue' => (int)$overdue,
        ];
    }

    private static function assertOwner(int $userId, int $tenantId): void
    {
        if (StoreMembershipService::memberRole($userId, $tenantId) !== StoreMembershipService::ROLE_OWNER) {
            throw new \RuntimeException('仅店铺老板可以注销店铺');
        }
    }

    private static function tenant(int $tenantId): array
    {
        $tenant = Db::name('tenant')
            ->where('id', $tenantId)
            ->where('disable', 0)
            ->whereNull('delete_time')
            ->find();
        if (!$tenant) {
            throw new \RuntimeException('店铺不存在或已不可用');
        }
        return (array)$tenant;
    }

    private static function fingerprint(int $tenantId, array $tenant): string
    {
        return hash('sha256', implode('|', [
            $tenantId,
            (string)($tenant['name'] ?? ''),
            (string)($tenant['update_time'] ?? ''),
            json_encode(self::tenantDataVersion($tenantId, $tenant), JSON_UNESCAPED_UNICODE),
        ]));
    }

    private static function tenantDataVersion(int $tenantId, array $tenant): array
    {
        $versions = [];
        foreach (self::deletableTenantTables() as $table) {
            $versions[$table] = self::tableVersion($table, '`tenant_id` = ?', [$tenantId]);
        }
        if ((int)($tenant['tactics'] ?? 0) === 1) {
            foreach (self::isolatedTenantTables((string)($tenant['sn'] ?? '')) as $table) {
                $versions[$table] = self::tableVersion($table);
            }
        }
        ksort($versions);
        return $versions;
    }

    private static function tableVersion(string $table, string $where = '', array $bindings = []): array
    {
        $columns = array_column(Db::query('SHOW COLUMNS FROM `' . $table . '`'), 'Field');
        // HEX preserves binary/string values; JSON separates columns and distinguishes NULL from empty.
        $values = array_map(static fn (string $column): string =>
            'HEX(`' . str_replace('`', '``', $column) . '`)', $columns);
        $query = Db::table($table)->fieldRaw(
            'SHA2(CAST(JSON_ARRAY(' . implode(',', $values) . ') AS CHAR), 256) AS row_hash'
        )->order('row_hash');
        if ($where !== '') {
            $query->whereRaw($where, $bindings);
        }
        $digest = hash_init('sha256');
        $count = 0;
        foreach ($query->cursor() as $row) {
            hash_update($digest, (string)$row['row_hash']);
            $count++;
        }
        return ['row_count' => $count, 'content_hash' => hash_final($digest)];
    }

    private static function tenantDataSummary(int $tenantId): array
    {
        $tables = 0;
        $records = 0;
        foreach (self::deletableTenantTables() as $table) {
            $count = (int)(Db::query(
                'SELECT COUNT(*) AS aggregate FROM `' . $table . '` WHERE `tenant_id` = ?',
                [$tenantId]
            )[0]['aggregate'] ?? 0);
            if ($count > 0) {
                $tables++;
                $records += $count;
            }
        }
        $tenant = Db::name('tenant')->where('id', $tenantId)->find();
        if ($tenant && (int)($tenant['tactics'] ?? 0) === 1) {
            foreach (self::isolatedTenantTables((string)$tenant['sn']) as $table) {
                $count = (int)(Db::query(
                    'SELECT COUNT(*) AS aggregate FROM `' . $table . '`'
                )[0]['aggregate'] ?? 0);
                $tables++;
                $records += $count;
            }
        }
        return ['table_count' => $tables, 'record_count' => $records];
    }

    private static function unfinishedSummary(): array
    {
        try {
            $summary = TodoQueryLogic::summary();
            if ($summary === false) {
                return [
                    'available' => false,
                    'message' => '未完成业务暂时无法汇总，仍可继续注销。',
                ];
            }
            $complete = (bool)($summary['complete'] ?? false);
            return [
                'available' => true,
                'total_count' => $summary['total_count'] ?? null,
                'known_count' => (int)($summary['known_count'] ?? 0),
                'complete' => $complete,
                'branches' => $summary['branches'] ?? [],
                'message' => $complete
                    ? '这些项目仅作提醒，注销不会将其标记为已完成或已结算。'
                    : '部分待办来源暂时无法汇总；以下仅是已知事项，仍可继续注销。',
            ];
        } catch (\Throwable $e) {
            Log::warning('[TenantClosure] unfinished summary unavailable', [
                'error' => $e->getMessage(),
            ]);
            return [
                'available' => false,
                'message' => '未完成业务暂时无法汇总，仍可继续注销。',
            ];
        }
    }

    private static function disableTenantEntryPoints(int $tenantId, int $time): void
    {
        if (self::tableExists(self::table('tenant_invite'))) {
            Db::name('tenant_invite')->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId);
                if (self::columnExists(self::table('tenant_invite'), 'target_tenant_id')) {
                    $query->whereOr('target_tenant_id', $tenantId);
                }
            })->update([
                'status' => StoreMembershipService::STATUS_DISABLED,
                'update_time' => $time,
            ]);
        }
        if (self::tableExists(self::table('tenant_relation'))) {
            Db::name('tenant_relation')
                ->where('parent_tenant_id', $tenantId)
                ->whereOr('child_tenant_id', $tenantId)
                ->update([
                    'status' => StoreMembershipService::STATUS_DISABLED,
                    'is_deleted' => 1,
                    'delete_time' => $time,
                    'update_time' => $time,
                ]);
        }
    }

    /** @return list<int> */
    private static function reassignCurrentTenant(int $tenantId, int $time): array
    {
        if (!self::tableExists(self::table('user'))) {
            return [];
        }
        $userIds = array_map('intval', Db::name('user')
            ->where('tenant_id', $tenantId)
            ->column('id'));
        foreach ($userIds as $userId) {
            $replacement = 0;
            if (self::tableExists(self::table('tenant_member'))) {
                $replacement = (int)Db::name('tenant_member')
                    ->alias('m')
                    ->join('tenant t', 't.id = m.tenant_id')
                    ->where('m.user_id', $userId)
                    ->where('m.tenant_id', '<>', $tenantId)
                    ->where('m.status', StoreMembershipService::STATUS_ACTIVE)
                    ->whereNull('m.delete_time')
                    ->where('t.disable', 0)
                    ->whereNull('t.delete_time')
                    ->orderRaw("FIELD(m.role, 'owner', 'admin', 'member', 'viewer')")
                    ->order('m.id', 'asc')
                    ->value('m.tenant_id');
            }
            Db::name('user')->where('id', $userId)->update([
                'tenant_id' => $replacement,
                'update_time' => $time,
            ]);
        }
        return $userIds;
    }

    private static function processReceipt(int $receiptId): void
    {
        if ($receiptId <= 0) {
            return;
        }
        $receipt = Db::transaction(function () use ($receiptId) {
            $locked = Db::name('tenant_closure_receipt')->where('id', $receiptId)->lock(true)->find();
            if (!$locked) {
                throw new \RuntimeException('注销凭据不存在');
            }
            if (in_array((string)$locked['status'], ['backup_retention', 'completed'], true)) {
                return (array)$locked;
            }
            if (!empty($locked['online_deleted_at'])) {
                return (array)$locked;
            }
            if ((string)$locked['status'] === 'cleaning'
                && (int)$locked['update_time'] > time() - 300) {
                return [];
            }
            Db::name('tenant_closure_receipt')->where('id', $receiptId)->update([
                'status' => 'cleaning',
                'phase' => 'attachments',
                'retry_count' => (int)$locked['retry_count'] + 1,
                'last_error_code' => '',
                'last_error_message' => '',
                'update_time' => time(),
            ]);
            $locked['status'] = 'cleaning';
            return (array)$locked;
        });
        if ($receipt === []
            || !empty($receipt['online_deleted_at'])
            || in_array((string)$receipt['status'], ['backup_retention', 'completed'], true)) {
            return;
        }

        $tenantId = (int)$receipt['tenant_id'];
        try {
            if ($receipt['attachments_deleted_at'] === null) {
                self::deleteExclusiveAttachments($tenantId);
                Db::name('tenant_closure_receipt')->where('id', $receiptId)->update([
                    'attachments_deleted_at' => time(),
                    'phase' => 'online_data',
                    'update_time' => time(),
                ]);
            }
            self::deleteOnlineTenantData($tenantId, $receiptId);
        } catch (\Throwable $e) {
            Log::error('[TenantClosure] cleanup failed', [
                'receipt_id' => $receiptId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            Db::name('tenant_closure_receipt')->where('id', $receiptId)->update([
                'status' => 'failed',
                'phase' => 'online_cleanup_failed',
                'last_error_code' => self::errorCode($e),
                'last_error_message' => mb_substr($e->getMessage(), 0, 500),
                'update_time' => time(),
            ]);
        }
    }

    private static function deleteExclusiveAttachments(int $tenantId): void
    {
        $uris = [];
        $tenant = Db::name('tenant')->where('id', $tenantId)->find();
        if ($tenant && trim((string)($tenant['avatar'] ?? '')) !== '') {
            $uris[] = trim((string)$tenant['avatar']);
        }
        $tenantFileTable = self::table('tenant_file');
        if (self::tableExists($tenantFileTable)
            && self::columnExists($tenantFileTable, 'tenant_id')
            && self::columnExists($tenantFileTable, 'uri')) {
            $uris = array_merge($uris, array_map('strval', Db::name('tenant_file')
                ->where('tenant_id', $tenantId)
                ->column('uri')));
        }
        if ($tenant && (int)($tenant['tactics'] ?? 0) === 1) {
            $isolatedFileTable = self::table('tenant_file_' . (string)$tenant['sn']);
            if (self::tableExists($isolatedFileTable) && self::columnExists($isolatedFileTable, 'uri')) {
                $uris = array_merge($uris, array_map('strval', Db::table($isolatedFileTable)->column('uri')));
            }
        }
        $uris = array_values(array_unique(array_filter(array_map('trim', $uris))));
        if ($uris === []) {
            return;
        }

        $driver = new StorageDriver([
            'default' => ConfigService::get('storage', 'default', 'local'),
            'engine' => ConfigService::get('storage') ?? ['local' => []],
        ]);
        foreach ($uris as $uri) {
            if (self::isAttachmentShared($uri, $tenantId)) {
                continue;
            }
            if (!$driver->delete($uri)) {
                $error = trim((string)$driver->getError());
                if (!self::isMissingObjectError($error)) {
                    throw new \RuntimeException('附件删除失败' . ($error === '' ? '' : '：' . $error));
                }
            }
        }
    }

    private static function isAttachmentShared(string $uri, int $tenantId): bool
    {
        $fileTable = self::table('tenant_file');
        if (self::tableExists($fileTable)
            && self::columnExists($fileTable, 'tenant_id')
            && self::columnExists($fileTable, 'uri')
            && Db::name('tenant_file')->where('uri', $uri)->where('tenant_id', '<>', $tenantId)->count() > 0) {
            return true;
        }
        foreach (self::isolatedTenantFileTables($tenantId) as $isolatedFileTable) {
            if (self::columnExists($isolatedFileTable, 'uri')
                && Db::table($isolatedFileTable)->where('uri', $uri)->count() > 0) {
                return true;
            }
        }
        if (self::tableExists(self::table('tenant'))
            && self::columnExists(self::table('tenant'), 'avatar')
            && Db::name('tenant')->where('avatar', $uri)->where('id', '<>', $tenantId)->count() > 0) {
            return true;
        }
        return self::tableExists(self::table('user'))
            && self::columnExists(self::table('user'), 'avatar')
            && Db::name('user')->where('avatar', $uri)->count() > 0;
    }

    private static function isMissingObjectError(string $error): bool
    {
        $error = strtolower($error);
        return str_contains($error, 'not found') || str_contains($error, 'no such')
            || str_contains($error, 'nosuchkey') || str_contains($error, '612');
    }

    private static function deleteOnlineTenantData(int $tenantId, int $receiptId): void
    {
        $tenant = Db::name('tenant')->where('id', $tenantId)->find();
        Db::execute('SET FOREIGN_KEY_CHECKS=0');
        try {
            if ($tenant && (int)($tenant['tactics'] ?? 0) === 1) {
                self::dropIsolatedTenantTables((string)$tenant['sn']);
            }
            Db::transaction(function () use ($tenantId) {
                $roleIds = self::deleteTenantRoleLinks($tenantId);
                self::deleteTenantAdminLinks($tenantId);
                self::deleteTenantRelations($tenantId);

                foreach (self::deletableTenantTables() as $table) {
                    Db::table($table)->where('tenant_id', $tenantId)->delete();
                }

                Db::name('tenant')->where('id', $tenantId)->delete();
                self::verifyNoTenantDataRemains($tenantId, $roleIds);
            });
            self::verifyNoTenantDataRemains($tenantId);
            $time = time();
            Db::name('tenant_closure_receipt')->where('id', $receiptId)->update([
                'status' => 'backup_retention',
                'phase' => 'backup_retention',
                'online_deleted_at' => $time,
                'last_error_code' => '',
                'last_error_message' => '',
                'update_time' => $time,
            ]);
        } finally {
            Db::execute('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private static function verifyNoTenantDataRemains(int $tenantId, array $roleIds = []): void
    {
        $remaining = 0;
        foreach (['tenant_system_role_menu', 'tenant_admin_role'] as $name) {
            if ($roleIds !== [] && self::tableExists(self::table($name))) {
                $remaining += (int)Db::name($name)->whereIn('role_id', $roleIds)->count();
            }
        }
        foreach (self::deletableTenantTables() as $table) {
            $remaining += (int)Db::table($table)->where('tenant_id', $tenantId)->count();
        }
        $relation = self::table('tenant_relation');
        if (self::tableExists($relation)) {
            $remaining += (int)Db::table($relation)->where('parent_tenant_id', $tenantId)
                ->whereOr('child_tenant_id', $tenantId)->count();
        }
        $invite = self::table('tenant_invite');
        if (self::tableExists($invite) && self::columnExists($invite, 'target_tenant_id')) {
            $remaining += (int)Db::table($invite)->where('target_tenant_id', $tenantId)->count();
        }
        if ($remaining > 0) {
            throw new \RuntimeException('检测到新的租户数据写入，在线清理将自动重试');
        }
    }

    /** @return list<int> Role IDs must be retained until the transaction's residual check. */
    private static function deleteTenantRoleLinks(int $tenantId): array
    {
        if (!self::tableExists(self::table('tenant_system_role'))) {
            return [];
        }
        $roleIds = array_map('intval', Db::name('tenant_system_role')
            ->where('tenant_id', $tenantId)->lock(true)->column('id'));
        foreach (['tenant_system_role_menu', 'tenant_admin_role'] as $name) {
            if ($roleIds !== [] && self::tableExists(self::table($name))) {
                Db::name($name)->whereIn('role_id', $roleIds)->delete();
            }
        }
        return $roleIds;
    }

    private static function deleteTenantAdminLinks(int $tenantId): void
    {
        $adminTable = self::table('tenant_admin');
        if (!self::tableExists($adminTable)) {
            return;
        }
        $adminIds = array_map('intval', Db::name('tenant_admin')
            ->where('tenant_id', $tenantId)
            ->column('id'));
        if ($adminIds === []) {
            return;
        }
        foreach (['tenant_admin_dept', 'tenant_admin_jobs', 'tenant_admin_role', 'tenant_admin_session'] as $name) {
            $table = self::table($name);
            if (self::tableExists($table) && self::columnExists($table, 'admin_id')) {
                Db::table($table)->whereIn('admin_id', $adminIds)->delete();
            }
        }
    }

    private static function deleteTenantRelations(int $tenantId): void
    {
        $relation = self::table('tenant_relation');
        if (self::tableExists($relation)) {
            Db::table($relation)->where('parent_tenant_id', $tenantId)
                ->whereOr('child_tenant_id', $tenantId)->delete();
        }
        $invite = self::table('tenant_invite');
        if (self::tableExists($invite) && self::columnExists($invite, 'target_tenant_id')) {
            Db::table($invite)->where('target_tenant_id', $tenantId)->delete();
        }
    }

    /** @return list<string> */
    private static function deletableTenantTables(): array
    {
        $preserved = array_map([self::class, 'table'], self::PRESERVED_TENANT_TABLES);
        return array_values(array_diff(self::tenantScopedTables(), $preserved));
    }

    /** @return list<string> */
    private static function tenantScopedTables(): array
    {
        $rows = Db::query(
            'SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ? AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME',
            ['tenant_id', self::prefix() . '%']
        );
        $tables = [];
        foreach ($rows as $row) {
            $table = (string)($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
            if ($table !== '' && preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    /** @return list<string> */
    private static function isolatedTenantTables(string $tenantSn): array
    {
        if ($tenantSn === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tenantSn)) {
            throw new \RuntimeException('独立租户表编号不安全，已停止清理');
        }
        $templatePath = app()->getRootPath() . 'app/platformapi/db/tenant.sql';
        $template = is_file($templatePath) ? file_get_contents($templatePath) : false;
        if ($template === false) {
            throw new \RuntimeException('独立租户表清单不可用');
        }
        preg_match_all('/`la_([A-Za-z0-9_]+)_\{tenantSn\}`/', $template, $matches);
        $tables = [];
        foreach (array_unique($matches[1] ?? []) as $baseName) {
            $table = self::table((string)$baseName . '_' . $tenantSn);
            if (self::tableExists($table)) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    private static function dropIsolatedTenantTables(string $tenantSn): void
    {
        foreach (self::isolatedTenantTables($tenantSn) as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                throw new \RuntimeException('独立租户表名不安全，已停止清理');
            }
            Db::execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    /** @return list<string> */
    private static function isolatedTenantFileTables(int $excludedTenantId): array
    {
        $excludedSn = (string)(Db::name('tenant')->where('id', $excludedTenantId)->value('sn') ?? '');
        $rows = Db::query(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME',
            [self::table('tenant_file_') . '%']
        );
        $excludedTable = $excludedSn === '' ? '' : self::table('tenant_file_' . $excludedSn);
        $tables = [];
        foreach ($rows as $row) {
            $table = (string)($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
            if ($table !== $excludedTable && preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    private static function receiptById(int $receiptId): array
    {
        $receipt = Db::name('tenant_closure_receipt')->where('id', $receiptId)->find();
        if (!$receipt) {
            throw new \RuntimeException('注销凭据不存在');
        }
        return self::formatReceipt((array)$receipt);
    }

    private static function errorCode(\Throwable $error): string
    {
        return substr(hash('sha256', get_class($error) . ':' . $error->getCode()), 0, 16);
    }

    private static function table(string $logicalName): string
    {
        return self::prefix() . $logicalName;
    }

    private static function prefix(): string
    {
        return (string)config('database.connections.mysql.prefix', 'la_');
    }

    private static function tableExists(string $table): bool
    {
        return Db::query(
            'SELECT 1 FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private static function columnExists(string $table, string $column): bool
    {
        return Db::query(
            'SELECT 1 FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function signPreview(int $userId, int $tenantId, string $fingerprint, int $expiresAt): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'u' => $userId,
            't' => $tenantId,
            'f' => $fingerprint,
            'e' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES));
        return $payload . '.' . hash_hmac('sha256', $payload, self::signingSecret());
    }

    private static function verifyPreview(
        string $token,
        int $userId,
        int $tenantId,
        string $fingerprint
    ): void {
        $decoded = self::previewPayload($token, $userId);
        if ((int)$decoded['t'] !== $tenantId
            || (int)($decoded['e'] ?? 0) < time()
            || !hash_equals((string)($decoded['f'] ?? ''), $fingerprint)) {
            throw new \RuntimeException('店铺数据已变化，请重新预览后再确认');
        }
    }

    /** Validate identity and signature separately: accepted replays may outlive the preview TTL. */
    private static function previewPayload(string $token, int $userId): array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || !hash_equals(hash_hmac('sha256', $payload, self::signingSecret()), $signature)) {
            throw new \RuntimeException('注销预览已失效，请重新获取');
        }
        $decoded = json_decode(self::base64UrlDecode($payload), true);
        if (!is_array($decoded)
            || (int)($decoded['u'] ?? 0) !== $userId
            || (int)($decoded['t'] ?? 0) <= 0) {
            throw new \RuntimeException('注销预览已失效，请重新获取');
        }
        return $decoded;
    }

    private static function signingSecret(): string
    {
        return (string)config('project.unique_identification', 'likeadmin') . ':tenant-closure-preview';
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string)base64_decode(strtr($value, '-_', '+/'));
    }

    private static function formatReceipt(array $receipt): array
    {
        $backupOverdue = empty($receipt['backup_deleted_at'])
            && !empty($receipt['backup_purge_due_at'])
            && (int)$receipt['backup_purge_due_at'] <= time();
        return [
            'receipt_id' => (string)$receipt['public_id'],
            'status' => $backupOverdue ? 'failed' : (string)$receipt['status'],
            'phase' => $backupOverdue ? 'backup_overdue' : (string)$receipt['phase'],
            'effective_at' => (int)$receipt['effective_at'],
            'online_deleted_at' => $receipt['online_deleted_at'] === null ? null : (int)$receipt['online_deleted_at'],
            'attachments_deleted_at' => $receipt['attachments_deleted_at'] === null ? null : (int)$receipt['attachments_deleted_at'],
            'backup_purge_due_at' => (int)$receipt['backup_purge_due_at'],
            'backup_deleted_at' => $receipt['backup_deleted_at'] === null ? null : (int)$receipt['backup_deleted_at'],
            'last_error' => $backupOverdue
                ? '备份超过30天仍未确认清理，请联系平台处理'
                : (string)($receipt['last_error_message'] ?? ''),
        ];
    }
}
