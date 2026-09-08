CREATE TABLE IF NOT EXISTS `{{prefix}}finance_expense_category` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `parent` varchar(30) NOT NULL,
  `name` varchar(80) NOT NULL,
  `is_enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `version` int unsigned NOT NULL DEFAULT 1,
  `used_at` int unsigned NOT NULL DEFAULT 0,
  `document_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_expense_category_name` (`tenant_id`,`parent`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_expense_bill` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `source_reference` varchar(160) NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_expense_bill_reference` (`tenant_id`,`vendor_id`,`source_reference`),
  UNIQUE KEY `uk_finance_expense_bill_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
