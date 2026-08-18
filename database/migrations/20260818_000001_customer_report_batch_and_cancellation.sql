-- 客户报货批次、补报归属与取消审计事实。

CREATE TABLE IF NOT EXISTS `{{prefix}}customer_report_batch` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '门店租户ID',
  `batch_no` varchar(64) NOT NULL DEFAULT '',
  `delivery_date` date NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'open' COMMENT 'open/processing/ended',
  `idempotency_key` varchar(96) NOT NULL DEFAULT '',
  `request_fingerprint` char(64) NOT NULL DEFAULT '',
  `version` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `processing_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `processing_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `ended_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `ended_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_customer_report_batch_no` (`tenant_id`,`batch_no`),
  UNIQUE KEY `uk_tenant_customer_report_batch_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_customer_report_batch_date_status` (`tenant_id`,`delivery_date`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店客户报货批次';

SET @customer_report_column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}customer_report' AND COLUMN_NAME = 'batch_id'
);
SET @customer_report_column_sql := IF(
  @customer_report_column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report` ADD COLUMN `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT ''报货批次ID'' AFTER `tenant_id`',
  'SELECT 1'
);
PREPARE customer_report_column_stmt FROM @customer_report_column_sql;
EXECUTE customer_report_column_stmt;
DEALLOCATE PREPARE customer_report_column_stmt;

SET @customer_report_column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}customer_report' AND COLUMN_NAME = 'supplement_for_report_id'
);
SET @customer_report_column_sql := IF(
  @customer_report_column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report` ADD COLUMN `supplement_for_report_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT ''补报对应的原报货单ID'' AFTER `batch_id`',
  'SELECT 1'
);
PREPARE customer_report_column_stmt FROM @customer_report_column_sql;
EXECUTE customer_report_column_stmt;
DEALLOCATE PREPARE customer_report_column_stmt;

SET @customer_report_column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}customer_report' AND COLUMN_NAME = 'cancellation_reason'
);
SET @customer_report_column_sql := IF(
  @customer_report_column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report` ADD COLUMN `cancellation_reason` varchar(255) NOT NULL DEFAULT '''' AFTER `submitted_time`',
  'SELECT 1'
);
PREPARE customer_report_column_stmt FROM @customer_report_column_sql;
EXECUTE customer_report_column_stmt;
DEALLOCATE PREPARE customer_report_column_stmt;

SET @customer_report_column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}customer_report' AND COLUMN_NAME = 'cancelled_by'
);
SET @customer_report_column_sql := IF(
  @customer_report_column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report` ADD COLUMN `cancelled_by` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `cancellation_reason`',
  'SELECT 1'
);
PREPARE customer_report_column_stmt FROM @customer_report_column_sql;
EXECUTE customer_report_column_stmt;
DEALLOCATE PREPARE customer_report_column_stmt;

SET @customer_report_column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}customer_report' AND COLUMN_NAME = 'cancelled_time'
);
SET @customer_report_column_sql := IF(
  @customer_report_column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report` ADD COLUMN `cancelled_time` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `cancelled_by`',
  'SELECT 1'
);
PREPARE customer_report_column_stmt FROM @customer_report_column_sql;
EXECUTE customer_report_column_stmt;
DEALLOCATE PREPARE customer_report_column_stmt;

SET @customer_report_index_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}customer_report'
    AND INDEX_NAME = 'idx_tenant_customer_report_batch'
);
SET @customer_report_index_sql := IF(
  @customer_report_index_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report` ADD KEY `idx_tenant_customer_report_batch` (`tenant_id`,`batch_id`,`id`)',
  'SELECT 1'
);
PREPARE customer_report_index_stmt FROM @customer_report_index_sql;
EXECUTE customer_report_index_stmt;
DEALLOCATE PREPARE customer_report_index_stmt;

SET @customer_report_index_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}customer_report'
    AND INDEX_NAME = 'idx_tenant_customer_report_supplement_source'
);
SET @customer_report_index_sql := IF(
  @customer_report_index_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report` ADD KEY `idx_tenant_customer_report_supplement_source` (`tenant_id`,`supplement_for_report_id`,`id`)',
  'SELECT 1'
);
PREPARE customer_report_index_stmt FROM @customer_report_index_sql;
EXECUTE customer_report_index_stmt;
DEALLOCATE PREPARE customer_report_index_stmt;
