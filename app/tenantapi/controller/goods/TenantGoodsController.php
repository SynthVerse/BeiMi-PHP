<?php
namespace app\tenantapi\controller\goods;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\goods\TenantGoodsLists;
use app\tenantapi\logic\goods\TenantGoodsLogic;
use app\tenantapi\validate\goods\TenantGoodsValidate;


/**
 * TenantGoods控制器
 * Class TenantGoodsController
 * @package app\platform\controller
 */
class TenantGoodsController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:26
     */
    public function lists()
    {
        return $this->dataLists(new TenantGoodsLists());
    }


    /**
     * @notes 添加
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:26
     */
    public function add()
    {
        $params = (new TenantGoodsValidate())->post()->goCheck('add');
        $result = TenantGoodsLogic::add($params);
        if (true === $result) {
            return $this->success('添加成功', [], 1, 1);
        }
        return $this->fail(TenantGoodsLogic::getError());
    }


    /**
     * @notes 编辑
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:26
     */
    public function edit()
    {
        $params = (new TenantGoodsValidate())->post()->goCheck('edit');
        $result = TenantGoodsLogic::edit($params);
        if (true === $result) {
            return $this->success('编辑成功', [], 1, 1);
        }
        return $this->fail(TenantGoodsLogic::getError());
    }


    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:26
     */
    public function delete()
    {
        $params = (new TenantGoodsValidate())->post()->goCheck('delete');
        TenantGoodsLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 获取详情
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/04 14:26
     */
    public function detail()
    {
        $params = (new TenantGoodsValidate())->goCheck('detail');
        $result = TenantGoodsLogic::detail($params);
        return $this->data($result);
    }


}