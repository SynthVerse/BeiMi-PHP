-- 客户报货加工分组、采购计划、分批到货来源分配与待开单重量。

CREATE TABLE IF NOT EXISTS `{{prefix}}customer_report_processing_group` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `group_key` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `planned_qty` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '沿用报货行单位的分组计划数量',
  `final_actual_weight` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '待开单阶段登记的分组最终实重',
  `sort` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_report_processing_group_key` (`tenant_id`,`report_item_id`,`group_key`),
  KEY `idx_report_processing_group_item` (`tenant_id`,`report_id`,`report_item_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户报货加工分组';

CREATE TABLE IF NOT EXISTS `{{prefix}}customer_report_processing_group_process` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `processing_group_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `process_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `process_name_snapshot` varchar(60) NOT NULL DEFAULT '',
  `process_sort_snapshot` int(11) NOT NULL DEFAULT 0,
  `step_no` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_processing_group_process` (`tenant_id`,`processing_group_id`,`process_id`),
  KEY `idx_processing_group_process_step` (`tenant_id`,`processing_group_id`,`step_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='加工分组有序工序快照';

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}fulfillment_task' AND COLUMN_NAME = 'processing_group_id'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}fulfillment_task` ADD COLUMN `processing_group_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `report_item_id`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @index_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}fulfillment_task'
    AND INDEX_NAME = 'idx_fulfillment_processing_group'
);
SET @index_sql := IF(
  @index_exists = 0,
  'ALTER TABLE `{{prefix}}fulfillment_task` ADD KEY `idx_fulfillment_processing_group` (`tenant_id`,`processing_group_id`,`id`)',
  'SELECT 1'
);
PREPARE statement FROM @index_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_plan` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源报货批次',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_name` varchar(100) NOT NULL DEFAULT '',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_name` varchar(200) NOT NULL DEFAULT '',
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_name` varchar(200) NOT NULL DEFAULT '',
  `base_unit_name` varchar(50) NOT NULL DEFAULT '',
  `planned_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `arrived_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `allocated_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `surplus_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending/partial/complete/terminated',
  `active_scope_key` varchar(96) DEFAULT NULL COMMENT '活动计划唯一范围；完成或终止后置空',
  `termination_reason` varchar(500) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_plan_active_scope` (`tenant_id`,`active_scope_key`),
  KEY `idx_purchase_plan_scope` (`tenant_id`,`batch_id`,`warehouse_id`,`sku_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='缺货采购计划';

CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_plan_source` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `purchase_plan_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `customer_name` varchar(100) NOT NULL DEFAULT '',
  `delivery_date` date DEFAULT NULL,
  `shortage_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `allocated_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending/partial/fulfilled/released',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_plan_source_task` (`tenant_id`,`purchase_plan_id`,`task_id`),
  KEY `idx_purchase_plan_source_task` (`tenant_id`,`task_id`,`status`),
  KEY `idx_purchase_plan_source_plan` (`tenant_id`,`purchase_plan_id`,`delivery_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购计划来源缺货';

CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_plan_arrival` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `purchase_plan_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `purchase_batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `arrival_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `allocated_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `surplus_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_plan_arrival_batch` (`tenant_id`,`purchase_plan_id`,`purchase_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购计划分批到货';

CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_plan_allocation` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `purchase_plan_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `arrival_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `source_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `allocated_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_plan_allocation_source` (`tenant_id`,`arrival_id`,`source_id`),
  KEY `idx_purchase_plan_allocation_plan` (`tenant_id`,`purchase_plan_id`,`arrival_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='到货来源分配';

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}purchase_batch' AND COLUMN_NAME = 'purchase_plan_id'
);
SET @table_exists := (
  SELECT COUNT(1) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}purchase_batch'
);
SET @column_sql := IF(
  @table_exists = 1 AND @column_exists = 0,
  'ALTER TABLE `{{prefix}}purchase_batch` ADD COLUMN `purchase_plan_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `tenant_id`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}purchase_batch' AND COLUMN_NAME = 'plan_held_qty'
);
SET @column_sql := IF(
  @table_exists = 1 AND @column_exists = 0,
  'ALTER TABLE `{{prefix}}purchase_batch` ADD COLUMN `plan_held_qty` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT ''采购计划待显式分配的锁定库存'' AFTER `purchase_plan_id`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @index_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}purchase_batch'
    AND INDEX_NAME = 'idx_purchase_batch_plan'
);
SET @index_sql := IF(
  @table_exists = 1 AND @index_exists = 0,
  'ALTER TABLE `{{prefix}}purchase_batch` ADD KEY `idx_purchase_batch_plan` (`tenant_id`,`purchase_plan_id`,`id`)',
  'SELECT 1'
);
PREPARE statement FROM @index_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;
