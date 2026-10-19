CREATE TABLE IF NOT EXISTS `{{prefix}}pending_goods` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `origin_key` varchar(80) NOT NULL,
 `report_id` int unsigned NOT NULL,
 `purchase_batch_id` int unsigned NOT NULL,
 `warehouse_id` int unsigned NOT NULL,
 `goods_id` int unsigned NOT NULL,
 `sku_id` int unsigned NOT NULL,
 `total_qty` decimal(18,4) NOT NULL,
 `pending_qty` decimal(18,4) NOT NULL,
 `return_qty` decimal(18,4) NOT NULL DEFAULT 0,
 `kept_qty` decimal(18,4) NOT NULL DEFAULT 0,
 `returned_qty` decimal(18,4) NOT NULL DEFAULT 0,
 `version` int unsigned NOT NULL DEFAULT 1,
 `create_time` int unsigned NOT NULL,
 `update_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_origin` (`tenant_id`,`origin_key`),
 KEY `idx_report` (`tenant_id`,`report_id`), KEY `idx_batch` (`tenant_id`,`purchase_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='取消后待定货物；与库存预留同事务转移';
CREATE TABLE IF NOT EXISTS `{{prefix}}pending_goods_action` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `pending_goods_id` bigint unsigned NOT NULL,
 `idempotency_key` varchar(96) NOT NULL,
 `fingerprint` char(64) NOT NULL,
 `action` varchar(30) NOT NULL,
 `quantity` decimal(18,4) NOT NULL,
 `operator_id` int unsigned NOT NULL,
 `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_command` (`tenant_id`,`idempotency_key`),
 KEY `idx_pending` (`tenant_id`,`pending_goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}pending_goods_return` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `pending_goods_id` bigint unsigned NOT NULL,
 `supply_order_id` int unsigned NOT NULL,
 `quantity` decimal(18,4) NOT NULL,
 `remaining_qty` decimal(18,4) NOT NULL,
 `status` varchar(20) NOT NULL DEFAULT 'planned',
 `return_order_id` int unsigned NOT NULL DEFAULT 0,
 `create_time` int unsigned NOT NULL,
 `update_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), KEY `idx_pending` (`tenant_id`,`pending_goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}pending_goods_return_order` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `pending_return_id` bigint unsigned NOT NULL,
 `return_order_id` int unsigned NOT NULL,
 `quantity` decimal(18,4) NOT NULL,
 `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_return_order` (`tenant_id`,`return_order_id`), KEY `idx_pending_return` (`tenant_id`,`pending_return_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}pending_goods_return_fact` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `pending_return_id` bigint unsigned NOT NULL,
 `legacy_order_id` int unsigned DEFAULT NULL,
 `finance_line_id` bigint unsigned DEFAULT NULL,
 `quantity` decimal(18,4) NOT NULL,
 `closed` tinyint unsigned NOT NULL DEFAULT 0,
 `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_legacy` (`tenant_id`,`legacy_order_id`), UNIQUE KEY `uk_finance` (`tenant_id`,`finance_line_id`),
 KEY `idx_pending_return` (`tenant_id`,`pending_return_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}pending_goods_return_resolution` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `fact_id` bigint unsigned NOT NULL,
 `kind` varchar(20) NOT NULL,
 `quantity` decimal(18,4) NOT NULL DEFAULT 0,
 `amount` decimal(18,2) NOT NULL DEFAULT 0,
 `note` varchar(500) NOT NULL,
 `operator_id` int unsigned NOT NULL,
 `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), KEY `idx_fact` (`tenant_id`,`fact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
