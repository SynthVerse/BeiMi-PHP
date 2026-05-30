<?php

use think\facade\Route;

Route::post('user/login', 'jxc.Auth/login');
Route::post('user/mnpLogin', 'login/mnpLogin');

// 用户信息接口 —— 使用 LoginMiddleware（UserTokenCache 查询，兼容微信登录用户）
Route::group('', function () {
    Route::get('user/info', 'jxc.Auth/info');
    Route::post('user/logout', 'jxc.Auth/logout');
})->middleware(\app\api\http\middleware\LoginMiddleware::class, 'enforce');

// JXC 业务接口 —— 使用 JxcLoginMiddleware（双 Token 查询）
Route::group('', function () {
    // === 单位管理 ===
    Route::get('units/index', 'jxc.GoodsUnit/lists');
    Route::get('units/detail', 'jxc.GoodsUnit/detail');
    Route::post('units/add', 'jxc.GoodsUnit/add');
    Route::post('units/edit', 'jxc.GoodsUnit/edit');
    Route::delete('units/del', 'jxc.GoodsUnit/delete');

    Route::get('warehouse/index', 'jxc.Warehouse/lists');
    Route::get('warehouse/detail', 'jxc.Warehouse/detail');
    Route::post('warehouse/add', 'jxc.Warehouse/add');
    Route::post('warehouse/edit', 'jxc.Warehouse/edit');
    Route::post('warehouse/del', 'jxc.Warehouse/delete');
    Route::post('warehouse/enable', 'jxc.Warehouse/enable');
    Route::post('warehouse/disable', 'jxc.Warehouse/disable');

    Route::get('supplier/index', 'jxc.Supplier/lists');
    Route::get('supplier/details', 'jxc.Supplier/detail');
    Route::get('supplier/goods', 'jxc.Supplier/goods');
    Route::post('supplier/paymoney', 'jxc.Supplier/paymoney');
    Route::post('supplier/add', 'jxc.Supplier/add');
    Route::post('supplier/edit', 'jxc.Supplier/edit');
    Route::delete('supplier/del', 'jxc.Supplier/delete');
    Route::post('supplier/del', 'jxc.Supplier/delete');

    Route::get('goods/index', 'jxc.Goods/lists');
    Route::get('goods/cloud/index', 'jxc.CloudGoods/lists');
    Route::get('goods/cloud/detail', 'jxc.CloudGoods/detail');
    Route::post('goods/cloud/load', 'jxc.CloudGoods/load');
    Route::get('goods/detail', 'jxc.Goods/detail');
    Route::get('goods/suppliers', 'jxc.Goods/suppliers');
    Route::post('goods/suppliers/save', 'jxc.Goods/saveSuppliers');
    Route::post('goods/add', 'jxc.Goods/add');
    Route::post('goods/edit', 'jxc.Goods/edit');
    Route::delete('goods/del', 'jxc.Goods/delete');
    Route::post('goods/del', 'jxc.Goods/delete');

    Route::get('customer/detail', 'jxc.Customer/detail');
    Route::get('customer/children', 'jxc.Customer/children');
    Route::get('customer/summary', 'jxc.Customer/summary');
    Route::get('customer/search', 'jxc.Customer/search');
    Route::get('customer/salesHistory', 'jxc.Customer/salesHistory');
    Route::get('customer/receivableSummary', 'jxc.Customer/receivableSummary');
    Route::post('customer/bindStore', 'jxc.Customer/bindStore');
    Route::post('customer/unbindStore', 'jxc.Customer/unbindStore');
    Route::post('customer/groups/assign', 'jxc.Customer/assignGroup');
    Route::get('customer/groups/detail', 'jxc.CustomerGroup/detail');
    Route::post('customer/groups/rename', 'jxc.CustomerGroup/rename');
    Route::post('customer/groups/delete', 'jxc.CustomerGroup/delete');
    Route::get('customer/groups', 'jxc.CustomerGroup/lists');
    Route::post('customer/groups', 'jxc.CustomerGroup/add');
    Route::post('customer/status', 'jxc.Customer/status');
    Route::post('customer/paymoney', 'jxc.Customer/paymoney');
    Route::get('customer/index', 'jxc.Customer/lists');
    Route::post('customer/add', 'jxc.Customer/add');
    Route::post('customer/edit', 'jxc.Customer/edit');
    Route::delete('customer/del', 'jxc.Customer/delete');
    Route::post('customer/del', 'jxc.Customer/delete');

    Route::get('order/details', 'jxc.SalesOrder/detail');
    Route::get('order/statistics', 'jxc.SalesOrder/statistics');
    Route::post('order/publish', 'jxc.SalesOrder/publish');
    Route::post('order/edit', 'jxc.SalesOrder/edit');
    Route::delete('order/remove', 'jxc.SalesOrder/remove');
    Route::post('order/remove', 'jxc.SalesOrder/remove');
    Route::get('order/lists', 'jxc.SalesOrder/lists');

    // === 进货单（供货单）===
    Route::get('supply/lists',      'jxc.SupplyOrder/lists');
    Route::post('supply/publish',   'jxc.SupplyOrder/publish');
    Route::post('supply/edit',      'jxc.SupplyOrder/edit');
    Route::delete('supply/remove',  'jxc.SupplyOrder/remove');
    Route::get('supply/details',    'jxc.SupplyOrder/detail');
    Route::get('supply/statistics', 'jxc.SupplyOrder/statistics');

    // === 销售退货单 ===
    Route::get('return/lists',      'jxc.SalesReturnOrder/lists');
    Route::post('return/publish',   'jxc.SalesReturnOrder/publish');
    Route::post('return/edit',      'jxc.SalesReturnOrder/edit');
    Route::delete('return/remove',  'jxc.SalesReturnOrder/remove');
    Route::get('return/details',    'jxc.SalesReturnOrder/detail');

    // === 订货单 ===
    Route::get('purchase/lists',              'jxc.PurchaseOrder/lists');
    Route::get('purchase/details',            'jxc.PurchaseOrder/detail');
    Route::post('purchase/publish',           'jxc.PurchaseOrder/add');
    Route::post('purchase/edit',              'jxc.PurchaseOrder/edit');
    Route::delete('purchase/remove',          'jxc.PurchaseOrder/remove');
    Route::post('purchase/confirm',           'jxc.PurchaseOrder/confirm');
    Route::post('purchase/cancel',            'jxc.PurchaseOrder/cancel');
    Route::post('purchase/convert-to-sales',  'jxc.PurchaseOrder/convertToSalesOrder');
    Route::post('purchase/parse-text',        'jxc.PurchaseOrder/parsePastedText');
    Route::get('purchase/statistics',         'jxc.PurchaseOrder/statistics');

    // === 审计日志 ===
    Route::get('audit/lists', 'jxc.Audit/lists');

    // === 店铺管理 ===
    Route::get('user/store/status', 'jxc.Store/status');
    Route::get('user/store',     'jxc.Store/detail');
    Route::get('user/store/current', 'jxc.Store/detail');
    Route::get('user/stores',    'jxc.Store/lists');
    Route::post('user/storeset', 'jxc.Store/setStore');
    Route::post('user/open',     'jxc.Store/createStore');
    Route::post('user/store/create', 'jxc.Store/createStore');
    Route::post('user/store/switch', 'jxc.Store/switchStore');
    Route::post('user/store/join',   'jxc.Store/join');
    Route::post('store/invite/accept', 'jxc.Store/join');
    Route::get('user/store/invite',  'jxc.Store/invite');
    Route::get('user/store/member-invite', 'jxc.Store/memberInvite');
    Route::post('user/store/member-invite/accept', 'jxc.Store/acceptMemberInvite');
    Route::get('user/store/hierarchy', 'jxc.Store/hierarchy');
    Route::get('user/store/hierarchy/children', 'jxc.Store/hierarchyChildren');
    Route::get('user/store/hierarchy/tree', 'jxc.Store/hierarchyTree');
    Route::get('user/store/hierarchy/invite/preview', 'jxc.Store/hierarchyInvitePreview');
    Route::post('user/store/hierarchy/invite', 'jxc.Store/createHierarchyInvite');
    Route::post('user/store/hierarchy/invite/accept', 'jxc.Store/acceptHierarchyInvite');
    Route::post('user/store/hierarchy/unbind', 'jxc.Store/unbindHierarchy');
})->middleware(\app\api\jxc\middleware\JxcLoginMiddleware::class);
