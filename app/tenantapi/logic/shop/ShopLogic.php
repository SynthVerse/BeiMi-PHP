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

namespace app\tenantapi\logic\shop;

use app\common\logic\BaseLogic;
use app\common\model\dept\TenantDept;


/**
 * 店铺管理逻辑
 * Class ShopLogic
 * @package app\tenantapi\logic\shop
 */
class ShopLogic extends BaseLogic
{

    /**
     * @notes 获取子店铺列表（分页）
     * @param $params
     * @return array
     */
    public static function lists($params): array
    {
        $where = [];
        if (!empty($params['name'])) {
            $where[] = ['name', 'like', '%' . $params['name'] . '%'];
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $where[] = ['status', '=', $params['status']];
        }
        if (!empty($params['leader'])) {
            $where[] = ['leader', 'like', '%' . $params['leader'] . '%'];
        }

        $pageNo = intval($params['page_no'] ?? 1);
        $pageSize = intval($params['page_size'] ?? 20);

        $count = TenantDept::where($where)->count();
        $lists = TenantDept::where($where)
            ->append(['status_desc'])
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->page($pageNo, $pageSize)
            ->select()
            ->toArray();

        return [
            'lists' => $lists,
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }


    /**
     * @notes 获取店铺详情
     * @param $params
     * @return array
     */
    public static function detail($params): array
    {
        return TenantDept::findOrEmpty($params['id'])->toArray();
    }


    /**
     * @notes 添加子店铺
     * @param array $params
     * @return void
     */
    public static function add(array $params): void
    {
        TenantDept::create([
            'pid' => $params['pid'] ?? 0,
            'name' => $params['name'],
            'leader' => $params['leader'] ?? '',
            'mobile' => $params['mobile'] ?? '',
            'status' => $params['status'] ?? 1,
            'sort' => $params['sort'] ?? 0,
        ]);
    }


    /**
     * @notes 编辑店铺信息
     * @param array $params
     * @return bool
     */
    public static function edit(array $params): bool
    {
        try {
            TenantDept::update([
                'name' => $params['name'],
                'leader' => $params['leader'] ?? '',
                'mobile' => $params['mobile'] ?? '',
                'status' => $params['status'] ?? 1,
                'sort' => $params['sort'] ?? 0,
            ], ['id' => $params['id']]);
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 删除店铺（软删除）
     * @param array $params
     * @return void
     */
    public static function delete(array $params): void
    {
        TenantDept::destroy($params['id']);
    }

}
