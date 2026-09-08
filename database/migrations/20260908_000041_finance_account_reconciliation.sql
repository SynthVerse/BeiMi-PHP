CREATE TABLE IF NOT EXISTS `{{prefix}}finance_account_reconciliation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `month` char(7) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_reconciliation_document` (`tenant_id`,`document_id`),
  KEY `idx_account_reconciliation_month` (`tenant_id`,`account_id`,`month`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_cash_shortage_application` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `reconciliation_document_id` bigint unsigned NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cash_shortage_document` (`tenant_id`,`document_id`),
  KEY `idx_cash_shortage_reconciliation` (`tenant_id`,`reconciliation_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
