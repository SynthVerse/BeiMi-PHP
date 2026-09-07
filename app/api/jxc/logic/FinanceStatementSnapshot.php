<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 生成时冻结已知业务；对账期间不改变分录的财务归属月份。 */
final class FinanceStatementSnapshot
{
    public static function capture(int $customer, string $from, string $to, bool $supplier = false): array
    {
        $tenant = FinanceAccess::tenant(); $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        if ($from < $activation || $to > date('Y-m-d') || $from > $to) { throw new \DomainException('对账期间须在启用日至今天之间，起始日不能晚于截止日'); }
        $categories = $supplier ? ['payable', 'expense_payable', 'supplier_refund', 'expense_refund'] : ['receivable', 'advance', 'customer_refund', 'recovery']; $sources = []; $balances = []; $movements = [];
        foreach ($categories as $category) { $balances[$category] = ['opening' => '0.00', 'change' => '0.00', 'closing' => '0.00']; }
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            $rows = Db::name($table)->where('tenant_id', $tenant)->where('subject_id', $customer)->whereIn('category', $categories)->order('id')->limit(5001)->select()->toArray();
            if (count($rows) > 5000) { throw new \DomainException('客户来源超过单次对账容量，请联系管理员分段归档后处理'); }
            $references = array_map(static fn(array $row): string => $kind . ':' . $row['id'], $rows);
            $dues = FinanceDueDates::forSources($tenant, $references); $revisions = FinanceAdvanceRevisions::latest($tenant, $references);
            foreach ($rows as $row) {
                $ref = $kind . ':' . $row['id']; $revision = $revisions[$ref] ?? null;
                $date = $kind === 'o' ? $activation : ($revision['new_business_date'] ?? $row['business_date'] ?? $activation);
                if ($date > $to) { continue; }
                $data = FinanceValue::decode($row[$kind === 'o' ? 'source_snapshot' : 'snapshot']);
                $amount = $revision['new_amount'] ?? $row['amount'];
                $source = ['reference' => $ref, 'category' => $row['category'], 'subject_id' => (int)$row['subject_id'], 'snapshot' => $data,
                    'document_id' => $kind === 'o' ? null : (int)$row['document_id'], 'business_date' => $kind === 'o' ? ($data['historical_date'] ?? null) : $date,
                    'confirmed_amount' => $amount, 'original_amount' => $row['amount'], 'advance_revision' => (int)($revision['id'] ?? 0),
                    'due_date' => isset($dues[$ref]) ? $dues[$ref]['new_due_date'] : ($kind === 'o' ? ($data['due_date'] ?? null) : $row['due_date'])];
                $sources[$ref] = $source + ['opening' => '0.00', 'change' => '0.00', 'closing' => '0.00', 'reply_limit' => $amount];
                // 期初是启用日前截点承接，不因历史日期未知变成期间新增应收。
                $bucket = $kind === 'o' || $date < $from ? 'opening' : 'change';
                self::add($sources[$ref], $balances[$row['category']], $bucket, $amount);
                if ($bucket === 'change') { $movements[] = ['key' => $ref, 'source' => $ref, 'category' => $row['category'], 'date' => $date, 'amount' => $amount, 'purpose' => 'source', 'document_id' => $source['document_id']]; }
            }
        }
        if ($sources) {
            $entries = Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'balance')->whereIn('source_ref', array_keys($sources))->order('id')->limit(20001)->select()->toArray();
            if (count($entries) > 20000) { throw new \DomainException('客户分录超过单次对账容量，请联系管理员分段归档后处理'); }
            foreach ($entries as $entry) {
                if ($entry['purpose'] === 'advance_revision' && $sources[$entry['source_ref']]['advance_revision']) { continue; }
                $date = $entry['effective_date'] ?? $entry['business_date'] ?? $activation;
                // 日期不详的期初贷项可能抵扣后月应付，原启用日不能使核销提前进入旧期快照。
                $datePrecision = 'day';
                if ($supplier && $entry['effective_date'] === null && $date < $entry['posting_month'] . '-01') {
                    $date = $entry['posting_month'] . '-01'; $datePrecision = 'month';
                }
                if ($date > $to) { continue; }
                $ref = $entry['source_ref']; $category = $sources[$ref]['category']; $bucket = $date < $from ? 'opening' : 'change';
                if (bccomp($entry['amount'], '0', 2) > 0) { $sources[$ref]['reply_limit'] = bcadd($sources[$ref]['reply_limit'], $entry['amount'], 2); }
                self::add($sources[$ref], $balances[$category], $bucket, $entry['amount']);
                if ($bucket === 'change') { $movements[] = ['key' => 'e:' . $entry['id'], 'source' => $ref, 'category' => $category, 'date' => $datePrecision === 'month' ? substr($date, 0, 7) : $date,
                    'date_precision' => $datePrecision, 'effective_date' => $entry['effective_date'],
                    'amount' => $entry['amount'], 'purpose' => $entry['purpose'], 'document_id' => (int)$entry['document_id'], 'posting_month' => $entry['posting_month']]; }
            }
        }
        foreach ($sources as &$source) { unset($source['balance']); }
        $documents = array_values(array_unique(array_filter(array_column($movements, 'document_id'))));
        $types = $documents ? Db::name('finance_document')->where('tenant_id', $tenant)->whereIn('id', $documents)->column('type', 'id') : [];
        foreach ($movements as &$movement) { $movement['document_type'] = $types[$movement['document_id']] ?? ''; }
        return ['generated_at' => date('Y-m-d H:i:s'), 'balances' => $balances, 'sources' => array_values($sources), 'movements' => $movements,
            'actual_money' => self::actualMoney($customer, $from, $to, $supplier),
            'pending_deliveries' => $supplier ? [] : self::pendingDeliveries($customer, $from, $to),
            'pending_arrivals' => $supplier ? self::pendingArrivals($customer, $to) : [],
            'net_reference' => $supplier
                ? bcsub(bcsub(bcadd($balances['payable']['closing'], $balances['expense_payable']['closing'], 2), $balances['supplier_refund']['closing'], 2), $balances['expense_refund']['closing'], 2)
                : bcsub(bcsub($balances['receivable']['closing'], $balances['advance']['closing'], 2), $balances['customer_refund']['closing'], 2)];
    }

    private static function actualMoney(int $customer, string $from, string $to, bool $supplier): array
    {
        $tenant = FinanceAccess::tenant();
        $replaced = Db::name('finance_correction')->where('tenant_id', $tenant)->field('original_document_id')->buildSql();
        $rows = Db::name('finance_entry')->alias('e')->join('finance_document d', 'd.id=e.document_id AND d.tenant_id=e.tenant_id')
            ->where('e.tenant_id', $tenant)->where('e.metric', 'cash')->whereIn('e.purpose', ['actual_money', 'correction_replacement'])
            ->whereBetween('e.business_date', [$from, $to])->where('d.status', 'confirmed')
            ->whereIn('d.type', $supplier ? ['supplier_payment', 'supplier_refund', 'expense_refund'] : ['receipt', 'receipt_return', 'advance_refund', 'customer_refund', 'recovery_receipt'])
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(d.payload,'$.subject_id')) AS UNSIGNED)=?", [$customer])
            ->whereRaw('d.id NOT IN ' . $replaced)->field('e.id,e.document_id,e.amount,e.business_date,e.details,d.type,d.confirmed_result')->order('e.business_date,e.id')->limit(5001)->select()->toArray();
        if (count($rows) > 5000) { throw new \DomainException('期间收退款过多，请缩短对账期间'); }
        $result = [];
        foreach ($rows as $row) {
            $money = FinanceValue::decode($row['confirmed_result'])['money'] ?? [];
            $result[] = ['id' => (int)$row['id'], 'document_id' => (int)$row['document_id'], 'type' => $row['type'], 'amount' => $row['amount'],
                'actual_date' => $row['business_date'], 'transaction_id' => FinanceValue::decode($row['details'])['transaction_id'] ?? null, 'account' => $money['account'] ?? ''];
        }
        return $result;
    }

    private static function add(array &$source, array &$category, string $bucket, string $amount): void
    {
        $source[$bucket] = bcadd($source[$bucket], $amount, 2); $source['closing'] = bcadd($source['closing'], $amount, 2);
        $category[$bucket] = bcadd($category[$bucket], $amount, 2); $category['closing'] = bcadd($category['closing'], $amount, 2);
    }

    private static function pendingDeliveries(int $customer, string $from, string $to): array
    {
        return FinanceDeliveries::rows($customer, $from, $to);
    }

    public static function pendingArrivals(int $vendor, string $to): array
    {
        $tenant = FinanceAccess::tenant();
        $covered = FinancePurchaseCoverage::sql($to);
        $rows = Db::name('finance_purchase_arrival_line')->alias('a')->leftJoin([$covered => 's'], 's.arrival_line_id=a.id')
            ->where('a.tenant_id', $tenant)->where('a.vendor_id', $vendor)->where('a.business_date', '<=', $to)
            ->whereRaw('a.actual_quantity>COALESCE(s.quantity,0)')->field('a.*,COALESCE(s.quantity,0) AS covered_quantity')->order('a.business_date,a.id')->limit(5001)->select()->toArray();
        if (count($rows) > 5000) { throw new \DomainException('待结算到货超过单次对账容量，请联系管理员处理'); }
        foreach ($rows as &$row) {
            $row['snapshot'] = FinanceValue::decode($row['snapshot']);
            $row['pending_quantity'] = bcsub($row['actual_quantity'], $row['covered_quantity'], 4);
        }
        return $rows;
    }
}
