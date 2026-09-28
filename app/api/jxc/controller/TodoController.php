<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\TodoQueryLogic;

class TodoController extends BaseJxcController
{
    public function summary() { return $this->respond(TodoQueryLogic::summary()); }
    public function lists() { return $this->respond(TodoQueryLogic::lists($this->request->get())); }

    private function respond(array|false $result)
    {
        return $result === false ? $this->fail(TodoQueryLogic::getError()) : $this->data($result);
    }
}
