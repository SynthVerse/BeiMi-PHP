<?php
namespace app\tenantapi\controller\goods;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\goods\TenantUnitsLists;
use app\tenantapi\logic\goods\TenantUnitsLogic;
use app\tenantapi\validate\goods\TenantUnitsValidate;


/**
 * TenantUnits控制器
 * Class TenantUnitsController
 * @package app\platform\controller
 */
class TenantUnitsController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:28
     */
    public function lists()
    {
        return $this->dataLists(new TenantUnitsLists($this->tenantId));
    }


    /**
     * @notes 添加
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:28
     */
    public function add()
    {
        $params = (new TenantUnitsValidate())->post()->goCheck('add');
        $params['tenant_id'] = $this->tenantId;
        $result = TenantUnitsLogic::add($params);
        if (true === $result) {
            return $this->success('添加成功', [], 1, 1);
        }
        return $this->fail(TenantUnitsLogic::getError());
    }


    /**
     * @notes 编辑
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:28
     */
    public function edit()
    {
        $params = (new TenantUnitsValidate())->post()->goCheck('edit');
        $result = TenantUnitsLogic::edit($params);
        if (true === $result) {
            return $this->success('编辑成功', [], 1, 1);
        }
        return $this->fail(TenantUnitsLogic::getError());
    }


    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:28
     */
    public function delete()
    {
        $params = (new TenantUnitsValidate())->post()->goCheck('delete');
        TenantUnitsLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 获取详情
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:28
     */
    public function detail()
    {
        $params = (new TenantUnitsValidate())->goCheck('detail');
        $result = TenantUnitsLogic::detail($params);
        return $this->data($result);
    }

    /**
     * @notes 获取所有
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:28
     */
    public function all()
    {
        $result = TenantUnitsLogic::all();
        return $this->data($result);
    }
}