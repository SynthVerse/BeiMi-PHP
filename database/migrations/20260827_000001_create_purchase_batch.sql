-- 采购批次父记录与供应商进货子单关联。库存、到货、应付及采购退货仍归属 supply_order。

CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_batch` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '门店租户ID',
  `batch_no` varchar(64) NOT NULL DEFAULT '' COMMENT '采购批次号',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_name` varchar(100) NOT NULL DEFAULT '',
  `datetimesingle` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '采购日期',
  `remarks` varchar(500) NOT NULL DEFAULT '',
  `status` varchar(24) NOT NULL DEFAULT 'submitted',
  `supplier_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `line_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `total_amount` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '只读采购金额汇总，非付款登记',
  `idempotency_key` varchar(64) NOT NULL DEFAULT '',
  `request_fingerprint` char(64) NOT NULL DEFAULT '',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_purchase_batch_no` (`tenant_id`,`batch_no`),
  UNIQUE KEY `uk_tenant_purchase_batch_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_purchase_batch_date` (`tenant_id`,`datetimesingle`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='多供应商采购批次';

CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_batch_supply_order` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `purchase_batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supplier_name` varchar(100) NOT NULL DEFAULT '',
  `line_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_money` decimal(18,2) NOT NULL DEFAULT 0.00,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_purchase_batch_supply_order` (`tenant_id`,`purchase_batch_id`,`supply_order_id`),
  UNIQUE KEY `uk_tenant_purchase_batch_supplier` (`tenant_id`,`purchase_batch_id`,`supplier_id`),
  KEY `idx_tenant_purchase_batch_link` (`tenant_id`,`purchase_batch_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购批次供应商进货子单关联';

SET @purchase_batch_column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}supply_order' AND COLUMN_NAME = 'purchase_batch_id'
);
SET @purchase_batch_column_sql := IF(
  @purchase_batch_column_exists = 0,
  'ALTER TABLE `{{prefix}}supply_order` ADD COLUMN `purchase_batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT ''采购批次ID'' AFTER `tenant_id`',
  'SELECT 1'
);
PREPARE purchase_batch_column_stmt FROM @purchase_batch_column_sql;
EXECUTE purchase_batch_column_stmt;
DEALLOCATE PREPARE purchase_batch_column_stmt;

SET @purchase_batch_index_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}supply_order'
    AND INDEX_NAME = 'idx_tenant_purchase_batch'
);
SET @purchase_batch_index_sql := IF(
  @purchase_batch_index_exists = 0,
  'ALTER TABLE `{{prefix}}supply_order` ADD KEY `idx_tenant_purchase_batch` (`tenant_id`,`purchase_batch_id`,`id`)',
  'SELECT 1'
);
PREPARE purchase_batch_index_stmt FROM @purchase_batch_index_sql;
EXECUTE purchase_batch_index_stmt;
DEALLOCATE PREPARE purchase_batch_index_stmt;
