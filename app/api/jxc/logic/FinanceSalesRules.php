<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 客户付款规则只影响后续销售，规则修订与单笔覆盖均留存不可变依据。 */
final class FinanceSalesRules
{
    public static function terms(int $customer, string $deliveryDate): array
    {
        $rule = Db::name('finance_customer_terms')->where('tenant_id', FinanceAccess::tenant())->where('customer_id', $customer)->order('version', 'desc')->find();
        $mode = $rule['mode'] ?? 'delivery'; $days = (int)($rule['days'] ?? 0);
        $due = $mode === 'unagreed' ? null : (new \DateTimeImmutable($deliveryDate))->modify('+' . $days . ' days')->format('Y-m-d');
        return ['version' => (int)($rule['version'] ?? 0), 'mode' => $mode, 'days' => $days, 'default_due_date' => $due];
    }

    public static function save(array $params): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant();
        if (FinanceValue::id($params['expected_tenant_id'] ?? 0) !== $tenant) { throw new \DomainException('当前门店已变化'); }
        $customer = FinanceValue::id($params['customer_id'] ?? 0); $version = FinanceValue::id($params['expected_version'] ?? 0, true);
        $key = FinanceValue::text($params['idempotency_key'] ?? '', 96);
        if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D', $key)) { throw new \DomainException('提交标识无效'); }
        $actorIdentity = FinanceAccess::actor();
        $fingerprint = hash('sha256', FinanceValue::json(['sales_customer_terms', $params, $actorIdentity['id'], $actorIdentity['type']]));
        return Db::transaction(static function () use ($tenant, $customer, $version, $key, $fingerprint, $params): array {
            (new FinanceLedger($tenant))->lockBook();
            $command = Db::name('finance_command')->where('tenant_id', $tenant)->where('idempotency_key', $key)->find();
            if ($command) {
                if (!hash_equals($command['fingerprint'], $fingerprint)) { throw new \DomainException('同一提交标识不能用于不同内容或操作人'); }
                return FinanceValue::decode($command['result']);
            }
            if (!Db::name('customer')->where('tenant_id', $tenant)->where('id', $customer)->where('parent_id', 0)->count()) { throw new \DomainException('请选择本门店主客户'); }
            $current = self::terms($customer, date('Y-m-d'));
            if ($current['version'] !== $version) { throw new \DomainException('客户付款规则已变化，请重新核对'); }
            $mode = FinanceValue::text($params['mode'] ?? '', 20);
            if (!in_array($mode, ['delivery', 'days_after', 'unagreed'], true)) { throw new \DomainException('付款规则无效'); }
            $days = $mode === 'days_after' ? FinanceValue::id($params['days'] ?? 0, true) : 0;
            if ($days > 365) { throw new \DomainException('交付后付款天数须在0至365天之间'); }
            $reason = FinanceValue::text($params['reason'] ?? '', 1000); $actor = FinanceValue::json(FinanceAccess::actor()); $now = time();
            $document = (int)Db::name('finance_document')->insertGetId(['tenant_id' => $tenant, 'type' => 'sales_customer_terms', 'status' => 'confirmed', 'version' => 1,
                'payload' => FinanceValue::json($params), 'confirmed_result' => '{}', 'created_by' => $actor, 'last_modified_by' => $actor, 'confirmed_by' => $actor,
                'confirmed_at' => $now, 'create_time' => $now, 'update_time' => $now]);
            Db::name('finance_customer_terms')->insert(['tenant_id' => $tenant, 'customer_id' => $customer, 'version' => $version + 1,
                'document_id' => $document, 'mode' => $mode, 'days' => $days, 'reason' => $reason, 'actor' => $actor, 'create_time' => $now]);
            $result = ['tenant_id' => $tenant, 'customer_id' => $customer, 'document_id' => $document, 'before' => $current, 'rule' => self::terms($customer, date('Y-m-d'))];
            Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $document)->update(['confirmed_result' => FinanceValue::json($result)]);
            Db::name('finance_command')->insert(['tenant_id' => $tenant, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'document_id' => $document,
                'action' => 'sales_customer_terms', 'actor' => $actor, 'result' => FinanceValue::json($result), 'create_time' => $now]);
            return $result;
        });
    }
}
