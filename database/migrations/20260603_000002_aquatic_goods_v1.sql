-- 水产生鲜商品模块 V1
-- 基于 JXC la_goods 扩展：品质 SKU、SKU 级供应商矩阵、动态单位换算、采购入库批次与损耗。

CREATE TABLE IF NOT EXISTS `la_goods_spec_template` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '规格模板ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '模板名称',
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '模板编码',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态（0=停用，1=启用）',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`, `code`),
  KEY `idx_tenant_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品规格模板表';

CREATE TABLE IF NOT EXISTS `la_goods_spec` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '规格维度ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `template_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '模板ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '规格名称',
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '规格编码',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态（0=停用，1=启用）',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_template_code` (`tenant_id`, `template_id`, `code`),
  KEY `idx_tenant_template` (`tenant_id`, `template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品规格维度表';

CREATE TABLE IF NOT EXISTS `la_goods_spec_value` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '规格值ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '规格维度ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '规格值名称',
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '规格值编码',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态（0=停用，1=启用）',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_spec_code` (`tenant_id`, `spec_id`, `code`),
  KEY `idx_tenant_spec` (`tenant_id`, `spec_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品规格值表';

CREATE TABLE IF NOT EXISTS `la_goods_sku` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'SKU ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '基础商品ID',
  `sku_name` varchar(200) NOT NULL DEFAULT '' COMMENT 'SKU名称',
  `sku_code` varchar(100) NOT NULL DEFAULT '' COMMENT 'SKU编码',
  `quality_status` varchar(50) NOT NULL DEFAULT '' COMMENT '品质状态编码',
  `quality_label` varchar(100) NOT NULL DEFAULT '' COMMENT '品质状态名称',
  `base_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '库存基准单位ID',
  `base_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '库存基准单位名称',
  `purchase_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '采购状态（0=停用，1=启用）',
  `sale_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '销售状态（0=停用，1=启用）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态（0=停用，1=启用）',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_goods_sku_code` (`tenant_id`, `goods_id`, `sku_code`),
  KEY `idx_tenant_goods` (`tenant_id`, `goods_id`),
  KEY `idx_tenant_quality` (`tenant_id`, `goods_id`, `quality_status`),
  KEY `idx_tenant_status` (`tenant_id`, `status`, `purchase_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品SKU表';

CREATE TABLE IF NOT EXISTS `la_goods_sku_spec_value` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'SKU规格值关系ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID',
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '规格维度ID',
  `spec_value_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '规格值ID',
  `spec_name` varchar(100) NOT NULL DEFAULT '' COMMENT '规格名称快照',
  `spec_value_name` varchar(100) NOT NULL DEFAULT '' COMMENT '规格值名称快照',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sku_spec` (`tenant_id`, `sku_id`, `spec_id`),
  KEY `idx_tenant_goods` (`tenant_id`, `goods_id`),
  KEY `idx_tenant_spec_value` (`tenant_id`, `spec_value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品SKU规格值关系表';

ALTER TABLE `la_goods_supplier`
  ADD COLUMN `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID' AFTER `goods_id`;

ALTER TABLE `la_goods_supplier`
  ADD COLUMN `supplier_goods_name` varchar(200) NOT NULL DEFAULT '' COMMENT '供应商商品名' AFTER `supplier_product_code`;

ALTER TABLE `la_goods_supplier`
  ADD COLUMN `purchase_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '采购单位ID' AFTER `purchase_price`;

ALTER TABLE `la_goods_supplier`
  ADD COLUMN `purchase_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '采购单位名称' AFTER `purchase_unit_id`;

ALTER TABLE `la_goods_supplier`
  ADD COLUMN `settlement_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '结算单位ID' AFTER `purchase_unit_name`;

ALTER TABLE `la_goods_supplier`
  ADD COLUMN `settlement_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '结算单位名称' AFTER `settlement_unit_id`;

ALTER TABLE `la_goods_supplier`
  ADD COLUMN `daily_capacity_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '日供货能力' AFTER `min_purchase_qty`;

ALTER TABLE `la_goods_supplier`
  ADD COLUMN `is_preferred` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否首选' AFTER `is_primary`;

SET @idx_exists := (
  SELECT COUNT(1)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'la_goods_supplier'
    AND index_name = 'uk_tenant_goods_supplier'
);
SET @drop_idx_sql := IF(@idx_exists > 0, 'ALTER TABLE `la_goods_supplier` DROP INDEX `uk_tenant_goods_supplier`', 'SELECT 1');
PREPARE drop_idx_stmt FROM @drop_idx_sql;
EXECUTE drop_idx_stmt;
DEALLOCATE PREPARE drop_idx_stmt;

ALTER TABLE `la_goods_supplier`
  ADD UNIQUE KEY `uk_tenant_goods_sku_supplier` (`tenant_id`, `goods_id`, `sku_id`, `supplier_id`);

ALTER TABLE `la_goods_supplier`
  ADD KEY `idx_tenant_goods_sku` (`tenant_id`, `goods_id`, `sku_id`);

ALTER TABLE `la_goods_supplier`
  ADD KEY `idx_tenant_supplier_sku` (`tenant_id`, `supplier_id`, `sku_id`, `status`);

CREATE TABLE IF NOT EXISTS `la_goods_supplier_price_history` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '价格历史ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `goods_supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商SKU关系ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `purchase_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '采购价',
  `effective_date` date DEFAULT NULL COMMENT '生效日期',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_relation` (`tenant_id`, `goods_supplier_id`),
  KEY `idx_tenant_supplier_sku_date` (`tenant_id`, `supplier_id`, `sku_id`, `effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='供应商SKU价格历史表';

CREATE TABLE IF NOT EXISTS `la_goods_unit_conversion_rule` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '换算规则ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID，0=租户默认',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID，0=商品默认',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID，0=非供应商维度',
  `from_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源单位ID',
  `from_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '来源单位名称',
  `to_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '目标单位ID',
  `to_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '目标单位名称',
  `ratio` decimal(18,6) NOT NULL DEFAULT 0.000000 COMMENT '换算比例：1来源单位=ratio目标单位',
  `effective_date` date DEFAULT NULL COMMENT '生效日期',
  `expire_date` date DEFAULT NULL COMMENT '失效日期',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态（0=停用，1=启用）',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_resolve` (`tenant_id`, `goods_id`, `sku_id`, `supplier_id`, `from_unit_id`, `to_unit_id`, `status`),
  KEY `idx_tenant_effective` (`tenant_id`, `effective_date`, `expire_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品动态单位换算规则表';

ALTER TABLE `la_order_goods`
  ADD COLUMN `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID' AFTER `goods_id`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `sku_name` varchar(200) NOT NULL DEFAULT '' COMMENT 'SKU名称快照' AFTER `sku_id`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `supplier_relation_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商SKU关系ID' AFTER `sku_name`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `order_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '下单单位ID' AFTER `units`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `order_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '下单单位名称' AFTER `order_unit_id`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `order_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '下单数量' AFTER `order_unit_name`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `base_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '库存基准单位ID' AFTER `order_qty`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `base_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '库存基准单位名称' AFTER `base_unit_id`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `conversion_rate` decimal(18,6) NOT NULL DEFAULT 1.000000 COMMENT '换算快照：1下单单位=conversion_rate基准单位' AFTER `base_unit_name`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `conversion_source_type` varchar(50) NOT NULL DEFAULT '' COMMENT '换算来源类型' AFTER `conversion_rate`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `conversion_effective_date` date DEFAULT NULL COMMENT '换算生效日期快照' AFTER `conversion_source_type`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `expected_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '预期基准数量' AFTER `conversion_effective_date`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `actual_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '实际基准数量' AFTER `expected_base_qty`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `loss_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '损耗基准数量' AFTER `actual_base_qty`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `loss_rate` decimal(12,6) NOT NULL DEFAULT 0.000000 COMMENT '损耗率' AFTER `loss_base_qty`;

ALTER TABLE `la_order_goods`
  ADD COLUMN `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '批次ID' AFTER `loss_rate`;

ALTER TABLE `la_order_goods`
  ADD KEY `idx_tenant_sku_order` (`tenant_id`, `sku_id`, `order_id`, `order_type`);

ALTER TABLE `la_order_goods`
  ADD KEY `idx_tenant_supplier_relation` (`tenant_id`, `supplier_relation_id`);

ALTER TABLE `la_stock_flow`
  ADD COLUMN `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID' AFTER `goods_id`;

ALTER TABLE `la_stock_flow`
  ADD COLUMN `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '批次ID' AFTER `sku_id`;

ALTER TABLE `la_stock_flow`
  ADD KEY `idx_tenant_sku` (`tenant_id`, `sku_id`);

ALTER TABLE `la_stock_flow`
  ADD KEY `idx_tenant_batch` (`tenant_id`, `batch_id`);

CREATE TABLE IF NOT EXISTS `la_purchase_arrival` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '到货单ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `arrival_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '到货单号',
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '进货单ID',
  `supply_order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '进货单号快照',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `supplier_name` varchar(100) NOT NULL DEFAULT '' COMMENT '供应商名称快照',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `arrival_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '到货时间',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_supply_order` (`tenant_id`, `supply_order_id`),
  KEY `idx_tenant_supplier_time` (`tenant_id`, `supplier_id`, `arrival_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购到货单表';

CREATE TABLE IF NOT EXISTS `la_purchase_arrival_detail` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '到货明细ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `arrival_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '到货单ID',
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '进货单ID',
  `order_goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '进货明细ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '批次ID',
  `order_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '下单数量',
  `order_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '下单单位快照',
  `expected_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '预期基准数量',
  `actual_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '实际基准数量',
  `loss_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '损耗基准数量',
  `loss_rate` decimal(12,6) NOT NULL DEFAULT 0.000000 COMMENT '损耗率',
  `conversion_rate` decimal(18,6) NOT NULL DEFAULT 1.000000 COMMENT '换算比例快照',
  `conversion_source_type` varchar(50) NOT NULL DEFAULT '' COMMENT '换算来源类型',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_arrival` (`tenant_id`, `arrival_id`),
  KEY `idx_tenant_order_goods` (`tenant_id`, `order_goods_id`),
  KEY `idx_tenant_supplier_sku` (`tenant_id`, `supplier_id`, `sku_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购到货明细表';

CREATE TABLE IF NOT EXISTS `la_goods_batch` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '批次ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `batch_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '批次号',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '进货单ID',
  `order_goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '进货明细ID',
  `base_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '基准单位ID',
  `base_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '基准单位名称',
  `expected_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '预期基准数量',
  `actual_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '实际基准数量',
  `loss_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '损耗基准数量',
  `conversion_snapshot` json DEFAULT NULL COMMENT '换算快照',
  `production_date` date DEFAULT NULL COMMENT '生产/捕捞日期',
  `arrival_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '到货时间',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_batch_sn` (`tenant_id`, `batch_sn`),
  KEY `idx_tenant_goods_sku` (`tenant_id`, `goods_id`, `sku_id`),
  KEY `idx_tenant_supplier` (`tenant_id`, `supplier_id`),
  KEY `idx_tenant_supply` (`tenant_id`, `supply_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品批次表';

CREATE TABLE IF NOT EXISTS `la_goods_loss_record` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '损耗记录ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `loss_type` varchar(50) NOT NULL DEFAULT 'arrival_shortage' COMMENT '损耗类型',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '批次ID',
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '进货单ID',
  `order_goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '进货明细ID',
  `expected_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '预期基准数量',
  `actual_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '实际基准数量',
  `loss_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '损耗基准数量',
  `loss_rate` decimal(12,6) NOT NULL DEFAULT 0.000000 COMMENT '损耗率',
  `reason` varchar(500) NOT NULL DEFAULT '' COMMENT '原因',
  `record_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '记录时间',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_supplier_sku_time` (`tenant_id`, `supplier_id`, `sku_id`, `record_time`),
  KEY `idx_tenant_goods_sku_time` (`tenant_id`, `goods_id`, `sku_id`, `record_time`),
  KEY `idx_tenant_order_goods` (`tenant_id`, `order_goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品损耗记录表';
