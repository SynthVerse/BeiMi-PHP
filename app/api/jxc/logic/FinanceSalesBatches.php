<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 跨交付结算只消费覆盖量；实物库存不在此处增减。确认由调用方门店锁事务保护。 */
final class FinanceSalesBatches
{
    public static function options(int $customer, string $from, string $to): array
    {
        FinanceDocumentPolicy::read('sales_batch');
        if ($from > $to || $to > date('Y-m-d')) { throw new \DomainException('交付日期范围无效或包含尚未发生的日期'); }
        $rows = $customer ? FinanceDeliveries::rows($customer, $from, $to) : [];
        $rule = FinanceSalesRules::terms($customer, $from);
        foreach ($rows as &$row) { $row['subject_id'] = $customer; $row['terms'] = self::datedTerms($rule, $row['date']); } unset($row);
        return ['tenant_id' => FinanceAccess::tenant(), 'deliveries' => $rows, 'precision_rules' => FinanceSalesPrecision::rules($customer),
            'can_override_precision' => FinanceAccess::has('finance.sales.precision_override'), 'can_round_sales' => FinanceAccess::has('finance.sales.rounding'),
            'can_override_due' => FinanceAccess::has('finance.sales.due_override'), 'can_confirm_difference' => FinanceAccess::owner(),
            'default_show_cumulative_debt' => (int)Db::name('customer_sales_preference')->where('tenant_id', FinanceAccess::tenant())->where('customer_id', $customer)->value('show_cumulative_debt'),
            'overdue' => $customer ? FinanceCustomers::overdue($customer) : ['amount' => '0.00']];
    }

    public static function reauthorize(array $result): void
    {
        if (!empty($result['precision']['overridden'])) { FinanceAccess::require('finance.sales.precision_override'); }
        if (bccomp($result['rounding_amount'] ?? '0', '0', 2) > 0) { FinanceAccess::require('finance.sales.rounding'); }
        foreach ($result['lines'] ?? [] as $line) {
            if (!empty($line['due_override_applied'])) { FinanceAccess::require('finance.sales.due_override'); }
            if (bccomp($line['weight_difference'] ?? '0', '0', 4) !== 0) { FinanceAccess::require('', true); }
        }
    }

    public static function confirm(FinanceLedger $ledger, array $document, ?array $original = null, string $correctionReason = ''): array
    {
        FinanceDocumentPolicy::authorize('sales_batch', true);
        $tenant = FinanceAccess::tenant(); $id = (int)$document['id']; $data = FinanceValue::decode($document['payload']);
        $customer = FinanceValue::id($data['subject_id'] ?? 0);
        $name = Db::name('customer')->where('tenant_id', $tenant)->where('id', $customer)->where('parent_id', 0)->value('customer_name');
        if ($name === null) { throw new \DomainException('请选择本店主客户'); }
        $reason = FinanceValue::text($data['reason'] ?? '', 1000);
        $showDebt = isset($data['show_cumulative_debt']) ? FinanceValue::id($data['show_cumulative_debt'], true)
            : (int)Db::name('customer_sales_preference')->where('tenant_id', $tenant)->where('customer_id', $customer)->value('show_cumulative_debt');
        if (!in_array($showDebt, [0, 1], true)) { throw new \DomainException('累计欠款打印偏好无效'); }
        $previous = $original ? FinanceValue::decode($original['confirmed_result']) : null;
        if ($original) {
            $correctionReason = FinanceValue::text($correctionReason, 1000);
            if ((int)$previous['subject_id'] !== $customer) { throw new \DomainException('销售金额更正不能更换主客户'); }
            if (Db::name('finance_correction')->where('tenant_id', $tenant)->where('original_document_id', $original['id'])->count()) { throw new \DomainException('原销售结算已有后续更正，请打开最新版本'); }
        }
        $precision = FinanceSalesPrecision::selection($customer, $data);
        $overdue = FinanceCustomers::overdue($customer);
        if (bccomp($overdue['amount'], '0', 2) > 0 && empty($data['overdue_acknowledged'])) { throw new \DomainException('客户存在逾期余额，请明确知晓后继续确认'); }
        $input = $data['lines'] ?? null;
        if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 200) { throw new \DomainException('请选择1至200条真实交付来源'); }
        $available = array_column(FinanceDeliveries::rows($customer, '1900-01-01', date('Y-m-d'), true), null, 'delivery_item_id');
        $currentTerms = FinanceSalesRules::terms($customer, date('Y-m-d'));
        $before = array_column($previous['lines'] ?? [], null, 'delivery_item_id'); $lines = []; $seen = []; $goods = '0.00'; $automatic = '0.000000';
        foreach ($input as $item) {
            if (!is_array($item)) { throw new \DomainException('交付组成格式无效'); }
            $delivery = FinanceValue::id($item['delivery_item_id'] ?? 0);
            if (!isset($available[$delivery]) || isset($seen[$delivery])) { throw new \DomainException('交付来源不属于本客户或重复选择'); } $seen[$delivery] = true;
            $source = $available[$delivery]; $covered = self::weight($item['covered_weight'] ?? null); $weight = self::weight($item['settlement_weight'] ?? null, true);
            if (bccomp($covered, bcadd($source['pending_weight'], $before[$delivery]['covered_weight'] ?? '0', 4), 4) > 0) { throw new \DomainException('本次覆盖量超过该交付剩余量'); }
            $difference = bcsub($weight, $covered, 4); $differenceReason = '';
            if (bccomp($difference, '0', 4) !== 0) { FinanceAccess::require('', true); $differenceReason = FinanceValue::text($item['difference_reason'] ?? '', 1000); }
            $price = FinanceValue::money($item['price'] ?? null, true);
            if (bccomp($weight, '0', 4) === 0 && bccomp($price, '0', 2) > 0) { throw new \DomainException('免费、赠送或赔偿应明确零价及原因，不能用零计费重量代替'); }
            $zeroReason = bccomp($price, '0', 2) === 0 ? FinanceValue::text($item['zero_price_reason'] ?? '', 500) : '';
            $calculation = FinanceSalesPrecision::amount($weight, $price, $precision['actual_mode']);
            FinanceValue::money($calculation['amount'], true);
            $terms = $before[$delivery]['terms'] ?? self::datedTerms($currentTerms, $source['date']);
            if (isset($item['terms_version']) && FinanceValue::id($item['terms_version'], true) !== (int)$terms['version']) { throw new \DomainException('客户付款规则已变化，请重新核对'); }
            $due = isset($before[$delivery]) ? $before[$delivery]['due_date'] : $terms['default_due_date']; $dueReason = $before[$delivery]['due_override_reason'] ?? ''; $dueApplied = false;
            $receivableSources = $before[$delivery]['receivable_sources'] ?? [];
            if ($receivableSources) { $currentSource = $ledger->source($receivableSources[count($receivableSources) - 1]); $due = $currentSource['due_date']; }
            if (array_key_exists('due_date', $item)) {
                $selected = FinanceValue::date($item['due_date'], true);
                if (isset($before[$delivery]) && $selected !== $before[$delivery]['due_date']) { throw new \DomainException('原应收付款日应通过付款日调整处理'); }
                if (!isset($before[$delivery])) {
                    if ($selected !== $terms['default_due_date']) { FinanceAccess::require('finance.sales.due_override'); $dueReason = FinanceValue::text($item['due_override_reason'] ?? '', 1000); $dueApplied = true; }
                    $due = $selected;
                }
            }
            if ($due && $due < $source['date']) { throw new \DomainException('付款日不能早于实际交付日'); }
            $ledger->postingMonth($source['date']);
            $lines[] = array_merge($source, $calculation, ['covered_weight' => $covered, 'settlement_weight' => $weight, 'price' => $price,
                'weight_difference' => $difference, 'difference_reason' => $differenceReason, 'zero_price_reason' => $zeroReason,
                'terms' => $terms, 'due_date' => $due, 'due_override_reason' => $dueReason, 'due_override_applied' => $dueApplied, 'receivable_sources' => $receivableSources]);
            $goods = bcadd($goods, $calculation['amount'], 2); $automatic = bcadd($automatic, $calculation['automatic_rounding_difference'], 6);
        }
        $rounding = FinanceValue::money($data['rounding_amount'] ?? '0', true);
        if (bccomp($rounding, '0', 2) > 0) { FinanceAccess::require('finance.sales.rounding'); if (bccomp($rounding, $goods, 2) >= 0) { throw new \DomainException('人工抹零必须小于商品合计'); } }
        $remaining = $rounding;
        foreach ($lines as &$line) {
            $line['rounding_share'] = bccomp($goods, '0', 2) > 0 ? bcdiv(bcmul($rounding, $line['amount'], 4), $goods, 2) : '0.00';
            $remaining = bcsub($remaining, $line['rounding_share'], 2);
        } unset($line);
        foreach ($lines as &$line) {
            if (bccomp($remaining, '0', 2) > 0 && bccomp($line['rounding_share'], $line['amount'], 2) < 0) { $line['rounding_share'] = bcadd($line['rounding_share'], '0.01', 2); $remaining = bcsub($remaining, '0.01', 2); }
            $line['net_amount'] = bcsub($line['amount'], $line['rounding_share'], 2);
        } unset($line);
        $after = array_column($lines, null, 'delivery_item_id'); $created = []; $credits = []; $months = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $delivery) {
            $old = $before[$delivery] ?? null; $new = $after[$delivery] ?? null; $line = $new ?? $old;
            $coverage = bcsub($new['covered_weight'] ?? '0', $old['covered_weight'] ?? '0', 4);
            Db::name('finance_sales_coverage')->insert(['tenant_id' => $tenant, 'document_id' => $id, 'delivery_item_id' => $delivery, 'order_id' => $line['order_id'], 'covered_delta' => $coverage, 'create_time' => time()]);
            $delta = bcsub($new['net_amount'] ?? '0', $old['net_amount'] ?? '0', 2); $month = $ledger->postingMonth($line['date']); $months[] = $month;
            $details = $line + ['subject_name' => $name, 'source_reference' => '销售结算 #' . $id . ' / ' . $line['order_sn'], 'reason' => $reason,
                'corrects_document_id' => (int)($original['id'] ?? 0), 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
            if (bccomp($delta, '0', 2) > 0) {
                $reference = $ledger->createSource($id, 'receivable', $customer, $delta, $line['date'], $line['due_date'], $details); $created[] = $reference;
                $after[$delivery]['receivable_sources'][] = $reference;
            }
            elseif (bccomp($delta, '0', 2) < 0) {
                $credit = bcsub('0', $delta, 2); $reference = $ledger->createSource($id, 'customer_refund', $customer, $credit, $line['date'], null, $details);
                $credits[] = ['reference' => $reference, 'amount' => $credit, 'date' => $line['date']]; $created[] = $reference;
            }
            $ledger->add($id, 'revenue', $customer, $delta, $line['date'], $month, 'sales_batch', '', null, $details);
        }
        if ($credits) { self::allocateCredits($ledger, $id, $customer, $credits, $data); }
        if ($original) { Db::name('finance_correction')->insert(['tenant_id' => $tenant, 'original_document_id' => $original['id'], 'replacement_document_id' => $id, 'reason' => $correctionReason, 'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]); }
        Db::name('customer_sales_preference')->duplicate(['show_cumulative_debt' => $showDebt, 'operator_id' => FinanceAccess::operator(), 'update_time' => time()])
            ->insert(['tenant_id' => $tenant, 'customer_id' => $customer, 'show_cumulative_debt' => $showDebt, 'operator_id' => FinanceAccess::operator(), 'create_time' => time(), 'update_time' => time()]);
        return ['subject_id' => $customer, 'subject_name' => $name, 'lines' => array_values($after), 'goods_amount' => $goods, 'amount' => bcsub($goods, $rounding, 2),
            'precision' => $precision, 'automatic_rounding_difference' => $automatic, 'rounding_amount' => $rounding,
            'rounding_allocation' => '按行金额比例分摊，分位差依来源顺序补齐', 'created_sources' => $created, 'posting_months' => array_values(array_unique($months)),
            'debt_after_order' => $ledger->categoryBalance('receivable', $customer), 'show_cumulative_debt' => (bool)$showDebt, 'overdue_at_confirmation' => $overdue,
            'corrects_document_id' => (int)($original['id'] ?? 0)];
    }

    private static function datedTerms(array $rule, string $date): array
    {
        $rule['default_due_date'] = $rule['mode'] === 'unagreed' ? null : (new \DateTimeImmutable($date))->modify('+' . (int)$rule['days'] . ' days')->format('Y-m-d');
        return $rule;
    }

    private static function weight(mixed $value, bool $allowZero = false): string
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,4})?$/D', $value) || (!$allowZero && bccomp($value, '0', 4) <= 0)) { throw new \DomainException('重量须为最多四位小数，覆盖量必须大于零'); }
        return bcadd($value, '0', 4);
    }

    private static function allocateCredits(FinanceLedger $ledger, int $id, int $customer, array $credits, array $data): void
    {
        if (empty($data['credit_reviewed']) || !is_array($data['credit_allocations'] ?? null) || !array_is_list($data['credit_allocations']) || count($data['credit_allocations']) > 200) { throw new \DomainException('销售调减须明确核对冲抵应收组成及剩余应退款'); }
        $seen = []; $index = 0;
        foreach ($data['credit_allocations'] as $allocation) {
            if (!is_array($allocation)) { throw new \DomainException('冲抵组成格式无效'); }
            $reference = FinanceValue::text($allocation['source'] ?? '', 40); $source = $ledger->source($reference);
            if ($source['subject_id'] !== $customer || $source['category'] !== 'receivable' || isset($seen[$reference])) { throw new \DomainException('冲抵对象必须为本客户不重复的正式应收'); } $seen[$reference] = true;
            $amount = FinanceValue::money($allocation['amount'] ?? null);
            while (bccomp($amount, '0', 2) > 0) {
                while (isset($credits[$index]) && bccomp($credits[$index]['amount'], '0', 2) === 0) { $index++; }
                if (!isset($credits[$index])) { throw new \DomainException('贷项冲抵合计超过销售调减金额'); }
                $credit = &$credits[$index]; $part = bccomp($amount, $credit['amount'], 2) < 0 ? $amount : $credit['amount'];
                $timing = $ledger->allocationTiming($source, $credit['date']);
                $ledger->add($id, 'balance', $customer, '-' . $part, $timing['date'], $timing['month'], 'sales_credit', $reference, $timing['effective_date']);
                $ledger->add($id, 'balance', $customer, '-' . $part, $timing['date'], $timing['month'], 'credit_use', $credit['reference'], $timing['effective_date']);
                $amount = bcsub($amount, $part, 2); $credit['amount'] = bcsub($credit['amount'], $part, 2); unset($credit);
            }
        }
    }
}
