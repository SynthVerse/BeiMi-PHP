-- 客户报货的单条重量要求、总重可接受范围与现场规格核实结果。

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report_item'
    AND COLUMN_NAME = 'acceptable_base_qty_min'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `acceptable_base_qty_min` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `expected_base_qty`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report_item'
    AND COLUMN_NAME = 'acceptable_base_qty_max'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `acceptable_base_qty_max` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `acceptable_base_qty_min`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report_item'
    AND COLUMN_NAME = 'specification_verification_status'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `specification_verification_status` varchar(32) NOT NULL DEFAULT ''not_required'' AFTER `piece_weight_confirmed`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report_item'
    AND COLUMN_NAME = 'verified_piece_count'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `verified_piece_count` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `specification_verification_status`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report_item'
    AND COLUMN_NAME = 'verified_piece_weight_min'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `verified_piece_weight_min` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `verified_piece_count`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report_item'
    AND COLUMN_NAME = 'verified_piece_weight_max'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `verified_piece_weight_max` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `verified_piece_weight_min`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report_item'
    AND COLUMN_NAME = 'specification_verification_note'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `specification_verification_note` varchar(500) NOT NULL DEFAULT '''' AFTER `verified_piece_weight_max`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report_item'
    AND COLUMN_NAME = 'specification_verified_by'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `specification_verified_by` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `specification_verification_note`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @column_exists := (
  SELECT COUNT(1) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_report_item'
    AND COLUMN_NAME = 'specification_verified_time'
);
SET @column_sql := IF(
  @column_exists = 0,
  'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `specification_verified_time` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `specification_verified_by`',
  'SELECT 1'
);
PREPARE statement FROM @column_sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

UPDATE `{{prefix}}customer_report_item`
SET `acceptable_base_qty_min` = `expected_base_qty`,
    `acceptable_base_qty_max` = `expected_base_qty`,
    `specification_verification_status` = IF(`piece_weight_confirmed` = 1, 'pending', 'not_required')
WHERE `acceptable_base_qty_min` = 0.00
  AND `acceptable_base_qty_max` = 0.00;
