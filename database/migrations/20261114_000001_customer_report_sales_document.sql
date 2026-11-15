-- 报货销售单的提前签发、不可变修订及延后入账。
CREATE TABLE IF NOT EXISTS `{{prefix}}customer_report_sales_document` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL,
 `report_id` int unsigned NOT NULL, `order_sn` varchar(64) NOT NULL,
 `version` int unsigned NOT NULL DEFAULT 1, `status` varchar(20) NOT NULL DEFAULT 'issued',
 `accounting_status` varchar(30) NOT NULL DEFAULT 'pending_delivery',
 `snapshot` longtext NOT NULL, `approval_actor` text NOT NULL,
 `blocked_reason` varchar(1000) NOT NULL DEFAULT '', `finance_document_id` int unsigned NOT NULL DEFAULT 0,
 `last_operation_key` varchar(96) NOT NULL DEFAULT '', `last_operation_fingerprint` varchar(64) NOT NULL DEFAULT '',
 `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_report_sales_document` (`tenant_id`,`report_id`),
 KEY `idx_report_sales_accounting` (`tenant_id`,`status`,`accounting_status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}customer_report_sales_revision` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL,
 `document_id` int unsigned NOT NULL, `version` int unsigned NOT NULL,
 `snapshot` longtext NOT NULL, `reason` varchar(1000) NOT NULL DEFAULT '', `actor` text NOT NULL,
 `operation_key` varchar(96) NOT NULL, `fingerprint` varchar(64) NOT NULL, `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_report_sales_revision` (`tenant_id`,`document_id`,`version`),
 UNIQUE KEY `uk_report_sales_operation` (`tenant_id`,`operation_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
