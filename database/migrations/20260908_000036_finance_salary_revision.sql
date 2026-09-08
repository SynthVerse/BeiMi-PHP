CREATE TABLE IF NOT EXISTS `{{prefix}}finance_salary_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `salary_result_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `previous_revision_id` bigint unsigned NOT NULL DEFAULT 0,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_salary_revision` (`tenant_id`,`salary_result_id`,`previous_revision_id`),
  UNIQUE KEY `uk_finance_salary_revision_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
