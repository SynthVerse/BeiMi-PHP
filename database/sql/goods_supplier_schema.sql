-- 商品-供应商关联能力补充脚本
-- 适用于当前 JXC 表结构，默认表前缀为 la_。
-- 执行前请先确认 .env 中 DATABASE.PREFIX 与当前库表前缀一致。

ALTER TABLE `la_goods`
  ADD COLUMN `primary_supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '默认供应商ID' AFTER `category_id`,
  ADD KEY `idx_tenant_primary_supplier` (`tenant_id`, `primary_supplier_id`);

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

ALTER TABLE `la_supply_order`
  ADD KEY `idx_tenant_supplier_date` (`tenant_id`, `supplier_id`, `datetimesingle`);

ALTER TABLE `la_order_goods`
  ADD KEY `idx_tenant_goods_order` (`tenant_id`, `goods_id`, `order_id`, `order_type`);
