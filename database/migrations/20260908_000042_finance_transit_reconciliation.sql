CREATE TABLE IF NOT EXISTS `{{prefix}}finance_transit_reconciliation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `month` char(7) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transit_reconciliation_document` (`tenant_id`,`document_id`),
  KEY `idx_transit_reconciliation_source_month` (`tenant_id`,`source_ref`,`month`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
