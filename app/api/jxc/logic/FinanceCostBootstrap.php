<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 期初确认时承接截点后的实物流水，只建立成本，不重做库存或往来。 */
final class FinanceCostBootstrap
{
    public static function withinTransaction(int $tenant, string $date): array
    {
        $pdo = Db::connect()->getPdo();
        if ($tenant !== FinanceAccess::tenant() || !$pdo || !$pdo->inTransaction()) { throw new \DomainException('启用成本承接须在本门店期初确认事务内执行'); }
        $rows = Db::name('stock_flow')->where('tenant_id', $tenant)->where('create_time', '>=', strtotime($date))->order('create_time,id')->lock(true)->select()->toArray();
        $cost = new FinanceCostLedger($tenant); $count = 0; $pending = []; $last = 0;
        foreach ($rows as $flow) {
            $last = max($last, (int)$flow['id']); $delta = bcsub($flow['after_stock'], $flow['before_stock'], 4);
            if (bccomp($delta, '0', 4) === 0) { continue; }
            $occurred = FinanceStockFactTime::resolve($tenant, $flow, true);
            if ($occurred < strtotime($date)) { continue; }
            $warehouse = FinanceValue::id($flow['warehouse_id']); $sku = FinanceValue::id($flow['sku_id']);
            $quantity = ltrim($delta, '-'); $reference = 'stock:' . $flow['id'];
            $event = ['reference' => $reference, 'warehouse_id' => $warehouse, 'sku_id' => $sku, 'quantity' => $quantity,
                'business_date' => date('Y-m-d', $occurred),
                'snapshot' => ['stock_flow_id' => (int)$flow['id'], 'order_type' => $flow['order_type'], 'order_id' => (int)$flow['order_id'],
                    'bootstrap' => true, 'original_flow' => $flow]];
            if ($flow['order_type'] === 'sales_delivery_correction') {
                $event += ['type' => bccomp($delta, '0', 4) > 0 ? 'restore' : 'issue',
                    'bucket' => 'sale', 'target_reference' => 'sales_order:' . $flow['order_id']];
            } elseif ($flow['order_type'] === 'sales-return' && bccomp($delta, '0', 4) > 0) {
                $return = Db::name('sales_return_order')->where('tenant_id', $tenant)->where('id', $flow['order_id'])->lock(true)->find();
                $source = $return ? Db::name('sales_order')->where('tenant_id', $tenant)->where('id', $return['original_sales_order_id'])->lock(true)->find() : null;
                if (!$source) { throw new \DomainException('启用承接的销售退回缺少本门店原销售来源，请先核实'); }
                $event['to_warehouse_id'] = $warehouse; $event['warehouse_id'] = (int)$source['warehouse_id'];
                $event += ['type' => 'restore', 'bucket' => 'sale', 'target_reference' => 'sales_order:' . $source['id']];
                $event['snapshot']['return_source'] = $return;
                $sourceTime = (int)(($source['datetimesingle'] ?? 0) ?: ($source['create_time'] ?? 0));
                if (($sourceTime === 0 || $sourceTime < strtotime($date))
                    && bccomp($cost->destination((int)$source['warehouse_id'], $sku, 'sale', $event['target_reference'])['quantity'], '0', 12) === 0) {
                    // 截点前售出的货不在期初盘存中，退回成本须另核原来源，不能消耗本期销售或套用期初均价。
                    unset($event['bucket'], $event['target_reference'], $event['to_warehouse_id']);
                    $event['warehouse_id'] = $warehouse; $event['type'] = 'receive'; $event['origin'] = $reference; $event['amount'] = null;
                    $event['snapshot']['cost_basis_pending'] = 'pre_cutoff_sales_return'; $pending[] = (int)$flow['id'];
                }
            } elseif (bccomp($delta, '0', 4) > 0) {
                throw new \DomainException('库存流水 #' . $flow['id'] . ' 的入库成本或调拨来源尚未核实，不能正式启用');
            } else {
                [$bucket, $destination] = match ($flow['order_type']) {
                    'sales', 'sales_delivery', 'sales_delivery_correction' => ['sale', 'sales_order:' . $flow['order_id']],
                    'delivery_transport_loss' => ['loss', 'delivery_loss:' . $flow['order_id']],
                    'fulfillment_internal_loss' => ['loss', 'fulfillment_loss:' . $flow['order_id']],
                    'fulfillment_pending' => ['pending', 'fulfillment_loss:' . $flow['order_id']],
                    'purchase_return', 'supply_return', 'purchase-return' => ['return', 'purchase_return:' . $flow['order_id']],
                    default => throw new \DomainException('库存流水 #' . $flow['id'] . ' 的实物去向或调拨配对尚未核实，不能正式启用'),
                };
                if ($bucket === 'pending') { $pending[] = (int)$flow['id']; }
                $event += ['type' => 'issue', 'bucket' => $bucket, 'target_reference' => $destination];
            }
            $result = $cost->recordWithinTransaction($event);
            if (!empty($result['pending'])) { $pending[] = (int)$flow['id']; } $count++;
        }
        foreach (Db::name('warehouse_sku_balance')->where('tenant_id', $tenant)->order('id')->lock(true)->select()->toArray() as $balance) {
            $current = $cost->balance((int)$balance['warehouse_id'], (int)$balance['sku_id']);
            if (bccomp($current['quantity'], $balance['on_hand_qty'], 4) !== 0) { throw new \DomainException('截点至启用的库存流水与成本数量不一致，请核实仓库与商品来源后启用'); }
        }
        return ['stock_flow_cutoff_id' => $last, 'replayed_flows' => $count, 'pending_flow_ids' => array_values(array_unique($pending))];
    }
}
