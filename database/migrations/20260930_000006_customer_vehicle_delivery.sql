-- 客户车辆交付冻结提前交付的显式确认与原因，避免以后从备注文本反推事实。

SET @customer_vehicle_delivery_column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}fulfillment_delivery_event'
    AND COLUMN_NAME = 'early_delivery_reason'
);
SET @customer_vehicle_delivery_column_sql := IF(
  @customer_vehicle_delivery_column_exists = 0,
  'ALTER TABLE `{{prefix}}fulfillment_delivery_event`
    ADD COLUMN `early_delivery_reason` varchar(500) NOT NULL DEFAULT '''' AFTER `actual_handoff_time`,
    ADD COLUMN `early_delivery_confirmed` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `early_delivery_reason`',
  'SELECT 1'
);
PREPARE customer_vehicle_delivery_stmt FROM @customer_vehicle_delivery_column_sql;
EXECUTE customer_vehicle_delivery_stmt;
DEALLOCATE PREPARE customer_vehicle_delivery_stmt;
