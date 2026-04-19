CREATE TABLE IF NOT EXISTS `lk_goods_unit` (
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

CREATE TABLE IF NOT EXISTS `lk_warehouse` (
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

CREATE TABLE IF NOT EXISTS `lk_vendor` (
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

CREATE TABLE IF NOT EXISTS `lk_goods` (
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
  `is_disabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否停用',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_unit_id` (`unit_id`),
  KEY `idx_is_disabled` (`is_disabled`),
  KEY `idx_name` (`name`),
  KEY `idx_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品表';

CREATE TABLE IF NOT EXISTS `lk_customer` (
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

CREATE TABLE IF NOT EXISTS `lk_customer_group` (
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

CREATE TABLE IF NOT EXISTS `lk_sales_order` (
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
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
  `purpose_type` varchar(50) NOT NULL DEFAULT 'sales' COMMENT '出库目的类型',
  `remarks` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建管理员ID',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_order_sn` (`tenant_id`, `order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_datetimesingle` (`datetimesingle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售单表';

CREATE TABLE IF NOT EXISTS `lk_order_goods` (
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
  KEY `idx_goods_id` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='单据商品明细表';
