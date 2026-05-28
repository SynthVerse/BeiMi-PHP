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
namespace app\platformapi\validate\tenant;


use app\common\model\tenant\Tenant;
use app\common\validate\BaseValidate;

/**
 * 用户验证
 * Class TenantValidate
 * @package app\platformapi\validate\user
 */
class TenantValidate extends BaseValidate
{
    private const AUTO_PROVISION_NOTE = '微信小程序用户自动创建';

    protected $rule = [
        'id'   => 'require|checkUser',
        'name' => 'require',
        'sn' => 'checkHostName',
        'host_name' => 'checkHostName',
        'expired_time' => 'require|checkExpiredTime',
        'domain_alias' => 'checkDomainAlias',
        'notes' => 'checkNotes',
    ];

    protected $message = [
        'id.require'           => '请选择店铺',
        'name.require'         => '请输入店铺名称',
        'expired_time.require' => '请选择有效期',
    ];


    /**
     * @notes 详情场景
     * @return TenantValidate
     * @author 段誉
     * @date 2022/9/22 16:35
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 租户信息校验
     * @param $value
     * @param $rule
     * @param $data
     * @return bool|string
     * @author 段誉
     * @date 2022/9/22 17:03
     */
    public function checkUser($value, $rule, $data)
    {
        $userIds = Tenant::findOrEmpty($value);
        if ($userIds->isEmpty()) {
            return '店铺不存在';
        }
        if (($userIds['notes'] ?? '') === self::AUTO_PROVISION_NOTE) {
            return '店铺不存在';
        }
        return true;
    }

    /**
     * @notes 回收站店铺校验
     * @param $value
     * @param $rule
     * @param $data
     * @return bool|string
     */
    public function checkTrashedStore($value, $rule, $data)
    {
        $tenant = Tenant::onlyTrashed()->where('id', $value)->findOrEmpty();
        if ($tenant->isEmpty()) {
            return '回收站店铺不存在';
        }
        if (($tenant['notes'] ?? '') === self::AUTO_PROVISION_NOTE) {
            return '回收站店铺不存在';
        }
        return true;
    }

    /**
     * @notes 域名校验
     * @param $value
     * @param $rule
     * @param $data
     * @return string|true
     * @author JXDN
     * @date 2024/09/11 15:30
     */
    public function checkDomainAlias($value, $rule, $data)
    {
        $value = $this->formatDomainAlias($value);
        if ((int)($data['domain_alias_enable'] ?? 1) !== 0) {
            return true;
        }
        if ($value === '') {
            return '请输入域名别名';
        }
        $tenant = Tenant::where(['domain_alias' => $value])->findOrEmpty();
        if (!$tenant->isEmpty()) {
            return '域名别名已存在';
        }
        return true;
    }

    /**
     * @notes 域名校验
     * @param $value
     * @param $rule
     * @param $data
     * @return string|true
     * @author JXDN
     * @date 2024/09/11 15:30
     */
    public function checkDomainAliasEdit($value, $rule, $data)
    {
        $value = $this->formatDomainAlias($value);
        if ((int)($data['domain_alias_enable'] ?? 1) !== 0) {
            return true;
        }
        if ($value === '') {
            return '请输入域名别名';
        }
        $tenant = Tenant::where('domain_alias', $value)
            ->where('id', '<>', $data['id']) // 排除当前租户
            ->findOrEmpty();
        if (!$tenant->isEmpty()) {
            return '域名别名已存在';
        }
        return true;
    }

    public function checkHostName($value, $rule, $data)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return true;
        }
        if (!preg_match('/^[a-z0-9]{3,32}$/', $value)) {
            return '店铺编号仅支持3-32位小写字母或数字';
        }
        return true;
    }

    public function checkExpiredTime($value, $rule, $data)
    {
        if (strtotime((string)$value) === false) {
            return '有效期格式错误';
        }
        return true;
    }

    public function checkNotes($value, $rule, $data)
    {
        if (trim((string)$value) === self::AUTO_PROVISION_NOTE) {
            return '该备注为系统保留字，请更换';
        }
        return true;
    }


    /**
     * @notes 添加场景
     * @return TenantValidate
     * @author 段誉
     * @date 2022/5/25 18:16
     */
    public function sceneAdd()
    {
        return $this->remove('id', true);
    }

    /**
     * @notes 编辑场景
     * @return TenantValidate
     * @author JXDN
     * @date 2024/09/11 15:31
     */
    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'expired_time', 'notes'])
            ->append('domain_alias', 'checkDomainAliasEdit');
    }

    /**
     * @notes 删除场景
     * @return TenantValidate
     * @author 段誉
     * @date 2022/5/25 18:16
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    /**
     * @notes 恢复场景
     * @return TenantValidate
     */
    public function sceneRestore()
    {
        return $this->only(['id'])
            ->remove('id', 'checkUser')
            ->append('id', 'checkTrashedStore');
    }

    private function formatDomainAlias($value): string
    {
        return preg_replace('/^https?:\/\//i', '', rtrim(trim((string)$value), '/'));
    }
}
