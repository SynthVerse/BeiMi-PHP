<?php
namespace app\tenantapi\validate\user;


use app\common\model\user\User;
use app\common\validate\BaseValidate;

/**
 * 用户验证
 * Class TenantValidate
 * @package app\tenantapi\validate\user
 */
class UserValidate extends BaseValidate
{

    protected $rule = [
        'id' => 'require|checkUser',
        'field' => 'require|checkField',
        'value' => 'require',
        'tenant_id' => 'require',
        'order_money' => 'require',
        'pay_type' => 'require|in:1,2',
    ];

    protected $message = [
        'id.require' => '请选择用户',
        'field.require' => '请选择操作',
        'value.require' => '请输入内容',
        'tenant_id.require' => '请选择租户标识',
        'order_money' => '订单金额',
        'pay_type' => '支付方式',
    ];


    /**
     * @notes 详情场景
     * @return UserValidate
     * @author 段誉
     * @date 2022/9/22 16:35
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    public function sceneAddInfo()
    {
        return $this->only(['real_name','nickname','mobile','group_id']);
    }

    /**
     * @notes 删除场景
     * @return UserValidate
     * @author likeadmin
     * @date 2025/12/08 09:46
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    /**
     * @notes 用户信息校验
     * @param $value
     * @param $rule
     * @param $data
     * @return bool|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/22 17:03
     */
    public function checkUser($value, $rule, $data)
    {
        $userIds = is_array($value) ? $value : [$value];

        foreach ($userIds as $item) {
            if (!User::find($item)) {
                return '用户不存在！';
            }
        }
        return true;
    }

    /**
     * @notes 校验平台端查询用户信息的情况
     * @param $value
     * @param $rule
     * @param $data
     * @return UserValidate
     * @author yfdong
     * @date 2024/09/04 23:46
     */
    public function sceneManager(){
        return $this->only(['tenant_id']);
    }



    /**
     * @notes 校验是否可更新信息
     * @param $value
     * @param $rule
     * @param $data
     * @return bool|string
     * @author 段誉
     * @date 2022/9/22 16:37
     */
    public function checkField($value, $rule, $data)
    {
        $allowField = ['account', 'mobile', 'real_name','group_id'];

        if (!in_array($value, $allowField)) {
            return '用户信息不允许更新';
        }

        switch ($value) {
            case 'account':
                //验证手机号码是否存在
                $account = User::where([
                    ['id', '<>', $data['id']],
                    ['account', '=', $data['value']]
                ])->findOrEmpty();

                if (!$account->isEmpty()) {
                    return '账号已被使用';
                }
                break;

            case 'mobile':
                if (false == $this->validate($data['value'], 'mobile', $data)) {
                    return '手机号码格式错误';
                }

                //验证手机号码是否存在
                $mobile = User::where([
                    ['id', '<>', $data['id']],
                    ['mobile', '=', $data['value']]
                ])->findOrEmpty();

                if (!$mobile->isEmpty()) {
                    return '手机号码已存在';
                }
                break;
        }
        return true;
    }


    /**
     * @notes 付款场景
     * @author 金毛失望
     * @date 2025/12/22 16:53
     */
    public function scenePays()
    {
        return $this->only(['id','order_money','pay_type']);
    }

}