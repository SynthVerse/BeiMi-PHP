-- 客户实际验收退回独立于销售金额贷项和真实退款。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_customer_return` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `sales_order_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `original_warehouse_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `business_date` date NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_return_document` (`tenant_id`,`document_id`),
  KEY `idx_customer_return_sales` (`tenant_id`,`sales_order_id`,`sku_id`,`id`),
  KEY `idx_customer_return_customer` (`tenant_id`,`customer_id`,`business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
