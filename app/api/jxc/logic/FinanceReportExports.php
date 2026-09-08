<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use think\facade\Db;

/** 导出保存读取时的权威版本；文件读取再次鉴权，二进制不放公共目录。 */
final class FinanceReportExports
{
    public static function auditAttempt(string $action, array $params, ?array $result, string $message = ''): void
    {
        $tenant = FinanceAccess::tenant();
        if ($tenant <= 0 || FinanceAccess::operator() <= 0) { return; }
        $snapshot = []; $exportId = $result['id'] ?? null;
        if ($action === 'content' && filter_var($params['id'] ?? null, FILTER_VALIDATE_INT)) {
            $row = Db::name('finance_report_export')->where('tenant_id', $tenant)->where('id', $params['id'])->find();
            if ($row) { $snapshot = FinanceValue::decode($row['snapshot']); $exportId = (int)$row['id']; }
        }
        $fields = $result ?? ($snapshot ?: $params);
        $text = static fn(mixed $value, int $limit): ?string => is_string($value) ? mb_substr($value, 0, $limit, 'UTF-8') : null;
        Db::name('finance_report_export_attempt')->insert(['tenant_id' => $tenant, 'export_id' => $exportId, 'actor' => FinanceValue::json(FinanceAccess::actor()),
            'action' => mb_substr($action, 0, 24, 'UTF-8'), 'report' => $text($fields['report'] ?? null, 24), 'period_type' => $text($fields['period_type'] ?? 'month', 12),
            'period' => $text($fields['period'] ?? $fields['month'] ?? null, 12), 'cutoff' => $result['cutoff'] ?? $snapshot['cutoff'] ?? null,
            'outcome' => $result === null ? 'failure' : 'success', 'message' => mb_substr($message, 0, 240, 'UTF-8'), 'create_time' => time()]);
    }

    public static function execute(FinanceLedger $ledger, string $action, array $params): array
    {
        $tenant = FinanceAccess::tenant();
        if ($action === 'prepare') {
            $kind = FinanceReports::authorize($params); FinanceAccess::require('finance.report.' . $kind . '.export');
            if (FinanceValue::id($params['expected_tenant_id'] ?? null) !== $tenant) { throw new \DomainException('门店已变化，请重新读取报表'); }
            $key = FinanceValue::text($params['idempotency_key'] ?? null, 96);
            if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D', $key)) { throw new \DomainException('导出提交标识无效'); }
            $query = ['report' => $kind, 'period_type' => $params['period_type'] ?? 'month', 'period' => $params['period'] ?? $params['month'] ?? date('Y-m')];
            $actor = FinanceAccess::actor(); $fingerprint = self::hash([$tenant, $actor['type'], $actor['id'], $query]);
            $existing = Db::name('finance_report_export')->where('tenant_id', $tenant)->where('idempotency_key', $key)->find();
            if ($existing) {
                if ($existing['fingerprint'] !== $fingerprint) { throw new \DomainException('同一导出标识不能更换报表范围或操作人'); }
                self::authorize($existing); return self::describe($existing);
            }
            $snapshot = FinanceReportPeriods::read($ledger, $query);
            $row = ['tenant_id' => $tenant, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'report' => $kind, 'actor' => FinanceValue::json($actor),
                'snapshot' => FinanceValue::json($snapshot), 'snapshot_hash' => self::hash($snapshot), 'create_time' => time()];
            $row['id'] = Db::name('finance_report_export')->insertGetId($row);
            return self::describe($row);
        }
        if ($action !== 'content') { throw new \DomainException('导出动作无效'); }
        $id = FinanceValue::id($params['id'] ?? null);
        $row = Db::name('finance_report_export')->where('tenant_id', $tenant)->where('id', $id)->find();
        if (!$row) { throw new \DomainException('导出记录不存在或不属于当前门店'); }
        $snapshot = self::authorize($row);
        if (!hash_equals($row['snapshot_hash'], self::hash($snapshot))) { throw new \DomainException('导出依据完整性校验失败，请联系管理员'); }
        $bytes = self::workbook($snapshot, $row);
        Db::name('finance_report_export_access')->insert(['tenant_id' => $tenant, 'export_id' => $id, 'actor' => FinanceValue::json(FinanceAccess::actor()), 'content_hash' => hash('sha256', $bytes), 'create_time' => time()]);
        return self::describe($row) + ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'base64' => base64_encode($bytes)];
    }

    private static function authorize(array $row): array
    {
        FinanceReports::authorize(['report' => $row['report']]); FinanceAccess::require('finance.report.' . $row['report'] . '.export');
        $actor = FinanceValue::decode($row['actor']); $current = FinanceAccess::actor();
        if ($actor['type'] !== $current['type'] || (int)$actor['id'] !== (int)$current['id']) { throw new \DomainException('请以本次导出操作人的身份读取文件'); }
        $snapshot = FinanceValue::decode($row['snapshot']);
        if ($snapshot['salary_details_visible'] && !FinanceAccess::has('finance.salary.view')) { throw new \DomainException('工资明细权限已变化，请重新生成不含个人明细的报表'); }
        return $snapshot;
    }

    private static function describe(array $row): array
    {
        $snapshot = FinanceValue::decode($row['snapshot']);
        return ['tenant_id' => (int)$row['tenant_id'], 'id' => (int)$row['id'], 'report' => $row['report'], 'period_type' => $snapshot['period_type'], 'period' => $snapshot['period'],
            'filename' => FinanceReports::TYPES[$row['report']] . '-' . $snapshot['period'] . '-' . $row['id'] . '.xlsx', 'snapshot_hash' => $row['snapshot_hash'], 'cutoff' => $snapshot['cutoff'], 'created_at' => (int)$row['create_time']];
    }

    private static function hash(mixed $value): string
    {
        $normalize = static function (mixed $part) use (&$normalize): mixed {
            if (!is_array($part)) { return $part; }
            if (!array_is_list($part)) { ksort($part); }
            foreach ($part as &$child) { $child = $normalize($child); } unset($child);
            return $part;
        };
        return hash('sha256', FinanceValue::json($normalize($value)));
    }

    private static function workbook(array $report, array $export): string
    {
        $book = new Spreadsheet(); $book->removeSheetByIndex(0);
        $book->getProperties()->setCreator('BeiMi')->setTitle(FinanceReports::TYPES[$report['report']])->setCreated((int)$export['create_time'])->setModified((int)$export['create_time']);
        $rows = [['报表', FinanceReports::TYPES[$report['report']]], ['门店编号', (string)$report['tenant_id']], ['统计期间', $report['period']], ['实际范围', $report['start_month'] . ' 至 ' . $report['end_month']],
            ['截止日期', $report['cutoff']], ['生成时间', $report['generated_at']], ['结果状态', $report['stage'] ? '阶段结果' : '已结月份原快照'],
            ['结账状态', self::closing($report['closing_status'])], ['原核验状态', $report['verification']['status'] === 'checked' ? '已核验' : '尚未完全核验'], ['导出记录编号', (string)$export['id']], ['依据校验值', $export['snapshot_hash']]];
        foreach ($report['data']['summary'] ?? [] as $field => $amount) { $rows[] = [self::label($field), self::value($amount)]; }
        self::sheet($book, '报表汇总', ['项目', '内容'], $rows);
        $months = [];
        foreach ($report['months'] as $month) { $months[] = [$month['month'], self::closing($month['closing_status']), $month['stage'] ? '阶段结果' : '冻结快照', $month['verification']['status'] === 'checked' ? '已核验' : '尚未完全核验', $month['cutoff']]; }
        self::sheet($book, '月份与核验', ['月份', '原结账方式', '数据版本', '原核验状态', '截止日期'], $months);
        foreach (['advance_movements' => '预收期间更正', 'summary' => '汇总字段', 'accounts' => '资金账户', 'transit' => '在途余额', 'categories' => '类别组成', 'subjects' => '往来对象', 'sources' => '未结来源', 'entries' => '金额流水', 'external_money' => '实际对外收支', 'internal_transfers' => '内部互转', 'adjustments' => '账面调整', 'pending_arrivals' => '待结算到货', 'positions' => '期末库存', 'opening_sources' => '期初来源', 'shares' => '库存成本来源', 'loss_effects' => '库存损耗', 'cost_effects' => '成本影响', 'prior_period_entries' => '以前期间金额调整', 'prior_period_cost_effects' => '以前期间成本调整'] as $key => $title) {
            if (!isset($report['data'][$key]) || $key === 'summary') { continue; }
            self::table($book, $title, array_is_list($report['data'][$key]) ? $report['data'][$key] : [$report['data'][$key]]);
        }
        foreach ($report['data']['obligations'] ?? [] as $key => $parts) { self::table($book, '费用余额-' . self::label($key), $parts); }
        self::table($book, '导出时遗留进度', $report['current_followups']);
        // 保存逐月及嵌套组成的完整字段，辅助复查；所有原文均按文本写入，不能执行公式。
        $facts = []; self::flatten($report, '', $facts); self::sheet($book, '完整导出依据', ['字段路径', '原值'], $facts);
        $stream = fopen('php://temp', 'w+b');
        try { (new Xlsx($book))->save($stream); rewind($stream); return stream_get_contents($stream); }
        finally { fclose($stream); $book->disconnectWorksheets(); }
    }

    private static function table(Spreadsheet $book, string $title, array $rows): void
    {
        if (!$rows) { self::sheet($book, $title, ['内容'], [['此期间没有该类明细']]); return; }
        $keys = [];
        foreach ($rows as $row) { foreach (array_keys($row) as $key) { $keys[$key] = true; } }
        $keys = array_keys($keys);
        self::sheet($book, $title, array_map([self::class, 'label'], $keys), array_map(static fn(array $row): array => array_map(static fn(string $key): string => self::value($row[$key] ?? null), $keys), $rows));
    }

    private static function sheet(Spreadsheet $book, string $title, array $headers, array $rows): void
    {
        if (count($rows) > 1048575) { throw new \DomainException('报表明细超过单工作表容量，请缩小统计期间'); }
        $sheet = $book->createSheet(); $sheet->setTitle($title); $line = 1;
        foreach (array_merge([$headers], $rows) as $row) {
            foreach ($row as $column => $value) {
                $value = (string)$value;
                if (mb_strlen($value, 'UTF-8') > 15000) {
                    $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1) . $line;
                    self::longText($book, $title, $coordinate, $value);
                    $value = '完整内容见“长文本续页”：' . $title . ' / ' . $coordinate;
                }
                $sheet->setCellValueExplicitByColumnAndRow($column + 1, $line, $value, DataType::TYPE_STRING);
            }
            $line++;
        }
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $sheet->freezePane('A2'); $sheet->setAutoFilter('A1:' . $last . max(1, $line - 1));
        $sheet->getStyle('A1:' . $last . '1')->getFont()->setBold(true); $sheet->getStyle('A1:' . $last . '1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE6F0FA');
        $sheet->getDefaultColumnDimension()->setWidth(24); $sheet->getStyle('A1:' . $last . max(1, $line - 1))->getAlignment()->setWrapText(true)->setVertical('top');
    }

    private static function longText(Spreadsheet $book, string $title, string $coordinate, string $text): void
    {
        if (!$book->getSheetByName('长文本续页')) { self::sheet($book, '长文本续页', ['原工作表', '原单元格', '片段序号', '原文片段（按序拼接）'], []); }
        $sheet = $book->getSheetByName('长文本续页');
        $chunks = mb_str_split($text, 15000, 'UTF-8'); $line = $sheet->getHighestRow() + 1;
        if ($line + count($chunks) - 1 > 1048576) { throw new \DomainException('报表长文本超过工作表容量，请缩小统计期间'); }
        foreach ($chunks as $index => $chunk) {
            foreach ([$title, $coordinate, (string)($index + 1), $chunk] as $column => $value) { $sheet->setCellValueExplicitByColumnAndRow($column + 1, $line, $value, DataType::TYPE_STRING); }
            $line++;
        }
        $sheet->setAutoFilter('A1:D' . ($line - 1));
        $sheet->getStyle('A1:D' . ($line - 1))->getAlignment()->setWrapText(true)->setVertical('top');
    }

    private static function flatten(array $values, string $path, array &$rows): void
    {
        foreach ($values as $key => $value) {
            $next = $path === '' ? (string)$key : $path . '.' . $key;
            if (is_array($value) && $value) { self::flatten($value, $next, $rows); }
            else { $rows[] = [$next, self::value($value)]; }
        }
    }
    private static function value(mixed $value): string { return $value === null ? '待核实' : (is_bool($value) ? ($value ? '是' : '否') : (is_array($value) ? FinanceValue::json($value) : (string)$value)); }
    private static function closing(string $state): string { return ['open' => '尚未结账', 'closed' => '普通结账', 'estimated' => '暂估结账', 'mixed' => '各月结账状态不同'][$state] ?? $state; }
    private static function label(string $key): string
    {
        return ['revision_id' => '预收更正版本', 'cash_entry_id' => '资金分录编号', 'movement' => '期间计入方式', 'reason' => '更正原因', 'id' => '记录编号', 'document_id' => '原凭据编号', 'subject_id' => '对象编号', 'subject_name' => '对象名称', 'account_id' => '资金账户编号', 'name' => '名称', 'category' => '业务类别', 'reference' => '来源编号', 'source_ref' => '关联来源', 'business_date' => '业务日期', 'actual_date' => '实际日期', 'posting_month' => '入账月份', 'opening' => '期初余额', 'closing' => '期末余额', 'new_sources' => '本期新增', 'entries_change' => '本期余额变动', 'change' => '本期净变动', 'amount' => '金额', 'principal' => '互转本金', 'fee' => '手续费', 'snapshot' => '原凭据依据', 'details' => '业务说明', 'revenue' => '销售收入', 'sales_cost' => '销售成本', 'known_sales_cost' => '已知销售成本', 'gross_profit' => '毛利', 'expense' => '经营费用', 'loss' => '账面损失', 'total_loss' => '损耗及损失合计', 'recovery_income' => '坏账收回收益', 'profit' => '经营利润', 'known_profit' => '已知金额部分利润', 'external_in' => '实际对外流入', 'external_out' => '实际对外流出', 'external_net' => '对外收支净额', 'opening_accounts' => '期初账户资金', 'closing_accounts' => '期末账户资金', 'opening_transit' => '期初在途', 'closing_transit' => '期末在途', 'cash_adjustment' => '内部账面调整', 'loss_cost' => '库存损耗成本', 'known_loss_cost' => '已知库存损耗成本', 'cost' => '库存成本', 'quantity' => '数量', 'categories' => '类别', 'subjects' => '对象', 'sources' => '来源', 'entries' => '流水'][$key] ?? $key;
    }
}
