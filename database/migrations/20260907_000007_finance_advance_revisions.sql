CREATE TABLE IF NOT EXISTS `{{prefix}}finance_advance_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `old_amount` decimal(18,2) NOT NULL,
  `new_amount` decimal(18,2) NOT NULL,
  `old_business_date` date DEFAULT NULL,
  `new_business_date` date NOT NULL,
  `reason` varchar(1000) NOT NULL,
  `actor` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_advance_revision` (`tenant_id`,`document_id`,`source_ref`),
  KEY `idx_finance_advance_revision_source` (`tenant_id`,`source_ref`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
