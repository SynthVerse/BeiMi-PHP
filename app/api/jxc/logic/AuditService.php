<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\model\jxc\AuditLog;

class AuditService
{
    // 模块常量
    const MODULE_SALES_ORDER    = 'sales_order';
    const MODULE_SUPPLY_ORDER   = 'supply_order';
    const MODULE_RETURN_ORDER   = 'return_order';
    const MODULE_PURCHASE_ORDER = 'purchase_order';

    // 操作常量
    const ACTION_CREATE  = 'create';
    const ACTION_EDIT    = 'edit';
    const ACTION_DELETE  = 'delete';
    const ACTION_CONFIRM = 'confirm';
    const ACTION_CANCEL  = 'cancel';
    const ACTION_CONVERT = 'convert';

    /**
     * 记录审计日志
     *
     * @param string     $module     模块名
     * @param string     $action     操作类型
     * @param int        $targetId   目标记录ID
     * @param string     $targetSn   目标单据编号
     * @param array|null $beforeData 变更前数据
     * @param array|null $afterData  变更后数据
     * @param string     $remark     备注
     */
    public static function log(
        string $module,
        string $action,
        int $targetId,
        string $targetSn = '',
        ?array $beforeData = null,
        ?array $afterData = null,
        string $remark = ''
    ): void {
        try {
            $tenantId = request()->tenantId ?? 0;
            $adminId  = request()->adminId  ?? 0;
            $ip       = request()->ip()     ?? '';

            AuditLog::create([
                'tenant_id'   => (int)$tenantId,
                'admin_id'    => (int)$adminId,
                'module'      => $module,
                'action'      => $action,
                'target_id'   => $targetId,
                'target_sn'   => $targetSn,
                'before_data' => $beforeData !== null ? json_encode($beforeData, JSON_UNESCAPED_UNICODE) : null,
                'after_data'  => $afterData  !== null ? json_encode($afterData,  JSON_UNESCAPED_UNICODE) : null,
                'ip'          => $ip,
                'remark'      => $remark,
                'create_time' => time(),
            ]);
        } catch (\Throwable $e) {
            // 审计日志写入失败不应影响主业务
            \think\facade\Log::error('审计日志写入失败: ' . $e->getMessage());
        }
    }
}
