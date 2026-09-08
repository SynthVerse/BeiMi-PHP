CREATE TABLE IF NOT EXISTS `{{prefix}}finance_report_export_attempt` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `export_id` bigint unsigned NULL,
  `actor` json NOT NULL,
  `action` varchar(24) NOT NULL,
  `report` varchar(24) NULL,
  `period_type` varchar(12) NULL,
  `period` varchar(12) NULL,
  `cutoff` date NULL,
  `outcome` varchar(12) NOT NULL,
  `message` varchar(240) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_report_export_attempt` (`tenant_id`,`report`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
