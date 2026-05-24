<?php

namespace app\platformapi\lists\user;

use app\common\enum\user\UserTerminalEnum;
use app\common\lists\ListsExcelInterface;
use app\common\model\user\User;
use app\platformapi\lists\BaseAdminDataLists;
use think\facade\Db;

class UserLists extends BaseAdminDataLists implements ListsExcelInterface
{
    public function setSearch(): array
    {
        $allowSearch = ['keyword', 'create_time_start', 'create_time_end', 'channel', 'has_store'];
        return array_intersect(array_keys($this->params), $allowSearch);
    }

    public function lists(): array
    {
        $lists = $this->query()
            ->limit($this->limitOffset, $this->limitLength)
            ->field('id,sn,real_name,nickname,account,mobile,avatar,channel,tenant_id,login_time,create_time,is_disable')
            ->order('id desc')
            ->select()
            ->toArray();

        $tenantIds = array_values(array_filter(array_unique(array_map(
            fn($item) => (int)($item['tenant_id'] ?? 0),
            $lists
        ))));
        $tenantMap = $tenantIds
            ? Db::name('tenant')->whereIn('id', $tenantIds)->column('name', 'id')
            : [];

        $userIds = array_column($lists, 'id');
        $authMap = $userIds
            ? Db::name('user_auth')
                ->whereIn('user_id', $userIds)
                ->where('terminal', UserTerminalEnum::WECHAT_MMP)
                ->column('openid', 'user_id')
            : [];

        return array_map(function ($item) use ($tenantMap, $authMap) {
            $tenantId = (int)($item['tenant_id'] ?? 0);
            $item['has_store'] = $tenantId > 0;
            $item['tenant_name'] = (string)($tenantMap[$tenantId] ?? '');
            $item['openid'] = (string)($authMap[(int)$item['id']] ?? '');
            return $item;
        }, $lists);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    public function setFileName(): string
    {
        return '微信用户列表';
    }

    public function setExcelFields(): array
    {
        return [
            'sn' => '用户编号',
            'nickname' => '用户昵称',
            'account' => '账号',
            'mobile' => '手机号码',
            'channel' => '注册来源',
            'tenant_id' => '当前店铺ID',
            'tenant_name' => '当前店铺',
            'create_time' => '注册时间',
        ];
    }

    private function query()
    {
        $params = $this->params;
        if (!isset($params['channel']) || $params['channel'] === '') {
            $params['channel'] = UserTerminalEnum::WECHAT_MMP;
        }

        $search = array_diff(array_unique(array_merge($this->setSearch(), ['channel'])), ['has_store']);

        $query = User::withSearch($search, $params);
        if (isset($params['has_store']) && $params['has_store'] !== '') {
            if ((int)$params['has_store'] === 1) {
                $query->where('tenant_id', '>', 0);
            } else {
                $query->where(function ($query) {
                    $query->where('tenant_id', '=', 0)->whereOrNull('tenant_id');
                });
            }
        }

        return $query;
    }
}
