CREATE TABLE IF NOT EXISTS `{{prefix}}finance_report_export` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `idempotency_key` varchar(96) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `report` varchar(24) NOT NULL,
  `actor` json NOT NULL,
  `snapshot` json NOT NULL,
  `snapshot_hash` char(64) NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_report_export_command` (`tenant_id`,`idempotency_key`),
  KEY `idx_report_export_history` (`tenant_id`,`report`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_report_export_access` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `export_id` bigint unsigned NOT NULL,
  `actor` json NOT NULL,
  `content_hash` char(64) NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_report_export_access` (`tenant_id`,`export_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
