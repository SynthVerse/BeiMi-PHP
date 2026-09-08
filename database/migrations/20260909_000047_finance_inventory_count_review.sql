-- 原实盘和确认快照不覆盖，逐SKU追加原因与截止成本核实。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_inventory_count_review` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `count_result_document_id` bigint unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_count_review_document` (`tenant_id`,`document_id`),
  KEY `idx_finance_count_review_source` (`tenant_id`,`count_result_document_id`,`sku_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
