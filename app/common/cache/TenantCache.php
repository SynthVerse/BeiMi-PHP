<?php

namespace app\common\cache;

use app\common\model\tenant\Tenant;

/**
 * 租户信息缓存
 * Class AdminTokenCache
 * @package app\common\cache
 */
class TenantCache extends BaseCache
{

    private $prefix = 'tenant_info_';


    /**
     * @notes 通过token获取缓存管理员信息
     * @param $token
     * @return false|mixed
     * @author 令狐冲
     * @date 2021/6/30 16:57
     */
    public function getTenantInfo($id)
    {
        //直接从缓存获取
        $adminInfo = $this->get($this->prefix . $id);
        if ($adminInfo) {
            return $adminInfo;
        }

        //从数据获取信息被设置缓存(可能后台清除缓存）
        $info = Tenant::where(['id' => $id])->find();
        if ($info) {
            return $info;
        }

        return [];
    }

}