<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 逐行金额精度与人工抹零分别确认，默认规则的修改只作用于后续版本。 */
final class FinanceSalesPrecision
{
    public static function rules(int $customer): array
    {
        $store = self::latest(0); $specific = $customer ? self::latest($customer) : null;
        $storeMode = $store['mode'] ?? 'cents'; $customerMode = $specific['mode'] ?? 'inherit';
        return ['store_version' => (int)($store['version'] ?? 0), 'customer_version' => (int)($specific['version'] ?? 0),
            'store_mode' => $storeMode, 'customer_mode' => $customerMode, 'default_mode' => $customerMode === 'inherit' ? $storeMode : $customerMode];
    }

    private static function latest(int $customer): ?array
    {
        return Db::name('finance_sales_precision_rule')->where('tenant_id', FinanceAccess::tenant())->where('customer_id', $customer)->order('version', 'desc')->find();
    }

    public static function selection(int $customer, array $request): array
    {
        $rules = self::rules($customer);
        foreach (['store_version', 'customer_version'] as $field) {
            if (isset($request['precision_rules'][$field]) && FinanceValue::id($request['precision_rules'][$field], true) !== $rules[$field]) { throw new \DomainException('金额精度默认规则已变化，请重新核对本次精度'); }
        }
        $mode = FinanceValue::text($request['precision_mode'] ?? $rules['default_mode'], 16);
        if (!in_array($mode, ['cents', 'integer'], true)) { throw new \DomainException('销售金额精度须为逐行两位小数或逐行整元'); }
        $overridden = $mode !== $rules['default_mode']; $reason = '';
        if ($overridden) { FinanceAccess::require('finance.sales.precision_override'); $reason = FinanceValue::text($request['precision_override_reason'] ?? '', 1000); }
        return $rules + ['actual_mode' => $mode, 'overridden' => $overridden, 'reason' => $reason, 'actor' => FinanceAccess::actor(), 'confirmed_at' => time()];
    }

    public static function amount(string $quantity, string $price, string $mode): array
    {
        $raw = bcmul($quantity, $price, 6);
        $rounded = $mode === 'integer' ? bcadd(bcadd($raw, '0.500000', 6), '0', 0) . '.00' : bcadd(bcadd($raw, '0.005000', 6), '0', 2);
        return ['amount' => $rounded, 'raw_amount' => $raw, 'automatic_rounding_difference' => bcsub($rounded, $raw, 6)];
    }

    public static function save(array $params): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant();
        if (FinanceValue::id($params['expected_tenant_id'] ?? 0) !== $tenant) { throw new \DomainException('当前门店已变化'); }
        $customer = FinanceValue::id($params['customer_id'] ?? 0, true); $version = FinanceValue::id($params['expected_version'] ?? 0, true);
        $key = FinanceValue::text($params['idempotency_key'] ?? '', 96);
        if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D', $key)) { throw new \DomainException('提交标识无效'); }
        $actor = FinanceAccess::actor(); $fingerprint = hash('sha256', FinanceValue::json(['sales_precision', $params, $actor['id'], $actor['type']]));
        return Db::transaction(static function () use ($tenant, $customer, $version, $key, $fingerprint, $params, $actor): array {
            (new FinanceLedger($tenant))->lockBook();
            $command = Db::name('finance_command')->where('tenant_id', $tenant)->where('idempotency_key', $key)->find();
            if ($command) { if (!hash_equals($command['fingerprint'], $fingerprint)) { throw new \DomainException('同一提交标识不能用于不同内容或操作人'); } return FinanceValue::decode($command['result']); }
            if ($customer && !Db::name('customer')->where('tenant_id', $tenant)->where('id', $customer)->where('parent_id', 0)->count()) { throw new \DomainException('请选择本店主客户'); }
            $current = self::latest($customer);
            if ((int)($current['version'] ?? 0) !== $version) { throw new \DomainException('金额精度规则已变化，请重新读取'); }
            $mode = FinanceValue::text($params['mode'] ?? '', 16);
            if (!in_array($mode, $customer ? ['cents', 'integer', 'inherit'] : ['cents', 'integer'], true)) { throw new \DomainException('金额精度规则无效'); }
            Db::name('finance_sales_precision_rule')->insert(['tenant_id' => $tenant, 'customer_id' => $customer, 'version' => $version + 1,
                'mode' => $mode, 'reason' => FinanceValue::text($params['reason'] ?? '', 1000), 'actor' => FinanceValue::json($actor), 'create_time' => time()]);
            $result = ['tenant_id' => $tenant, 'customer_id' => $customer, 'rule' => self::rules($customer)];
            Db::name('finance_command')->insert(['tenant_id' => $tenant, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'document_id' => 0,
                'action' => 'sales_precision', 'actor' => FinanceValue::json($actor), 'result' => FinanceValue::json($result), 'create_time' => time()]);
            return $result;
        });
    }
}
