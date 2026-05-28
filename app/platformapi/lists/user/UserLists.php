<?php

namespace app\platformapi\lists\user;

use app\common\enum\user\UserTerminalEnum;
use app\common\lists\ListsExcelInterface;
use app\common\model\user\User;
use app\platformapi\lists\BaseAdminDataLists;
use think\facade\Db;

class UserLists extends BaseAdminDataLists implements ListsExcelInterface
{
    private const AUTO_PROVISION_NOTE = '微信小程序用户自动创建';

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

        $userIds = array_column($lists, 'id');
        $storeMap = $this->getCreatedStoreMap($userIds, $lists);
        $authMap = $userIds
            ? Db::name('user_auth')
                ->whereIn('user_id', $userIds)
                ->where('terminal', UserTerminalEnum::WECHAT_MMP)
                ->column('openid', 'user_id')
            : [];

        return array_map(function ($item) use ($storeMap, $authMap) {
            $userId = (int)$item['id'];
            $store = $storeMap[$userId] ?? [];
            $hasStore = !empty($store);
            $isDefaultNickname = $this->isDefaultNickname((string)($item['nickname'] ?? ''), (string)($item['sn'] ?? ''));

            $item['display_nickname'] = $isDefaultNickname ? '未授权昵称' : (string)($item['nickname'] ?? '');
            $item['nickname_is_default'] = $isDefaultNickname;
            $item['has_store'] = $hasStore;
            $item['store_status'] = $hasStore ? 'created' : 'not_created';
            $item['store_status_text'] = $hasStore ? '已创建' : '未创建';
            $item['store_id'] = $hasStore ? (int)$store['id'] : 0;
            $item['tenant_name'] = $hasStore ? (string)$store['name'] : '';
            $item['openid_masked'] = $this->maskOpenid((string)($authMap[$userId] ?? ''));
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
            'display_nickname' => '用户昵称',
            'account' => '账号',
            'mobile' => '手机号码',
            'channel' => '注册来源',
            'store_status_text' => '店铺状态',
            'store_id' => '店铺ID',
            'tenant_name' => '关联店铺',
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
                $query->whereIn('id', function ($query) {
                    $this->buildCreatedStoreUserSubQuery($query);
                });
            } else {
                $query->whereNotIn('id', function ($query) {
                    $this->buildCreatedStoreUserSubQuery($query);
                });
            }
        }

        return $query;
    }

    private function buildCreatedStoreUserSubQuery($query): void
    {
        $query->name('tenant_member')
            ->alias('m')
            ->join('tenant t', 't.id = m.tenant_id')
            ->where('m.status', 1)
            ->whereNull('m.delete_time')
            ->where('t.disable', 0)
            ->whereNull('t.delete_time')
            ->whereRaw('(`t`.`notes` IS NULL OR `t`.`notes` <> ?)', [self::AUTO_PROVISION_NOTE])
            ->distinct(true)
            ->field('m.user_id');
    }

    private function getCreatedStoreMap(array $userIds, array $lists): array
    {
        if (empty($userIds)) {
            return [];
        }

        $storeMap = [];
        $rows = Db::name('tenant_member')
            ->alias('m')
            ->join('tenant t', 't.id = m.tenant_id')
            ->whereIn('m.user_id', $userIds)
            ->where('m.status', 1)
            ->whereNull('m.delete_time')
            ->where('t.disable', 0)
            ->whereNull('t.delete_time')
            ->whereRaw('(`t`.`notes` IS NULL OR `t`.`notes` <> ?)', [self::AUTO_PROVISION_NOTE])
            ->field('m.user_id,t.id,t.name')
            ->order('m.id desc')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            if (!isset($storeMap[$userId])) {
                $storeMap[$userId] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                ];
            }
        }

        $fallbackTenantIds = [];
        foreach ($lists as $item) {
            $userId = (int)$item['id'];
            $tenantId = (int)($item['tenant_id'] ?? 0);
            if ($tenantId > 0 && !isset($storeMap[$userId])) {
                $fallbackTenantIds[$tenantId] = $tenantId;
            }
        }

        if (!empty($fallbackTenantIds)) {
            $tenantMap = Db::name('tenant')
                ->whereIn('id', array_values($fallbackTenantIds))
                ->where('disable', 0)
                ->whereNull('delete_time')
                ->whereRaw('(`notes` IS NULL OR `notes` <> ?)', [self::AUTO_PROVISION_NOTE])
                ->column('name', 'id');

            foreach ($lists as $item) {
                $userId = (int)$item['id'];
                $tenantId = (int)($item['tenant_id'] ?? 0);
                if ($tenantId > 0 && !isset($storeMap[$userId]) && isset($tenantMap[$tenantId])) {
                    $storeMap[$userId] = [
                        'id' => $tenantId,
                        'name' => (string)$tenantMap[$tenantId],
                    ];
                }
            }
        }

        return $storeMap;
    }

    private function isDefaultNickname(string $nickname, string $sn): bool
    {
        $nickname = trim($nickname);
        if ($nickname === '') {
            return true;
        }
        if ($sn !== '' && $nickname === '用户' . $sn) {
            return true;
        }
        return preg_match('/^用户\d+$/u', $nickname) === 1;
    }

    private function maskOpenid(string $openid): string
    {
        if ($openid === '') {
            return '';
        }
        if (strlen($openid) <= 10) {
            return '***';
        }
        return substr($openid, 0, 4) . '...' . substr($openid, -4);
    }
}
