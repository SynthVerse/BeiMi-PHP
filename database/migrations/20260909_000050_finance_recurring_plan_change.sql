-- 周期计划仅修改未来待办，保留原计划及每次规则变更。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_recurring_plan_change` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `plan_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `effective_month` char(7) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recurring_plan_change_version` (`tenant_id`,`plan_id`,`version`),
  UNIQUE KEY `uk_recurring_plan_change_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
