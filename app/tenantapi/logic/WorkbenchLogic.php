<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------

namespace app\tenantapi\logic;


use app\common\logic\BaseLogic;
use app\common\model\supplier\UserSupplier;
use app\common\model\supplier\UserSupplierOrder;
use app\common\model\user\User;
use app\common\model\user\UserOrder;
use app\common\service\ConfigService;
use app\common\service\FileService;


/**
 * 工作台
 * Class WorkbenchLogic
 * @package app\tenantapi\logic
 */
class WorkbenchLogic extends BaseLogic
{
    /**
     * @notes 工作套
     * @param $adminInfo
     * @return array
     * @author 段誉
     * @date 2021/12/29 15:58
     */
    public static function index()
    {
        return [
            // 版本信息
            'version' => self::versionInfo(),
            // 今日数据
            'today' => self::today(),
            // 常用功能
            'menu' => self::menu(),
            // 近15日访客数
            'visitor' => self::visitor(),
            // 服务支持
            'support' => self::support(),
            // 销售数据
            'sale' => self::sale()
        ];
    }


    /**
     * @notes 常用功能
     * @return array[]
     * @author 段誉
     * @date 2021/12/29 16:40
     */
    public static function menu(): array
    {
        return [
            [
                'name' => '客户管理',
                'image' => FileService::getFileUrl(config('project.default_image.menu_role')),
                'url' => '/consumer/lists'
            ],
            [
                'name' => '商品管理',
                'image' => FileService::getFileUrl(config('project.default_image.menu_goods')),
                'url' => '/goods/lists'
            ],
            [
                'name' => '销售订单',
                'image' => FileService::getFileUrl(config('project.default_image.menu_goods_order')),
                'url' => '/consumer/order'
            ],
            [
                'name' => '供应商管理',
                'image' => FileService::getFileUrl(config('project.default_image.menu_supplier')),
                'url' => '/supplier/lists'
            ],
            [
                'name' => '供应商订单',
                'image' => FileService::getFileUrl(config('project.default_image.menu_supplier_order')),
                'url' => '/supplier/order'
            ],
            [
                'name' => '管理员管理',
                'image' => FileService::getFileUrl(config('project.default_image.menu_dept')),
                'url' => '/organization/admin'
            ],
            // [
            //     'name' => '字典管理',
            //     'image' => FileService::getFileUrl(config('project.default_image.menu_dict')),
            //     'url' => '/setting/dev_tools/dict'
            // ],
            // [
            //     'name' => '代码生成器',
            //     'image' => FileService::getFileUrl(config('project.default_image.menu_generator')),
            //     'url' => '/setting/dev_tools/code'
            // ],
//            [
//                'name' => '素材中心',
//                'image' => FileService::getFileUrl(config('project.default_image.menu_file')),
//                'url' => '/app/material/index'
//            ],
//            [
//                'name' => '菜单权限',
//                'image' => FileService::getFileUrl(config('project.default_image.menu_auth')),
//                'url' => '/permission/menu'
//            ],
//            [
//                'name' => '网站信息',
//                'image' => FileService::getFileUrl(config('project.default_image.menu_web')),
//                'url' => '/setting/website/information'
//            ],
        ];
    }


    /**
     * @notes 版本信息
     * @return array
     * @author 段誉
     * @date 2021/12/29 16:08
     */
    public static function versionInfo(): array
    {
        return [
            'version' => config('project.version'),
            'website' => config('project.website.url'),
            'name' => ConfigService::get('tenant', 'name'),
            'based' => 'sass系统',
        ];
    }


    /**
     * @notes 今日数据
     * @return int[]
     * @author 段誉
     * @date 2021/12/29 16:15
     */
    public static function today(): array
    {
        return [
            'time' => date('Y-m-d H:i:s'),

            // 今日新增用户量
            'today_new_user' => User::where('create_time', '>=', strtotime('today'))->count(),
            // 总用户量
            'total_new_user' => User::count(),

            // 今日新增客户订单
            'today_new_user_order' => UserOrder::where('create_time', '>=', strtotime('today'))->sum("order_money"),
            // 总供客户订单
            'total_new_user_order' => UserOrder::sum("order_money"),

            // 客户订单数量
            'total_new_user_num' => UserOrder::count(),
            'today_new_user_num' => UserOrder::where('create_time', '>=', strtotime('today'))->count(),

            // 客户订单欠款
            'total_new_user_debt' => UserOrder::sum("order_arrears_money"),
            'today_new_user_debt' => UserOrder::where('create_time', '>=', strtotime('today'))->sum("order_arrears_money"),

            // 今日新增供应商
            'today_new_supplier' => UserSupplier::where('create_time', '>=', strtotime('today'))->count(),
            // 总供应商
            'total_new_supplier' => UserSupplier::count(),

            // 今日新增供应商订单
            'today_new_supplier_order' => UserSupplierOrder::where('create_time', '>=', strtotime('today'))->sum("order_money"),
            // 总供应商订单
            'total_new_supplier_order' => UserSupplierOrder::sum("order_money"),

            // 供应商订单数量
            'total_new_supplier_num' => UserSupplierOrder::count(),
            'today_new_supplier_num' => UserSupplierOrder::where('create_time', '>=', strtotime('today'))->count(),

            // 供应商订单欠款
            'total_new_supplier_debt' => UserSupplierOrder::sum("order_arrears_money"),
            'today_new_supplier_debt' => UserSupplierOrder::where('create_time', '>=', strtotime('today'))->sum("order_arrears_money"),

        ];
    }


    /**
     * @notes 访问数
     * @return array
     * @author 段誉
     * @date 2021/12/29 16:57
     */
    public static function visitor(): array
    {
        $num = [];
        $date = [];
        for ($i = 0; $i < 15; $i++) {
            $where_start = strtotime("- " . $i . "day");
            $date[] = date('m/d', $where_start);
            $num[$i] = rand(0, 100);
        }

        return [
            'date' => $date,
            'list' => [
                ['name' => '访客数', 'data' => $num]
            ]
        ];
    }

    /**
     * @notes 访问数
     * @return array
     * @author 段誉
     * @date 2021/12/29 16:57
     */
    public static function sale(): array
    {
        $num = [];
        $date = [];
        for ($i = 0; $i < 7; $i++) {
            $where_start = strtotime("- " . $i . "day");
            $date[] = date('m/d', $where_start);
            $num[$i] = rand(30, 200);
        }

        return [
            'date' => $date,
            'list' => [
                ['name' => '销售量', 'data' => $num]
            ]
        ];
    }


    /**
     * @notes 服务支持
     * @return array[]
     * @author 段誉
     * @date 2022/7/18 11:18
     */
    public static function support()
    {
        return [
            [
                'image' => FileService::getFileUrl(config('project.default_image.qq_group')),
                'title' => '官方公众号',
                'desc' => '关注官方公众号',
            ],
            [
                'image' => FileService::getFileUrl(config('project.default_image.customer_service')),
                'title' => '添加企业客服微信',
                'desc' => '想了解更多请添加客服',
            ]
        ];
    }

}