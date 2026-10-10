<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 页面和 Word 共用的报货快照；数量只来自报货行，不累加工序任务。 */
final class CustomerReportOverviewLogic extends BaseLogic
{
    private const STATUSES = ['submitted_ready', 'submitted_shortage', 'submitted_pending_measurement', 'completed'];
    private const STATUS_LABELS = ['submitted_ready' => '已报货', 'submitted_shortage' => '有缺货',
        'submitted_pending_measurement' => '待称重', 'completed' => '已完成', 'cancelled' => '已取消'];

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
                throw new \DomainException('门店已变化，请重新加载报货总览');
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
            self::setError('请先加载报货总览，再导出 Word');
            return false;
        }
        $snapshot = self::overview($params);
        if ($snapshot === false) { return false; }
        if ($snapshot['query']['status'] === 'cancelled') {
            self::setError('取消记录仅供查询，不支持备货汇总导出');
            return false;
        }
        if (!hash_equals($snapshot['snapshot_hash'], $params['snapshot_hash'])) {
            self::setError('报货内容已变化，请刷新总览后重新导出');
            return false;
        }
        if ($snapshot['summary']['report_count'] === 0) {
            self::setError('当前范围没有报货记录，请调整筛选条件');
            return false;
        }
        return ['tenant_id' => $snapshot['tenant_id'], 'query' => $snapshot['query'], 'snapshot_hash' => $snapshot['snapshot_hash'],
            'filename' => '客户报货总览_' . $snapshot['query']['delivery_date'] . '.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'base64' => base64_encode(CustomerReportWordDocument::create($snapshot))];
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
            'pending' => array_slice(self::STATUSES, 0, 3), 'completed' => ['completed'],
            'cancelled' => ['cancelled'], default => self::STATUSES,
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
        return $data;
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
            $requirements .= ($requirements !== '' ? '；' : '') . '单体重量 ' . $item['piece_weight_min'] . '–' . $item['piece_weight_max'] . '斤';
        }
        if (bccomp((string)($item['acceptable_base_qty_max'] ?? '0'), '0', 4) > 0) {
            $requirements .= ($requirements !== '' ? '；' : '') . '总重范围 ' . $item['acceptable_base_qty_min'] . '–' . $item['acceptable_base_qty_max'] . '斤';
        }
        return ['id' => (int)$item['id'], 'goods_name' => (string)$item['goods_name'], 'sku_name' => (string)$item['sku_name'],
            'quantity' => (string)$item['order_qty'], 'unit_name' => (string)$item['unit_name'],
            'requirements' => $requirements, 'remark' => (string)$item['line_remark'], 'processing_groups' => $groups,
            'pending_measurement' => $pending, 'shortage_quantity' => $shortage, 'base_unit_name' => (string)$item['base_unit_name'],
            'fulfillment_status' => (string)($item['fulfillment_status'] ?? ''),
            'undelivered_reason' => (string)($item['undelivered_reason'] ?? '')];
    }
}
