-- 库内实际损耗和后续分次核实各自留痕，不创建第二套库存余额。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_inventory_loss` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `warehouse_id` int unsigned NOT NULL,
  `sku_id` int unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `business_date` date NOT NULL,
  `source_reference` varchar(160) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inventory_loss_document` (`tenant_id`,`document_id`),
  UNIQUE KEY `uk_inventory_loss_fact` (`tenant_id`,`warehouse_id`,`sku_id`,`source_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_inventory_loss_resolution` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `incident_document_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inventory_loss_resolution_document` (`tenant_id`,`document_id`),
  KEY `idx_inventory_loss_resolution_source` (`tenant_id`,`incident_document_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
