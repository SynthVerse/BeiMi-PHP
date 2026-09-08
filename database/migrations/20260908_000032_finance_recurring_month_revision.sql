CREATE TABLE IF NOT EXISTS `{{prefix}}finance_recurring_month_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `plan_id` bigint unsigned NOT NULL,
  `benefit_month` char(7) NOT NULL,
  `previous_revision_id` bigint unsigned NOT NULL DEFAULT 0,
  `document_id` bigint unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_recurring_month_revision` (`tenant_id`,`plan_id`,`benefit_month`,`previous_revision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
