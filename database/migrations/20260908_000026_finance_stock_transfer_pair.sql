-- 权威调拨入口保存两条实物流水的明确配对；不能通过相邻编号猜测历史调拨。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_stock_transfer_pair` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `outbound_flow_id` bigint unsigned NOT NULL,
  `inbound_flow_id` bigint unsigned NOT NULL,
  `from_warehouse_id` int unsigned NOT NULL,
  `to_warehouse_id` int unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `occurred_at` int unsigned NOT NULL,
  `snapshot` mediumtext NOT NULL,
  `actor` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_transfer_out` (`tenant_id`,`outbound_flow_id`),
  UNIQUE KEY `uk_stock_transfer_in` (`tenant_id`,`inbound_flow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
