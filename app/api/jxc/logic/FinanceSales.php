<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 销售结算版本的账务映射；不在金额确认处重复登记实物交付。 */
final class FinanceSales
{
    public static function post(array $order, array $snapshot, int $actionId): array
    {
        $tenant = FinanceAccess::tenant(); $ledger = new FinanceLedger($tenant); $ledger->lockBook();
        FinanceOverdue::captureWithinTransaction($tenant, [(int)$order['customer_id']]);
        $version = (int)($order['settlement_version'] ?? 0);
        $previous = $version ? Db::name('finance_sales_version')->where('tenant_id', $tenant)->where('order_id', $order['id'])->where('version', $version)->find() : null;
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        $historical = $version && !$previous;
        if ($historical) {
            if (date('Y-m-d', (int)$order['datetimesingle']) >= $activation) { throw new \DomainException('启用后的历史版本缺少财务记录，请先核对，不得重复确认收入'); }
            if (empty($snapshot['opening_link_reviewed'])) { throw new \DomainException('该历史销售须明确核对已承接的期初应收来源'); }
            $opening = $ledger->source(FinanceValue::text($snapshot['opening_source'] ?? '', 40));
            if (!str_starts_with($opening['reference'], 'o:') || $opening['category'] !== 'receivable' || $opening['subject_id'] !== (int)$order['customer_id']) {
                throw new \DomainException('历史销售只能关联本客户的合法期初应收');
            }
            $previous = ['source_ref' => $opening['reference'], 'business_date' => date('Y-m-d', (int)$order['datetimesingle']), 'due_date' => $opening['due_date']];
        }
        $date = $previous['business_date'] ?? date('Y-m-d', (int)$order['datetimesingle']);
        $month = $ledger->postingMonth($date < $activation ? date('Y-m-d') : $date);
        $overdue = FinanceCustomers::overdue((int)$order['customer_id']);
        if (bccomp($overdue['amount'], '0', 2) > 0 && empty($snapshot['overdue_acknowledged'])) { throw new \DomainException('客户存在逾期未收款，请核对逾期提示并明确知晓后继续确认'); }
        $terms = $snapshot['due_terms'] ?? FinanceSalesRules::terms((int)$order['customer_id'], $date);
        $dueDate = $previous ? $previous['due_date'] : ((!empty($snapshot['due_date_reviewed']) || !empty($snapshot['due_date']))
            ? FinanceValue::date($snapshot['due_date'] ?? null, true) : $terms['default_due_date']);
        $dueReason = '';
        if (!$previous && $dueDate !== $terms['default_due_date']) {
            FinanceAccess::require('finance.sales.due_override');
            $dueReason = FinanceValue::text($snapshot['due_override_reason'] ?? '', 1000);
        }
        if (!$previous && $dueDate && $dueDate < $date) { throw new \DomainException('销售约定付款日不能早于业务日期'); }
        $delta = bcsub($snapshot['order_money'], $version ? $order['order_money'] : '0', 2);
        $actor = FinanceValue::json(FinanceAccess::actor()); $now = time();
        $documentId = (int)Db::name('finance_document')->insertGetId(['tenant_id' => $tenant, 'type' => 'sales_confirmation', 'status' => 'confirmed', 'version' => 1,
            'payload' => FinanceValue::json($snapshot), 'confirmed_result' => '{}', 'created_by' => $actor, 'last_modified_by' => $actor,
            'confirmed_by' => $actor, 'confirmed_at' => $now, 'create_time' => $now, 'update_time' => $now]);
        $reference = $previous['source_ref'] ?? '';
        $subject = (int)$order['customer_id'];
        $sourceData = ['subject_name' => $order['customer_name'], 'source_reference' => $order['order_sn'], 'sales_order_id' => (int)$order['id'],
            'sales_version' => $version + 1, 'settlement_action_id' => $actionId, 'reason' => $snapshot['edit_reason'] ?: '销售金额确认',
            'opening_link_reviewed' => (bool)$historical, 'overdue_at_confirmation' => $overdue,
            'due_terms' => $terms, 'actual_due_date' => $dueDate, 'due_override_reason' => $dueReason,
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => $now];
        if (bccomp($delta, '0', 2) > 0) {
            if ($reference === '') { $reference = $ledger->createSource($documentId, 'receivable', $subject, $delta, $date, $dueDate, $sourceData); }
            else { $ledger->add($documentId, 'balance', $subject, $delta, $date, $month, 'sales_amount_adjustment', $reference, null, $sourceData); }
        }
        $refund = '';
        if (bccomp($delta, '0', 2) < 0) {
            if (empty($snapshot['credit_reviewed']) || !is_array($snapshot['credit_allocations'] ?? null)) { throw new \DomainException('销售调减须明确核对冲抵应收组成与剩余客户应退款项'); }
            $credit = bcsub('0', $delta, 2); $used = '0.00'; $seen = [];
            if (count($snapshot['credit_allocations']) > 200 || !array_is_list($snapshot['credit_allocations'])) { throw new \DomainException('贷项冲抵组成须为不超过200项的明细'); }
            // 先保留完整客户贷项，再按每项生效日成对冲抵；跨月时未用贷项仍可追溯。
            $refund = $ledger->createSource($documentId, 'customer_refund', $subject, $credit, $date, null, $sourceData + ['credit_kind' => 'sales_reduction']);
            foreach ($snapshot['credit_allocations'] as $line) {
                if (!is_array($line)) { throw new \DomainException('贷项冲抵组成格式无效'); }
                $source = $ledger->source(FinanceValue::text($line['source'] ?? '', 40));
                if ($source['category'] !== 'receivable' || $source['subject_id'] !== $subject || isset($seen[$source['reference']])) { throw new \DomainException('贷项只能冲抵本客户不重复的正式应收'); }
                $seen[$source['reference']] = true; $amount = FinanceValue::money($line['amount'] ?? null);
                $used = bcadd($used, $amount, 2);
                $timing = $ledger->allocationTiming($source, $date);
                if ($date < $activation) { $timing['month'] = $month; }
                $ledger->add($documentId, 'balance', $subject, '-' . $amount, $timing['date'], $timing['month'], 'sales_credit', $source['reference'], $timing['effective_date'], $sourceData);
                $ledger->add($documentId, 'balance', $subject, '-' . $amount, $timing['date'], $timing['month'], 'credit_use', $refund, $timing['effective_date'], $sourceData + ['receivable_source' => $source['reference']]);
            }
            if (bccomp($used, $credit, 2) > 0) { throw new \DomainException('贷项冲抵合计超过销售调减金额'); }
        }
        $ledger->add($documentId, 'revenue', $subject, $delta, $date, $month, 'sales_confirmation', '', null, $sourceData);
        $result = ['document_id' => $documentId, 'source_ref' => $reference, 'customer_refund_ref' => $refund, 'amount_change' => $delta,
            'debt_after_order' => $ledger->categoryBalance('receivable', $subject), 'business_date' => $date, 'due_date' => $dueDate, 'posting_month' => $month];
        Db::name('finance_document')->where('id', $documentId)->where('tenant_id', $tenant)->update(['confirmed_result' => FinanceValue::json($result)]);
        Db::name('finance_sales_version')->insert(['tenant_id' => $tenant, 'order_id' => $order['id'], 'version' => $version + 1, 'document_id' => $documentId,
            'source_ref' => $reference, 'business_date' => $date, 'due_date' => $dueDate, 'create_time' => $now]);
        FinanceOverdue::captureWithinTransaction($tenant, [$subject], $documentId);
        return $result;
    }
}
