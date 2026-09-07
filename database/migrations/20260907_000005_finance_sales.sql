CREATE TABLE IF NOT EXISTS `{{prefix}}finance_sales_version` (
  `tenant_id` int unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`order_id`,`version`),
  UNIQUE KEY `uk_finance_sales_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_customer_terms` (
  `tenant_id` int unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `mode` varchar(20) NOT NULL,
  `days` int unsigned NOT NULL DEFAULT 0,
  `reason` varchar(1000) NOT NULL,
  `actor` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`customer_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
