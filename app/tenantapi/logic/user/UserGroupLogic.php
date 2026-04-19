<?php
namespace app\tenantapi\logic\user;

use app\common\model\user\UserGroup;
use app\common\logic\BaseLogic;
use think\facade\Db;


/**
 * UserGroup逻辑
 * Class UserGroupLogic
 * @package app\platform\logic
 */
class UserGroupLogic extends BaseLogic
{


    /**
     * @notes 添加
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/08 09:46
     */
    public static function add(array $params): bool
    {
        Db::startTrans();
        try {
            UserGroup::create([
                'name' => $params['name'],
                'sort' => $params['sort'],
                'is_show' => $params['is_show'],
                'tenant_id' => $params['tenant_id'],
                'desc' => $params['desc']
            ]);

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 编辑
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/08 09:46
     */
    public static function edit(array $params): bool
    {
        Db::startTrans();
        try {
            UserGroup::where('id', $params['id'])->update([
                'name' => $params['name'],
                'sort' => $params['sort'],
                'is_show' => $params['is_show'],
                'tenant_id' => $params['tenant_id'],
                'desc' => $params['desc']
            ]);

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 删除
     * @param array $params
     * @return bool
     * @author likeadmin
     * @date 2025/12/08 09:46
     */
    public static function delete(array $params): bool
    {
        return UserGroup::destroy($params['id']);
    }


    /**
     * @notes 获取详情
     * @param $params
     * @return array
     * @author likeadmin
     * @date 2025/12/08 09:46
     */
    public static function detail($params): array
    {
        return UserGroup::findOrEmpty($params['id'])->toArray();
    }

    public static function getAllData()
    {
        return UserGroup::order(['sort' => 'desc', 'id' => 'desc'])->where(['is_show'=>0])->field(['id','name'])
            ->select()
            ->toArray();
    }


    /**
     * @notes 初始化
     * @param mixed $tenant_id
     * @return void
     * @author yfdong
     * @date 2024/09/05 23:01
     */
    public static function initialization(mixed $tenant_id){
        $params = [];
        $params['tenant_id'] = $tenant_id;
        $params['name'] = '默认组';
        $params['sort'] = 1;
        $params['is_show'] = 0;
        $params['desc'] = '默认组';
        $params['is_default'] = '1';
        return UserGroup::create($params);
    }
}