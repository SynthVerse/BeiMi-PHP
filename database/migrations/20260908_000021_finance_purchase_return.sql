-- 实物退离与供应商认可分离保存；本表不形成应付核销或退款。
-- 非交付来源没有 delivery_item_id；NULL 不占用交付明细唯一键，保留已有交付来源约束。
ALTER TABLE `{{prefix}}negative_inventory_attribution` MODIFY COLUMN `delivery_item_id` int(11) UNSIGNED NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_purchase_return_line` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `arrival_line_id` bigint unsigned NOT NULL,
  `vendor_id` int unsigned NOT NULL,
  `warehouse_id` int unsigned NOT NULL,
  `sku_id` int unsigned NOT NULL,
  `business_date` date NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `snapshot` json NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finance_purchase_return_line` (`tenant_id`,`document_id`,`arrival_line_id`),
  KEY `idx_finance_purchase_return_arrival` (`tenant_id`,`arrival_line_id`,`id`),
  KEY `idx_finance_purchase_return_vendor` (`tenant_id`,`vendor_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
