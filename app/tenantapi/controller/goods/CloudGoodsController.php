<?php

namespace app\tenantapi\controller\goods;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\goods\CloudGoodsLists;
use app\tenantapi\logic\goods\CloudGoodsLogic;
use app\tenantapi\validate\goods\CloudGoodsValidate;

class CloudGoodsController extends BaseAdminController
{
    public function lists()
    {
        (new CloudGoodsValidate())->get()->goCheck('lists');
        return $this->dataLists(new CloudGoodsLists());
    }

    public function add()
    {
        $params = (new CloudGoodsValidate())->post()->goCheck('add');
        $result = CloudGoodsLogic::add($params, $this->tenantId, $this->adminId);
        if ($result === false) {
            return $this->fail(CloudGoodsLogic::getError());
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new CloudGoodsValidate())->post()->goCheck('edit');
        $result = CloudGoodsLogic::edit($params, $this->tenantId, $this->adminId);
        if ($result === false) {
            return $this->fail(CloudGoodsLogic::getError());
        }
        return $this->success('编辑成功', [], 1, 1);
    }

    public function delete()
    {
        $params = (new CloudGoodsValidate())->post()->goCheck('delete');
        $result = CloudGoodsLogic::delete($params, $this->tenantId);
        if ($result === false) {
            return $this->fail(CloudGoodsLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

    public function detail()
    {
        $params = (new CloudGoodsValidate())->goCheck('detail');
        return $this->data(CloudGoodsLogic::detail($params, $this->tenantId));
    }

    public function load()
    {
        $params = (new CloudGoodsValidate())->post()->goCheck('load');
        $params['unit_id'] = (int)($params['unit_id'] ?? $params['units_id'] ?? 0);
        if ($params['unit_id'] <= 0) {
            return $this->fail('请选择有效的本地单位');
        }
        $result = CloudGoodsLogic::load($params, $this->tenantId, $this->adminId);
        if ($result === false) {
            return $this->fail(CloudGoodsLogic::getError());
        }
        return $this->success($result['loaded'] ? '加载成功' : '商品已存在', $result, 1, 1);
    }
}
