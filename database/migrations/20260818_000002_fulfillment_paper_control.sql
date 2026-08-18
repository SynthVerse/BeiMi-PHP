-- 纸质工票副本、作废通知、最终称重与履约变更审计。

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_paper_copy` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `print_log_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `control_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `print_type` varchar(24) NOT NULL DEFAULT 'task' COMMENT 'task/void_notice/change_notice',
  `copy_no` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `ticket_no` varchar(40) NOT NULL DEFAULT '',
  `content_version` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `content_hash` char(64) NOT NULL DEFAULT '',
  `paper_status` varchar(32) NOT NULL DEFAULT 'not_issued',
  `printed_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `accounted_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `accounted_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `account_reason` varchar(32) NOT NULL DEFAULT '',
  `account_note` varchar(500) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_fulfillment_paper_print_log` (`tenant_id`,`print_log_id`),
  KEY `idx_tenant_fulfillment_paper_task` (`tenant_id`,`task_id`,`paper_status`,`id`),
  KEY `idx_tenant_fulfillment_paper_control` (`tenant_id`,`control_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每次成功或失败打印对应的纸票副本身份';

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_ticket_control` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `item_change_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `print_log_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `action_type` varchar(24) NOT NULL DEFAULT 'change' COMMENT 'change/void',
  `action_key` varchar(160) NOT NULL DEFAULT '',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `before_snapshot` longtext DEFAULT NULL,
  `after_snapshot` longtext DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending_recovery' COMMENT 'pending_recovery/notice_required/notice_printing/closed',
  `resolution` varchar(24) NOT NULL DEFAULT '',
  `resolution_note` varchar(500) NOT NULL DEFAULT '',
  `resolved_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `resolved_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `notice_print_log_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_fulfillment_ticket_control_key` (`tenant_id`,`action_key`),
  KEY `idx_tenant_fulfillment_ticket_control_change` (`tenant_id`,`item_change_id`,`id`),
  KEY `idx_tenant_fulfillment_ticket_control_report` (`tenant_id`,`report_id`,`status`,`id`),
  KEY `idx_tenant_fulfillment_ticket_control_task` (`tenant_id`,`task_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='已打印工票内容变化后的作废与通知控制';

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_item_change` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `action_type` varchar(24) NOT NULL DEFAULT '',
  `idempotency_key` varchar(96) NOT NULL DEFAULT '',
  `request_fingerprint` char(64) NOT NULL DEFAULT '',
  `quantity` decimal(18,2) NOT NULL DEFAULT 0.00,
  `processed_quantity` decimal(18,2) NOT NULL DEFAULT 0.00,
  `disposition` varchar(32) NOT NULL DEFAULT '',
  `reason_code` varchar(32) NOT NULL DEFAULT '',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `before_data` longtext DEFAULT NULL,
  `after_data` longtext DEFAULT NULL,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_fulfillment_item_change_idem` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_fulfillment_item_change_item` (`tenant_id`,`report_item_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='处理后减量与未交货的追加式业务事实';

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_ticket_control' AND COLUMN_NAME='item_change_id');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_ticket_control` ADD COLUMN `item_change_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `report_item_id`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

SET @fulfillment_index_exists := (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_ticket_control' AND INDEX_NAME='idx_tenant_fulfillment_ticket_control_change');
SET @fulfillment_index_sql := IF(@fulfillment_index_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_ticket_control` ADD KEY `idx_tenant_fulfillment_ticket_control_change` (`tenant_id`,`item_change_id`,`id`)', 'SELECT 1');
PREPARE fulfillment_index_stmt FROM @fulfillment_index_sql;
EXECUTE fulfillment_index_stmt;
DEALLOCATE PREPARE fulfillment_index_stmt;

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_task' AND COLUMN_NAME='content_version');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_task` ADD COLUMN `content_version` int(11) UNSIGNED NOT NULL DEFAULT 1 AFTER `ticket_no`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_task' AND COLUMN_NAME='content_hash');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_task` ADD COLUMN `content_hash` char(64) NOT NULL DEFAULT '''' AFTER `content_version`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_task' AND COLUMN_NAME='process_weight');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_task` ADD COLUMN `process_weight` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `actual_weight`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_task' AND COLUMN_NAME='process_name_snapshot');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_task` ADD COLUMN `process_name_snapshot` varchar(100) NOT NULL DEFAULT '''' AFTER `process_id`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

UPDATE `{{prefix}}fulfillment_task` AS task
INNER JOIN `{{prefix}}work_process` AS process ON process.tenant_id=task.tenant_id AND process.id=task.process_id
SET task.process_name_snapshot=process.name
WHERE task.process_name_snapshot='';

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='final_actual_weight');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `final_actual_weight` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `fulfilled_base_qty`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='final_weight_task_id');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `final_weight_task_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `final_actual_weight`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='fulfillment_status');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `fulfillment_status` varchar(32) NOT NULL DEFAULT ''pending'' AFTER `final_weight_task_id`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='undelivered_reason_code');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `undelivered_reason_code` varchar(32) NOT NULL DEFAULT '''' AFTER `fulfillment_status`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='undelivered_reason');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `undelivered_reason` varchar(500) NOT NULL DEFAULT '''' AFTER `undelivered_reason_code`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;

SET @fulfillment_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='reduction_total_qty');
SET @fulfillment_column_sql := IF(@fulfillment_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `reduction_total_qty` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `undelivered_reason`', 'SELECT 1');
PREPARE fulfillment_column_stmt FROM @fulfillment_column_sql;
EXECUTE fulfillment_column_stmt;
DEALLOCATE PREPARE fulfillment_column_stmt;
