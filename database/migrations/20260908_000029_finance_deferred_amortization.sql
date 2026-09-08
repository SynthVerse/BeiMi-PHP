CREATE TABLE IF NOT EXISTS `{{prefix}}finance_deferred_amortization` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `benefit_month` char(7) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_deferred_month` (`tenant_id`,`source_ref`,`benefit_month`),
  UNIQUE KEY `uk_finance_deferred_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
