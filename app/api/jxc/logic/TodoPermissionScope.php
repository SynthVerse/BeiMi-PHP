<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 统一待办的权限快照；游标绑定该快照，切换门店/账号或权限后旧游标立即失效。 */
final class TodoPermissionScope
{
    private const KEYS = [
        'report.view', 'report.edit', 'report.remark', 'task.view', 'task.print', 'task.reprint', 'task.control',
        'process.manage', 'settlement.view', 'settlement.weight', 'settlement.bill', 'delivery.line.manage', 'delivery.confirm',
        'inventory.negative.manage', 'finance.purchase.prepare', 'finance.purchase.receive',
        'finance.purchase.confirm', 'finance.inventory.prepare', 'finance.inventory.count',
        'finance.inventory.confirm', 'finance.receipt.prepare', 'finance.receipt.confirm',
        'finance.receivable.prepare', 'finance.receivable.view', 'finance.payment.prepare',
        'finance.payable.view', 'finance.expense.prepare', 'finance.expense.confirm',
        'finance.salary.view', 'finance.salary.prepare', 'finance.reimbursement.prepare',
        'finance.equipment.prepare', 'finance.transfer.prepare', 'finance.reconcile.prepare',
        'finance.refund.prepare', 'finance.recovery.prepare', 'finance.opening.prepare',
    ];

    /** @return array{tenant_id:int,operator_id:int,identity:string,owner:bool,permissions:array<int,string>} */
    public static function snapshot(): array
    {
        unset(request()->todoPermissionSnapshot);
        $tenantId = FinanceAccess::tenant();
        $operatorId = FinanceAccess::operator();
        $userIdentity = FinanceAccess::userIdentity();
        $owner = FinanceAccess::owner();
        $permissions = $owner ? self::KEYS : [];
        if (!$owner && $userIdentity && $tenantId > 0 && $operatorId > 0) {
            $employeeId = (int)Db::name('employee')->where('tenant_id', $tenantId)
                ->where('bind_user_id', $operatorId)->where('is_enabled', 1)->whereNull('delete_time')->value('id');
            if ($employeeId > 0) {
                $permissions = Db::name('employee_permission')->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)->whereIn('permission_key', self::KEYS)
                    ->order('permission_key')->column('permission_key');
            }
        }
        return ['tenant_id' => $tenantId, 'operator_id' => $operatorId,
            'identity' => $userIdentity ? 'user' : 'tenant_admin',
            'owner' => $owner, 'permissions' => $permissions];
    }

    /** @param array<string,mixed> $snapshot */
    public static function version(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $snapshot */
    public static function activate(array $snapshot): void
    {
        request()->todoPermissionSnapshot = $snapshot;
    }
}
