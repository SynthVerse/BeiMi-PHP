<?php
namespace app\platformapi\lists\tenant;

use app\common\lists\ListsExcelInterface;
use app\common\model\tenant\Tenant;
use app\platformapi\lists\BaseAdminDataLists;
use think\facade\Db;

/**
 * 店铺回收站列表
 * Class TenantRecycleLists
 * @package app\platformapi\lists\tenant
 */
class TenantRecycleLists extends BaseAdminDataLists implements ListsExcelInterface
{
    private const AUTO_PROVISION_NOTE = '微信小程序用户自动创建';

    /**
     * @notes 搜索条件
     * @return array
     */
    public function setSearch(): array
    {
        $allowSearch = ['keyword', 'create_time_start', 'create_time_end'];
        return array_intersect(array_keys($this->params), $allowSearch);
    }

    /**
     * @notes 获取回收站店铺列表
     * @return array
     */
    public function lists(): array
    {
        $field = "id,sn,name,avatar,disable,create_time,expired_time,delete_time,domain_alias,domain_alias_enable,notes,tel";

        $lists = $this->queryRecycleStores()
            ->limit($this->limitOffset, $this->limitLength)
            ->field($field)
            ->order('delete_time desc,id desc')
            ->select()
            ->toArray();

        $userCounts = $this->getUserCounts(array_column($lists, 'id'));
        $domain = TenantLists::getRootDmain(request()->domain());

        return array_map(function ($item) use ($domain, $userCounts) {
            $httpPrefix = TenantLists::checkHttp() ? 'https://' : 'http://';
            $item['default_domain'] = $httpPrefix . $item['sn'] . '.' . $domain . '/admin/';
            $item['domain'] = (int)$item['domain_alias_enable'] === 0 && !empty($item['domain_alias'])
                ? $httpPrefix . $item['domain_alias'] . '/admin/'
                : $item['default_domain'];
            $item['expired_time'] = empty($item['expired_time']) ? '-' : date('Y-m-d', (int)$item['expired_time']);
            $item['delete_time'] = empty($item['delete_time']) ? '-' : date('Y-m-d H:i:s', (int)$item['delete_time']);
            $item['users_count'] = (int)($userCounts[(int)$item['id']] ?? 0);
            return $item;
        }, $lists);
    }

    /**
     * @notes 获取数量
     * @return int
     */
    public function count(): int
    {
        return $this->queryRecycleStores()->count();
    }

    /**
     * @notes 导出文件名
     * @return string
     */
    public function setFileName(): string
    {
        return '店铺回收站列表';
    }

    /**
     * @notes 导出字段
     * @return string[]
     */
    public function setExcelFields(): array
    {
        return [
            'sn' => '店铺编号',
            'name' => '店铺名称',
            'tel' => '联系方式',
            'delete_time' => '放入回收站时间',
        ];
    }

    private function getUserCounts(array $tenantIds): array
    {
        $tenantIds = array_values(array_filter(array_unique(array_map('intval', $tenantIds))));
        if (!$tenantIds) {
            return [];
        }

        try {
            $rows = Db::name('tenant_member')
                ->whereIn('tenant_id', $tenantIds)
                ->where('status', 1)
                ->whereNull('delete_time')
                ->field('tenant_id,COUNT(*) AS user_count')
                ->group('tenant_id')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $rows = Db::name('user')
                ->whereIn('tenant_id', $tenantIds)
                ->whereNull('delete_time')
                ->field('tenant_id,COUNT(*) AS user_count')
                ->group('tenant_id')
                ->select()
                ->toArray();
        }

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['tenant_id']] = (int)$row['user_count'];
        }
        return $counts;
    }

    private function queryRecycleStores()
    {
        return Tenant::onlyTrashed()
            ->withSearch($this->setSearch(), $this->params)
            ->where(function ($query) {
                $query->whereNull('notes')->whereOr('notes', '<>', self::AUTO_PROVISION_NOTE);
            });
    }
}
