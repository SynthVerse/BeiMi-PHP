-- 将原盘点反向调整与已补录实物业务逐次关联，原盘点及真实业务均不覆盖。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_inventory_count_correction` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `count_result_document_id` bigint unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `stock_flow_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_count_correction_document` (`tenant_id`,`document_id`),
  KEY `idx_count_correction_original` (`tenant_id`,`count_result_document_id`,`sku_id`,`id`),
  KEY `idx_count_correction_real_flow` (`tenant_id`,`stock_flow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
