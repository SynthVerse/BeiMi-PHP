-- V2 独立数量事实；旧记录保留 V1，不反算历史计数。
SET @quantity_reference_column := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='estimate_reference_snapshot');
SET @quantity_reference_sql := IF(@quantity_reference_column=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `estimate_reference_snapshot` mediumtext NULL', 'SELECT 1');
PREPARE quantity_reference_statement FROM @quantity_reference_sql;
EXECUTE quantity_reference_statement;
DEALLOCATE PREPARE quantity_reference_statement;
ALTER TABLE `{{prefix}}customer_report_item` MODIFY COLUMN `order_qty` decimal(18,4) NOT NULL DEFAULT 0, MODIFY COLUMN `acceptable_base_qty_min` decimal(18,4) NOT NULL DEFAULT 0, MODIFY COLUMN `acceptable_base_qty_max` decimal(18,4) NOT NULL DEFAULT 0;
ALTER TABLE `{{prefix}}customer_report_processing_group` MODIFY COLUMN `planned_qty` decimal(18,4) NOT NULL DEFAULT 0;
SET @quantity_stage_column := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='size_check_stage');
SET @quantity_stage_sql := IF(@quantity_stage_column=0, 'ALTER TABLE `{{prefix}}customer_report_item` ADD COLUMN `size_check_stage` varchar(24) NOT NULL DEFAULT ''delivery''', 'SELECT 1');
PREPARE quantity_stage_statement FROM @quantity_stage_sql;
EXECUTE quantity_stage_statement;
DEALLOCATE PREPARE quantity_stage_statement;

SET @quantity_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}goods_unit' AND COLUMN_NAME='measure_type');
SET @quantity_sql := IF(@quantity_column_exists=0, 'ALTER TABLE `{{prefix}}goods_unit` ADD COLUMN `measure_type` varchar(16) NOT NULL DEFAULT '''', ADD COLUMN `quantity_precision` tinyint unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE quantity_statement FROM @quantity_sql;
EXECUTE quantity_statement;
DEALLOCATE PREPARE quantity_statement;

SET @quantity_group_column := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_item' AND COLUMN_NAME='processing_group_id');
SET @quantity_group_sql := IF(@quantity_group_column=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_item` ADD COLUMN `processing_group_id` int unsigned NOT NULL DEFAULT 0, DROP INDEX `uk_tenant_delivery_event_item`, ADD UNIQUE KEY `uk_delivery_event_item_group` (`tenant_id`,`delivery_event_id`,`report_item_id`,`processing_group_id`)', 'SELECT 1');
PREPARE quantity_group_statement FROM @quantity_group_sql;
EXECUTE quantity_group_statement;
DEALLOCATE PREPARE quantity_group_statement;

CREATE TABLE IF NOT EXISTS `{{prefix}}goods_packaging_source` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `warehouse_id` int unsigned NOT NULL,
 `goods_id` int unsigned NOT NULL, `sku_id` int unsigned NOT NULL, `stock_flow_id` int unsigned NOT NULL,
 `rule_id` int unsigned NOT NULL, `rule_snapshot` mediumtext NOT NULL,
 `package_unit_id` int unsigned NOT NULL, `base_unit_id` int unsigned NOT NULL,
 `package_quantity` decimal(18,4) NOT NULL, `remaining_package_quantity` decimal(18,4) NOT NULL,
 `remaining_base_quantity` decimal(18,4) NOT NULL, `last_stock_flow_id` int unsigned NOT NULL,
 `status` varchar(24) NOT NULL DEFAULT 'verified', `verified_by` int unsigned NOT NULL, `verified_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_packaging_receipt` (`tenant_id`,`stock_flow_id`), KEY `idx_packaging_sku` (`tenant_id`,`warehouse_id`,`sku_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE `{{prefix}}goods_unit` SET `measure_type`='weight', `quantity_precision`=4 WHERE `measure_type`='' AND LOWER(`name`) IN ('斤','两','克','公斤','千克','kg','g','jin');

SET @quantity_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}goods' AND COLUMN_NAME='default_order_unit_id');
SET @quantity_sql := IF(@quantity_column_exists=0, 'ALTER TABLE `{{prefix}}goods` ADD COLUMN `default_order_unit_id` int unsigned NOT NULL DEFAULT 0, ADD COLUMN `default_pricing_unit_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE quantity_statement FROM @quantity_sql;
EXECUTE quantity_statement;
DEALLOCATE PREPARE quantity_statement;

SET @quantity_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_item' AND COLUMN_NAME='quantity_contract_version');
SET @quantity_sql := IF(@quantity_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_item`
 ADD COLUMN `quantity_contract_version` tinyint unsigned NOT NULL DEFAULT 1,
 ADD COLUMN `estimate_status` varchar(24) NOT NULL DEFAULT ''known'',
 ADD COLUMN `estimate_source` varchar(32) NOT NULL DEFAULT ''legacy'',
 ADD COLUMN `quantity_facts_snapshot` mediumtext NULL,
 MODIFY COLUMN `expected_base_qty` decimal(18,4) NOT NULL DEFAULT 0,
 MODIFY COLUMN `reserved_base_qty` decimal(18,4) NOT NULL DEFAULT 0,
 MODIFY COLUMN `shortage_base_qty` decimal(18,4) NOT NULL DEFAULT 0,
 MODIFY COLUMN `fulfilled_base_qty` decimal(18,4) NOT NULL DEFAULT 0,
 MODIFY COLUMN `final_actual_weight` decimal(18,4) NULL DEFAULT NULL', 'SELECT 1');
PREPARE quantity_statement FROM @quantity_sql;
EXECUTE quantity_statement;
DEALLOCATE PREPARE quantity_statement;

ALTER TABLE `{{prefix}}customer_report_reservation`
 MODIFY COLUMN `reserved_base_qty` decimal(18,4) NOT NULL DEFAULT 0,
 MODIFY COLUMN `consumed_base_qty` decimal(18,4) NOT NULL DEFAULT 0,
 MODIFY COLUMN `released_base_qty` decimal(18,4) NOT NULL DEFAULT 0;

SET @quantity_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report' AND COLUMN_NAME='quantity_operation_key');
SET @quantity_sql := IF(@quantity_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report`
 ADD COLUMN `quantity_operation_key` varchar(96) NULL,
 ADD COLUMN `quantity_operation_fingerprint` char(64) NULL,
 MODIFY COLUMN `total_base_qty` decimal(18,4) NOT NULL DEFAULT 0,
 MODIFY COLUMN `reserved_base_qty` decimal(18,4) NOT NULL DEFAULT 0,
 MODIFY COLUMN `shortage_base_qty` decimal(18,4) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE quantity_statement FROM @quantity_sql;
EXECUTE quantity_statement;
DEALLOCATE PREPARE quantity_statement;

SET @quantity_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}customer_report_processing_group' AND COLUMN_NAME='quantity_facts_snapshot');
SET @quantity_sql := IF(@quantity_column_exists=0, 'ALTER TABLE `{{prefix}}customer_report_processing_group`
 ADD COLUMN `quantity_facts_snapshot` mediumtext NULL,
 ADD COLUMN `processing_loss_flow_id` int unsigned NOT NULL DEFAULT 0,
 MODIFY COLUMN `final_actual_weight` decimal(18,4) NULL DEFAULT NULL', 'SELECT 1');
PREPARE quantity_statement FROM @quantity_sql;
EXECUTE quantity_statement;
DEALLOCATE PREPARE quantity_statement;

SET @quantity_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_delivery_item' AND COLUMN_NAME='fact_version');
SET @quantity_sql := IF(@quantity_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_delivery_item`
 ADD COLUMN `fact_version` tinyint unsigned NOT NULL DEFAULT 1,
 ADD COLUMN `delivery_group_key` varchar(96) NULL,
 ADD COLUMN `inventory_base_quantity` decimal(18,4) NULL,
 ADD COLUMN `inventory_base_unit_id` int unsigned NULL,
 ADD COLUMN `inventory_base_unit_name` varchar(50) NULL,
 ADD COLUMN `quantity_facts` mediumtext NULL,
 ADD COLUMN `quantity_facts_snapshot` mediumtext NULL,
 MODIFY COLUMN `actual_delivery_weight` decimal(18,4) NULL DEFAULT NULL,
 ADD UNIQUE KEY `uk_delivery_physical_group` (`tenant_id`,`delivery_group_key`)', 'SELECT 1');
PREPARE quantity_statement FROM @quantity_sql;
EXECUTE quantity_statement;
DEALLOCATE PREPARE quantity_statement;
