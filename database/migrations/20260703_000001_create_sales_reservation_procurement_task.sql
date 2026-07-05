-- P0 sales reservation and logical inventory reservation schema.
-- Database prefix applied: la_.

CREATE TABLE IF NOT EXISTS `la_sales_reservation` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '销售预定ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `sn` varchar(64) NOT NULL DEFAULT '' COMMENT '销售预定单号',
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户ID',
  `customer_name` varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称快照',
  `status` varchar(32) NOT NULL DEFAULT 'draft' COMMENT '状态：draft/shortage/ready/gap_closed/converted/cancelled',
  `total_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '总数量',
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '已预留数量',
  `shortage_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '缺口数量',
  `converted_sales_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '转换后的销售单ID',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `update_by` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新人',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sn` (`tenant_id`, `sn`),
  KEY `idx_tenant_status` (`tenant_id`, `status`),
  KEY `idx_tenant_customer` (`tenant_id`, `customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC销售预定表';

CREATE TABLE IF NOT EXISTS `la_sales_reservation_item` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '销售预定明细ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售预定ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `goods_name` varchar(200) NOT NULL DEFAULT '' COMMENT '商品名称快照',
  `goods_code` varchar(100) NOT NULL DEFAULT '' COMMENT '商品编码快照',
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单位ID',
  `unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '单位名称',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID，仅展示/未来扩展',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID，仅展示/未来扩展',
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '规格ID，仅展示/未来扩展',
  `num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '需求数量',
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '预留数量',
  `shortage_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '缺口数量',
  `status` varchar(32) NOT NULL DEFAULT 'reserved' COMMENT '状态：reserved/shortage/gap_closed/converted/released',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_reservation` (`tenant_id`, `reservation_id`),
  KEY `idx_tenant_goods` (`tenant_id`, `goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC销售预定明细表';

CREATE TABLE IF NOT EXISTS `la_inventory_reservation` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '库存逻辑预留ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售预定ID',
  `reservation_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售预定明细ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID，仅展示/未来扩展',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SKU ID，仅展示/未来扩展',
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '规格ID，仅展示/未来扩展',
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '预留数量',
  `consumed_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '已消耗数量',
  `released_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '已释放数量',
  `status` varchar(32) NOT NULL DEFAULT 'active' COMMENT '状态：active/consumed/released',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_goods_status` (`tenant_id`, `goods_id`, `status`),
  KEY `idx_tenant_reservation` (`tenant_id`, `reservation_id`),
  KEY `idx_tenant_item` (`tenant_id`, `reservation_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC库存逻辑预留表';
