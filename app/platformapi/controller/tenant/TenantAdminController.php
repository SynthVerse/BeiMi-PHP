<?php

namespace app\platformapi\controller\tenant;

use app\api\validate\PasswordValidate;
use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\tenant\TenantAdminLists;
use app\platformapi\logic\tenant\TenantAdminLogic;
use app\platformapi\validate\tenant\TenantAdminValidate;
use think\response\Json;

/**
 * 租户账号用户控制器
 * Class TenantController
 * @package app\platformapi\controller\user
 */
class TenantAdminController extends BaseAdminController
{

    /**
     * @notes 获取租户管理员账号 租户查询参数必传防止越权查看
     * @return Json
     * @author yfdong
     * @date 2024/09/04 22:11
     */
    public function lists()
    {
        return $this->dataLists(new TenantAdminLists());
    }


    /**
     * @notes 获取租户管理员账号 租户id以及主键id查询参数必传 防止越权查看
     * @return Json
     * @author yfdong
     * @date 2024/09/04 22:14
     */
    public function detail()
    {
        $params = (new TenantAdminValidate())->goCheck('detail');
        $result = TenantAdminLogic::detail($params['id']);
        if (false === $result) {
            return $this->fail(TenantAdminLogic::getError());
        }
        return $this->success('获取成功', $result);
    }

    /**
     * @notes 创建租户管理员账号
     * @return Json
     * @author yfdong
     * @date 2024/09/04 22:58
     */
   public function add()
    {
        $params = (new TenantAdminValidate())->post()->goCheck('add');
        $result = TenantAdminLogic::add($params);
        if (true === $result) {
            return $this->success('操作成功', [], 1, 1);
        }
        return $this->fail(TenantAdminLogic::getError());
    }

    /**
     * @notes 编辑租户管理员账号
     * @return Json
     * @author yfdong
     * @date 2024/09/04 22:58
     */
    public function edit()
    {
        $params = (new TenantAdminValidate())->post()->goCheck('edit');
        $result = TenantAdminLogic::edit($params);
        if (true === $result) {
            return $this->success('操作成功', [], 1, 1);
        }
        return $this->fail(TenantAdminLogic::getError());
    }

    /**
     * @notes 删除租户管理员账号
     * @return Json
     * @author yfdong
     * @date 2024/09/04 22:59
     */
    public function delete()
    {
        $params = (new TenantAdminValidate())->post()->goCheck('delete');
        $result = TenantAdminLogic::delete($params);
        if (true === $result) {
            return $this->success('删除成功', [], 1, 1);
        }
        return $this->fail(TenantAdminLogic::getError());
    }
}