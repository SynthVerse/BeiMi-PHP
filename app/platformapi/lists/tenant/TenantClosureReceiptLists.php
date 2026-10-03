<?php

declare(strict_types=1);

namespace app\platformapi\lists\tenant;

use app\platformapi\lists\BaseAdminDataLists;
use think\facade\Db;

class TenantClosureReceiptLists extends BaseAdminDataLists
{
    public function lists(): array
    {
        $rows = $this->query()
            ->limit($this->limitOffset, $this->limitLength)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return array_map(static function (array $row): array {
            $backupDeleted = !empty($row['backup_deleted_at']);
            $backupOverdue = !$backupDeleted
                && (int)$row['backup_purge_due_at'] > 0
                && (int)$row['backup_purge_due_at'] <= time();
            return [
                'public_id' => (string)$row['public_id'],
                'tenant_id' => (int)$row['tenant_id'],
                'operator_user_id' => (int)$row['operator_user_id'],
                'status' => $backupOverdue ? 'failed' : (string)$row['status'],
                'phase' => $backupOverdue ? 'backup_overdue' : (string)$row['phase'],
                'effective_at' => (int)$row['effective_at'],
                'online_deleted_at' => $row['online_deleted_at'] === null ? null : (int)$row['online_deleted_at'],
                'attachments_deleted_at' => $row['attachments_deleted_at'] === null ? null : (int)$row['attachments_deleted_at'],
                'backup_purge_due_at' => (int)$row['backup_purge_due_at'],
                'backup_deleted_at' => $row['backup_deleted_at'] === null ? null : (int)$row['backup_deleted_at'],
                'backup_purged_by_admin_id' => (int)$row['backup_purged_by_admin_id'],
                'backup_overdue' => $backupOverdue,
                'retry_count' => (int)$row['retry_count'],
                'last_error_code' => (string)$row['last_error_code'],
                'last_error_message' => $backupOverdue
                    ? '备份超过30天仍未确认清理，请立即处理'
                    : (string)$row['last_error_message'],
                'create_time' => (int)$row['create_time'],
                'update_time' => (int)$row['update_time'],
            ];
        }, $rows);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    private function query()
    {
        $query = Db::name('tenant_closure_receipt');
        $keyword = trim((string)($this->params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('public_id', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $query->whereOr('tenant_id', (int)$keyword)
                        ->whereOr('operator_user_id', (int)$keyword);
                }
            });
        }
        $status = trim((string)($this->params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        return $query;
    }
}
