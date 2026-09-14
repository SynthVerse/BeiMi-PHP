<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 交付事件是覆盖量的唯一来源；旧全单结算按事件先后分配，新结算显式保存覆盖。 */
final class FinanceDeliveries
{
    /** 工作台跨客户读取仍沿用交付覆盖算法，并补回主客户作为精确跳转依据。 */
    public static function pendingAll(): array
    {
        $result = [];
        $customers = Db::name('sales_order')->where('tenant_id', FinanceAccess::tenant())->where('source_type', 'customer_report')->where('customer_id', '>', 0)->distinct(true)->column('customer_id');
        foreach ($customers as $customer) {
            foreach (self::rows((int)$customer, '1900-01-01', date('Y-m-d')) as $row) {
                $result[] = ['customer_id' => (int)$customer] + $row;
                if (count($result) > 5000) { throw new \DomainException('待结算交付超过工作台容量，请进入销售结算按客户处理'); }
            }
        }
        return $result;
    }

    public static function rows(int $customer, string $from, string $to, bool $includeCovered = false): array
    {
        $tenant = FinanceAccess::tenant();
        $rows = Db::name('fulfillment_delivery_item')->alias('d')
            ->join('fulfillment_delivery_event e', "e.id=d.delivery_event_id AND e.tenant_id=d.tenant_id AND e.status='completed'")
            ->join('sales_order o', 'o.id=d.sales_order_id AND o.tenant_id=d.tenant_id')
            ->join('order_goods g', "g.order_id=o.id AND g.tenant_id=o.tenant_id AND g.order_type='sales' AND g.source_line_type='customer_report_item' AND g.source_line_id=d.report_item_id")
            ->leftJoin('customer_report_item i', 'i.id=d.report_item_id AND i.tenant_id=d.tenant_id')
            ->where('d.tenant_id', $tenant)->where('o.customer_id', $customer)->where('o.source_type', 'customer_report')
            ->where('e.delivered_time', '<', strtotime($to . ' +1 day'))
            ->field('d.id AS delivery_item_id,e.delivered_time,o.id AS order_id,o.order_sn,o.customer_name,g.id AS line_id,g.name,g.sku_name,d.actual_delivery_weight,i.delivery_customer_id,i.delivery_customer_name')
            ->order('e.delivered_time,d.id')->limit(5001)->select()->toArray();
        if (count($rows) > 5000) { throw new \DomainException('客户交付来源超过单次核对容量，请先归档已结历史后再处理'); }
        if (!$rows) { return []; }
        $orders = array_values(array_unique(array_column($rows, 'order_id'))); $versions = [];
        $latest = Db::name('sales_order_version')->where('tenant_id', $tenant)->whereIn('order_id', $orders)->field('MAX(id) AS id')->group('order_id')->buildSql();
        foreach (Db::name('sales_order_version')->where('tenant_id', $tenant)->whereRaw('id IN ' . $latest)->select()->toArray() as $version) {
            foreach (FinanceValue::decode($version['snapshot_json'])['lines'] ?? [] as $line) { $versions[(int)$version['order_id']][(int)$line['order_goods_id']] = (string)$line['actual_delivery_weight']; }
        }
        foreach (Db::name('sales_delivery_correction')->where('tenant_id', $tenant)->whereIn('order_id', $orders)
            ->field('order_id,order_goods_id,SUM(delta_quantity) AS delta')->group('order_id,order_goods_id')->select()->toArray() as $correction) {
            if (isset($versions[$correction['order_id']][$correction['order_goods_id']])) {
                $versions[$correction['order_id']][$correction['order_goods_id']] = bcsub($versions[$correction['order_id']][$correction['order_goods_id']], $correction['delta'], 4);
            }
        }
        $explicit = [];
        foreach (Db::name('finance_sales_coverage')->where('tenant_id', $tenant)->whereIn('order_id', $orders)
            ->field('delivery_item_id,SUM(covered_delta) AS covered')->group('delivery_item_id')->select()->toArray() as $coverage) { $explicit[(int)$coverage['delivery_item_id']] = $coverage['covered']; }
        $result = [];
        foreach ($rows as $row) {
            $actual = bcadd((string)$row['actual_delivery_weight'], '0', 4); $budget = $versions[(int)$row['order_id']][(int)$row['line_id']] ?? '0.0000';
            if (bccomp($budget, '0', 4) < 0) { throw new \DomainException('正式交付覆盖量与实重更正不一致，请先核对'); }
            $legacy = bccomp($budget, $actual, 4) > 0 ? $actual : $budget;
            $versions[(int)$row['order_id']][(int)$row['line_id']] = bcsub($budget, $legacy, 4);
            $covered = bcadd($legacy, $explicit[(int)$row['delivery_item_id']] ?? '0', 4);
            if (bccomp($covered, '0', 4) < 0 || bccomp($covered, $actual, 4) > 0) { throw new \DomainException('交付覆盖量与实际交付不一致，请先核对'); }
            $date = date('Y-m-d', (int)$row['delivered_time']);
            $remaining = bcsub($actual, $covered, 4);
            if ($date < $from || (!$includeCovered && bccomp($remaining, '0', 4) <= 0)) { continue; }
            $result[] = ['delivery_item_id' => (int)$row['delivery_item_id'], 'order_id' => (int)$row['order_id'], 'order_sn' => $row['order_sn'], 'line_id' => (int)$row['line_id'], 'date' => $date,
                'delivery_customer_id' => (int)($row['delivery_customer_id'] ?: $customer), 'delivery_customer_name' => $row['delivery_customer_name'] ?: $row['customer_name'],
                'goods_name' => $row['name'], 'sku_name' => $row['sku_name'], 'actual_weight' => $actual, 'covered_weight' => $covered, 'pending_weight' => $remaining];
        }
        return $result;
    }
}
