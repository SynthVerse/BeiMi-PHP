<?php
namespace app\tenantapi\controller\goods;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\goods\TenantGoodscatLists;
use app\tenantapi\logic\goods\TenantGoodscatLogic;
use app\tenantapi\validate\goods\TenantGoodscatValidate;


/**
 * TenantGoodscat控制器
 * Class TenantGoodscatController
 * @package app\tenantapi\controller\goods
 */
class TenantGoodscatController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function lists()
    {
        return $this->dataLists(new TenantGoodscatLists());
    }


    /**
     * @notes 添加
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function add()
    {
        $params = (new TenantGoodscatValidate())->post()->goCheck('add');
        $result = TenantGoodscatLogic::add($params);
        if (true === $result) {
            return $this->success('添加成功', [], 1, 1);
        }
        return $this->fail(TenantGoodscatLogic::getError());
    }


    /**
     * @notes 编辑
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function edit()
    {
        $params = (new TenantGoodscatValidate())->post()->goCheck('edit');
        $result = TenantGoodscatLogic::edit($params);
        if (true === $result) {
            return $this->success('编辑成功', [], 1, 1);
        }
        return $this->fail(TenantGoodscatLogic::getError());
    }


    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function delete()
    {
        $params = (new TenantGoodscatValidate())->post()->goCheck('delete');
        TenantGoodscatLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 获取详情
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function detail()
    {
        $params = (new TenantGoodscatValidate())->goCheck('detail');
        $result = TenantGoodscatLogic::detail($params);
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
        $result = TenantGoodscatLogic::all();
        return $this->data($result);
    }
}