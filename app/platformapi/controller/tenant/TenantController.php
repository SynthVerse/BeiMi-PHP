<?php
namespace app\platformapi\controller\tenant;

use app\common\cache\AdminAuthCache;
use app\common\model\dept\TenantDept;
use app\common\model\user\UserGroup;
use app\common\service\jxc\DefaultDataInitService;
use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\tenant\TenantLists;
use app\platformapi\lists\tenant\TenantClosureReceiptLists;
use app\platformapi\lists\tenant\TenantRecycleLists;
use app\platformapi\logic\setting\pay\PayConfigLogic;
use app\platformapi\logic\setting\pay\PayWayLogic;
use app\platformapi\logic\tenant\TenantAdminLogic;
use app\platformapi\logic\tenant\TenantLogic;
use app\platformapi\logic\tenant\TenantSystemMenuLogic;
use app\platformapi\service\TenantCreatService;
use app\platformapi\validate\tenant\TenantValidate;
use app\tenantapi\logic\article\ArticleLogic;
use app\tenantapi\logic\decorate\DecorateDataLogic;
use app\tenantapi\logic\notice\NoticeLogic;
use app\tenantapi\logic\user\UserGroupLogic;
use think\facade\Db;
use think\helper\Str;

/**
 * 用户控制器
 * Class TenantController
 * @package app\platformapi\controller\user
 */
class TenantController extends BaseAdminController
{

    /**
     * @notes 用户列表
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:16
     */
    public function lists()
    {
        return $this->dataLists(new TenantLists());
    }

    /**
     * @notes 店铺回收站列表
     * @return \think\response\Json
     */
    public function recycleLists()
    {
        return $this->dataLists(new TenantRecycleLists());
    }

    public function closureReceipts()
    {
        if (!$this->assertPlatformPermission('tenant.tenant/lists')) {
            return $this->fail('权限不足，无法查询注销凭据');
        }
        return $this->dataLists(new TenantClosureReceiptLists());
    }

    public function confirmClosureBackupPurged()
    {
        if (!$this->assertPlatformPermission('tenant.tenant/edit')) {
            return $this->fail('权限不足，无法登记备份清理状态');
        }
        $params = $this->request->post();
        $result = TenantLogic::confirmClosureBackupPurged(
            (string)($params['public_id'] ?? $params['receipt_id'] ?? ''),
            $this->adminId,
            (bool)($params['confirmed'] ?? false)
        );
        if ($result === false) {
            return $this->fail(TenantLogic::getError());
        }
        return $this->success('备份清理状态已登记', $result, 1, 1);
    }

    private function assertPlatformPermission(string $uri): bool
    {
        if ((int)($this->adminInfo['root'] ?? 0) === 1) {
            return true;
        }
        $target = strtolower(Str::camel($uri));
        $permissions = (new AdminAuthCache($this->adminId))->getAdminUri() ?? [];
        $permissions = array_map(
            static fn ($permission): string => strtolower(Str::camel((string)$permission)),
            $permissions
        );
        return in_array($target, $permissions, true);
    }


    /**
     * @notes 获取用户详情
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function detail()
    {
        $params = (new TenantValidate())->goCheck('detail');
        $result = TenantLogic::detail($params['id']);
        if (false === $result) {
            return $this->fail(TenantLogic::getError());
        }
        return $this->success('获取成功', $result);
    }

    /**
     * @notes 新增租户信息 同步初始化对应租户信息
     * @return \think\response\Json
     * @author yfdong
     * @date 2024/09/07 12:23
     */
    public function add()
    {
        $params = (new TenantValidate())->post()->goCheck('add');
        try {
            // 开始事务
            DB::startTrans();
            // 验证参数
            // 创建租户基本信息
            $expiredTime = empty($params['expired_time']) ? time() : strtotime((string)$params['expired_time']);
            if (false === $expiredTime) {
                throw new \Exception('有效期格式错误');
            }
            $params['expired_time'] = $expiredTime;
            $tenant = TenantLogic::add($params);
            // 判断用户是否采用分表模式
            if (isset($params['tactics']) && $params['tactics'] == '1') {
                (new TenantCreatService)->createTenantTable($tenant['sn']);
                (new TenantCreatService)->initializationTenantData($tenant['id'],$tenant['sn'],$params);
            }else{
                // 初始化租户文章列表
                ArticleLogic::initialization($tenant['id']);
                // 初始化租户管理员账号
                $managerInfo = TenantAdminLogic::initialization($tenant['id'], $tenant['sn'], $params);
                // 初始化管理员部门信息
                TenantDept::initialization($tenant['id'], $managerInfo['id']);
                // 创建租户菜单权限
                //TenantSystemMenuLogic::initialization($tenant['id']);
                // 初始化支付方式配置
                PayConfigLogic::initialization($tenant['id']);
                // 初始化支付配置是否开启
                PayWayLogic::initialization($tenant['id']);
                //初始化客户组
                UserGroupLogic::initialization($tenant['id']);
            }
            // 初始化 JXC 默认基础数据（默认仓库/客户/供应商/计量单位）
            DefaultDataInitService::initForTenant((int)$tenant['id']);
            // 提交事务
            DB::commit();
            // 返回成功
            return $this->success('新增成功', ['id' => (int)$tenant['id']], 1, 1);
        } catch (\Exception $e) {
            // 回滚事务
            DB::rollBack();
            // 处理异常并返回错误信息
            return $this->fail('新增失败：' . $e->getMessage());
        }
    }

    /**
     * @notes 编辑用户信息
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function edit()
    {
        $params = (new TenantValidate())->post()->goCheck('edit');
        $result = TenantLogic::edit($params);
        if (true === $result) {
            return $this->success('操作成功', [], 1, 1);
        }
        return $this->fail(TenantLogic::getError());
    }

    /**
     * @notes 放入回收站
     * @return \think\response\Json
     * @author JXDN
     * @date 2024/09/03 17:02
     */
    public function delete()
    {
        $params = (new TenantValidate())->post()->goCheck('delete');
        $result = TenantLogic::delete($params);
        if (true === $result) {
            return $this->success('已放入回收站', [], 1, 1);
        }
        return $this->fail(TenantLogic::getError());
    }

    /**
     * @notes 恢复回收站店铺
     * @return \think\response\Json
     */
    public function restore()
    {
        $params = (new TenantValidate())->post()->goCheck('restore');
        $result = TenantLogic::restore($params);
        if (true === $result) {
            return $this->success('恢复成功', [], 1, 1);
        }
        return $this->fail(TenantLogic::getError());
    }
}
