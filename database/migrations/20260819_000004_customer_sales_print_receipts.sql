-- 客户销售单打印采用服务端准备/回执闭环；成功副本按销售单累计，不随版本或设备重置。

CREATE TABLE IF NOT EXISTS `{{prefix}}customer_sales_print_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `version` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `copy_no` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `idempotency_key` varchar(96) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `error_message` varchar(255) NOT NULL DEFAULT '',
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `printed_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_customer_sales_print_key` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_customer_sales_order` (`tenant_id`,`order_id`,`status`,`id`),
  KEY `idx_tenant_customer_sales_pending` (`tenant_id`,`status`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户销售单纸质副本打印准备与回执';

SET @customer_sales_print_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_sales_print_log' AND COLUMN_NAME='idempotency_key');
SET @customer_sales_print_column_sql := IF(@customer_sales_print_column_exists=0, 'ALTER TABLE `{{prefix}}customer_sales_print_log` ADD COLUMN `idempotency_key` varchar(96) NOT NULL DEFAULT '''' AFTER `copy_no`', 'SELECT 1');
PREPARE customer_sales_print_column_stmt FROM @customer_sales_print_column_sql;
EXECUTE customer_sales_print_column_stmt;
DEALLOCATE PREPARE customer_sales_print_column_stmt;

SET @customer_sales_print_index_exists := (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_sales_print_log' AND INDEX_NAME='uk_tenant_customer_sales_print_key');
SET @customer_sales_print_index_sql := IF(@customer_sales_print_index_exists=0, 'ALTER TABLE `{{prefix}}customer_sales_print_log` ADD UNIQUE KEY `uk_tenant_customer_sales_print_key` (`tenant_id`,`idempotency_key`)', 'SELECT 1');
PREPARE customer_sales_print_index_stmt FROM @customer_sales_print_index_sql;
EXECUTE customer_sales_print_index_stmt;
DEALLOCATE PREPARE customer_sales_print_index_stmt;
