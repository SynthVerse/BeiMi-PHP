-- 成本事件与差额只追加；来源当前值、份额和待补数量是可重建投影。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_cost_origin` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `origin_key` varchar(160) COLLATE utf8mb4_bin NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `quantity` decimal(30,12) NOT NULL,
  `initial_amount` decimal(16,2) DEFAULT NULL,
  `current_amount` decimal(16,2) DEFAULT NULL,
  `snapshot` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cost_origin` (`tenant_id`,`origin_key`),
  KEY `idx_cost_origin_sku` (`tenant_id`,`sku_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_cost_position` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `position_key` char(64) NOT NULL,
  `origin_key` varchar(160) COLLATE utf8mb4_bin NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `bucket` varchar(16) NOT NULL,
  `reference` varchar(160) COLLATE utf8mb4_bin NOT NULL,
  `quantity` decimal(30,12) NOT NULL,
  `value` decimal(24,6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cost_position` (`tenant_id`,`position_key`),
  KEY `idx_cost_position_sku` (`tenant_id`,`sku_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_cost_shortage` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `position_key` char(64) NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `bucket` varchar(16) NOT NULL,
  `reference` varchar(160) COLLATE utf8mb4_bin NOT NULL,
  `quantity` decimal(30,12) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cost_shortage` (`tenant_id`,`position_key`),
  KEY `idx_cost_shortage_sku` (`tenant_id`,`sku_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_cost_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `reference` varchar(160) COLLATE utf8mb4_bin NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `event_type` varchar(20) NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL DEFAULT 0,
  `business_date` date NOT NULL,
  `snapshot` mediumtext NOT NULL,
  `result` mediumtext NOT NULL,
  `actor` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cost_event` (`tenant_id`,`reference`),
  KEY `idx_cost_event_sku` (`tenant_id`,`sku_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}finance_cost_effect` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `origin_key` varchar(160) COLLATE utf8mb4_bin NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `bucket` varchar(16) NOT NULL,
  `reference` varchar(160) COLLATE utf8mb4_bin NOT NULL,
  `quantity_delta` decimal(30,12) NOT NULL,
  `value_delta` decimal(24,6) NOT NULL,
  `business_date` date NOT NULL,
  `posting_month` char(7) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cost_effect_period` (`tenant_id`,`posting_month`,`bucket`,`id`),
  KEY `idx_cost_effect_destination` (`tenant_id`,`sku_id`,`warehouse_id`,`bucket`,`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
