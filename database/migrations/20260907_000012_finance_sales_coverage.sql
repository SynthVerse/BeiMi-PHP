CREATE TABLE IF NOT EXISTS `{{prefix}}finance_sales_coverage` (
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `delivery_item_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `covered_delta` decimal(18,4) NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`document_id`,`delivery_item_id`),
  KEY `idx_finance_sales_coverage_delivery` (`tenant_id`,`delivery_item_id`),
  KEY `idx_finance_sales_coverage_order` (`tenant_id`,`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
