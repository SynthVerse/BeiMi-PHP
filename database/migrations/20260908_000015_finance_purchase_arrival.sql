CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_arrival_line` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `vendor_id` int unsigned NOT NULL,
  `warehouse_id` int unsigned NOT NULL,
  `sku_id` int unsigned NOT NULL,
  `business_date` date NOT NULL,
  `actual_quantity` decimal(18,4) NOT NULL,
  `estimated_amount` decimal(16,2) DEFAULT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_arrival_line` (`tenant_id`,`document_id`,`line_number`),
  KEY `idx_finance_arrival_vendor` (`tenant_id`,`vendor_id`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_price` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `vendor_id` int unsigned NOT NULL,
  `sku_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `price` decimal(16,2) NOT NULL,
  `business_date` date NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_finance_purchase_price` (`tenant_id`,`vendor_id`,`sku_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
