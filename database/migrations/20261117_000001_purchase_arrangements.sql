-- 采购安排：原单位需求、多商品执行范围、逐纸回执和逐实收行锁库。
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND COLUMN_NAME='plan_no');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan` ADD COLUMN `plan_no` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND COLUMN_NAME='buyer_id');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan` ADD COLUMN `buyer_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND COLUMN_NAME='buyer_name');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan` ADD COLUMN `buyer_name` varchar(100) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND COLUMN_NAME='delivery_date');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan` ADD COLUMN `delivery_date` date DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND COLUMN_NAME='version');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan` ADD COLUMN `version` int unsigned NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND COLUMN_NAME='idempotency_key');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan` ADD COLUMN `idempotency_key` varchar(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND COLUMN_NAME='request_fingerprint');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan` ADD COLUMN `request_fingerprint` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND COLUMN_NAME='replaces_plan_id');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan` ADD COLUMN `replaces_plan_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND COLUMN_NAME='recall_note');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan` ADD COLUMN `recall_note` text DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='planned_qty');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `planned_qty` decimal(18,4) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='fulfilled_qty');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `fulfilled_qty` decimal(18,4) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='bought_qty');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `bought_qty` decimal(18,4) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='goods_id');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `goods_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='goods_name');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `goods_name` varchar(200) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='sku_id');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `sku_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='sku_name');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `sku_name` varchar(200) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='unit_name');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `unit_name` varchar(50) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='base_unit_name');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `base_unit_name` varchar(50) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='requirement');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `requirement` text DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND COLUMN_NAME='report_version');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_source` ADD COLUMN `report_version` int unsigned NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_allocation' AND COLUMN_NAME='quantity');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_allocation` ADD COLUMN `quantity` decimal(18,4) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_allocation' AND COLUMN_NAME='arrival_line_id');
SET @ddl := IF(@exists=0, 'ALTER TABLE `{{prefix}}purchase_plan_allocation` ADD COLUMN `arrival_line_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan' AND INDEX_NAME='uk_plan_request');
SET @ddl := IF(@exists=0,'ALTER TABLE `{{prefix}}purchase_plan` ADD UNIQUE KEY `uk_plan_request` (tenant_id,idempotency_key)','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_demand_coverage` (
 id int unsigned NOT NULL AUTO_INCREMENT, tenant_id int unsigned NOT NULL, report_item_id int unsigned NOT NULL,
 quantity decimal(18,4) NOT NULL, base_qty decimal(18,4) NOT NULL, reason varchar(500) NOT NULL,
 idempotency_key varchar(100) NOT NULL, request_fingerprint varchar(64) NOT NULL, create_time int unsigned NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uk_coverage_request(tenant_id,idempotency_key), KEY idx_coverage_item(tenant_id,report_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_plan_sheet` (
 id int unsigned NOT NULL AUTO_INCREMENT, tenant_id int unsigned NOT NULL, purchase_plan_id int unsigned NOT NULL,
 plan_version int unsigned NOT NULL, request_key varchar(100) NOT NULL, sheet_key varchar(100) NOT NULL,
 copy_no int unsigned NOT NULL, status varchar(24) NOT NULL DEFAULT 'pending', ticket longtext NOT NULL,
 error_message varchar(500) NOT NULL DEFAULT '', create_time int unsigned NOT NULL, update_time int unsigned NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uk_sheet_request(tenant_id,purchase_plan_id,request_key,sheet_key), KEY idx_sheet_plan(tenant_id,purchase_plan_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_plan_hold` (
 id int unsigned NOT NULL AUTO_INCREMENT, tenant_id int unsigned NOT NULL, purchase_plan_id int unsigned NOT NULL,
 purchase_batch_id int unsigned NOT NULL, arrival_line_id int unsigned NOT NULL, sku_id int unsigned NOT NULL,
 arrival_qty decimal(18,4) NOT NULL, remaining_qty decimal(18,4) NOT NULL, create_time int unsigned NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uk_hold_arrival(tenant_id,arrival_line_id), KEY idx_hold_plan(tenant_id,purchase_plan_id,purchase_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}purchase_plan_action` (
 id int unsigned NOT NULL AUTO_INCREMENT, tenant_id int unsigned NOT NULL, purchase_plan_id int unsigned NOT NULL,
 action varchar(32) NOT NULL, idempotency_key varchar(100) NOT NULL, fingerprint varchar(64) NOT NULL,
 payload longtext NOT NULL, result longtext NOT NULL, operator_id int unsigned NOT NULL DEFAULT 0, create_time int unsigned NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uk_plan_action_request(tenant_id,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND INDEX_NAME='uk_purchase_plan_source_task');
SET @ddl := IF(@exists>0, 'ALTER TABLE `{{prefix}}purchase_plan_source` DROP INDEX `uk_purchase_plan_source_task`', 'SELECT 1');
PREPARE statement FROM @ddl; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_demand_coverage' AND COLUMN_NAME='stock_snapshot');
SET @ddl := IF(@exists=0,'ALTER TABLE `{{prefix}}purchase_demand_coverage` ADD COLUMN `stock_snapshot` varchar(200) NOT NULL DEFAULT \'\'','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_allocation' AND INDEX_NAME='uk_purchase_plan_allocation_source');
SET @ddl := IF(@exists>0,'ALTER TABLE `{{prefix}}purchase_plan_allocation` DROP INDEX `uk_purchase_plan_allocation_source`','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_sheet' AND COLUMN_NAME='print_permission');
SET @ddl := IF(@exists=0,'ALTER TABLE `{{prefix}}purchase_plan_sheet` ADD COLUMN `print_permission` varchar(40) NOT NULL DEFAULT \'task.print\'','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_sheet' AND COLUMN_NAME='request_fingerprint');
SET @ddl := IF(@exists=0,'ALTER TABLE `{{prefix}}purchase_plan_sheet` ADD COLUMN `request_fingerprint` varchar(64) NOT NULL DEFAULT \'\'','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}purchase_plan_source' AND INDEX_NAME='idx_source_report_item');
SET @ddl := IF(@exists=0,'ALTER TABLE `{{prefix}}purchase_plan_source` ADD KEY `idx_source_report_item` (tenant_id,report_item_id,id)','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
