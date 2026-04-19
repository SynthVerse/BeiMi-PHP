<?php

namespace app\tenantapi\lists\user;


use app\tenantapi\lists\BaseAdminDataLists;
use app\common\model\user\UserMoney;
use app\common\lists\ListsSearchInterface;


/**
 * UserMoney列表
 * Class UserMoneyLists
 * @package app\tenantapi\listsuser
 */
class UserMoneyLists extends BaseAdminDataLists implements ListsSearchInterface
{


    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function setSearch(): array
    {
        return [
            '=' => ['user_id', 'createtime'],
        ];
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function lists(): array
    {
        $list = UserMoney::where($this->searchWhere)->with(["user", "admin"])
            ->field(['id', 'user_id', 'money', 'remarks', 'admin_id','create_time'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
        foreach ($list as $k => $val) {
            $list[$k]["user_name"] = $val["user"]["real_name"];
            $list[$k]["admin_name"] = $val["admin"]["name"];
            unset($list[$k]["user"]);
            unset($list[$k]["admin"]);
        }

        return $list;
    }


    /**
     * @notes 获取数量
     * @return int
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function count(): int
    {
        return UserMoney::where($this->searchWhere)->count();
    }

}