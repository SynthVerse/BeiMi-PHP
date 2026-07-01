-- P0 purchase return backend schema.
-- Replace {{prefix}} with the configured database prefix before applying.
-- Idempotency note: CREATE TABLE uses IF NOT EXISTS. Before running the ALTER TABLE below,
-- check whether {{prefix}}supply_order.return_status already exists in the target database.
-- MySQL versions differ on ADD COLUMN IF NOT EXISTS support, so this script intentionally
-- keeps compatible ALTER syntax and requires the pre-check instead of using version-specific SQL.

CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_return_order` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '采购退货单ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '采购退货单号',
  `original_supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '原进货单ID',
  `original_order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '原进货单号',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商ID',
  `supplier_name` varchar(100) NOT NULL DEFAULT '' COMMENT '供应商名称快照',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `order_money` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '退货金额',
  `return_reason` varchar(500) NOT NULL DEFAULT '' COMMENT '退货原因',
  `datetimesingle` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单据日期',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
  `remarks` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建管理员ID',
  `idempotent_key` varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_order_sn` (`tenant_id`, `order_sn`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_original_supply_order` (`tenant_id`, `original_supply_order_id`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_datetimesingle` (`datetimesingle`),
  KEY `idx_tenant_idempotent` (`tenant_id`, `idempotent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购退货单表';

CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_return_order_lists` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '采购退货明细ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `purchase_return_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '采购退货单ID',
  `original_supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '原进货单ID',
  `original_supply_order_list_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '原进货单明细ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID',
  `goods_name` varchar(255) NOT NULL DEFAULT '' COMMENT '商品名称快照',
  `unit_name` varchar(64) NOT NULL DEFAULT '' COMMENT '单位快照',
  `original_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '原进货数量',
  `return_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '本次退货数量',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '单价',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_return_order` (`tenant_id`, `purchase_return_order_id`),
  KEY `idx_tenant_original_order` (`tenant_id`, `original_supply_order_id`),
  KEY `idx_tenant_original_line` (`tenant_id`, `original_supply_order_list_id`),
  KEY `idx_tenant_goods_sku` (`tenant_id`, `goods_id`, `sku_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购退货明细表';

-- Execute only when `return_status` is absent from `{{prefix}}supply_order`.
ALTER TABLE `{{prefix}}supply_order`
  ADD COLUMN `return_status` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '退货状态：0未退货，1部分退货，2已退货' AFTER `status`;
