ALTER TABLE `{{prefix}}customer_report_reservation`
  ADD COLUMN `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '预留库存对应的SKU ID' AFTER `goods_id`,
  ADD KEY `idx_tenant_sku` (`tenant_id`, `sku_id`);
