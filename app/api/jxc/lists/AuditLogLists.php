<?php
declare(strict_types=1);

namespace app\api\jxc\lists;

use app\common\lists\BaseDataLists;
use app\common\model\jxc\AuditLog;

class AuditLogLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = AuditLog::field([
            'id',
            'tenant_id',
            'admin_id',
            'module',
            'action',
            'target_id',
            'target_sn',
            'before_data',
            'after_data',
            'ip',
            'remark',
            'create_time',
        ]);

        $module = trim((string)($this->params['module'] ?? ''));
        if ($module !== '') {
            $query->where('module', $module);
        }

        $action = trim((string)($this->params['action'] ?? ''));
        if ($action !== '') {
            $query->where('action', $action);
        }

        $adminId = (int)($this->params['admin_id'] ?? 0);
        if ($adminId > 0) {
            $query->where('admin_id', $adminId);
        }

        $targetSn = trim((string)($this->params['target_sn'] ?? ''));
        if ($targetSn !== '') {
            $query->whereLike('target_sn', '%' . $targetSn . '%');
        }

        $startTime = (int)($this->params['start_time'] ?? 0);
        $endTime   = (int)($this->params['end_time'] ?? 0);
        if ($startTime > 0 && $endTime > 0) {
            $query->whereBetween('create_time', [$startTime, $endTime]);
        } elseif ($startTime > 0) {
            $query->where('create_time', '>=', $startTime);
        } elseif ($endTime > 0) {
            $query->where('create_time', '<=', $endTime);
        }

        return $query->order(['create_time' => 'desc', 'id' => 'desc']);
    }

    public function lists(): array
    {
        return $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
