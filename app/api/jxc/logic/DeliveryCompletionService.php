<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 结束已实际交付但仍待续的重量履约，不制造新的实物交付。 */
final class DeliveryCompletionService extends BaseLogic
{
    public static function complete(array $params): array|false
    {
        self::clearError();
        $committed = false;
        try {
            FinanceAccess::require('delivery.confirm');
            $reportId = FinanceValue::id($params['report_id'] ?? 0);
            $version = FinanceValue::id($params['version'] ?? 0);
            $key = FinanceValue::text($params['idempotency_key'] ?? '', 96);
            $rawIds = $params['item_ids'] ?? null;
            if (!is_array($rawIds) || !array_is_list($rawIds) || !$rawIds || count($rawIds) > 200) {
                throw new \DomainException('请选择需要确认交完的商品');
            }
            $ids = array_map(static fn($id) => FinanceValue::id($id), $rawIds);
            if (count(array_unique($ids)) !== count($ids)) throw new \DomainException('交完商品不能重复');
            sort($ids);
            $tenant = FinanceAccess::tenant();
            $fingerprint = hash('sha256', FinanceValue::json([$reportId, $version, $ids, FinanceAccess::actor()]));
            $result = Db::transaction(static function () use ($tenant, $reportId, $version, $ids, $key, $fingerprint): array {
                FinanceIntegration::lock();
                $existing = Db::name('fulfillment_delivery_event')->where('tenant_id', $tenant)->where('idempotency_key', $key)->find();
                if ($existing) {
                    if ($existing['event_type'] !== 'fulfillment_completed' || (int)$existing['report_id'] !== $reportId
                        || !hash_equals($existing['request_fingerprint'], $fingerprint)) {
                        throw new \DomainException('同一提交标识不能用于不同交完内容');
                    }
                    return ['event_id' => (int)$existing['id'], 'replayed' => true];
                }
                $report = Db::name('customer_report')->where('tenant_id', $tenant)->where('id', $reportId)->whereNull('delete_time')->lock(true)->find();
                if (!$report) throw new \DomainException('报货单不存在');
                if ((int)$report['version'] !== $version) throw new \DomainException('报货数据已变化，请重新核对');
                if ($report['status'] === 'cancelled' || CustomerReportSalesDocument::closed($report)) {
                    throw new \DomainException('本单已经结束，不能重复确认交完');
                }
                $items = Db::name('customer_report_item')->where('tenant_id', $tenant)->where('report_id', $reportId)->whereNull('delete_time')->order('id')->lock(true)->select()->toArray();
                $detail = CustomerReportLogic::detail(['id' => $reportId]);
                if ($detail === false) throw new \DomainException(CustomerReportLogic::getError());
                $details = array_column($detail['items'], null, 'id');
                $before = [];
                foreach ($ids as $id) {
                    $item = $details[$id] ?? null;
                    if (!$item || empty($item['delivery_progress']['can_complete'])) {
                        throw new \DomainException('仅已交付且仍待续、没有未处理子组的重量商品可确认交完');
                    }
                    CustomerReportQuantityRequirements::assertCompleteWeight($item);
                    $before[] = ['id' => $id, 'fulfillment_status' => $item['fulfillment_status']];
                }
                $now = FulfillmentClock::now();
                $task = Db::name('fulfillment_task')->where('tenant_id', $tenant)->where('report_id', $reportId)
                    ->where('source_key', 'report:' . $reportId . ':delivery')->lock(true)->find();
                $eventId = (int)Db::name('fulfillment_delivery_event')->insertGetId([
                    'tenant_id' => $tenant, 'report_id' => $reportId, 'task_id' => (int)($task['id'] ?? 0),
                    'delivery_method' => $report['delivery_method'], 'event_type' => 'fulfillment_completed',
                    'status' => 'completed', 'delivery_outcome' => 'partial_completed',
                    'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                    'handoff_note' => '确认已交完：商品明细 ' . implode('、', $ids),
                    'operator_id' => FinanceAccess::operator(), 'delivered_time' => 0, 'create_time' => $now, 'update_time' => $now,
                ]);
                foreach ($items as &$item) {
                    if (!in_array((int)$item['id'], $ids, true)) continue;
                    self::releaseReservation($item, $eventId, $now);
                    $item['reserved_base_qty'] = '0.0000';
                    $item['fulfillment_status'] = 'partially_delivered_completed';
                    Db::name('customer_report_item')->where('tenant_id', $tenant)->where('id', $item['id'])->update([
                        'status' => 'partially_delivered_completed', 'fulfillment_status' => 'partially_delivered_completed',
                        'has_partial_delivery' => 1, 'reserved_base_qty' => '0.0000', 'shortage_base_qty' => '0.0000', 'update_time' => $now,
                    ]);
                }
                unset($item);
                $closedStates = ['delivered', 'partially_delivered_completed', 'undelivered', 'delivery_exception_completed'];
                $pending = (bool)array_filter($items, static fn($item) => !in_array($item['fulfillment_status'], $closedStates, true));
                $reserved = '0.0000';
                foreach ($items as $item) $reserved = bcadd($reserved, $item['reserved_base_qty'], 4);
                Db::name('customer_report')->where('tenant_id', $tenant)->where('id', $reportId)->update([
                    'status' => $pending ? 'partially_delivered_pending' : 'partial_pending_settlement',
                    'version' => $version + 1, 'reserved_base_qty' => $reserved,
                    'shortage_base_qty' => $pending ? $report['shortage_base_qty'] : '0.0000', 'update_time' => $now,
                ]);
                if ($task && !$pending) Db::name('fulfillment_task')->where('tenant_id', $tenant)->where('id', $task['id'])->update([
                    'status' => 'recovered', 'recovery_note' => '确认已交完', 'recovered_by' => FinanceAccess::operator(), 'recovered_time' => $now, 'update_time' => $now,
                ]);
                AuditService::logWithinTransaction('fulfillment_delivery', 'fulfillment_completed', $eventId, $key,
                    ['items' => $before], ['report_id' => $reportId, 'item_ids' => $ids, 'report_status' => $pending ? 'partially_delivered_pending' : 'partial_pending_settlement'], '确认已交完');
                FulfillmentTaskLogic::refreshGroupForItem($ids[0]);
                return ['event_id' => $eventId, 'replayed' => false];
            });
            $committed = true;
            CustomerReportSalesDocument::afterReportChange($reportId);
            $detail = CustomerReportLogic::detail(['id' => $reportId]);
            if ($detail === false) throw new \RuntimeException('delivery_completion_detail_unavailable');
            return $detail + ['completion_result' => $result];
        } catch (\Throwable $error) {
            $rejected = !$committed && $error instanceof \DomainException;
            self::setError($rejected ? $error->getMessage() : '尚未核实交完结果，请保留原操作并重试');
            self::setReturnData(['command_outcome' => $rejected ? 'rejected' : 'unknown']);
            return false;
        }
    }

    private static function releaseReservation(array $item, int $eventId, int $now): void
    {
        $tenant = FinanceAccess::tenant();
        $reservation = Db::name('customer_report_reservation')->where('tenant_id', $tenant)->where('report_item_id', $item['id'])->lock(true)->find();
        $quantity = (string)($reservation['reserved_base_qty'] ?? '0');
        if (bccomp($quantity, '0', 4) <= 0) return;
        $order = Db::name('sales_order')->where('tenant_id', $tenant)->where('source_type', 'customer_report')
            ->where('source_id', $item['report_id'])->where('warehouse_id', $item['warehouse_id'])->find();
        $movement = StockService::releaseDeliveryReservationWithinTransaction((int)$item['warehouse_id'], (int)$item['sku_id'], $quantity,
            (int)($order['id'] ?? 0), (string)($order['order_sn'] ?? ''), $eventId);
        if ($movement === false) throw new \RuntimeException('delivery_completion_reservation_release_failed');
        Db::name('customer_report_reservation')->where('tenant_id', $tenant)->where('id', $reservation['id'])->update([
            'reserved_base_qty' => '0.0000', 'released_base_qty' => bcadd($reservation['released_base_qty'], $quantity, 4), 'status' => 'fulfilled', 'update_time' => $now,
        ]);
    }
}
