<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 报表只读取正式影响；金额计算及组成留在同一账套读取事务中。 */
final class FinanceReports
{
    public const TYPES = ['profit' => '经营利润', 'cash' => '资金收支', 'customer' => '客户往来', 'vendor' => '供应商往来', 'expense' => '费用分析', 'inventory' => '库存与损耗'];

    public static function authorize(array $params): string
    {
        $kind = FinanceValue::text($params['report'] ?? 'profit', 24);
        if (!isset(self::TYPES[$kind])) { throw new \DomainException('报表类别无效'); }
        FinanceAccess::require('finance.report.' . $kind . '.view');
        return $kind;
    }

    public static function monthly(FinanceLedger $ledger, array $params): array
    {
        $month = FinanceValue::text($params['month'] ?? date('Y-m'), 7);
        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $month)) { throw new \DomainException('请选择有效报表月份'); }
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', FinanceAccess::tenant())->value('activation_date');
        if ($month < substr($activation, 0, 7) || $month > date('Y-m')) { throw new \DomainException('报表月份须在启用月与当前月之间'); }
        $kind = self::authorize($params);
        $cutoff = min(date('Y-m-d'), date('Y-m-t', strtotime($month . '-01')));
        $period = Db::name('finance_period')->where('tenant_id', FinanceAccess::tenant())->where('month', $month)->find();
        if ($period) {
            $snapshot = FinanceValue::decode($period['snapshot']);
            if (!isset($snapshot['reports'][$kind])) { throw new \DomainException('该历史月份缺少权威报表快照，不能用当前数据重算替代'); }
            return self::visible($snapshot['reports'][$kind]);
        }
        $checklist = FinancePeriodChecklist::collect($ledger, ['month' => $month]);
        $unresolved = array_filter($checklist['items'], static fn(array $item): bool => in_array($item['severity'], ['hard', 'blocking'], true) && !in_array($item['category'], ['month_not_ended', 'month_order', 'month_closed'], true));
        $estimated = array_filter($checklist['items'], static fn(array $item): bool => $item['severity'] === 'estimate' || $item['category'] === 'purchase_cost');
        return self::visible(['tenant_id' => FinanceAccess::tenant(), 'report' => $kind, 'month' => $month, 'cutoff' => $cutoff,
            'verification' => ['has_unresolved' => (bool)$unresolved, 'has_estimates' => (bool)$estimated, 'status' => $unresolved || $estimated ? 'not_fully_verified' : 'checked'],
            'closing_status' => 'open', 'stage' => true, 'generated_at' => date('Y-m-d H:i:s'), 'data' => match ($kind) {
                'cash' => self::cash($month), 'customer' => self::balances($month, ['receivable', 'advance', 'customer_refund', 'recovery']), 'vendor' => self::vendor($month, $cutoff), 'expense' => self::expense($month), 'inventory' => self::inventory($month), default => self::profit($month, $cutoff),
            }]);
    }

    private static function profit(string $month, string $cutoff): array
    {
        $entries = Db::name('finance_entry')->where('tenant_id', FinanceAccess::tenant())->where('posting_month', $month)
            ->whereIn('metric', ['revenue', 'expense', 'loss', 'recovery_income'])->order('id')->select()->toArray();
        foreach ($entries as &$entry) { $entry['details'] = FinanceValue::decode($entry['details']); } unset($entry);
        $effects = Db::name('finance_cost_effect')->where('tenant_id', FinanceAccess::tenant())->where('posting_month', $month)
            ->whereIn('bucket', ['sale', 'loss'])->order('id')->select()->toArray();
        $totals = ['revenue' => '0.00', 'expense' => '0.00', 'loss' => '0.00', 'recovery_income' => '0.00'];
        foreach ($entries as $entry) { $totals[$entry['metric']] = bcadd($totals[$entry['metric']], $entry['amount'], 2); }
        $cost = ['sale' => '0.000000', 'loss' => '0.000000'];
        foreach ($effects as $effect) { $cost[$effect['bucket']] = bcadd($cost[$effect['bucket']], $effect['value_delta'], 6); }
        $pending = self::pendingCost($month, $effects);
        $salesCost = self::money($cost['sale']); $loss = bcadd($totals['loss'], self::money($cost['loss']), 2);
        $gross = bcsub($totals['revenue'], $salesCost, 2);
        $profit = bcadd(bcsub(bcsub($gross, $totals['expense'], 2), $loss, 2), $totals['recovery_income'], 2);
        $summary = $totals + ['known_sales_cost' => $salesCost, 'sales_cost' => $pending['sale'] ? null : $salesCost,
            'gross_profit' => $pending['sale'] ? null : $gross, 'known_loss' => $loss, 'total_loss' => $pending['loss'] ? null : $loss,
            'known_profit' => $profit, 'profit' => in_array(true, $pending, true) ? null : $profit, 'cost_pending' => in_array(true, $pending, true)];
        return ['summary' => $summary, 'entries' => $entries, 'cost_effects' => $effects,
            'prior_period_entries' => array_values(array_filter($entries, static fn(array $row): bool => ($row['details']['benefit_month'] ?? substr($row['business_date'] ?? $month, 0, 7)) < $month)),
            'prior_period_cost_effects' => array_values(array_filter($effects, static fn(array $row): bool => substr($row['business_date'], 0, 7) < $month))];
    }

    private static function pendingCost(string $month, array $periodEffects): array
    {
        $pending = ['sale' => false, 'loss' => false]; $affected = [];
        foreach ($periodEffects as $row) { $affected[FinanceValue::json([$row['origin_key'], $row['warehouse_id'], $row['sku_id'], $row['bucket'], $row['reference']])] = true; }
        $rows = Db::name('finance_cost_effect')->alias('e')->leftJoin('finance_cost_origin o', 'o.tenant_id=e.tenant_id AND o.origin_key=e.origin_key')
            ->where('e.tenant_id', FinanceAccess::tenant())->where('e.posting_month', '<=', $month)->whereIn('e.bucket', ['sale', 'loss'])->whereNull('o.current_amount')
            ->field('e.origin_key,e.warehouse_id,e.sku_id,e.bucket,e.reference,SUM(e.quantity_delta) AS quantity')->group('e.origin_key,e.warehouse_id,e.sku_id,e.bucket,e.reference')->select()->toArray();
        foreach ($rows as $row) {
            $key = FinanceValue::json([$row['origin_key'], $row['warehouse_id'], $row['sku_id'], $row['bucket'], $row['reference']]);
            if (isset($affected[$key]) && bccomp($row['quantity'], '0', 12) > 0) { $pending[$row['bucket']] = true; }
        }
        return $pending;
    }

    private static function money(string $value): string { return bcadd($value, bccomp($value, '0', 6) < 0 ? '-0.005' : '0.005', 2); }

    private static function cash(string $month): array
    {
        $tenant = FinanceAccess::tenant(); $accounts = [];
        foreach (Db::name('finance_account')->where('tenant_id', $tenant)->order('id')->select()->toArray() as $account) {
            $accounts[(int)$account['id']] = ['account_id' => (int)$account['id'], 'name' => $account['name'], 'account_type' => $account['account_type'], 'opening' => '0.00', 'change' => '0.00', 'closing' => '0.00'];
        }
        $opening = Db::name('finance_opening_source')->where('tenant_id', $tenant)->whereIn('category', ['account', 'transit'])->where('activation_date', '<=', date('Y-m-t', strtotime($month . '-01')))->order('id')->select()->toArray();
        $transit = ['opening' => '0.00', 'change' => '0.00', 'closing' => '0.00'];
        foreach ($opening as $source) {
            if ($source['category'] === 'account') { $accounts[(int)$source['subject_id']]['opening'] = bcadd($accounts[(int)$source['subject_id']]['opening'], $source['amount'], 2); }
            else { $transit['opening'] = bcadd($transit['opening'], $source['amount'], 2); }
        }
        $entries = Db::name('finance_entry')->where('tenant_id', $tenant)->whereIn('metric', ['cash', 'transit'])->where('posting_month', '<=', $month)->order('id')->select()->toArray();
        $period = [];
        foreach ($entries as $entry) {
            $bucket = $entry['posting_month'] < $month ? 'opening' : 'change';
            if ($entry['metric'] === 'cash') { $accounts[(int)$entry['subject_id']][$bucket] = bcadd($accounts[(int)$entry['subject_id']][$bucket], $entry['amount'], 2); }
            else { $transit[$bucket] = bcadd($transit[$bucket], $entry['amount'], 2); }
            if ($entry['posting_month'] === $month) { $entry['details'] = FinanceValue::decode($entry['details']); $period[] = $entry; }
        }
        $summary = ['external_in' => '0.00', 'external_out' => '0.00', 'opening_accounts' => '0.00', 'closing_accounts' => '0.00', 'opening_transit' => $transit['opening'], 'closing_transit' => bcadd($transit['opening'], $transit['change'], 2), 'cash_adjustment' => '0.00'];
        foreach ($accounts as &$account) { $account['closing'] = bcadd($account['opening'], $account['change'], 2); $summary['opening_accounts'] = bcadd($summary['opening_accounts'], $account['opening'], 2); $summary['closing_accounts'] = bcadd($summary['closing_accounts'], $account['closing'], 2); } unset($account);
        $ids = array_unique(array_column($period, 'document_id'));
        $documents = $ids ? Db::name('finance_document')->where('tenant_id', $tenant)->whereIn('id', $ids)->column('confirmed_result', 'id') : [];
        $external = []; $internal = []; $adjustments = []; $transferDocuments = [];
        foreach ($period as $entry) {
            $fact = FinanceValue::decode($documents[$entry['document_id']] ?? '{}'); $type = $entry['details']['type'] ?? $fact['type'] ?? '';
            $entry['document_type'] = $type;
            if (in_array($type, ['account_transfer_out', 'account_transfer_arrival', 'account_transfer_return'], true)) {
                if (isset($transferDocuments[$entry['document_id']])) { continue; }
                $transferDocuments[$entry['document_id']] = true;
                $fee = $type === 'account_transfer_out' ? $fact['extra_fee'] : ($fact['withheld_fee'] ?? '0.00');
                $summary['external_out'] = bcadd($summary['external_out'], $fee, 2);
                $internal[] = ['document_id' => (int)$entry['document_id'], 'type' => $type, 'actual_date' => $entry['business_date'], 'posting_month' => $entry['posting_month'], 'principal' => $fact['internal_principal'], 'fee' => $fee, 'entry_id' => (int)$entry['id'], 'transfer_source' => $fact['transfer_source']];
                if (bccomp($fee, '0', 2) > 0) { $external[] = ['document_id' => (int)$entry['document_id'], 'actual_date' => $entry['business_date'], 'amount' => '-' . $fee, 'type' => 'transfer_fee', 'from_transit' => $type === 'account_transfer_arrival']; }
            } else {
                if ($entry['metric'] !== 'cash') { continue; }
                if ($entry['purpose'] !== 'actual_money') { $summary['cash_adjustment'] = bcadd($summary['cash_adjustment'], $entry['amount'], 2); $adjustments[] = $entry; continue; }
                $incoming = bccomp($entry['amount'], '0', 2) >= 0; $field = $incoming ? 'external_in' : 'external_out';
                $summary[$field] = bcadd($summary[$field], $incoming ? $entry['amount'] : bcsub('0', $entry['amount'], 2), 2); $external[] = $entry;
            }
        }
        $summary['external_net'] = bcsub($summary['external_in'], $summary['external_out'], 2);
        $transit['closing'] = $summary['closing_transit'];
        return ['summary' => $summary, 'accounts' => array_values($accounts), 'transit' => $transit, 'opening_sources' => $opening,
            'entries' => $period, 'external_money' => $external, 'internal_transfers' => $internal, 'adjustments' => $adjustments];
    }

    public static function closingBalances(string $month): array
    {
        FinanceAccess::require('', true);
        return self::balances($month, ['receivable', 'advance', 'customer_refund', 'recovery', 'payable', 'supplier_refund', 'expense_payable', 'expense_refund', 'salary', 'reimbursement', 'deferred', 'equipment', 'equipment_refund', 'unclaimed', 'transit']);
    }

    private static function balances(string $month, array $categories): array
    {
        $tenant = FinanceAccess::tenant(); $activation = substr((string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date'), 0, 7);
        $documentMonths = array_column(Db::name('finance_entry')->where('tenant_id', $tenant)->field('document_id,MIN(posting_month) AS month')->group('document_id')->select()->toArray(), 'month', 'document_id');
        $sources = [];
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $prefix => $table) {
            foreach (Db::name($table)->where('tenant_id', $tenant)->whereIn('category', $categories)->order('id')->select()->toArray() as $row) {
                $snapshot = FinanceValue::decode($row[$prefix === 'o' ? 'source_snapshot' : 'snapshot']);
                $posting = $prefix === 'o' ? substr($row['activation_date'], 0, 7) : ($snapshot['posting_month'] ?? max($activation, substr($row['business_date'] ?? $activation, 0, 7), $documentMonths[$row['document_id']] ?? $activation));
                if ($posting > $month) { continue; }
                $ref = $prefix . ':' . $row['id'];
                $sources[$ref] = ['reference' => $ref, 'category' => $row['category'], 'subject_id' => (int)$row['subject_id'], 'subject_name' => $snapshot['subject_name'] ?? '',
                    'document_id' => $prefix === 'o' ? null : (int)$row['document_id'], 'business_date' => $prefix === 'o' ? ($snapshot['historical_date'] ?? null) : $row['business_date'], 'posting_month' => $posting, 'snapshot' => $snapshot,
                    'opening' => $prefix === 'o' || $posting < $month ? $row['amount'] : '0.00', 'new_sources' => $prefix === 'n' && $posting === $month ? $row['amount'] : '0.00', 'entries_change' => '0.00', 'closing' => $row['amount']];
            }
        }
        $entries = $sources ? Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'balance')->whereIn('source_ref', array_keys($sources))->where('posting_month', '<=', $month)->order('id')->select()->toArray() : [];
        $period = [];
        foreach ($entries as $entry) {
            $ref = $entry['source_ref']; $bucket = $entry['posting_month'] < $month ? 'opening' : 'entries_change';
            $sources[$ref][$bucket] = bcadd($sources[$ref][$bucket], $entry['amount'], 2); $sources[$ref]['closing'] = bcadd($sources[$ref]['closing'], $entry['amount'], 2);
            if ($entry['posting_month'] === $month) { $entry['details'] = FinanceValue::decode($entry['details']); $period[] = $entry; }
        }
        $totals = []; $subjects = [];
        foreach ($categories as $category) { $totals[$category] = ['category' => $category, 'opening' => '0.00', 'new_sources' => '0.00', 'entries_change' => '0.00', 'closing' => '0.00']; }
        foreach ($sources as $source) {
            $key = $source['subject_id'] . ':' . $source['category'];
            $subjects[$key] ??= ['subject_id' => $source['subject_id'], 'subject_name' => $source['subject_name'], 'category' => $source['category'], 'opening' => '0.00', 'new_sources' => '0.00', 'entries_change' => '0.00', 'closing' => '0.00'];
            foreach (['opening', 'new_sources', 'entries_change', 'closing'] as $field) {
                $totals[$source['category']][$field] = bcadd($totals[$source['category']][$field], $source[$field], 2); $subjects[$key][$field] = bcadd($subjects[$key][$field], $source[$field], 2);
            }
        }
        return ['categories' => array_values($totals), 'subjects' => array_values($subjects), 'sources' => array_values($sources), 'entries' => $period];
    }

    private static function vendor(string $month, string $cutoff): array
    {
        $result = self::balances($month, ['payable', 'expense_payable', 'supplier_refund', 'expense_refund', 'equipment_refund']);
        $arrivals = [];
        foreach (Db::name('finance_purchase_arrival_line')->where('tenant_id', FinanceAccess::tenant())->where('business_date', '<=', $cutoff)->distinct(true)->column('vendor_id') as $vendor) {
            foreach (FinanceStatementSnapshot::pendingArrivals((int)$vendor, $cutoff) as $arrival) { $arrivals[] = $arrival; }
        }
        return $result + ['pending_arrivals' => $arrivals];
    }

    private static function expense(string $month): array
    {
        $entries = Db::name('finance_entry')->where('tenant_id', FinanceAccess::tenant())->where('metric', 'expense')->where('posting_month', $month)->order('id')->select()->toArray();
        $ids = array_unique(array_column($entries, 'document_id'));
        $documents = $ids ? Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->whereIn('id', $ids)->column('confirmed_result', 'id') : [];
        $categories = []; $total = '0.00';
        foreach ($entries as &$entry) {
            $details = FinanceValue::decode($entry['details']); $entry['details'] = $details;
            // 冲正保留原凭据的类别；当前更正原因仍留在 entry.details 中。
            $seen = [];
            while (!empty($details['original_entry_id']) && !isset($seen[$details['original_entry_id']])) {
                $seen[$details['original_entry_id']] = true;
                $original = Db::name('finance_entry')->where('tenant_id', FinanceAccess::tenant())->where('id', $details['original_entry_id'])->where('metric', 'expense')->find();
                if (!$original) { throw new \DomainException('费用冲正缺少原始分类依据'); }
                $details = FinanceValue::decode($original['details']);
            }
            $entry['classification'] = $details;
            $entry['document'] = FinanceValue::decode($documents[$entry['document_id']] ?? '{}');
            $key = isset($details['category_id']) ? 'category:' . $details['category_id'] : 'system:' . ($details['category'] ?? $entry['purpose']);
            $categories[$key] ??= ['key' => $key, 'category_id' => $details['category_id'] ?? null, 'name' => $details['category_name'] ?? (($details['category'] ?? '') === 'equipment' ? '设备实际支出' : '未分类费用'), 'parent' => $details['parent'] ?? 'other', 'amount' => '0.00'];
            $categories[$key]['amount'] = bcadd($categories[$key]['amount'], $entry['amount'], 2); $total = bcadd($total, $entry['amount'], 2);
        } unset($entry);
        return ['summary' => ['amount' => $total], 'categories' => array_values($categories), 'entries' => $entries,
            'obligations' => self::balances($month, ['expense_payable', 'expense_refund', 'salary', 'reimbursement', 'deferred', 'equipment', 'equipment_refund'])];
    }

    private static function inventory(string $month): array
    {
        $tenant = FinanceAccess::tenant(); $shares = [];
        $opening = Db::name('finance_opening_source')->where('tenant_id', $tenant)->where('category', 'inventory')->where('activation_date', '<=', date('Y-m-t', strtotime($month . '-01')))->order('id')->select()->toArray();
        foreach ($opening as $row) {
            $snapshot = FinanceValue::decode($row['source_snapshot']); $subject = $snapshot['subject_snapshot'];
            $key = 'opening:' . $row['id'] . ':' . $subject['warehouse_id'] . ':' . $subject['sku_id'];
            $shares[$key] = ['origin_key' => 'opening:' . $row['id'], 'warehouse_id' => (int)$subject['warehouse_id'], 'sku_id' => (int)$subject['sku_id'], 'quantity' => $snapshot['details']['quantity'], 'value' => $row['amount']];
        }
        $effects = Db::name('finance_cost_effect')->where('tenant_id', $tenant)->where('posting_month', '<=', $month)->order('id')->select()->toArray();
        $loss = []; $lossTotal = '0.000000';
        foreach ($effects as $effect) {
            if ($effect['bucket'] === 'loss' && $effect['posting_month'] === $month) { $loss[] = $effect; $lossTotal = bcadd($lossTotal, $effect['value_delta'], 6); }
            if ($effect['bucket'] !== 'inventory') { continue; }
            $key = $effect['origin_key'] . ':' . $effect['warehouse_id'] . ':' . $effect['sku_id'];
            $shares[$key] ??= ['origin_key' => $effect['origin_key'], 'warehouse_id' => (int)$effect['warehouse_id'], 'sku_id' => (int)$effect['sku_id'], 'quantity' => '0', 'value' => '0'];
            $shares[$key]['quantity'] = bcadd($shares[$key]['quantity'], $effect['quantity_delta'], 12); $shares[$key]['value'] = bcadd($shares[$key]['value'], $effect['value_delta'], 6);
        }
        $origins = Db::name('finance_cost_origin')->where('tenant_id', $tenant)->column('initial_amount', 'origin_key');
        // 库存补价记入补价月份，历史切片的未知状态必须使用同一时间边界。
        foreach (Db::name('finance_cost_event')->where('tenant_id', $tenant)->whereIn('event_type', ['adjust', 'reestimate'])->where('business_date', '<=', date('Y-m-t', strtotime($month . '-01')))->order('id')->column('snapshot') as $snapshot) {
            $event = FinanceValue::decode($snapshot);
            $origins[$event['origin']] = $event['type'] === 'reestimate' && $event['pending'] ? null : $event['amount'];
        }
        $warehouses = Db::name('warehouse')->where('tenant_id', $tenant)->column('name', 'id');
        $skus = array_column(Db::name('goods_sku')->where('tenant_id', $tenant)->field('id,goods_id,sku_name,base_unit_name')->select()->toArray(), null, 'id');
        $goods = Db::name('goods')->where('tenant_id', $tenant)->column('name', 'id');
        $positions = [];
        foreach ($shares as $share) {
            if (bccomp($share['quantity'], '0', 12) === 0 && bccomp($share['value'], '0', 6) === 0) { continue; }
            $key = $share['warehouse_id'] . ':' . $share['sku_id']; $sku = $skus[$share['sku_id']] ?? [];
            $positions[$key] ??= ['warehouse_id' => $share['warehouse_id'], 'warehouse_name' => $warehouses[$share['warehouse_id']] ?? '原仓库', 'sku_id' => $share['sku_id'], 'goods_name' => $goods[$sku['goods_id'] ?? 0] ?? '原商品', 'sku_name' => $sku['sku_name'] ?? '', 'base_unit_name' => $sku['base_unit_name'] ?? '', 'quantity' => '0', 'known_cost' => '0', 'cost_pending' => false];
            $positions[$key]['quantity'] = bcadd($positions[$key]['quantity'], $share['quantity'], 12); $positions[$key]['known_cost'] = bcadd($positions[$key]['known_cost'], $share['value'], 6);
            if (bccomp($share['quantity'], '0', 12) !== 0 && !str_starts_with($share['origin_key'], 'opening:') && ($origins[$share['origin_key']] ?? null) === null) { $positions[$key]['cost_pending'] = true; }
        }
        foreach ($positions as &$position) { $position['quantity'] = bcadd($position['quantity'], '0', 4); $position['known_cost'] = self::money($position['known_cost']); $position['cost'] = $position['cost_pending'] ? null : $position['known_cost']; } unset($position);
        $pendingLoss = self::pendingCost($month, $loss)['loss'];
        return ['positions' => array_values($positions), 'opening_sources' => $opening, 'shares' => array_values($shares), 'cost_effects' => $effects, 'loss_effects' => $loss,
            'summary' => ['known_loss_cost' => self::money($lossTotal), 'loss_cost' => $pendingLoss ? null : self::money($lossTotal), 'loss_cost_pending' => $pendingLoss]];
    }

    private static function visible(array $report): array
    {
        if (FinanceAccess::has('finance.salary.view')) { return array_replace($report, ['salary_details_visible' => true]); }
        $tenant = FinanceAccess::tenant();
        $documents = array_fill_keys(Db::name('finance_document')->where('tenant_id', $tenant)->whereIn('type', ['salary_expense', 'salary_adjustment', 'salary_payment'])->column('id'), true);
        $sources = [];
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $prefix => $table) {
            foreach (Db::name($table)->where('tenant_id', $tenant)->where('category', 'salary')->column('id') as $id) { $sources[$prefix . ':' . $id] = true; }
        }
        $filter = static function (array $value) use (&$filter, $documents, $sources): ?array {
            if (isset($documents[$value['document_id'] ?? 0]) || isset($sources[$value['source_ref'] ?? $value['reference'] ?? ''])
                || (($value['category'] ?? '') === 'salary' && isset($value['subject_id'])) || ($value['details']['salary_private'] ?? false)) { return null; }
            $result = [];
            foreach ($value as $key => $part) {
                $next = is_array($part) ? $filter($part) : $part;
                if (is_array($part) && $next === null) { continue; }
                $result[$key] = $next;
            }
            return array_is_list($value) ? array_values($result) : $result;
        };
        return array_replace($filter($report), ['salary_details_visible' => false]);
    }
}
