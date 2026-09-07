-- 对账快照与客户回复分别追加保存，不改写账务来源或客户余额。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_statement` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `previous_id` bigint unsigned NOT NULL DEFAULT 0,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `snapshot` longtext NOT NULL,
  `actor` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_statement_customer` (`tenant_id`,`customer_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_statement_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `statement_id` bigint unsigned NOT NULL,
  `kind` varchar(24) NOT NULL,
  `payload` mediumtext NOT NULL,
  `actor` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_statement_event` (`tenant_id`,`statement_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_statement_dispute` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `statement_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `reason` text NOT NULL,
  `ledger_uncertain` tinyint unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_statement_dispute_source` (`tenant_id`,`source_ref`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_statement_resolution` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `dispute_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `resolution` varchar(24) NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_statement_resolution` (`tenant_id`,`dispute_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
