<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 退货查询只投影现有事实：待定处置、财务实物单与原业务历史不重复计入。 */
final class FinanceReturnQuery
{
    private static function q(string $table) { return Db::name($table)->where('tenant_id', FinanceAccess::tenant()); }

    public static function purchaseProgress(int $document): array
    {
        $lines = []; $ledger = new FinanceLedger(FinanceAccess::tenant());
        foreach (self::q('finance_purchase_return_line')->where('document_id', $document)->order('id')->select()->toArray() as $row) {
            $basis = FinanceValue::decode($row['snapshot']); $accepted = '0.0000'; $back = '0.0000'; $loss = '0.0000'; $money = '0.00'; $credits = []; $moneyKnown = false;
            foreach (self::q('finance_purchase_return_resolution')->where('return_line_id', $row['id'])->order('id')->select()->toArray() as $resolution) {
                if ($resolution['kind'] === 'accepted') { $accepted = bcadd($accepted, $resolution['quantity'], 4); $moneyKnown = true; }
                if ($resolution['kind'] === 'returned') { $back = bcadd($back, $resolution['quantity'], 4); }
                if ($resolution['kind'] === 'loss') { $loss = bcadd($loss, $resolution['quantity'], 4); }
                foreach (FinanceValue::decode($resolution['snapshot'])['created_sources'] ?? [] as $reference) {
                    if (isset($credits[$reference])) { continue; }
                    $credit = $ledger->source($reference);
                    if ($credit['category'] !== 'supplier_refund' || (int)$credit['subject_id'] !== (int)$row['vendor_id']) { continue; }
                    $credits[$reference] = $credit; $money = bcadd($money, $credit['balance'], 2);
                }
            }
            $unresolved = bcsub(bcsub(bcsub($row['quantity'], $accepted, 4), $back, 4), $loss, 4);
            $lines[] = ['return_line_id' => (int)$row['id'], 'arrival_line_id' => (int)$row['arrival_line_id'], 'subject_id' => (int)$row['vendor_id'],
                'goods_name' => $basis['goods_name'] ?? '', 'sku_name' => $basis['sku_name'] ?? '', 'unit_name' => $basis['base_unit_name'] ?? '',
                'actual_quantity' => $row['quantity'], 'accepted_quantity' => $accepted, 'returned_quantity' => $back, 'loss_quantity' => $loss,
                'unresolved_quantity' => $unresolved, 'money_remaining' => $moneyKnown || bccomp($unresolved, '0', 4) === 0 ? $money : null, 'credits' => array_values($credits)];
        }
        $active = FinanceIntegration::active();
        return ['lines' => $lines, 'can_process' => $active && FinanceAccess::has('finance.purchase.prepare'),
            'can_refund' => $active && FinanceAccess::has(FinanceDocumentPolicy::type('supplier_refund')['prepare']),
            'can_credit_allocate' => $active && FinanceAccess::has(FinanceDocumentPolicy::type('supplier_credit_allocate')['prepare'])];
    }

    public static function lists(array $params): array
    {
        $kind = $params['return_kind'] ?? '';
        if (!in_array($kind, ['purchase', 'sales'], true)) { throw new \DomainException('请选择采购退货或客户退货'); }
        $type = $kind === 'purchase' ? 'purchase_return_actual' : 'customer_return_actual';
        $financial = true;
        try { FinanceDocumentPolicy::read($type); } catch (\DomainException) { $financial = false; }
        $pending = $kind === 'purchase' && WorkforceLogic::hasAnyPermission(['purchase.plan.view', 'purchase.plan.manage', 'report.edit']);
        if (!$financial && !$pending) { FinanceDocumentPolicy::read($type); }
        $page = FinanceValue::id($params['page'] ?? 1); $parts = [];
        $from = empty($params['date_from']) ? null : FinanceValue::date($params['date_from']);
        $to = empty($params['date_to']) ? null : FinanceValue::date($params['date_to']);
        if ($from && $to && $from > $to) { throw new \DomainException('开始日期不能晚于结束日期'); }
        if ($financial) {
            $subjectTable = $kind === 'purchase' ? 'vendor' : 'customer'; $subjectName = $kind === 'purchase' ? 'supplier_name' : 'customer_name';
            $query = Db::name('finance_document')->alias('d')
                ->leftJoin($subjectTable . ' subject', "subject.tenant_id=d.tenant_id AND subject.id=CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.confirmed_result,'$.subject_id')),JSON_UNQUOTE(JSON_EXTRACT(d.payload,'$.subject_id'))) AS UNSIGNED)")
                ->where('d.tenant_id', FinanceAccess::tenant())->where('d.type', $type);
            if ($kind === 'purchase') {
                $linked = Db::name('pending_goods_return_fact')->alias('f')->join('finance_purchase_return_line r', 'r.tenant_id=f.tenant_id AND r.id=f.finance_line_id')
                    ->where('f.tenant_id', FinanceAccess::tenant())->field('r.document_id')->buildSql();
                // 无待定查看权限的财务人员仍可通过自己的财务单读取；有权限时只显示待定聚合行。
                if ($pending) { $query->whereRaw('d.id NOT IN ' . $linked); }
            }
            $parts[] = $query->fieldRaw("'finance' AS record_kind,d.id,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.confirmed_result,'$.actual_date')),JSON_UNQUOTE(JSON_EXTRACT(d.payload,'$.actual_date')),DATE(FROM_UNIXTIME(d.create_time))) AS actual_date,CONCAT(CAST(d.id AS CHAR),' ',COALESCE(subject.$subjectName,''),' ',COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.confirmed_result,'$.subject_name')),''),' ',COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.payload,'$.subject_name')),''),' ',COALESCE(CAST(d.confirmed_result AS CHAR),''),' ',COALESCE(CAST(d.payload AS CHAR),'')) AS search_text")->buildSql(false);
            $table = $kind === 'purchase' ? 'purchase_return_order' : 'sales_return_order';
            $lineNames = $kind === 'purchase'
                ? self::q('purchase_return_order_lists')->fieldRaw("purchase_return_order_id AS order_id,GROUP_CONCAT(goods_name SEPARATOR ' ') AS goods_names")->group('purchase_return_order_id')->buildSql()
                : self::q('order_goods')->where('order_type', 'sales-return')->fieldRaw("order_id,GROUP_CONCAT(name SEPARATOR ' ') AS goods_names")->group('order_id')->buildSql();
            $legacy = Db::name($table)->alias('o')->leftJoin([$lineNames => 'names'], 'names.order_id=o.id')->where('o.tenant_id', FinanceAccess::tenant());
            if ($kind === 'purchase' && $pending) {
                $legacy->whereRaw('o.id NOT IN ' . self::q('pending_goods_return_fact')->whereNotNull('legacy_order_id')->field('legacy_order_id')->buildSql());
            }
            $name = $kind === 'purchase' ? 'supplier_name' : 'customer_name';
            $parts[] = $legacy->fieldRaw("'legacy' AS record_kind,o.id,DATE(FROM_UNIXTIME(o.datetimesingle)) AS actual_date,CONCAT(o.order_sn,' ',o.original_order_sn,' ',o.$name,' ',COALESCE(names.goods_names,'')) AS search_text")->buildSql(false);
        }
        if ($pending) {
            // 同一待定记录按安排及每次真实退离日期可查；只在筛选范围内选展示日期，再聚合去重。
            $events = [self::q('pending_goods_return')->fieldRaw('pending_goods_id,DATE(FROM_UNIXTIME(create_time)) AS event_date')->buildSql(false),
                Db::name('pending_goods_return_fact')->alias('f')->join('pending_goods_return r', 'r.tenant_id=f.tenant_id AND r.id=f.pending_return_id')
                    ->join('finance_purchase_return_line l', 'l.tenant_id=f.tenant_id AND l.id=f.finance_line_id')->where('f.tenant_id', FinanceAccess::tenant())
                    ->field('r.pending_goods_id,l.business_date AS event_date')->buildSql(false),
                Db::name('pending_goods_return_fact')->alias('f')->join('pending_goods_return r', 'r.tenant_id=f.tenant_id AND r.id=f.pending_return_id')
                    ->join('purchase_return_order o', 'o.tenant_id=f.tenant_id AND o.id=f.legacy_order_id')->where('f.tenant_id', FinanceAccess::tenant())
                    ->fieldRaw('r.pending_goods_id,DATE(FROM_UNIXTIME(o.datetimesingle)) AS event_date')->buildSql(false)];
            $dates = Db::table('(' . implode(' UNION ALL ', $events) . ')')->alias('events');
            if ($from) { $dates->where('event_date', '>=', $from); }
            if ($to) { $dates->where('event_date', '<=', $to); }
            $dates = $dates->fieldRaw('pending_goods_id,MAX(event_date) AS actual_date')->group('pending_goods_id')->buildSql();
            $returns = Db::name('pending_goods_return')->alias('r')->join('supply_order s', 's.tenant_id=r.tenant_id AND s.id=r.supply_order_id')
                ->leftJoin('vendor v', 'v.tenant_id=s.tenant_id AND v.id=s.supplier_id')
                ->where('r.tenant_id', FinanceAccess::tenant())->fieldRaw("r.pending_goods_id,MIN(r.create_time) AS create_time,GROUP_CONCAT(DISTINCT CONCAT(COALESCE(NULLIF(s.supplier_name,''),v.supplier_name,''),' ',s.order_sn) SEPARATOR ' ') AS suppliers")
                ->group('r.pending_goods_id')->buildSql();
            $parts[] = Db::name('pending_goods')->alias('p')->join([$returns => 'r'], 'r.pending_goods_id=p.id')->join([$dates => 'dates'], 'dates.pending_goods_id=p.id')
                ->leftJoin('goods g', 'g.tenant_id=p.tenant_id AND g.id=p.goods_id')->leftJoin('customer_report c', 'c.tenant_id=p.tenant_id AND c.id=p.report_id')
                ->where('p.tenant_id', FinanceAccess::tenant())->fieldRaw("'pending' AS record_kind,p.id,dates.actual_date,CONCAT(COALESCE(g.name,''),' ',COALESCE(c.sn,''),' ',r.suppliers) AS search_text")->buildSql(false);
        }
        // JSON、旧单与待定表的历史排序规则不同；统一只读投影，避免跨来源 UNION 依赖表默认值。
        $parts = array_map(static fn(string $sql): string => "SELECT CONVERT(record_kind USING utf8mb4) COLLATE utf8mb4_unicode_ci AS record_kind,id,CAST(actual_date AS DATE) AS actual_date,CONVERT(search_text USING utf8mb4) COLLATE utf8mb4_unicode_ci AS search_text FROM ($sql) AS normalized", $parts);
        $query = Db::table('(' . implode(' UNION ALL ', $parts) . ')')->alias('returns');
        $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        if ($keyword !== '') { $query->whereLike('search_text', '%' . addcslashes($keyword, '%_\\') . '%'); }
        if ($from) { $query->where('actual_date', '>=', $from); }
        if ($to) { $query->where('actual_date', '<=', $to); }
        $count = (clone $query)->count();
        $rows = $query->field('record_kind,id,actual_date')->order('actual_date desc,record_kind,id desc')->limit(($page - 1) * 20, 20)->select()->toArray();
        return ['tenant_id' => FinanceAccess::tenant(), 'page' => $page, 'count' => $count, 'has_more' => $page * 20 < $count,
            'can_create' => $financial && FinanceIntegration::active() && FinanceAccess::has(FinanceDocumentPolicy::type($type)['prepare']),
            'lists' => array_map(static fn(array $row): array => self::present($row, $kind, $type), $rows)];
    }

    private static function present(array $row, string $kind, string $type): array
    {
        $id = (int)$row['id']; $record = $row['record_kind'];
        $base = ['id' => $record . ':' . $id, 'record_kind' => $record, 'actual_date' => $row['actual_date'], 'pending_goods_id' => 0,
            'document_type' => '', 'document_id' => 0, 'legacy_order_id' => 0, 'subject_id' => 0, 'subject_name' => '',
            'order_sn' => '', 'original_order_sn' => '', 'goods_summary' => '', 'quantities' => [], 'money_remaining' => null,
            'status_label' => '', 'can_process' => false];
        if ($record === 'pending') {
            $detail = PendingGoodsLogic::detail(['id' => $id]);
            if ($detail === false) { throw new \DomainException(PendingGoodsLogic::getError()); }
            $actual = '0.0000'; $depart = '0.0000'; $unresolved = '0.0000'; $money = '0.00'; $vendors = []; $moneyKnown = false;
            foreach ($detail['returns'] as $return) {
                $depart = bcadd($depart, $return['remaining_qty'], 4);
                $supply = self::q('supply_order')->where('id', $return['supply_order_id'])->find();
                if ($supply) { $vendors[(int)$supply['supplier_id']] = $supply['supplier_name'] ?: (string)self::q('vendor')->where('id', $supply['supplier_id'])->value('supplier_name'); }
                foreach ($return['facts'] as $fact) {
                    $actual = bcadd($actual, $fact['quantity'], 4); $unresolved = bcadd($unresolved, $fact['unresolved_qty'], 4); $money = bcadd($money, $fact['money_remaining'], 2);
                    $moneyKnown = $moneyKnown || in_array('accepted', array_column($fact['history'], 'kind'), true);
                }
            }
            $status = bccomp($depart, '0', 4) > 0 ? '待实际退走' : (bccomp($unresolved, '0', 4) > 0 ? '待供应商确认' : (bccomp($money, '0', 2) > 0 ? '待处理货款' : (bccomp($detail['pending_qty'], '0', 4) > 0 ? '仍有待定货物' : '处理完成')));
            return array_replace($base, ['pending_goods_id' => $id, 'subject_id' => count($vendors) === 1 ? (int)array_key_first($vendors) : 0,
                'subject_name' => implode('、', $vendors), 'order_sn' => '退货处理', 'original_order_sn' => $detail['report_sn'],
                'goods_summary' => trim($detail['goods_name'] . ' ' . $detail['sku_name']),
                'money_remaining' => $moneyKnown || (bccomp($depart, '0', 4) === 0 && bccomp($unresolved, '0', 4) === 0) ? $money : null,
                'quantities' => [['unit_name' => $detail['unit_name'], 'actual_quantity' => $actual, 'pending_quantity' => $depart, 'unresolved_quantity' => $unresolved]],
                'status_label' => $status, 'can_process' => WorkforceLogic::hasPermission('purchase.plan.manage')]);
        }
        if ($record === 'finance') {
            $document = self::q('finance_document')->where('id', $id)->find();
            $fact = FinanceValue::decode($document['confirmed_result']); $input = FinanceValue::decode($document['payload']); $data = $document['status'] === 'confirmed' ? $fact : $input;
            $subject = (int)($data['subject_id'] ?? 0); $name = $data['subject_name'] ?? '';
            if ($name === '') { $name = self::q($kind === 'purchase' ? 'vendor' : 'customer')->where('id', $subject)->value($kind === 'purchase' ? 'supplier_name' : 'customer_name') ?: ''; }
            $quantities = []; $goods = []; $money = null; $status = ['draft' => '未提交', 'pending' => '待确认', 'confirmed' => '实物已登记'][$document['status']] ?? '待核实';
            $replacement = (int)self::q('finance_correction')->where('original_document_id', $id)->value('replacement_document_id');
            $reversal = !empty($fact['reversal_of']);
            if ($replacement || $reversal) {
                $replacementFact = $replacement ? FinanceValue::decode(self::q('finance_document')->where('id', $replacement)->value('confirmed_result')) : [];
                return array_replace($base, ['document_type' => $type, 'document_id' => $id, 'subject_id' => $subject, 'subject_name' => $name,
                    'order_sn' => '退货记录 #' . $id, 'original_order_sn' => $data['order_sn'] ?? '',
                    'status_label' => $reversal ? '撤销记录' : (!empty($replacementFact['reversal_of']) ? '已撤销' : '已更正'),
                    'replacement_document_id' => $replacement]);
            }
            if ($document['status'] === 'confirmed' && $kind === 'purchase') {
                $progress = self::purchaseProgress($id); $money = '0.00'; $unresolved = false; $moneyKnown = false;
                foreach ($progress['lines'] as $line) {
                    $goods[] = trim($line['goods_name'] . ' ' . $line['sku_name']);
                    $quantities[] = ['unit_name' => $line['unit_name'], 'actual_quantity' => $line['actual_quantity'], 'pending_quantity' => '0.0000', 'unresolved_quantity' => $line['unresolved_quantity']];
                    if ($line['money_remaining'] !== null) { $money = bcadd($money, $line['money_remaining'], 2); $moneyKnown = true; }
                    $unresolved = $unresolved || bccomp($line['unresolved_quantity'], '0', 4) > 0;
                }
                $status = $unresolved ? '待供应商确认' : (bccomp($money, '0', 2) > 0 ? '待处理货款' : '处理完成');
                if (!$moneyKnown) { $money = null; }
            } elseif ($document['status'] === 'confirmed') {
                $goods[] = trim(($data['goods_name'] ?? '') . ' ' . ($data['sku_name'] ?? ''));
                $quantities[] = ['unit_name' => $data['base_unit_name'] ?? '', 'actual_quantity' => $data['quantity'], 'pending_quantity' => '0.0000', 'unresolved_quantity' => null];
                $status = ($data['disposition_status'] ?? '') === 'pending' ? '已验收，实物去向待核' : '已验收，款项另行处理';
            }
            $original = $data['original_order_sn'] ?? $data['order_sn'] ?? '';
            if ($original === '' && $kind === 'purchase') {
                $ids = array_unique(array_filter(array_map(static fn(array $line): int => (int)($line['original_supply_order_id'] ?? 0), $data['lines'] ?? [])));
                if ($ids) { $original = implode('、', self::q('supply_order')->whereIn('id', $ids)->column('order_sn')); }
            }
            return array_replace($base, ['document_type' => $type, 'document_id' => $id, 'subject_id' => $subject, 'subject_name' => $name,
                'order_sn' => '退货记录 #' . $id, 'original_order_sn' => $original, 'goods_summary' => implode('、', array_unique($goods)),
                'quantities' => $quantities, 'money_remaining' => $money, 'status_label' => $status,
                'can_process' => FinanceAccess::has(FinanceDocumentPolicy::type($type)['prepare'])]);
        }
        $legacy = self::q($kind === 'purchase' ? 'purchase_return_order' : 'sales_return_order')->where('id', $id)->find();
        $lines = $kind === 'purchase' ? self::q('purchase_return_order_lists')->where('purchase_return_order_id', $id)->select()->toArray()
            : self::q('order_goods')->where('order_type', 'sales-return')->where('order_id', $id)->select()->toArray();
        $goods = []; $quantities = [];
        foreach ($lines as $line) {
            $goods[] = $line['goods_name'] ?? $line['name'] ?? '';
            $quantities[] = ['unit_name' => $line['unit_name'] ?? $line['unit'] ?? '', 'actual_quantity' => $line['return_num'] ?? $line['number'], 'pending_quantity' => '0.0000', 'unresolved_quantity' => null];
        }
        return array_replace($base, ['legacy_order_id' => $id, 'subject_id' => (int)$legacy[$kind === 'purchase' ? 'supplier_id' : 'customer_id'],
            'subject_name' => $legacy[$kind === 'purchase' ? 'supplier_name' : 'customer_name'], 'order_sn' => $legacy['order_sn'],
            'original_order_sn' => $legacy['original_order_sn'], 'goods_summary' => implode('、', array_unique($goods)), 'quantities' => $quantities,
            'status_label' => '原业务退货记录']);
    }
}
