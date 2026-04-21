<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Customer;

class StoreLogic extends BaseLogic
{
    /**
     * 获取店铺信息
     * 基于当前租户的主客户信息（或指定的 store_id）
     */
    public static function getStoreInfo(array $params): array
    {
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
}
