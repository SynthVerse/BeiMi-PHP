-- V2 交付子组按物理身份覆盖；不回填或猜分历史重量结算。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_sales_group_coverage` (
  `tenant_id` int unsigned NOT NULL,
  `delivery_item_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`delivery_item_id`),
  KEY `idx_group_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_sales_pricing_fact` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `goods_id` int unsigned NOT NULL,
  `sku_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `delivery_item_id` bigint unsigned NOT NULL,
  `price_unit_id` int unsigned NOT NULL,
  `price_unit_name` varchar(50) NOT NULL,
  `confirmed_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pricing_fact` (`tenant_id`,`document_id`,`delivery_item_id`),
  KEY `idx_pricing_preference` (`tenant_id`,`customer_id`,`goods_id`,`sku_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
