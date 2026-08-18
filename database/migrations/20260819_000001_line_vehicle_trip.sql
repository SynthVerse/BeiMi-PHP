-- 固定线车班次、门店送站趟次、纸质装车清单与实际线车交接。

CREATE TABLE IF NOT EXISTS `{{prefix}}line_vehicle_schedule` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(120) NOT NULL DEFAULT '',
  `handoff_location` varchar(255) NOT NULL DEFAULT '',
  `departure_time` char(5) NOT NULL DEFAULT '00:00' COMMENT '每日线车发车时刻 HH:MM',
  `buffer_minutes` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `version` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_line_vehicle_schedule_name` (`tenant_id`,`name`),
  KEY `idx_tenant_line_vehicle_schedule_enabled` (`tenant_id`,`is_enabled`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='固定线车班次配置';

CREATE TABLE IF NOT EXISTS `{{prefix}}line_vehicle_trip` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `trip_no` varchar(64) NOT NULL DEFAULT '',
  `trip_date` date NOT NULL,
  `planned_store_departure_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `actual_store_departure_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'planned' COMMENT 'planned/loading/departed/completed/closed',
  `idempotency_key` varchar(96) NOT NULL DEFAULT '',
  `request_fingerprint` char(64) NOT NULL DEFAULT '',
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_line_vehicle_trip_no` (`tenant_id`,`trip_no`),
  UNIQUE KEY `uk_tenant_line_vehicle_trip_idem` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_line_vehicle_trip_date` (`tenant_id`,`trip_date`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店送站趟次';

CREATE TABLE IF NOT EXISTS `{{prefix}}line_vehicle_trip_report` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `trip_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `schedule_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `active_report_id` int(11) UNSIGNED DEFAULT NULL COMMENT '未交接时占用的报货单身份，完成后置空以允许审计化改派',
  `delivery_task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `line_name_snapshot` varchar(120) NOT NULL DEFAULT '',
  `handoff_location_snapshot` varchar(255) NOT NULL DEFAULT '',
  `line_departure_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `handoff_deadline` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `main_customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `main_customer_name` varchar(200) NOT NULL DEFAULT '',
  `delivery_customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_customer_name` varchar(200) NOT NULL DEFAULT '',
  `report_sn` varchar(64) NOT NULL DEFAULT '',
  `expected_package_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `actual_packed_package_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `actual_loaded_package_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `actual_handoff_package_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `packed_checked` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `loaded_checked` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `handoff_checked` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `actual_handoff_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `packed_exception_note` varchar(500) NOT NULL DEFAULT '',
  `loaded_exception_note` varchar(500) NOT NULL DEFAULT '',
  `handoff_exception_note` varchar(500) NOT NULL DEFAULT '',
  `package_exception_note` varchar(500) NOT NULL DEFAULT '',
  `reroute_method` varchar(32) NOT NULL DEFAULT '',
  `reroute_reason` varchar(500) NOT NULL DEFAULT '',
  `rerouted_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'planned' COMMENT 'planned/loading/handed_over/rerouted',
  `delivery_event_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_line_vehicle_trip_report` (`tenant_id`,`trip_id`,`report_id`),
  UNIQUE KEY `uk_tenant_line_vehicle_active_report` (`tenant_id`,`active_report_id`),
  KEY `idx_tenant_line_vehicle_report_source` (`tenant_id`,`report_id`,`status`,`id`),
  KEY `idx_tenant_line_vehicle_report_schedule` (`tenant_id`,`schedule_id`,`handoff_deadline`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='送站趟次按报货单的装车与线车交接事实';

SET @line_vehicle_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_event' AND COLUMN_NAME='trip_id');
SET @line_vehicle_column_sql := IF(@line_vehicle_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_event` ADD COLUMN `trip_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `task_id`', 'SELECT 1');
PREPARE line_vehicle_column_stmt FROM @line_vehicle_column_sql;
EXECUTE line_vehicle_column_stmt;
DEALLOCATE PREPARE line_vehicle_column_stmt;

SET @line_vehicle_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_event' AND COLUMN_NAME='trip_report_id');
SET @line_vehicle_column_sql := IF(@line_vehicle_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_event` ADD COLUMN `trip_report_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `trip_id`', 'SELECT 1');
PREPARE line_vehicle_column_stmt FROM @line_vehicle_column_sql;
EXECUTE line_vehicle_column_stmt;
DEALLOCATE PREPARE line_vehicle_column_stmt;

SET @line_vehicle_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_event' AND COLUMN_NAME='line_schedule_id');
SET @line_vehicle_column_sql := IF(@line_vehicle_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_event` ADD COLUMN `line_schedule_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `trip_report_id`', 'SELECT 1');
PREPARE line_vehicle_column_stmt FROM @line_vehicle_column_sql;
EXECUTE line_vehicle_column_stmt;
DEALLOCATE PREPARE line_vehicle_column_stmt;

SET @line_vehicle_index_exists := (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_event' AND INDEX_NAME='idx_tenant_delivery_event_trip');
SET @line_vehicle_index_sql := IF(@line_vehicle_index_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_event` ADD KEY `idx_tenant_delivery_event_trip` (`tenant_id`,`trip_id`,`trip_report_id`)', 'SELECT 1');
PREPARE line_vehicle_index_stmt FROM @line_vehicle_index_sql;
EXECUTE line_vehicle_index_stmt;
DEALLOCATE PREPARE line_vehicle_index_stmt;
