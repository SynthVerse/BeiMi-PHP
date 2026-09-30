-- 报货单与履约任务组保存同一份订单级送货安排快照；旧数据保留为空并由读模型显式标记 missing。

SET @delivery_arrangement_column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report'
    AND COLUMN_NAME = 'delivery_arrangement_snapshot'
);
SET @delivery_arrangement_sql := IF(
  @delivery_arrangement_column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report`
    ADD COLUMN `delivery_method` varchar(32) NOT NULL DEFAULT '''' AFTER `is_supplement`,
    ADD COLUMN `delivery_arrangement_status` varchar(32) NOT NULL DEFAULT '''' AFTER `delivery_method`,
    ADD COLUMN `delivery_customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `delivery_arrangement_status`,
    ADD COLUMN `earliest_delivery_time` char(5) NOT NULL DEFAULT '''' AFTER `delivery_customer_id`,
    ADD COLUMN `delivery_arrangement_snapshot` text DEFAULT NULL AFTER `earliest_delivery_time`',
  'SELECT 1'
);
PREPARE delivery_arrangement_stmt FROM @delivery_arrangement_sql;
EXECUTE delivery_arrangement_stmt;
DEALLOCATE PREPARE delivery_arrangement_stmt;

SET @delivery_arrangement_group_column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}fulfillment_task_group'
    AND COLUMN_NAME = 'delivery_arrangement_snapshot'
);
SET @delivery_arrangement_group_sql := IF(
  @delivery_arrangement_group_column_exists = 0,
  'ALTER TABLE `{{prefix}}fulfillment_task_group`
    ADD COLUMN `delivery_method` varchar(32) NOT NULL DEFAULT '''' AFTER `is_supplement`,
    ADD COLUMN `delivery_arrangement_status` varchar(32) NOT NULL DEFAULT '''' AFTER `delivery_method`,
    ADD COLUMN `delivery_customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `delivery_arrangement_status`,
    ADD COLUMN `earliest_delivery_time` char(5) NOT NULL DEFAULT '''' AFTER `delivery_customer_id`,
    ADD COLUMN `delivery_arrangement_snapshot` text DEFAULT NULL AFTER `earliest_delivery_time`,
    ADD KEY `idx_fulfillment_delivery_order` (`tenant_id`,`delivery_date`,`delivery_arrangement_status`,`earliest_delivery_time`,`id`)',
  'SELECT 1'
);
PREPARE delivery_arrangement_group_stmt FROM @delivery_arrangement_group_sql;
EXECUTE delivery_arrangement_group_stmt;
DEALLOCATE PREPARE delivery_arrangement_group_stmt;
