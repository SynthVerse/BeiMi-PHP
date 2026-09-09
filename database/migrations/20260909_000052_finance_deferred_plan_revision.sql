-- 当前待摊计划由追加修订投影，原合同与期初来源保持不变。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_deferred_plan_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `previous_revision_id` bigint unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_deferred_plan_document` (`tenant_id`,`document_id`),
  UNIQUE KEY `uk_deferred_plan_previous` (`tenant_id`,`source_ref`,`previous_revision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
