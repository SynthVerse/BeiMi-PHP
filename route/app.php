<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Console;
use think\facade\Route;

// tenant 项目总后台入口，供开发人员、BeiMi项目总负责人和项目管理者使用
Route::rule('platform/:any', function () {
    return view(app()->getRootPath() . 'public/platform/index.html');
})->pattern(['any' => '\w+']);

// 框架保留的企业侧管理入口，不是 tenant 项目总后台
Route::rule('admin/:any', function () {
    return view(app()->getRootPath() . 'public/admin/index.html');
})->pattern(['any' => '\w+']);

// 手机端
Route::rule('mobile/:any', function () {
    return view(app()->getRootPath() . 'public/mobile/index.html');
})->pattern(['any' => '\w+']);

// PC端
Route::rule('pc/:any', function () {
    return view(app()->getRootPath() . 'public/pc/index.html');
})->pattern(['any' => '\w+']);

//定时任务
Route::rule('crontab', function () {
    Console::call('crontab');
});
