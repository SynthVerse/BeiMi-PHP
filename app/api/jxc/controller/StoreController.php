<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\StoreLogic;

class StoreController extends BaseJxcController
{
    public function detail()
    {
        $params = $this->request->get();
        return $this->data(StoreLogic::getStoreInfo($params));
    }

    public function status()
    {
        return $this->data(StoreLogic::status());
    }

    public function setStore()
    {
        $params = $this->request->post();
        $result = StoreLogic::setStore($params);
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('更新成功', $result, 1, 1);
    }

    public function createStore()
    {
        $params = $this->request->post();
        $result = StoreLogic::createStore($params);
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('创建成功', $result, 1, 1);
    }

    public function lists()
    {
        return $this->data(StoreLogic::listStores());
    }

    public function switchStore()
    {
        $params = $this->request->post();
        $result = StoreLogic::switchStore($params);
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('切换成功', $result, 1, 1);
    }

    public function join()
    {
        $params = $this->request->post();
        $result = StoreLogic::joinStore($params);
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('加入成功', $result, 1, 1);
    }

    public function invite()
    {
        $result = StoreLogic::inviteCode();
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->data($result);
    }

    public function memberInvite()
    {
        $result = StoreLogic::inviteCode();
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->data($result);
    }

    public function acceptMemberInvite()
    {
        $result = StoreLogic::acceptMemberInvite($this->request->post());
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('加入成功', $result, 1, 1);
    }

    public function hierarchy()
    {
        $result = StoreLogic::hierarchy();
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->data($result);
    }

    public function hierarchyChildren()
    {
        $result = StoreLogic::hierarchyChildren();
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->data($result);
    }

    public function hierarchyTree()
    {
        $result = StoreLogic::hierarchyTree();
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->data($result);
    }

    public function hierarchyInvitePreview()
    {
        $result = StoreLogic::hierarchyInvitePreview($this->request->get());
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->data($result);
    }

    public function createHierarchyInvite()
    {
        $result = StoreLogic::createHierarchyInvite($this->request->post());
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('创建成功', $result, 1, 1);
    }

    public function acceptHierarchyInvite()
    {
        $result = StoreLogic::acceptHierarchyInvite($this->request->post());
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('加入成功', $result, 1, 1);
    }

    public function unbindHierarchy()
    {
        $result = StoreLogic::unbindHierarchy($this->request->post());
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('解除成功', $result, 1, 1);
    }
}
