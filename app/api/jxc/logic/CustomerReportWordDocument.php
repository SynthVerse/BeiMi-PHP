<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

/** 固定 A4 纵向业务模板。仅生成 OOXML，不执行宏、不加载外部资源。 */
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
                'word/header1.xml' => self::wrap('hdr', self::paragraph($snapshot['store_name'] . ' · 客户报货总览 · ' . $snapshot['query']['delivery_date'], 18)),
                'word/footer1.xml' => self::wrap('ftr', '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>店内备货核对    第 </w:t></w:r><w:fldSimple w:instr="PAGE"/><w:r><w:t> 页    文档修改不回写业务数据</w:t></w:r></w:p>'),
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
        $body = self::paragraph('客户报货总览', 42, true, true)
            . self::paragraph($data['store_name'] . '    送货日期 ' . $query['delivery_date'], 24, true, true)
            . self::paragraph('导出时间 ' . $data['generated_at'] . '    范围：' . $scope . ($query['keyword'] !== '' ? '；客户含“' . $query['keyword'] . '”' : '；所有客户'))
            . self::paragraph('主客户 ' . $summary['main_customer_count'] . ' 位    收货对象 ' . $summary['customer_count'] . ' 处    报货单 ' . $summary['report_count'] . ' 张    明细 ' . $summary['line_count'] . ' 条')
            . self::paragraph('含补报，不含取消单；数量按原报货单位汇总。缺货为报货记录中的库存提示，未知重量保持待称重。', 20);
        foreach ($data['customers'] as $index => $customer) {
            $body .= self::paragraph(sprintf('%02d  ', $index + 1) . $customer['main_customer_name'], 30, true, true);
            if ($customer['delivery_customer_name'] !== $customer['main_customer_name']) {
                $body .= self::paragraph('实际收货  ' . $customer['delivery_customer_name'], 22, false, true);
            }
            foreach ($customer['reports'] as $report) {
                $body .= self::paragraph(($report['supplement_for_report_id'] > 0 ? '补报单号 ' : '报货单号 ') . $report['sn'] . '    ' . $report['status_label'], 21, false, true)
                    . self::paragraph(self::delivery($report['delivery_arrangement']), 21, false, true);
                $rows = [];
                foreach ($report['items'] as $i => $line) {
                    $processing = [];
                    foreach ($line['processing_groups'] as $group) {
                        $processing[] = $group['name'] . ' ' . self::quantity($group['quantity']) . $line['unit_name']
                            . ($group['processes'] ? '：' . implode(' → ', $group['processes']) : '');
                    }
                    $notes = array_filter([$line['requirements'], $line['remark'],
                        $line['pending_measurement'] ? '待称重，缺货量未知' : (bccomp($line['shortage_quantity'] ?? '0', '0', 4) > 0 ? '缺货 ' . self::quantity($line['shortage_quantity']) . $line['base_unit_name'] : ''),
                        $line['fulfillment_status'] === 'undelivered' ? '未送达：' . ($line['undelivered_reason'] ?: '原因待核对') : '']);
                    $rows[] = [(string)($i + 1), $line['goods_name'] . ($line['sku_name'] !== '' ? '\n' . $line['sku_name'] : ''),
                        self::quantity($line['quantity']) . ' ' . $line['unit_name'], implode("\n", $processing) ?: '无分组记录', implode("\n", $notes) ?: '—'];
                }
                $body .= self::table(['序号', '商品与 SKU', '报货数量', '分批处理', '客户要求与备注'], $rows, [600, 1900, 1100, 2400, 3638]);
                $body .= self::paragraph('总备注  ' . ($report['remark'] ?: '无'), 21, false, true);
            }
            $body .= self::paragraph('备货人：____________    核对人：____________', 20);
        }
        $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>' . self::paragraph('商品需求汇总', 30, true, true);
        $rows = [];
        foreach ($data['goods_summary'] as $good) {
            $processing = [];
            foreach ($good['processing_summary'] as $group) {
                $processing[] = implode(' → ', $group['processes']) . ' ' . self::quantity($group['quantity']) . $good['unit_name'];
            }
            $rows[] = [$good['goods_name'] . "\n" . ($good['sku_name'] ?: '默认规格'), self::quantity($good['quantity']) . ' ' . $good['unit_name'], (string)$good['customer_count'] . ' 处', implode("\n", $processing) ?: '无分组记录'];
        }
        $body .= self::table(['商品与 SKU', '总报货量', '收货对象', '分批处理合计'], $rows, [2494, 1644, 1530, 3970]);
        $totals = [];
        foreach ($summary['unit_totals'] as $unit => $quantity) { $totals[] = self::quantity($quantity) . ' ' . $unit; }
        $body .= self::paragraph('原单位合计  ' . implode(' / ', $totals), 22, true)
            . self::paragraph('不同单位分别列示，不按均重将条、只、件换算为斤；分批处理和工序任务不重复计入报货总量。', 20)
            . '<w:sectPr><w:headerReference w:type="default" r:id="header"/><w:footerReference w:type="default" r:id="footer"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1020" w:right="1134" w:bottom="1020" w:left="1134" w:header="400" w:footer="400"/></w:sectPr>';
        return self::wrap('document', '<w:body>' . $body . '</w:body>');
    }

    private static function delivery(array $arrangement): string
    {
        $method = ['customer_vehicle' => '客户车辆交付', 'third_party' => '第三方配送', 'self_delivery' => '自配送', 'counter_pickup' => '客户自提'][$arrangement['delivery_method']] ?? '送货安排待完善';
        $details = array_filter([$arrangement['earliest_delivery_time'], $arrangement['plate_number'], $arrangement['vehicle_location'], $arrangement['driver_phone']]);
        return $method . ($details ? '  ' . implode('  ', $details) : '') . ($arrangement['status'] !== 'ready' ? '（资料待完善）' : '');
    }

    private static function table(array $headers, array $rows, array $widths): string
    {
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="9638" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) { $xml .= '<w:' . $side . ' w:val="single" w:sz="4" w:color="B8B8B8"/>'; }
        $xml .= '</w:tblBorders><w:tblCellMar><w:top w:w="85" w:type="dxa"/><w:left w:w="90" w:type="dxa"/><w:bottom w:w="85" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($widths as $width) { $xml .= '<w:gridCol w:w="' . $width . '"/>'; }
        $xml .= '</w:tblGrid>';
        foreach (array_merge([$headers], $rows) as $i => $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/>' . ($i === 0 ? '<w:tblHeader/>' : '') . '</w:trPr>';
            foreach ($row as $j => $value) {
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$j] . '" w:type="dxa"/><w:vAlign w:val="center"/>' . ($i === 0 ? '<w:shd w:fill="EFEFEF"/>' : '') . '</w:tcPr>' . self::paragraph($value, 21, $i === 0, $i === 0 || count($rows) <= 8, $widths[$j] <= 1644) . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl>';
    }

    private static function paragraph(string $text, int $size = 21, bool $bold = false, bool $keep = false, bool $center = false): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
        $lines = explode("\n", str_replace(['\\n', "\r"], ["\n", ''], $text));
        $runs = [];
        foreach ($lines as $line) { $runs[] = '<w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t>'; }
        $style = $size === 42 ? '<w:pStyle w:val="Title"/>' : ($size === 30 ? '<w:pStyle w:val="Heading1"/>' : '');
        return '<w:p><w:pPr>' . $style . '<w:spacing w:after="85" w:line="300" w:lineRule="auto"/>' . ($keep ? '<w:keepNext/>' : '') . ($center ? '<w:jc w:val="center"/>' : '') . '</w:pPr><w:r><w:rPr><w:sz w:val="' . $size . '"/>' . ($bold ? '<w:b/>' : '') . '</w:rPr>' . implode('<w:br/>', $runs) . '</w:r></w:p>';
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
