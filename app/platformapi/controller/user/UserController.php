<?php

namespace app\platformapi\controller\user;

use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\user\UserLists;

class UserController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new UserLists());
    }
}
