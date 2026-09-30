<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;
use think\facade\Log;

class AuditService
{
    // 模块常量
    const MODULE_SALES_ORDER    = 'sales_order';
    const MODULE_SUPPLY_ORDER   = 'supply_order';
    const MODULE_RETURN_ORDER   = 'return_order';
    const MODULE_PURCHASE_RETURN_ORDER = 'purchase_return_order';
    const MODULE_PURCHASE_BATCH = 'purchase_batch';
    const MODULE_CUSTOMER_REPORT = 'customer_report';
    const MODULE_CUSTOMER_REPORT_BATCH = 'customer_report_batch';

    // 操作常量
    const ACTION_CREATE  = 'create';
    const ACTION_EDIT    = 'edit';
    const ACTION_DELETE  = 'delete';
    const ACTION_CONFIRM = 'confirm';
    const ACTION_CANCEL  = 'cancel';
    const ACTION_CONVERT = 'convert';
    const ACTION_SUPPLEMENT = 'supplement';

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
            self::insert($module, $action, $targetId, $targetSn, $beforeData, $afterData, $remark);
        } catch (\Throwable $e) {
            // 审计日志写入失败不应影响主业务
            Log::error('审计日志写入失败: ' . $e->getMessage());
        }
    }

    /**
     * 调用方拥有事务时使用；审计失败必须抛出并使主业务整体回滚。
     */
    public static function logWithinTransaction(
        string $module,
        string $action,
        int $targetId,
        string $targetSn = '',
        ?array $beforeData = null,
        ?array $afterData = null,
        string $remark = ''
    ): void {
        self::insert($module, $action, $targetId, $targetSn, $beforeData, $afterData, $remark);
    }

    private static function insert(
        string $module,
        string $action,
        int $targetId,
        string $targetSn,
        ?array $beforeData,
        ?array $afterData,
        string $remark
    ): void {
        $operatorId = (int)(request()->adminId ?? 0);
        if ($operatorId <= 0) {
            $operatorId = (int)(request()->userId ?? 0);
        }
        $inserted = Db::name('audit_log')->insert([
            'tenant_id' => (int)(request()->tenantId ?? 0),
            'admin_id' => $operatorId,
            'module' => $module,
            'action' => $action,
            'target_id' => $targetId,
            'target_sn' => $targetSn,
            'before_data' => $beforeData !== null ? json_encode($beforeData, JSON_UNESCAPED_UNICODE) : null,
            'after_data' => $afterData !== null ? json_encode($afterData, JSON_UNESCAPED_UNICODE) : null,
            'ip' => (string)(request()->ip() ?? ''),
            'remark' => $remark,
            'create_time' => time(),
        ]);
        if ($inserted !== 1) {
            throw new \RuntimeException('audit_log_insert_failed');
        }
    }
}
