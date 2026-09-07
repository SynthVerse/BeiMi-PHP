-- 供应商认可及退货争议处置只追加；实际退离原始数量不可覆盖。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_return_resolution` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `return_line_id` bigint unsigned NOT NULL,
  `arrival_line_id` bigint unsigned NOT NULL,
  `kind` varchar(20) NOT NULL,
  `business_date` date NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unsettled_quantity` decimal(18,4) NOT NULL DEFAULT 0,
  `unsettled_amount` decimal(18,2) NOT NULL DEFAULT 0,
  `credit_amount` decimal(18,2) NOT NULL DEFAULT 0,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_return_resolution_document` (`tenant_id`,`document_id`,`return_line_id`),
  KEY `idx_finance_return_resolution_line` (`tenant_id`,`return_line_id`,`id`),
  KEY `idx_finance_return_resolution_arrival` (`tenant_id`,`arrival_line_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
