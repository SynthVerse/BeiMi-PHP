CREATE TABLE IF NOT EXISTS `{{prefix}}finance_transfer_settlement_rebase` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `correction_document_id` bigint unsigned NOT NULL,
  `original_source_ref` varchar(40) NOT NULL,
  `replacement_source_ref` varchar(40) NOT NULL,
  `settlement_document_id` bigint unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `withheld_fee` decimal(16,2) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transfer_settlement_rebase_event` (`tenant_id`,`correction_document_id`,`settlement_document_id`),
  KEY `idx_transfer_settlement_rebase_original` (`tenant_id`,`original_source_ref`,`id`),
  KEY `idx_transfer_settlement_rebase_replacement` (`tenant_id`,`replacement_source_ref`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
