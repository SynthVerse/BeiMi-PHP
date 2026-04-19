<?php
namespace app\tenantapi\controller\user;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\user\UserGroupLists;
use app\tenantapi\logic\user\UserGroupLogic;
use app\tenantapi\validate\user\UserGroupValidate;


/**
 * UserGroup控制器
 * Class UserGroupController
 * @package app\platform\controller
 */
class UserGroupController extends BaseAdminController
{


    /**
     * @notes 获取列表
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/08 09:50
     */
    public function lists()
    {
        return $this->dataLists(new UserGroupLists());
    }


    /**
     * @notes 添加
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/08 09:50
     */
    public function add()
    {
        $params = (new UserGroupValidate())->post()->goCheck('add');
        $params['tenant_id'] = $this->tenantId;
        $result = UserGroupLogic::add($params);
        if (true === $result) {
            return $this->success('添加成功', [], 1, 1);
        }
        return $this->fail(UserGroupLogic::getError());
    }


    /**
     * @notes 编辑
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/08 09:50
     */
    public function edit()
    {
        $params = (new UserGroupValidate())->post()->goCheck('edit');
        $params['tenant_id'] = $this->tenantId;
        $result = UserGroupLogic::edit($params);
        if (true === $result) {
            return $this->success('编辑成功', [], 1, 1);
        }
        return $this->fail(UserGroupLogic::getError());
    }


    /**
     * @notes 删除
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/08 09:50
     */
    public function delete()
    {
        $params = (new UserGroupValidate())->post()->goCheck('delete');
        UserGroupLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }


    /**
     * @notes 获取详情
     * @return \think\response\Json
     * @author likeadmin
     * @date 2025/12/08 09:50
     */
    public function detail()
    {
        $params = (new UserGroupValidate())->goCheck('detail');
        $result = UserGroupLogic::detail($params);
        return $this->data($result);
    }

    /**
     * @notes 获取数据
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 乔发疯
     * @date 2022/10/13 10:39
     */
    public function all()
    {
        $result = UserGroupLogic::getAllData();
        return $this->data($result);
    }

}