-- 第三方司机、分批交付、运输损耗、明确未交货余量与改派返回门店事实。

CREATE TABLE IF NOT EXISTS `{{prefix}}third_party_driver` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(120) NOT NULL DEFAULT '',
  `mobile` varchar(32) NOT NULL DEFAULT '',
  `platform` varchar(80) NOT NULL DEFAULT '',
  `vehicle_no` varchar(32) NOT NULL DEFAULT '',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `version` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_third_party_driver_status` (`tenant_id`,`is_enabled`,`name`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户登记的第三方即时配送司机';

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_delivery_loss` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_event_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `loss_weight` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `loss_package_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reason` varchar(500) NOT NULL DEFAULT '',
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `occurred_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_delivery_loss_item` (`tenant_id`,`delivery_item_id`),
  KEY `idx_tenant_delivery_loss_report` (`tenant_id`,`report_id`,`report_item_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='运输或交接阶段独立库存损耗事实';

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_delivery_remainder` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_event_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `quantity` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `action` varchar(24) NOT NULL DEFAULT 'undelivered',
  `reason_code` varchar(32) NOT NULL DEFAULT '',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `action_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_delivery_remainder_item` (`tenant_id`,`delivery_item_id`),
  KEY `idx_tenant_delivery_remainder_report` (`tenant_id`,`report_id`,`report_item_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='部分交付后明确不再交付的余量事实';

CREATE TABLE IF NOT EXISTS `{{prefix}}line_vehicle_return_event` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `trip_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `trip_report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'returned_to_pending',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `idempotency_key` varchar(96) NOT NULL DEFAULT '',
  `request_fingerprint` char(64) NOT NULL DEFAULT '',
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `returned_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_line_return_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_line_return_report` (`tenant_id`,`report_id`,`trip_report_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='错过线车后返回门店并恢复待配送的追加事实';

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_event' AND COLUMN_NAME='driver_id');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_event` ADD COLUMN `driver_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `line_schedule_id`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_event' AND COLUMN_NAME='driver_snapshot');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_event` ADD COLUMN `driver_snapshot` longtext DEFAULT NULL AFTER `driver_id`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_event' AND COLUMN_NAME='actual_handoff_time');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_event` ADD COLUMN `actual_handoff_time` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `driver_snapshot`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_event' AND COLUMN_NAME='delivery_outcome');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_event` ADD COLUMN `delivery_outcome` varchar(32) NOT NULL DEFAULT ''complete'' AFTER `actual_handoff_time`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_item' AND COLUMN_NAME='loss_weight');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_item` ADD COLUMN `loss_weight` decimal(18,4) NOT NULL DEFAULT 0.0000 AFTER `actual_delivery_weight`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_item' AND COLUMN_NAME='undelivered_weight');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_item` ADD COLUMN `undelivered_weight` decimal(18,4) NOT NULL DEFAULT 0.0000 AFTER `loss_weight`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_item' AND COLUMN_NAME='remaining_action');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_item` ADD COLUMN `remaining_action` varchar(24) NOT NULL DEFAULT ''none'' AFTER `undelivered_weight`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='delivery_loss_total_qty');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `delivery_loss_total_qty` decimal(18,4) NOT NULL DEFAULT 0.0000 AFTER `fulfilled_base_qty`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='undelivered_total_qty');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `undelivered_total_qty` decimal(18,4) NOT NULL DEFAULT 0.0000 AFTER `delivery_loss_total_qty`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='has_partial_delivery');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `has_partial_delivery` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `undelivered_total_qty`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}line_vehicle_trip_report' AND COLUMN_NAME='return_event_id');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}line_vehicle_trip_report` ADD COLUMN `return_event_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `delivery_event_id`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}line_vehicle_trip_report' AND COLUMN_NAME='return_status');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}line_vehicle_trip_report` ADD COLUMN `return_status` varchar(32) NOT NULL DEFAULT '''' AFTER `return_event_id`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}line_vehicle_trip_report' AND COLUMN_NAME='return_reason');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}line_vehicle_trip_report` ADD COLUMN `return_reason` varchar(500) NOT NULL DEFAULT '''' AFTER `return_status`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;

SET @delivery_variant_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}line_vehicle_trip_report' AND COLUMN_NAME='returned_to_store_time');
SET @delivery_variant_column_sql := IF(@delivery_variant_column_exists=0, 'ALTER TABLE `{{prefix}}line_vehicle_trip_report` ADD COLUMN `returned_to_store_time` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `return_reason`', 'SELECT 1');
PREPARE delivery_variant_column_stmt FROM @delivery_variant_column_sql;
EXECUTE delivery_variant_column_stmt;
DEALLOCATE PREPARE delivery_variant_column_stmt;
