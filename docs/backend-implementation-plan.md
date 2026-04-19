# BeiMi 进销存系统 —— 后端实施计划方案

> **文档版本**：v1.0  
> **目标项目**：BeiMi-PHP（ThinkPHP 8 + likeadmin 框架）  
> **实施依据**：基于前端项目 BeiMi-uniapp 反推后端需求  
> **文档定位**：面向后端开发团队，可直接执行的技术实施方案  
> **校正日期**：2026-04-19  
> **校正内容**：表前缀 la_ → lk_；列表响应格式已实际适配为 {data,total,page,pagesize}；标记模块实施状态

---

## 第一章：项目概述

### 1.1 背景与目标

**背景**

BeiMi-uniapp 前端项目已完成全部 UI 和业务逻辑开发，包含 11 个业务模块、75 个 API 接口的定义与调用。目前后端 BeiMi-PHP 项目仅有框架骨架和部分旧模块（用户订单、供应商订单），与前端定义的进销存业务接口存在严重不匹配。

**目标**

1. 以前端 `common/api/` 目录下的 API 调用定义为需求基准，逆向推导后端数据库结构、接口设计和代码实现
2. 在不修改现有 likeadmin 框架核心代码的前提下，新增完整的进销存业务模块
3. 实现前后端接口的无缝对接，支持多租户隔离

**核心目标接口**：75个，覆盖认证、客户、供应商、商品、单位、销售单、进货单、退货单、订货单、仓库、店铺 11 个模块。

### 1.2 技术约束

| 约束项 | 要求 |
|--------|------|
| 框架版本 | ThinkPHP 8.x，likeadmin 架构规范 |
| 数据库 | MySQL，表前缀 `lk_`，必须含 `tenant_id` 字段 |
| 分层规范 | Controller → Validate → Logic → Model（含 Lists） |
| 基类约束 | Controller 继承 `BaseAdminController`；Logic 继承 `BaseLogic`；Model 继承 `BaseModel` |
| 多租户 | `BaseModel` 已实现全局 `tenant_id` 作用域，新增模型自动继承隔离 |
| 鉴权方式 | Header `token`，由 `tenantapi/http/middleware/LoginMiddleware.php` 统一处理 |
| 响应格式 | `{code:1, msg:"成功", data:{...}}` / `{code:0, msg:"错误"}` |
| 列表格式 | `{code:1, data:{lists:[], count:N, page_no:N, page_size:N}}`（已实际适配为 `{data:[],total:N,page:N,pagesize:N}`） |
| 金额计算 | 使用 PHP `bcmath` 函数，保留2位小数 |
| 不可修改 | 现有框架核心文件、已有业务模块代码；允许按接入需要修改 `route/app.php` |

### 1.3 实施范围

**新增内容（除路由接入文件外，尽量不修改现有业务文件）：**

- 数据库：新建 11 张业务表
- `app/api/` 下：新建业务专属 Controller、Validate、Logic、Lists、Model
- `app/common/model/` 下：新建对应 Model 文件

**不在范围内：**

- 现有框架中间件修改
- 支付模块（已有独立实现）
- 文件上传（已有 UploadController）
- 权限菜单配置

---

## 第二章：前端功能模块需求分析

### 2.1 模块清单与 API 统计表

| 序号 | 模块名称 | API 数量 | 对应前端文件 | 路由前缀 |
|------|---------|---------|------------|--------|
| 1 | 认证模块 | 4 | `api/auth.js` | `/api/user` |
| 2 | 客户管理 | 18 | `api/customer.js` | `/api/customer` |
| 3 | 供应商管理 | 5 | `api/supplier.js` | `/api/supplier` |
| 4 | 商品管理 | 7 | `api/product.js` | `/api/goods` |
| 5 | 单位管理 | 4 | `api/unit.js` | `/api/units` |
| 6 | 销售单 | 6 | `api/sales.js` | `/api/order` |
| 7 | 进货单（供货单） | 6 | `api/supply.js` | `/api/supplier` |
| 8 | 退货单 | 5 | `api/sales-return.js` | `/api/return` |
| 9 | 订货单 | 10 | `api/purchase.js` | `/api/purchase` |
| 10 | 仓库管理 | 7 | `api/warehouse.js` | `/api/warehouse` |
| 11 | 店铺管理 | 3 | `api/store.js` | `/api/user` |
| **合计** | | **75** | | |

> **接口基线待确认项：**
> 1. `AUTH_API.USER_INFO` / `AUTH_API.LOGOUT` 当前在前端常量文件中仍为注释状态，建议联调前冻结为 `GET /api/user/info`、`POST /api/user/logout`
> 2. `PRODUCT_API.DETAIL` 当前在前端常量文件中仍为注释状态，建议联调前补齐为 `GET /api/goods/detail`
> 3. `SUPPLIER_API` 与 `SUPPLY_API` 当前在 `/api/supplier/details`、`/api/supplier/edit` 上存在路径冲突，不建议按现状直接开发；建议将进货单独立回 `/api/supply/*`

### 2.2 各模块核心业务流程

**认证流程**
```
用户打开APP → 检查本地token → 无效则跳转login页 
→ 手机号/微信登录 → 后端返回token+userInfo 
→ 存储至 store/modules/user.js → 后续请求携带 Header token
```

**客户管理流程**
```
客户列表（支持分组过滤/关键字搜索/禁用过滤）
├── 新建客户（基本信息+分组+是否为门店）
├── 编辑客户
├── 禁用/启用客户
├── 客户详情
│   ├── 基本信息
│   ├── 下属门店列表（parent_id关联）
│   ├── 绑定/解绑门店
│   ├── 销售记录（关联sales order）
│   └── 应收汇总（含下属门店聚合）
└── 分组管理（CRUD + 批量分配客户）
```

**订货单完整状态流程**
```
创建草稿(draft) → 发送(sent) → 客户确认收货(received) 
→ 配送(delivered) → 完成(completed)
                ↓（任意非completed状态）
              取消(cancelled)
特殊操作：
- 转销售单：sent/received状态 → 生成对应 sales order（from_purchase_order_id关联）
- 文本识别：粘贴商品文本 → 后端解析返回 goods[]
- 统计：按时间范围汇总金额、数量
```

**进销存单据流程**
```
进货单(JHD): 供应商 → 入库
销售单(XSD): 出货 → 客户 → 可关联订货单
退货单(THD): 客户退回 → 关联原销售单
订货单(DDH): 客户下单 → 可转销售单
```

### 2.3 前端已定义的业务常量和状态枚举

**单据类型注册表（`constants/orderTypes.js`）：**
```javascript
sales        = 'sales'        // 出货/销售单，前缀 XSD
supply       = 'supply'       // 进货/供货单，前缀 JHD
sales-return = 'sales-return' // 退货单，前缀 THD
purchase     = 'purchase'     // 订货单，前缀 DDH
```

**订货单状态枚举（`constants/purchaseStatus.js`）：**
```javascript
draft     = 'draft'     // 草稿
sent      = 'sent'      // 已发送
received  = 'received'  // 已收货
delivered = 'delivered' // 已配送
completed = 'completed' // 已完成
cancelled = 'cancelled' // 已取消
```

**数据库存储约定（整数映射）：**
```
draft=1, sent=2, received=3, delivered=4, completed=5, cancelled=6
```

---

## 第三章：数据库设计方案

### 3.1 新增数据表清单（完整建表SQL）

#### 3.1.1 客户表 `lk_customer`

```sql
CREATE TABLE `lk_customer` (
  `id`              int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '客户ID',
  `tenant_id`       int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `customer_name`   varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称',
  `contact`         varchar(50) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone`           varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `address`         varchar(255) NOT NULL DEFAULT '' COMMENT '地址',
  `remark`          varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `group_id`        int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分组ID',
  `parent_id`       int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父级客户ID（0=主客户，>0=门店）',
  `is_store`        tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否为门店（0=否，1=是）',
  `children_count`  int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '下属门店数量',
  `is_disabled`     tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否禁用（0=正常，1=禁用）',
  `order_receivable` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计应收金额（汇总）',
  `order_money`     decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计销售金额',
  `order_pay_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计已付金额',
  `create_time`     int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`     int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_customer_name` (`customer_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户表';
```

#### 3.1.2 客户分组表 `lk_customer_group`

```sql
CREATE TABLE `lk_customer_group` (
  `id`             int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '分组ID',
  `tenant_id`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `group_name`     varchar(100) NOT NULL DEFAULT '' COMMENT '分组名称',
  `customer_count` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户数量',
  `sort`           int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time`    int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`    int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户分组表';
```

#### 3.1.3 供应商表 `lk_vendor`（新增，区别于旧 lk_user_supplier）

> 说明：旧表 `lk_user_supplier` 结构复杂且与进销存新业务不完全兼容，建议新建专用表 `lk_vendor`。

```sql
CREATE TABLE `lk_vendor` (
  `id`            int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '供应商ID',
  `tenant_id`     int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `supplier_name` varchar(100) NOT NULL DEFAULT '' COMMENT '供应商名称',
  `contact`       varchar(50) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone`         varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `address`       varchar(255) NOT NULL DEFAULT '' COMMENT '地址',
  `remark`        varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `is_disabled`   tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否禁用',
  `order_money`   decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计进货金额',
  `create_time`   int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`   int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_supplier_name` (`supplier_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='供应商表（进销存业务专用）';
```

#### 3.1.4 商品单位表 `lk_goods_unit`

```sql
CREATE TABLE `lk_goods_unit` (
  `id`          int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '单位ID',
  `tenant_id`   int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name`        varchar(50) NOT NULL DEFAULT '' COMMENT '单位名称',
  `sort`        int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品单位表';
```

#### 3.1.5 仓库表 `lk_warehouse`

```sql
CREATE TABLE `lk_warehouse` (
  `id`          int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '仓库ID',
  `tenant_id`   int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name`        varchar(100) NOT NULL DEFAULT '' COMMENT '仓库名称',
  `address`     varchar(255) NOT NULL DEFAULT '' COMMENT '仓库地址',
  `is_enabled`  tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用（0=禁用，1=启用）',
  `sort`        int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='仓库表';
```

#### 3.1.6 商品表 `lk_goods`（进销存专用，区别于旧 lk_tenant_goods）

```sql
CREATE TABLE `lk_goods` (
  `id`           int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '商品ID',
  `tenant_id`    int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name`         varchar(200) NOT NULL DEFAULT '' COMMENT '商品名称',
  `product_code` varchar(100) NOT NULL DEFAULT '' COMMENT '商品编号',
  `units`        varchar(50) NOT NULL DEFAULT '' COMMENT '计量单位',
  `unit_id`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单位ID',
  `price`        decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '销售价格',
  `cost`         decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '成本价',
  `stock`        decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '库存数量',
  `category_id`  int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID',
  `is_disabled`  tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否停用',
  `create_time`  int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`  int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_name` (`name`),
  KEY `idx_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品表（进销存业务）';
```

#### 3.1.7 销售单表 `lk_sales_order`

```sql
CREATE TABLE `lk_sales_order` (
  `id`                     int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`              int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_sn`               varchar(64) NOT NULL DEFAULT '' COMMENT '单号（XSD前缀）',
  `customer_id`            int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户ID',
  `customer_name`          varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称（冗余）',
  `warehouse_id`           int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `order_money`            decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '订单金额',
  `order_pay_money`        decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '已付金额',
  `order_arrears_money`    decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '欠款金额',
  `datetimesingle`         int(11) NOT NULL DEFAULT 0 COMMENT '单据日期（Unix时间戳）',
  `from_purchase_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源订货单ID（0=非转换）',
  `remarks`                varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status`                 tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态(1=未付款,2=部分付款,3=已付款)',
  `admin_id`               int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `idempotent_key`         varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键，防重复提交',
  `create_time`            int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`            int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_sn` (`order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_datetimesingle` (`datetimesingle`),
  KEY `idx_from_purchase` (`from_purchase_order_id`),
  KEY `idx_tenant_idempotent` (`tenant_id`, `idempotent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售单表';
```

#### 3.1.8 进货单表 `lk_supply_order`

```sql
CREATE TABLE `lk_supply_order` (
  `id`                  int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`           int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_sn`            varchar(64) NOT NULL DEFAULT '' COMMENT '单号（JHD前缀）',
  `supplier_id`         int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `supplier_name`       varchar(100) NOT NULL DEFAULT '' COMMENT '供应商名称（冗余）',
  `warehouse_id`        int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `order_money`         decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '订单金额',
  `order_pay_money`     decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '已付金额',
  `order_arrears_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '欠款金额',
  `datetimesingle`      int(11) NOT NULL DEFAULT 0 COMMENT '单据日期（Unix时间戳）',
  `remarks`             varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status`              tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态(1=未付款,2=部分付款,3=已付款)',
  `admin_id`            int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `idempotent_key`      varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键，防重复提交',
  `create_time`         int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`         int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_sn` (`order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_datetimesingle` (`datetimesingle`),
  KEY `idx_tenant_idempotent` (`tenant_id`, `idempotent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='进货单表';
```

#### 3.1.9 退货单表 `lk_sales_return_order`

```sql
CREATE TABLE `lk_sales_return_order` (
  `id`                     int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`              int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_sn`               varchar(64) NOT NULL DEFAULT '' COMMENT '单号（THD前缀）',
  `customer_id`            int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户ID',
  `customer_name`          varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称（冗余）',
  `original_sales_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '原销售单ID',
  `original_order_sn`      varchar(64) NOT NULL DEFAULT '' COMMENT '原销售单号（冗余）',
  `warehouse_id`           int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `order_money`            decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '退货金额',
  `reason`                 varchar(500) NOT NULL DEFAULT '' COMMENT '退货原因',
  `datetimesingle`         int(11) NOT NULL DEFAULT 0 COMMENT '单据日期（Unix时间戳）',
  `remarks`                varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `admin_id`               int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `idempotent_key`         varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键，防重复提交',
  `create_time`            int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`            int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_sn` (`order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_original_sales_order_id` (`original_sales_order_id`),
  KEY `idx_tenant_idempotent` (`tenant_id`, `idempotent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退货单表';
```

#### 3.1.10 订货单表 `lk_purchase_order`

```sql
CREATE TABLE `lk_purchase_order` (
  `id`               int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_sn`         varchar(64) NOT NULL DEFAULT '' COMMENT '单号（DDH前缀）',
  `customer_id`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户ID',
  `customer_name`    varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称（冗余）',
  `warehouse_id`     int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `order_money`      decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '订单金额',
  `order_pay_money`  decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '已付金额',
  `datetimesingle`   int(11) NOT NULL DEFAULT 0 COMMENT '单据日期（Unix时间戳）',
  `predicted_date`   int(11) NOT NULL DEFAULT 0 COMMENT '预计交货日期（Unix时间戳）',
  `remarks`          varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status`           tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态(1=draft,2=sent,3=received,4=delivered,5=completed,6=cancelled)',
  `admin_id`         int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `idempotent_key`   varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键，防重复提交',
  `create_time`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_sn` (`order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_datetimesingle` (`datetimesingle`),
  KEY `idx_tenant_idempotent` (`tenant_id`, `idempotent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订货单表';
```

#### 3.1.11 单据明细通用表 `lk_order_goods`

```sql
CREATE TABLE `lk_order_goods` (
  `id`         int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_id`   int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单据ID',
  `order_type` varchar(30) NOT NULL DEFAULT '' COMMENT '单据类型(sales/supply/sales-return/purchase)',
  `goods_id`   int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `name`       varchar(200) NOT NULL DEFAULT '' COMMENT '商品名称（冗余）',
  `units`      varchar(50) NOT NULL DEFAULT '' COMMENT '单位（冗余）',
  `number`     decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '数量',
  `price`      decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '单价',
  `amount`     decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '小计（number × price）',
  `remark`     varchar(255) NOT NULL DEFAULT '' COMMENT '行备注',
  `sort`       int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_id_type` (`order_id`, `order_type`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_goods_id` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='单据商品明细通用表';
```

#### 3.1.12 库存流水表 `lk_stock_flow`

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int unsigned AI | 主键 |
| tenant_id | int unsigned | 租户ID |
| warehouse_id | int unsigned | 仓库ID |
| goods_id | int unsigned | 商品ID |
| flow_type | tinyint | 流水类型：1=入库 2=出库 |
| quantity | decimal(12,4) | 变动数量 |
| before_quantity | decimal(12,4) | 变动前库存 |
| after_quantity | decimal(12,4) | 变动后库存 |
| order_id | int unsigned | 关联单据ID |
| order_type | varchar(32) | 关联单据类型 |
| order_sn | varchar(64) | 关联单据编号 |
| create_time | int unsigned | 创建时间 |

#### 3.1.13 客户应收流水表 `lk_receivable_flow`

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int unsigned AI | 主键 |
| tenant_id | int unsigned | 租户ID |
| customer_id | int unsigned | 客户ID |
| flow_type | tinyint | 1=销售增加 2=收款减少 3=退货减少 |
| amount | decimal(12,2) | 变动金额 |
| before_amount | decimal(12,2) | 变动前应收 |
| after_amount | decimal(12,2) | 变动后应收 |
| order_id | int unsigned | 关联单据ID |
| order_type | varchar(32) | 关联单据类型 |
| order_sn | varchar(64) | 关联单据编号 |
| create_time | int unsigned | 创建时间 |

#### 3.1.14 供应商应付流水表 `lk_payable_flow`

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int unsigned AI | 主键 |
| tenant_id | int unsigned | 租户ID |
| supplier_id | int unsigned | 供应商ID |
| flow_type | tinyint | 1=进货增加 2=付款减少 |
| amount | decimal(12,2) | 变动金额 |
| before_amount | decimal(12,2) | 变动前应付 |
| after_amount | decimal(12,2) | 变动后应付 |
| order_id | int unsigned | 关联单据ID |
| order_type | varchar(32) | 关联单据类型 |
| order_sn | varchar(64) | 关联单据编号 |
| create_time | int unsigned | 创建时间 |

### 3.2 表关系 ER 图（文字描述）

```
lk_customer_group (1) ──── (N) lk_customer
      [id]                        [group_id]

lk_customer (1) ──── (N) lk_customer    [自关联：主客户-门店]
      [id]                   [parent_id]

lk_customer (1) ──── (N) lk_sales_order
      [id]                   [customer_id]

lk_customer (1) ──── (N) lk_purchase_order
      [id]                    [customer_id]

lk_customer (1) ──── (N) lk_sales_return_order
      [id]                      [customer_id]

lk_vendor (1) ──── (N) lk_supply_order
    [id]                  [supplier_id]

lk_purchase_order (1) ──── (0,1) lk_sales_order
        [id]                        [from_purchase_order_id]

lk_sales_order (1) ──── (N) lk_sales_return_order
        [id]                       [original_sales_order_id]

lk_goods (1) ──── (N) lk_order_goods
    [id]                  [goods_id]

lk_warehouse (1) ──── (N) lk_sales_order / lk_supply_order / lk_purchase_order
        [id]                    [warehouse_id]

lk_goods_unit (1) ──── (N) lk_goods
      [id]                    [unit_id]

lk_sales_order / lk_supply_order / lk_sales_return_order / lk_purchase_order
        (1) ──── (N) lk_order_goods [通过 order_id + order_type 关联]
```

### 3.3 索引设计

**高频查询场景索引策略：**

| 表 | 查询场景 | 建议索引 |
|----|---------|---------|
| `lk_customer` | 按分组过滤 | `(tenant_id, group_id)` |
| `lk_customer` | 按关键字搜索名称 | `FULLTEXT(customer_name)` 或 `(customer_name)` |
| `lk_purchase_order` | 按状态+日期筛选 | `(tenant_id, status, datetimesingle)` |
| `lk_sales_order` | 按客户查订单 | `(tenant_id, customer_id, datetimesingle)` |
| `lk_order_goods` | 查某单据所有明细 | `(order_id, order_type)` |
| `lk_order_goods` | 按商品统计 | `(tenant_id, goods_id)` |

### 3.4 与现有表的关联关系

| 新表 | 关联现有表 | 关联字段 | 说明 |
|------|----------|---------|------|
| 所有新表 | `lk_tenant` | `tenant_id` | 多租户隔离 |
| 所有单据表 | `lk_tenant_admin` | `admin_id` | 记录操作人 |
| `lk_goods` | `lk_tenant_goodscat` | `category_id` | 复用现有商品分类（可选） |

---

## 第四章：后端 API 接口设计

### 4.1 接口路由规划

**关键发现**：前端请求路径格式为 `/api/{module}/{action}`，而现有后端 tenantapi 自动路由格式为 `/api/tenantapi/{controller}/{action}`。需要通过 **路由配置** 或 **新建 `app/api` 子模块** 来兼容。

**推荐方案**：在现有 `app/api/` 目录下新建进销存业务子目录，利用 ThinkPHP 8 的分组路由在 `route/app.php` 中注册。

> **说明**：以下路由示例按“建议冻结后的标准路由”编写，而不是机械复刻前端当前常量。原因是前端常量中存在认证接口未补齐、商品详情未补齐、供应商管理与进货单路径冲突三类问题。建议先统一前端常量，再按下列路由冻结实施。

```php
// route/app.php 新增路由组
Route::group('api', function () {
    // 认证（白名单）
    Route::post('user/login',   'api/jxc.Login/login');
    Route::post('user/third',   'api/jxc.Login/third');
    Route::post('user/logout',  'api/jxc.Login/logout');
    Route::get ('user/info',    'api/jxc.Login/userInfo');

    // 客户管理
    Route::get ('customer/index',          'api/jxc.Customer/lists');
    Route::post('customer/add',            'api/jxc.Customer/add');
    Route::post('customer/edit',           'api/jxc.Customer/edit');
    Route::post('customer/del',            'api/jxc.Customer/delete');
    Route::get ('customer/detail',         'api/jxc.Customer/detail');
    Route::get ('customer/children',       'api/jxc.Customer/children');
    Route::post('customer/bindStore',      'api/jxc.Customer/bindStore');
    Route::post('customer/unbindStore',    'api/jxc.Customer/unbindStore');
    Route::get ('customer/groups',         'api/jxc.CustomerGroup/lists');
    Route::post('customer/groups',         'api/jxc.CustomerGroup/add');
    Route::post('customer/groups/rename',  'api/jxc.CustomerGroup/edit');
    Route::post('customer/groups/delete',  'api/jxc.CustomerGroup/delete');
    Route::post('customer/groups/assign',  'api/jxc.Customer/assignGroup');
    Route::get ('customer/summary',        'api/jxc.Customer/summary');
    Route::get ('customer/receivableSummary', 'api/jxc.Customer/receivableSummary');
    Route::get ('customer/salesHistory',   'api/jxc.Customer/salesRecords');
    Route::post('customer/paymoney',       'api/jxc.Customer/paymoney');
    Route::post('customer/status',         'api/jxc.Customer/setStatus');

    // 供应商管理
    Route::get ('supplier/index',  'api/jxc.Supplier/lists');
    Route::post('supplier/add',    'api/jxc.Supplier/add');
    Route::post('supplier/edit',   'api/jxc.Supplier/edit');
    Route::post('supplier/del',    'api/jxc.Supplier/delete');
    Route::get ('supplier/details','api/jxc.Supplier/detail');

    // 商品管理
    Route::get ('goods/index',    'api/jxc.Goods/lists');
    Route::post('goods/add',      'api/jxc.Goods/add');
    Route::post('goods/edit',     'api/jxc.Goods/edit');
    Route::post('goods/del',      'api/jxc.Goods/delete');
    Route::get ('goods/detail',   'api/jxc.Goods/detail');

    // 单位管理
    Route::get ('units/index',  'api/jxc.GoodsUnit/lists');
    Route::post('units/add',    'api/jxc.GoodsUnit/add');
    Route::post('units/edit',   'api/jxc.GoodsUnit/edit');
    Route::post('units/del',    'api/jxc.GoodsUnit/delete');

    // 销售单
    Route::get ('order/lists',      'api/jxc.SalesOrder/lists');
    Route::post('order/publish',    'api/jxc.SalesOrder/add');
    Route::post('order/edit',       'api/jxc.SalesOrder/edit');
    Route::post('order/remove',     'api/jxc.SalesOrder/delete');
    Route::get ('order/details',    'api/jxc.SalesOrder/detail');
    Route::get ('order/statistics', 'api/jxc.SalesOrder/statistics');

    // 进货单（推荐冻结为独立 /supply 前缀，避免与 supplier 管理冲突）
    Route::get ('supply/lists',      'api/jxc.SupplyOrder/lists');
    Route::post('supply/publish',    'api/jxc.SupplyOrder/add');
    Route::post('supply/edit',       'api/jxc.SupplyOrder/edit');
    Route::post('supply/remove',     'api/jxc.SupplyOrder/delete');
    Route::get ('supply/details',    'api/jxc.SupplyOrder/detail');
    Route::get ('supply/statistics', 'api/jxc.SupplyOrder/statistics');

    // 退货单
    Route::get ('return/lists',  'api/jxc.SalesReturnOrder/lists');
    Route::post('return/publish','api/jxc.SalesReturnOrder/add');
    Route::post('return/edit',   'api/jxc.SalesReturnOrder/edit');
    Route::post('return/remove', 'api/jxc.SalesReturnOrder/delete');
    Route::get ('return/details','api/jxc.SalesReturnOrder/detail');

    // 订货单
    Route::get ('purchase/lists',              'api/jxc.PurchaseOrder/lists');
    Route::post('purchase/publish',            'api/jxc.PurchaseOrder/add');
    Route::post('purchase/edit',               'api/jxc.PurchaseOrder/edit');
    Route::post('purchase/remove',             'api/jxc.PurchaseOrder/delete');
    Route::get ('purchase/details',            'api/jxc.PurchaseOrder/detail');
    Route::post('purchase/confirm',            'api/jxc.PurchaseOrder/confirm');
    Route::post('purchase/cancel',             'api/jxc.PurchaseOrder/cancel');
    Route::post('purchase/convert-to-sales',   'api/jxc.PurchaseOrder/convertToSalesOrder');
    Route::post('purchase/parse-text',         'api/jxc.PurchaseOrder/parsePastedText');
    Route::get ('purchase/statistics',         'api/jxc.PurchaseOrder/statistics');

    // 仓库管理
    Route::get ('warehouse/index',   'api/jxc.Warehouse/lists');
    Route::post('warehouse/add',     'api/jxc.Warehouse/add');
    Route::post('warehouse/edit',    'api/jxc.Warehouse/edit');
    Route::post('warehouse/del',     'api/jxc.Warehouse/delete');
    Route::get ('warehouse/detail',  'api/jxc.Warehouse/detail');
    Route::post('warehouse/enable',  'api/jxc.Warehouse/enable');
    Route::post('warehouse/disable', 'api/jxc.Warehouse/disable');

    // 店铺管理
    Route::get ('user/store',         'api/jxc.Store/getStoreInfo');
    Route::post('user/storeset',      'api/jxc.Store/setStore');
    Route::post('user/open',          'api/jxc.Store/createStore');
})->middleware([\app\api\jxc\middleware\JxcLoginMiddleware::class]);
```

### 4.2 各模块接口详细设计

#### 4.2.1 认证模块

**POST /api/user/login**（免token）
```json
请求：{ "account": "string", "password": "string", "tenant_sn": "string" }
响应：{ "code": 1, "data": { "token": "xxx", "user_info": { "id": 1, "name": "xxx" } } }
```

**认证补充说明**

- 当前前端常量中 `AUTH_API.USER_INFO`、`AUTH_API.LOGOUT` 尚未定义
- 建议联调前冻结为 `GET /api/user/info`、`POST /api/user/logout`

#### 4.2.2 客户管理模块

**GET /api/customer/index**（分页+搜索）
```
请求参数：page, pagesize, keyword, group_id, is_disabled, parent_id
响应：{ "code": 1, "data": { "data": [...], "total": 100 } }
```
> **注意**：前端期望响应格式为 `{ data: [], total: N }`，需在 Controller 中封装转换。

**客户对象字段：**
```json
{
  "id": 1, "customer_name": "xxx", "contact": "xxx", "phone": "xxx",
  "address": "xxx", "remark": "xxx", "group_id": 1, "parent_id": 0,
  "is_store": 0, "children_count": 3, "is_disabled": 0,
  "order_receivable": "1200.00"
}
```

**POST /api/customer/paymoney**
```json
请求：{ "customer_id": 1, "money": "500.00", "remark": "xxx" }
响应：{ "code": 1, "msg": "付款成功" }
```

#### 4.2.3 订货单模块（最复杂）

**POST /api/purchase/publish**
```json
请求：{
  "customer_id": 1,
  "warehouse_id": 1,
  "datetimesingle": 1713340800,
  "predicted_date": 1713427200,
  "remarks": "xxx",
  "goods": [
    { "goods_id": 1, "name": "商品A", "number": 10, "price": 5.50, "amount": 55.00, "units": "箱", "remark": "" }
  ]
}
响应：{ "code": 1, "msg": "添加成功" }
```

**POST /api/purchase/confirm**（状态推进，sent→received 等）
```json
请求：{ "id": 1, "action": "sent" }
// action: sent | received | delivered | completed
响应：{ "code": 1, "msg": "操作成功" }
```

**POST /api/purchase/cancel**
```json
请求：{ "id": 1, "reason": "xxx" }
响应：{ "code": 1, "msg": "取消成功" }
```

**POST /api/purchase/convert-to-sales**
```json
请求：{ "id": 1 }
响应：{ "code": 1, "data": { "sales_order_id": 88, "order_sn": "XSD20260417001" } }
```

**POST /api/purchase/parse-text**
```json
请求：{ "text": "商品A 10箱 5.5\n商品B 20个 3.0" }
响应：{ "code": 1, "data": { "goods": [{"name":"商品A","number":10,"units":"箱","price":5.5,"amount":55},...] } }
```

### 4.3 接口鉴权策略

| 接口 | 鉴权方式 |
|------|---------|
| `login/index`, `login/wechat`, `login/register` | **免鉴权**，加入白名单 |
| 所有其他接口 | 必须携带 `Header: token`，通过 `JxcLoginMiddleware` 验证 |

**JxcLoginMiddleware 实现要点：**
- 从 `Header token` 获取令牌
- 从 Redis/缓存中查找 token 对应的 `tenant_id` + `admin_id`
- 将信息写入 `$request->adminInfo`，供 Controller 使用
- token 续期：临近过期时自动延长

### 4.4 分页与搜索规范

**请求参数约定：**
```
page      (int)    当前页，默认 1
pagesize  (int)    每页条数，默认 15，最大 100
keyword   (string) 关键字搜索
```

**后端响应（框架默认格式）：**
```json
{ "code": 1, "data": { "lists": [], "count": 100, "page_no": 1, "page_size": 15 } }
```

> **注意**：以上为框架原始格式，Controller 层已统一适配为前端期望格式（见下方）。

**实际响应格式（已适配）：**
```json
{ "code": 1, "data": { "data": [], "total": 100, "page": 1, "pagesize": 15 } }
```

**前端期望格式（`request.js` 中解析）：**
```json
{ "code": 1, "data": { "data": [], "total": 100 } }
```

**适配方案**：Controller 层统一完成两类适配：

1. 请求参数：将前端 `page/pagesize` 映射为框架实际读取的 `page_no/page_size`
2. 响应参数：将框架返回的 `lists/count` 转换为前端期望的 `data/total`

---

## 第五章：后端代码生成方案

### 5.1 需要创建的文件完整清单

```
app/api/jxc/
├── middleware/
│   └── JxcLoginMiddleware.php
├── controller/
│   ├── BaseJxcController.php
│   ├── LoginController.php
│   ├── CustomerController.php
│   ├── CustomerGroupController.php
│   ├── SupplierController.php
│   ├── GoodsController.php
│   ├── GoodsUnitController.php
│   ├── WarehouseController.php
│   ├── SalesOrderController.php
│   ├── SupplyOrderController.php
│   ├── SalesReturnOrderController.php
│   ├── PurchaseOrderController.php
│   └── StoreController.php
├── validate/
│   ├── CustomerValidate.php
│   ├── CustomerGroupValidate.php
│   ├── SupplierValidate.php
│   ├── GoodsValidate.php
│   ├── GoodsUnitValidate.php
│   ├── WarehouseValidate.php
│   ├── SalesOrderValidate.php
│   ├── SupplyOrderValidate.php
│   ├── SalesReturnOrderValidate.php
│   ├── PurchaseOrderValidate.php
│   └── StoreValidate.php
├── logic/
│   ├── LoginLogic.php
│   ├── CustomerLogic.php
│   ├── CustomerGroupLogic.php
│   ├── SupplierLogic.php
│   ├── GoodsLogic.php
│   ├── GoodsUnitLogic.php
│   ├── WarehouseLogic.php
│   ├── SalesOrderLogic.php
│   ├── SupplyOrderLogic.php
│   ├── SalesReturnOrderLogic.php
│   ├── PurchaseOrderLogic.php
│   └── StoreLogic.php
└── lists/
    ├── CustomerLists.php
    ├── CustomerGroupLists.php
    ├── SupplierLists.php
    ├── GoodsLists.php
    ├── GoodsUnitLists.php
    ├── WarehouseLists.php
    ├── SalesOrderLists.php
    ├── SupplyOrderLists.php
    ├── SalesReturnOrderLists.php
    └── PurchaseOrderLists.php

app/common/model/jxc/
├── Customer.php
├── CustomerGroup.php
├── Vendor.php
├── Goods.php
├── GoodsUnit.php
├── Warehouse.php
├── SalesOrder.php
├── SupplyOrder.php
├── SalesReturnOrder.php
├── PurchaseOrder.php
└── OrderGoods.php

route/app.php（修改现有文件，新增路由组）
```

**文件总计：约 58 个新文件，1 个文件修改（route/app.php）**

### 5.2 各层代码模板说明

#### 5.2.1 Controller 层模板

继承 `BaseJxcController`（参考现有 `BaseAdminController`），核心职责：接收请求、调用 Validate 验证、调用 Logic 处理、返回响应。

```php
<?php
namespace app\api\jxc\controller;

use app\tenantapi\controller\BaseAdminController;

class BaseJxcController extends BaseAdminController
{
    // 重写分页响应方法，适配前端期望格式
    protected function paginate($lists, $total)
    {
        return $this->success('获取成功', [
            'data'  => $lists,
            'total' => $total,
        ]);
    }
}
```

**标准 Controller 方法结构：**
```php
public function lists()
{
    // 使用Lists类，但返回前端兼容格式
    $lists = (new XxxLists())->listsData();
    return $this->paginate($lists['data'], $lists['total']);
}

public function add()
{
    $params = (new XxxValidate())->post()->goCheck('add');
    $result = XxxLogic::add($params);
    if (true === $result) {
        return $this->success('添加成功', [], 1, 1);
    }
    return $this->fail(XxxLogic::getError());
}
```

#### 5.2.2 Validate 层模板

```php
<?php
namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class XxxValidate extends BaseValidate
{
    protected $rule = [
        'id'      => 'require|integer',
        'name'    => 'require|max:100',
        // ...
    ];

    protected $field = [
        'id'   => 'ID',
        'name' => '名称',
    ];

    public function sceneAdd()   { return $this->only(['name', ...]); }
    public function sceneEdit()  { return $this->only(['id', 'name', ...]); }
    public function sceneDelete(){ return $this->only(['id']); }
    public function sceneDetail(){ return $this->only(['id']); }
}
```

#### 5.2.3 Logic 层模板

```php
<?php
namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Xxx;
use think\facade\Db;

class XxxLogic extends BaseLogic
{
    public static function add(array $params): bool
    {
        Db::startTrans();
        try {
            Xxx::create([
                'name'       => $params['name'],
                // ... 其他字段（tenant_id 由 BaseModel onBeforeInsert 自动写入）
                'create_time' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function edit(array $params): bool
    {
        Db::startTrans();
        try {
            Xxx::where('id', $params['id'])->update([
                'name'        => $params['name'],
                'update_time' => time(),
            ]);
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function delete(array $params): bool
    {
        return Xxx::destroy($params['id']);
    }

    public static function detail(array $params): array
    {
        return Xxx::findOrEmpty($params['id'])->toArray();
    }
}
```

#### 5.2.4 Lists 层模板

```php
<?php
namespace app\api\jxc\lists;

use app\tenantapi\lists\BaseAdminDataLists;
use app\common\lists\ListsSearchInterface;
use app\common\model\jxc\Xxx;

class XxxLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '%like%' => ['name'],   // 模糊搜索
            '='      => ['status'], // 精确匹配
        ];
    }

    public function lists(): array
    {
        return Xxx::where($this->searchWhere)
            ->field(['id', 'name', 'create_time'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
    }

    public function count(): int
    {
        return Xxx::where($this->searchWhere)->count();
    }
}
```

#### 5.2.5 Model 层模板

```php
<?php
namespace app\common\model\jxc;

use app\common\model\BaseModel;

class Xxx extends BaseModel
{
    protected $name = 'xxx'; // 对应表名 lk_xxx（去掉前缀）
    protected $pk   = 'id';

    // 搜索器：按名称模糊搜索
    public function searchNameAttr($query, $value, $data)
    {
        if ($value) {
            $query->where('name', 'like', '%' . $value . '%');
        }
    }

    // 关联关系
    public function customer()
    {
        return $this->hasOne(Customer::class, 'id', 'customer_id')
                    ->field(['id', 'customer_name']);
    }

    // 访问器：时间戳格式化
    public function getCreateTimeAttr($value)
    {
        return $value ? date('Y-m-d H:i:s', $value) : '';
    }
}
```

### 5.3 模块间依赖关系和创建顺序

```
第1轮（无依赖）：
  GoodsUnit（单位）
  Warehouse（仓库）
  CustomerGroup（客户分组）

第2轮（依赖第1轮）：
  Vendor（供应商）
  Customer（依赖 CustomerGroup）
  Goods（依赖 GoodsUnit）

第3轮（依赖第2轮）：
  SalesOrder（依赖 Customer、Warehouse、Goods）
  SupplyOrder（依赖 Vendor、Warehouse、Goods）
  SalesReturnOrder（依赖 Customer、SalesOrder、Goods）

第4轮（依赖第3轮）：
  PurchaseOrder（依赖 Customer、Warehouse、Goods；转换依赖 SalesOrder）
```

### 5.4 完整代码示例：订货单（Purchase Order）模块

#### Model: `app/common/model/jxc/PurchaseOrder.php`

```php
<?php
namespace app\common\model\jxc;

use app\common\model\BaseModel;

class PurchaseOrder extends BaseModel
{
    protected $name = 'purchase_order';
    protected $pk   = 'id';

    // 状态常量
    const STATUS_DRAFT     = 1;
    const STATUS_SENT      = 2;
    const STATUS_RECEIVED  = 3;
    const STATUS_DELIVERED = 4;
    const STATUS_COMPLETED = 5;
    const STATUS_CANCELLED = 6;

    // 状态到前端字符串映射
    public static $statusMap = [
        1 => 'draft',
        2 => 'sent',
        3 => 'received',
        4 => 'delivered',
        5 => 'completed',
        6 => 'cancelled',
    ];

    // 前端字符串到状态映射
    public static $statusReverseMap = [
        'draft'     => 1,
        'sent'      => 2,
        'received'  => 3,
        'delivered' => 4,
        'completed' => 5,
        'cancelled' => 6,
    ];

    // 合法的状态推进映射（当前状态 => 可推进到的状态）
    public static $statusTransitions = [
        1 => [2],       // draft → sent
        2 => [3, 4],    // sent → received | delivered
        3 => [4],       // received → delivered
        4 => [5],       // delivered → completed
    ];

    // 访问器：状态转字符串
    public function getStatusTextAttr($value, $data)
    {
        return self::$statusMap[$data['status']] ?? 'draft';
    }

    // 搜索器
    public function searchCustomerIdAttr($query, $value, $data)
    {
        if ($value) $query->where('customer_id', $value);
    }

    public function searchStatusAttr($query, $value, $data)
    {
        if ($value !== '' && $value !== null) {
            // 支持前端传字符串状态
            $intStatus = is_numeric($value) ? (int)$value
                : (self::$statusReverseMap[$value] ?? null);
            if ($intStatus) $query->where('status', $intStatus);
        }
    }

    public function searchKeywordAttr($query, $value, $data)
    {
        if ($value) {
            $query->where('order_sn|customer_name', 'like', '%' . $value . '%');
        }
    }

    // 关联明细
    public function goods()
    {
        return $this->hasMany(OrderGoods::class, 'order_id', 'id')
                    ->where('order_type', 'purchase');
    }

    // 关联客户
    public function customer()
    {
        return $this->hasOne(Customer::class, 'id', 'customer_id')
                    ->field(['id', 'customer_name', 'phone']);
    }
}
```

#### Logic: `app/api/jxc/logic/PurchaseOrderLogic.php`

```php
<?php
namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\PurchaseOrder;
use app\common\model\jxc\SalesOrder;
use app\common\model\jxc\Customer;
use app\common\model\jxc\Goods;
use app\common\model\jxc\OrderGoods;
use think\facade\Db;

class PurchaseOrderLogic extends BaseLogic
{
    /**
     * 生成订货单号：DDH + YmdHis + 6位随机数
     */
    private static function generateOrderSn(): string
    {
        return 'DDH' . date('YmdHis') . rand(100000, 999999);
    }

    /**
     * 添加订货单
     */
    public static function add(array $params): bool
    {
        // 验证客户存在
        $customer = Customer::findOrEmpty($params['customer_id'])->toArray();
        if (!$customer) {
            self::setError('客户不存在');
            return false;
        }

        // 验证商品并计算金额
        $goodsData = self::processGoods($params['goods'] ?? []);
        if ($goodsData === false) return false;

        Db::startTrans();
        try {
            $order = PurchaseOrder::create([
                'order_sn'       => self::generateOrderSn(),
                'customer_id'    => $params['customer_id'],
                'customer_name'  => $customer['customer_name'],
                'warehouse_id'   => $params['warehouse_id'] ?? 0,
                'order_money'    => $goodsData['total_amount'],
                'order_pay_money'=> 0,
                'datetimesingle' => $params['datetimesingle'] ?? time(),
                'predicted_date' => $params['predicted_date'] ?? 0,
                'remarks'        => $params['remarks'] ?? '',
                'status'         => PurchaseOrder::STATUS_DRAFT,
                'admin_id'       => $params['admin_id'] ?? 0,
                'create_time'    => time(),
                'update_time'    => time(),
            ]);

            // 保存明细
            $orderId = $order->id;
            self::saveOrderGoods($orderId, 'purchase', $goodsData['goods_list']);

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * 状态推进（sent/received/delivered/completed）
     */
    public static function confirm(array $params): bool
    {
        $order = PurchaseOrder::findOrEmpty($params['id'])->toArray();
        if (!$order) {
            self::setError('订货单不存在');
            return false;
        }

        $actionMap = PurchaseOrder::$statusReverseMap;
        $targetStatus = $actionMap[$params['action']] ?? null;
        if (!$targetStatus) {
            self::setError('无效的操作');
            return false;
        }

        $currentStatus = $order['status'];
        $allowedTransitions = PurchaseOrder::$statusTransitions[$currentStatus] ?? [];
        if (!in_array($targetStatus, $allowedTransitions)) {
            self::setError('当前状态不允许此操作');
            return false;
        }

        PurchaseOrder::where('id', $params['id'])->update([
            'status'      => $targetStatus,
            'update_time' => time(),
        ]);
        return true;
    }

    /**
     * 取消订货单
     */
    public static function cancel(array $params): bool
    {
        $order = PurchaseOrder::findOrEmpty($params['id'])->toArray();
        if (!$order) {
            self::setError('订货单不存在');
            return false;
        }
        if ($order['status'] == PurchaseOrder::STATUS_COMPLETED) {
            self::setError('已完成的订货单不能取消');
            return false;
        }
        if ($order['status'] == PurchaseOrder::STATUS_CANCELLED) {
            self::setError('订货单已取消');
            return false;
        }

        PurchaseOrder::where('id', $params['id'])->update([
            'status'      => PurchaseOrder::STATUS_CANCELLED,
            'update_time' => time(),
        ]);
        return true;
    }

    /**
     * 转换为销售单
     */
    public static function convertToSalesOrder(array $params): array
    {
        $order = PurchaseOrder::where('id', $params['id'])
                              ->with(['goods'])
                              ->find();
        if (!$order) {
            self::setError('订货单不存在');
            return [];
        }

        $allowedStatuses = [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_RECEIVED];
        if (!in_array($order['status'], $allowedStatuses)) {
            self::setError('只有已发送或已收货状态的订货单才能转为销售单');
            return [];
        }

        Db::startTrans();
        try {
            $salesOrder = SalesOrder::create([
                'order_sn'               => 'XSD' . date('YmdHis') . rand(100000, 999999),
                'customer_id'            => $order['customer_id'],
                'customer_name'          => $order['customer_name'],
                'warehouse_id'           => $order['warehouse_id'],
                'order_money'            => $order['order_money'],
                'order_pay_money'        => 0,
                'order_arrears_money'    => $order['order_money'],
                'datetimesingle'         => time(),
                'from_purchase_order_id' => $order['id'],
                'remarks'                => '由订货单 ' . $order['order_sn'] . ' 转换',
                'status'                 => 1,
                'create_time'            => time(),
                'update_time'            => time(),
            ]);

            // 复制明细
            $goodsList = $order['goods']->toArray();
            foreach ($goodsList as &$item) {
                unset($item['id']);
                $item['order_id']   = $salesOrder->id;
                $item['order_type'] = 'sales';
                $item['create_time']= time();
            }
            (new OrderGoods())->saveAll($goodsList);

            Db::commit();
            return ['sales_order_id' => $salesOrder->id, 'order_sn' => $salesOrder['order_sn']];
        } catch (\Exception $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return [];
        }
    }

    /**
     * 解析粘贴文本（简单格式：每行 "商品名 数量单位 价格"）
     */
    public static function parsePastedText(array $params): array
    {
        $text  = trim($params['text'] ?? '');
        $lines = preg_split('/\r?\n/', $text);
        $goods = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 匹配格式：商品名 数量[单位] 价格
            // 例如："商品A 10箱 5.5" 或 "商品A 10 5.5"
            if (preg_match('/^(.+?)\s+(\d+\.?\d*)\s*([^\d\s]*)\s+(\d+\.?\d*)$/', $line, $m)) {
                $name   = trim($m[1]);
                $number = floatval($m[2]);
                $units  = trim($m[3]) ?: '个';
                $price  = floatval($m[4]);
                $amount = round($number * $price, 2);

                // 尝试匹配现有商品
                $existGoods = Goods::where('name', $name)->find();

                $goods[] = [
                    'goods_id' => $existGoods ? $existGoods->id : 0,
                    'name'     => $name,
                    'number'   => $number,
                    'units'    => $units,
                    'price'    => $price,
                    'amount'   => $amount,
                    'remark'   => '',
                ];
            }
        }

        return ['goods' => $goods];
    }

    /**
     * 统计数据
     */
    public static function statistics(array $params): array
    {
        $where = [];
        if (!empty($params['start_date'])) {
            $where[] = ['datetimesingle', '>=', strtotime($params['start_date'])];
        }
        if (!empty($params['end_date'])) {
            $where[] = ['datetimesingle', '<=', strtotime($params['end_date']) + 86399];
        }

        return [
            'total_count'  => PurchaseOrder::where($where)->count(),
            'total_money'  => PurchaseOrder::where($where)->sum('order_money'),
            'draft_count'  => PurchaseOrder::where($where)->where('status', 1)->count(),
            'sent_count'   => PurchaseOrder::where($where)->where('status', 2)->count(),
            'completed_count' => PurchaseOrder::where($where)->where('status', 5)->count(),
            'cancelled_count' => PurchaseOrder::where($where)->where('status', 6)->count(),
        ];
    }

    /**
     * 处理商品数据，验证并计算金额
     */
    private static function processGoods(array $goods): array|false
    {
        if (empty($goods)) {
            self::setError('请至少添加一个商品');
            return false;
        }

        $totalAmount = '0';
        $goodsList   = [];

        foreach ($goods as $item) {
            $amount = bcmul(strval($item['number'] ?? 0), strval($item['price'] ?? 0), 2);
            $totalAmount = bcadd($totalAmount, $amount, 2);

            $goodsList[] = [
                'goods_id' => $item['goods_id'] ?? 0,
                'name'     => $item['name'] ?? '',
                'units'    => $item['units'] ?? '',
                'number'   => $item['number'] ?? 0,
                'price'    => $item['price'] ?? 0,
                'amount'   => $amount,
                'remark'   => $item['remark'] ?? '',
            ];
        }

        return ['total_amount' => $totalAmount, 'goods_list' => $goodsList];
    }

    /**
     * 保存单据明细
     */
    private static function saveOrderGoods(int $orderId, string $orderType, array $goodsList): void
    {
        foreach ($goodsList as &$item) {
            $item['order_id']    = $orderId;
            $item['order_type']  = $orderType;
            $item['create_time'] = time();
        }
        (new OrderGoods())->saveAll($goodsList);
    }
}
```

#### Controller: `app/api/jxc/controller/PurchaseOrderController.php`

```php
<?php
namespace app\api\jxc\controller;

use app\api\jxc\lists\PurchaseOrderLists;
use app\api\jxc\logic\PurchaseOrderLogic;
use app\api\jxc\validate\PurchaseOrderValidate;

class PurchaseOrderController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(new PurchaseOrderLists());
    }

    public function add()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('add');
        $params['admin_id'] = $this->adminId;
        $result = PurchaseOrderLogic::add($params);
        if (true === $result) {
            return $this->success('添加成功', [], 1, 1);
        }
        return $this->fail(PurchaseOrderLogic::getError());
    }

    public function edit()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('edit');
        $result = PurchaseOrderLogic::edit($params);
        if (true === $result) {
            return $this->success('编辑成功', [], 1, 1);
        }
        return $this->fail(PurchaseOrderLogic::getError());
    }

    public function delete()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('delete');
        PurchaseOrderLogic::delete($params);
        return $this->success('删除成功', [], 1, 1);
    }

    public function detail()
    {
        $params = (new PurchaseOrderValidate())->goCheck('detail');
        $result = PurchaseOrderLogic::detail($params);
        return $this->data($result);
    }

    public function confirm()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('confirm');
        $result = PurchaseOrderLogic::confirm($params);
        if (!$result) {
            return $this->fail(PurchaseOrderLogic::getError());
        }
        return $this->success('操作成功', [], 1, 1);
    }

    public function cancel()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('cancel');
        $result = PurchaseOrderLogic::cancel($params);
        if (!$result) {
            return $this->fail(PurchaseOrderLogic::getError());
        }
        return $this->success('取消成功', [], 1, 1);
    }

    public function convertToSalesOrder()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('convert');
        $result = PurchaseOrderLogic::convertToSalesOrder($params);
        if (empty($result)) {
            return $this->fail(PurchaseOrderLogic::getError());
        }
        return $this->success('转换成功', $result, 1, 1);
    }

    public function parsePastedText()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('parse');
        $result = PurchaseOrderLogic::parsePastedText($params);
        return $this->success('解析成功', $result);
    }

    public function statistics()
    {
        $params = $this->request->get();
        $result = PurchaseOrderLogic::statistics($params);
        return $this->success('获取成功', $result);
    }
}
```

#### Validate: `app/api/jxc/validate/PurchaseOrderValidate.php`

```php
<?php
namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class PurchaseOrderValidate extends BaseValidate
{
    protected $rule = [
        'id'              => 'require|integer',
        'customer_id'     => 'require|integer',
        'warehouse_id'    => 'integer',
        'datetimesingle'  => 'integer',
        'predicted_date'  => 'integer',
        'goods'           => 'require|array',
        'action'          => 'require|in:sent,received,delivered,completed',
        'text'            => 'require|string',
    ];

    protected $field = [
        'id'           => '订货单ID',
        'customer_id'  => '客户',
        'warehouse_id' => '仓库',
        'goods'        => '商品列表',
        'action'       => '操作动作',
        'text'         => '文本内容',
    ];

    public function sceneAdd()     { return $this->only(['customer_id', 'goods']); }
    public function sceneEdit()    { return $this->only(['id', 'customer_id', 'goods']); }
    public function sceneDelete()  { return $this->only(['id']); }
    public function sceneDetail()  { return $this->only(['id']); }
    public function sceneConfirm() { return $this->only(['id', 'action']); }
    public function sceneCancel()  { return $this->only(['id']); }
    public function sceneConvert() { return $this->only(['id']); }
    public function sceneParse()   { return $this->only(['text']); }
}
```

---

## 第六章：前后端接口对接策略

### 6.1 响应格式映射

**核心问题**：框架默认列表响应格式为 `{lists, count, page_no, page_size}`，且框架列表类默认读取 `page_no/page_size`，但前端当前大量使用 `page/pagesize` 和 `{data, total}`。

**推荐解决方案**：在 `BaseJxcController` 中统一做请求/响应适配：

```php
// app/api/jxc/controller/BaseJxcController.php

/**
 * 将前端 page/pagesize 兼容为框架 page_no/page_size
 */
protected function normalizeListQueryParams(): void
{
    $page = $this->request->get('page');
    $pageSize = $this->request->get('pagesize');
    if (!is_null($page) && is_null($this->request->get('page_no'))) {
        $this->request->withGet(['page_no' => $page]);
    }
    if (!is_null($pageSize) && is_null($this->request->get('page_size'))) {
        $this->request->withGet(['page_size' => $pageSize]);
    }
}

/**
 * 列表数据返回（转换为前端期望格式）
 */
protected function dataLists(BaseDataLists $lists = null): \think\response\Json
{
    if (is_null($lists)) {
        $listName = str_replace('.', '\\', App::getNamespace() . '\\lists\\' . $this->request->controller() . ucwords($this->request->action()));
        $lists = invoke($listName);
    }
    $result = $lists->lists();
    $count = $lists->count();
    return $this->success('获取成功', [
        'data'  => $result,
        'total' => $count,
    ]);
}
```

**各接口响应格式对照表：**

| 响应类型 | 后端格式 | 前端期望格式 | 适配位置 |
|---------|---------|------------|---------|
| 列表接口 | `{lists:[], count:N}`（框架原始） | `{data:[], total:N, page:N, pagesize:N}` | BaseJxcController.dataLists()，**已实际适配** |
| 单条数据 | `{code:1, data:{...}}` | `{code:1, data:{...}}` | 无需适配 |
| 操作成功 | `{code:1, msg:"成功"}` | `{code:1, msg:"成功"}` | 无需适配 |
| 操作失败 | `{code:0, msg:"错误"}` | `{code:0, msg:"错误"}` | 无需适配 |

### 6.2 字段命名映射表

| 前端字段名 | 后端字段名 | 说明 |
|-----------|---------|------|
| `customer_name` | `customer_name` | 一致 |
| `supplier_name` | `supplier_name` | 一致 |
| `page` | `page_no` | 前端传 page，需映射为框架 page_no |
| `pagesize` | `page_size` | 前端传 pagesize，需映射为框架 page_size |
| `datetimesingle` | `datetimesingle` | Unix 时间戳，存 int |
| `order_receivable` | `order_arrears_money` | 语义等同 |
| `status`（字符串） | `status`（整数） | 需要 Model 访问器转换 |
| `is_store` | `is_store` | 一致 |
| `parent_id` | `parent_id` | 一致 |
| `from_purchase_order_id` | `from_purchase_order_id` | 一致 |
| `original_sales_order_id` | `original_sales_order_id` | 一致 |

### 6.3 时间戳处理约定

- **存储**：所有时间字段存储为 **Unix 时间戳（int）**，包括 `datetimesingle`、`predicted_date`
- **前端传入**：前端传 Unix 时间戳，后端直接存储
- **返回给前端**：`datetimesingle` 字段直接返回 Unix 时间戳（前端自行格式化）
- **`create_time`、`update_time`**：可通过 Model 访问器转为 `Y-m-d H:i:s` 字符串返回

```php
// Model 中添加
public function getDatetimesingleAttr($value)
{
    return (int)$value; // 确保是整数
}

public function getCreateTimeAttr($value)
{
    return $value ? date('Y-m-d H:i:s', $value) : '';
}
```

### 6.4 文件上传接口对接

文件上传使用现有的 `app/api/controller/UploadController.php`，已实现：
- `POST /api/upload/image` - 图片上传
- `POST /api/upload/file` - 文件上传

前端调用时携带 token 即可，无需新增接口。

### 6.5 错误处理对接

**前端 `request.js` 错误处理逻辑：**
- `code === 1`：成功
- `code === 0`：业务失败，弹出 `msg`
- `code === -1`：token 失效，跳转登录
- HTTP 4xx/5xx：网络错误提示

**后端对应规范：**
```php
// 成功
return $this->success('操作成功', $data);  // code=1

// 业务失败
return $this->fail('客户不存在');           // code=0

// Token失效（由中间件返回）
JsonService::fail('登录超时，请重新登录', [], -1, 0);  // code=-1
```

---

## 第七章：实施计划

### 7.1 开发阶段划分

#### Phase 1：基础设施（预计 2 天）**[已完成]**

**目标**：搭建新模块骨架，创建数据库，实现无依赖的基础模块

**任务清单：**
1. 执行所有建表 SQL（11 张表）
2. 创建 `app/api/jxc/` 目录结构
3. 实现 `BaseJxcController`（含格式转换）
4. 实现 `JxcLoginMiddleware`（token 鉴权）
5. 在 `route/app.php` 注册所有路由
6. 完成 **单位管理（GoodsUnit）** 全链路：Model→Lists→Validate→Logic→Controller
7. 完成 **仓库管理（Warehouse）** 全链路（含 enable/disable）

#### Phase 2：主数据模块（预计 3 天）**[已完成]**

**目标**：完成核心主数据的 CRUD

**任务清单：**
1. **供应商管理（Vendor）**：标准 CRUD
2. **商品管理（Goods）**：CRUD + 单位关联查询
3. **客户分组（CustomerGroup）**：CRUD
4. **客户管理（Customer）**：CRUD + 门店关联（children/bindStore/unbindStore）+ 分组分配（assignGroup）+ 禁用/启用（setStatus）

#### Phase 3：基础单据模块（预计 4 天）**[已完成 2026-04-19]**

**目标**：完成销售单、进货单、退货单

**任务清单：**
1. 实现 `OrderGoods` Model（通用明细表）
2. **销售单（SalesOrder）**：CRUD + 明细管理 + 统计（statistics）
3. **进货单（SupplyOrder）**：CRUD + 明细管理 + 统计
4. **退货单（SalesReturnOrder）**：CRUD + 关联原销售单验证

#### Phase 4：订货单模块（预计 3 天）**[已完成 2026-04-19]**

**目标**：完成最复杂的订货单，含状态机和特殊操作

**任务清单：**
1. 订货单基础 CRUD + 明细
2. 状态机（confirm/cancel）
3. 转销售单（convertToSalesOrder）
4. 文本识别（parsePastedText）- 正则解析
5. 统计接口（statistics）

#### Phase 5：财务统计 + 客户汇总（预计 2 天）**[已完成 2026-04-19]**

**目标**：完成客户付款、应收汇总、首页统计

**任务清单：**
1. 客户付款（paymoney）
2. 客户应收汇总（receivableSummary，含门店聚合）
3. 客户销售记录（salesRecords）
4. 客户统计（summary）
5. 店铺管理（Store：getStoreInfo/setStore/createStore）

#### Phase 6：联调测试优化（预计 3 天）**[已完成 2026-04-19]**

**任务清单：**
1. 前后端联调，修复接口格式差异
2. 多租户隔离测试
3. 订货单状态流转完整测试
4. 分页/搜索功能测试
5. 金额精度验证（bcmath）
6. 并发安全测试（单号唯一性）
7. 性能优化（索引、N+1查询）

### 7.2 各阶段预估工时

| 阶段 | 开发内容 | 预估工时 |
|------|---------|---------|
| Phase 1 | 基础设施+单位+仓库 | 2 天 | **[已完成]** |
| Phase 2 | 供应商+商品+客户（含分组、层级） | 3 天 | **[已完成]** |
| Phase 3 | 销售单+进货单+退货单 | 4 天 | **[已完成 2026-04-19]** |
| Phase 4 | 订货单（含状态机+转单+文本识别） | 3 天 | **[已完成 2026-04-19]** |
| Phase 5 | 财务统计+客户汇总+店铺 | 2 天 | **[已完成 2026-04-19]** |
| Phase 6 | 联调测试+优化 | 3 天 | **[已完成 2026-04-19]** |
| **合计** | | **约 17 工作日** |

### 7.3 里程碑节点

| 里程碑 | 完成标志 | 目标日期 |
|-------|---------|---------|
| M1：数据库就绪 | 全部 11 张表创建完成，索引验证通过 | Day 1 |
| M2：基础模块上线 | 单位、仓库、供应商、商品接口可用 | Day 5 |
| M3：客户模块上线 | 客户 CRUD + 门店管理 + 分组完成 | Day 8 |
| M4：单据模块上线 | 销售单、进货单、退货单完成 | Day 12 |
| M5：订货单上线 | 订货单含状态机、转单、文本识别完成 | Day 15 |
| M6：前后端联调完成 | 75 个接口全部通过验收测试 | Day 17 |

---

## 第八章：风险评估与应对

### 8.1 技术风险

#### 风险 T1：路由兼容性
- **描述**：前端 API 路径采用 `/api/{module}/{action}`（如 `/api/customer/index`、`/api/order/lists`），与现有 tenantapi 路由 `/api/tenantapi/...` 不兼容
- **影响**：高
- **缓解**：在 `route/app.php` 中显式注册所有 75 个路由，不依赖自动路由；新建 `app/api/jxc/` 命名空间与现有 `app/api/` 隔离

#### 风险 T2：响应格式差异（分页）
- **描述**：框架返回 `{lists, count}`，前端期望 `{data, total}`，且请求分页字段也存在 `page/pagesize` 与 `page_no/page_size` 差异
- **影响**：中
- **缓解**：在 `BaseJxcController.dataLists()` 中统一转换，一处修改全局生效

#### 风险 T3：订货单状态字符串与整数不匹配
- **描述**：前端使用字符串状态（`draft/sent/...`），数据库存整数
- **影响**：中
- **缓解**：在 Model 中定义双向映射常量，在 Lists 搜索器和 Logic 中统一转换

#### 风险 T4：`datetimesingle` 字段类型
- **描述**：前端传 Unix 时间戳（整数），旧代码中有的字段存为字符串 `Y-m-d H:i:s`
- **影响**：低
- **缓解**：新表统一定义为 `int(11)`，Model 访问器保证类型一致

### 8.2 业务风险

#### 风险 B1：订货单转销售单的并发问题
- **描述**：同一订货单被两个请求同时转换，导致重复创建销售单
- **影响**：高
- **缓解**：在 Logic 中使用数据库行锁 `->lock(true)` 查询订货单，或添加唯一索引 `from_purchase_order_id`（允许0，唯一非0）

#### 风险 B2：金额精度
- **描述**：浮点运算导致金额计算误差
- **影响**：高
- **缓解**：所有金额计算强制使用 `bcadd/bcmul/bcsub`，保留2位小数；数据库字段使用 `DECIMAL(12,2)`

#### 风险 B3：客户层级聚合性能
- **描述**：客户应收汇总需要查询主客户+所有门店，数据量大时性能差
- **影响**：中
- **缓解**：在 `lk_customer` 表维护冗余汇总字段（`order_receivable`），每次付款/下单后异步更新；避免实时聚合查询

#### 风险 B4：单号唯一性
- **描述**：高并发下 `date('YmdHis') + rand()` 可能重复
- **影响**：中
- **缓解**：在单号列添加唯一索引；Logic 中 `try-catch` 捕获唯一键冲突异常并重试

### 8.3 集成风险

#### 风险 I1：多租户隔离泄漏
- **描述**：`BaseModel` 全局作用域自动添加 `tenant_id` 条件，若中间件未正确设置 `$request->tenantId`，可能查到其他租户数据
- **影响**：高（数据安全）
- **缓解**：`JxcLoginMiddleware` 必须验证 token 后设置 `$request->tenantId`；每个模块完成后进行多租户隔离测试（用两个不同租户账号交叉验证）

#### 风险 I2：旧模块数据冲突
- **描述**：旧的 `lk_user_supplier`（供应商）、`lk_tenant_goods`（商品）与新表并存，可能造成数据混乱
- **影响**：中
- **缓解**：新模块使用全新表名（`lk_vendor`、`lk_goods`），通过路由完全隔离；如需数据迁移，另行制定迁移脚本

#### 风险 I3：Token 鉴权体系
- **描述**：现有两套 token 体系（tenantapi 管理员 token、api 用户 token），进销存 APP 用哪套？
- **影响**：高
- **缓解**：与产品确认后决定；推荐使用 **tenantapi 管理员 token**（因为进销存是 B 端操作）；`JxcLoginMiddleware` 复用 `tenantapi/http/middleware/LoginMiddleware.php` 的逻辑

#### 风险 I4：前端 API 常量冲突/未冻结
- **描述**：前端 `common/constants/api.js` 中存在认证接口未补齐、商品详情未补齐、供应商管理与进货单路径冲突等问题
- **影响**：高
- **缓解**：编码前先冻结 API 常量；建议补齐 `AUTH_API.USER_INFO`、`AUTH_API.LOGOUT`、`PRODUCT_API.DETAIL`，并将进货单独立回 `/api/supply/*`

### 8.4 风险缓解措施汇总

| 优先级 | 措施 |
|-------|------|
| 高 | 所有金额字段使用 DECIMAL(12,2) + bcmath 计算 |
| 高 | 转单操作使用数据库事务 + 行锁 |
| 高 | `JxcLoginMiddleware` 完成后立即进行多租户隔离验证 |
| 高 | 联调前先冻结前端 API 常量，消除认证/商品详情缺失与 supplier/supply 路径冲突 |
| 中 | 单号字段添加 UNIQUE 索引，捕获重复异常并重试 |
| 中 | `BaseJxcController` 统一处理分页格式转换，全模块受益 |
| 低 | 文本识别接口先实现简单正则版本，迭代优化 |

---

## 附录 A：完整文件创建清单

### 新建文件（共 58 个）

```
# 目录结构
app/api/jxc/
├── middleware/JxcLoginMiddleware.php
├── controller/
│   ├── BaseJxcController.php
│   ├── LoginController.php
│   ├── CustomerController.php
│   ├── CustomerGroupController.php
│   ├── SupplierController.php
│   ├── GoodsController.php
│   ├── GoodsUnitController.php
│   ├── WarehouseController.php
│   ├── SalesOrderController.php
│   ├── SupplyOrderController.php
│   ├── SalesReturnOrderController.php
│   ├── PurchaseOrderController.php
│   └── StoreController.php
├── validate/
│   ├── CustomerValidate.php
│   ├── CustomerGroupValidate.php
│   ├── SupplierValidate.php
│   ├── GoodsValidate.php
│   ├── GoodsUnitValidate.php
│   ├── WarehouseValidate.php
│   ├── SalesOrderValidate.php
│   ├── SupplyOrderValidate.php
│   ├── SalesReturnOrderValidate.php
│   ├── PurchaseOrderValidate.php
│   └── StoreValidate.php
├── logic/
│   ├── LoginLogic.php
│   ├── CustomerLogic.php
│   ├── CustomerGroupLogic.php
│   ├── SupplierLogic.php
│   ├── GoodsLogic.php
│   ├── GoodsUnitLogic.php
│   ├── WarehouseLogic.php
│   ├── SalesOrderLogic.php
│   ├── SupplyOrderLogic.php
│   ├── SalesReturnOrderLogic.php
│   ├── PurchaseOrderLogic.php
│   └── StoreLogic.php
└── lists/
    ├── CustomerLists.php
    ├── CustomerGroupLists.php
    ├── SupplierLists.php
    ├── GoodsLists.php
    ├── GoodsUnitLists.php
    ├── WarehouseLists.php
    ├── SalesOrderLists.php
    ├── SupplyOrderLists.php
    ├── SalesReturnOrderLists.php
    └── PurchaseOrderLists.php

app/common/model/jxc/
├── Customer.php
├── CustomerGroup.php
├── Vendor.php
├── Goods.php
├── GoodsUnit.php
├── Warehouse.php
├── SalesOrder.php
├── SupplyOrder.php
├── SalesReturnOrder.php
├── PurchaseOrder.php
└── OrderGoods.php
```

### 修改文件（共 1 个）

```
route/app.php  ← 新增 jxc 路由组（约 60 行）
```

---

## 附录 B：数据库建表 SQL 汇总

> 完整 SQL 见第三章 3.1 节，此处提供执行顺序：

```sql
-- 执行顺序（无外键约束，任意顺序均可）
-- 1. 基础数据表（无依赖）
lk_goods_unit
lk_warehouse
lk_customer_group

-- 2. 主数据表（依赖基础表）
lk_customer
lk_vendor
lk_goods

-- 3. 单据表（依赖主数据）
lk_sales_order
lk_supply_order
lk_sales_return_order
lk_purchase_order

-- 4. 明细表（依赖所有单据）
lk_order_goods
```

---

## 附录 C：前后端字段映射对照表

| 功能场景 | 前端字段 | 后端字段/表 | 类型映射 | 备注 |
|---------|---------|-----------|---------|------|
| 分页请求 | `page` | `page` | int | 一致 |
| 分页请求 | `page` | `page_no` | int | 需在 Controller 层显式映射 |
| 分页请求 | `pagesize` | `page_size` | int | 需在 Controller 层显式映射 |
| 分页响应 | `data.data` | `data.lists` | array | 需 BaseJxcController 转换 |
| 分页响应 | `data.total` | `data.count` | int | 需 BaseJxcController 转换 |
| 订货单状态 | `"draft"` | `1` | string→int | Model 双向映射 |
| 订货单状态 | `"sent"` | `2` | string→int | Model 双向映射 |
| 订货单状态 | `"received"` | `3` | string→int | Model 双向映射 |
| 订货单状态 | `"delivered"` | `4` | string→int | Model 双向映射 |
| 订货单状态 | `"completed"` | `5` | string→int | Model 双向映射 |
| 订货单状态 | `"cancelled"` | `6` | string→int | Model 双向映射 |
| 单据日期 | `datetimesingle` | `datetimesingle` | int(Unix) | 前后端均用 Unix 时间戳 |
| 客户应收 | `order_receivable` | `order_arrears_money` | decimal | 语义相同 |
| 商品明细 | `goods[].number` | `lk_order_goods.number` | decimal | 数量 |
| 商品明细 | `goods[].price` | `lk_order_goods.price` | decimal | 单价 |
| 商品明细 | `goods[].amount` | `lk_order_goods.amount` | decimal | 小计 = number × price |
| 商品明细 | `goods[].units` | `lk_order_goods.units` | string | 单位名称（冗余存储） |
| 门店关系 | `parent_id > 0` | `lk_customer.parent_id` | int | 0=主客户，>0=门店 |
| 转单关联 | `from_purchase_order_id` | `lk_sales_order.from_purchase_order_id` | int | 0=非转换创建 |

---

---

## 8. Phase 2 实施成果补充

> 更新日期：2026-04-19

以下功能在实施过程中超出原规划范围新增实现：

### 8.1 库存与财务核心服务

- **StockService**（`app/api/jxc/logic/StockService.php`，192行）：提供 inbound/outbound/rollback 三个核心方法，使用行锁（lock(true)）保证并发安全，全程 bcmath 精确计算。
- **FinanceService**（`app/api/jxc/logic/FinanceService.php`，258行）：提供客户应收管理（addReceivable/reduceReceivable/rollbackReceivable）和供应商应付管理（addPayable/reducePayable/rollbackPayable），同步更新累计字段并写流水。
- 新增 3 张流水表：lk_stock_flow、lk_receivable_flow、lk_payable_flow。

### 8.2 幂等键和单据编号安全

- 4 张订单表均新增 idempotent_key 字段和 idx_tenant_idempotent 索引。
- 订单发布接口支持可选幂等键参数，重复提交直接返回已创建订单。
- 单据编号生成改为带 3 次重试机制，利用唯一索引防止冲突。

### 8.3 订货单状态机

- PurchaseOrder Model 定义 6 状态常量（STATUS_DRAFT=1 到 STATUS_CANCELLED=6）。
- ALLOWED_TRANSITIONS 静态属性定义合法状态转移路径。
- canTransitionTo 方法校验状态转移合法性。

### 8.4 bcmath 金额计算统一

- SalesOrderLogic、CustomerLogic、PurchaseOrderLogic 中的核心金额计算已从浮点运算统一为 bcadd/bcsub/bcmul/bccomp。

### 8.5 删除占用校验

- GoodsUnitLogic：检查 Goods.unit_id 占用。
- WarehouseLogic：检查 4 张订单表的 warehouse_id 占用。
- SupplierLogic：检查 SupplyOrder.supplier_id 占用。
- GoodsLogic：检查 OrderGoods.goods_id 占用。
- CustomerLogic：检查 SalesOrder/SalesReturnOrder/PurchaseOrder 的 customer_id 占用。
- CustomerGroupLogic：检查 Customer.group_id 占用。

### 8.6 冒烟测试扩展

- tests/smoke_test.php 从 9 步扩展到 16 步，覆盖进货单 CRUD、销售退货单 CRUD、订货单状态推进、店铺管理、删除占用校验。

### 8.7 工程化增强

- 接口契约文档 docs/jxc-api-contract-v0.1.md 已冻结。
- 一键启停脚本 scripts/start-dev.ps1、scripts/stop-dev.ps1。
- 数据库初始化脚本 scripts/init-db.ps1。
- Git 基线提交完成（commit 64a6d9f + 9e3dcb6）。

---

---

## 9. Phase 6 实施成果补充

> 更新日期：2026-04-19

### 9.1 集成测试体系

**新增专项测试脚本（`tests/` 目录）**

- `tests/isolation_test.php` —— 多租户隔离集成测试：19 个步骤，覆盖客户、商品、仓库、销售单、进货单的列表隔离验证，以及越权查看、越权编辑、越权删除的拒绝校验。
- `tests/sales_flow_test.php` —— 销售单完整闭环测试：3 个场景，包括正常销售流程、多商品行金额汇总、bcmath 金额精度验证。
- `tests/supply_flow_test.php` —— 进货单库存联动测试：2 个场景，包括正常进货流程、同一供应商多次进货累计应付。
- `tests/purchase_convert_test.php` —— 订货单转销售单端到端测试：3 个场景，包括正常转单、取消订货单、非法状态转移拒绝。
- `tests/load_test.php` —— 性能基准测试：3 个场景，包括列表查询性能、批量创建订单、curl_multi 并发支付模拟。

### 9.2 客户销售历史真实实现

- **修改文件**：`app/api/jxc/logic/CustomerLogic.php`（salesHistory 方法）
- **改动说明**：从占位返回改为基于 `sales_order.customer_id` 的真实 SQL 关联查询，支持主客户+门店汇总，分页返回销售记录列表。

### 9.3 配套文档补齐

**新增文档（`docs/` 目录）**

- `docs/deployment-guide.md` —— 部署手册：环境要求、快速开始、生产部署流程、CI/CD 集成说明、常见故障排查。
- `docs/configuration-guide.md` —— 配置管理文档：配置文件概览、环境变量清单、三环境对比表、安全配置建议。

### 9.4 风险缓解状态更新

| 原风险 | 缓解措施 | 当前状态 |
|---------|---------|----------|
| 多租户隔离 | tests/isolation_test.php 19 步测试 | **已通过系统测试验证** |
| 业务流程端到端 | 销售单/进货单/订货转单闭环测试 | **已通过闭环测试验证** |
| 金额精度 | bcmath 边界场景测试 | **已通过 bcmath 边界测试验证** |
| 部署文档缺失 | deployment-guide.md + configuration-guide.md | **已补齐** |

---

*文档结束*

> **下一步行动**：
> 1. DBA 审查并执行附录 B 的建表 SQL
> 2. 后端负责人审查路由规划，确认 `app/api/jxc/` 命名空间策略
> 3. 确认 token 鉴权体系（使用租户 token 还是用户 token）
> 4. 按 Phase 1 开始编码，单位管理作为第一个完整实现模块（最简单，适合验证架构）
