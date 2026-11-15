-- 报货统一保存：未完成输入与幂等结果独立于已签发金额，说明补录不改写历史版本。
CREATE TABLE IF NOT EXISTS `{{prefix}}customer_report_workspace_save` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL,
 `report_id` int unsigned NOT NULL, `version` int unsigned NOT NULL,
 `report_version` int unsigned NOT NULL, `document_version` int unsigned NOT NULL DEFAULT 0,
 `state` varchar(20) NOT NULL, `input` longtext NOT NULL, `missing_fields` text NOT NULL,
 `actor` text NOT NULL, `operation_key` varchar(96) NOT NULL, `fingerprint` varchar(64) NOT NULL,
 `result` text NOT NULL, `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_report_workspace_version` (`tenant_id`,`report_id`,`version`),
 UNIQUE KEY `uk_report_workspace_operation` (`tenant_id`,`operation_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}customer_report_sales_note` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL,
 `report_id` int unsigned NOT NULL, `document_id` int unsigned NOT NULL, `revision_id` int unsigned NOT NULL,
 `report_item_id` int unsigned NOT NULL DEFAULT 0, `type` varchar(32) NOT NULL,
 `content` varchar(1000) NOT NULL, `actor` text NOT NULL, `operation_key` varchar(96) NOT NULL,
 `fingerprint` varchar(64) NOT NULL, `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_report_sales_note` (`tenant_id`,`revision_id`,`report_item_id`,`type`),
 KEY `idx_report_sales_notes` (`tenant_id`,`report_id`,`id`),
 KEY `idx_report_note_operation` (`tenant_id`,`operation_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
