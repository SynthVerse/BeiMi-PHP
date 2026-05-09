<?php
namespace app\tenantapi\controller\goods;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\logic\goods\TenantUnitConversionLogic;


/**
 * 商品单位换算控制器
 * Class TenantUnitConversionController
 * @package app\tenantapi\controller\goods
 */
class TenantUnitConversionController extends BaseAdminController
{

    /**
     * @notes 获取商品的换算配置列表
     * @return \think\response\Json
     */
    public function list()
    {
        $productId = intval($this->request->get('product_id', 0));
        if ($productId <= 0) {
            return $this->fail('商品ID不能为空');
        }
        $result = TenantUnitConversionLogic::list($this->tenantId, $productId);
        return $this->data($result);
    }

    /**
     * @notes 批量保存换算配置
     * @return \think\response\Json
     */
    public function save()
    {
        $productId = intval($this->request->post('product_id', 0));
        $conversions = $this->request->post('conversions', []);

        if ($productId <= 0) {
            return $this->fail('商品ID不能为空');
        }

        if (!is_array($conversions)) {
            $conversions = json_decode($conversions, true) ?: [];
        }

        $result = TenantUnitConversionLogic::save($this->tenantId, $productId, $conversions);
        if ($result) {
            return $this->success('保存成功', [], 1, 1);
        }
        return $this->fail(TenantUnitConversionLogic::getError());
    }

    /**
     * @notes 删除单个换算配置
     * @return \think\response\Json
     */
    public function delete()
    {
        $id = intval($this->request->post('id', 0));
        if ($id <= 0) {
            return $this->fail('ID不能为空');
        }

        $result = TenantUnitConversionLogic::delete($this->tenantId, $id);
        if ($result) {
            return $this->success('删除成功', [], 1, 1);
        }
        return $this->fail('删除失败');
    }
}
