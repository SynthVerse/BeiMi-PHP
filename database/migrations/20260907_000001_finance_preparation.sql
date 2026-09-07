-- 财务准备阶段只保存账户档案和启用准备草稿，不改变旧账、库存或正式余额。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_preparation` (
  `tenant_id` int unsigned NOT NULL,
  `activation_date` date DEFAULT NULL,
  `inventory_cost_reviewed` tinyint unsigned NOT NULL DEFAULT 0,
  `legacy_settlement_reviewed` tinyint unsigned NOT NULL DEFAULT 0,
  `excluded_business_reviewed` tinyint unsigned NOT NULL DEFAULT 0,
  `notes` varchar(1000) NOT NULL DEFAULT '',
  `version` int unsigned NOT NULL DEFAULT 0,
  `operator_id` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店财务启用准备草稿，不代表正式启用';

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_account` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `name` varchar(60) NOT NULL,
  `account_type` varchar(20) NOT NULL,
  `is_enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `version` int unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_account_name` (`tenant_id`,`name`),
  KEY `idx_finance_account_enabled` (`tenant_id`,`is_enabled`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店资金账户档案，无直接改余额入口';

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_setup_action` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `idempotency_key` varchar(96) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `action_type` varchar(32) NOT NULL,
  `operator_id` int unsigned NOT NULL,
  `before_data` mediumtext NOT NULL,
  `result_data` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_setup_action` (`tenant_id`,`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='财务准备操作审计及提交重试结果';
