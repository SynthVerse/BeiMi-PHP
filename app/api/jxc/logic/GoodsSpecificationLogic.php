<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\GoodsSpec;
use app\common\model\jxc\GoodsSpecTemplate;
use app\common\model\jxc\GoodsSpecValue;
use think\facade\Db;

class GoodsSpecificationLogic extends BaseLogic
{
    /**
     * 获取商品品质列表
     * 从 la_goods_spec_value 中查询 spec.code='quality_status' 的记录
     */
    public static function qualityList(array $params): array
    {
        $goodsId = (int)($params['goods_id'] ?? 0);
        $spec = self::getSpec('quality_status');
        if (!$spec || $goodsId <= 0) {
            return [];
        }

        $rows = GoodsSpecValue::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('spec_id', (int)$spec['id'])
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        return array_map(function ($item) {
            return [
                'id' => (int)$item['id'],
                'name' => (string)$item['name'],
                'code' => (string)$item['code'],
                'status' => (int)$item['status'],
                'sort' => (int)$item['sort'],
            ];
        }, $rows);
    }

    /**
     * 获取商品规格列表
     * 从 la_goods_spec_value 中查询 spec.code='weight_grade' 的记录
     */
    public static function specificationList(array $params): array
    {
        $goodsId = (int)($params['goods_id'] ?? 0);
        $spec = self::getSpec('weight_grade');
        if (!$spec || $goodsId <= 0) {
            return [];
        }

        $rows = GoodsSpecValue::where('tenant_id', self::tenantId())
            ->where('goods_id', $goodsId)
            ->where('spec_id', (int)$spec['id'])
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        return array_map(function ($item) {
            return [
                'id' => (int)$item['id'],
                'name' => (string)$item['name'],
                'code' => (string)$item['code'],
                'status' => (int)$item['status'],
                'sort' => (int)$item['sort'],
            ];
        }, $rows);
    }

    /**
     * 保存品质列表
     * 接收 goods_id + qualities[] (每项包含 name, code, status)
     * 需要：更新/创建 spec_value 记录
     * 返回完整品质列表
     */
    public static function saveQualities(array $params): array|false
    {
        $goodsId = (int)($params['goods_id'] ?? 0);
        if ($goodsId <= 0) {
            self::setError('商品ID不能为空');
            return false;
        }

        $spec = self::ensureSpec('quality_status', '品质状态');
        if (!$spec) {
            self::setError('无法获取品质规格维度');
            return false;
        }

        $qualities = $params['qualities'] ?? [];
        if (!is_array($qualities)) {
            self::setError('品质列表格式错误');
            return false;
        }

        Db::startTrans();
        try {
            foreach (array_values($qualities) as $index => $quality) {
                if (!is_array($quality)) {
                    continue;
                }
                $name = trim((string)($quality['name'] ?? ''));
                $code = trim((string)($quality['code'] ?? ''));
                if ($code === '' && $name !== '') {
                    $code = strtolower((string)preg_replace('/[^a-zA-Z0-9_]+/', '_', $name));
                }
                if ($name === '') {
                    continue;
                }

                $id = (int)($quality['id'] ?? 0);
                $status = (int)($quality['status'] ?? 1);
                $sort = (int)($quality['sort'] ?? $index);

                if ($id > 0) {
                    $model = GoodsSpecValue::where('id', $id)
                        ->where('tenant_id', self::tenantId())
                        ->where('goods_id', $goodsId)
                        ->where('spec_id', (int)$spec['id'])
                        ->findOrEmpty();
                    if (!$model->isEmpty()) {
                        $model->save([
                            'name' => $name,
                            'code' => $code,
                            'status' => $status,
                            'sort' => $sort,
                            'update_time' => time(),
                        ]);
                    }
                } else {
                    // 检查是否已存在相同code（商品级去重）
                    $existing = GoodsSpecValue::where('tenant_id', self::tenantId())
                        ->where('goods_id', $goodsId)
                        ->where('spec_id', (int)$spec['id'])
                        ->where('code', $code)
                        ->findOrEmpty();
                    if ($existing->isEmpty()) {
                        GoodsSpecValue::create([
                            'tenant_id' => self::tenantId(),
                            'goods_id' => $goodsId,
                            'spec_id' => (int)$spec['id'],
                            'name' => $name,
                            'code' => $code,
                            'status' => $status,
                            'sort' => $sort,
                            'create_time' => time(),
                            'update_time' => time(),
                        ]);
                    } else {
                        $existing->save([
                            'name' => $name,
                            'status' => $status,
                            'sort' => $sort,
                            'update_time' => time(),
                        ]);
                    }
                }
            }
            Db::commit();
            return self::qualityList($params);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * 保存规格列表
     * 接收 goods_id + specifications[] (每项包含 name, code, status)
     * 需要：更新/创建 spec_value 记录
     * 返回完整规格列表
     */
    public static function saveSpecifications(array $params): array|false
    {
        $goodsId = (int)($params['goods_id'] ?? 0);
        if ($goodsId <= 0) {
            self::setError('商品ID不能为空');
            return false;
        }

        $spec = self::ensureSpec('weight_grade', '重量规格');
        if (!$spec) {
            self::setError('无法获取规格维度');
            return false;
        }

        $specifications = $params['specifications'] ?? [];
        if (!is_array($specifications)) {
            self::setError('规格列表格式错误');
            return false;
        }

        Db::startTrans();
        try {
            foreach (array_values($specifications) as $index => $specification) {
                if (!is_array($specification)) {
                    continue;
                }
                $name = trim((string)($specification['name'] ?? ''));
                $code = trim((string)($specification['code'] ?? ''));
                if ($code === '' && $name !== '') {
                    $code = strtolower((string)preg_replace('/[^a-zA-Z0-9_]+/', '_', $name));
                }
                if ($name === '') {
                    continue;
                }

                $id = (int)($specification['id'] ?? 0);
                $status = (int)($specification['status'] ?? 1);
                $sort = (int)($specification['sort'] ?? $index);

                if ($id > 0) {
                    $model = GoodsSpecValue::where('id', $id)
                        ->where('tenant_id', self::tenantId())
                        ->where('goods_id', $goodsId)
                        ->where('spec_id', (int)$spec['id'])
                        ->findOrEmpty();
                    if (!$model->isEmpty()) {
                        $model->save([
                            'name' => $name,
                            'code' => $code,
                            'status' => $status,
                            'sort' => $sort,
                            'update_time' => time(),
                        ]);
                    }
                } else {
                    // 检查是否已存在相同code（商品级去重）
                    $existing = GoodsSpecValue::where('tenant_id', self::tenantId())
                        ->where('goods_id', $goodsId)
                        ->where('spec_id', (int)$spec['id'])
                        ->where('code', $code)
                        ->findOrEmpty();
                    if ($existing->isEmpty()) {
                        GoodsSpecValue::create([
                            'tenant_id' => self::tenantId(),
                            'goods_id' => $goodsId,
                            'spec_id' => (int)$spec['id'],
                            'name' => $name,
                            'code' => $code,
                            'status' => $status,
                            'sort' => $sort,
                            'create_time' => time(),
                            'update_time' => time(),
                        ]);
                    } else {
                        $existing->save([
                            'name' => $name,
                            'status' => $status,
                            'sort' => $sort,
                            'update_time' => time(),
                        ]);
                    }
                }
            }
            Db::commit();
            return self::specificationList($params);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * 获取指定 spec code 的 spec 记录
     */
    protected static function getSpec(string $specCode): ?array
    {
        $template = GoodsSpecTemplate::where('tenant_id', self::tenantId())
            ->where('code', 'aquatic_quality')
            ->findOrEmpty();
        if ($template->isEmpty()) {
            return null;
        }

        $spec = GoodsSpec::where('tenant_id', self::tenantId())
            ->where('template_id', (int)$template->id)
            ->where('code', $specCode)
            ->findOrEmpty();
        if ($spec->isEmpty()) {
            return null;
        }

        return $spec->toArray();
    }

    /**
     * 确保 spec 维度存在（不存在则自动创建）
     */
    protected static function ensureSpec(string $specCode, string $specName): ?array
    {
        $template = GoodsSpecTemplate::where('tenant_id', self::tenantId())
            ->where('code', 'aquatic_quality')
            ->findOrEmpty();
        if ($template->isEmpty()) {
            $template = GoodsSpecTemplate::create([
                'tenant_id' => self::tenantId(),
                'name' => '水产品质',
                'code' => 'aquatic_quality',
                'status' => 1,
                'sort' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $spec = GoodsSpec::where('tenant_id', self::tenantId())
            ->where('template_id', (int)$template->id)
            ->where('code', $specCode)
            ->findOrEmpty();
        if ($spec->isEmpty()) {
            $spec = GoodsSpec::create([
                'tenant_id' => self::tenantId(),
                'template_id' => (int)$template->id,
                'name' => $specName,
                'code' => $specCode,
                'status' => 1,
                'sort' => $specCode === 'weight_grade' ? 1 : 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        return $spec->toArray();
    }

    protected static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
