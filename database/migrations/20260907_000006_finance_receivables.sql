CREATE TABLE IF NOT EXISTS `{{prefix}}finance_due_adjustment` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `previous_revision` bigint unsigned NOT NULL DEFAULT 0,
  `old_due_date` date DEFAULT NULL,
  `new_due_date` date DEFAULT NULL,
  `reason` varchar(1000) NOT NULL,
  `actor` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_due_document` (`tenant_id`,`document_id`),
  KEY `idx_finance_due_source` (`tenant_id`,`source_ref`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
