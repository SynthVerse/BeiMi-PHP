<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 持有账套锁的确认事务中，原验收冲回与替代实物事实一起生效。 */
final class FinanceCustomerReturnCorrections
{
    public static function assertCurrent(int $document): void
    {
        if (Db::name('finance_correction')->where('tenant_id', FinanceAccess::tenant())->where('original_document_id', $document)->find()) {
            throw new \DomainException('原实物验收已有更正或撤销，请重新核对有效验收；历史记录仍可查看');
        }
    }

    public static function replace(FinanceLedger $ledger, array $original, array $replacement, string $reason, int $duplicateOf, bool $reverseOnly): array
    {
        if ($duplicateOf) { throw new \DomainException('实物重复录入请核对原验收后关联撤销，不能仅按金额判重'); }
        $tenant = FinanceAccess::tenant(); $fact = FinanceValue::decode($original['confirmed_result']);
        $replaced = Db::name('finance_correction')->where('tenant_id', $tenant)->field('original_document_id')->buildSql();
        $refund = Db::name('finance_document')->where('tenant_id', $tenant)->where('type', 'customer_refund')->where('status', 'confirmed')
            ->whereRaw('id NOT IN ' . $replaced)->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(confirmed_result,'$.return_context.document_id'))=?", [(string)$original['id']])->find();
        if ($refund) { throw new \DomainException('原验收仍关联有效退款，请先核对并更正退款的实物关联，不能自动改变真实退款'); }
        $used = (string)Db::name('finance_inventory_count_correction')->where('tenant_id', $tenant)->where('stock_flow_id', $fact['stock_flow_id'])->sum('quantity');
        if (bccomp($used, '0', 4) > 0) { throw new \DomainException('原验收已关联盘点反向，请先撤回盘点关联后更正实物'); }
        $input = FinanceValue::decode($replacement['payload']);
        if (!$reverseOnly) {
            $source = FinanceCustomerReturns::source(FinanceValue::id($input['original_sales_order_id'] ?? null), FinanceValue::id($input['sku_id'] ?? null), FinanceValue::id($input['subject_id'] ?? null), $input['actual_date'] ?? null, $input['delivery_period'] ?? '');
            foreach (['expected_return_id', 'expected_sales_version', 'expected_cost_event_id'] as $key) {
                if (FinanceValue::id($input[$key] ?? null, true) !== $source[$key]) { throw new \DomainException('替代验收依据已有变化，请重新读取原销售及成本'); }
            }
        }
        $flow = StockService::voidFinanceCustomerReturnWithinTransaction($fact, (int)$replacement['id']);
        Db::name('finance_correction')->insert(['tenant_id' => $tenant, 'original_document_id' => $original['id'], 'replacement_document_id' => $replacement['id'],
            'reason' => $reason, 'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
        if ($reverseOnly) {
            $result = ['type' => 'customer_return_actual', 'subject_id' => $fact['subject_id'], 'subject_name' => $fact['subject_name'], 'reversal_of' => (int)$original['id'], 'created_sources' => []];
        } else {
            $source = FinanceCustomerReturns::source((int)$input['original_sales_order_id'], (int)$input['sku_id'], (int)$input['subject_id'], $input['actual_date'], $input['delivery_period'] ?? '');
            foreach (['expected_return_id', 'expected_sales_version', 'expected_cost_event_id'] as $key) { $input[$key] = $source[$key]; }
            $result = FinanceCustomerReturns::confirm($ledger, $replacement, $input);
        }
        return $result + ['corrects_document_id' => (int)$original['id'], 'correction_reason' => $reason, 'reversed_stock_flow_id' => $flow, 'original_return' => $fact,
            'cost_impacts' => (new FinanceCostLedger($tenant))->documentImpacts((int)$replacement['id'])];
    }
}
