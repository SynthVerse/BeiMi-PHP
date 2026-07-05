<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\SalesReservation;
use app\common\model\jxc\SalesReservationItem;
use think\facade\Db;
use think\facade\Log;

class SalesReservationLogic extends BaseLogic
{
    public static function submit(array $params): array|false
    {
        self::clearError();
        $items = $params['items'] ?? $params['goods'] ?? [];
        if (empty($items) || !is_array($items)) {
            return self::failWithCode('请选择商品', 'JXC_QTY_INVALID');
        }

        Db::startTrans();
        try {
            $reservation = SalesReservation::create([
                'tenant_id' => self::tenantId(),
                'sn' => self::generateSn(),
                'customer_id' => (int)($params['customer_id'] ?? 0),
                'customer_name' => trim((string)($params['customer_name'] ?? '')),
                'status' => SalesReservation::STATUS_DRAFT,
                'total_num' => '0.0000',
                'reserved_num' => '0.0000',
                'shortage_num' => '0.0000',
                'converted_sales_order_id' => 0,
                'remark' => trim((string)($params['remark'] ?? '')),
                'create_by' => self::adminId(),
                'update_by' => self::adminId(),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            $total = '0.0000';
            $reservedTotal = '0.0000';
            $shortageTotal = '0.0000';
            $resultItems = [];

            foreach (array_values($items) as $item) {
                $goodsId = (int)($item['goods_id'] ?? $item['id'] ?? 0);
                $num = InventoryReservationService::qty($item['num'] ?? $item['number'] ?? 0);
                if ($goodsId <= 0) {
                    throw new \RuntimeException('JXC_GOODS_NOT_FOUND|商品不存在');
                }
                if (bccomp($num, '0', 4) <= 0) {
                    throw new \RuntimeException('JXC_QTY_INVALID|商品数量必须大于0');
                }

                $goods = Goods::where('id', $goodsId)
                    ->where('tenant_id', self::tenantId())
                    ->lock(true)
                    ->findOrEmpty();
                if ($goods->isEmpty()) {
                    throw new \RuntimeException('JXC_GOODS_NOT_FOUND|商品不存在');
                }

                $available = InventoryReservationService::availableForGoods($goodsId);
                $reserved = bccomp($available, $num, 4) > 0 ? $num : $available;
                $shortage = bcsub($num, $reserved, 4);
                if (bccomp($shortage, '0', 4) < 0) {
                    $shortage = '0.0000';
                }

                $row = SalesReservationItem::create([
                    'tenant_id' => self::tenantId(),
                    'reservation_id' => (int)$reservation->id,
                    'goods_id' => $goodsId,
                    'goods_name' => (string)$goods->name,
                    'goods_code' => (string)$goods->product_code,
                    'unit_id' => (int)($goods->unit_id ?? 0),
                    'unit_name' => (string)($goods->units ?? ''),
                    'warehouse_id' => (int)($item['warehouse_id'] ?? 0),
                    'sku_id' => (int)($item['sku_id'] ?? 0),
                    'spec_id' => (int)($item['spec_id'] ?? 0),
                    'num' => $num,
                    'reserved_num' => $reserved,
                    'shortage_num' => InventoryReservationService::qty($shortage),
                    'status' => bccomp($shortage, '0', 4) > 0 ? SalesReservationItem::STATUS_SHORTAGE : SalesReservationItem::STATUS_RESERVED,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                if (bccomp($reserved, '0', 4) > 0) {
                    InventoryReservationService::reserve(array_merge($row->toArray(), [
                        'reservation_id' => (int)$reservation->id,
                    ]), $reserved);
                }

                $task = false;
                if (bccomp($shortage, '0', 4) > 0) {
                    $task = WorkTaskService::createProcurementWorkTaskForReservationItem((int)$row->id);
                }

                $total = bcadd($total, $num, 4);
                $reservedTotal = bcadd($reservedTotal, $reserved, 4);
                $shortageTotal = bcadd($shortageTotal, $shortage, 4);
                $fresh = SalesReservationItem::find((int)$row->id)->toArray();
                if ($task !== false) {
                    $fresh['work_task'] = $task;
                }
                $resultItems[] = self::formatItem($fresh);
            }

            $status = bccomp($shortageTotal, '0', 4) > 0 ? SalesReservation::STATUS_SHORTAGE : SalesReservation::STATUS_READY;
            $reservation->save([
                'status' => $status,
                'total_num' => InventoryReservationService::qty($total),
                'reserved_num' => InventoryReservationService::qty($reservedTotal),
                'shortage_num' => InventoryReservationService::qty($shortageTotal),
                'update_time' => time(),
            ]);

            $freshReservation = SalesReservation::find((int)$reservation->id)->toArray();
            if ($status === SalesReservation::STATUS_READY) {
                WorkTaskService::createSalesConvertTask([
                    'reservation_id' => (int)$freshReservation['id'],
                    'reservation_sn' => (string)$freshReservation['sn'],
                    'title' => '预定转销售',
                ]);
            }
            Db::commit();
            return self::format($freshReservation, $resultItems);
        } catch (\RuntimeException $e) {
            Db::rollback();
            [$code, $message] = self::splitRuntimeError($e->getMessage());
            return self::failWithCode($message, $code);
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('销售预定提交失败: ' . $e->getMessage());
            return self::failWithCode('操作失败，请稍后重试', 'JXC_STOCK_RESERVATION_CONFLICT');
        }
    }

    public static function cancel(array $params): array|false
    {
        self::clearError();
        $reservation = self::findReservation((int)($params['id'] ?? 0));
        if (!$reservation) {
            return self::failWithCode('销售预定不存在', 'JXC_RESERVATION_STATUS_INVALID');
        }
        if (!in_array((string)$reservation->status, [SalesReservation::STATUS_READY, SalesReservation::STATUS_SHORTAGE, SalesReservation::STATUS_GAP_CLOSED], true)) {
            return self::failWithCode('销售预定状态不可取消', 'JXC_RESERVATION_STATUS_INVALID');
        }

        Db::startTrans();
        try {
            InventoryReservationService::releaseReservation((int)$reservation->id);
            WorkTaskService::cancelOpenProcurementWorkTasksForReservation((int)$reservation->id);
            SalesReservationItem::where('reservation_id', (int)$reservation->id)
                ->where('tenant_id', self::tenantId())
                ->update([
                    'status' => SalesReservationItem::STATUS_RELEASED,
                    'update_time' => time(),
                ]);
            $reservation->save([
                'status' => SalesReservation::STATUS_CANCELLED,
                'update_by' => self::adminId(),
                'update_time' => time(),
            ]);
            Db::commit();
            return self::detail(['id' => (int)$reservation->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('销售预定取消失败: ' . $e->getMessage());
            return self::failWithCode('操作失败，请稍后重试', 'JXC_STOCK_RESERVATION_CONFLICT');
        }
    }

    public static function convertSales(array $params): array|false
    {
        self::clearError();
        $reservation = self::findReservation((int)($params['id'] ?? 0));
        if (!$reservation) {
            return self::failWithCode('销售预定不存在', 'JXC_RESERVATION_STATUS_INVALID');
        }
        if ((string)$reservation->status !== SalesReservation::STATUS_READY) {
            return self::failWithCode('销售预定未全量就绪', 'JXC_RESERVATION_NOT_READY');
        }

        $items = SalesReservationItem::where('reservation_id', (int)$reservation->id)
            ->where('tenant_id', self::tenantId())
            ->order(['id' => 'asc'])
            ->select()
            ->toArray();
        foreach ($items as $item) {
            if ((string)$item['status'] !== SalesReservationItem::STATUS_RESERVED || bccomp((string)$item['shortage_num'], '0', 4) !== 0) {
                return self::failWithCode('销售预定不允许部分转销售', 'JXC_RESERVATION_CONVERT_PARTIAL_FORBIDDEN');
            }
        }

        $goodsRows = [];
        foreach ($items as $item) {
            $goods = Goods::where('id', (int)$item['goods_id'])
                ->where('tenant_id', self::tenantId())
                ->findOrEmpty();
            if ($goods->isEmpty()) {
                return self::failWithCode('商品不存在', 'JXC_GOODS_NOT_FOUND');
            }
            $goodsRows[] = [
                'goods_id' => (int)$item['goods_id'],
                'name' => (string)$item['goods_name'],
                'number' => (string)$item['num'],
                'price' => (string)$goods->price,
                'units' => (string)$item['unit_name'],
            ];
        }

        $sales = SalesOrderLogic::publish([
            'customer_id' => (int)$reservation->customer_id,
            'warehouse_id' => (int)($items[0]['warehouse_id'] ?? 0),
            'goods' => $goodsRows,
            'remark' => '销售预定转销售-' . (string)$reservation->sn,
            'idempotent_key' => 'sales_reservation:' . (int)$reservation->id,
        ]);
        if ($sales === false) {
            return self::failWithCode(SalesOrderLogic::getError(), 'JXC_RESERVATION_STATUS_INVALID');
        }

        InventoryReservationService::consumeReservation((int)$reservation->id);
        $reservation->save([
            'status' => SalesReservation::STATUS_CONVERTED,
            'converted_sales_order_id' => (int)$sales['id'],
            'update_by' => self::adminId(),
            'update_time' => time(),
        ]);

        return [
            'id' => (int)$reservation->id,
            'sales_order_id' => (int)$sales['id'],
            'order_sn' => (string)($sales['order_sn'] ?? ''),
        ];
    }

    public static function detail(array $params): array
    {
        $reservation = self::findReservation((int)($params['id'] ?? 0));
        if (!$reservation) {
            return [];
        }

        $items = SalesReservationItem::where('reservation_id', (int)$reservation->id)
            ->where('tenant_id', self::tenantId())
            ->order(['id' => 'asc'])
            ->select()
            ->toArray();

        return self::format($reservation->toArray(), array_map([self::class, 'formatItem'], $items));
    }

    public static function format(array $reservation, array $items = []): array
    {
        return [
            'id' => (int)($reservation['id'] ?? 0),
            'sn' => (string)($reservation['sn'] ?? ''),
            'customer_id' => (int)($reservation['customer_id'] ?? 0),
            'customer_name' => (string)($reservation['customer_name'] ?? ''),
            'status' => (string)($reservation['status'] ?? ''),
            'total_num' => InventoryReservationService::qty($reservation['total_num'] ?? 0),
            'reserved_num' => InventoryReservationService::qty($reservation['reserved_num'] ?? 0),
            'shortage_num' => InventoryReservationService::qty($reservation['shortage_num'] ?? 0),
            'converted_sales_order_id' => (int)($reservation['converted_sales_order_id'] ?? 0),
            'remark' => (string)($reservation['remark'] ?? ''),
            'items' => $items,
            'create_time' => $reservation['create_time'] ?? '',
            'update_time' => $reservation['update_time'] ?? '',
        ];
    }

    public static function formatItem(array $item): array
    {
        return [
            'id' => (int)($item['id'] ?? 0),
            'reservation_id' => (int)($item['reservation_id'] ?? 0),
            'goods_id' => (int)($item['goods_id'] ?? 0),
            'goods_name' => (string)($item['goods_name'] ?? ''),
            'goods_code' => (string)($item['goods_code'] ?? ''),
            'unit_id' => (int)($item['unit_id'] ?? 0),
            'unit_name' => (string)($item['unit_name'] ?? ''),
            'warehouse_id' => (int)($item['warehouse_id'] ?? 0),
            'sku_id' => (int)($item['sku_id'] ?? 0),
            'spec_id' => (int)($item['spec_id'] ?? 0),
            'num' => InventoryReservationService::qty($item['num'] ?? 0),
            'reserved_num' => InventoryReservationService::qty($item['reserved_num'] ?? 0),
            'shortage_num' => InventoryReservationService::qty($item['shortage_num'] ?? 0),
            'status' => (string)($item['status'] ?? ''),
            'work_task' => $item['work_task'] ?? null,
        ];
    }

    private static function findReservation(int $id): ?SalesReservation
    {
        if ($id <= 0) {
            return null;
        }
        $reservation = SalesReservation::where('id', $id)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        return $reservation->isEmpty() ? null : $reservation;
    }

    private static function failWithCode(string $message, string $code): false
    {
        self::setError($message);
        self::setReturnData(['error_code' => $code]);
        return false;
    }

    private static function splitRuntimeError(string $message): array
    {
        if (str_contains($message, '|')) {
            return explode('|', $message, 2);
        }
        return ['JXC_STOCK_RESERVATION_CONFLICT', $message];
    }

    private static function generateSn(): string
    {
        return 'XSDD' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function adminId(): int
    {
        return (int)(request()->adminId ?? 0);
    }
}
