-- 销售单表追加来源订货单ID字段
ALTER TABLE `la_sales_order` 
ADD COLUMN `from_purchase_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源订货单ID（0=非转换）' AFTER `datetimesingle`;

ALTER TABLE `la_sales_order`
ADD KEY `idx_from_purchase` (`from_purchase_order_id`);
