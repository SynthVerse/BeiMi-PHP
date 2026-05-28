<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Customer;
use app\common\service\jxc\StoreMembershipService;
use app\common\service\jxc\TenantRelationService;
use think\facade\Db;

class StoreLogic extends BaseLogic
{
    /**
     * 获取店铺信息
     * 基于当前租户的主客户信息（或指定的 store_id）
     */
    public static function getStoreInfo(array $params): array
    {
        $userId = (int)(request()->adminInfo['user_id'] ?? request()->userId ?? 0);
        $tenantId = (int)(request()->tenantId ?? 0);
        $fromUserToken = (bool)(request()->jxcFromUserToken ?? false);

        if (!$fromUserToken && $tenantId > 0) {
            $tenant = Db::name('tenant')
                ->where('id', $tenantId)
                ->whereNull('delete_time')
                ->find();
            return $tenant ? self::formatTenantStore((array)$tenant) : [];
        }

        if ($userId > 0) {
            return StoreMembershipService::currentTenant($userId, $tenantId);
        }

        // 如果传了 store_id，查指定店铺
        $storeId = (int)($params['store_id'] ?? $params['id'] ?? 0);
        if ($storeId > 0) {
            $store = Customer::where('id', $storeId)->where('is_store', 1)->findOrEmpty();
        } else {
            // 默认获取当前租户下第一个店铺
            $store = Customer::where('is_store', 1)->findOrEmpty();
        }

        if ($store->isEmpty()) {
            return [];
        }

        return self::formatStore($store->toArray());
    }

    /**
     * 更新店铺设置
     */
    public static function setStore(array $params): array|false
    {
        $userId = (int)(request()->adminInfo['user_id'] ?? request()->userId ?? 0);
        $tenantId = (int)(request()->tenantId ?? 0);
        $fromUserToken = (bool)(request()->jxcFromUserToken ?? false);

        if (!$fromUserToken && $tenantId > 0) {
            $name = trim((string)($params['store_name'] ?? $params['tenant_name'] ?? $params['name'] ?? ''));
            $updateData = [];
            if ($name !== '') $updateData['name'] = $name;
            if (isset($params['avatar'])) $updateData['avatar'] = (string)$params['avatar'];
            if (isset($params['tel'])) $updateData['tel'] = (string)$params['tel'];
            if (isset($params['phone'])) $updateData['tel'] = (string)$params['phone'];
            if (isset($params['notes'])) $updateData['notes'] = (string)$params['notes'];
            if (isset($params['remark'])) $updateData['notes'] = (string)$params['remark'];

            if (empty($updateData)) {
                self::setError('无更新内容');
                return false;
            }

            $updateData['update_time'] = time();
            Db::name('tenant')->where('id', $tenantId)->update($updateData);
            $tenant = Db::name('tenant')->where('id', $tenantId)->whereNull('delete_time')->find();
            return $tenant ? self::formatTenantStore((array)$tenant) : [];
        }

        if ($userId > 0 && $tenantId > 0) {
            if (!StoreMembershipService::requireCurrentMembership($userId, $tenantId)) {
                self::setError('无权访问该店铺');
                return false;
            }

            $name = trim((string)($params['store_name'] ?? $params['tenant_name'] ?? $params['name'] ?? ''));
            $updateData = [];
            if ($name !== '') $updateData['name'] = $name;
            if (isset($params['avatar'])) $updateData['avatar'] = (string)$params['avatar'];
            if (isset($params['tel'])) $updateData['tel'] = (string)$params['tel'];
            if (isset($params['phone'])) $updateData['tel'] = (string)$params['phone'];
            if (isset($params['notes'])) $updateData['notes'] = (string)$params['notes'];
            if (isset($params['remark'])) $updateData['notes'] = (string)$params['remark'];

            if (empty($updateData)) {
                self::setError('无更新内容');
                return false;
            }
            $updateData['update_time'] = time();
            Db::name('tenant')->where('id', $tenantId)->update($updateData);
            return StoreMembershipService::currentTenant($userId, $tenantId);
        }

        $updateData = [];
        if (isset($params['store_name'])) $updateData['customer_name'] = trim((string)$params['store_name']);
        if (isset($params['customer_name'])) $updateData['customer_name'] = trim((string)$params['customer_name']);
        if (isset($params['name'])) $updateData['customer_name'] = trim((string)$params['name']);
        if (isset($params['contact'])) $updateData['contact'] = trim((string)$params['contact']);
        if (isset($params['phone'])) $updateData['phone'] = trim((string)$params['phone']);
        if (isset($params['address'])) $updateData['address'] = trim((string)$params['address']);
        if (isset($params['remark'])) $updateData['remark'] = trim((string)$params['remark']);

        $settings = self::extractSettings($params);
        if (empty($updateData) && !empty($settings)) {
            return ['settings' => $settings];
        }
        if (empty($updateData)) {
            self::setError('无更新内容');
            return false;
        }

        $storeId = (int)($params['id'] ?? $params['store_id'] ?? 0);
        if ($storeId > 0) {
            $store = Customer::where('id', $storeId)->where('is_store', 1)->findOrEmpty();
        } else {
            $store = Customer::where('is_store', 1)->findOrEmpty();
        }
        if ($store->isEmpty()) {
            self::setError('店铺不存在');
            return false;
        }

        $store->save($updateData);
        $result = self::formatStore($store->toArray());
        if (!empty($settings)) {
            $result['settings'] = $settings;
        }
        return $result;
    }

    /**
     * 创建新店铺（在指定主客户下创建门店）
     */
    public static function createStore(array $params): array|false
    {
        $userId = (int)(request()->adminInfo['user_id'] ?? request()->userId ?? 0);
        if ($userId > 0) {
            try {
                return StoreMembershipService::createStore($userId, $params, request()->header('token'));
            } catch (\Throwable $e) {
                self::setError($e->getMessage());
                return false;
            }
        }

        $parentId = (int)($params['parent_id'] ?? $params['customer_id'] ?? 0);
        $name = trim((string)($params['store_name'] ?? $params['customer_name'] ?? $params['name'] ?? ''));

        if (empty($name)) {
            self::setError('店铺名称不能为空');
            return false;
        }

        // 如果指定了主客户，验证其存在
        if ($parentId > 0) {
            $parent = Customer::findOrEmpty($parentId);
            if ($parent->isEmpty()) {
                self::setError('主客户不存在');
                return false;
            }
        }

        $store = Customer::create([
            'tenant_id' => (int)(request()->tenantId ?? 0),
            'customer_name' => $name,
            'contact' => trim((string)($params['contact'] ?? (isset($params['store_name']) ? ($params['name'] ?? '') : ''))),
            'phone' => trim((string)($params['phone'] ?? '')),
            'address' => trim((string)($params['address'] ?? '')),
            'remark' => trim((string)($params['remark'] ?? '')),
            'parent_id' => $parentId,
            'is_store' => 1,
            'group_id' => $parentId > 0 ? (int)($parent->group_id ?? 0) : 0,
        ]);

        // 更新主客户的 children_count
        if ($parentId > 0) {
            $childrenCount = Customer::where('parent_id', $parentId)->where('is_store', 1)->count();
            Customer::where('id', $parentId)->update(['children_count' => $childrenCount]);
        }

        return self::formatStore($store->toArray());
    }

    public static function listStores(): array
    {
        $userId = (int)(request()->adminInfo['user_id'] ?? request()->userId ?? 0);
        $tenantId = (int)(request()->tenantId ?? 0);
        $fromUserToken = (bool)(request()->jxcFromUserToken ?? false);

        if (!$fromUserToken && $tenantId > 0) {
            $tenant = Db::name('tenant')
                ->where('id', $tenantId)
                ->whereNull('delete_time')
                ->find();
            return $tenant ? [self::formatTenantStore((array)$tenant)] : [];
        }

        return StoreMembershipService::listTenants($userId);
    }

    public static function status(): array
    {
        $userId = (int)(request()->adminInfo['user_id'] ?? request()->userId ?? 0);
        $tenantId = (int)(request()->tenantId ?? 0);
        $fromUserToken = (bool)(request()->jxcFromUserToken ?? false);

        if (!$fromUserToken && $tenantId > 0) {
            $tenant = Db::name('tenant')
                ->where('id', $tenantId)
                ->whereNull('delete_time')
                ->find();
            $currentStore = $tenant ? self::formatTenantStore((array)$tenant) : null;

            return self::formatStoreStatus([
                'has_store' => !empty($currentStore),
                'needs_store_setup' => empty($currentStore),
                'needs_store_switch' => false,
                'store_count' => !empty($currentStore) ? 1 : 0,
                'current_store_id' => $currentStore['id'] ?? 0,
                'current_store' => $currentStore,
                'actions' => [
                    'create' => false,
                    'join' => false,
                    'switch' => false,
                ],
            ]);
        }

        $stores = StoreMembershipService::listTenants($userId);
        $currentStore = null;
        if ($tenantId > 0) {
            $currentStore = StoreMembershipService::currentTenant($userId, $tenantId);
            if (empty($currentStore)) {
                $currentStore = null;
            }
        }

        $storeCount = count($stores);
        $hasStore = $storeCount > 0 || !empty($currentStore);
        $needsStoreSetup = !$hasStore;
        $needsStoreSwitch = $hasStore && empty($currentStore);
        $canCreateStore = !StoreMembershipService::hasCreatedStore($userId);

        return self::formatStoreStatus([
            'has_store' => $hasStore,
            'needs_store_setup' => $needsStoreSetup,
            'needs_store_switch' => $needsStoreSwitch,
            'store_count' => $storeCount,
            'current_store_id' => (int)($currentStore['id'] ?? $currentStore['tenant_id'] ?? 0),
            'current_store' => $currentStore,
            'actions' => [
                'create' => $canCreateStore,
                'join' => true,
                'switch' => $needsStoreSwitch || $storeCount > 1,
            ],
        ]);
    }

    public static function switchStore(array $params): array|false
    {
        try {
            $userId = (int)(request()->adminInfo['user_id'] ?? request()->userId ?? 0);
            $tenantId = (int)($params['tenant_id'] ?? $params['store_id'] ?? $params['id'] ?? 0);
            return StoreMembershipService::switchTenant($userId, $tenantId, request()->header('token'));
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function joinStore(array $params): array|false
    {
        try {
            $userId = (int)(request()->adminInfo['user_id'] ?? request()->userId ?? 0);
            $inviteCode = (string)($params['invite_code'] ?? $params['code'] ?? '');
            return StoreMembershipService::joinByInviteCode($userId, $inviteCode, request()->header('token'));
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function inviteCode(): array|false
    {
        try {
            $userId = (int)(request()->adminInfo['user_id'] ?? request()->userId ?? 0);
            $tenantId = (int)(request()->tenantId ?? 0);
            return StoreMembershipService::memberInvite($userId, $tenantId);
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function acceptMemberInvite(array $params): array|false
    {
        return self::joinStore($params);
    }

    public static function hierarchy(): array|false
    {
        try {
            return TenantRelationService::summary(self::currentUserId(), self::currentTenantId());
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function hierarchyChildren(): array|false
    {
        try {
            return TenantRelationService::children(self::currentUserId(), self::currentTenantId());
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function hierarchyTree(): array|false
    {
        try {
            return TenantRelationService::tree(self::currentUserId(), self::currentTenantId());
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function hierarchyInvitePreview(array $params): array|false
    {
        try {
            $code = (string)($params['invite_code'] ?? $params['code'] ?? '');
            return TenantRelationService::previewInvite(self::currentUserId(), self::currentTenantId(), $code);
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function createHierarchyInvite(array $params): array|false
    {
        try {
            return TenantRelationService::createInvite(self::currentUserId(), self::currentTenantId(), $params);
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function acceptHierarchyInvite(array $params): array|false
    {
        try {
            $code = (string)($params['invite_code'] ?? $params['code'] ?? '');
            return TenantRelationService::acceptInvite(self::currentUserId(), self::currentTenantId(), $code);
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function unbindHierarchy(array $params): array|false
    {
        try {
            return TenantRelationService::unbind(self::currentUserId(), self::currentTenantId(), $params);
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    protected static function formatStore(array $item): array
    {
        return [
            'id' => (int)($item['id'] ?? 0),
            'name' => (string)($item['customer_name'] ?? ''),
            'customer_name' => (string)($item['customer_name'] ?? ''),
            'contact' => (string)($item['contact'] ?? ''),
            'phone' => (string)($item['phone'] ?? ''),
            'address' => (string)($item['address'] ?? ''),
            'remark' => (string)($item['remark'] ?? ''),
            'parent_id' => (int)($item['parent_id'] ?? 0),
            'is_store' => (int)($item['is_store'] ?? 0),
            'group_id' => (int)($item['group_id'] ?? 0),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];
    }

    protected static function formatTenantStore(array $item): array
    {
        $tenantId = (int)($item['id'] ?? $item['tenant_id'] ?? 0);

        return [
            'id' => $tenantId,
            'tenant_id' => $tenantId,
            'sn' => (string)($item['sn'] ?? ''),
            'name' => (string)($item['name'] ?? ''),
            'store_name' => (string)($item['name'] ?? ''),
            'avatar' => (string)($item['avatar'] ?? ''),
            'tel' => (string)($item['tel'] ?? ''),
            'phone' => (string)($item['tel'] ?? ''),
            'disable' => (int)($item['disable'] ?? 0),
            'notes' => (string)($item['notes'] ?? ''),
            'remark' => (string)($item['notes'] ?? ''),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];
    }

    protected static function formatStoreStatus(array $status): array
    {
        return [
            'has_store' => (bool)($status['has_store'] ?? false),
            'needs_store_setup' => (bool)($status['needs_store_setup'] ?? false),
            'needs_store_switch' => (bool)($status['needs_store_switch'] ?? false),
            'store_count' => (int)($status['store_count'] ?? 0),
            'current_store_id' => (int)($status['current_store_id'] ?? 0),
            'current_store' => $status['current_store'] ?? null,
            'actions' => [
                'create' => (bool)($status['actions']['create'] ?? false),
                'join' => (bool)($status['actions']['join'] ?? false),
                'switch' => (bool)($status['actions']['switch'] ?? false),
            ],
        ];
    }

    protected static function extractSettings(array $params): array
    {
        $allowedKeys = [
            'fontsize',
            'show_one',
            'show_qian',
            'show_sale',
            'print_fontsize',
            'print_bill_style',
        ];

        $settings = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $params)) {
                $settings[$key] = $params[$key];
            }
        }

        return $settings;
    }

    private static function currentUserId(): int
    {
        return (int)(request()->adminInfo['user_id'] ?? request()->userId ?? 0);
    }

    private static function currentTenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
