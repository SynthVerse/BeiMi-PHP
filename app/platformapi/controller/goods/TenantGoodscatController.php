<?php

namespace app\platformapi\controller\goods;

use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\goods\TenantGoodscatLists;
use app\platformapi\logic\goods\TenantGoodscatLogic;
use app\platformapi\validate\goods\TenantGoodscatValidate;

class TenantGoodscatController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new TenantGoodscatLists());
    }

    public function add()
    {
        $params = (new TenantGoodscatValidate())->post()->goCheck('add');
        $result = TenantGoodscatLogic::add($params);
        if ($result === false) {
            return $this->fail(TenantGoodscatLogic::getError());
        }
        return $this->success('添加成功', [], 1, 1);
    }

    public function edit()
    {
        $params = (new TenantGoodscatValidate())->post()->goCheck('edit');
        $result = TenantGoodscatLogic::edit($params);
        if ($result === false) {
            return $this->fail(TenantGoodscatLogic::getError());
        }
        return $this->success('编辑成功', [], 1, 1);
    }

    public function delete()
    {
        $params = (new TenantGoodscatValidate())->post()->goCheck('delete');
        $result = TenantGoodscatLogic::delete($params);
        if ($result === false) {
            return $this->fail(TenantGoodscatLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

    public function detail()
    {
        $params = (new TenantGoodscatValidate())->goCheck('detail');
        return $this->data(TenantGoodscatLogic::detail($params));
    }

    public function all()
    {
        return $this->data(TenantGoodscatLogic::all());
    }
}
