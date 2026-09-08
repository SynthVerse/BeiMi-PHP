CREATE TABLE IF NOT EXISTS `{{prefix}}finance_equipment_refund_due` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `purchase_document_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_equipment_refund_purchase` (`tenant_id`,`purchase_document_id`),
  UNIQUE KEY `uk_equipment_refund_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_equipment_refund_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `refund_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `previous_revision_id` bigint unsigned NOT NULL DEFAULT 0,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_equipment_refund_revision` (`tenant_id`,`refund_id`,`previous_revision_id`),
  UNIQUE KEY `uk_equipment_refund_revision_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
