CREATE TABLE IF NOT EXISTS `{{prefix}}finance_equipment_purchase` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `source_reference` varchar(160) NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_equipment_reference` (`tenant_id`,`vendor_id`,`source_reference`),
  UNIQUE KEY `uk_finance_equipment_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_equipment_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `purchase_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `previous_revision_id` bigint unsigned NOT NULL DEFAULT 0,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_equipment_revision` (`tenant_id`,`purchase_id`,`previous_revision_id`),
  UNIQUE KEY `uk_finance_equipment_revision_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
