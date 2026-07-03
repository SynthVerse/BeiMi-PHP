<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\ProcurementTask;
use think\facade\Db;

class ProcurementTaskLogic extends BaseLogic
{
    public static function manualCreate(array $params): array|false
    {
        self::clearError();
        $goodsId = (int)($params['goods_id'] ?? 0);
        $requiredNum = InventoryReservationService::qty($params['required_num'] ?? 0);
        if ($goodsId <= 0) {
            return self::failWithCode('商品不存在', 'JXC_GOODS_NOT_FOUND');
        }
        if (bccomp($requiredNum, '0', 4) <= 0) {
            return self::failWithCode('采购数量必须大于0', 'JXC_QTY_INVALID');
        }

        $goods = Goods::where('id', $goodsId)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        if ($goods->isEmpty()) {
            return self::failWithCode('商品不存在', 'JXC_GOODS_NOT_FOUND');
        }

        $task = ProcurementTask::create([
            'tenant_id' => self::tenantId(),
            'sn' => self::generateSn(),
            'source_type' => 'manual',
            'source_key' => 'manual:' . uniqid('', true),
            'source_reservation_id' => 0,
            'source_reservation_item_id' => 0,
            'goods_id' => $goodsId,
            'goods_name' => (string)$goods->name,
            'goods_code' => (string)$goods->product_code,
            'warehouse_id' => (int)($params['warehouse_id'] ?? 0),
            'sku_id' => (int)($params['sku_id'] ?? 0),
            'spec_id' => (int)($params['spec_id'] ?? 0),
            'required_num' => $requiredNum,
            'arrived_num' => '0.0000',
            'status' => ProcurementTask::STATUS_PENDING,
            'create_by' => self::adminId(),
            'update_by' => self::adminId(),
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return ProcurementTaskService::format($task->toArray());
    }

    public static function start(array $params): array|false
    {
        return self::transition((int)($params['id'] ?? 0), [ProcurementTask::STATUS_PENDING], [
            'status' => ProcurementTask::STATUS_PURCHASING,
            'start_time' => time(),
        ]);
    }

    public static function close(array $params): array|false
    {
        self::clearError();
        $task = self::findTask((int)($params['id'] ?? 0));
        if (!$task || !in_array((string)$task->status, [ProcurementTask::STATUS_PENDING, ProcurementTask::STATUS_PURCHASING, ProcurementTask::STATUS_PARTIAL_ARRIVED], true)) {
            return self::failWithCode('采购任务状态不可关闭', 'JXC_PROCUREMENT_TASK_STATUS_INVALID');
        }

        Db::startTrans();
        try {
            $reason = trim((string)($params['close_reason'] ?? $params['reason'] ?? ''));
            $result = ProcurementTaskService::closeTask($task, $reason);
            Db::commit();
            return $result;
        } catch (\Throwable) {
            Db::rollback();
            return self::failWithCode('采购任务关闭失败', 'JXC_PROCUREMENT_TASK_CLOSED_GAP');
        }
    }

    public static function cancel(array $params): array|false
    {
        return self::transition((int)($params['id'] ?? 0), [ProcurementTask::STATUS_PENDING, ProcurementTask::STATUS_PURCHASING], [
            'status' => ProcurementTask::STATUS_CANCELLED,
        ]);
    }

    public static function detail(array $params): array
    {
        $task = self::findTask((int)($params['id'] ?? 0));
        return $task ? ProcurementTaskService::format($task->toArray()) : [];
    }

    private static function transition(int $id, array $allowed, array $data): array|false
    {
        self::clearError();
        $task = self::findTask($id);
        if (!$task || !in_array((string)$task->status, $allowed, true)) {
            return self::failWithCode('采购任务状态不允许操作', 'JXC_PROCUREMENT_TASK_STATUS_INVALID');
        }
        $task->save(array_merge($data, [
            'update_by' => self::adminId(),
            'update_time' => time(),
        ]));
        return ProcurementTaskService::format($task->toArray());
    }

    private static function findTask(int $id): ?ProcurementTask
    {
        if ($id <= 0) {
            return null;
        }
        $task = ProcurementTask::where('id', $id)
            ->where('tenant_id', self::tenantId())
            ->findOrEmpty();
        return $task->isEmpty() ? null : $task;
    }

    private static function failWithCode(string $message, string $code): false
    {
        self::setError($message);
        self::setReturnData(['error_code' => $code]);
        return false;
    }

    private static function generateSn(): string
    {
        return 'CGT' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
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
