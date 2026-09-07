-- 到货差复核只追加，原报量、实收量和到货时的阈值快照保持不变。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_difference_review` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `arrival_line_id` bigint unsigned NOT NULL,
  `classification` varchar(20) NOT NULL,
  `resolved` tinyint unsigned NOT NULL DEFAULT 0,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_difference_review_document` (`tenant_id`,`document_id`,`arrival_line_id`),
  KEY `idx_finance_difference_review_arrival` (`tenant_id`,`arrival_line_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
