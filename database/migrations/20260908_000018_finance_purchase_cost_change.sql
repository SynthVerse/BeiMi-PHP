CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_cost_bill` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `source_reference` varchar(160) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_purchase_cost_bill` (`tenant_id`,`vendor_id`,`source_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_cost_change` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `arrival_line_id` bigint unsigned NOT NULL,
  `settlement_line_id` bigint unsigned NOT NULL DEFAULT 0,
  `kind` varchar(32) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_purchase_cost_change` (`tenant_id`,`document_id`,`arrival_line_id`),
  KEY `idx_finance_purchase_cost_origin` (`tenant_id`,`arrival_line_id`,`id`),
  KEY `idx_finance_purchase_cost_settlement` (`tenant_id`,`settlement_line_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
