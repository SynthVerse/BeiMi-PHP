<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

/** A4 纵向紧凑明细模板。仅生成 OOXML，不执行宏、不加载外部资源。 */
final class CustomerReportWordDocument
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public static function create(array $snapshot): string
    {
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
                'word/document.xml' => self::document($snapshot),
                'word/header1.xml' => self::wrap('hdr', self::paragraph($snapshot['store_name'] . ' · 报货汇总 · ' . $snapshot['query']['delivery_date'], 18)),
                'word/footer1.xml' => self::wrap('ftr', '<w:p><w:pPr><w:spacing w:line="360" w:lineRule="exact"/><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:sz w:val="18"/></w:rPr><w:t xml:space="preserve">店内备货核对    第 </w:t></w:r><w:fldSimple w:instr="PAGE"/><w:r><w:rPr><w:sz w:val="18"/></w:rPr><w:t xml:space="preserve"> 页    文档修改不回写业务数据</w:t></w:r></w:p>'),
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

    private static function document(array $data): string
    {
        $query = $data['query']; $summary = $data['summary'];
        $scope = ['all' => '全部有效报货', 'pending' => '待处理报货', 'completed' => '已完成报货'][$query['status']];
        $body = self::paragraph('报货汇总', 32, true, true, false, 60)
            . self::paragraph($data['store_name'] . '    送货日期 ' . $query['delivery_date'] . '    紧凑明细版', 21, false, true, false, 40)
            . self::paragraph('收货对象 ' . $summary['customer_count'] . ' 处    主客户 ' . $summary['main_customer_count'] . ' 位    报货单 ' . $summary['report_count'] . ' 张    明细 ' . $summary['line_count'] . ' 条    ' . $scope . '含补报', 21, false, true, false, 40)
            . self::paragraph('导出时间 ' . $data['generated_at'] . ($query['keyword'] !== '' ? '    客户含“' . $query['keyword'] . '”' : ''), 21, false, true, false, 40)
            . self::paragraph('数量按原报货单位列示；待称重项目的缺货量未知。勾选栏用于备货核对。', 21, false, false, false, 80);
        foreach ($data['customers'] as $index => $customer) {
            $receiver = $customer['main_customer_name'] . ($customer['delivery_customer_name'] !== $customer['main_customer_name'] ? ' / ' . $customer['delivery_customer_name'] : '');
            $body .= self::paragraph(sprintf('%02d  ', $index + 1) . $receiver, 26, true, true, false, 40, 120);
            $remarks = [];
            foreach ($customer['reports'] as $report) {
                $label = ($report['supplement_for_report_id'] > 0 ? '补报 ' : '单号 ') . $report['sn'];
                $body .= self::paragraph($label . '  ' . $report['status_label'] . '  |  ' . self::delivery($report['delivery_arrangement']), 21, false, true, false, 40);
                $rows = [];
                foreach ($report['items'] as $line) {
                    $processing = [];
                    foreach ($line['processing_groups'] as $group) {
                        $showQuantity = count($line['processing_groups']) > 1 || bccomp($group['quantity'], $line['quantity'], 4) !== 0;
                        $processing[] = ($showQuantity ? $group['name'] . ' ' . self::quantity($group['quantity']) . $line['unit_name'] . ' ' : '')
                            . ($group['processes'] ? implode('→', $group['processes']) : '无工序记录');
                    }
                    $notes = array_filter([$line['requirements'], $line['remark'],
                        $line['pending_measurement'] ? '待称重' : (bccomp($line['shortage_quantity'] ?? '0', '0', 4) > 0 ? '缺货 ' . self::quantity($line['shortage_quantity']) . $line['base_unit_name'] : ''),
                        $line['fulfillment_status'] === 'undelivered' ? '未送达：' . ($line['undelivered_reason'] ?: '原因待核对') : '']);
                    $rows[] = ['□ ' . $line['goods_name'] . ($line['sku_name'] !== '' ? ' ' . $line['sku_name'] : ''),
                        self::quantity($line['quantity']) . $line['unit_name'], implode('；', $processing) ?: '无分组记录',
                        str_replace(["\r\n", "\r", "\n", '\\n'], '，', implode('；', $notes)) ?: '—'];
                }
                $body .= self::table(['核对 商品及规格', '数量', '分批加工', '要求及提示'], $rows, [2324, 1020, 3005, 4025], [1], true);
                $remark = trim(str_replace(["\r\n", "\r", "\n"], ' ', $report['remark']));
                if ($remark !== '') { $remarks[$remark][] = $label; }
            }
            $notes = [];
            foreach ($remarks as $remark => $labels) {
                $notes[] = (count($remarks) > 1 ? implode(' / ', $labels) . '：' : '') . $remark;
            }
            // 备注结束本客户的段落链，避免把相邻客户一起推到下一页。
            $body .= self::paragraph('备注  ' . (implode('；', $notes) ?: '无'), 21, false, false, false, 60, 60);
        }
        $body .= self::paragraph('商品需求汇总', 23, true, true, false, 60, 140);
        $rows = [];
        foreach ($data['goods_summary'] as $good) {
            $processing = [];
            foreach ($good['processing_summary'] as $group) {
                $processing[] = implode('→', $group['processes']) . ' ' . self::quantity($group['quantity']) . $good['unit_name'];
            }
            $rows[] = [$good['goods_name'] . ' ' . ($good['sku_name'] ?: '默认规格'), self::quantity($good['quantity']) . $good['unit_name'], (string)$good['customer_count'] . '处', implode('；', $processing) ?: '无分组记录'];
        }
        $body .= self::table(['商品及规格', '原单位总量', '收货对象', '加工合计'], $rows, [2665, 1587, 1191, 4931], [1, 2]);
        $totals = [];
        foreach ($summary['unit_totals'] as $unit => $quantity) { $totals[] = self::quantity($quantity) . ' ' . $unit; }
        $body .= self::paragraph('原单位合计  ' . implode(' / ', $totals) . '    备货人：________    核对人：________', 21, true, false, false, 0, 80)
            . '<w:sectPr><w:headerReference w:type="default" r:id="header"/><w:footerReference w:type="default" r:id="footer"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="709" w:right="765" w:bottom="680" w:left="765" w:header="283" w:footer="283"/></w:sectPr>';
        return self::wrap('document', '<w:body>' . $body . '</w:body>');
    }

    private static function delivery(array $arrangement): string
    {
        $method = ['customer_vehicle' => '客户车辆', 'third_party' => '第三方配送', 'self_delivery' => '自配送', 'counter_pickup' => '客户自提'][$arrangement['delivery_method']] ?? '送货安排待完善';
        $details = array_filter([$arrangement['earliest_delivery_time'], $method, $arrangement['plate_number'], $arrangement['vehicle_location'], $arrangement['driver_phone']]);
        return implode('  ', $details) . ($arrangement['status'] !== 'ready' ? '  资料待完善' : '');
    }

    private static function table(array $headers, array $rows, array $widths, array $centered = [], bool $keepRows = false): string
    {
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:jc w:val="center"/><w:tblLayout w:type="fixed"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) { $xml .= '<w:' . $side . ' w:val="single" w:sz="5" w:color="D9D9D9"/>'; }
        $xml .= '</w:tblBorders><w:tblCellMar><w:top w:w="45" w:type="dxa"/><w:left w:w="85" w:type="dxa"/><w:bottom w:w="45" w:type="dxa"/><w:right w:w="85" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($widths as $width) { $xml .= '<w:gridCol w:w="' . $width . '"/>'; }
        $xml .= '</w:tblGrid>';
        foreach (array_merge([$headers], $rows) as $i => $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/>' . ($i === 0 ? '<w:tblHeader/>' : '') . '</w:trPr>';
            foreach ($row as $j => $value) {
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$j] . '" w:type="dxa"/><w:vAlign w:val="center"/>' . ($i === 0 ? '<w:shd w:fill="EFEFEF"/>' : '') . '</w:tcPr>' . self::paragraph($value, 24, $i === 0, $i === 0 || ($keepRows && count($rows) <= 8), in_array($j, $centered, true)) . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl>';
    }

    private static function paragraph(string $text, int $size = 21, bool $bold = false, bool $keep = false, bool $center = false, int $after = 0, int $before = 0): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
        $lines = explode("\n", str_replace(['\\n', "\r"], ["\n", ''], $text));
        $runs = [];
        foreach ($lines as $line) { $runs[] = '<w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t>'; }
        $style = $size === 32 ? '<w:pStyle w:val="Title"/>' : ($size === 26 || $size === 23 ? '<w:pStyle w:val="Heading1"/>' : '');
        $lineHeight = max(360, $size * 10 + 100);
        return '<w:p><w:pPr>' . $style . '<w:keepLines/><w:widowControl w:val="0"/><w:snapToGrid w:val="0"/><w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="' . $lineHeight . '" w:lineRule="exact"/>' . ($keep ? '<w:keepNext/>' : '') . ($center ? '<w:jc w:val="center"/>' : '') . '</w:pPr><w:r><w:rPr><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>' . ($bold ? '<w:b/>' : '') . '</w:rPr>' . implode('<w:br/>', $runs) . '</w:r></w:p>';
    }

    private static function quantity(string $value): string { return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value; }
    private static function wrap(string $tag, string $body): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:' . $tag . ' xmlns:w="' . self::NS . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . $body . '</w:' . $tag . '>'; }
    private static function styles(): string { return self::wrap('styles', '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Microsoft YaHei" w:hAnsi="Microsoft YaHei" w:eastAsia="Microsoft YaHei"/><w:sz w:val="21"/><w:color w:val="000000"/><w:lang w:val="zh-CN"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:outlineLvl w:val="0"/></w:pPr></w:style>'); }
    private static function relationships(array $parts): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($parts as $type => $target) { $xml .= '<Relationship Id="' . $type . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $type . '" Target="' . $target . '"/>'; }
        return $xml . '</Relationships>';
    }
}
