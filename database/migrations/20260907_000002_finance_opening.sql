-- 期初承接单独保存，不补造收付款、销售单，也不重复增加实物库存。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_opening_book` (
  `tenant_id` int unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `version` int unsigned NOT NULL DEFAULT 0,
  `reviews` mediumtext NOT NULL,
  `submitted_hash` char(64) NOT NULL DEFAULT '',
  `confirmed_snapshot` mediumtext NOT NULL,
  `created_by` text NOT NULL,
  `last_modified_by` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  `confirmed_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='期初草稿、待确认与不可变启用快照';

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_opening_item` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `category` varchar(32) NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `amount` decimal(16,2) DEFAULT NULL,
  `historical_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `source_mode` varchar(16) NOT NULL,
  `source_reference` varchar(200) NOT NULL,
  `evidence` varchar(1000) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_opening_category` (`tenant_id`,`category`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='期初明细草稿，金额未知保持NULL';

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_opening_source` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `opening_item_id` bigint unsigned NOT NULL,
  `category` varchar(32) NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `activation_date` date NOT NULL,
  `source_snapshot` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_opening_source` (`tenant_id`,`opening_item_id`),
  KEY `idx_opening_subject` (`tenant_id`,`category`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='已确认期初合法来源，不记录当期收入费用或现金交易';
