<?php

namespace app\platformapi\controller\goods;

use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\goods\CloudGoodsArchiveLists;
use app\platformapi\lists\goods\CloudGoodsLists;
use app\platformapi\logic\goods\CloudGoodsLogic;
use app\platformapi\validate\goods\CloudGoodsValidate;

class CloudGoodsController extends BaseAdminController
{
    public function lists()
    {
        (new CloudGoodsValidate())->get()->goCheck('lists');
        return $this->dataLists(new CloudGoodsLists());
    }

    public function archive()
    {
        (new CloudGoodsValidate())->get()->goCheck('lists');
        return $this->dataLists(new CloudGoodsArchiveLists());
    }

    public function add()
    {
        $params = (new CloudGoodsValidate())->post()->goCheck('add');
        $result = CloudGoodsLogic::add($params, $this->adminId);
        if ($result === false) {
            return $this->fail(CloudGoodsLogic::getError());
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new CloudGoodsValidate())->post()->goCheck('edit');
        $result = CloudGoodsLogic::edit($params, $this->adminId);
        if ($result === false) {
            return $this->fail(CloudGoodsLogic::getError());
        }
        return $this->success('编辑成功', [], 1, 1);
    }

    public function delete()
    {
        $params = (new CloudGoodsValidate())->post()->goCheck('delete');
        $result = CloudGoodsLogic::delete($params);
        if ($result === false) {
            return $this->fail(CloudGoodsLogic::getError());
        }
        return $this->success('归档成功', [], 1, 1);
    }

    public function detail()
    {
        $params = (new CloudGoodsValidate())->goCheck('detail');
        return $this->data(CloudGoodsLogic::detail($params));
    }
}
