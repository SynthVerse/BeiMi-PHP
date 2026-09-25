<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 复杂期初的组成校验；只承接原事实，不产生新收付款或实物变动。 */
final class FinanceOpeningAssets
{
    public static function inventorySubjects(int $tenantId, string $keyword = '', int $page = 1): array
    {
        $query = self::inventoryQuery($tenantId);
        if ($keyword !== '') { $query->whereLike('s.sku_name|w.name', '%' . addcslashes($keyword, '%_\\') . '%'); }
        return $query->order('b.id')->page($page, 20)->select()->toArray();
    }

    public static function inventorySubject(int $tenantId, int $id, ?string $date = null, bool $lock = false): ?array
    {
        $row = self::inventoryQuery($tenantId)->where('b.id', $id)->lock($lock)->find();
        return $row ? self::atCutoff($tenantId, $row, $date, $lock) : null;
    }

    /** 与所有权威库存写入口的 SKU/仓库锁互斥，也覆盖尚未有库存余额的 SKU。 */
    public static function lockInventory(int $tenantId): void
    {
        Db::name('goods_sku')->where('tenant_id', $tenantId)->order('id')->lock(true)->select();
        Db::name('warehouse')->where('tenant_id', $tenantId)->order('id')->lock(true)->select();
        Db::name('warehouse_sku_balance')->where('tenant_id', $tenantId)->order('id')->lock(true)->select();
    }

    public static function stockAtCutoff(int $tenantId, ?string $date, bool $lock = false): array
    {
        $rows = Db::name('warehouse_sku_balance')->where('tenant_id', $tenantId)->order('id')->lock($lock)->select()->toArray();
        return array_map(static fn(array $row): array => self::atCutoff($tenantId, $row, $date, $lock), $rows);
    }

    /** @return array<int, string> */
    public static function inventoryNames(int $tenantId, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) { return []; }
        return array_column(
            self::inventoryQuery($tenantId)->whereIn('b.id', $ids)->select()->toArray(),
            'name',
            'id',
        );
    }

    private static function atCutoff(int $tenantId, array $row, ?string $date, bool $lock): array
    {
        $row['cutoff_qty'] = $row['on_hand_qty'];
        if ($date) {
            // 回退截点之后的净变动；晚录旧交付沿原事件日期，包含已售罄的 SKU。
            $flows = Db::name('stock_flow')->where('tenant_id', $tenantId)->where('warehouse_id', $row['warehouse_id'])
                ->where('sku_id', $row['sku_id'])->where('create_time', '>=', strtotime($date))->order('id')->lock($lock)->select()->toArray();
            foreach ($flows as $flow) {
                if (FinanceStockFactTime::resolve($tenantId, $flow, $lock) < strtotime($date)) { continue; }
                $delta = bcsub($flow['after_stock'], $flow['before_stock'], 4);
                $row['cutoff_qty'] = bcsub($row['cutoff_qty'], $delta, 4);
            }
        }
        return $row;
    }

    private static function inventoryQuery(int $tenantId): \think\db\Query
    {
        return Db::name('warehouse_sku_balance')->alias('b')
            ->join('warehouse w', 'w.id=b.warehouse_id AND w.tenant_id=b.tenant_id')
            ->join('goods_sku s', 's.id=b.sku_id AND s.tenant_id=b.tenant_id')
            ->where('b.tenant_id', $tenantId)
            ->field("b.id,b.warehouse_id,b.sku_id,b.base_unit_id,b.base_unit_name,b.on_hand_qty,b.version,CONCAT(w.name,' / ',s.sku_name) AS name");
    }

    public static function fields(string $category): array
    {
        $definitions = match ($category) {
            'equipment' => [['original_amount', '已确认购置金额', 'money'], ['price_adjustment', '有效价格调整（可负数）', 'signed_money'], ['paid_amount', '累计有效付款', 'money'], ['cancelled_amount', '已取消未付金额', 'money']],
            'equipment_refund' => [['equipment_reference', '原设备购置及退款依据', 'text']],
            'inventory' => [['quantity', '截点实物数量（基础单位）', 'quantity']],
            'transit' => [['target_account_id', '目标资金账户', 'account'], ['principal', '原转出本金', 'money'], ['arrived_amount', '已到账本金', 'money'], ['returned_amount', '已实际返还本金', 'money'], ['withheld_fee', '已从在途扣除手续费', 'money'], ['additional_fee', '来源账户额外手续费', 'money']],
            'unclaimed' => [['original_amount', '原实际到账金额', 'money'], ['claimed_amount', '已认领金额', 'money'], ['account_inclusion', '该笔到账已包含在期初账户余额中', 'acknowledgement']],
            'deferred' => [['original_amount', '原费用总金额', 'money'], ['amortized_amount', '启用前已摊金额', 'money'], ['paid_amount', '原费用已付款', 'money'], ['unpaid_amount', '原费用未付款', 'money'], ['payable_reference', '另列费用应付来源标识（未付为零可空）', 'text'], ['original_service_start', '原服务开始月份', 'month'], ['benefited_until', '已受益截至月份（尚未受益可空）', 'month'], ['service_start', '剩余服务起始月份', 'month'], ['service_end', '剩余服务结束月份', 'month'], ['schedule', '剩余逐月摊销计划', 'schedule']],
            default => [],
        };
        return array_map(static fn(array $field): array => ['key' => $field[0], 'label' => $field[1], 'type' => $field[2], 'required' => !in_array($field[0], ['payable_reference', 'benefited_until'], true)], $definitions);
    }

    public static function normalize(array $field, mixed $value): mixed
    {
        if ($field['type'] === 'schedule') {
            if ($value === '') { return []; }
            if (!is_array($value) || !array_is_list($value) || count($value) > 120) { throw new \DomainException('摊销计划须为不超过120个月的明细'); }
            return array_map(static function (mixed $row): array {
                if (!is_array($row)) { throw new \DomainException('摊销计划明细格式不正确'); }
                return ['month' => self::normalize(['label' => '计划月份', 'type' => 'month'], $row['month'] ?? ''),
                    'amount' => self::normalize(['label' => '计划金额', 'type' => 'money'], $row['amount'] ?? '')];
            }, $value);
        }
        if (!is_string($value) || mb_strlen(trim($value)) > 200) { throw new \DomainException($field['label'] . '格式或长度不正确'); }
        $value = trim($value);
        if ($value === '') { return ''; }
        $type = $field['type'];
        if ($type === 'month' && !preg_match('/^(19|20)[0-9]{2}-(0[1-9]|1[0-2])$/D', $value)) { throw new \DomainException($field['label'] . '格式必须为 YYYY-MM'); }
        if (in_array($type, ['money', 'signed_money', 'quantity'], true)) {
            $scale = $type === 'quantity' ? 4 : 2;
            $sign = $type === 'signed_money' ? '-?' : '';
            if (!preg_match('/^' . $sign . '(0|[1-9][0-9]{0,11})(\.[0-9]{1,' . $scale . '})?$/D', $value)) { throw new \DomainException($field['label'] . '必须填写有效数字，最多' . $scale . '位小数'); }
            return bcadd($value, '0', $scale);
        }
        if ($type === 'account' && !preg_match('/^[1-9][0-9]{0,9}$/D', $value)) { throw new \DomainException('请选择有效资金账户'); }
        if ($type === 'acknowledgement' && $value !== 'confirmed') { throw new \DomainException('请明确核对到账与期初账户余额的关系'); }
        return $value;
    }

    public static function blockers(int $tenantId, array $item, ?string $activationDate): array
    {
        $category = $item['category']; $details = $item['details']; $amount = $item['amount'];
        $errors = [];
        if (in_array($category, ['transit', 'unclaimed'], true) && !$item['historical_date']) { $errors[] = '实际资金日期尚未核实'; }
        if ($category === 'transit' && ($details['target_account_id'] ?? '') !== '') {
            $target = (int)$details['target_account_id'];
            if ($target === (int)$item['subject_id'] || !Db::name('finance_account')->where('tenant_id', $tenantId)->where('id', $target)->count()) { $errors[] = '目标账户必须是本门店的另一个资金账户'; }
        }
        foreach (self::fields($category) as $field) {
            $value = $details[$field['key']] ?? '';
            if (($field['required'] ?? true) && ($value === '' || $value === [])) { return $errors; }
        }
        if ($amount === null) { return $errors; }
        if ($category === 'equipment') {
            $remaining = bcsub(bcsub(bcadd($details['original_amount'], $details['price_adjustment'], 2), $details['paid_amount'], 2), $details['cancelled_amount'], 2);
            if (bccomp($remaining, $amount, 2) !== 0 || bccomp($remaining, '0', 2) < 0) { $errors[] = '剩余额度与购置金额、调整、已付和取消组成不一致'; }
        }
        if ($category === 'transit') {
            $remaining = $details['principal'];
            foreach (['arrived_amount', 'returned_amount', 'withheld_fee'] as $key) { $remaining = bcsub($remaining, $details[$key], 2); }
            if (bccomp($remaining, $amount, 2) !== 0) { $errors[] = '剩余在途与转出、到账、返还及代扣手续费组成不一致'; }
        }
        if ($category === 'unclaimed' && bccomp(bcsub($details['original_amount'], $details['claimed_amount'], 2), $amount, 2) !== 0) { $errors[] = '待认领余额与原到账、已认领组成不一致'; }
        if ($category === 'inventory') {
            if (bccomp($details['quantity'], '0', 4) === 0 && bccomp($amount, '0', 2) !== 0) { $errors[] = '零数量不能承接非零库存成本'; }
            if (bccomp($details['quantity'], '0', 4) > 0 && bccomp($amount, '0', 2) <= 0) { $errors[] = '非零库存须有已核实的历史总成本'; }
            $subject = $item['subject_snapshot'];
            if ($subject && bccomp($details['quantity'], $subject['cutoff_qty'], 4) !== 0) { $errors[] = '期初数量与启用截点库存不一致，须核对截点后来源流水'; }
        }
        if ($category === 'deferred') {
            if (bccomp(bcadd($details['amortized_amount'], $amount, 2), $details['original_amount'], 2) !== 0) { $errors[] = '已摊与未摊合计必须等于原费用金额'; }
            if (bccomp(bcadd($details['paid_amount'], $details['unpaid_amount'], 2), $details['original_amount'], 2) !== 0) { $errors[] = '已付款与未付款合计必须等于原费用金额'; }
            if (bccomp($details['unpaid_amount'], '0', 2) > 0 && $details['payable_reference'] === '') { $errors[] = '未付款必须关联另列费用应付来源'; }
            $start = $details['service_start']; $end = $details['service_end'];
            if ($start > $end || ($activationDate && $start < substr($activationDate, 0, 7))) { $errors[] = '剩余服务期间须从启用月份或之后开始，且结束不早于开始'; }
            if ($details['original_service_start'] > $start) { $errors[] = '原服务起始月份不能晚于剩余服务期间'; }
            if (bccomp($details['amortized_amount'], '0', 2) > 0) {
                $benefited = $details['benefited_until'];
                $cutoffMonth = $activationDate ? (new \DateTimeImmutable($activationDate))->modify('-1 day')->format('Y-m') : '';
                if ($benefited === '' || $benefited < $details['original_service_start'] || $benefited > $start || ($cutoffMonth && $benefited > $cutoffMonth)) { $errors[] = '已摊费用的已受益期间尚未核实或超出截点'; }
            }
            $sum = '0.00'; $months = [];
            foreach ($details['schedule'] as $row) {
                if ($row['month'] === '' || $row['amount'] === '' || bccomp($row['amount'], '0', 2) <= 0) { $errors[] = '计划月份和正数金额尚未核实'; continue; }
                if ($row['month'] < $start || $row['month'] > $end || isset($months[$row['month']])) { $errors[] = '计划月份重复或超出剩余服务期间'; }
                $months[$row['month']] = true; $sum = bcadd($sum, $row['amount'], 2);
            }
            if (bccomp($sum, $amount, 2) !== 0) { $errors[] = '逐月计划合计必须等于未摊金额'; }
            if ($start <= $end) {
                $cursor = new \DateTimeImmutable($start . '-01'); $count = 0;
                while ($cursor->format('Y-m') <= $end && $count++ <= 120) {
                    if (!isset($months[$cursor->format('Y-m')])) { $errors[] = '剩余服务月份缺少摊销计划'; break; }
                    $cursor = $cursor->modify('+1 month');
                }
            }
        }
        return array_values(array_unique($errors));
    }

    public static function payableBlockers(array $items): array
    {
        $obligations = []; $totals = []; $errors = [];
        foreach ($items as $item) {
            if ($item['category'] === 'expense_payable') { $obligations[$item['subject_id'] . ':' . $item['source_reference']] = $item['amount']; }
            if ($item['category'] === 'deferred' && ($item['details']['unpaid_amount'] ?? '') !== '' && bccomp($item['details']['unpaid_amount'], '0', 2) > 0) {
                $key = $item['subject_id'] . ':' . ($item['details']['payable_reference'] ?? '');
                $totals[$key] = bcadd($totals[$key] ?? '0.00', $item['details']['unpaid_amount'], 2);
            }
        }
        foreach ($totals as $key => $total) {
            if (!isset($obligations[$key]) || bccomp($total, $obligations[$key], 2) > 0) { $errors[] = '待摊未付组成缺少同一对象的足额费用应付承接'; }
        }
        return array_values(array_unique($errors));
    }
}
