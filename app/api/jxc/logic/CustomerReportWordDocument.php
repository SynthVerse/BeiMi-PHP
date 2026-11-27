<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

/** 报货快照的两种 A4 打印单。 */
final class CustomerReportWordDocument
{
    public const TITLES = ['customers' => '客户报货单', 'goods' => '商品分货单'];

    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const PAGE_WIDTH = 11906;
    private const PAGE_HEIGHT = 16838;
    private const SIDE_MARGIN = 740;
    private const BOTTOM_MARGIN = 740;
    private const COLUMN_GAP = 448;
    private const COLUMN_WIDTH = (self::PAGE_WIDTH - 2 * self::SIDE_MARGIN - self::COLUMN_GAP) / 2;
    private const HEADER_DISTANCE = 320;
    private const BODY_LINE = 340;
    private const HEADING_GAP = 170;
    private const CHECK_INDENT = 300;
    private const COLUMN_BREAK = '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/>'
        . '</w:pPr><w:r><w:br w:type="column"/></w:r></w:p>';

    public static function create(array $snapshot, string $documentType): string
    {
        $title = self::TITLES[$documentType];
        $header = self::header($snapshot, $title);
        $topMargin = self::HEADER_DISTANCE + self::BODY_LINE * count($header) + 220;
        $blocks = $documentType === 'customers'
            ? self::customerBlocks($snapshot)
            : self::goodsBlocks($snapshot);
        $path = tempnam(sys_get_temp_dir(), 'beimi-report-');
        if ($path === false) { throw new \RuntimeException('Word 临时文件创建失败'); }
        $zip = new \ZipArchive();
        try {
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Word 文件创建失败'); }
            $parts = [
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>',
                '_rels/.rels' => self::relationships(['officeDocument' => 'word/document.xml']),
                'word/_rels/document.xml.rels' => self::relationships(['styles' => 'styles.xml', 'header' => 'header1.xml', 'footer' => 'footer1.xml']),
                'word/styles.xml' => self::styles(),
                'word/document.xml' => self::document($blocks, $topMargin),
                'word/header1.xml' => self::wrap('hdr', implode('', $header)),
                'word/footer1.xml' => self::wrap('ftr', '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
                    . '<w:r><w:rPr><w:sz w:val="19"/></w:rPr><w:t xml:space="preserve">第 </w:t></w:r>'
                    . '<w:fldSimple w:instr="PAGE"><w:r><w:rPr><w:sz w:val="19"/></w:rPr><w:t>1</w:t></w:r></w:fldSimple>'
                    . '<w:r><w:rPr><w:sz w:val="19"/></w:rPr><w:t xml:space="preserve"> 页</w:t></w:r></w:p>'),
            ];
            foreach ($parts as $name => $content) {
                if (!$zip->addFromString($name, $content)) { throw new \RuntimeException('Word 文件写入失败'); }
            }
            if (!$zip->close()) { throw new \RuntimeException('Word 文件保存失败'); }
            $bytes = file_get_contents($path);
            if ($bytes === false) { throw new \RuntimeException('Word 文件读取失败'); }
            return $bytes;
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    private static function header(array $data, string $title): array
    {
        $query = $data['query'];
        $header = [self::paperLine(self::run($title, 28, true))];
        $width = self::PAGE_WIDTH - 2 * self::SIDE_MARGIN;
        $header = [...$header, ...self::field('门店', $data['store_name'], width: $width),
            ...self::field('送货日期', $query['delivery_date'], width: $width)];
        $scope = array_filter([
            ['pending' => '待交付', 'completed' => '已交付'][$query['status']] ?? '',
            $query['keyword'] === '' ? '' : '客户含“' . $query['keyword'] . '”',
        ]);
        if ($scope) {
            $header = [...$header, ...self::field('筛选', implode('　', $scope), width: $width)];
        }
        return $header;
    }

    private static function customerBlocks(array $data): array
    {
        $blocks = [];
        $itemPositions = [];
        foreach (self::numberedReports($data['reports']) as [$report, $position, $order]) {
            $customerId = $report['delivery_customer_id'];
            $itemPositions[$customerId] ??= 0;
            $name = self::receiverName($report, $position);
            $heading = self::field('客户', $name, 28, true);
            $continued = self::field('客户', $name, 28, true, continuation: true);
            if ($order !== '') {
                $heading = [...$heading, ...self::field('报货次序', $order)];
                $continued = [...$continued, ...self::field('报货次序', $order)];
            }
            $heading = [...$heading, ...self::detailFields(self::reportDetails($report, $data['query']['status']))];
            $rows = [];
            foreach ($report['items'] as $line) {
                $number = self::goodsOrdinal(++$itemPositions[$customerId]);
                $product = self::goodsName($line);
                $opening = [
                    ...self::field('商品', $product, 24, true, $number, check: true),
                    ...self::field('报货数量', self::amount($line['quantity'], $line['unit_name']), 24, true, indent: self::CHECK_INDENT),
                ];
                $rows[] = self::row($opening, self::detailFields(self::lineDetails($line), self::CHECK_INDENT),
                    continued: self::field('商品', $product, 24, true, $number, indent: self::CHECK_INDENT, continuation: true));
            }
            $delivery = self::delivery($report['delivery_arrangement']);
            $remark = trim($report['remark']);
            $rows[] = self::row(...$delivery);
            if ($remark !== '') {
                $rows[] = self::row([], self::field('整单备注', $remark));
            }
            $blocks[] = ['heading' => $heading, 'continued' => $continued, 'rows' => $rows];
        }
        return $blocks;
    }

    private static function goodsBlocks(array $data): array
    {
        $products = [];
        foreach (self::numberedReports($data['reports']) as [$report, $position, $order]) {
            $reportDetails = self::reportDetails($report, $data['query']['status']);
            $arrangement = $report['delivery_arrangement'];
            $time = self::deliveryValue($arrangement, 'earliest_delivery_time');
            foreach ($report['items'] as $line) {
                $key = self::goodsKey($line);
                $good = &$products[$line['goods_id']][$key];
                $good ??= ['name' => self::goodsName($line), 'unit' => $line['unit_name'], 'quantity' => '0', 'receivers' => []];
                $good['quantity'] = bcadd($good['quantity'], $line['quantity'], 4);
                $receiver = &$good['receivers'][$position];
                $receiver ??= ['name' => self::receiverName($report, $position), 'quantity' => '0', 'reports' => []];
                $receiver['quantity'] = bcadd($receiver['quantity'], $line['quantity'], 4);
                $allocation = &$receiver['reports'][$report['id']];
                $allocation ??= ['order' => $order, 'time' => $time, 'quantity' => '0', 'details' => $reportDetails, 'items' => []];
                $allocation['quantity'] = bcadd($allocation['quantity'], $line['quantity'], 4);
                $allocation['items'][] = ['quantity' => $line['quantity'], 'details' => self::lineDetails($line)];
                unset($allocation, $receiver, $good);
            }
        }

        $blocks = [];
        foreach ($products as $variants) {
            foreach ($variants as $good) {
                $blocks[] = self::goodsBlock($good, count($blocks) + 1);
            }
        }
        return $blocks;
    }

    private static function goodsBlock(array $good, int $position): array
    {
        $number = self::goodsOrdinal($position);
        $unit = $good['unit'];
        $rows = [];
        ksort($good['receivers']);
        foreach ($good['receivers'] as $receiver) {
            $customer = self::field('客户', $receiver['name'], 24, true);
            $continued = self::field('客户', $receiver['name'], 24, true, continuation: true);
            $multiple = count($receiver['reports']) > 1;
            if ($multiple) {
                $customer = [...$customer, ...self::field('客户合计', self::amount($receiver['quantity'], $unit), 24, true)];
            }
            foreach ($receiver['reports'] as $report) {
                $order = $multiple ? self::field('报货次序', $report['order'], check: true) : [];
                $quantity = self::field('本单分货量', self::amount($report['quantity'], $unit), 24, true,
                    check: !$multiple, indent: self::CHECK_INDENT);
                $details = self::detailFields([['最早送货时间', $report['time']], ...$report['details']], self::CHECK_INDENT);
                foreach ($report['items'] as $item) {
                    if (count($report['items']) > 1 && $item['details']) {
                        $details = [...$details, ...self::field('明细数量', self::amount($item['quantity'], $unit), indent: self::CHECK_INDENT)];
                    }
                    $details = [...$details, ...self::detailFields($item['details'], self::CHECK_INDENT)];
                }
                $context = $continued;
                if ($multiple) {
                    $context = [...$context, ...self::field('报货次序', $report['order'], indent: self::CHECK_INDENT)];
                }
                $rows[] = self::row([...$customer, ...$order, ...$quantity], $details,
                    beforeBreak: $customer ? [] : $continued, continued: $context, startsCustomer: $customer !== []);
                $customer = [];
            }
        }
        return [
            'heading' => [
                ...self::field('商品', $good['name'], 28, true, $number),
                ...self::field('总报货量', self::amount($good['quantity'], $unit), 24, true),
            ],
            'continued' => self::field('商品', $good['name'], 28, true, $number, continuation: true),
            'rows' => $rows,
        ];
    }

    private static function numberedReports(array $reports): array
    {
        $counts = array_count_values(array_column($reports, 'delivery_customer_id'));
        $positions = [];
        $orders = [];
        $numbered = [];
        foreach ($reports as $report) {
            $id = $report['delivery_customer_id'];
            $positions[$id] ??= count($positions) + 1;
            $orders[$id] = ($orders[$id] ?? 0) + 1;
            $order = $counts[$id] > 1 ? '第 ' . $orders[$id] . ' 单' : '';
            $numbered[] = [$report, $positions[$id], $order];
        }
        return $numbered;
    }

    private static function goodsOrdinal(int $position): string
    {
        $circles = '①②③④⑤⑥⑦⑧⑨⑩⑪⑫⑬⑭⑮⑯⑰⑱⑲⑳㉑㉒㉓㉔㉕㉖㉗㉘㉙㉚㉛㉜㉝㉞㉟㊱㊲㊳㊴㊵㊶㊷㊸㊹㊺㊻㊼㊽㊾㊿';
        return $position <= 50 ? mb_substr($circles, $position - 1, 1, 'UTF-8') : (string)$position;
    }

    private static function goodsKey(array $line): string
    {
        return json_encode(
            [$line['goods_id'], $line['sku_id'], $line['spec_id'], $line['unit_id'], $line['unit_name']],
            JSON_THROW_ON_ERROR
        );
    }

    private static function receiverName(array $customer, int $position): string
    {
        $main = $customer['main_customer_name'];
        $receiver = $customer['delivery_customer_name'];
        $name = $receiver === '' || $receiver === $main ? $main : $main . ' / ' . $receiver;
        return $position . '. ' . $name;
    }

    private static function goodsName(array $line): string
    {
        $name = trim($line['goods_name']);
        $sku = trim($line['sku_name']);
        if (in_array($sku, [$name, '默认规格', '不分规格'], true)) {
            $sku = '';
        }
        $specification = $line['specification'];
        $skuParts = preg_split('/[\s\/、·（）()]+/u', $sku, -1, PREG_SPLIT_NO_EMPTY);
        $parts = [$name, $sku, in_array($specification, [$name, $sku, ...$skuParts], true) ? '' : $specification];
        return implode(' ', array_filter($parts, static fn(string $part) => $part !== ''));
    }

    private static function reportDetails(array $report, string $scope): array
    {
        $status = match ($report['status']) {
            'partially_delivered_pending', 'partial_pending_settlement', 'undelivered', 'fulfilled_undelivered' => $report['status_label'],
            'delivered_pending_settlement', 'settled', 'completed' => $scope === 'completed' ? '' : '已交付',
            default => '',
        };
        return [
            ['报货类型', $report['supplement_for_report_id'] > 0 ? '补报' : ''],
            ['交付状态', $status],
        ];
    }

    private static function delivery(array $arrangement): array
    {
        $methods = ['customer_vehicle' => '客户车辆', 'third_party' => '第三方配送', 'self_delivery' => '自配送', 'counter_pickup' => '客户自提'];
        $method = $arrangement['delivery_method'];
        $name = $methods[$method] ?? '方式待完善';
        if ($method === 'counter_pickup') {
            return [self::field('送货', $name), []];
        }

        $fields = [
            ['送货', $name],
            ['车牌', self::deliveryValue($arrangement, 'plate_number')],
            ['最早', self::deliveryValue($arrangement, 'earliest_delivery_time')],
        ];
        $summary = implode('　', array_map(
            static fn(array $field): string => $field[0] . '：' . $field[1],
            array_filter($fields, static fn(array $field): bool => $field[1] !== '')
        ));
        // 普通车牌横向微调即可放在同一行。超长录入值按字段分行，保留完整车牌和可读字号。
        $scale = min(100, intdiv(self::lineWidth(22, self::COLUMN_WIDTH) * 100, self::textWidth($summary)));
        $opening = $scale >= 85
            ? [self::paperLine(self::run($summary, 22, format: $scale < 100 ? '<w:w w:val="' . $scale . '"/>' : ''))]
            : self::detailFields($fields);
        return [$opening, self::detailFields([
            ['交接地点', self::deliveryValue($arrangement, 'vehicle_location')],
            ['司机电话', self::deliveryValue($arrangement, 'driver_phone')],
        ])];
    }

    private static function deliveryValue(array $arrangement, string $field): string
    {
        $value = trim((string)$arrangement[$field]);
        if ($field === 'plate_number') {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        }
        if ($value === '' && $arrangement['delivery_method'] === 'customer_vehicle'
            && in_array($field, $arrangement['missing_fields'], true)) {
            return '待补';
        }
        return $value;
    }

    private static function lineDetails(array $line): array
    {
        $details = [];
        if ($line['pending_measurement']) {
            $details[] = ['称重状态', '待称重'];
        } elseif (bccomp($line['shortage_quantity'] ?? '0', '0', 4) > 0) {
            $details[] = ['缺货数量', self::amount($line['shortage_quantity'], $line['base_unit_name'])];
        }
        if ($line['fulfillment_status'] === 'undelivered') {
            $details[] = ['未送达原因', $line['undelivered_reason'] ?: '原因待核对'];
        }
        $processDetails = [];
        foreach ($line['processing_groups'] as $group) {
            $split = count($line['processing_groups']) > 1 || bccomp($group['quantity'], $line['quantity'], 4) !== 0;
            $processes = implode('→', $group['processes']);
            $name = trim($group['name']);
            if ($name === $processes || preg_match('/^第\s*\d+\s*组$/u', $name)) {
                $name = '';
            }
            $mode = !$split && $processes === '直接备货' ? '' : $processes;
            $mode = $mode !== '' && $name !== '' ? $mode . '（' . $name . '）' : $mode . $name;
            if ($split) {
                $mode = self::amount($group['quantity'], $line['unit_name']) . ' ' . ($mode ?: $group['name']);
            }
            if ($mode !== '') {
                $processDetails[] = $mode;
                $details[] = ['加工要求', $mode];
            }
        }
        foreach ($line['requirement_fields'] as $label => $value) {
            if ($label === '规格' || ($label === '加工要求' && in_array($value, $processDetails, true))) {
                continue;
            }
            $details[] = [$label, trim($value)];
        }
        if (trim($line['remark']) !== '') {
            $details[] = ['商品备注', trim($line['remark'])];
        }
        return $details;
    }

    private static function row(
        array $opening,
        array $details,
        array $beforeBreak = [],
        array $continued = [],
        bool $startsCustomer = false
    ): array
    {
        return [
            'lines' => [...$opening, ...$details],
            'minimum' => max(1, count($opening)),
            'before_break' => $beforeBreak,
            'continued' => $continued,
            'starts_customer' => $startsCustomer,
        ];
    }

    private static function document(array $blocks, int $topMargin): string
    {
        // 每个预排段落占一行，换栏时留出分隔段落并补印这段的归属。
        $capacity = self::PAGE_HEIGHT - $topMargin - self::BOTTOM_MARGIN - 500;
        $columnBreak = self::balancedColumnBreak($blocks, $capacity);
        $receiverBreak = count($blocks) === 1 ? self::balancedReceiverBreak($blocks[0], $capacity) : null;
        $body = '';
        $used = 0;
        foreach ($blocks as $blockIndex => $block) {
            $heading = $block['heading'];
            $opened = $block['rows'][0];
            $opening = (count($heading) + $opened['minimum']) * self::BODY_LINE;
            if ($used && ($blockIndex === $columnBreak || $used + self::HEADING_GAP + $opening > $capacity)) {
                $body .= self::COLUMN_BREAK;
                $used = 0;
            }
            if ($used) {
                $heading[0] = str_replace('w:before="0"', 'w:before="' . self::HEADING_GAP . '"', $heading[0]);
                $used += self::HEADING_GAP;
            }
            $available = intdiv($capacity - $used, self::BODY_LINE);
            while (count($heading) > $available) {
                $body .= implode('', array_splice($heading, 0, $available));
                $used = self::continueColumn($body, $block['continued'], []);
                $available = intdiv($capacity - $used, self::BODY_LINE);
            }
            $body .= implode('', $heading);
            $used += count($heading) * self::BODY_LINE;
            foreach ($block['rows'] as $index => $row) {
                $lines = $row['lines'];
                $available = intdiv($capacity - $used, self::BODY_LINE);
                if ($available < $row['minimum'] || $index === $receiverBreak) {
                    $used = self::continueColumn($body, $block['continued'], $row['before_break']);
                }
                while ($lines) {
                    $available = max(1, intdiv($capacity - $used, self::BODY_LINE));
                    $part = array_splice($lines, 0, $available);
                    $body .= implode('', $part);
                    $used += count($part) * self::BODY_LINE;
                    if ($lines) {
                        $used = self::continueColumn($body, $block['continued'], $row['continued']);
                    }
                }
            }
        }
        $body .= '<w:sectPr><w:headerReference w:type="default" r:id="header"/><w:footerReference w:type="default" r:id="footer"/>'
            . '<w:pgSz w:w="' . self::PAGE_WIDTH . '" w:h="' . self::PAGE_HEIGHT . '"/>'
            . '<w:pgMar w:top="' . $topMargin . '" w:right="' . self::SIDE_MARGIN . '" w:bottom="' . self::BOTTOM_MARGIN . '" w:left="' . self::SIDE_MARGIN . '"'
            . ' w:header="' . self::HEADER_DISTANCE . '" w:footer="320" w:gutter="0"/>'
            . '<w:cols w:num="2" w:space="' . self::COLUMN_GAP . '"/></w:sectPr>';
        return self::wrap('document', '<w:body>' . $body . '</w:body>');
    }

    private static function continueColumn(string &$body, array $heading, array $context): int
    {
        $body .= self::COLUMN_BREAK . implode('', $heading) . implode('', $context);
        return (count($heading) + count($context)) * self::BODY_LINE;
    }

    private static function balancedColumnBreak(array $blocks, int $capacity): ?int
    {
        // 一张纸能放下完整分组时，在组与组之间换栏，让两栏接近等高。
        $heights = [];
        foreach ($blocks as $block) {
            $lines = count($block['heading']);
            foreach ($block['rows'] as $row) {
                $lines += count($row['lines']);
            }
            $heights[] = $lines * self::BODY_LINE + self::HEADING_GAP;
        }
        $total = array_sum($heights);
        if ($total - self::HEADING_GAP <= $capacity || $total > 2 * ($capacity + self::HEADING_GAP)) {
            return null;
        }
        $left = 0;
        $bestBreak = null;
        $bestDifference = $total;
        foreach ($heights as $index => $height) {
            $right = $total - $left;
            $difference = abs($left - $right);
            if ($left > 0 && max($left, $right) - self::HEADING_GAP <= $capacity && $difference < $bestDifference) {
                $bestBreak = $index;
                $bestDifference = $difference;
            }
            $left += $height;
        }
        return $bestBreak;
    }

    private static function balancedReceiverBreak(array $block, int $capacity): ?int
    {
        // 单品占据大半栏时，在客户之间分栏，避免现场核对时只用到纸的一半。
        $heights = array_map(static fn(array $row): int => count($row['lines']) * self::BODY_LINE, $block['rows']);
        $total = count($block['heading']) * self::BODY_LINE + array_sum($heights);
        if ($total < $capacity * 2 / 3 || $total > $capacity) {
            return null;
        }
        $left = count($block['heading']) * self::BODY_LINE;
        $continuation = count($block['continued']) * self::BODY_LINE;
        $best = null;
        $bestDifference = $total;
        foreach ($block['rows'] as $index => $row) {
            $right = $total - $left + $continuation;
            $difference = abs($left - $right);
            if ($index > 0 && $row['starts_customer'] && $right <= $capacity && $difference < $bestDifference) {
                $best = $index;
                $bestDifference = $difference;
            }
            $left += $heights[$index];
        }
        return $best;
    }

    private static function detailFields(array $fields, int $indent = 0): array
    {
        $lines = [];
        foreach ($fields as [$label, $value]) {
            $lines = [...$lines, ...self::field($label, $value, indent: $indent)];
        }
        return $lines;
    }

    private static function field(
        string $label,
        string $value,
        int $size = 22,
        bool $bold = false,
        string $number = '',
        bool $check = false,
        int $indent = 0,
        int $width = self::COLUMN_WIDTH,
        bool $continuation = false
    ): array {
        if ($value === '') {
            return [];
        }
        // 续行缩进到字段值，圈号和手写框不随内容换行重复。
        $label .= '：';
        $labelIndent = $check ? self::CHECK_INDENT : $indent;
        $prefixWidth = self::textWidth($label . ($number === '' ? '' : $number . ' '));
        $valueIndent = $labelIndent + intdiv($prefixWidth * $size * 10, 4);
        $prefix = $check ? self::run('□ ', 28, font: 'Segoe UI Symbol') : '';
        $prefix .= self::run($label, $size);
        if ($number !== '') {
            $prefix .= self::ordinalRun($number, $size) . self::run(' ', $size);
        }
        $limit = self::lineWidth($size, $width - $valueIndent);
        $lines = self::wrapText($continuation ? $value . '（续）' : $value, $limit);
        if ($continuation && count($lines) > 3) {
            // 保留开头的序号和末尾的实际收货客户名或商品规格。
            $parts = self::wrapText($value, $limit - self::textWidth('…（续）'));
            [$beforeLast, $last] = array_slice($parts, -2);
            $lines = [$parts[0], '…' . $beforeLast, $last . '（续）'];
        }
        $paragraphs = [];
        foreach ($lines as $index => $line) {
            $runs = ($index === 0 ? $prefix : '') . self::run($line, $size, $bold);
            $paragraphs[] = self::paperLine($runs, indent: $index === 0 ? ($check ? 0 : $indent) : $valueIndent);
        }
        return $paragraphs;
    }

    private static function lineWidth(int $size, int $width): int
    {
        // 换算成四分之一全宽字，并给字体替换留出余量。
        return intdiv(($width - 160) * 4, $size * 10);
    }

    private static function ordinalRun(string $number, int $size): string
    {
        if (!ctype_digit($number)) {
            $font = preg_match('/^[①-⑳]$/u', $number) ? 'Cambria Math' : 'Microsoft YaHei';
            return self::run($number, $size, font: $font);
        }
        // ㊿之后没有预组圈号，按圈和数字的实际字体叠印，复制时仍能取得原数字。
        [$tracking, $scale] = match (strlen($number)) {
            2 => [-204, 100],
            3 => [-304, 142],
            default => [-391, 180],
        };
        $ring = '<w:spacing w:val="' . $tracking . '"/><w:w w:val="' . $scale . '"/>';
        return self::run('◯', 24, format: $ring, font: 'Cambria Math')
            . self::run($number, 14, format: '<w:position w:val="3"/>', font: 'Microsoft YaHei');
    }

    private static function wrapText(string $text, int $limit): array
    {
        $text = str_replace(["\r\n", "\r", '\\n', "\t"], ["\n", "\n", "\n", ' '], trim($text));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', "\u{FFFD}", $text) ?? '';
        $lines = [];
        foreach (explode("\n", $text) as $source) {
            // 按显示宽度折行，同时保留电话、车牌、重量范围和数量单位。
            preg_match_all(
                '/\p{Han}?（续）|\p{Han}[A-Z][A-Z0-9]{5,7}|\+?[0-9-]{7,18}'
                    . '|\d{1,6}(?:\.\d{1,4})?[–-]\d{1,6}(?:\.\d{1,4})?(?: \p{Han}{1,3}(?=\P{Han}|$))?'
                    . '|\d{1,14}(?:\.\d{1,4})? (?:\p{Han}{1,4}(?=\P{Han}|$)|[A-Za-z]{1,6}\b)'
                    . '|[A-Za-z0-9][A-Za-z0-9.+@%\/:_-]{0,17}|\X/u',
                $source, $tokens
            );
            $line = '';
            $width = 0;
            foreach ($tokens[0] as $token) {
                $size = self::textWidth($token);
                if ($width > 0 && $width + $size > $limit
                    && ($width + $size > $limit + 4 || !preg_match('/^[，。！？；：、）】》]$/u', $token))) {
                    $lines[] = trim($token) === '' ? $line . $token : $line;
                    $line = '';
                    $width = 0;
                }
                if ($width || trim($token) !== '') {
                    $line .= $token;
                    $width += $size;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    private static function textWidth(string $text): int
    {
        return 4 * mb_strlen($text, 'UTF-8')
            - preg_match_all('/[A-LNPR-VX-Za-lnpr-vx-z0-9]/', $text)
            - 2 * preg_match_all('/[ .,;:!\'\/-]/', $text);
    }

    private static function run(string $line, int $size, bool $bold = false, string $color = '000000', string $format = '', string $font = ''): string
    {
        $fonts = $font === '' ? '' : '<w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:eastAsia="' . $font . '" w:cs="' . $font . '"/>';
        return '<w:r><w:rPr>' . $fonts . ($bold ? '<w:b/>' : '') . '<w:color w:val="' . $color . '"/>' . $format . '<w:sz w:val="' . $size
            . '"/><w:szCs w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">'
            . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8') . '</w:t></w:r>';
    }

    private static function paperLine(string $runs, int $indent = 0): string
    {
        return '<w:p><w:pPr><w:widowControl w:val="0"/><w:snapToGrid w:val="0"/>'
            . '<w:spacing w:before="0" w:after="0" w:line="' . self::BODY_LINE . '" w:lineRule="exact"/>'
            . ($indent ? '<w:ind w:left="' . $indent . '"/>' : '') . '</w:pPr>' . $runs . '</w:p>';
    }

    private static function amount(string $quantity, string $unit): string
    {
        $value = str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
        return trim($value . ' ' . $unit);
    }

    private static function wrap(string $tag, string $body): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:' . $tag . ' xmlns:w="' . self::NS . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . $body . '</w:' . $tag . '>'; }
    private static function styles(): string
    {
        return self::wrap('styles', '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Microsoft YaHei" w:hAnsi="Microsoft YaHei" w:eastAsia="Microsoft YaHei"/>'
            . '<w:color w:val="000000"/><w:sz w:val="22"/><w:lang w:val="zh-CN" w:eastAsia="zh-CN"/>'
            . '</w:rPr></w:rPrDefault></w:docDefaults>');
    }
    private static function relationships(array $parts): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($parts as $type => $target) { $xml .= '<Relationship Id="' . $type . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $type . '" Target="' . $target . '"/>'; }
        return $xml . '</Relationships>';
    }
}
