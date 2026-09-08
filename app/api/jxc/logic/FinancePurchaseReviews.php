<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 到货差异独立复核；未查明和异常损耗待处理不自动变成完好库存成本。 */
final class FinancePurchaseReviews
{
    public static function latest(int $arrival): ?array
    {
        return Db::name('finance_purchase_difference_review')->where('tenant_id', FinanceAccess::tenant())->where('arrival_line_id', $arrival)->order('id', 'desc')->lock(true)->find();
    }

    public static function pending(array $arrival): bool
    {
        $snapshot = FinanceValue::decode($arrival['snapshot']);
        if (bccomp($snapshot['arrival_difference'] ?? '0', '0', 4) === 0) { return false; }
        $review = self::latest((int)$arrival['id']);
        return $review !== null && !(bool)$review['resolved'];
    }

    public static function options(int $vendor, array $params, bool $onlyLoss = false): array
    {
        $tenant = FinanceAccess::tenant(); $page = max(1, FinanceValue::id($params['page'] ?? 1));
        $latest = Db::name('finance_purchase_difference_review')->where('tenant_id', $tenant)->field('arrival_line_id,MAX(id) AS id')->group('arrival_line_id')->buildSql();
        $query = Db::name('finance_purchase_arrival_line')->alias('a')->leftJoin([$latest => 'r'], 'r.arrival_line_id=a.id')
            ->leftJoin('finance_purchase_difference_review d', 'd.id=r.id AND d.tenant_id=a.tenant_id')
            ->where('a.tenant_id', $tenant)->where('a.vendor_id', $vendor)
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(a.snapshot,'$.arrival_difference')) AS DECIMAL(18,4))<>0")
            ->whereRaw('(d.id IS NULL OR d.resolved=0)');
        if ($onlyLoss) {
            $query->whereIn('d.classification', ['loss', 'dispute'])->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(a.snapshot,'$.arrival_difference')) AS DECIMAL(18,4))<0");
            if (!empty($params['arrival_line_id'])) { $query->where('a.id', FinanceValue::id($params['arrival_line_id'])); }
        }
        $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        if ($keyword !== '') { $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(a.snapshot,'$.goods_name')) LIKE ?", ['%' . addcslashes($keyword, '%_\\') . '%']); }
        $rows = $query->field('a.*,d.id AS review_id,d.classification,d.snapshot AS review_snapshot')->order('a.business_date,a.id')->limit(($page - 1) * 20, 21)->select()->toArray();
        $more = count($rows) > 20; $arrivals = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $basis = FinanceValue::decode($row['snapshot']);
            $arrivals[] = array_merge($basis, ['arrival_line_id' => (int)$row['id'], 'arrival_document_id' => (int)$row['document_id'],
                'subject_id' => $vendor, 'actual_date' => $row['business_date'], 'warehouse_id' => (int)$row['warehouse_id'],
                'warehouse_name' => $basis['warehouse_name'] ?? (Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $row['warehouse_id'])->value('name') ?: '名称未留存'),
                'expected_review_id' => (int)($row['review_id'] ?? 0), 'classification' => $row['classification'] ?? '',
                'assessment' => self::assessment($row), 'latest_review' => $row['review_snapshot'] ? FinanceValue::decode($row['review_snapshot']) : null]);
        }
        return ['sources' => [], 'has_more' => false, 'arrivals' => $arrivals, 'arrival_has_more' => $more, 'can_review_escalation' => FinanceAccess::owner()];
    }

    public static function assessment(array $arrival): array
    {
        $basis = FinanceValue::decode($arrival['snapshot']);
        return FinancePurchaseDifference::assess($basis['arrival_difference'] ?? '0', $basis['reported_quantity'] ?? $arrival['actual_quantity'], $basis['difference_rule'] ?? null);
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $tenant = FinanceAccess::tenant(); $vendor = FinanceValue::id($data['subject_id'] ?? null); $id = FinanceValue::id($data['arrival_line_id'] ?? null);
        $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('vendor_id', $vendor)->where('id', $id)->lock(true)->find();
        if (!$arrival) { throw new \DomainException('到货来源不存在或不属于本供应商'); }
        $assessment = self::assessment($arrival);
        if (!$assessment['requires_confirmation']) { throw new \DomainException('本到货没有需要复核的非零重量差'); }
        $old = self::latest($id);
        if (FinanceValue::id($data['expected_review_id'] ?? null, true) !== (int)($old['id'] ?? 0)) { throw new \DomainException('到货差已有新的复核，请重新读取待办'); }
        if ($old && (bool)$old['resolved']) { throw new \DomainException('已完成复核须通过关联异常处理保留原结论，不能覆盖历史'); }
        $class = FinanceValue::text($data['classification'] ?? null, 20);
        if (!in_array($class, ['normal', 'supplier', 'dispute', 'loss'], true)) { throw new \DomainException('请选择有效的到货差分类'); }
        if ($assessment['requires_owner'] || in_array($class, ['loss', 'dispute'], true)) { FinanceAccess::require('', true); }
        if (($data['review_confirmed'] ?? null) !== 1) { throw new \DomainException('非零到货差须由人员明确复核，不能自动结案'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        $responsibility = FinanceValue::text($data['responsibility'] ?? '', 1000, in_array($class, ['loss', 'dispute'], true));
        $result = self::append($arrival, $document, $assessment + ['classification' => $class, 'reason' => $reason, 'responsibility' => $responsibility,
            'actor' => FinanceAccess::actor(), 'confirmed_at' => time()]);
        $cost = FinancePurchaseCosts::revalue($arrival, $document, $result);
        $basis = FinanceValue::decode($arrival['snapshot']);
        $line = array_merge($basis, $result, $cost, ['arrival_line_id' => $id, 'warehouse_id' => (int)$arrival['warehouse_id'], 'actual_date' => $arrival['business_date']]);
        return $line + ['type' => 'purchase_difference', 'subject_id' => $vendor,
            'subject_name' => Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name'),
            'created_sources' => [], 'posting_months' => [$ledger->postingMonth($arrival['business_date'])], 'lines' => [$line]];
    }

    public static function append(array $arrival, array $document, array $review, bool $lossConfirmed = false): array
    {
        if ($lossConfirmed) {
            FinanceAccess::require('', true);
            if ($document['type'] !== 'purchase_arrival_loss' || $review['classification'] !== 'loss') { throw new \DomainException('异常损耗须通过关联损失确认结案'); }
        }
        $resolved = $lossConfirmed || in_array($review['classification'], ['normal', 'supplier'], true);
        $review += ['resolved' => $resolved, 'previous_review_id' => (int)(self::latest((int)$arrival['id'])['id'] ?? 0)];
        $id = (int)Db::name('finance_purchase_difference_review')->insertGetId(['tenant_id' => FinanceAccess::tenant(), 'document_id' => $document['id'],
            'arrival_line_id' => $arrival['id'], 'classification' => $review['classification'], 'resolved' => $resolved ? 1 : 0,
            'snapshot' => FinanceValue::json($review), 'create_time' => time()]);
        return $review + ['review_id' => $id];
    }

    public static function reauthorize(array $result): void
    {
        if (!empty($result['requires_owner']) || in_array($result['classification'] ?? '', ['loss', 'dispute'], true)) { FinanceAccess::require('', true); }
    }
}
