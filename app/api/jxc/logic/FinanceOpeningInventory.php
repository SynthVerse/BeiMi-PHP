<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 首次现实盘存与已有系统库存承接分流。草稿绝不建立余额行。 */
final class FinanceOpeningInventory
{
    public static function isFirst(array $details): bool { return ($details['inventory_entry'] ?? '') === 'first_record'; }

    public static function subject(int $tenant, array $details, bool $lock = false): ?array
    {
        $warehouse = (int)($details['warehouse_id'] ?? 0); $sku = (int)($details['sku_id'] ?? 0);
        $s = Db::name('goods_sku')->where('tenant_id', $tenant)->where('id', $sku)->lock($lock)->find();
        $w = Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $warehouse)->lock($lock)->find();
        if (!$s || !$w || (!empty($details['goods_id']) && (int)$details['goods_id'] !== (int)$s['goods_id'])) { return null; }
        $goods = Db::name('goods')->where('tenant_id', $tenant)->where('id', $s['goods_id'])->lock($lock)->find();
        if (!$goods) { return null; }
        return ['id' => 0, 'warehouse_id' => $warehouse, 'sku_id' => $sku, 'goods_id' => (int)$s['goods_id'],
            'base_unit_id' => (int)(($s['base_unit_id'] ?? 0) ?: ($goods['unit_id'] ?? 0)),
            'base_unit_name' => ($s['base_unit_name'] ?? '') ?: ($goods['units'] ?? ''),
            'name' => $w['name'] . ' / ' . $s['sku_name'], 'inventory_entry' => 'first_record'];
    }

    public static function blockers(int $tenant, array $subject): array
    {
        $pair = ['tenant_id' => $tenant, 'warehouse_id' => $subject['warehouse_id'], 'sku_id' => $subject['sku_id']];
        if (Db::name('warehouse_sku_balance')->where($pair)->count() || Db::name('stock_flow')->where($pair)->count()) {
            return ['该仓库规格已有系统库存或历史流水，请选择已有库存核对截止数量，不能再次登记实物'];
        }
        if (Db::name('finance_cost_position')->where($pair)->count() || Db::name('finance_cost_effect')->where($pair)->count()) { return ['该仓库规格已有历史成本，请核对原库存来源后承接']; }
        $goodsStock = Db::name('goods')->where('tenant_id', $tenant)->where('id', $subject['goods_id'])->value('stock');
        $sum = Db::name('warehouse_sku_balance')->where('tenant_id', $tenant)->where('goods_id', $subject['goods_id'])->sum('on_hand_qty');
        if (bccomp((string)($goodsStock ?? '0'), (string)$sum, 4) !== 0) { return ['商品还有未分配到仓库规格的旧库存，请先核对原系统库存']; }
        return [];
    }

    public static function confirm(int $tenant, array $item): array
    {
        if (bccomp((string)($item['details']['quantity'] ?? '0'), '0', 4) <= 0) { throw new \DomainException('首次实物数量须大于零；没有实物请移除这笔草稿并核对没有库存'); }
        $subject = self::subject($tenant, $item['details'], true);
        if (!$subject) { throw new \DomainException('首次盘存的仓库或规格已失效'); }
        $errors = self::blockers($tenant, $subject);
        if ($errors) { throw new \DomainException(implode('；', $errors)); }
        $changed = WarehouseSkuBalanceService::inboundWithinTransaction($subject['warehouse_id'], $subject['sku_id'], $item['details']['quantity']);
        if ($changed === false) { throw new \DomainException('首次库存登记失败，资料与库存均未确认，请重试'); }
        $id = (int)Db::name('warehouse_sku_balance')->where('tenant_id', $tenant)->where('warehouse_id', $subject['warehouse_id'])->where('sku_id', $subject['sku_id'])->value('id');
        if (!$id) { throw new \DomainException('首次库存余额未建立'); }
        Db::name('finance_opening_item')->where('tenant_id', $tenant)->where('id', $item['id'])->update(['subject_id' => $id]);
        $item['subject_id'] = $id; $subject['id'] = $id; $subject['cutoff_qty'] = $item['details']['quantity'];
        $item['subject_snapshot'] = $subject; $item['subject_name'] = $subject['name'];
        return $item;
    }
}
