<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\SalesReservation;
use app\common\model\jxc\SalesReservationItem;
use app\common\model\jxc\WorkTask;
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
                $warehouseId = (int)($item['warehouse_id'] ?? 0);
                $num = InventoryReservationService::qty($item['num'] ?? $item['number'] ?? 0);
                if ($goodsId <= 0 || $warehouseId <= 0) {
                    throw new \RuntimeException('JXC_STOCK_RESERVATION_CONFLICT|请选择指定仓库');
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

                $available = InventoryReservationService::availableForGoods($goodsId, $warehouseId);
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
                    'warehouse_id' => $warehouseId,
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
                    $inventoryReservation = InventoryReservationService::reserve(array_merge($row->toArray(), [
                        'reservation_id' => (int)$reservation->id,
                    ]), $reserved);
                    if ($inventoryReservation === null) {
                        throw new \RuntimeException('JXC_STOCK_RESERVATION_CONFLICT|仓库可用库存已变化');
                    }
                }

                $total = bcadd($total, $num, 4);
                $reservedTotal = bcadd($reservedTotal, $reserved, 4);
                $shortageTotal = bcadd($shortageTotal, $shortage, 4);
                $fresh = SalesReservationItem::where('tenant_id', self::tenantId())
                    ->where('id', (int)$row->id)
                    ->lock(true)
                    ->findOrEmpty()
                    ->toArray();
                if (bccomp($shortage, '0', 4) > 0) {
                    $task = TaskCenterService::ensureProcurementForReservationItem($fresh);
                    $fresh['procurement_task_id'] = (int)($task['id'] ?? 0);
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
            Db::commit();
            $result = self::format($freshReservation, $resultItems);
            $taskIds = array_values(array_filter(array_map(static fn(array $item): int => (int)($item['procurement_task_id'] ?? 0), $resultItems)));
            $result['task_summary'] = [
                'required_item_count' => count($taskIds),
                'procurement_task_count' => count($taskIds),
                'procurement_task_ids' => $taskIds,
            ];
            return $result;
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

    public static function edit(array $params): array|false
    {
        self::clearError();
        $items = $params['items'] ?? $params['goods'] ?? [];
        if (empty($items) || !is_array($items)) {
            return self::failWithCode('请选择商品', 'JXC_QTY_INVALID');
        }
        Db::startTrans();
        try {
            $reservation = SalesReservation::where('tenant_id', self::tenantId())->where('id', (int)($params['id'] ?? 0))->lock(true)->findOrEmpty();
            if ($reservation->isEmpty() || !in_array((string)$reservation->status, [SalesReservation::STATUS_READY, SalesReservation::STATUS_SHORTAGE], true)) {
                throw new \RuntimeException('JXC_RESERVATION_STATUS_INVALID|销售预定状态不可编辑');
            }
            $existingRows = SalesReservationItem::where('tenant_id', self::tenantId())->where('reservation_id', (int)$reservation->id)->order(['id' => 'asc'])->lock(true)->select()->toArray();
            $existingById = [];
            foreach ($existingRows as $row) {
                $existingById[(int)$row['id']] = $row;
                $progressed = WorkTask::where('tenant_id', self::tenantId())
                    ->where('source_type', 'sales_reservation_item')
                    ->where('source_id', (int)$row['id'])
                    ->where(function ($query) {
                        $query->where('progress_num', '>', '0')
                            ->whereOr(function ($statusQuery) {
                                $statusQuery->whereIn('status', [WorkTask::STATUS_PROCESSING, WorkTask::STATUS_COMPLETED]);
                            });
                    })
                    ->lock(true)
                    ->count();
                if ($progressed > 0) {
                    throw new \RuntimeException('JXC_RESERVATION_STATUS_INVALID|已有供货进度的预定不可编辑');
                }
            }
            $seen = [];
            $total = '0.0000'; $reservedTotal = '0.0000'; $shortageTotal = '0.0000';
            foreach (array_values($items) as $input) {
                $itemId = (int)($input['reservation_item_id'] ?? $input['id'] ?? 0);
                $old = $existingById[$itemId] ?? null;
                $goodsId = (int)($input['goods_id'] ?? ($old['goods_id'] ?? 0));
                $warehouseId = (int)($input['warehouse_id'] ?? ($old['warehouse_id'] ?? 0));
                $num = InventoryReservationService::qty($input['num'] ?? $input['number'] ?? 0);
                if ($goodsId <= 0 || $warehouseId <= 0 || bccomp($num, '0', 4) <= 0) { throw new \RuntimeException('JXC_QTY_INVALID|商品、仓库和数量必须有效'); }
                $goods = Goods::where('tenant_id', self::tenantId())->where('id', $goodsId)->lock(true)->findOrEmpty();
                if ($goods->isEmpty()) { throw new \RuntimeException('JXC_GOODS_NOT_FOUND|商品不存在'); }
                if ($old) { InventoryReservationService::releaseReservationItem((int)$old['id']); }
                $available = InventoryReservationService::availableForGoods($goodsId, $warehouseId);
                $reserved = bccomp($available, $num, 4) > 0 ? $num : $available;
                $shortage = bcsub($num, $reserved, 4);
                if (bccomp($shortage, '0', 4) < 0) { $shortage = '0.0000'; }
                $data = ['goods_id' => $goodsId, 'goods_name' => (string)$goods->name, 'goods_code' => (string)$goods->product_code, 'warehouse_id' => $warehouseId, 'sku_id' => (int)($input['sku_id'] ?? ($old['sku_id'] ?? 0)), 'spec_id' => (int)($input['spec_id'] ?? ($old['spec_id'] ?? 0)), 'num' => $num, 'reserved_num' => $reserved, 'shortage_num' => InventoryReservationService::qty($shortage), 'status' => bccomp($shortage, '0', 4) > 0 ? SalesReservationItem::STATUS_SHORTAGE : SalesReservationItem::STATUS_RESERVED, 'update_time' => time()];
                if ($old) { $model = SalesReservationItem::where('tenant_id', self::tenantId())->where('id', (int)$old['id'])->findOrEmpty(); $model->save($data); } else { $model = SalesReservationItem::create(array_merge($data, ['tenant_id' => self::tenantId(), 'reservation_id' => (int)$reservation->id, 'unit_id' => (int)($goods->unit_id ?? 0), 'unit_name' => (string)($goods->units ?? ''), 'create_time' => time()])); }
                if (bccomp($reserved, '0', 4) > 0 && InventoryReservationService::reserve($model->toArray(), $reserved) === null) { throw new \RuntimeException('JXC_STOCK_RESERVATION_CONFLICT|仓库可用库存已变化'); }
                if (bccomp($shortage, '0', 4) > 0) { TaskCenterService::ensureProcurementForReservationItem($model->toArray()); } else { TaskCenterService::cancelByReservationItem((int)$model->id); }
                $seen[(int)$model->id] = true; $total = bcadd($total, $num, 4); $reservedTotal = bcadd($reservedTotal, $reserved, 4); $shortageTotal = bcadd($shortageTotal, $shortage, 4);
            }
            foreach ($existingRows as $old) { if (!isset($seen[(int)$old['id']])) { InventoryReservationService::releaseReservationItem((int)$old['id']); TaskCenterService::cancelByReservationItem((int)$old['id']); SalesReservationItem::where('tenant_id', self::tenantId())->where('id', (int)$old['id'])->update(['status' => SalesReservationItem::STATUS_RELEASED, 'update_time' => time()]); } }
            $reservation->save(['customer_id' => (int)($params['customer_id'] ?? $reservation->customer_id), 'customer_name' => trim((string)($params['customer_name'] ?? $reservation->customer_name)), 'remark' => trim((string)($params['remark'] ?? $reservation->remark)), 'total_num' => InventoryReservationService::qty($total), 'reserved_num' => InventoryReservationService::qty($reservedTotal), 'shortage_num' => InventoryReservationService::qty($shortageTotal), 'status' => bccomp($shortageTotal, '0', 4) > 0 ? SalesReservation::STATUS_SHORTAGE : SalesReservation::STATUS_READY, 'update_by' => self::adminId(), 'update_time' => time()]);
            Db::commit();
            return self::detail(['id' => (int)$reservation->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            [$code, $message] = self::splitRuntimeError($e->getMessage());
            return self::failWithCode($message, $code);
        }
    }

    public static function cancel(array $params): array|false
    {
        self::clearError();
        Db::startTrans();
        try {
            $reservation = SalesReservation::where('tenant_id', self::tenantId())
                ->where('id', (int)($params['id'] ?? 0))
                ->lock(true)
                ->findOrEmpty();
            if ($reservation->isEmpty()) {
                throw new \RuntimeException('JXC_RESERVATION_STATUS_INVALID|销售预定不存在');
            }
            if ((string)$reservation->status === SalesReservation::STATUS_CANCELLED) {
                Db::commit();
                return self::detail(['id' => (int)$reservation->id]);
            }
            if (!in_array((string)$reservation->status, [SalesReservation::STATUS_READY, SalesReservation::STATUS_SHORTAGE, SalesReservation::STATUS_GAP_CLOSED], true)) {
                throw new \RuntimeException('JXC_RESERVATION_STATUS_INVALID|销售预定状态不可取消');
            }
            SalesReservationItem::where('tenant_id', self::tenantId())
                ->where('reservation_id', (int)$reservation->id)
                ->order(['id' => 'asc'])
                ->lock(true)
                ->select();
            InventoryReservationService::releaseReservation((int)$reservation->id);
            TaskCenterService::cancelByReservation((int)$reservation->id);
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
            if ($e instanceof \RuntimeException) {
                [$code, $message] = self::splitRuntimeError($e->getMessage());
                return self::failWithCode($message, $code);
            }
            Log::error('销售预定取消失败: ' . $e->getMessage());
            return self::failWithCode('操作失败，请稍后重试', 'JXC_RESERVATION_CANCEL_FAILED');
        }
    }

    public static function convertSales(array $params): array|false
    {
        self::clearError();
        Db::startTrans();
        try {
            $reservation = SalesReservation::where('tenant_id', self::tenantId())
                ->where('id', (int)($params['id'] ?? 0))
                ->lock(true)
                ->findOrEmpty();
            if ($reservation->isEmpty()) {
                throw new \RuntimeException('JXC_RESERVATION_STATUS_INVALID|销售预定不存在');
            }
            if ((string)$reservation->status === SalesReservation::STATUS_CONVERTED) {
                if ((int)$reservation->converted_sales_order_id <= 0) {
                    throw new \RuntimeException('JXC_RESERVATION_CONVERT_CONFLICT|已转换预定缺少销售单');
                }
                Db::commit();
                return ['id' => (int)$reservation->id, 'sales_order_id' => (int)$reservation->converted_sales_order_id];
            }
            if ((string)$reservation->status !== SalesReservation::STATUS_READY) {
                throw new \RuntimeException('JXC_RESERVATION_NOT_READY|销售预定未全量就绪');
            }

            $items = SalesReservationItem::where('reservation_id', (int)$reservation->id)
                ->where('tenant_id', self::tenantId())
                ->order(['id' => 'asc'])
                ->lock(true)
                ->select()
                ->toArray();
            if (empty($items)) {
                throw new \RuntimeException('JXC_RESERVATION_CONVERT_CONFLICT|销售预定缺少明细');
            }
            $openTasks = WorkTask::where('tenant_id', self::tenantId())
                ->where('reservation_id', (int)$reservation->id)
                ->whereIn('status', [WorkTask::STATUS_PENDING, WorkTask::STATUS_ASSIGNED, WorkTask::STATUS_PROCESSING, WorkTask::STATUS_BLOCKED])
                ->order(['id' => 'asc'])
                ->lock(true)
                ->count();
            if ($openTasks > 0) {
                throw new \RuntimeException('JXC_RESERVATION_TASK_OPEN|存在未完成采购任务');
            }

            $goodsRows = [];
            $warehouseId = (int)($items[0]['warehouse_id'] ?? 0);
            foreach ($items as $item) {
                if ((int)$item['warehouse_id'] !== $warehouseId || (string)$item['status'] !== SalesReservationItem::STATUS_RESERVED || bccomp((string)$item['shortage_num'], '0', 4) !== 0) {
                    throw new \RuntimeException('JXC_RESERVATION_CONVERT_PARTIAL_FORBIDDEN|销售预定不允许部分转销售');
                }
                $goods = Goods::where('id', (int)$item['goods_id'])
                    ->where('tenant_id', self::tenantId())
                    ->lock(true)
                    ->findOrEmpty();
                if ($goods->isEmpty()) {
                    throw new \RuntimeException('JXC_GOODS_NOT_FOUND|商品不存在');
                }
                $goodsRows[] = ['goods_id' => (int)$item['goods_id'], 'name' => (string)$item['goods_name'], 'number' => (string)$item['num'], 'price' => (string)$goods->price, 'units' => (string)$item['unit_name']];
            }

            InventoryReservationService::consumeReservation((int)$reservation->id);
            $sales = SalesOrderLogic::publishWithinTransaction([
                'customer_id' => (int)$reservation->customer_id,
                'warehouse_id' => $warehouseId,
                'goods' => $goodsRows,
                'remarks' => '销售预定转销售-' . (string)$reservation->sn,
                'idempotent_key' => 'sales_reservation_convert:' . self::tenantId() . ':' . (int)$reservation->id,
            ]);
            if ($sales === false) {
                throw new \RuntimeException('JXC_RESERVATION_CONVERT_FAILED|' . SalesOrderLogic::getError());
            }
            $reservation->save(['status' => SalesReservation::STATUS_CONVERTED, 'converted_sales_order_id' => (int)$sales['id'], 'update_by' => self::adminId(), 'update_time' => time()]);
            Db::commit();
            return ['id' => (int)$reservation->id, 'sales_order_id' => (int)$sales['id'], 'order_sn' => (string)($sales['order_sn'] ?? '')];
        } catch (\Throwable $e) {
            Db::rollback();
            [$code, $message] = self::splitRuntimeError($e->getMessage());
            return self::failWithCode($message, $code === 'JXC_STOCK_RESERVATION_CONFLICT' ? 'JXC_RESERVATION_CONVERT_FAILED' : $code);
        }
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

        $itemIds = array_column($items, 'id');
        $tasksByItem = empty($itemIds) ? [] : WorkTask::where('tenant_id', self::tenantId())
            ->where('source_type', 'sales_reservation_item')
            ->whereIn('source_id', $itemIds)
            ->order(['id' => 'asc'])
            ->column('id', 'source_id');
        foreach ($items as &$item) {
            $item['procurement_task_id'] = (int)($tasksByItem[(int)$item['id']] ?? 0);
        }
        unset($item);

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
            'procurement_task_id' => (int)($item['procurement_task_id'] ?? 0),
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
