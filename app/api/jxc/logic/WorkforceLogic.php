<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\service\jxc\StoreMembershipService;
use think\facade\Db;

/** 员工档案、可执行工序与显式电子权限。这里不创建默认角色，也不自动授权。 */
final class WorkforceLogic extends BaseLogic
{
    /** @var array<string,array<int,array{key:string,name:string}>> */
    private const PERMISSION_CATALOG = [
        '报货' => [
            ['key' => 'report.view', 'name' => '查看报货'],
            ['key' => 'report.create', 'name' => '录入报货'],
            ['key' => 'report.edit', 'name' => '修改报货'],
            ['key' => 'report.remark', 'name' => '补充与确认加工备注'],
        ],
        '任务' => [
            ['key' => 'task.view', 'name' => '查看任务看板'],
            ['key' => 'task.print', 'name' => '首次打印工票'],
            ['key' => 'task.reprint', 'name' => '补打与重试工票'],
            ['key' => 'task.control', 'name' => '处理工票作废、异常补录与履约变更'],
        ],
        '结算' => [
            ['key' => 'settlement.view', 'name' => '查看结算'],
            ['key' => 'settlement.weight', 'name' => '录入最终实重'],
            ['key' => 'settlement.price', 'name' => '录入最终实价'],
            ['key' => 'settlement.bill', 'name' => '确认并开销售单'],
        ],
        '配送与库存异常' => [
            ['key' => 'delivery.line.manage', 'name' => '管理线车班次与送站趟次'],
            ['key' => 'delivery.confirm', 'name' => '确认真实交付事件'],
            ['key' => 'inventory.negative.manage', 'name' => '处理真负库存待办'],
        ],
        '商品' => [
            ['key' => 'goods.maintain', 'name' => '商品维护'],
        ],
        '财务准备' => [
            ['key' => 'finance.opening.prepare', 'name' => '录入财务启用准备草稿（不含正式确认）'],
            ['key' => 'finance.salary.view', 'name' => '查看工资明细（不含导出或付款确认）'],
            ['key' => 'finance.opening.salary.prepare', 'name' => '准备期初工资草稿（另需工资明细查看权）'],
        ],
        '财务收付款' => [
            ['key' => 'finance.purchase.prepare', 'name' => '准备采购到货与供应商结算草稿'],
            ['key' => 'finance.inventory.prepare', 'name' => '准备库内损耗与核实草稿'],
            ['key' => 'finance.inventory.count', 'name' => '建立盘点范围并录入实盘数量'],
            ['key' => 'finance.inventory.confirm', 'name' => '确认盘点库存与成本差异'],
            ['key' => 'finance.purchase.receive', 'name' => '确认采购实际到货与暂估成本'],
            ['key' => 'finance.purchase.confirm', 'name' => '确认供应商采购结算'],
            ['key' => 'finance.purchase.due_override', 'name' => '单笔采购覆盖默认付款日（须说明原因）'],
            ['key' => 'finance.receipt.prepare', 'name' => '准备客户收款草稿'],
            ['key' => 'finance.receivable.prepare', 'name' => '准备应收调整与坏账草稿（不含确认）'],
            ['key' => 'finance.receivable.view', 'name' => '查看本门店客户往来与欠款明细'],
            ['key' => 'finance.sales.precision_override', 'name' => '单笔覆盖销售金额精度（须说明原因）'],
            ['key' => 'finance.sales.rounding', 'name' => '销售人工抹零'],
            ['key' => 'finance.receipt.confirm', 'name' => '确认本门店客户收款'],
            ['key' => 'finance.sales.due_override', 'name' => '单笔销售覆盖默认付款日（需填写依据）'],
            ['key' => 'finance.payment.prepare', 'name' => '准备供应商与费用付款（不含确认）'],
            ['key' => 'finance.expense.prepare', 'name' => '准备普通费用草稿（不含付款）'],
            ['key' => 'finance.expense.confirm', 'name' => '确认普通费用（不含付款）'],
            ['key' => 'finance.payable.view', 'name' => '查看本门店供应商往来与对账明细'],
            ['key' => 'finance.salary.prepare', 'name' => '准备工资与发放草稿（另需工资明细权）'],
            ['key' => 'finance.reimbursement.prepare', 'name' => '准备员工垫付报销（不含付款确认）'],
            ['key' => 'finance.equipment.prepare', 'name' => '准备设备业务（不含确认）'],
            ['key' => 'finance.transfer.prepare', 'name' => '准备同门店互转、到账、返还及手续费（不含确认）'],
            ['key' => 'finance.reconcile.prepare', 'name' => '准备账户月末核对与现金短款（不含确认）'],
            ['key' => 'finance.refund.prepare', 'name' => '准备退款业务（不含确认）'],
            ['key' => 'finance.recovery.prepare', 'name' => '准备坏账追偿收回（不含确认）'],
        ],
        '财务报表' => [
            ['key' => 'finance.report.profit.view', 'name' => '查看经营利润报表'],
            ['key' => 'finance.report.cash.view', 'name' => '查看资金收支报表'],
            ['key' => 'finance.report.customer.view', 'name' => '查看客户往来报表'],
            ['key' => 'finance.report.vendor.view', 'name' => '查看供应商往来报表'],
            ['key' => 'finance.report.expense.view', 'name' => '查看费用分析报表（工资明细另行授权）'],
            ['key' => 'finance.report.inventory.view', 'name' => '查看库存与损耗报表'],
            ['key' => 'finance.report.profit.export', 'name' => '导出经营利润报表'],
            ['key' => 'finance.report.cash.export', 'name' => '导出资金收支报表'],
            ['key' => 'finance.report.customer.export', 'name' => '导出客户往来报表'],
            ['key' => 'finance.report.vendor.export', 'name' => '导出供应商往来报表'],
            ['key' => 'finance.report.expense.export', 'name' => '导出费用分析报表（工资明细另行授权）'],
            ['key' => 'finance.report.inventory.export', 'name' => '导出库存与损耗报表'],
        ],
        '设置' => [
            ['key' => 'employee.manage', 'name' => '管理员工'],
            ['key' => 'process.manage', 'name' => '管理工序'],
            ['key' => 'setting.bluetooth', 'name' => '配置蓝牙打印机'],
        ],
    ];

    /** @return array{groups:array<string,array<int,array{key:string,name:string}>>,keys:array<int,string>} */
    public static function permissionCatalog(): array|false
    {
        self::clearError();
        if (!self::requirePermission('employee.manage')) {
            return false;
        }
        $keys = [];
        foreach (self::PERMISSION_CATALOG as $permissions) {
            foreach ($permissions as $permission) {
                $keys[] = $permission['key'];
            }
        }
        return ['groups' => self::PERMISSION_CATALOG, 'keys' => $keys];
    }

    /** @return array{keys:array<int,string>,is_admin:bool} */
    public static function currentPermissions(): array
    {
        self::clearError();
        $keys = [];
        foreach (self::PERMISSION_CATALOG as $permissions) {
            foreach ($permissions as $permission) {
                if (self::hasPermission($permission['key'])) {
                    $keys[] = $permission['key'];
                }
            }
        }
        $all = self::allPermissionKeys();
        sort($keys);
        sort($all);
        return ['keys' => $keys, 'is_admin' => $keys === $all];
    }

    public static function ensureInitialProcesses(): void
    {
        // 兼容旧调用点。执行工序必须由店铺管理员显式创建，新店铺不再写入固定目录。
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int} */
    public static function processes(array $params): array|false
    {
        self::clearError();
        if (!self::requireAnyPermission(['process.manage', 'employee.manage', 'task.view'])) {
            return false;
        }
        $query = Db::name('work_process')->where('tenant_id', self::tenantId())->whereNull('delete_time');
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        if (isset($params['is_enabled']) && $params['is_enabled'] !== '') {
            $query->where('is_enabled', (int)$params['is_enabled']);
        }
        $lists = $query->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        foreach ($lists as &$process) {
            $process['keywords'] = self::decodeKeywords((string)$process['trigger_keywords']);
        }
        unset($process);
        return ['lists' => $lists, 'count' => count($lists)];
    }

    /** @return array<string,mixed>|false */
    public static function saveProcess(array $params): array|false
    {
        self::clearError();
        if (!self::requirePermission('process.manage')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $name = trim((string)($params['name'] ?? ''));
        $triggerType = (string)($params['trigger_type'] ?? 'report_selection');
        if ($name === '' || !in_array($triggerType, ['report_selection', 'inventory_shortage', 'all_processing_completed'], true)) {
            self::setError('工序名称或触发方式无效');
            return false;
        }
        $isEnabled = (int)($params['is_enabled'] ?? 1) === 1 ? 1 : 0;
        if ($isEnabled === 1 && self::automaticProcessExists($triggerType, $id)) {
            self::setError($triggerType === 'inventory_shortage'
                ? '库存不足自动产生只能启用一个工序'
                : '全部加工完成后自动产生只能启用一个工序');
            return false;
        }
        $now = time();
        $data = [
            'name' => $name,
            'trigger_type' => $triggerType,
            'trigger_keywords' => '[]',
            'sort' => (int)($params['sort'] ?? 0),
            'is_enabled' => $isEnabled,
            'update_time' => $now,
        ];
        if ($id > 0) {
            $exists = Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')->find();
            if (!$exists) {
                self::setError('工序不存在');
                return false;
            }
            Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('process_id', $id)
                ->where('process_name_snapshot', '')->update(['process_name_snapshot' => (string)$exists['name']]);
            Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->update($data);
        } else {
            $id = (int)Db::name('work_process')->insertGetId($data + [
                'tenant_id' => self::tenantId(),
                'code' => 'custom_' . substr(sha1($name . ':' . microtime(true)), 0, 16),
                'is_system' => 0,
                'create_time' => $now,
            ]);
        }
        $process = self::processById($id);
        if ($process !== false && $isEnabled === 1) {
            FulfillmentTaskLogic::resyncReportsWaitingForProcess($triggerType);
        }
        return $process;
    }

    /** @return array<string,mixed>|false */
    public static function statusProcess(array $params): array|false
    {
        self::clearError();
        if (!self::requirePermission('process.manage')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $process = Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')->find();
        if (!$process) {
            self::setError('工序不存在');
            return false;
        }
        $isEnabled = (int)($params['is_enabled'] ?? 0) === 1 ? 1 : 0;
        if ($isEnabled === 1 && self::automaticProcessExists((string)$process['trigger_type'], $id)) {
            self::setError((string)$process['trigger_type'] === 'inventory_shortage'
                ? '库存不足自动产生只能启用一个工序'
                : '全部加工完成后自动产生只能启用一个工序');
            return false;
        }
        $updated = Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')
            ->update(['is_enabled' => $isEnabled, 'update_time' => time()]);
        if ($updated < 1) {
            self::setError('工序不存在或状态未变化');
            return false;
        }
        $result = self::processById($id);
        if ($result !== false && $isEnabled === 1) {
            FulfillmentTaskLogic::resyncReportsWaitingForProcess((string)$process['trigger_type']);
        }
        return $result;
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int} */
    public static function reorderProcesses(array $params): array|false
    {
        self::clearError();
        if (!self::requirePermission('process.manage')) {
            return false;
        }
        $ids = array_values(array_unique(array_map('intval', (array)($params['ids'] ?? []))));
        $allIds = array_map('intval', Db::name('work_process')->where('tenant_id', self::tenantId())
            ->whereNull('delete_time')->order(['sort' => 'asc', 'id' => 'asc'])->column('id'));
        $sortedIds = $ids;
        $sortedAllIds = $allIds;
        sort($sortedIds);
        sort($sortedAllIds);
        if ($sortedIds !== $sortedAllIds) {
            self::setError('排序必须包含完整工序目录');
            return false;
        }
        Db::transaction(static function () use ($ids): void {
            foreach ($ids as $sort => $id) {
                Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')
                    ->update(['sort' => ($sort + 1) * 10, 'update_time' => time()]);
            }
        });
        return self::processes([]);
    }

    /** @return array{id:int}|false */
    public static function deleteProcess(array $params): array|false
    {
        self::clearError();
        if (!self::requirePermission('process.manage')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $process = Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')->find();
        if (!$process) {
            self::setError('工序不存在');
            return false;
        }
        if (Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('process_id', $id)->count() > 0) {
            self::setError('工序已有历史任务，只能停用');
            return false;
        }
        if (Db::name('employee_process')->where('tenant_id', self::tenantId())->where('process_id', $id)->count() > 0) {
            self::setError('工序已有历史员工关联，只能停用');
            return false;
        }
        Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)
            ->update(['is_enabled' => 0, 'delete_time' => time(), 'update_time' => time()]);
        return ['id' => $id];
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int} */
    public static function employees(array $params): array|false
    {
        self::clearError();
        if (!self::requirePermission('employee.manage')) {
            return false;
        }
        $query = Db::name('employee')->where('tenant_id', self::tenantId())->whereNull('delete_time');
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(static function ($builder) use ($keyword): void {
                $builder->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('mobile', 'like', '%' . $keyword . '%');
            });
        }
        if (isset($params['is_enabled']) && $params['is_enabled'] !== '') {
            $query->where('is_enabled', (int)$params['is_enabled']);
        }
        $lists = $query->order(['is_enabled' => 'desc', 'id' => 'desc'])->select()->toArray();
        foreach ($lists as &$employee) {
            $employee = self::hydrateEmployee($employee);
        }
        unset($employee);
        return ['lists' => $lists, 'count' => count($lists)];
    }

    /** @return array<string,mixed>|false */
    public static function employee(array $params): array|false
    {
        self::clearError();
        if (!self::requirePermission('employee.manage')) {
            return false;
        }
        return self::employeeById((int)($params['id'] ?? 0));
    }

    /** @return array<string,mixed>|false */
    public static function saveEmployee(array $params): array|false
    {
        self::clearError();
        if (!self::requirePermission('employee.manage')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $name = trim((string)($params['name'] ?? ''));
        $mobile = trim((string)($params['mobile'] ?? ''));
        if ($name === '' || $mobile === '') {
            self::setError('员工姓名和手机号不能为空');
            return false;
        }
        $permissionKeys = self::normalizeStrings((array)($params['permission_keys'] ?? []), 80, 64);
        $known = self::permissionCatalog()['keys'];
        foreach ($permissionKeys as $permissionKey) {
            if (!in_array($permissionKey, $known, true)) {
                self::setError('包含未知的电子权限');
                return false;
            }
        }
        sort($permissionKeys);
        $bindUserId = max(0, (int)($params['bind_user_id'] ?? 0));
        try {
            Db::transaction(static function () use (&$id, $name, $mobile, $bindUserId, $params, $permissionKeys): void {
                $now = time();
                $data = [
                    'name' => $name,
                    'mobile' => $mobile,
                    'bind_user_id' => $bindUserId > 0 ? $bindUserId : null,
                    'is_enabled' => (int)($params['is_enabled'] ?? 1) === 1 ? 1 : 0,
                    'update_time' => $now,
                ];
                if ($id > 0) {
                    $exists = Db::name('employee')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')->lock(true)->find();
                    if (!$exists) {
                        throw new \RuntimeException('employee_not_found');
                    }
                    Db::name('employee')->where('tenant_id', self::tenantId())->where('id', $id)->update($data);
                } else {
                    $id = (int)Db::name('employee')->insertGetId($data + ['tenant_id' => self::tenantId(), 'create_time' => $now]);
                }
                Db::name('employee_permission')->where('tenant_id', self::tenantId())->where('employee_id', $id)->delete();
                foreach ($permissionKeys as $permissionKey) {
                    Db::name('employee_permission')->insert(['tenant_id' => self::tenantId(), 'employee_id' => $id, 'permission_key' => $permissionKey, 'create_time' => $now]);
                }
            });
        } catch (\Throwable $exception) {
            self::setError($exception->getMessage() === 'employee_not_found' ? '员工不存在' : '员工手机号或绑定账号已存在');
            return false;
        }
        return self::employeeById($id);
    }

    /** @return array<string,mixed>|false */
    public static function statusEmployee(array $params): array|false
    {
        self::clearError();
        if (!self::requirePermission('employee.manage')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $updated = Db::name('employee')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')
            ->update(['is_enabled' => (int)($params['is_enabled'] ?? 0) === 1 ? 1 : 0, 'update_time' => time()]);
        if ($updated < 1) {
            self::setError('员工不存在或状态未变化');
            return false;
        }
        return self::employeeById($id);
    }

    public static function hasPermission(string $permissionKey): bool
    {
        $adminInfo = (array)(request()->adminInfo ?? []);
        if ((int)($adminInfo['root'] ?? 0) === 1) {
            return true;
        }
        $userId = (int)(request()->userId ?? request()->adminId ?? 0);
        if ($userId <= 0) {
            return false;
        }
        if (StoreMembershipService::isTenantAdmin($userId, self::tenantId())) {
            return true;
        }
        $employeeId = (int)Db::name('employee')->where('tenant_id', self::tenantId())->where('bind_user_id', $userId)
            ->where('is_enabled', 1)->whereNull('delete_time')->value('id');
        return $employeeId > 0 && Db::name('employee_permission')->where('tenant_id', self::tenantId())
            ->where('employee_id', $employeeId)->where('permission_key', $permissionKey)->count() > 0;
    }

    public static function requirePermission(string $permissionKey): bool
    {
        if (self::hasPermission($permissionKey)) {
            return true;
        }
        self::setError('没有执行该操作的电子权限');
        return false;
    }

    /** @param array<int,string> $permissionKeys */
    public static function hasAnyPermission(array $permissionKeys): bool
    {
        foreach ($permissionKeys as $permissionKey) {
            if (self::hasPermission($permissionKey)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,string> $permissionKeys */
    public static function requireAnyPermission(array $permissionKeys): bool
    {
        if (self::hasAnyPermission($permissionKeys)) {
            return true;
        }
        self::setError('没有执行该操作的电子权限');
        return false;
    }

    /** @return array<int,string> */
    private static function allPermissionKeys(): array
    {
        $keys = [];
        foreach (self::PERMISSION_CATALOG as $permissions) {
            foreach ($permissions as $permission) {
                $keys[] = $permission['key'];
            }
        }
        return $keys;
    }

    /** @return array<string,mixed>|false */
    private static function employeeById(int $id): array|false
    {
        $employee = Db::name('employee')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')->find();
        if (!$employee) {
            self::setError('员工不存在');
            return false;
        }
        return self::hydrateEmployee($employee);
    }

    /** @param array<string,mixed> $employee @return array<string,mixed> */
    private static function hydrateEmployee(array $employee): array
    {
        $employee['bind_user_id'] = (int)($employee['bind_user_id'] ?? 0);
        $employee['login_mode'] = $employee['bind_user_id'] > 0 ? 'bound' : 'paper_only';
        $employee['historical_process_ids'] = array_map('intval', Db::name('employee_process')->where('tenant_id', self::tenantId())->where('employee_id', (int)$employee['id'])->order('process_id')->column('process_id'));
        $employee['historical_processes'] = $employee['historical_process_ids'] === [] ? [] : Db::name('work_process')->where('tenant_id', self::tenantId())->whereIn('id', $employee['historical_process_ids'])->order(['sort' => 'asc', 'id' => 'asc'])->field('id,code,name')->select()->toArray();
        $employee['permission_keys'] = Db::name('employee_permission')->where('tenant_id', self::tenantId())->where('employee_id', (int)$employee['id'])->order('permission_key')->column('permission_key');
        $employee['permission_count'] = count($employee['permission_keys']);
        return $employee;
    }

    /** @return array<string,mixed>|false */
    private static function processById(int $id): array|false
    {
        $process = Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')->find();
        if (!$process) {
            self::setError('工序不存在');
            return false;
        }
        $process['keywords'] = self::decodeKeywords((string)$process['trigger_keywords']);
        return $process;
    }

    private static function automaticProcessExists(string $triggerType, int $exceptId = 0): bool
    {
        if (!in_array($triggerType, ['inventory_shortage', 'all_processing_completed'], true)) {
            return false;
        }
        $query = Db::name('work_process')
            ->where('tenant_id', self::tenantId())
            ->where('trigger_type', $triggerType)
            ->where('is_enabled', 1)
            ->whereNull('delete_time');
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        return $query->count() > 0;
    }

    /** @return array<int,string> */
    private static function decodeKeywords(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? self::normalizeStrings($decoded, 30, 40) : [];
    }

    /** @return array<int,string> */
    private static function normalizeStrings(array $values, int $maxItems, int $maxLength): array
    {
        $result = [];
        foreach (array_slice($values, 0, $maxItems) as $value) {
            $value = trim((string)$value);
            if ($value !== '' && mb_strlen($value) <= $maxLength) {
                $result[] = $value;
            }
        }
        return array_values(array_unique($result));
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
