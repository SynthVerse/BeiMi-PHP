<?php
namespace app\tenantapi\logic\goods;


use app\common\model\goods\TenantProductUnitConversion;
use app\common\logic\BaseLogic;
use think\facade\Db;


/**
 * 商品单位换算逻辑
 * Class TenantUnitConversionLogic
 * @package app\tenantapi\logic\goods
 */
class TenantUnitConversionLogic extends BaseLogic
{

    /**
     * @notes 获取商品的换算配置列表
     * @param int $tenantId 租户ID
     * @param int $productId 商品ID
     * @return array
     */
    public static function list(int $tenantId, int $productId): array
    {
        return TenantProductUnitConversion::where([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
        ])->order(['id' => 'asc'])
          ->field(['id', 'target_unit_id', 'target_unit_name', 'conversion_rate'])
          ->select()
          ->toArray();
    }

    /**
     * @notes 批量保存换算配置（全量覆盖）
     * @param int $tenantId 租户ID
     * @param int $productId 商品ID
     * @param array $conversions 换算配置数组 [{target_unit_id, target_unit_name, conversion_rate}]
     * @return bool
     */
    public static function save(int $tenantId, int $productId, array $conversions): bool
    {
        Db::startTrans();
        try {
            // 删除旧配置
            TenantProductUnitConversion::where([
                'tenant_id' => $tenantId,
                'product_id' => $productId,
            ])->delete();

            // 批量插入新配置
            if (!empty($conversions)) {
                $insertData = [];
                foreach ($conversions as $conv) {
                    $insertData[] = [
                        'tenant_id' => $tenantId,
                        'product_id' => $productId,
                        'target_unit_id' => intval($conv['target_unit_id'] ?? 0),
                        'target_unit_name' => $conv['target_unit_name'] ?? '',
                        'conversion_rate' => floatval($conv['conversion_rate'] ?? 0),
                        'create_time' => time(),
                        'update_time' => time(),
                    ];
                }
                (new TenantProductUnitConversion())->saveAll($insertData);
            }

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * @notes 删除单个换算配置
     * @param int $tenantId 租户ID
     * @param int $id 记录ID
     * @return bool
     */
    public static function delete(int $tenantId, int $id): bool
    {
        return TenantProductUnitConversion::where([
            'id' => $id,
            'tenant_id' => $tenantId,
        ])->delete() > 0;
    }
}
