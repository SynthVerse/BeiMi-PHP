CREATE TABLE IF NOT EXISTS `{{prefix}}finance_recurring_expense_plan` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `source_reference` varchar(120) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_recurring_plan` (`tenant_id`,`source_reference`),
  KEY `idx_finance_recurring_vendor` (`tenant_id`,`vendor_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_recurring_expense_month` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `plan_id` bigint unsigned NOT NULL,
  `benefit_month` char(7) NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_recurring_month` (`tenant_id`,`plan_id`,`benefit_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
