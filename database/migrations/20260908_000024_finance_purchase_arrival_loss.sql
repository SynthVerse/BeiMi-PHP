-- 实收量已排除的到货异常损失：保留实物事实，另建损失成本份额。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_arrival_loss` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `arrival_line_id` bigint unsigned NOT NULL,
  `review_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_arrival_loss_source` (`tenant_id`,`arrival_line_id`),
  UNIQUE KEY `uk_finance_arrival_loss_document` (`tenant_id`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
