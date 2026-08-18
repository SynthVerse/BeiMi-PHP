-- 自配送实际交付出库、销售单待结算身份与真负库存追加式归因。

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_delivery_event` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_method` varchar(24) NOT NULL DEFAULT 'self_delivery',
  `event_type` varchar(32) NOT NULL DEFAULT 'customer_handoff',
  `status` varchar(24) NOT NULL DEFAULT 'completed',
  `idempotency_key` varchar(96) NOT NULL DEFAULT '',
  `request_fingerprint` char(64) NOT NULL DEFAULT '',
  `handoff_note` varchar(500) NOT NULL DEFAULT '',
  `exception_reason` varchar(500) NOT NULL DEFAULT '',
  `second_confirmed` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivered_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_delivery_event_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_delivery_event_report` (`tenant_id`,`report_id`,`id`),
  KEY `idx_tenant_delivery_event_task` (`tenant_id`,`task_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配送真实交付事件（门店车辆离店不是交付）';

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_delivery_item` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_event_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sales_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `actual_delivery_weight` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `reservation_consumed_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `reservation_released_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `negative_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_delivery_event_item` (`tenant_id`,`delivery_event_id`,`report_item_id`),
  KEY `idx_tenant_delivery_item_report` (`tenant_id`,`report_id`,`report_item_id`),
  KEY `idx_tenant_delivery_item_order` (`tenant_id`,`sales_order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='交付事件按报货行的实际出库事实';

CREATE TABLE IF NOT EXISTS `{{prefix}}negative_inventory_attribution` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_event_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sales_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `negative_qty` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '不可覆盖的原始负数来源数量',
  `negative_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `remaining_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `reason` varchar(500) NOT NULL DEFAULT '最终实重超过可用库存',
  `threshold_explanation` varchar(500) NOT NULL DEFAULT '',
  `threshold_confirmed` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `resolution_status` varchar(32) NOT NULL DEFAULT 'open' COMMENT 'open/waiting_inbound/retained/resolved',
  `cost_status` varchar(24) NOT NULL DEFAULT 'confirmed' COMMENT 'confirmed/pending',
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `occurred_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `resolved_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_negative_delivery_item` (`tenant_id`,`delivery_item_id`),
  KEY `idx_tenant_negative_source` (`tenant_id`,`sales_order_id`,`report_id`,`report_item_id`),
  KEY `idx_tenant_negative_balance` (`tenant_id`,`warehouse_id`,`sku_id`,`resolution_status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='真负库存不可变来源归因';

CREATE TABLE IF NOT EXISTS `{{prefix}}negative_inventory_todo` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `attribution_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'open',
  `severity` varchar(16) NOT NULL DEFAULT 'red',
  `assignee_scope` varchar(32) NOT NULL DEFAULT 'highest_privilege',
  `resolved_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `resolved_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_negative_todo_source` (`tenant_id`,`attribution_id`),
  KEY `idx_tenant_negative_todo_status` (`tenant_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='最高权限真负库存异常待办';

CREATE TABLE IF NOT EXISTS `{{prefix}}negative_inventory_action` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `attribution_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `action_type` varchar(32) NOT NULL DEFAULT '',
  `quantity` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(500) NOT NULL DEFAULT '',
  `cost_status` varchar(24) NOT NULL DEFAULT 'confirmed',
  `idempotency_key` varchar(96) NOT NULL DEFAULT '',
  `request_fingerprint` char(64) NOT NULL DEFAULT '',
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `action_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `before_snapshot` longtext DEFAULT NULL,
  `after_snapshot` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_negative_action_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_negative_action_source` (`tenant_id`,`attribution_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='负库存等待、补录、核销和保留的追加式动作';

CREATE TABLE IF NOT EXISTS `{{prefix}}negative_inventory_setting` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `quantity_threshold` decimal(18,4) NOT NULL DEFAULT 999999999.0000,
  `amount_threshold` decimal(18,2) NOT NULL DEFAULT 999999999.00,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_negative_inventory_setting` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='真负库存二次确认阈值';

SET @delivery_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='settlement_status');
SET @delivery_column_sql := IF(@delivery_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `settlement_status` varchar(32) NOT NULL DEFAULT ''formal'' AFTER `source_version`', 'SELECT 1');
PREPARE delivery_column_stmt FROM @delivery_column_sql;
EXECUTE delivery_column_stmt;
DEALLOCATE PREPARE delivery_column_stmt;

SET @delivery_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='cost_status');
SET @delivery_column_sql := IF(@delivery_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `cost_status` varchar(24) NOT NULL DEFAULT ''confirmed'' AFTER `settlement_status`', 'SELECT 1');
PREPARE delivery_column_stmt FROM @delivery_column_sql;
EXECUTE delivery_column_stmt;
DEALLOCATE PREPARE delivery_column_stmt;

SET @delivery_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='profit_status');
SET @delivery_column_sql := IF(@delivery_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `profit_status` varchar(24) NOT NULL DEFAULT ''accurate'' AFTER `cost_status`', 'SELECT 1');
PREPARE delivery_column_stmt FROM @delivery_column_sql;
EXECUTE delivery_column_stmt;
DEALLOCATE PREPARE delivery_column_stmt;

SET @delivery_check_exists := (SELECT COUNT(1) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}warehouse_sku_balance' AND CONSTRAINT_NAME='chk_warehouse_sku_on_hand_non_negative');
SET @delivery_check_sql := IF(@delivery_check_exists>0, 'ALTER TABLE `{{prefix}}warehouse_sku_balance` DROP CONSTRAINT `chk_warehouse_sku_on_hand_non_negative`', 'SELECT 1');
PREPARE delivery_check_stmt FROM @delivery_check_sql;
EXECUTE delivery_check_stmt;
DEALLOCATE PREPARE delivery_check_stmt;
