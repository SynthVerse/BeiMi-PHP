<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Customer;
use app\common\model\jxc\CustomerGroup;
use think\facade\Config;
use think\facade\Db;

class CustomerGroupLogic extends BaseLogic
{
    public static function add(array $params): array|false
    {
        $groupName = trim((string)($params['group_name'] ?? $params['name'] ?? ''));
        if ($groupName === '') {
            self::setError('请输入分组名称');
            return false;
        }

        if (self::existsByName($groupName)) {
            self::setError('该分组已存在');
            return false;
        }

        Db::startTrans();
        try {
            $group = CustomerGroup::create([
                'group_name' => $groupName,
                'customer_count' => 0,
                'sort' => (int)($params['sort'] ?? 0),
            ]);
            Db::commit();
            return self::formatItem($group->toArray());
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function rename(array $params): array|false
    {
        $groupId = (int)($params['id'] ?? 0);
        $oldGroupName = trim((string)($params['old_group_name'] ?? $params['group_name'] ?? ''));
        $nextGroupName = trim((string)($params['new_group_name'] ?? $params['name'] ?? ''));

        if ($groupId <= 0 && $oldGroupName === '') {
            self::setError('分组不存在');
            return false;
        }

        if ($nextGroupName === '') {
            self::setError('请输入分组名称');
            return false;
        }

        $model = $groupId > 0
            ? CustomerGroup::find($groupId)
            : CustomerGroup::where('group_name', $oldGroupName)->find();
        if (!$model) {
            self::setError('分组不存在');
            return false;
        }

        $previousGroupName = (string)$model->group_name;
        if ($previousGroupName !== $nextGroupName && self::existsByName($nextGroupName, (int)$model->id)) {
            self::setError('该分组已存在');
            return false;
        }

        Db::startTrans();
        try {
            $model->save([
                'group_name' => $nextGroupName,
                'sort' => (int)($params['sort'] ?? $model->sort ?? 0),
            ]);
            Db::commit();
            return [
                'id' => (int)$model->id,
                'group_name' => $nextGroupName,
                'previous_group_name' => $previousGroupName,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function delete(array $params): array|false
    {
        $groupName = trim((string)($params['group_name'] ?? $params['name'] ?? ''));
        $groupId = (int)($params['id'] ?? 0);

        $model = null;
        if ($groupId > 0) {
            $model = CustomerGroup::find($groupId);
        }
        if (!$model && $groupName !== '') {
            $model = CustomerGroup::where('group_name', $groupName)->find();
        }

        if (!$model) {
            self::setError('分组不存在');
            return false;
        }

        $groupName = (string)$model->group_name;
        $customerTable = 'customer';
        if (self::tableExists($customerTable) && self::tableHasField($customerTable, 'group_id')) {
            $customerCount = Db::name($customerTable)
                ->where('group_id', (int)$model->id)
                ->count();
            if ($customerCount > 0) {
                self::setError('该分组下仍有关联客户，请先迁移后再删除');
                return false;
            }
        }

        Db::startTrans();
        try {
            $model->delete();
            Db::commit();
            return [
                'id' => (int)$model->id,
                'group_name' => $groupName,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function detail(array $params): array
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $model = CustomerGroup::where('id', (int)$params['id'])
            ->where('tenant_id', $tenantId)
            ->findOrEmpty();
        if ($model->isEmpty()) {
            return [];
        }

        $group = self::formatItem($model->toArray());
        $customers = Customer::where('tenant_id', $tenantId)
            ->where('group_id', (int)$model->id)
            ->where('parent_id', 0)
            ->order(['customer_name' => 'asc', 'id' => 'desc'])
            ->select()
            ->toArray();
        $group['customers'] = CustomerLogic::formatList($customers, true);
        $group['customer_count'] = count($group['customers']);
        return $group;
    }

    public static function formatItem(array $item): array
    {
        $item['group_name'] = (string)($item['group_name'] ?? '');
        $item['name'] = $item['group_name'];
        $item['customer_count'] = (int)($item['customer_count'] ?? 0);
        $item['sort'] = (int)($item['sort'] ?? 0);
        return $item;
    }

    protected static function existsByName(string $groupName, int $ignoreId = 0): bool
    {
        $query = CustomerGroup::where('group_name', $groupName);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        return $query->count() > 0;
    }

    protected static function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $prefix = (string)Config::get('database.connections.mysql.prefix', env('database.prefix', 'la_'));
            $tableName = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix . $table);
            return (bool)Db::query("SHOW TABLES LIKE '{$tableName}'");
        } catch (\Throwable) {
            return false;
        }
    }

    protected static function tableHasField(string $table, string $field): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            return false;
        }

        try {
            $fields = Db::name($table)->getConnection()->getTableInfo(Db::name($table)->getTable(), 'fields');
            return in_array($field, $fields, true);
        } catch (\Throwable) {
            return false;
        }
    }
}
