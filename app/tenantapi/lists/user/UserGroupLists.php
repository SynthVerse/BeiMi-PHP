<?php
namespace app\tenantapi\lists\user;


use app\common\lists\BaseDataLists;
use app\common\model\user\UserGroup;
use app\common\lists\ListsSearchInterface;


/**
 * UserGroup列表
 * Class UserGroupLists
 * @package app\platform\lists
 */
class UserGroupLists extends BaseDataLists implements ListsSearchInterface
{


    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2025/12/08 09:50
     */
    public function setSearch(): array
    {
        return [
            '=' => ['name', 'sort', 'is_show', 'tenant_id', 'desc'],
        ];
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2025/12/08 09:50
     */
    public function lists(): array
    {
        return UserGroup::where($this->searchWhere)
            ->field(['id', 'name', 'sort', 'is_show', 'tenant_id', 'desc'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
    }


    /**
     * @notes 获取数量
     * @return int
     * @author likeadmin
     * @date 2025/12/08 09:50
     */
    public function count(): int
    {
        return UserGroup::where($this->searchWhere)->count();
    }

}