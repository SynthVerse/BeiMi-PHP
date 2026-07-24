<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------

namespace app\tenantapi\validate\auth;


use app\common\validate\BaseValidate;
use app\common\model\auth\{TenantAdminRole, TenantSystemRole, TenantSystemMenu};

/**
 * 角色验证器
 * Class RoleValidate
 * @package app\tenantapi\validate\auth
 */
class RoleValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|checkRole',
        'name' => 'require|max:64|checkName',
        'menu_id' => 'array|checkMenus',
    ];

    protected $message = [
        'id.require' => '请选择角色',
        'name.require' => '请输入角色名称',
        'name.max' => '角色名称最长为16个字符',
        'name.unique' => '角色名称已存在',
        'menu_id.array' => '权限格式错误'
    ];

    /**
     * @notes 添加场景
     * @return RoleValidate
     * @author 段誉
     * @date 2021/12/29 15:47
     */
    public function sceneAdd()
    {
        return $this->only(['name', 'menu_id']);
    }

    /**
     * @notes 详情场景
     * @return RoleValidate
     * @author 段誉
     * @date 2021/12/29 15:47
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    /**
     * @notes 删除场景
     * @return RoleValidate
     * @author 段誉
     * @date 2021/12/29 15:48
     */
    public function sceneDel()
    {
        return $this->only(['id'])
            ->append('id', 'checkAdmin');
    }


    /**
     * @notes 验证角色是否存在
     * @param $value
     * @param $rule
     * @param $data
     * @return bool|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2021/12/29 15:48
     */
    public function checkRole($value, $rule, $data)
    {
        if (!TenantSystemRole::where('id', $value)->where('tenant_id', $this->currentTenantId())->find()) {
            return '角色不存在';
        }
        return true;
    }



    /**
     * @notes 验证角色是否被使用
     * @param $value
     * @param $rule
     * @param $data
     * @return bool|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2021/12/29 15:49
     */
    public function checkAdmin($value, $rule, $data)
    {
        if (TenantAdminRole::where(['role_id' => $value])->find()) {
            return '有管理员在使用该角色，不允许删除';
        }
        return true;
    }

    public function checkName($value, $rule, $data)
    {
        $tenantId = $this->currentTenantId();
        if ($tenantId <= 0) {
            return '租户上下文不可用';
        }
        $query = TenantSystemRole::where('tenant_id', $tenantId)->where('name', $value);
        if (!empty($data['id'])) {
            $query->where('id', '<>', (int)$data['id']);
        }
        return $query->find() ? '角色名称已存在' : true;
    }

    public function checkMenus($value, $rule, $data)
    {
        if (!is_array($value)) {
            return '权限格式错误';
        }
        $ids = [];
        foreach ($value as $menuId) {
            if (!is_int($menuId) || $menuId <= 0) {
                return '权限菜单不存在';
            }
            $ids[] = $menuId;
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return true;
        }
        if (TenantSystemMenu::where('tenant_id', $this->currentTenantId())
            ->whereIn('id', $ids)->count() !== count($ids)) {
            return '权限菜单不存在';
        }
        return true;
    }

    private function currentTenantId(): int
    {
        $adminInfo = request()->adminInfo ?? null;
        return is_array($adminInfo) ? max(0, (int)($adminInfo['tenant_id'] ?? 0)) : 0;
    }

}
