-- 摊销与关联取消追加历史，不覆盖原月确认记录。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_deferred_amortization_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `benefit_month` char(7) NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `previous_document_id` bigint unsigned NOT NULL,
  `status` varchar(20) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_deferred_revision_document` (`tenant_id`,`document_id`),
  UNIQUE KEY `uk_deferred_revision_previous` (`tenant_id`,`previous_document_id`),
  KEY `idx_deferred_revision_source` (`tenant_id`,`source_ref`,`benefit_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
