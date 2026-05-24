<?php
namespace app\platformapi\lists\tenant;

use app\common\lists\ListsExcelInterface;
use app\common\model\tenant\Tenant;
use app\platformapi\lists\BaseAdminDataLists;
use think\facade\Db;


/**
 * 用户列表
 * Class TenantLists
 * @package app\tenantapi\lists\user
 */
class TenantLists extends BaseAdminDataLists implements ListsExcelInterface
{

    /**
     * @notes 搜索条件
     * @return array
     * @author 段誉
     * @date 2022/9/22 15:50
     */
    public function setSearch(): array
    {
        $allowSearch = ['keyword', 'create_time_start', 'create_time_end'];
        return array_intersect(array_keys($this->params), $allowSearch);
    }


    /**
     * @notes 获取用户列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/22 15:50
     */
    public function lists(): array
    {
        $field = "id,sn,name,avatar,disable,create_time,expired_time,domain_alias,domain_alias_enable,notes,tel";

        $lists = Tenant::withSearch($this->setSearch(), $this->params)
            ->limit($this->limitOffset, $this->limitLength)
            ->field($field)
            ->order('id desc')
            ->select()->toArray();

        $userCounts = $this->getUserCounts(array_column($lists, 'id'));
        $domain = self::getRootDmain(request()->domain());

        // 遍历结果，添加 link 字段
        return array_map(function ($item) use ($domain, $userCounts) {
            // 拼接租户的链接 http://[sn].likeadmin-saas.localhost/admin/
            $http_prefix = self::checkHttp() ? 'https://' : 'http://';
            $item['default_domain'] = $http_prefix . $item['sn'] . '.' . $domain . '/admin/';

            if ($item['domain_alias_enable'] === 0) {
                $item['domain'] = $http_prefix . $item['domain_alias'] . '/admin/';
            } else {
                $item['domain'] = $item['default_domain'];
            }

            $item['expired_time'] = date("Y-m-d",$item['expired_time']);
            $item['users_count'] = (int)($userCounts[(int)$item['id']] ?? 0);
            return $item;
        }, $lists);
    }


    /**
     * @notes 获取数量
     * @return int
     * @author 段誉
     * @date 2022/9/22 15:51
     */
    public function count(): int
    {
        return Tenant::withSearch($this->setSearch(), $this->params)->count();
    }


    /**
     * @notes 导出文件名
     * @return string
     * @author 段誉
     * @date 2022/11/24 16:17
     */
    public function setFileName(): string
    {
        return '租户列表';
    }


    /**
     * @notes 导出字段
     * @return string[]
     * @author 段誉
     * @date 2022/11/24 16:17
     */
    public function setExcelFields(): array
    {
        return [
            'sn' => '租户编号',
            'name' => '租户昵称',
            'disable' => '租户状态',
            'create_time' => '注册时间',
        ];
    }

    /**
     * @notes 检查是否为https
     * @return bool
     * @author JXDN
     * @date 2024/09/11 14:39
     */
    public static function checkHttp()
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @notes 获取根域名
     * @param $url
     * @return array|int|string|null
     * @author JXDN
     * @date 2024/09/11 14:49
     */
    public static function getRootDmain($url)
    {
        // 解析 URL 获取主机名
        $host = parse_url($url, PHP_URL_HOST);

        // 如果主机名为空，返回 null
        if (!$host) {
            return null;
        }

        // 拆分域名
        $parts = explode('.', $host);

        // 检查域名的级数
        $numParts = count($parts);

        // 针对常见的两级或三级域名进行处理
        if ($numParts >= 2) {
            // 获取最后两部分，例如 qq.com 或 co.uk
            $rootDomain = $parts[$numParts - 2] . '.' . $parts[$numParts - 1];
            return $rootDomain;
        }

        return $host; // 当域名本身就是根域名时，直接返回
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
}
