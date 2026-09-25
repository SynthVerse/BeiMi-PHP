-- 执行工序改为店铺显式配置的三种产生方式；保留已有目录与历史任务引用。
UPDATE `{{prefix}}work_process`
SET `trigger_type` = 'report_selection', `trigger_keywords` = '[]', `is_system` = 0
WHERE `trigger_type` = 'remark';

UPDATE `{{prefix}}work_process`
SET `trigger_type` = 'inventory_shortage', `trigger_keywords` = '[]', `is_system` = 0
WHERE `trigger_type` = 'shortage';

UPDATE `{{prefix}}work_process`
SET `trigger_type` = 'all_processing_completed', `trigger_keywords` = '[]', `is_system` = 0
WHERE `trigger_type` = 'group_ready';

UPDATE `{{prefix}}work_process`
SET `trigger_type` = 'report_selection', `trigger_keywords` = '[]', `is_enabled` = 0, `is_system` = 0
WHERE `trigger_type` = 'ticket_recovered';

SET @procedure_mode_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}work_process' AND COLUMN_NAME='active_automatic_trigger');
SET @procedure_mode_column_sql := IF(
  @procedure_mode_column_exists=0,
  'ALTER TABLE `{{prefix}}work_process` ADD COLUMN `active_automatic_trigger` varchar(32) GENERATED ALWAYS AS (CASE WHEN `is_enabled`=1 AND `delete_time` IS NULL AND `trigger_type` IN (''inventory_shortage'',''all_processing_completed'') THEN `trigger_type` ELSE NULL END) STORED',
  'SELECT 1'
);
PREPARE procedure_mode_column_stmt FROM @procedure_mode_column_sql;
EXECUTE procedure_mode_column_stmt;
DEALLOCATE PREPARE procedure_mode_column_stmt;

SET @procedure_mode_index_exists := (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}work_process' AND INDEX_NAME='uk_tenant_active_automatic_trigger');
SET @procedure_mode_index_sql := IF(@procedure_mode_index_exists=0, 'ALTER TABLE `{{prefix}}work_process` ADD UNIQUE KEY `uk_tenant_active_automatic_trigger` (`tenant_id`,`active_automatic_trigger`)', 'SELECT 1');
PREPARE procedure_mode_index_stmt FROM @procedure_mode_index_sql;
EXECUTE procedure_mode_index_stmt;
DEALLOCATE PREPARE procedure_mode_index_stmt;

SET @procedure_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}fulfillment_task' AND COLUMN_NAME='process_sort_snapshot');
SET @procedure_column_sql := IF(@procedure_column_exists=0, 'ALTER TABLE `{{prefix}}fulfillment_task` ADD COLUMN `process_sort_snapshot` int(11) NOT NULL DEFAULT 0 AFTER `process_name_snapshot`', 'SELECT 1');
PREPARE procedure_column_stmt FROM @procedure_column_sql;
EXECUTE procedure_column_stmt;
DEALLOCATE PREPARE procedure_column_stmt;

UPDATE `{{prefix}}fulfillment_task` AS task
INNER JOIN `{{prefix}}work_process` AS process ON process.tenant_id=task.tenant_id AND process.id=task.process_id
SET task.process_sort_snapshot=process.sort
WHERE task.process_sort_snapshot=0;
