CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_legacy_link` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `original_supply_order_id` bigint unsigned NOT NULL,
  `original_order_goods_id` bigint unsigned NOT NULL,
  `original_arrival_detail_id` bigint unsigned NOT NULL,
  `arrival_line_id` bigint unsigned NOT NULL,
  `payable_source` varchar(40) NOT NULL DEFAULT '',
  `linked_payable_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `fingerprint` char(64) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_legacy_purchase_line` (`tenant_id`,`original_order_goods_id`),
  UNIQUE KEY `uk_finance_legacy_purchase_arrival` (`tenant_id`,`arrival_line_id`),
  KEY `idx_finance_legacy_purchase_order` (`tenant_id`,`original_supply_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
