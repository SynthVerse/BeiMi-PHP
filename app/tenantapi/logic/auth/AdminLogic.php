<?php
namespace app\tenantapi\logic\auth;

use app\common\cache\TenantAdminAuthCache;
use app\common\enum\YesNoEnum;
use app\common\logic\BaseLogic;
use app\common\model\auth\TenantAdmin;
use app\common\model\auth\TenantAdminDept;
use app\common\model\auth\TenantAdminJobs;
use app\common\model\auth\TenantAdminRole;
use app\common\model\auth\TenantAdminSession;
use app\common\model\auth\TenantSystemRole;
use app\common\cache\TenantAdminTokenCache;
use app\common\model\dept\TenantDept;
use app\common\model\dept\TenantJobs;
use app\common\model\tenant\Tenant;
use app\common\service\FileService;
use app\common\service\auth\TenantSessionAuthorityService;
use think\facade\Config;
use think\facade\Db;

/**
 * 管理员逻辑
 * Class AdminLogic
 * @package app\tenantapi\logic\auth
 */
class AdminLogic extends BaseLogic
{

    /**
     * @notes 添加管理员
     * @param array $params
     * @author 段誉
     * @date 2021/12/29 10:23
     */
    public static function add(array $params)
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            self::setError('角色参数错误');
            return false;
        }

        Db::startTrans();
        try {
            $roleIds = self::tenantRoleIds($params['role_id'] ?? [], $tenantId);
            $deptIds = self::tenantOwnedIds($params['dept_id'] ?? [], $tenantId, TenantDept::class, '部门参数错误');
            $jobsIds = self::tenantOwnedIds($params['jobs_id'] ?? [], $tenantId, TenantJobs::class, '岗位参数错误');
            $passwordSalt = Config::get('project.unique_identification');
            $password = create_password($params['password'], $passwordSalt);
            $defaultAvatar = config('project.default_image.admin_avatar');
            $avatar = !empty($params['avatar']) ? FileService::setFileUrl($params['avatar']) : $defaultAvatar;

            $admin = TenantAdmin::create([
                'tenant_id' => $tenantId,
                'name' => $params['name'],
                'account' => $params['account'],
                'avatar' => $avatar,
                'password' => $password,
                'create_time' => time(),
                'disable' => $params['disable'],
                'multipoint_login' => $params['multipoint_login'],
            ]);

            // 角色
            self::insertRole($admin['id'], $roleIds);
            // 部门
            self::insertDept($admin['id'], $deptIds);
            // 岗位
            self::insertJobs($admin['id'], $jobsIds);

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 编辑管理员
     * @param array $params
     * @return bool
     * @author 段誉
     * @date 2021/12/29 10:43
     */
    public static function edit(array $params): bool
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            self::setError('角色参数错误');
            return false;
        }

        $expiredTokens = [];
        Db::startTrans();
        try {
            $admin = TenantAdmin::where('id', $params['id'])
                ->where('tenant_id', $tenantId)
                ->lock(true)
                ->findOrEmpty();
            if ($admin->isEmpty()) {
                throw new \Exception('管理员不存在');
            }
            $roleIds = self::tenantRoleIds($params['role_id'] ?? [], $tenantId);
            $deptIds = self::tenantOwnedIds($params['dept_id'] ?? [], $tenantId, TenantDept::class, '部门参数错误');
            $jobsIds = self::tenantOwnedIds($params['jobs_id'] ?? [], $tenantId, TenantJobs::class, '岗位参数错误');
            // 基础信息
            $data = [
                'name' => $params['name'],
                'account' => $params['account'],
                'disable' => $params['disable'],
                'multipoint_login' => $params['multipoint_login']
            ];

            // 头像
            $data['avatar'] = !empty($params['avatar']) ? FileService::setFileUrl($params['avatar']) : '';

            // 密码
            if (!empty($params['password'])) {
                $passwordSalt = Config::get('project.unique_identification');
                $data['password'] = create_password($params['password'], $passwordSalt);
            }

            // 禁用或更换角色后.设置token过期
            $roleId = TenantAdminRole::where('admin_id', $admin['id'])->column('role_id');
            $editRole = false;
            $roleId = array_map('intval', $roleId);
            sort($roleId, SORT_NUMERIC);
            $newRoleIds = $roleIds;
            sort($newRoleIds, SORT_NUMERIC);
            $editRole = $roleId !== $newRoleIds;

            if ($params['disable'] == 1 || $editRole || !empty($params['password']) || (int)$admin['multipoint_login'] !== (int)$params['multipoint_login']) {
                $expiredTokens = TenantSessionAuthorityService::expireAdminSessions((int)$admin['id']);
            }

            $admin->save($data);

            // 删除旧的关联信息
            TenantAdminRole::delByUserId($admin['id']);
            TenantAdminDept::delByUserId($admin['id']);
            TenantAdminJobs::delByUserId($admin['id']);
            // 角色
            self::insertRole($admin['id'], $roleIds);
            // 部门
            self::insertDept($admin['id'], $deptIds);
            // 岗位
            self::insertJobs($admin['id'], $jobsIds);

            Db::commit();
            TenantSessionAuthorityService::clearTokenCaches($expiredTokens);
            TenantSessionAuthorityService::clearAuthorizationCache((int)$admin['id'], $tenantId);
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 删除管理员
     * @param array $params
     * @return bool
     * @author 段誉
     * @date 2021/12/29 10:45
     */
    public static function delete(array $params): bool
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            self::setError('管理员不存在');
            return false;
        }

        $expiredTokens = [];
        Db::startTrans();
        try {
            $admin = TenantAdmin::where('id', $params['id'])
                ->where('tenant_id', $tenantId)
                ->lock(true)
                ->findOrEmpty();
            if ($admin->isEmpty()) {
                throw new \Exception('管理员不存在');
            }
            if ($admin->root == YesNoEnum::YES) {
                throw new \Exception("超级管理员不允许被删除");
            }
            $admin->delete();

            //设置token过期
            $expiredTokens = TenantSessionAuthorityService::expireAdminSessions((int)$admin['id']);
            // 删除旧的关联信息
            TenantAdminRole::delByUserId($admin['id']);
            TenantAdminDept::delByUserId($admin['id']);
            TenantAdminJobs::delByUserId($admin['id']);

            Db::commit();
            TenantSessionAuthorityService::clearTokenCaches($expiredTokens);
            TenantSessionAuthorityService::clearAuthorizationCache((int)$admin['id'], $tenantId);
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 过期token
     * @param $token
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2021/12/29 10:46
     */
    public static function expireToken($token): bool
    {
        $adminSession = TenantAdminSession::where('token', '=', $token)
            ->with('admin')
            ->find();

        if (empty($adminSession)) {
            return false;
        }

        $time = time();
        $adminSession->expire_time = $time;
        $adminSession->update_time = $time;
        $adminSession->save();

        return (new TenantAdminTokenCache())->deleteAdminInfo($token);
    }


    /**
     * @notes 查看管理员详情
     * @param $params
     * @return array
     * @author 段誉
     * @date 2021/12/29 11:07
     */
    public static function detail($params, $action = 'detail'): array
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            return [];
        }
        $admin = TenantAdmin::where('id', $params['id'])
            ->where('tenant_id', $tenantId)
            ->field([
            'id', 'account', 'name', 'disable', 'root','tenant_id',
            'multipoint_login', 'avatar',
        ])->findOrEmpty()->toArray();

        if ($admin === []) {
            return [];
        }

        if ($action == 'detail') {
            return $admin;
        }

        $tenant  = (new Tenant())->where(["id"=>$admin["tenant_id"]])->field(["id","name","avatar"])->find();

        $admin["tenant_name"] = $tenant["name"];
        $admin["tenant_avatar"] = $tenant["avatar"];

        $result['user'] = $admin;

        // 当前管理员角色拥有的菜单
        $result['menu'] = MenuLogic::getMenuByAdminId($params['id']);
        // 当前管理员橘色拥有的按钮权限
        $result['permissions'] = AuthLogic::getBtnAuthByRoleId($admin);
        return $result;
    }


    /**
     * @notes 编辑超级管理员
     * @param $params
     * @author 段誉
     * @date 2022/4/8 17:54
     */
    public static function editSelf($params)
    {
        $data = [
            'name' => $params['name'],
            'avatar' => FileService::setFileUrl($params['avatar']),
        ];

        if (!empty($params['password'])) {
            $passwordSalt = Config::get('project.unique_identification');
            $data['password'] = create_password($params['password'], $passwordSalt);
        }

        return TenantAdmin::update($data, ['id' => $params['admin_id']]);
    }


    /**
     * @notes 新增角色
     * @param $adminId
     * @param $roleIds
     * @throws \Exception
     * @author 段誉
     * @date 2022/11/25 14:23
     */
    private static function insertRole($adminId, $roleIds)
    {
        if (!empty($roleIds)) {
            // 角色
            $roleData = [];
            foreach ($roleIds as $roleId) {
                $roleData[] = [
                    'admin_id' => $adminId,
                    'role_id' => $roleId,
                ];
            }
            (new TenantAdminRole())->saveAll($roleData);
        }
    }

    /** @return list<int> */
    private static function tenantRoleIds(mixed $roleIds, int $tenantId): array
    {
        if ($tenantId <= 0 || !is_array($roleIds)) {
            throw new \Exception('角色参数错误');
        }

        $ids = [];
        foreach ($roleIds as $roleId) {
            if (!is_int($roleId) || $roleId <= 0 || isset($ids[$roleId])) {
                throw new \Exception('角色参数错误');
            }
            $ids[$roleId] = $roleId;
        }
        if ($ids === []) {
            throw new \Exception('角色参数错误');
        }

        $roleIds = array_values($ids);
        $foundIds = TenantSystemRole::where('tenant_id', $tenantId)
            ->whereIn('id', $roleIds)
            ->column('id');
        $foundIds = array_map('intval', $foundIds);
        sort($roleIds, SORT_NUMERIC);
        sort($foundIds, SORT_NUMERIC);
        if ($roleIds !== $foundIds) {
            throw new \Exception('角色参数错误');
        }

        return $roleIds;
    }

    private static function currentTenantId(): int
    {
        $adminInfo = request()->adminInfo ?? null;
        if (!is_array($adminInfo)) {
            return 0;
        }

        $tenantId = (int)($adminInfo['tenant_id'] ?? 0);
        return $tenantId > 0 ? $tenantId : 0;
    }

    /** @return list<int> */
    private static function tenantOwnedIds(
        mixed $values,
        int $tenantId,
        string $modelClass,
        string $error
    ): array {
        if ($tenantId <= 0 || !is_array($values)) {
            throw new \Exception($error);
        }

        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0 || isset($ids[$value])) {
                throw new \Exception($error);
            }
            $ids[$value] = $value;
        }
        if ($ids === []) {
            return [];
        }

        $expected = array_values($ids);
        $found = array_map('intval', $modelClass::where('tenant_id', $tenantId)
            ->whereIn('id', $expected)
            ->column('id'));
        sort($expected, SORT_NUMERIC);
        sort($found, SORT_NUMERIC);
        if ($expected !== $found) {
            throw new \Exception($error);
        }

        return $expected;
    }


    /**
     * @notes 新增部门
     * @param $adminId
     * @param $deptIds
     * @throws \Exception
     * @author 段誉
     * @date 2022/11/25 14:22
     */
    public static function insertDept($adminId, $deptIds)
    {
        // 部门
        if (!empty($deptIds)) {
            $deptData = [];
            foreach ($deptIds as $deptId) {
                $deptData[] = [
                    'admin_id' => $adminId,
                    'dept_id' => $deptId
                ];
            }
            (new TenantAdminDept())->saveAll($deptData);
        }
    }


    /**
     * @notes 新增岗位
     * @param $adminId
     * @param $jobsIds
     * @throws \Exception
     * @author 段誉
     * @date 2022/11/25 14:22
     */
    public static function insertJobs($adminId, $jobsIds)
    {
        // 岗位
        if (!empty($jobsIds)) {
            $jobsData = [];
            foreach ($jobsIds as $jobsId) {
                $jobsData[] = [
                    'admin_id' => $adminId,
                    'jobs_id' => $jobsId
                ];
            }
            (new TenantAdminJobs())->saveAll($jobsData);
        }
    }

}
