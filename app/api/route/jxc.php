<?php

use think\facade\Route;

Route::post('user/login', 'jxc.Auth/login');
Route::post('user/mnpLogin', 'login/mnpLogin');
// 小程序既有个人信息入口必须使用普通用户控制器及其 token 校验。
Route::get('user/info', 'User/info')
    ->middleware(\app\api\http\middleware\LoginMiddleware::class);

// 新用户开店/入店入口：精确白名单允许已登录但尚未加入店铺的用户访问。
Route::group('', function () {
    Route::get('jxc/auth/info', 'jxc.Auth/info');
    Route::post('user/logout', 'jxc.Auth/logout');
    Route::get('user/store/status', 'jxc.Store/status');
    Route::get('user/store/current', 'jxc.Store/detail');
    Route::get('user/stores',    'jxc.Store/lists');
    Route::post('user/open',     'jxc.Store/createStore');
    Route::post('user/store/create', 'jxc.Store/createStore');
    Route::post('user/store/join',   'jxc.Store/join');
    Route::post('store/invite/accept', 'jxc.Store/join');
    Route::post('user/store/member-invite/accept', 'jxc.Store/acceptMemberInvite');
})->middleware(\app\api\http\middleware\LoginMiddleware::class, 'enforce-onboarding');

// 店铺成员操作：继续要求当前店铺成员身份。
Route::group('', function () {
    Route::get('user/store',     'jxc.Store/detail');
    Route::post('user/storeset', 'jxc.Store/setStore');
    Route::post('user/store/switch', 'jxc.Store/switchStore');
    Route::get('user/store/member-invite', 'jxc.Store/memberInvite');
})->middleware(\app\api\http\middleware\LoginMiddleware::class, 'enforce');

// JXC 业务接口 —— 使用 JxcLoginMiddleware（双 Token 查询）
Route::group('', function () {
    // === 单位管理 ===
    Route::get('units/index', 'jxc.GoodsUnit/lists');
    Route::get('units/detail', 'jxc.GoodsUnit/detail');
    Route::post('units/add', 'jxc.GoodsUnit/add');
    Route::post('units/edit', 'jxc.GoodsUnit/edit');
    Route::delete('units/del', 'jxc.GoodsUnit/delete');
    Route::post('units/del', 'jxc.GoodsUnit/delete');
    Route::get('units/conversion/rules', 'jxc.GoodsUnit/conversionRules');
    Route::post('units/conversion/rules/save', 'jxc.GoodsUnit/saveConversionRules');
    Route::delete('units/conversion/rules/del', 'jxc.GoodsUnit/deleteConversionRule');
    Route::post('units/conversion/rules/del', 'jxc.GoodsUnit/deleteConversionRule');
    Route::get('units/conversion/resolve', 'jxc.GoodsUnit/resolveConversion');
    Route::get('goods/base-unit', 'jxc.GoodsUnit/goodsBaseUnit');

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
    Route::get('goods/categories', 'jxc.Goods/categories');
    Route::post('goods/categories/add', 'jxc.Goods/categoryAdd');
    Route::get('goods/recommendations', 'jxc.Goods/recommendations');
    Route::get('goods/cloud/index', 'jxc.CloudGoods/lists');
    Route::get('goods/cloud/detail', 'jxc.CloudGoods/detail');
    Route::post('goods/cloud/load', 'jxc.CloudGoods/load');
    Route::get('goods/skus', 'jxc.Goods/skus');
    Route::post('goods/skus/save', 'jxc.Goods/saveSkus');
    Route::post('goods/skus/status', 'jxc.Goods/skuStatus');
    Route::get('goods/supplier-matrix', 'jxc.Goods/supplierMatrix');
    Route::post('goods/supplier-matrix/save', 'jxc.Goods/saveSupplierMatrix');
    Route::get('goods/detail', 'jxc.Goods/detail');
    Route::get('goods/suppliers', 'jxc.Goods/suppliers');
    Route::get('goods/units-binding', 'jxc.Goods/unitsBinding');
    Route::post('goods/suppliers/save', 'jxc.Goods/saveSuppliers');
    Route::post('goods/add', 'jxc.Goods/add');
    Route::post('goods/edit', 'jxc.Goods/edit');
    Route::delete('goods/del', 'jxc.Goods/delete');
    Route::post('goods/del', 'jxc.Goods/delete');
    Route::post('goods/archive', 'jxc.Goods/archive');
    Route::post('goods/unarchive', 'jxc.Goods/unarchive');
    Route::get('goods/archived', 'jxc.Goods/archivedLists');

    // === 品质与规格管理 ===
    Route::get('goods/qualities', 'jxc.Goods/qualities');
    Route::post('goods/qualities/save', 'jxc.Goods/saveQualities');
    Route::get('goods/specifications', 'jxc.Goods/specifications');
    Route::post('goods/specifications/save', 'jxc.Goods/saveSpecifications');
    Route::post('goods/skus/generate', 'jxc.Goods/generateSkus');
    Route::get('goods/dimensions', 'jxc.Goods/dimensions');
    Route::post('goods/dimensions/save', 'jxc.Goods/saveDimension');
    Route::post('goods/dimensions/delete', 'jxc.Goods/deleteDimension');
    Route::get('goods/product-dimensions', 'jxc.Goods/productDimensions');
    Route::post('goods/product-dimensions/save', 'jxc.Goods/saveProductDimensions');

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

    // === 采购退货单 ===
    Route::get('purchase-return/lists',      'jxc.PurchaseReturnOrder/lists');
    Route::get('purchase-return/details',    'jxc.PurchaseReturnOrder/detail');
    Route::post('purchase-return/publish',   'jxc.PurchaseReturnOrder/publish');
    Route::post('purchase-return/edit',      'jxc.PurchaseReturnOrder/edit');
    Route::delete('purchase-return/remove',  'jxc.PurchaseReturnOrder/remove');

    // === 客户报货（唯一客户需求与库存预留入口）===
    Route::post('jxc/customer_report/recognize', 'jxc.CustomerReport/recognize');
    Route::post('jxc/customer_report/quick_create_goods', 'jxc.CustomerReport/quickCreateGoods');
    Route::get('jxc/customer_report/lists', 'jxc.CustomerReport/lists');
    Route::post('jxc/customer_report/submit', 'jxc.CustomerReport/submit');
    Route::get('jxc/customer_report/detail', 'jxc.CustomerReport/detail');
    Route::get('jxc/customer_report/availability', 'jxc.CustomerReport/availability');
    Route::post('jxc/customer_report/edit', 'jxc.CustomerReport/edit');
    Route::post('jxc/customer_report/retry', 'jxc.CustomerReport/retry');
    Route::post('jxc/customer_report/convert', 'jxc.CustomerReport/convert');
    Route::post('jxc/customer_report/cancel', 'jxc.CustomerReport/cancel');
    Route::post('jxc/customer_report/batch_start', 'jxc.CustomerReport/batchStart');
    Route::post('jxc/customer_report/batch_process', 'jxc.CustomerReport/batchProcess');
    Route::post('jxc/customer_report/batch_end', 'jxc.CustomerReport/batchEnd');
    Route::get('jxc/customer_report/batch_detail', 'jxc.CustomerReport/batchDetail');

    // === 任务调度、纸质工票与员工系统 ===
    Route::get('jxc/tasks/dashboard', 'jxc.FulfillmentTask/dashboard');
    Route::get('jxc/tasks/lists', 'jxc.FulfillmentTask/lists');
    Route::get('jxc/tasks/detail', 'jxc.FulfillmentTask/detail');
    Route::get('jxc/tasks/candidates', 'jxc.FulfillmentTask/candidates');
    Route::post('jxc/tasks/assign', 'jxc.FulfillmentTask/assign');
    Route::post('jxc/tasks/resolve', 'jxc.FulfillmentTask/resolve');
    Route::post('jxc/tasks/print_data', 'jxc.FulfillmentTask/printData');
    Route::post('jxc/tasks/print_result', 'jxc.FulfillmentTask/printResult');
    Route::post('jxc/tasks/recover', 'jxc.FulfillmentTask/recover');
    Route::post('jxc/tasks/bill', 'jxc.FulfillmentTask/bill');

    Route::get('jxc/workforce/permissions', 'jxc.Workforce/permissionCatalog');
    Route::get('jxc/workforce/me/permissions', 'jxc.Workforce/currentPermissions');
    Route::get('jxc/workforce/employees', 'jxc.Workforce/employees');
    Route::get('jxc/workforce/employee', 'jxc.Workforce/employee');
    Route::post('jxc/workforce/employee/save', 'jxc.Workforce/saveEmployee');
    Route::post('jxc/workforce/employee/status', 'jxc.Workforce/statusEmployee');
    Route::get('jxc/workforce/processes', 'jxc.Workforce/processes');
    Route::post('jxc/workforce/process/save', 'jxc.Workforce/saveProcess');
    Route::post('jxc/workforce/process/status', 'jxc.Workforce/statusProcess');
    Route::post('jxc/workforce/process/reorder', 'jxc.Workforce/reorderProcesses');
    Route::post('jxc/workforce/process/delete', 'jxc.Workforce/deleteProcess');

    // === 审计日志 ===
    Route::get('audit/lists', 'jxc.Audit/lists');

    // === 店铺管理 ===
    Route::get('user/store/invite',  'jxc.Store/invite');
    Route::get('user/store/hierarchy', 'jxc.Store/hierarchy');
    Route::get('user/store/hierarchy/children', 'jxc.Store/hierarchyChildren');
    Route::get('user/store/hierarchy/tree', 'jxc.Store/hierarchyTree');
    Route::get('user/store/hierarchy/invite/preview', 'jxc.Store/hierarchyInvitePreview');
    Route::post('user/store/hierarchy/invite', 'jxc.Store/createHierarchyInvite');
    Route::post('user/store/hierarchy/invite/accept', 'jxc.Store/acceptHierarchyInvite');
    Route::post('user/store/hierarchy/unbind', 'jxc.Store/unbindHierarchy');

    Route::get('return/statistics', 'jxc.SalesReturnOrder/statistics');
})->middleware(\app\api\jxc\middleware\JxcLoginMiddleware::class);
