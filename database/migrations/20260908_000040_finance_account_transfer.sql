CREATE TABLE IF NOT EXISTS `{{prefix}}finance_account_transfer` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `source_account_id` bigint unsigned NOT NULL,
  `target_account_id` bigint unsigned NOT NULL,
  `source_reference` varchar(160) NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_transfer_reference` (`tenant_id`,`source_account_id`,`source_reference`),
  UNIQUE KEY `uk_account_transfer_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_transfer_settlement` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `withheld_fee` decimal(16,2) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transfer_settlement_document` (`tenant_id`,`document_id`),
  KEY `idx_transfer_settlement_source` (`tenant_id`,`source_ref`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
