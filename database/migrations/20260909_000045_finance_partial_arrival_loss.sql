-- 同一到货允许分次核实不同原因的损失；单据唯一性保持，累计数量由账套与来源行锁保护。
SET @finance_loss_unique := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}finance_purchase_arrival_loss'
    AND INDEX_NAME = 'uk_finance_arrival_loss_source' AND NON_UNIQUE = 0);
SET @finance_loss_index_sql := IF(@finance_loss_unique > 0,
  'ALTER TABLE `{{prefix}}finance_purchase_arrival_loss` DROP INDEX `uk_finance_arrival_loss_source`, ADD KEY `idx_finance_arrival_loss_source` (`tenant_id`,`arrival_line_id`,`id`)', 'SELECT 1');
PREPARE finance_loss_index_stmt FROM @finance_loss_index_sql;
EXECUTE finance_loss_index_stmt;
DEALLOCATE PREPARE finance_loss_index_stmt;
