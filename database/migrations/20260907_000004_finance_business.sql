-- 正式业务草稿、确认命令、合法未结来源和追加式影响分开保存。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_document` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `type` varchar(40) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `version` int unsigned NOT NULL DEFAULT 1,
  `payload` mediumtext NOT NULL,
  `confirmed_result` mediumtext NOT NULL,
  `created_by` text NOT NULL,
  `last_modified_by` text NOT NULL,
  `confirmed_by` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  `confirmed_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_finance_document_status` (`tenant_id`,`type`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_command` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `idempotency_key` varchar(96) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `action` varchar(24) NOT NULL,
  `actor` text NOT NULL,
  `result` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_finance_command` (`tenant_id`,`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_source` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `category` varchar(32) NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `business_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `snapshot` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_finance_source_subject` (`tenant_id`,`category`,`subject_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_entry` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL DEFAULT '',
  `metric` varchar(24) NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `subject_id` bigint unsigned NOT NULL DEFAULT 0,
  `amount` decimal(16,2) NOT NULL,
  `business_date` date DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `posting_month` char(7) NOT NULL,
  `details` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_finance_entry_source` (`tenant_id`,`source_ref`,`id`),
  KEY `idx_finance_entry_month` (`tenant_id`,`posting_month`,`metric`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_money_transaction` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `direction` varchar(8) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `actual_date` date NOT NULL,
  `external_key` char(64) DEFAULT NULL,
  `evidence` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_finance_external_transaction` (`external_key`),
  KEY `idx_finance_money_risk` (`tenant_id`,`account_id`,`direction`,`actual_date`,`amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_period` (
  `tenant_id` int unsigned NOT NULL,
  `month` char(7) NOT NULL,
  `status` varchar(16) NOT NULL,
  `snapshot` longtext NOT NULL,
  `closed_by` text NOT NULL,
  `closed_at` int unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_type` varchar(40) NOT NULL,
  `file_id` bigint unsigned NOT NULL,
  `snapshot` text NOT NULL,
  `created_by` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_finance_evidence_file` (`tenant_id`,`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_correction` (
  `tenant_id` int unsigned NOT NULL,
  `original_document_id` bigint unsigned NOT NULL,
  `replacement_document_id` bigint unsigned NOT NULL,
  `reason` text NOT NULL,
  `actor` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`original_document_id`),
  UNIQUE KEY `uk_finance_correction_replacement` (`tenant_id`,`replacement_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_transaction_identity` (
  `external_key` char(64) NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `transaction_id` bigint unsigned NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`external_key`), KEY `idx_finance_identity_transaction` (`tenant_id`,`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
