CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_cost_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `bill_id` bigint unsigned NOT NULL,
  `previous_revision_id` bigint unsigned NOT NULL DEFAULT 0,
  `new_amount` decimal(16,2) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_purchase_cost_revision` (`tenant_id`,`bill_id`,`previous_revision_id`),
  KEY `idx_finance_purchase_cost_revision_latest` (`tenant_id`,`bill_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
