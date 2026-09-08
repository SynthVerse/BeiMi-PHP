-- 盘点截止快照和范围占用。实物余额仍由既有仓库库存原语维护。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_inventory_count` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `status` varchar(16) NOT NULL,
  `snapshot` json NOT NULL,
  `result_document_id` bigint unsigned NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_count_document` (`tenant_id`,`document_id`),
  KEY `idx_finance_count_status` (`tenant_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_inventory_count_measurement` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `count_document_id` bigint unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_finance_count_measurement` (`tenant_id`,`document_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_inventory_count_line` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `count_document_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `active_slot` tinyint unsigned NULL,
  `snapshot` json NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_count_line` (`tenant_id`,`count_document_id`,`sku_id`),
  UNIQUE KEY `uk_finance_count_active` (`tenant_id`,`warehouse_id`,`sku_id`,`active_slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
