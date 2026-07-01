-- Sales return original-line dimension for the shared order_goods table.
-- Replace {{prefix}} with the configured database prefix before applying.
-- Idempotency note: this script checks information_schema before adding the column
-- and index, so it can be re-run safely after the placeholder is replaced.

SET @sales_return_original_line_col_exists := (
  SELECT COUNT(1)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}order_goods'
    AND COLUMN_NAME = 'original_sales_order_list_id'
);
SET @sales_return_original_line_col_sql := IF(
  @sales_return_original_line_col_exists = 0,
  'ALTER TABLE `{{prefix}}order_goods` ADD COLUMN `original_sales_order_list_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT ''原销售单明细ID，仅销售退货使用'' AFTER `order_type`',
  'SELECT 1'
);
PREPARE sales_return_original_line_col_stmt FROM @sales_return_original_line_col_sql;
EXECUTE sales_return_original_line_col_stmt;
DEALLOCATE PREPARE sales_return_original_line_col_stmt;

SET @sales_return_original_line_idx_exists := (
  SELECT COUNT(1)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}order_goods'
    AND INDEX_NAME = 'idx_tenant_sales_return_original_line'
);
SET @sales_return_original_line_idx_sql := IF(
  @sales_return_original_line_idx_exists = 0,
  'ALTER TABLE `{{prefix}}order_goods` ADD KEY `idx_tenant_sales_return_original_line` (`tenant_id`, `order_type`, `original_sales_order_list_id`)',
  'SELECT 1'
);
PREPARE sales_return_original_line_idx_stmt FROM @sales_return_original_line_idx_sql;
EXECUTE sales_return_original_line_idx_stmt;
DEALLOCATE PREPARE sales_return_original_line_idx_stmt;
