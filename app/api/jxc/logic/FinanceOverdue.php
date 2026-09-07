<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 内部待办只记录系统当时观察与后来核实的事实，不发送客户消息。 */
final class FinanceOverdue
{
    public static function capture(int $tenant, array $customers = [], int $documentId = 0): void
    {
        Db::transaction(static fn() => self::captureWithinTransaction($tenant, $customers, $documentId));
    }

    /** 调用者已持有确认事务时使用，避免重新建立事务边界。 */
    public static function captureWithinTransaction(int $tenant, array $customers = [], int $documentId = 0): void
    {
            (new FinanceLedger($tenant))->lockBook();
            $latest = self::latestQuery($tenant);
            if ($customers) { $latest->whereIn('customer_id', $customers); }
            $previous = array_column($latest->select()->toArray(), null, 'source_ref');
            $changes = Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'balance')->field('source_ref,SUM(amount) AS delta')->group('source_ref')->buildSql();
            $documentType = $documentId ? (string)Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $documentId)->value('type') : '';
            $timing = [];
            if ($documentId) {
                foreach (Db::name('finance_entry')->where('tenant_id', $tenant)->where('document_id', $documentId)->where('metric', 'balance')->order('id')->select()->toArray() as $entry) {
                    $timing[$entry['source_ref']][] = ['entry_id' => (int)$entry['id'], 'amount' => ltrim($entry['amount'], '-'), 'change' => $entry['amount'],
                        'business_date' => $entry['business_date'], 'effective_date' => $entry['effective_date'], 'purpose' => $entry['purpose']];
                }
            }
            foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
                $query = Db::name($table)->alias('s')->leftJoin([$changes => 'b'], "b.source_ref=CONCAT('{$kind}:',s.id)")
                    ->where('s.tenant_id', $tenant)->where('s.category', 'receivable');
                if ($customers) { $query->whereIn('s.subject_id', $customers); }
                $after = 0;
                do {
                    $batch = (clone $query)->where('s.id', '>', $after)->field('s.*,s.amount+COALESCE(b.delta,0) AS balance')->order('s.id')->limit(500)->select()->toArray();
                    $refs = array_map(static fn(array $row): string => $kind . ':' . $row['id'], $batch);
                    $dues = FinanceDueDates::forSources($tenant, $refs);
                    foreach ($batch as $row) {
                        $after = (int)$row['id']; $ref = $kind . ':' . $row['id']; $snapshot = FinanceValue::decode($row[$kind === 'o' ? 'source_snapshot' : 'snapshot']);
                        $due = isset($dues[$ref]) ? $dues[$ref]['new_due_date'] : ($kind === 'o' ? ($snapshot['due_date'] ?? null) : $row['due_date']);
                        $revision = (int)($dues[$ref]['id'] ?? 0); $isOpen = $due && $due < date('Y-m-d') && bccomp($row['balance'], '0', 2) > 0;
                        $state = $isOpen ? 'open' : 'closed'; $old = $previous[$ref] ?? null;
                        if (!$old && !$isOpen) { continue; }
                        $unchanged = $old && $old['state'] === $state && $old['due_date'] === $due && (int)$old['due_revision'] === $revision && bccomp($old['balance'], $row['balance'], 2) === 0;
                        if ($unchanged && (!$documentId || empty($timing[$ref]) || (int)$old['document_id'] === $documentId)) { continue; }
                        Db::name('finance_overdue_event')->insert(['tenant_id' => $tenant, 'customer_id' => $row['subject_id'], 'source_ref' => $ref,
                            'customer_name' => $snapshot['subject_name'] ?? '', 'business_date' => $kind === 'o' ? ($snapshot['historical_date'] ?? null) : $row['business_date'],
                            'due_date' => $due, 'due_revision' => $revision, 'balance' => $row['balance'], 'state' => $state, 'observed_date' => date('Y-m-d'),
                            'document_id' => $documentId, 'document_type' => $documentType, 'timing' => FinanceValue::json($timing[$ref] ?? []), 'create_time' => time()]);
                    }
                } while (count($batch) === 500);
            }
    }

    public static function lists(array $params): array
    {
        FinanceAccess::require('finance.receivable.view'); $tenant = FinanceAccess::tenant(); $customer = FinanceValue::id($params['customer_id'] ?? 0, true);
        self::capture($tenant, $customer ? [$customer] : []);
        $query = self::latestQuery($tenant)->where('state', 'open');
        if ($customer) { $query->where('customer_id', $customer); }
        return self::page($query, $params);
    }

    public static function history(array $params): array
    {
        FinanceAccess::require('finance.receivable.view'); $tenant = FinanceAccess::tenant();
        $source = (new FinanceLedger($tenant))->source(FinanceValue::text($params['source'] ?? '', 40));
        if ($source['category'] !== 'receivable') { throw new \DomainException('只能查看本门店正式应收的逾期历史'); }
        self::capture($tenant, [$source['subject_id']]);
        return self::page(Db::name('finance_overdue_event')->where('tenant_id', $tenant)->where('source_ref', $source['reference']), $params) + ['source' => $source];
    }

    private static function latestQuery(int $tenant): \think\db\Query
    {
        $latest = Db::name('finance_overdue_event')->where('tenant_id', $tenant)->field('MAX(id) AS id')->group('source_ref')->buildSql();
        return Db::name('finance_overdue_event')->where('tenant_id', $tenant)->whereRaw('id IN ' . $latest);
    }

    private static function page(\think\db\Query $query, array $params): array
    {
        $page = FinanceValue::id($params['page'] ?? 1); $rows = $query->order('id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray();
        $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id']; $row['customer_id'] = (int)$row['customer_id']; $row['timing'] = FinanceValue::decode($row['timing']);
            $row['observed_days'] = $row['due_date'] && $row['due_date'] < $row['observed_date'] ? (int)(new \DateTimeImmutable($row['due_date']))->diff(new \DateTimeImmutable($row['observed_date']))->days : 0;
            $row['current_days'] = $row['state'] === 'open' ? (int)(new \DateTimeImmutable($row['due_date']))->diff(new \DateTimeImmutable(date('Y-m-d')))->days : 0;
        }
        return ['tenant_id' => FinanceAccess::tenant(), 'lists' => $rows, 'has_more' => $more, 'as_of' => date('Y-m-d H:i:s')];
    }
}
