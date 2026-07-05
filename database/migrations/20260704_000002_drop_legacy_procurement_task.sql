-- Drop legacy procurement task runtime tables and sales reservation item link.
-- Do not run automatically; apply through the normal migration process.

SET @schema_name := DATABASE();
SET @reservation_item_table := 'la_sales_reservation_item';

SET @drop_indexes := (
  SELECT GROUP_CONCAT(CONCAT('DROP INDEX `', `INDEX_NAME`, '`') SEPARATOR ', ')
  FROM `INFORMATION_SCHEMA`.`STATISTICS`
  WHERE `TABLE_SCHEMA` = @schema_name
    AND `TABLE_NAME` = @reservation_item_table
    AND `COLUMN_NAME` = 'procurement_task_id'
    AND `INDEX_NAME` <> 'PRIMARY'
);

SET @sql := IF(
  @drop_indexes IS NULL,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @reservation_item_table, '` ', @drop_indexes)
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM `INFORMATION_SCHEMA`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = @schema_name
    AND `TABLE_NAME` = @reservation_item_table
    AND `COLUMN_NAME` = 'procurement_task_id'
);

SET @sql := IF(
  @column_exists = 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @reservation_item_table, '` DROP COLUMN `procurement_task_id`')
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS `la_procurement_task_inbound`;
DROP TABLE IF EXISTS `la_procurement_task`;
