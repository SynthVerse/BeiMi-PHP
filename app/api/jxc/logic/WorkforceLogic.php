<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\service\jxc\StoreMembershipService;
use think\facade\Db;

/** 员工档案、可执行工序与显式电子权限。这里不创建默认角色，也不自动授权。 */
final class WorkforceLogic extends BaseLogic
{
    /** @var array<string,string> */
    private const CORE_PROCESS_TRIGGERS = [
        'purchase' => 'shortage',
        'delivery' => 'group_ready',
        'bookkeeping' => 'ticket_recovered',
    ];

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
            ['key' => 'task.assign', 'name' => '分配任务'],
            ['key' => 'task.print', 'name' => '首次打印工票'],
            ['key' => 'task.reprint', 'name' => '补打与重试工票'],
            ['key' => 'task.recover', 'name' => '确认纸质工票回收'],
            ['key' => 'task.control', 'name' => '处理工票作废、异常补录与履约变更'],
        ],
        '结算' => [
            ['key' => 'settlement.view', 'name' => '查看结算'],
            ['key' => 'settlement.weight', 'name' => '录入最终实重'],
            ['key' => 'settlement.price', 'name' => '录入最终实价'],
            ['key' => 'settlement.bill', 'name' => '确认并开销售单'],
        ],
        '配送与库存异常' => [
            ['key' => 'delivery.confirm', 'name' => '确认真实交付事件'],
            ['key' => 'inventory.negative.manage', 'name' => '处理真负库存待办'],
        ],
        '商品' => [
            ['key' => 'goods.maintain', 'name' => '商品维护'],
        ],
        '设置' => [
            ['key' => 'employee.manage', 'name' => '管理员工'],
            ['key' => 'process.manage', 'name' => '管理工序'],
            ['key' => 'setting.bluetooth', 'name' => '配置蓝牙打印机'],
        ],
    ];

    /** @var array<int,array<string,mixed>> */
    private const INITIAL_PROCESSES = [
        ['code' => 'purchase', 'name' => '采购', 'trigger_type' => 'shortage', 'keywords' => [], 'sort' => 10],
        ['code' => 'kill_fish', 'name' => '杀鱼', 'trigger_type' => 'remark', 'keywords' => ['杀好', '杀鱼', '宰杀', '劏', '去鳞', '开肚'], 'sort' => 20],
        ['code' => 'live_pack', 'name' => '活鱼打包', 'trigger_type' => 'remark', 'keywords' => ['活鱼打包', '活鱼装袋', '活包', '打氧', '氧气袋'], 'sort' => 30],
        ['code' => 'abalone_pack', 'name' => '鲍鱼打包', 'trigger_type' => 'remark', 'keywords' => ['鲍鱼打包', '鲍鱼装袋', '鲍鱼包', '冰袋', '泡沫箱'], 'sort' => 40],
        ['code' => 'delivery', 'name' => '送货', 'trigger_type' => 'group_ready', 'keywords' => [], 'sort' => 50],
        ['code' => 'bookkeeping', 'name' => '记账', 'trigger_type' => 'ticket_recovered', 'keywords' => [], 'sort' => 60],
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
        $tenantId = self::tenantId();
        if ($tenantId <= 0) {
            return;
        }
        $now = time();
        foreach (self::INITIAL_PROCESSES as $process) {
            Db::name('work_process')->duplicate(['code'])->insert([
                'tenant_id' => $tenantId,
                'code' => $process['code'],
                'name' => $process['name'],
                'trigger_type' => $process['trigger_type'],
                'trigger_keywords' => json_encode($process['keywords'], JSON_UNESCAPED_UNICODE),
                'sort' => $process['sort'],
                'is_enabled' => 1,
                'is_system' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            if (isset(self::CORE_PROCESS_TRIGGERS[(string)$process['code']])) {
                Db::name('work_process')->where('tenant_id', $tenantId)->where('code', $process['code'])->update([
                    'trigger_type' => self::CORE_PROCESS_TRIGGERS[(string)$process['code']],
                    'is_enabled' => 1,
                    'delete_time' => null,
                    'update_time' => $now,
                ]);
            }
        }
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int} */
    public static function processes(array $params): array|false
    {
        self::clearError();
        if (!self::requireAnyPermission(['process.manage', 'employee.manage', 'task.assign'])) {
            return false;
        }
        self::ensureInitialProcesses();
        $query = Db::name('work_process')->where('tenant_id', self::tenantId())->whereNull('delete_time');
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        if (isset($params['is_enabled']) && $params['is_enabled'] !== '') {
            $query->where('is_enabled', (int)$params['is_enabled']);
        }
        $lists = $query->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        $counts = Db::name('employee_process')->alias('ep')
            ->join('employee e', 'e.id=ep.employee_id AND e.tenant_id=ep.tenant_id')
            ->where('ep.tenant_id', self::tenantId())->where('e.is_enabled', 1)->whereNull('e.delete_time')
            ->group('ep.process_id')->column('COUNT(DISTINCT ep.employee_id)', 'ep.process_id');
        foreach ($lists as &$process) {
            $process['keywords'] = self::decodeKeywords((string)$process['trigger_keywords']);
            $process['employee_count'] = (int)($counts[$process['id']] ?? 0);
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
        $triggerType = (string)($params['trigger_type'] ?? 'remark');
        if ($name === '' || !in_array($triggerType, ['remark', 'shortage', 'group_ready', 'ticket_recovered', 'manual'], true)) {
            self::setError('工序名称或触发方式无效');
            return false;
        }
        $keywords = self::normalizeStrings((array)($params['keywords'] ?? []), 30, 40);
        $now = time();
        $data = [
            'name' => $name,
            'trigger_type' => $triggerType,
            'trigger_keywords' => json_encode($keywords, JSON_UNESCAPED_UNICODE),
            'sort' => (int)($params['sort'] ?? 0),
            'is_enabled' => (int)($params['is_enabled'] ?? 1) === 1 ? 1 : 0,
            'update_time' => $now,
        ];
        if ($id > 0) {
            $exists = Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')->find();
            if (!$exists) {
                self::setError('工序不存在');
                return false;
            }
            $coreTrigger = self::CORE_PROCESS_TRIGGERS[(string)$exists['code']] ?? null;
            if ($coreTrigger !== null && ($triggerType !== $coreTrigger || (int)$data['is_enabled'] !== 1)) {
                self::setError('采购、送货和记账是闭环核心工序，只能调整名称与排序');
                return false;
            }
            if ($coreTrigger === null && $triggerType !== 'remark') {
                self::setError('当前仅备注关键词工序支持自定义；其他触发类型由闭环核心工序专用');
                return false;
            }
            Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('process_id', $id)
                ->where('process_name_snapshot', '')->update(['process_name_snapshot' => (string)$exists['name']]);
            Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->update($data);
        } else {
            if ($triggerType !== 'remark') {
                self::setError('当前仅备注关键词工序支持自定义；其他触发类型由闭环核心工序专用');
                return false;
            }
            $id = (int)Db::name('work_process')->insertGetId($data + [
                'tenant_id' => self::tenantId(),
                'code' => 'custom_' . substr(sha1($name . ':' . microtime(true)), 0, 16),
                'is_system' => 0,
                'create_time' => $now,
            ]);
        }
        return self::processById($id);
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
        if (isset(self::CORE_PROCESS_TRIGGERS[(string)$process['code']]) && (int)($params['is_enabled'] ?? 0) !== 1) {
            self::setError('采购、送货和记账是闭环核心工序，不能停用');
            return false;
        }
        $updated = Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)->whereNull('delete_time')
            ->update(['is_enabled' => (int)($params['is_enabled'] ?? 0) === 1 ? 1 : 0, 'update_time' => time()]);
        if ($updated < 1) {
            self::setError('工序不存在或状态未变化');
            return false;
        }
        return self::processById($id);
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
        if (isset(self::CORE_PROCESS_TRIGGERS[(string)$process['code']])) {
            self::setError('采购、送货和记账是闭环核心工序，不能删除');
            return false;
        }
        if (Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('process_id', $id)->count() > 0) {
            self::setError('工序已有历史任务，只能停用');
            return false;
        }
        Db::transaction(static function () use ($id): void {
            Db::name('employee_process')->where('tenant_id', self::tenantId())->where('process_id', $id)->delete();
            Db::name('work_process')->where('tenant_id', self::tenantId())->where('id', $id)
                ->update(['is_enabled' => 0, 'delete_time' => time(), 'update_time' => time()]);
        });
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
        self::ensureInitialProcesses();
        $processIds = array_values(array_unique(array_filter(array_map('intval', (array)($params['process_ids'] ?? [])), static fn(int $value): bool => $value > 0)));
        $allowedProcessIds = Db::name('work_process')->where('tenant_id', self::tenantId())->whereIn('id', $processIds)->whereNull('delete_time')->column('id');
        sort($processIds);
        $allowedProcessIds = array_map('intval', $allowedProcessIds);
        sort($allowedProcessIds);
        if ($processIds !== $allowedProcessIds) {
            self::setError('包含不存在的工序');
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
            Db::transaction(static function () use (&$id, $name, $mobile, $bindUserId, $params, $processIds, $permissionKeys): void {
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
                Db::name('employee_process')->where('tenant_id', self::tenantId())->where('employee_id', $id)->delete();
                foreach ($processIds as $processId) {
                    Db::name('employee_process')->insert(['tenant_id' => self::tenantId(), 'employee_id' => $id, 'process_id' => $processId, 'create_time' => $now]);
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

    /** @return array<int,array<string,mixed>> */
    public static function candidatesForProcess(int $processId): array
    {
        $rows = Db::name('employee')->alias('e')->join('employee_process ep', 'ep.employee_id=e.id AND ep.tenant_id=e.tenant_id')
            ->where('e.tenant_id', self::tenantId())->where('ep.process_id', $processId)->where('e.is_enabled', 1)->whereNull('e.delete_time')
            ->field('e.id,e.name,e.mobile,e.bind_user_id')->order(['e.name' => 'asc', 'e.id' => 'asc'])->select()->toArray();
        foreach ($rows as &$row) {
            $row['bind_user_id'] = (int)($row['bind_user_id'] ?? 0);
            $row['login_mode'] = $row['bind_user_id'] > 0 ? 'bound' : 'paper_only';
        }
        unset($row);
        return $rows;
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
        $employee['process_ids'] = array_map('intval', Db::name('employee_process')->where('tenant_id', self::tenantId())->where('employee_id', (int)$employee['id'])->order('process_id')->column('process_id'));
        $employee['processes'] = $employee['process_ids'] === [] ? [] : Db::name('work_process')->where('tenant_id', self::tenantId())->whereIn('id', $employee['process_ids'])->order(['sort' => 'asc', 'id' => 'asc'])->field('id,code,name')->select()->toArray();
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
        $process['employee_count'] = Db::name('employee_process')->alias('ep')->join('employee e', 'e.id=ep.employee_id AND e.tenant_id=ep.tenant_id')
            ->where('ep.tenant_id', self::tenantId())->where('ep.process_id', $id)->where('e.is_enabled', 1)->whereNull('e.delete_time')->count();
        return $process;
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
