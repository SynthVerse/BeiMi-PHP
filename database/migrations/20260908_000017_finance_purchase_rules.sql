CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_difference_rule` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `scope` varchar(20) NOT NULL,
  `vendor_id` int unsigned NOT NULL DEFAULT 0,
  `sku_id` int unsigned NOT NULL DEFAULT 0,
  `category_id` int unsigned NOT NULL DEFAULT 0,
  `version` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `absolute_limit` decimal(18,4) NOT NULL,
  `percent_limit` decimal(18,4) NOT NULL,
  `reason` text NOT NULL,
  `actor` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_purchase_rule` (`tenant_id`,`scope`,`vendor_id`,`sku_id`,`category_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_supplier_terms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `vendor_id` int unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `mode` varchar(20) NOT NULL,
  `days` int unsigned NOT NULL DEFAULT 0,
  `reason` text NOT NULL,
  `actor` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_supplier_terms` (`tenant_id`,`vendor_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
