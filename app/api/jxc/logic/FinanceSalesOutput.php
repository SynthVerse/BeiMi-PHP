<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 输出使用已确认结果；打印准备、实际出纸回执独立于销售事实。 */
final class FinanceSalesOutput
{
    public static function document(array $params): array
    {
        FinanceAccess::require('settlement.view'); $tenant = FinanceAccess::tenant(); $id = FinanceValue::id($params['id'] ?? 0);
        $row = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $id)->where('type', 'sales_batch')->where('status', 'confirmed')->find();
        if (!$row) { throw new \DomainException('只能输出本店已确认的销售结算'); }
        $result = FinanceValue::decode($row['confirmed_result']); $root = $id; $version = 1; $seen = [];
        while ($parent = Db::name('finance_correction')->where('tenant_id', $tenant)->where('replacement_document_id', $root)->value('original_document_id')) {
            if (isset($seen[$parent]) || $version > 1000) { throw new \DomainException('销售版本链异常，请核对'); } $seen[$parent] = true; $root = (int)$parent; $version++;
        }
        $replacement = (int)(Db::name('finance_correction')->where('tenant_id', $tenant)->where('original_document_id', $id)->value('replacement_document_id') ?: 0);
        $pending = Db::name('finance_sales_print')->where('tenant_id', $tenant)->where('sales_id', $root)->where('status', 'pending')->field('id,document_id,copy_no,actor')->find();
        if ($pending) { $pending['can_resolve'] = FinanceAccess::owner() || FinanceValue::decode($pending['actor']) === [FinanceAccess::actor()['id'], FinanceAccess::actor()['type']]; unset($pending['actor']); }
        $lines = [];
        foreach ($result['lines'] as $line) {
            $lines[] = ['id' => $line['delivery_item_id'], 'name' => $line['goods_name'], 'sku_name' => $line['sku_name'],
                'delivery_date' => $line['date'], 'delivery_customer_name' => $line['delivery_customer_name'], 'covered_weight' => $line['covered_weight'],
                'actual_delivery_weight' => $line['actual_weight'], 'customer_settlement_weight' => $line['settlement_weight'],
                'base_unit_name' => '斤', 'pricing_unit_name' => '斤', 'pricing_quantity' => $line['settlement_weight'], 'price' => $line['price'], 'amount' => $line['amount']];
        }
        return ['tenant_id' => $tenant, 'id' => $id, 'order_id' => $id, 'sales_id' => $root, 'order_sn' => 'FS' . $root, 'document_version' => (int)$row['version'],
            'version' => $version, 'settlement_status' => 'formal', 'can_print' => true, 'can_share' => FinanceAccess::owner(),
            'replacement_document_id' => $replacement, 'invalidation_notice' => $replacement ? '已被第 ' . $replacement . ' 号销售结算替代，本版已失效' : '',
            'customer_id' => $result['subject_id'], 'customer_name' => $result['subject_name'], 'lines' => $lines, 'goods' => $lines,
            'goods_amount' => $result['goods_amount'], 'rounding_amount' => $result['rounding_amount'], 'order_money' => $result['amount'],
            'show_cumulative_debt' => (bool)($result['show_cumulative_debt'] ?? false), 'debt_after_order' => $result['debt_after_order'],
            'confirmed_at' => (int)$row['confirmed_at'], 'successful_print_count' => self::count($root), 'pending_print' => $pending];
    }

    public static function prepare(array $params): array
    {
        FinanceAccess::require('settlement.view'); $tenant = FinanceAccess::tenant();
        if (FinanceValue::id($params['expected_tenant_id'] ?? 0) !== $tenant) { throw new \DomainException('门店已变化'); }
        $id = FinanceValue::id($params['id'] ?? 0); $version = FinanceValue::id($params['expected_version'] ?? 0);
        $key = FinanceValue::text($params['idempotency_key'] ?? '', 96);
        if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D', $key)) { throw new \DomainException('打印提交标识无效'); }
        $identity = [FinanceAccess::actor()['id'], FinanceAccess::actor()['type']];
        $fingerprint = hash('sha256', FinanceValue::json([$params, $identity]));
        return Db::transaction(static function () use ($tenant, $id, $version, $key, $fingerprint, $identity): array {
            Db::name('finance_preparation')->where('tenant_id', $tenant)->lock(true)->find();
            $existing = Db::name('finance_sales_print')->where('tenant_id', $tenant)->where('idempotency_key', $key)->find();
            if ($existing) {
                if (!hash_equals($existing['fingerprint'], $fingerprint)) { throw new \DomainException('同一打印标识不能用于不同内容或操作人'); }
                $result = FinanceValue::decode($existing['prepared_result']);
                $result['document']['invalidation_notice'] = self::document(['id' => $id])['invalidation_notice'];
                return $result + ['status' => $existing['status']];
            }
            $document = self::document(['id' => $id]);
            if ($version !== $document['document_version']) { throw new \DomainException('销售记录版本已变化，请重新读取'); }
            $pending = Db::name('finance_sales_print')->where('tenant_id', $tenant)->where('sales_id', $document['sales_id'])->where('status', 'pending')->find();
            if ($pending) { throw new \DomainException('本销售单上次出纸结果待核实，请先登记回执'); }
            $copy = (int)Db::name('finance_sales_print')->where('tenant_id', $tenant)->where('sales_id', $document['sales_id'])->max('copy_no') + 1;
            $log = (int)Db::name('finance_sales_print')->insertGetId(['tenant_id' => $tenant, 'sales_id' => $document['sales_id'], 'document_id' => $id,
                'copy_no' => $copy, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'actor' => FinanceValue::json($identity), 'prepared_result' => '{}', 'create_time' => time()]);
            $result = ['tenant_id' => $tenant, 'print_log_id' => $log, 'document_id' => $id, 'copy_no' => $copy, 'reprint_count' => self::count($document['sales_id']),
                'successful_print_count' => self::count($document['sales_id']), 'document' => $document, 'print_requested_time' => time()];
            Db::name('finance_sales_print')->where('tenant_id', $tenant)->where('id', $log)->update(['prepared_result' => FinanceValue::json($result)]);
            return $result + ['status' => 'pending'];
        });
    }

    public static function receipt(array $params): array
    {
        FinanceAccess::require('settlement.view'); $tenant = FinanceAccess::tenant();
        if (FinanceValue::id($params['expected_tenant_id'] ?? 0) !== $tenant) { throw new \DomainException('门店已变化'); }
        $id = FinanceValue::id($params['id'] ?? 0); $logId = FinanceValue::id($params['print_log_id'] ?? 0);
        if (!in_array($params['success'] ?? null, [0, 1], true)) { throw new \DomainException('请明确核实实际是否出纸'); }
        $status = $params['success'] ? 'success' : 'failed'; $error = FinanceValue::text($params['error_message'] ?? '', 255, false);
        return Db::transaction(static function () use ($tenant, $id, $logId, $status, $error): array {
            Db::name('finance_preparation')->where('tenant_id', $tenant)->lock(true)->find();
            $log = Db::name('finance_sales_print')->where('tenant_id', $tenant)->where('id', $logId)->where('document_id', $id)->find();
            if (!$log) { throw new \DomainException('打印记录不存在或不属于本门店销售单'); }
            $actor = FinanceValue::decode($log['actor']);
            if ($actor !== [FinanceAccess::actor()['id'], FinanceAccess::actor()['type']] && !FinanceAccess::owner()) { throw new \DomainException('仅原打印人员或门店最高权限可核实出纸结果'); }
            if ($log['status'] !== 'pending' && $log['status'] !== $status) { throw new \DomainException('打印结果与已保存回执冲突'); }
            if ($log['status'] === 'pending') { Db::name('finance_sales_print')->where('tenant_id', $tenant)->where('id', $logId)->update(['status' => $status, 'error_message' => $error, 'finished_at' => time()]); }
            return ['tenant_id' => $tenant, 'id' => $id, 'print_log_id' => $logId, 'status' => $status, 'successful_print_count' => self::count((int)$log['sales_id'])];
        });
    }

    /** 仅用于恢复原打印终态，不把本机判断覆盖到另一人员已核实的回执上。 */
    public static function status(array $params): array
    {
        FinanceAccess::require('settlement.view'); $tenant = FinanceAccess::tenant();
        if (FinanceValue::id($params['expected_tenant_id'] ?? 0) !== $tenant) { throw new \DomainException('门店已变化'); }
        $log = Db::name('finance_sales_print')->where('tenant_id', $tenant)->where('id', FinanceValue::id($params['print_log_id'] ?? 0))
            ->where('document_id', FinanceValue::id($params['id'] ?? 0))->find();
        if (!$log) { throw new \DomainException('打印记录不存在或不属于本门店销售单'); }
        if (FinanceValue::decode($log['actor']) !== [FinanceAccess::actor()['id'], FinanceAccess::actor()['type']] && !FinanceAccess::owner()) { throw new \DomainException('仅原打印人员或门店最高权限可核实出纸结果'); }
        return ['tenant_id' => $tenant, 'id' => (int)$log['document_id'], 'print_log_id' => (int)$log['id'], 'status' => $log['status'], 'successful_print_count' => self::count((int)$log['sales_id'])];
    }

    private static function count(int $root): int
    {
        return Db::name('finance_sales_print')->where('tenant_id', FinanceAccess::tenant())->where('sales_id', $root)->where('status', 'success')->count();
    }
}
