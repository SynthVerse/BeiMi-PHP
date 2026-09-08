CREATE TABLE IF NOT EXISTS `{{prefix}}finance_expense_estimate_resolution` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `bill_id` bigint unsigned NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_expense_estimate_scope` (`tenant_id`,`bill_id`,`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
