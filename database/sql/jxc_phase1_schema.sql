CREATE TABLE IF NOT EXISTS `la_goods_unit` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '单位ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '单位名称',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态（0=停用，1=启用）',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品单位表';

CREATE TABLE IF NOT EXISTS `la_warehouse` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '仓库ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '仓库名称',
  `province` varchar(50) NOT NULL DEFAULT '' COMMENT '省份',
  `city` varchar(50) NOT NULL DEFAULT '' COMMENT '城市',
  `district` varchar(50) NOT NULL DEFAULT '' COMMENT '区县',
  `address` varchar(255) NOT NULL DEFAULT '' COMMENT '仓库地址',
  `address_detail` varchar(255) NOT NULL DEFAULT '' COMMENT '详细地址',
  `contact` varchar(50) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用（0=禁用，1=启用）',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_is_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='仓库表';

CREATE TABLE IF NOT EXISTS `la_vendor` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '供应商ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `supplier_name` varchar(100) NOT NULL DEFAULT '' COMMENT '供应商名称',
  `contact` varchar(50) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `address` varchar(255) NOT NULL DEFAULT '' COMMENT '地址',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `is_disabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否禁用',
  `order_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计进货金额',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_supplier_name` (`supplier_name`),
  KEY `idx_is_disabled` (`is_disabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='供应商表';

CREATE TABLE IF NOT EXISTS `la_goods` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '商品ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name` varchar(200) NOT NULL DEFAULT '' COMMENT '商品名称',
  `product_code` varchar(100) NOT NULL DEFAULT '' COMMENT '商品编号',
  `units` varchar(50) NOT NULL DEFAULT '' COMMENT '计量单位',
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单位ID',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '销售价格',
  `cost` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '成本价',
  `stock` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '库存数量',
  `category_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID',
  `primary_supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '默认供应商ID',
  `is_disabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否停用',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_unit_id` (`unit_id`),
  KEY `idx_tenant_primary_supplier` (`tenant_id`, `primary_supplier_id`),
  KEY `idx_is_disabled` (`is_disabled`),
  KEY `idx_name` (`name`),
  KEY `idx_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品表';

CREATE TABLE IF NOT EXISTS `la_goods_supplier` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '商品供应商关联ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否默认供应商',
  `supplier_product_code` varchar(100) NOT NULL DEFAULT '' COMMENT '供应商商品编码',
  `purchase_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '采购价',
  `min_purchase_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '最小采购数量',
  `lead_time_days` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '交期天数',
  `last_purchase_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '最近采购价',
  `last_purchase_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近采购时间',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态（0=停用，1=启用）',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_goods_supplier` (`tenant_id`, `goods_id`, `supplier_id`),
  KEY `idx_tenant_goods` (`tenant_id`, `goods_id`),
  KEY `idx_tenant_supplier` (`tenant_id`, `supplier_id`),
  KEY `idx_tenant_goods_primary` (`tenant_id`, `goods_id`, `is_primary`),
  KEY `idx_tenant_supplier_status` (`tenant_id`, `supplier_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品供应商关联表';

CREATE TABLE IF NOT EXISTS `la_customer` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '客户ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `customer_name` varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称',
  `contact` varchar(50) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `address` varchar(255) NOT NULL DEFAULT '' COMMENT '地址',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `group_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分组ID',
  `parent_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父级客户ID',
  `is_store` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否为门店',
  `children_count` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '下属门店数量',
  `is_disabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否禁用',
  `order_receivable` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计应收金额',
  `order_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计销售金额',
  `order_pay_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计已付金额',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_customer_name` (`customer_name`),
  KEY `idx_is_disabled` (`is_disabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户表';

CREATE TABLE IF NOT EXISTS `la_customer_group` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '分组ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `group_name` varchar(100) NOT NULL DEFAULT '' COMMENT '分组名称',
  `customer_count` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户数量',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_group_name` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户分组表';

CREATE TABLE IF NOT EXISTS `la_sales_order` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '销售单ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '销售单号',
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户ID',
  `customer_name` varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称快照',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `order_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '订单金额',
  `order_pay_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '已收金额',
  `order_arrears_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '未收金额',
  `datetimesingle` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单据日期',
  `from_purchase_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源订货单ID（0=非转换）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
  `purpose_type` varchar(50) NOT NULL DEFAULT 'sales' COMMENT '出库目的类型',
  `remarks` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建管理员ID',
  `idempotent_key` varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_order_sn` (`tenant_id`, `order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_datetimesingle` (`datetimesingle`),
  KEY `idx_from_purchase` (`from_purchase_order_id`),
  KEY `idx_tenant_idempotent` (`tenant_id`, `idempotent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售单表';

CREATE TABLE IF NOT EXISTS `la_order_goods` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '单据商品明细ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单据ID',
  `order_type` varchar(30) NOT NULL DEFAULT '' COMMENT '单据类型',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `name` varchar(200) NOT NULL DEFAULT '' COMMENT '商品名称快照',
  `units` varchar(50) NOT NULL DEFAULT '' COMMENT '计量单位快照',
  `number` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '数量',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '单价',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '小计金额',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_order` (`order_id`, `order_type`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_tenant_goods_order` (`tenant_id`, `goods_id`, `order_id`, `order_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='单据商品明细表';

-- ========================================
-- 库存流水表
-- ========================================
CREATE TABLE IF NOT EXISTS `la_stock_flow` (
  `id`            int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `warehouse_id`  int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `goods_id`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `order_id`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联单据ID',
  `order_type`    varchar(30) NOT NULL DEFAULT '' COMMENT '单据类型(sales/supply/sales-return)',
  `order_sn`      varchar(64) NOT NULL DEFAULT '' COMMENT '单据编号',
  `flow_type`     tinyint(1) NOT NULL DEFAULT 0 COMMENT '流向(1=入库,2=出库)',
  `quantity`      decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '数量(正数)',
  `before_stock`  decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动前库存',
  `after_stock`   decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动后库存',
  `admin_id`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `remark`        varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time`   int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_goods` (`tenant_id`, `goods_id`),
  KEY `idx_warehouse_goods` (`warehouse_id`, `goods_id`),
  KEY `idx_order` (`order_id`, `order_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='库存流水表';

-- ========================================
-- 客户应收流水表
-- ========================================
CREATE TABLE IF NOT EXISTS `la_receivable_flow` (
  `id`             int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `customer_id`    int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户ID',
  `order_id`       int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联单据ID',
  `order_type`     varchar(30) NOT NULL DEFAULT '' COMMENT '单据类型',
  `order_sn`       varchar(64) NOT NULL DEFAULT '' COMMENT '单据编号',
  `flow_type`      tinyint(1) NOT NULL DEFAULT 0 COMMENT '类型(1=应收增加-销售,2=应收减少-收款,3=应收减少-退货)',
  `amount`         decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
  `before_amount`  decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动前应收',
  `after_amount`   decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动后应收',
  `admin_id`       int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `remark`         varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time`    int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_customer` (`tenant_id`, `customer_id`),
  KEY `idx_order` (`order_id`, `order_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户应收流水表';

-- ========================================
-- 供应商应付流水表
-- ========================================
CREATE TABLE IF NOT EXISTS `la_payable_flow` (
  `id`             int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`      int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `supplier_id`    int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `order_id`       int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联单据ID',
  `order_type`     varchar(30) NOT NULL DEFAULT '' COMMENT '单据类型',
  `order_sn`       varchar(64) NOT NULL DEFAULT '' COMMENT '单据编号',
  `flow_type`      tinyint(1) NOT NULL DEFAULT 0 COMMENT '类型(1=应付增加-进货,2=应付减少-付款)',
  `amount`         decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
  `before_amount`  decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动前应付',
  `after_amount`   decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动后应付',
  `admin_id`       int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `remark`         varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time`    int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_supplier` (`tenant_id`, `supplier_id`),
  KEY `idx_order` (`order_id`, `order_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='供应商应付流水表';

-- ========================================
-- 供应商表补充应付字段
-- ========================================
ALTER TABLE `la_vendor`
  ADD COLUMN `order_payable` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计应付金额' AFTER `order_money`,
  ADD COLUMN `order_paid_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计已付金额' AFTER `order_payable`;

-- ========== Phase 2: 销售退货单 ==========

CREATE TABLE IF NOT EXISTS `la_sales_return_order` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '退货单ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '退货单号',
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户ID',
  `customer_name` varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称快照',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '退回仓库ID',
  `original_sales_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '原销售单ID',
  `original_order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '原销售单号',
  `order_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '退货金额',
  `return_reason` varchar(500) NOT NULL DEFAULT '' COMMENT '退货原因',
  `remarks` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建管理员ID',
  `idempotent_key` varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键',
  `datetimesingle` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单据日期',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_order_sn` (`tenant_id`, `order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_original_order` (`original_sales_order_id`),
  KEY `idx_datetimesingle` (`datetimesingle`),
  KEY `idx_tenant_idempotent` (`tenant_id`, `idempotent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售退货单表';

-- ========== Phase 2: 进货单 ==========

CREATE TABLE IF NOT EXISTS `la_supply_order` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '进货单ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '进货单号',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `supplier_name` varchar(100) NOT NULL DEFAULT '' COMMENT '供应商名称快照',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `order_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '订单金额',
  `order_pay_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '已付金额',
  `order_arrears_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '未付金额',
  `datetimesingle` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单据日期',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
  `purpose_type` varchar(50) NOT NULL DEFAULT 'supply' COMMENT '入库目的类型',
  `remarks` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建管理员ID',
  `idempotent_key` varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_order_sn` (`tenant_id`, `order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_tenant_supplier_date` (`tenant_id`, `supplier_id`, `datetimesingle`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_datetimesingle` (`datetimesingle`),
  KEY `idx_tenant_idempotent` (`tenant_id`, `idempotent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='进货单表';

-- ========== Phase 2: 订货单 ==========

CREATE TABLE IF NOT EXISTS `la_purchase_order` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '订货单ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '订货单号',
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户ID',
  `customer_name` varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称快照',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `order_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '订单金额',
  `order_pay_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '已付金额（预付/订金）',
  `datetimesingle` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单据日期',
  `predicted_date` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '预计交货日期',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态(1=草稿,2=已发送,3=已收货,4=已配送,5=已完成,6=已取消)',
  `cancel_reason` varchar(500) NOT NULL DEFAULT '' COMMENT '取消原因',
  `remarks` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建管理员ID',
  `idempotent_key` varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_order_sn` (`tenant_id`, `order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_status` (`status`),
  KEY `idx_datetimesingle` (`datetimesingle`),
  KEY `idx_tenant_idempotent` (`tenant_id`, `idempotent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订货单表';
