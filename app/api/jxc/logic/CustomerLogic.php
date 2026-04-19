<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Customer;
use app\common\model\jxc\CustomerGroup;
use think\facade\Db;

class CustomerLogic extends BaseLogic
{
    private const DEFAULT_GROUP_NAME = '潜力客户';

    public static function add(array $params): array|false
    {
        $saveData = self::buildSaveData($params);
        if (!self::assertCustomerNameUnique($saveData['customer_name'])) {
            return false;
        }

        $parent = null;
        if ($saveData['parent_id'] > 0) {
            $parent = Customer::findOrEmpty($saveData['parent_id']);
            if ($parent->isEmpty() || (int)$parent->parent_id > 0) {
                self::setError('所属客户不存在');
                return false;
            }
            if ((int)$parent->is_disabled === 1) {
                self::setError('停用客户不可绑定门店，请先启用');
                return false;
            }
            $saveData['group_id'] = (int)$parent->group_id;
            $saveData['is_store'] = 1;
        }

        if ($saveData['group_id'] <= 0) {
            $saveData['group_id'] = self::ensureDefaultGroupId();
        }

        Db::startTrans();
        try {
            $customer = Customer::create($saveData);
            if ($parent) {
                self::refreshChildrenCount((int)$parent->id);
            }
            self::refreshGroupCounts([$saveData['group_id']]);
            Db::commit();
            return self::detail(['id' => (int)$customer->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function edit(array $params): array|false
    {
        $model = Customer::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('客户不存在');
            return false;
        }

        $current = $model->toArray();
        $saveData = self::buildSaveData($params, $current);
        if (!self::assertCustomerNameUnique($saveData['customer_name'], (int)$model->id)) {
            return false;
        }

        $oldParentId = (int)$model->parent_id;
        $oldGroupId = (int)$model->group_id;
        $newParentId = (int)$saveData['parent_id'];

        if ($newParentId > 0) {
            if ($newParentId === (int)$model->id) {
                self::setError('不能将客户绑定到自己名下');
                return false;
            }
            if (self::childrenCount((int)$model->id) > 0) {
                self::setError('当前客户已拥有下属门店，暂不支持再次绑定');
                return false;
            }
            $parent = Customer::findOrEmpty($newParentId);
            if ($parent->isEmpty() || (int)$parent->parent_id > 0) {
                self::setError('所属客户不存在');
                return false;
            }
            $saveData['group_id'] = (int)$parent->group_id;
            $saveData['is_store'] = 1;
        } else {
            $saveData['is_store'] = 0;
            if ($saveData['group_id'] <= 0) {
                $saveData['group_id'] = self::ensureDefaultGroupId();
            }
        }

        Db::startTrans();
        try {
            $model->save($saveData);
            if ($oldParentId !== $newParentId) {
                self::refreshChildrenCount($oldParentId);
                self::refreshChildrenCount($newParentId);
            }
            if ($newParentId === 0 && $oldGroupId !== (int)$saveData['group_id']) {
                Customer::where('parent_id', (int)$model->id)->update(['group_id' => (int)$saveData['group_id']]);
            }
            self::refreshGroupCounts([$oldGroupId, (int)$saveData['group_id']]);
            Db::commit();
            return self::detail(['id' => (int)$model->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function delete(array $params): array|false
    {
        $model = Customer::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('客户不存在');
            return false;
        }

        $customerId = (int)$model->id;
        $parentId = (int)$model->parent_id;
        $groupId = (int)$model->group_id;
        $affectedStoreCount = self::childrenCount($customerId);

        Db::startTrans();
        try {
            if ($affectedStoreCount > 0) {
                Customer::where('parent_id', $customerId)->update([
                    'parent_id' => 0,
                    'is_store' => 0,
                ]);
            }
            $model->delete();
            self::refreshChildrenCount($parentId);
            self::refreshGroupCounts([$groupId]);
            Db::commit();
            return [
                'id' => $customerId,
                'affected_store_count' => $affectedStoreCount,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function detail(array $params): array
    {
        $model = Customer::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            return [];
        }

        return self::formatItem($model->toArray(), true);
    }

    public static function children(array $params): array
    {
        $parentId = (int)$params['parent_id'];
        $children = Customer::where('parent_id', $parentId)
            ->order(['customer_name' => 'asc', 'id' => 'desc'])
            ->select()
            ->toArray();

        return [
            'parent_id' => $parentId,
            'data' => self::formatList($children),
        ];
    }

    public static function summary(array $params): array
    {
        $parentId = (int)$params['parent_id'];
        $target = Customer::findOrEmpty($parentId);
        $children = self::formatList(Customer::where('parent_id', $parentId)->select()->toArray());
        $selfAmount = $target->isEmpty() ? 0 : (float)$target->order_receivable;
        $storeAmount = array_reduce($children, function ($sum, $item) {
            return $sum + (float)($item['order_receivable'] ?? 0);
        }, 0.0);

        return [
            'parent_id' => $parentId,
            'total_amount' => self::money($selfAmount + $storeAmount),
            'self_amount' => self::money($selfAmount),
            'store_amount' => self::money($storeAmount),
            'detail' => array_map(function ($item) {
                return [
                    'id' => (int)$item['id'],
                    'customer_name' => (string)$item['customer_name'],
                    'amount' => self::money($item['order_receivable'] ?? 0),
                    'last_transaction_date' => $item['last_transaction_date'] ?? '',
                    'ratio' => 0,
                ];
            }, $children),
        ];
    }

    public static function bindStore(array $params): array|false
    {
        $parentId = (int)$params['parent_id'];
        $storeId = (int)$params['store_id'];
        $parent = Customer::findOrEmpty($parentId);
        $store = Customer::findOrEmpty($storeId);

        if ($parent->isEmpty() || $store->isEmpty()) {
            self::setError('绑定失败，客户不存在');
            return false;
        }
        if ($parentId === $storeId) {
            self::setError('不能将客户绑定到自己名下');
            return false;
        }
        if ((int)$parent->parent_id > 0) {
            self::setError('门店不可作为所属客户');
            return false;
        }
        if ((int)$store->parent_id > 0) {
            self::setError('该客户已绑定为门店');
            return false;
        }
        if (self::childrenCount($storeId) > 0) {
            self::setError('当前客户已拥有下属门店，暂不支持再次绑定');
            return false;
        }
        if ((int)$parent->is_disabled === 1) {
            self::setError('停用客户不可绑定门店，请先启用');
            return false;
        }
        if ((int)$store->is_disabled === 1) {
            self::setError('停用客户不可绑定为门店，请先启用');
            return false;
        }

        Db::startTrans();
        try {
            $oldGroupId = (int)$store->group_id;
            $store->save([
                'parent_id' => $parentId,
                'is_store' => 1,
                'group_id' => (int)$parent->group_id,
            ]);
            self::refreshChildrenCount($parentId);
            self::refreshGroupCounts([$oldGroupId, (int)$parent->group_id]);
            Db::commit();
            return self::detail(['id' => $storeId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function unbindStore(array $params): array|false
    {
        $storeId = (int)($params['store_id'] ?? $params['id'] ?? 0);
        if ($storeId <= 0) {
            self::setError('门店不存在');
            return false;
        }

        $store = Customer::findOrEmpty($storeId);
        if ($store->isEmpty()) {
            self::setError('门店不存在');
            return false;
        }

        $oldParentId = (int)$store->parent_id;
        Db::startTrans();
        try {
            $store->save([
                'parent_id' => 0,
                'is_store' => 0,
            ]);
            self::refreshChildrenCount($oldParentId);
            Db::commit();
            return self::detail(['id' => $storeId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function assignGroup(array $params): array|false
    {
        $customerId = (int)($params['customer_id'] ?? $params['id'] ?? 0);
        $customer = Customer::findOrEmpty($customerId);
        if ($customer->isEmpty()) {
            self::setError('客户不存在');
            return false;
        }
        if ((int)$customer->parent_id > 0) {
            self::setError('门店不支持直接调整分组，请调整所属客户');
            return false;
        }

        $group = self::resolveGroup($params);
        if (!$group) {
            self::setError(($params['group_id'] ?? 0) || ($params['group_name'] ?? '') ? '目标分组不存在' : '请选择目标分组');
            return false;
        }

        $oldGroupId = (int)$customer->group_id;
        Db::startTrans();
        try {
            $customer->save(['group_id' => (int)$group->id]);
            Customer::where('parent_id', $customerId)->update(['group_id' => (int)$group->id]);
            self::refreshGroupCounts([$oldGroupId, (int)$group->id]);
            Db::commit();
            return self::detail(['id' => $customerId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function setStatus(array $params): array|false
    {
        $model = Customer::findOrEmpty((int)$params['id']);
        if ($model->isEmpty()) {
            self::setError('客户不存在');
            return false;
        }

        $isDisabled = self::normalizeDisabled($params['status'] ?? $params['is_disabled'] ?? 0);
        $cascadeChildren = self::truthy($params['cascade_children'] ?? null)
            || self::truthy($params['cascade_disable_children'] ?? null)
            || self::truthy($params['cascade_enable_children'] ?? null);

        Db::startTrans();
        try {
            $model->save(['is_disabled' => $isDisabled]);
            if ($cascadeChildren && (int)$model->parent_id === 0) {
                Customer::where('parent_id', (int)$model->id)->update(['is_disabled' => $isDisabled]);
            }
            Db::commit();
            return self::detail(['id' => (int)$model->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function paymoney(array $params): array|false
    {
        $customerId = (int)$params['customer_id'];
        $amount = max(0, (float)($params['money'] ?? $params['amount'] ?? 0));
        $model = Customer::findOrEmpty($customerId);
        if ($model->isEmpty()) {
            self::setError('客户不存在');
            return false;
        }
        if ((int)$model->is_disabled === 1) {
            self::setError('停用客户不可付款，请先启用');
            return false;
        }
        if ($amount <= 0) {
            self::setError('请输入付款金额');
            return false;
        }

        Db::startTrans();
        try {
            $model->save([
                'order_receivable' => self::money(max(0, (float)$model->order_receivable - $amount)),
                'order_pay_money' => self::money((float)$model->order_pay_money + $amount),
            ]);
            Db::commit();
            return self::detail(['id' => $customerId]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function salesHistory(array $params): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, (int)($params['pagesize'] ?? 15));

        return [
            'data' => [],
            'current_page' => $page,
            'page' => $page,
            'last_page' => 1,
            'total' => 0,
            'pagesize' => $pageSize,
            'has_more' => false,
        ];
    }

    public static function receivableSummary(array $params): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, (int)($params['pagesize'] ?? 20));
        $query = Customer::where('parent_id', 0);
        $status = $params['status'] ?? 'all';
        if ($status !== '' && $status !== 'all') {
            $query->where('is_disabled', self::normalizeDisabled($status));
        }
        $keyword = trim((string)($params['keyword'] ?? $params['name'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('customer_name', '%' . $keyword . '%');
        }
        $total = $query->count();
        $rows = $query->order(['customer_name' => 'asc', 'id' => 'desc'])
            ->limit(($page - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();
        $customers = self::formatList($rows, true);
        $totalAmount = array_reduce($customers, function ($sum, $item) {
            return $sum + (float)($item['total_receivable'] ?? $item['order_receivable'] ?? 0);
        }, 0.0);
        $storeCount = array_reduce($customers, function ($sum, $item) {
            return $sum + (int)($item['children_count'] ?? 0);
        }, 0);

        return [
            'customers' => $customers,
            'total_amount' => self::money($totalAmount),
            'customer_count' => $total,
            'store_count' => $storeCount,
            'current_page' => $page,
            'page' => $page,
            'last_page' => max(1, (int)ceil($total / $pageSize)),
            'total' => $total,
            'pagesize' => $pageSize,
            'has_more' => $page < max(1, (int)ceil($total / $pageSize)),
        ];
    }

    public static function formatList(array $rows, bool $includeChildren = false): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['group_id'] ?? 0), $rows))));
        $parentIds = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['parent_id'] ?? 0), $rows))));
        $groupMap = self::groupNameMap($groupIds);
        $parentMap = self::parentNameMap($parentIds);

        return array_map(fn($item) => self::formatItem($item, $includeChildren, $groupMap, $parentMap), $rows);
    }

    public static function formatItem(array $item, bool $includeChildren = false, array $groupMap = [], array $parentMap = []): array
    {
        $id = (int)($item['id'] ?? 0);
        $parentId = (int)($item['parent_id'] ?? 0);
        $groupId = (int)($item['group_id'] ?? 0);
        $groupName = $groupMap[$groupId] ?? self::groupName($groupId);
        $childrenRows = $includeChildren && $id > 0
            ? Customer::where('parent_id', $id)->order(['customer_name' => 'asc', 'id' => 'desc'])->select()->toArray()
            : [];
        $children = $includeChildren ? self::formatList($childrenRows, false) : null;
        $childrenCount = count($childrenRows) ?: (int)($item['children_count'] ?? 0);
        $selfReceivable = (float)($item['order_receivable'] ?? 0);
        $childrenReceivable = array_reduce($children ?: [], function ($sum, $child) {
            return $sum + (float)($child['order_receivable'] ?? 0);
        }, 0.0);
        $isDisabled = (int)($item['is_disabled'] ?? 0);

        return [
            'id' => $id,
            'customer_name' => (string)($item['customer_name'] ?? ''),
            'contact' => (string)($item['contact'] ?? ''),
            'phone' => (string)($item['phone'] ?? ''),
            'customer_mobile' => (string)($item['phone'] ?? ''),
            'address' => (string)($item['address'] ?? ''),
            'remark' => (string)($item['remark'] ?? ''),
            'customer_remark' => (string)($item['remark'] ?? ''),
            'group_id' => $groupId,
            'group_name' => $groupName !== '' ? $groupName : '未分组',
            'parent_id' => $parentId > 0 ? $parentId : null,
            'parent_name' => $parentMap[$parentId] ?? '',
            'is_store' => $parentId > 0 ? 1 : 0,
            'children_count' => $childrenCount,
            'children' => $includeChildren ? $children : null,
            'stores' => $includeChildren ? $children : null,
            'is_disabled' => $isDisabled,
            'status' => $isDisabled === 1 ? 'disabled' : 'enabled',
            'status_text' => $isDisabled === 1 ? '停用' : '启用',
            'is_enabled' => $isDisabled === 1 ? 0 : 1,
            'customer_type' => $parentId > 0 ? 'store' : 'customer',
            'order_receivable' => self::money($selfReceivable),
            'order_money' => self::money($item['order_money'] ?? 0),
            'order_pay_money' => self::money($item['order_pay_money'] ?? 0),
            'total_receivable' => self::money($selfReceivable + $childrenReceivable),
            'last_transaction_date' => self::dateText($item['update_time'] ?? $item['create_time'] ?? ''),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];
    }

    public static function groupedCustomers(array $groups, array $params): array
    {
        $status = $params['status'] ?? 'all';
        $keyword = trim((string)($params['keyword'] ?? $params['name'] ?? $params['group_name'] ?? ''));
        $groupIds = array_map(fn($group) => (int)($group['id'] ?? 0), $groups);
        $customers = [];
        if (!empty($groupIds)) {
            $query = Customer::where('parent_id', 0)->whereIn('group_id', $groupIds);
            if ($status !== '' && $status !== 'all') {
                $query->where('is_disabled', self::normalizeDisabled($status));
            }
            $customers = self::formatList($query->order(['customer_name' => 'asc', 'id' => 'desc'])->select()->toArray(), true);
        }

        $customersByGroup = [];
        foreach ($customers as $customer) {
            $searchable = strtolower($customer['customer_name'] . ' ' . $customer['group_name'] . ' ' . implode(' ', array_map(fn($store) => $store['customer_name'] ?? '', $customer['stores'] ?? [])));
            if ($keyword !== '' && !str_contains($searchable, strtolower($keyword))) {
                continue;
            }
            $customersByGroup[(int)$customer['group_id']][] = $customer;
        }

        $result = [];
        foreach ($groups as $group) {
            $groupId = (int)($group['id'] ?? 0);
            $groupName = (string)($group['group_name'] ?? '');
            $items = $customersByGroup[$groupId] ?? [];
            if ($keyword !== '' && !str_contains(strtolower($groupName), strtolower($keyword)) && empty($items)) {
                continue;
            }
            $group['customers'] = $items;
            $group['customer_count'] = count($items);
            $result[] = $group;
        }

        return $result;
    }

    public static function normalizeDisabled(mixed $status): int
    {
        if ($status === 1 || $status === '1' || $status === 'disabled' || $status === 'inactive' || $status === false || $status === 'false') {
            return 1;
        }
        return 0;
    }

    protected static function buildSaveData(array $params, array $current = []): array
    {
        $group = self::resolveGroup($params);
        $status = $params['status'] ?? ($params['is_disabled'] ?? ($current['is_disabled'] ?? 0));

        return [
            'customer_name' => trim((string)($params['customer_name'] ?? ($current['customer_name'] ?? ''))),
            'contact' => trim((string)($params['contact'] ?? ($current['contact'] ?? ''))),
            'phone' => trim((string)($params['phone'] ?? $params['customer_mobile'] ?? ($current['phone'] ?? ''))),
            'address' => trim((string)($params['address'] ?? ($current['address'] ?? ''))),
            'remark' => trim((string)($params['remark'] ?? ($current['remark'] ?? ''))),
            'group_id' => $group ? (int)$group->id : (int)($current['group_id'] ?? 0),
            'parent_id' => (int)($params['parent_id'] ?? ($current['parent_id'] ?? 0)),
            'is_store' => (int)($current['is_store'] ?? 0),
            'is_disabled' => self::normalizeDisabled($status),
            'order_receivable' => self::money($current['order_receivable'] ?? 0),
            'order_money' => self::money($current['order_money'] ?? 0),
            'order_pay_money' => self::money($current['order_pay_money'] ?? 0),
        ];
    }

    protected static function assertCustomerNameUnique(string $name, int $ignoreId = 0): bool
    {
        if ($name === '') {
            self::setError('请输入客户名称');
            return false;
        }
        $query = Customer::where('customer_name', $name);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('客户已存在');
            return false;
        }
        return true;
    }

    protected static function resolveGroup(array $params): ?CustomerGroup
    {
        $groupId = (int)($params['group_id'] ?? 0);
        $groupName = trim((string)($params['group_name'] ?? $params['name'] ?? ''));
        if ($groupId > 0) {
            $group = CustomerGroup::findOrEmpty($groupId);
            return $group->isEmpty() ? null : $group;
        }
        if ($groupName !== '') {
            $group = CustomerGroup::where('group_name', $groupName)->findOrEmpty();
            return $group->isEmpty() ? null : $group;
        }
        return null;
    }

    protected static function ensureDefaultGroupId(): int
    {
        $group = CustomerGroup::where('group_name', self::DEFAULT_GROUP_NAME)->findOrEmpty();
        if (!$group->isEmpty()) {
            return (int)$group->id;
        }
        $group = CustomerGroup::create([
            'group_name' => self::DEFAULT_GROUP_NAME,
            'customer_count' => 0,
            'sort' => 0,
        ]);
        return (int)$group->id;
    }

    protected static function childrenCount(int $customerId): int
    {
        if ($customerId <= 0) {
            return 0;
        }
        return (int)Customer::where('parent_id', $customerId)->count();
    }

    protected static function refreshChildrenCount(int $customerId): void
    {
        if ($customerId <= 0) {
            return;
        }
        Customer::where('id', $customerId)->update(['children_count' => self::childrenCount($customerId)]);
    }

    protected static function refreshGroupCounts(array $groupIds): void
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        foreach ($groupIds as $groupId) {
            $count = Customer::where('group_id', $groupId)->where('parent_id', 0)->count();
            CustomerGroup::where('id', $groupId)->update(['customer_count' => $count]);
        }
    }

    protected static function groupNameMap(array $groupIds): array
    {
        if (empty($groupIds)) {
            return [];
        }
        return CustomerGroup::whereIn('id', $groupIds)->column('group_name', 'id');
    }

    protected static function parentNameMap(array $parentIds): array
    {
        if (empty($parentIds)) {
            return [];
        }
        return Customer::whereIn('id', $parentIds)->column('customer_name', 'id');
    }

    protected static function groupName(int $groupId): string
    {
        if ($groupId <= 0) {
            return '未分组';
        }
        return (string)(CustomerGroup::where('id', $groupId)->value('group_name') ?: '未分组');
    }

    protected static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    protected static function money(mixed $value): string
    {
        return number_format(max(0, (float)$value), 2, '.', '');
    }

    protected static function dateText(mixed $value): string
    {
        if (is_numeric($value)) {
            return date('Y-m-d', (int)$value);
        }
        $text = (string)$value;
        return strlen($text) >= 10 ? substr($text, 0, 10) : $text;
    }
}
