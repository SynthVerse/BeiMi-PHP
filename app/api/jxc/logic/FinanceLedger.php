<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 只供已验证的业务确认调用；接口不接受自由填写的余额影响。 */
final class FinanceLedger
{
    public function __construct(private readonly int $tenantId) {}

    public function lockBook(): array
    {
        Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->lock(true)->find();
        $book = Db::name('finance_opening_book')->where('tenant_id', $this->tenantId)->find();
        if (!$book || $book['status'] !== 'active') { throw new \DomainException('请先完成财务期初启用，再确认正式财务业务'); }
        return FinanceValue::decode($book['confirmed_snapshot']);
    }

    public function postingMonth(string $businessDate): string
    {
        $date = FinanceValue::date($businessDate);
        $activation = Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->value('activation_date');
        if (!$activation || $date < $activation) { throw new \DomainException('启用前业务应使用合法期初来源，不能重复登记新业务'); }
        if ($date > date('Y-m-d')) { throw new \DomainException('不能确认尚未发生的未来业务'); }
        $month = substr($date, 0, 7);
        if (Db::name('finance_period')->where('tenant_id', $this->tenantId)->where('month', $month)->count()) { $month = date('Y-m'); }
        if (Db::name('finance_period')->where('tenant_id', $this->tenantId)->where('month', $month)->count()) { throw new \DomainException('当前自然月已结账，不能写入或任意选择其他月份'); }
        return $month;
    }

    public function source(string $reference): array
    {
        if (!preg_match('/^([on]):([1-9][0-9]{0,17})$/D', $reference, $match)) { throw new \DomainException('未结来源标识无效'); }
        $opening = $match[1] === 'o';
        $row = Db::name($opening ? 'finance_opening_source' : 'finance_source')->where('tenant_id', $this->tenantId)->where('id', $match[2])->find();
        if (!$row) { throw new \DomainException('未结来源不存在或不属于本门店'); }
        $snapshot = FinanceValue::decode($row[$opening ? 'source_snapshot' : 'snapshot']);
        $change = (string)(Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('source_ref', $reference)->where('metric', 'balance')->sum('amount') ?: '0');
        return ['reference' => $reference, 'category' => $row['category'], 'subject_id' => (int)$row['subject_id'],
            'subject_name' => $snapshot['subject_name'] ?? '', 'confirmed_amount' => $row['amount'],
            'balance' => bcadd($row['amount'], $change, 2), 'business_date' => $opening ? ($snapshot['historical_date'] ?? null) : $row['business_date'],
            'due_date' => $opening ? ($snapshot['due_date'] ?? null) : $row['due_date'], 'snapshot' => $snapshot,
            'document_id' => $opening ? null : (int)$row['document_id']];
    }

    public function sources(string $category, int $subjectId = 0): array
    {
        $sources = [];
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $prefix => $table) {
            $query = Db::name($table)->where('tenant_id', $this->tenantId)->where('category', $category);
            if ($subjectId) { $query->where('subject_id', $subjectId); }
            foreach ($query->order('id')->column('id') as $id) { $sources[] = $this->source($prefix . ':' . $id); }
        }
        return $sources;
    }

    public function account(int $id, bool $requireEnabled = true): array
    {
        $account = Db::name('finance_account')->where('tenant_id', $this->tenantId)->where('id', $id)->find();
        if (!$account || ($requireEnabled && !(int)$account['is_enabled'])) { throw new \DomainException('资金账户不存在、已停用或不属于本门店'); }
        $opening = (string)(Db::name('finance_opening_source')->where('tenant_id', $this->tenantId)->where('category', 'account')->where('subject_id', $id)->sum('amount') ?: '0');
        $change = (string)(Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('metric', 'cash')->where('subject_id', $id)->sum('amount') ?: '0');
        $account['balance'] = bcadd($opening, $change, 2);
        return $account;
    }

    public function sourcePage(array $categories, int $subjectId, int $page, bool $openingOnly = false): array
    {
        $changes = Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('metric', 'balance')
            ->field('source_ref,SUM(amount) AS delta')->group('source_ref')->buildSql();
        $parts = [];
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            if ($openingOnly && $kind !== 'o') { continue; }
            $query = Db::name($table)->alias('s')->leftJoin([$changes => 'b'], "b.source_ref=CONCAT('{$kind}:',s.id)")
                ->where('s.tenant_id', $this->tenantId)->whereIn('s.category', $categories);
            if (!$openingOnly) { $query->whereRaw('s.amount+COALESCE(b.delta,0)>0'); }
            if ($subjectId) { $query->where('s.subject_id', $subjectId); }
            $parts[] = $query->field("'{$kind}' AS source_kind,s.id AS source_id,CONCAT('{$kind}:',s.id) AS reference,s.category,s.subject_id,s.amount AS confirmed_amount,s.amount+COALESCE(b.delta,0) AS balance," .
                ($kind === 'o' ? 's.source_snapshot AS snapshot,NULL AS business_date,NULL AS due_date,NULL AS document_id' : 's.snapshot,s.business_date,s.due_date,s.document_id'))->buildSql();
        }
        $rows = Db::query(implode(' UNION ALL ', $parts) . ' ORDER BY source_kind,source_id LIMIT ' . (($page - 1) * 20) . ',21');
        $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
        foreach ($rows as &$row) {
            $row['snapshot'] = FinanceValue::decode($row['snapshot']); $row['subject_id'] = (int)$row['subject_id'];
            $row['subject_name'] = $row['snapshot']['subject_name'] ?? '';
            if ($row['source_kind'] === 'o') { $row['business_date'] = $row['snapshot']['historical_date'] ?? null; $row['due_date'] = $row['snapshot']['due_date'] ?? null; }
            unset($row['source_kind'], $row['source_id']);
        }
        return ['sources' => $rows, 'has_more' => $more];
    }

    public function categoryBalance(string $category, int $subjectId): string
    {
        $changes = Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('metric', 'balance')
            ->field('source_ref,SUM(amount) AS delta')->group('source_ref')->buildSql();
        $total = '0.00';
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            $row = Db::name($table)->alias('s')->leftJoin([$changes => 'b'], "b.source_ref=CONCAT('{$kind}:',s.id)")
                ->where('s.tenant_id', $this->tenantId)->where('s.category', $category)->where('s.subject_id', $subjectId)
                ->field('COALESCE(SUM(s.amount+COALESCE(b.delta,0)),0) AS balance')->find();
            $total = bcadd($total, (string)$row['balance'], 2);
        }
        return $total;
    }

    public function createSource(int $documentId, string $category, int $subjectId, string $amount, ?string $date, ?string $dueDate, array $snapshot): string
    {
        $id = Db::name('finance_source')->insertGetId(['tenant_id' => $this->tenantId, 'document_id' => $documentId,
            'category' => $category, 'subject_id' => $subjectId, 'amount' => FinanceValue::money($amount),
            'business_date' => $date, 'due_date' => $dueDate, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return 'n:' . $id;
    }

    public function add(int $documentId, string $metric, int $subjectId, string $amount, string $date, string $month, string $purpose, string $source = '', ?string $effectiveDate = null, array $details = []): void
    {
        if (!in_array($metric, ['balance', 'cash', 'revenue', 'expense', 'cost', 'loss', 'recovery_income', 'transit'], true)) { throw new \LogicException('Invalid financial metric'); }
        $amount = FinanceValue::money($amount, true, true);
        if (bccomp($amount, '0', 2) === 0) { return; }
        if ($metric === 'balance') {
            $current = $this->source($source);
            if (bccomp(bcadd($current['balance'], $amount, 2), '0', 2) < 0) { throw new \DomainException('所选来源余额已变化，本次金额超过当前未结余额'); }
        }
        Db::name('finance_entry')->insert(['tenant_id' => $this->tenantId, 'document_id' => $documentId,
            'source_ref' => $source, 'metric' => $metric, 'purpose' => $purpose, 'subject_id' => $subjectId,
            'amount' => $amount, 'business_date' => $date, 'effective_date' => $effectiveDate, 'posting_month' => $month,
            'details' => FinanceValue::json($details), 'create_time' => time()]);
    }

    /** 核销的日期与入账期间分开保存，期初日期未知时不伪造及时付款结论。 */
    public function allocationTiming(array $source, ?string $receiptDate): array
    {
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->value('activation_date');
        $effective = $receiptDate && $source['business_date'] ? max($receiptDate, $source['business_date']) : null;
        $date = $receiptDate ?? $activation;
        return ['date' => $date, 'effective_date' => $effective, 'month' => $this->postingMonth(max($effective ?? $date, $activation))];
    }

    public function allocate(int $documentId, array $lines, array $categories, int $subjectId, string $date, string $month, bool $unknownReceiptDate = false): string
    {
        if (!array_is_list($lines) || count($lines) > 200) { throw new \DomainException('核销组成须为不超过200项的明细'); }
        $total = '0.00'; $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) { throw new \DomainException('核销组成格式无效'); }
            $reference = FinanceValue::text($line['source'] ?? '', 40);
            if (isset($seen[$reference])) { throw new \DomainException('同一来源不能在组成中重复选择'); }
            $seen[$reference] = true;
            $amount = FinanceValue::money($line['amount'] ?? null);
            $source = $this->source($reference);
            if (!in_array($source['category'], $categories, true) || $source['subject_id'] !== $subjectId) { throw new \DomainException('核销来源业务类型或往来主体不匹配'); }
            $timing = $this->allocationTiming($source, $unknownReceiptDate ? null : $date);
            $this->add($documentId, 'balance', $subjectId, '-' . $amount, $timing['date'], $timing['month'], 'allocation', $reference, $timing['effective_date']);
            $total = bcadd($total, $amount, 2);
        }
        return $total;
    }
}
