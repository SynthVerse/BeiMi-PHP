-- 采购计划来源保存报货时的最早送货时间快照，供人工采购优先建议使用。

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}purchase_plan_source'
    AND COLUMN_NAME = 'earliest_delivery_time'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `earliest_delivery_time` time DEFAULT NULL AFTER `delivery_date`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

UPDATE `{{prefix}}purchase_plan_source` AS pps
INNER JOIN `{{prefix}}fulfillment_task` AS ft
  ON ft.`tenant_id` = pps.`tenant_id` AND ft.`id` = pps.`task_id`
INNER JOIN `{{prefix}}fulfillment_task_group` AS ftg
  ON ftg.`tenant_id` = ft.`tenant_id` AND ftg.`id` = ft.`group_id`
LEFT JOIN `{{prefix}}customer_report_item` AS cri
  ON cri.`tenant_id` = pps.`tenant_id` AND cri.`id` = pps.`report_item_id`
SET pps.`earliest_delivery_time` = COALESCE(pps.`earliest_delivery_time`, ftg.`earliest_delivery_time`),
    pps.`customer_name` = CASE
      WHEN COALESCE(cri.`delivery_customer_name`, '') <> '' THEN cri.`delivery_customer_name`
      ELSE pps.`customer_name`
    END
WHERE pps.`earliest_delivery_time` IS NULL
   OR COALESCE(cri.`delivery_customer_name`, '') <> '';

SET @index_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}purchase_plan_source'
    AND INDEX_NAME = 'idx_purchase_plan_source_priority'
);
SET @index_sql := IF(
  @index_exists = 0,
  'ALTER TABLE `{{prefix}}purchase_plan_source` ADD KEY `idx_purchase_plan_source_priority` (`tenant_id`,`purchase_plan_id`,`status`,`delivery_date`,`earliest_delivery_time`,`id`)',
  'SELECT 1'
);
PREPARE statement FROM @index_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;
