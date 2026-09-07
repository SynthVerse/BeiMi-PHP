CREATE TABLE IF NOT EXISTS `{{prefix}}finance_sales_print` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `sales_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `copy_no` int unsigned NOT NULL,
  `idempotency_key` varchar(96) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `actor` text NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `prepared_result` mediumtext NOT NULL,
  `error_message` varchar(255) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_sales_print_command` (`tenant_id`,`idempotency_key`),
  UNIQUE KEY `uk_finance_sales_print_copy` (`tenant_id`,`sales_id`,`copy_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
