<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 页面和 Word 共用的报货快照；数量只来自报货行，不累加工序任务。 */
final class CustomerReportOverviewLogic extends BaseLogic
{
    private const PENDING = ['submitted_ready', 'submitted_shortage', 'submitted_pending_measurement', 'delivery_pending', 'partially_delivered_pending'];
    private const DELIVERED = ['delivered_pending_settlement', 'settled', 'completed'];
    private const CLOSED_EXCEPTIONS = ['partial_pending_settlement', 'undelivered', 'fulfilled_undelivered'];
    private const STATUS_LABELS = [
        'submitted_ready' => '待交付', 'submitted_shortage' => '有缺货', 'submitted_pending_measurement' => '待称重',
        'delivery_pending' => '待交付', 'partially_delivered_pending' => '部分交付 · 待继续',
        'delivered_pending_settlement' => '已交付', 'settled' => '已交付', 'completed' => '已交付',
        'partial_pending_settlement' => '部分交付', 'undelivered' => '未送达', 'fulfilled_undelivered' => '未送达',
        'cancelled' => '已取消',
    ];

    public static function overview(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.view')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        try {
            $tenantId = (int)(request()->tenantId ?? 0);
            if ($tenantId <= 0 || (isset($params['expected_tenant_id'])
                && (!is_scalar($params['expected_tenant_id']) || !ctype_digit((string)$params['expected_tenant_id']) || (int)$params['expected_tenant_id'] !== $tenantId))) {
                throw new \DomainException('门店已变化，请重新加载报货汇总');
            }
            $query = self::filters($params);
            return Db::transaction(static fn() => self::snapshot($tenantId, $query));
        } catch (\DomainException $error) {
            self::setError($error->getMessage());
            return false;
        }
    }

    public static function exportWord(array $params): array|false
    {
        self::clearError();
        if (!isset($params['expected_tenant_id']) || !is_string($params['snapshot_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $params['snapshot_hash'])) {
            self::setError('请先加载报货汇总，再导出 Word');
            return false;
        }
        $documentType = $params['document_type'] ?? null;
        if (!is_string($documentType) || !isset(CustomerReportWordDocument::TITLES[$documentType])) {
            self::setError('请选择有效的 Word 单据类型');
            return false;
        }
        $snapshot = self::overview($params);
        if ($snapshot === false) { return false; }
        if ($snapshot['query']['status'] === 'cancelled') {
            self::setError('取消记录仅供查询，不支持报货汇总导出');
            return false;
        }
        if (!hash_equals($snapshot['snapshot_hash'], $params['snapshot_hash'])) {
            self::setError('报货内容已变化，请刷新报货汇总后重新导出');
            return false;
        }
        if ($snapshot['summary']['report_count'] === 0) {
            self::setError('当前范围没有报货记录，请调整筛选条件');
            return false;
        }
        return ['tenant_id' => $snapshot['tenant_id'], 'query' => $snapshot['query'], 'snapshot_hash' => $snapshot['snapshot_hash'],
            'document_type' => $documentType,
            'filename' => CustomerReportWordDocument::TITLES[$documentType] . '_' . $snapshot['query']['delivery_date'] . '.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'base64' => base64_encode(CustomerReportWordDocument::create($snapshot, $documentType))];
    }

    private static function filters(array $params): array
    {
        foreach (['delivery_date', 'status', 'keyword'] as $key) {
            if (isset($params[$key]) && !is_string($params[$key])) { throw new \DomainException('筛选条件格式无效'); }
        }
        $date = $params['delivery_date'] ?? date('Y-m-d');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new \DomainException('请选择有效的送货日期'); }
        $status = $params['status'] ?? 'all';
        if (!in_array($status, ['all', 'pending', 'completed', 'cancelled'], true)) { throw new \DomainException('报货状态无效'); }
        $keyword = trim($params['keyword'] ?? '');
        if (mb_strlen($keyword) > 100) { throw new \DomainException('客户名称最多 100 个字'); }
        return ['delivery_date' => $date, 'status' => $status, 'keyword' => $keyword];
    }

    private static function snapshot(int $tenantId, array $filters): array
    {
        $statuses = match ($filters['status']) {
            'pending' => self::PENDING, 'completed' => self::DELIVERED,
            'cancelled' => ['cancelled'], default => [...self::PENDING, ...self::DELIVERED, ...self::CLOSED_EXCEPTIONS],
        };
        $query = Db::name('customer_report')->where('tenant_id', $tenantId)->whereNull('delete_time')
            ->where('delivery_date', $filters['delivery_date'])->whereIn('status', $statuses);
        if ($filters['keyword'] !== '') {
            $pattern = '%' . addcslashes($filters['keyword'], '%_\\') . '%';
            $query->whereIn('id', static function ($subquery) use ($tenantId, $pattern) {
                $subquery->name('customer_report_item')->where('tenant_id', $tenantId)->whereNull('delete_time')
                    ->whereLike('main_customer_name|delivery_customer_name', $pattern)->field('report_id');
            });
        }
        $reports = $query->order('main_customer_id asc,id asc')->limit(501)->select()->toArray();
        if (count($reports) > 500) { throw new \DomainException('本次超过 500 张报货单，请按客户缩小范围后重试'); }
        $ids = array_column($reports, 'id');
        $items = $ids === [] ? [] : Db::name('customer_report_item')->where('tenant_id', $tenantId)
            ->whereIn('report_id', $ids)->whereNull('delete_time')->order('sort asc,id asc')->limit(5001)->select()->toArray();
        if (count($items) > 5000) { throw new \DomainException('本次超过 5000 条报货明细，请按客户缩小范围后重试'); }
        $groups = $ids === [] ? [] : Db::name('customer_report_processing_group')->where('tenant_id', $tenantId)
            ->whereIn('report_id', $ids)->order('sort asc,id asc')->select()->toArray();
        $groupIds = array_column($groups, 'id');
        $processes = $groupIds === [] ? [] : Db::name('customer_report_processing_group_process')->where('tenant_id', $tenantId)
            ->whereIn('processing_group_id', $groupIds)->order('step_no asc,id asc')->select()->toArray();
        $steps = [];
        foreach ($processes as $process) { $steps[$process['processing_group_id']][] = $process['process_name_snapshot']; }
        $groupMap = [];
        foreach ($groups as $group) {
            $groupMap[$group['report_item_id']][] = ['name' => (string)$group['name'], 'quantity' => (string)$group['planned_qty'],
                'processes' => $steps[$group['id']] ?? []];
        }
        $itemMap = [];
        foreach ($items as $item) { $itemMap[$item['report_id']][] = $item; }
        $customers = []; $goods = []; $units = []; $mainIds = []; $reportIds = []; $lineCount = 0;
        foreach ($reports as $report) {
            foreach ($itemMap[$report['id']] ?? [] as $item) {
                $customerKey = $report['main_customer_id'] . ':' . $item['delivery_customer_id'];
                $customers[$customerKey] ??= ['key' => $customerKey, 'main_customer_id' => (int)$report['main_customer_id'],
                    'main_customer_name' => (string)$report['main_customer_name'], 'delivery_customer_id' => (int)$item['delivery_customer_id'],
                    'delivery_customer_name' => (string)$item['delivery_customer_name'], 'reports' => []];
                $customers[$customerKey]['reports'][$report['id']] ??= [
                    'id' => (int)$report['id'], 'sn' => (string)$report['sn'], 'version' => (int)$report['version'],
                    'supplement_for_report_id' => (int)$report['supplement_for_report_id'],
                    'status' => (string)$report['status'], 'status_label' => self::STATUS_LABELS[$report['status']],
                    'cancellation_reason' => (string)($report['cancellation_reason'] ?? ''),
                    'cancelled_by' => (int)($report['cancelled_by'] ?? 0), 'cancelled_time' => (int)($report['cancelled_time'] ?? 0),
                    'remark' => (string)$report['remark'], 'delivery_arrangement' => CustomerReportDeliveryArrangementService::fromRow($report), 'items' => [],
                ];
                $line = self::line($item, $groupMap[$item['id']] ?? []);
                $customers[$customerKey]['reports'][$report['id']]['items'][] = $line;
                $goodsKey = json_encode([$item['goods_id'], $item['sku_id'], $item['unit_id'], $item['unit_name']]);
                $goods[$goodsKey] ??= ['goods_id' => (int)$item['goods_id'], 'goods_name' => (string)$item['goods_name'],
                    'sku_id' => (int)$item['sku_id'], 'sku_name' => (string)$item['sku_name'], 'unit_name' => (string)$item['unit_name'],
                    'quantity' => '0.0000', 'customer_keys' => [], 'processing_summary' => []];
                $goods[$goodsKey]['quantity'] = bcadd($goods[$goodsKey]['quantity'], $line['quantity'], 4);
                $goods[$goodsKey]['customer_keys'][$customerKey] = true;
                foreach ($line['processing_groups'] as $group) {
                    // 同一物理分组的完整工序链只累计一次；多工序不拆成多份需求。
                    $label = $group['processes'] ?: [$group['name']];
                    $processingKey = json_encode($label, JSON_UNESCAPED_UNICODE);
                    $goods[$goodsKey]['processing_summary'][$processingKey] ??= ['processes' => $label, 'quantity' => '0.0000'];
                    $goods[$goodsKey]['processing_summary'][$processingKey]['quantity'] = bcadd(
                        $goods[$goodsKey]['processing_summary'][$processingKey]['quantity'], $group['quantity'], 4
                    );
                }
                $unitKey = $item['unit_name'];
                $units[$unitKey] = bcadd($units[$unitKey] ?? '0', $line['quantity'], 4);
                $mainIds[$report['main_customer_id']] = true; $reportIds[$report['id']] = true; $lineCount++;
            }
        }
        foreach ($customers as &$customer) { $customer['reports'] = array_values($customer['reports']); } unset($customer);
        foreach ($goods as &$good) {
            $good['customer_count'] = count($good['customer_keys']);
            $good['processing_summary'] = array_values($good['processing_summary']);
            unset($good['customer_keys']);
        } unset($good);
        $data = ['tenant_id' => $tenantId, 'store_name' => (string)(Db::name('tenant')->where('id', $tenantId)->value('name') ?: '当前门店'), 'query' => $filters,
            'summary' => ['customer_count' => count($customers), 'main_customer_count' => count($mainIds),
                'report_count' => count($reportIds), 'line_count' => $lineCount, 'unit_totals' => $units],
            'customers' => array_values($customers), 'goods_summary' => array_values($goods)];
        $data['snapshot_hash'] = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $data['generated_at'] = date('Y-m-d H:i:s');
        // 收款可以在汇总查询后发生。只读标记不进入备货 Word 的快照口径。
        $data['settlement_notices'] = self::settlementNotices($tenantId, $reports);
        return $data;
    }

    private static function settlementNotices(int $tenantId, array $reports): array
    {
        $statuses = array_column(array_filter($reports, static fn(array $report): bool => in_array($report['status'], self::DELIVERED, true)), 'status', 'id');
        if (!$statuses) { return []; }
        $canReadPrice = FinanceAccess::has('settlement.view') || FinanceAccess::has('settlement.bill');
        $canReadDebt = FinanceAccess::has('finance.receivable.view');
        if (!$canReadPrice && !$canReadDebt) { return []; }

        $orders = Db::name('sales_order')->where('tenant_id', $tenantId)->where('source_type', 'customer_report')
            ->whereIn('source_id', array_keys($statuses))->field('id,source_id,settlement_status')->select()->toArray();
        $notices = [];
        if ($canReadPrice) {
            $documents = Db::name('customer_report_sales_document')->where('tenant_id', $tenantId)
                ->whereIn('report_id', array_keys($statuses))->column('accounting_status', 'report_id');
            $formal = array_column(array_filter($orders, static fn(array $order): bool => $order['settlement_status'] === 'formal'), 'id', 'source_id');
            foreach ($statuses as $reportId => $status) {
                $pricing = match ($documents[$reportId] ?? null) {
                    'pending_amount' => '待补价',
                    'pending_posting' => '待入账',
                    'review_required' => '金额待复核',
                    null => $status === 'delivered_pending_settlement' && !isset($formal[$reportId]) ? '待补价' : null,
                    default => null,
                };
                if ($pricing !== null) { $notices[$reportId]['pricing'] = $pricing; }
            }
        }
        if ($canReadDebt && $orders) {
            foreach (self::reportsWithOpenReceivables($tenantId, array_column($orders, 'source_id', 'id')) as $reportId) {
                $notices[$reportId]['receipt'] = '仍欠款';
            }
        }
        return $notices;
    }

    private static function reportsWithOpenReceivables(int $tenantId, array $reportByOrder): array
    {
        $orderIds = array_keys($reportByOrder);
        $refs = [];
        $versions = Db::name('finance_sales_version')->where('tenant_id', $tenantId)->whereIn('order_id', $orderIds)
            ->field('source_ref,order_id')->distinct(true)->select()->toArray();
        foreach ($versions as $version) {
            if (preg_match('/^n:[1-9][0-9]*$/D', $version['source_ref'])) {
                $refs[$version['source_ref']] = $reportByOrder[$version['order_id']];
            }
        }

        $documentIds = Db::name('finance_sales_coverage')->where('tenant_id', $tenantId)->whereIn('order_id', $orderIds)
            ->distinct(true)->column('document_id');
        if ($documentIds) {
            $sources = Db::name('finance_source')->where('tenant_id', $tenantId)->where('category', 'receivable')
                ->whereIn('document_id', $documentIds)->field('id,snapshot')->select()->toArray();
            foreach ($sources as $source) {
                // 合并结算可包含多张原单，只保留真正属于这些原单的应收来源。
                $reportId = $reportByOrder[FinanceValue::decode($source['snapshot'])['order_id'] ?? 0] ?? null;
                if ($reportId) { $refs['n:' . $source['id']] = $reportId; }
            }
        }
        if (!$refs) { return []; }

        $amounts = Db::name('finance_source')->where('tenant_id', $tenantId)->where('category', 'receivable')
            ->whereIn('id', array_map(static fn(string $reference): string => substr($reference, 2), array_keys($refs)))->column('amount', 'id');
        $changes = Db::name('finance_entry')->where('tenant_id', $tenantId)->where('metric', 'balance')
            ->whereIn('source_ref', array_keys($refs))->group('source_ref')->field('source_ref,SUM(amount) AS delta')->select()->toArray();
        $changesBySource = array_column($changes, 'delta', 'source_ref');
        $openReports = [];
        foreach ($amounts as $sourceId => $amount) {
            $reference = 'n:' . $sourceId;
            if (bccomp(bcadd((string)$amount, (string)($changesBySource[$reference] ?? '0'), 2), '0', 2) > 0) {
                $openReports[$refs[$reference]] = true;
            }
        }
        return array_keys($openReports);
    }

    private static function line(array $item, array $groups): array
    {
        $pending = ($item['estimate_status'] ?? '') === 'unknown';
        $shortage = $pending ? null : (string)$item['shortage_base_qty'];
        $requirements = trim((string)$item['processing_requirement']);
        foreach (['quality_snapshot' => '品质', 'specification_snapshot' => '规格'] as $field => $label) {
            if (trim((string)$item[$field]) !== '') { $requirements .= ($requirements !== '' ? '；' : '') . $label . ' ' . $item[$field]; }
        }
        if ((int)$item['piece_weight_confirmed'] === 1) {
            $requirements .= ($requirements !== '' ? '；' : '') . '单体重量 '
                . self::weightRange($item['piece_weight_min'], $item['piece_weight_max']);
        }
        if (bccomp((string)($item['acceptable_base_qty_max'] ?? '0'), '0', 4) > 0) {
            $requirements .= ($requirements !== '' ? '；' : '') . '总重范围 '
                . self::weightRange($item['acceptable_base_qty_min'], $item['acceptable_base_qty_max']);
        }
        return ['id' => (int)$item['id'], 'goods_id' => (int)$item['goods_id'], 'sku_id' => (int)$item['sku_id'],
            'unit_id' => (int)$item['unit_id'], 'spec_id' => (int)$item['spec_id'],
            'goods_name' => (string)$item['goods_name'], 'sku_name' => (string)$item['sku_name'],
            'specification' => trim((string)$item['specification_snapshot']),
            'quantity' => (string)$item['order_qty'], 'unit_name' => (string)$item['unit_name'],
            'requirements' => $requirements, 'remark' => (string)$item['line_remark'], 'processing_groups' => $groups,
            'pending_measurement' => $pending, 'shortage_quantity' => $shortage, 'base_unit_name' => (string)$item['base_unit_name'],
            'fulfillment_status' => (string)($item['fulfillment_status'] ?? ''),
            'undelivered_reason' => (string)($item['undelivered_reason'] ?? '')];
    }

    private static function weightRange(string $minimum, string $maximum): string
    {
        $display = static fn(string $value) => str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        return $display($minimum) . '–' . $display($maximum) . ' 斤';
    }
}
