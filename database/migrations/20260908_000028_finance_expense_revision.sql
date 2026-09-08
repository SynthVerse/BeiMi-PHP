CREATE TABLE IF NOT EXISTS `{{prefix}}finance_expense_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `bill_id` bigint unsigned NOT NULL,
  `previous_revision_id` bigint unsigned NOT NULL DEFAULT 0,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_expense_revision` (`tenant_id`,`bill_id`,`previous_revision_id`),
  KEY `idx_finance_expense_revision_latest` (`tenant_id`,`bill_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_expense_identity` (
  `tenant_id` int unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `source_reference` varchar(160) NOT NULL,
  `bill_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`vendor_id`,`source_reference`),
  KEY `idx_finance_expense_identity_bill` (`tenant_id`,`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `{{prefix}}finance_expense_identity` (`tenant_id`,`vendor_id`,`source_reference`,`bill_id`,`document_id`)
SELECT `tenant_id`,`vendor_id`,`source_reference`,`id`,`document_id` FROM `{{prefix}}finance_expense_bill`;
