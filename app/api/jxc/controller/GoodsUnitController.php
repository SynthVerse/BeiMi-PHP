<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\GoodsUnitLists;
use app\api\jxc\logic\GoodsUnitLogic;
use app\api\jxc\validate\GoodsUnitValidate;

class GoodsUnitController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(GoodsUnitLists::class);
    }

    public function add()
    {
        $params = (new GoodsUnitValidate())->post()->goCheck('add');
        $result = GoodsUnitLogic::add($params);
        if ($result === false) {
            return $this->fail(GoodsUnitLogic::getError());
        }
        return $this->success('添加成功', [], 1, 1);
    }

    public function edit()
    {
        $params = (new GoodsUnitValidate())->post()->goCheck('edit');
        $result = GoodsUnitLogic::edit($params);
        if ($result === false) {
            return $this->fail(GoodsUnitLogic::getError());
        }
        return $this->success('编辑成功', [], 1, 1);
    }

    public function delete()
    {
        $params = (new GoodsUnitValidate())->goCheck('delete');
        $result = GoodsUnitLogic::delete($params);
        if ($result === false) {
            return $this->fail(GoodsUnitLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

    public function detail()
    {
        $params = (new GoodsUnitValidate())->goCheck('detail');
        return $this->data(GoodsUnitLogic::detail($params));
    }
}
