<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

/** 报货快照的两种 A4 打印单。 */
final class CustomerReportWordDocument
{
    public const TITLES = ['customers' => '客户报货单', 'goods' => '商品分货单'];

    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const PAGE_HEIGHT = 16838;
    private const BOTTOM_MARGIN = 740;
    private const HEADER_DISTANCE = 320;
    private const HEADER_LINE = 280;
    private const BODY_LINE = 285;
    private const HEADING_GAP = 135;
    private const NAME_TAB = 960;
    private const QUANTITY_TAB = 4800;
    // 折行宽度以一个全宽字的四分之一计。
    private const BODY_WIDTH = 84;
    private const DETAIL_WIDTH = 66;
    private const COLUMN_BREAK = '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/>'
        . '</w:pPr><w:r><w:br w:type="column"/></w:r></w:p>';

    public static function create(array $snapshot, string $documentType): string
    {
        $title = self::TITLES[$documentType];
        $header = self::header($snapshot, $title);
        $topMargin = max(890, self::HEADER_DISTANCE + self::HEADER_LINE * count($header) + 220);
        $goodsNumbers = self::goodsNumbers($snapshot['customers']);
        $blocks = $documentType === 'customers'
            ? self::customerBlocks($snapshot, $goodsNumbers)
            : self::goodsBlocks($snapshot, $goodsNumbers);
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
                'word/document.xml' => self::document($blocks, $topMargin, $documentType),
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
        $headline = $data['store_name'] . ' · ' . $title . ' · 送货 ' . $query['delivery_date'];
        $header = array_map(
            static fn(string $line) => self::paragraph([$line], 23, true, self::HEADER_LINE),
            self::wrapText($headline, 168)
        );
        $scope = array_filter([
            ['pending' => '待交付', 'completed' => '已交付'][$query['status']] ?? '',
            $query['keyword'] === '' ? '' : '客户含“' . $query['keyword'] . '”',
        ]);
        if ($scope) {
            foreach (self::wrapText('筛选  ' . implode(' · ', $scope), 188) as $line) {
                $header[] = self::paragraph([$line], 20, false, self::HEADER_LINE);
            }
        }
        return $header;
    }

    private static function customerBlocks(array $data, array $goodsNumbers): array
    {
        $blocks = [];
        foreach ($data['customers'] as $position => $customer) {
            $rows = [];
            foreach ($customer['reports'] as $index => $report) {
                $order = count($customer['reports']) > 1 ? '第 ' . ($index + 1) . ' 单' : '';
                $label = self::reportLabel($report, $data['query']['status']);
                $continuation = $order === '' ? '' : $order . '（续）' . ($label !== '' ? ' · ' . $label : '');
                $rows[] = [
                    'text' => implode(' · ', array_filter([$order, $label, self::delivery($report['delivery_arrangement'])])),
                    'keep_next' => true, 'continuation' => $continuation,
                ];
                foreach ($report['items'] as $line) {
                    $number = $goodsNumbers[self::goodsKey($line)];
                    $name = self::goodsName($line);
                    $rows[] = [
                        'entry' => [$number, $name, self::amount($line['quantity'], $line['unit_name'])],
                        'text' => implode(' · ', self::lineDetails($line)),
                        'continuation' => $continuation, 'repeat_before' => true,
                        'split_context' => $number . ' ' . $name . '（续）',
                    ];
                }
                if (trim($report['remark']) !== '') {
                    $rows[] = ['text' => '备注  ' . trim($report['remark']), 'continuation' => $continuation, 'repeat_before' => true];
                }
            }
            $blocks[] = ['heading' => self::receiverEntry($customer, $position + 1), 'rows' => $rows];
        }
        return $blocks;
    }

    private static function goodsBlocks(array $data, array $goodsNumbers): array
    {
        $goods = [];
        foreach ($data['customers'] as $position => $customer) {
            $receiverKey = $customer['key'];
            $receiverEntry = self::receiverEntry($customer, $position + 1);
            foreach ($customer['reports'] as $report) {
                $label = self::reportLabel($report, $data['query']['status']);
                foreach ($report['items'] as $line) {
                    $key = self::goodsKey($line);
                    $goods[$key] ??= ['name' => self::goodsName($line), 'unit' => $line['unit_name'], 'quantity' => '0', 'receivers' => []];
                    $goods[$key]['quantity'] = bcadd($goods[$key]['quantity'], $line['quantity'], 4);
                    $goods[$key]['receivers'][$receiverKey] ??= ['entry' => $receiverEntry, 'quantity' => '0', 'items' => []];
                    $receiver = &$goods[$key]['receivers'][$receiverKey];
                    $receiver['quantity'] = bcadd($receiver['quantity'], $line['quantity'], 4);
                    $receiver['items'][] = [
                        'quantity' => $line['quantity'],
                        'label' => $label,
                        'details' => implode(' · ', self::lineDetails($line)),
                    ];
                    unset($receiver);
                }
            }
        }

        $blocks = [];
        foreach ($goodsNumbers as $key => $number) {
            $good = $goods[$key];
            $rows = [];
            foreach ($good['receivers'] as $receiver) {
                $details = [];
                if (count($receiver['items']) === 1) {
                    $item = $receiver['items'][0];
                    $details[] = implode(' · ', array_filter([$item['label'], $item['details']]));
                } else {
                    foreach ($receiver['items'] as $item) {
                        if ($item['label'] !== '' || $item['details'] !== '') {
                            $details[] = ($item['label'] !== '' ? $item['label'] . ' ' : '')
                                . self::amount($item['quantity'], $good['unit'])
                                . ($item['details'] !== '' ? '　' . $item['details'] : '');
                        }
                    }
                }
                $rows[] = [
                    'entry' => [$receiver['entry'][0], $receiver['entry'][1], self::amount($receiver['quantity'], $good['unit'])],
                    'text' => implode("\n", $details),
                    'continuation' => implode(' ', $receiver['entry']) . '（续）',
                ];
            }
            $blocks[] = [
                'heading' => [$number, $good['name'], '合计 ' . self::amount($good['quantity'], $good['unit'])],
                'rows' => $rows,
            ];
        }
        return $blocks;
    }

    private static function goodsNumbers(array $customers): array
    {
        $keysByProduct = [];
        foreach ($customers as $customer) {
            foreach ($customer['reports'] as $report) {
                foreach ($report['items'] as $line) {
                    $keysByProduct[$line['goods_id']][self::goodsKey($line)] = true;
                }
            }
        }

        $numbers = [];
        foreach ($keysByProduct as $variants) {
            foreach (array_keys($variants) as $key) {
                $numbers[$key] = sprintf('货%02d', count($numbers) + 1);
            }
        }
        return $numbers;
    }

    private static function goodsKey(array $line): string
    {
        return json_encode(
            [$line['goods_id'], $line['sku_id'], $line['spec_id'], $line['unit_id'], $line['unit_name']],
            JSON_THROW_ON_ERROR
        );
    }

    private static function receiverEntry(array $customer, int $position): array
    {
        $main = $customer['main_customer_name'];
        $receiver = $customer['delivery_customer_name'];
        $name = $receiver === '' || $receiver === $main ? $main : $main . ' / ' . $receiver;
        return [sprintf('客%02d', $position), $name];
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

    private static function reportLabel(array $report, string $scope): string
    {
        $status = match ($report['status']) {
            'partially_delivered_pending', 'partial_pending_settlement', 'undelivered', 'fulfilled_undelivered' => $report['status_label'],
            'delivered_pending_settlement', 'settled', 'completed' => $scope === 'completed' ? '' : '已交付',
            default => '',
        };
        return implode(' · ', array_filter([$report['supplement_for_report_id'] > 0 ? '补报' : '', $status]));
    }

    private static function delivery(array $arrangement): string
    {
        $methods = ['customer_vehicle' => '客户车辆', 'third_party' => '第三方配送', 'self_delivery' => '自配送', 'counter_pickup' => '客户自提'];
        $fields = ['earliest_delivery_time' => '最早', 'plate_number' => '车牌', 'vehicle_location' => '地点', 'driver_phone' => '司机电话'];
        $details = ['交接  ' . ($methods[$arrangement['delivery_method']] ?? '方式待完善')];
        foreach ($fields as $key => $label) {
            if (trim($arrangement[$key]) !== '') {
                $details[] = $label . ' ' . trim($arrangement[$key]);
            }
        }
        if ($arrangement['delivery_method'] === 'customer_vehicle' && $arrangement['missing_fields']) {
            $details[] = '待补 ' . implode('、', array_intersect_key($fields, array_flip($arrangement['missing_fields'])));
        }
        return implode(' · ', $details);
    }

    private static function lineDetails(array $line): array
    {
        $details = [];
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
                $details[] = $mode;
            }
        }
        $requirements = $line['requirements'];
        if ($line['specification'] !== '') {
            $requirements = implode('；', array_diff(explode('；', $requirements), ['规格 ' . $line['specification']]));
        }
        $notes = [trim($requirements), trim($line['remark'])];
        $alerts = [];
        if ($line['pending_measurement']) {
            $alerts[] = '待称重';
        } elseif (bccomp($line['shortage_quantity'] ?? '0', '0', 4) > 0) {
            $alerts[] = '缺 ' . self::amount($line['shortage_quantity'], $line['base_unit_name']);
        }
        if ($line['fulfillment_status'] === 'undelivered') {
            $alerts[] = '未送达 ' . ($line['undelivered_reason'] ?: '原因待核对');
        }
        return [...$alerts, ...$details, ...array_diff(array_unique($notes), ['', ...$alerts, ...$details])];
    }

    private static function document(array $blocks, int $topMargin, string $documentType): string
    {
        $boldHeading = $documentType === 'goods';
        // 固定行高并为栏分隔段落和字体替换留出余量，换栏位置可以显式补印名称。
        $capacity = self::PAGE_HEIGHT - $topMargin - self::BOTTOM_MARGIN - 500;
        $blocks = array_map(static function (array $block) use ($boldHeading): array {
            $block['heading_lines'] = self::entryLines($block['heading'], true, $boldHeading);
            foreach ($block['rows'] as &$row) {
                $row['lines'] = self::rowLines($row);
            }
            unset($row);
            return $block;
        }, $blocks);
        $columnBreak = self::balancedColumnBreak($blocks, $capacity);
        $body = '';
        $used = 0;
        foreach ($blocks as $blockIndex => $block) {
            $heading = $block['heading_lines'];
            $continuedEntry = $block['heading'];
            $continuedEntry[1] .= '（续）';
            $continuation = self::entryLines($continuedEntry, true, $boldHeading);
            $rows = $block['rows'];
            $opening = (count($heading) + self::requiredLines($rows, 0)) * self::BODY_LINE;
            if ($used && ($blockIndex === $columnBreak || $used + self::HEADING_GAP + $opening > $capacity)) {
                $body .= self::COLUMN_BREAK;
                $used = 0;
            }
            $gap = $used ? self::HEADING_GAP : 0;
            $body .= implode('', $gap ? self::entryLines($block['heading'], true, $boldHeading, $gap) : $heading);
            $used += count($heading) * self::BODY_LINE + $gap;
            $continuationHeight = count($continuation) * self::BODY_LINE;
            foreach ($rows as $index => $row) {
                $lines = $row['lines'];
                $required = self::requiredLines($rows, $index);
                $started = false;
                do {
                    $available = max(0, intdiv($capacity - $used, self::BODY_LINE));
                    $context = ($started || !empty($row['repeat_before'])) && !empty($row['continuation'])
                        ? self::wrapText($row['continuation'], self::BODY_WIDTH) : [];
                    if ($started && isset($row['split_context'])) {
                        $context = [...$context, ...self::wrapText($row['split_context'], self::BODY_WIDTH)];
                    }
                    $contextHeight = count($context) * self::BODY_LINE;
                    $freshLines = max(1, intdiv($capacity - $continuationHeight - $contextHeight, self::BODY_LINE));
                    if (!$available || ($index > 0 && $available < min($required, $freshLines))) {
                        $body .= self::COLUMN_BREAK . implode('', $continuation);
                        if ($context) {
                            $body .= self::paragraph($context);
                        }
                        $used = $continuationHeight + $contextHeight;
                        $available = $freshLines;
                    }
                    $part = array_splice($lines, 0, $available);
                    $body .= implode('', $part);
                    $used += count($part) * self::BODY_LINE;
                    $required = 1;
                    $started = true;
                } while ($lines);
            }
        }
        $body .= '<w:sectPr><w:headerReference w:type="default" r:id="header"/><w:footerReference w:type="default" r:id="footer"/>'
            . '<w:pgSz w:w="11906" w:h="' . self::PAGE_HEIGHT . '"/>'
            . '<w:pgMar w:top="' . $topMargin . '" w:right="740" w:bottom="' . self::BOTTOM_MARGIN . '" w:left="740"'
            . ' w:header="' . self::HEADER_DISTANCE . '" w:footer="320" w:gutter="0"/>'
            . '<w:cols w:num="2" w:space="448"/></w:sectPr>';
        return self::wrap('document', '<w:body>' . $body . '</w:body>');
    }

    private static function balancedColumnBreak(array $blocks, int $capacity): ?int
    {
        // 一张纸能放下完整分组时，在组与组之间换栏，让两栏接近等高。
        $heights = [];
        foreach ($blocks as $block) {
            $lines = count($block['heading_lines']);
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

    private static function requiredLines(array $rows, int $index): int
    {
        $count = count($rows[$index]['lines']);
        return empty($rows[$index]['keep_next']) ? min($count, 3) : $count + min(count($rows[$index + 1]['lines'] ?? []), 3);
    }

    private static function rowLines(array $row): array
    {
        if (!isset($row['entry'])) {
            return array_map(
                static fn(string $line) => self::paperLine(self::run($line, 22, false, '3E464D')),
                self::wrapText($row['text'], self::BODY_WIDTH)
            );
        }
        $lines = self::entryLines($row['entry']);
        if ($row['text'] !== '') {
            foreach (self::wrapText($row['text'], self::DETAIL_WIDTH) as $detail) {
                $lines[] = self::paperLine(self::run($detail, 22, false, '3E464D'), indent: self::NAME_TAB);
            }
        }
        return $lines;
    }

    private static function entryLines(array $entry, bool $heading = false, bool $boldName = false, int $before = 0): array
    {
        [$number, $name] = $entry;
        $amount = $entry[2] ?? '';
        $size = $heading ? 24 : 22;
        $amountWidth = self::textWidth($amount);
        $separateAmount = $amountWidth > 35;
        $nameWidth = ($heading ? 62 : 67) - ($amount === '' || $separateAmount ? 0 : $amountWidth + 6);
        $shade = $heading ? '<w:shd w:val="clear" w:fill="EFF1F3"/>' : '';
        $rightTab = '<w:tab w:val="right" w:pos="' . self::QUANTITY_TAB . '"/>';
        $format = $shade . '<w:tabs><w:tab w:val="left" w:pos="' . self::NAME_TAB . '"/>' . $rightTab . '</w:tabs>';
        $tab = '<w:r><w:tab/></w:r>';
        $lines = [];
        foreach (self::wrapText($name, $nameWidth) as $index => $line) {
            $runs = $index === 0 ? self::run(($heading ? ' ' : '□ ') . $number, 20, false, '424C55') : '';
            $runs .= $tab . self::run($line, $size, $boldName);
            if ($index === 0 && $amount !== '' && !$separateAmount) {
                $runs .= $tab . self::run($amount, $size, true);
            }
            $lines[] = self::paperLine($runs, before: $index === 0 ? $before : 0, format: $format);
        }
        if ($separateAmount) {
            $amountFormat = $shade . '<w:tabs>' . $rightTab . '</w:tabs>';
            foreach (self::wrapText($amount, $heading ? 74 : 80) as $amountLine) {
                $lines[] = self::paperLine($tab . self::run($amountLine, $size, true), format: $amountFormat);
            }
        }
        return $lines;
    }

    private static function wrapText(string $text, int $limit): array
    {
        $text = str_replace(["\r\n", "\r", '\\n', "\t"], ["\n", "\n", "\n", ' '], trim($text));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $text) ?? '';
        $lines = [];
        foreach (explode("\n", $text) as $source) {
            // 按显示宽度折行，同时保留电话、车牌、重量范围和数量单位。
            preg_match_all(
                '/\p{Han}?（续）|车牌 \p{Han}[A-Z][A-Z0-9]{5,7}|司机电话 \+?[0-9-]{7,18}|最早 \d{2}:\d{2}|\p{Han}[A-Z][A-Z0-9]{5,7}'
                    . '|单体重量|总重范围|\d{1,6}(?:\.\d{1,4})?[–-]\d{1,6}(?:\.\d{1,4})?(?: \p{Han}{1,3}(?=\P{Han}|$))?'
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

    private static function paragraph(array $lines, int $size = 22, bool $bold = false, int $lineHeight = self::BODY_LINE, int $before = 0): string
    {
        $runs = array_map(static fn(string $line) => self::run($line, $size, $bold), $lines);
        return self::paperLine(implode('<w:r><w:br/></w:r>', $runs), $lineHeight, $before);
    }

    private static function run(string $line, int $size, bool $bold = false, string $color = '000000'): string
    {
        return '<w:r><w:rPr>' . ($bold ? '<w:b/>' : '') . '<w:color w:val="' . $color . '"/><w:sz w:val="' . $size
            . '"/><w:szCs w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">'
            . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8') . '</w:t></w:r>';
    }

    private static function paperLine(string $runs, int $lineHeight = self::BODY_LINE, int $before = 0, string $format = '', int $indent = 0): string
    {
        return '<w:p><w:pPr><w:widowControl w:val="0"/>' . $format . '<w:snapToGrid w:val="0"/>'
            . '<w:spacing w:before="' . $before . '" w:after="0" w:line="' . $lineHeight . '" w:lineRule="exact"/>'
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
