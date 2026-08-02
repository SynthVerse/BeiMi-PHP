-- 客户报货转标准销售单：来源追溯、商业计价数量与库存基础数量分离。

ALTER TABLE `{{prefix}}sales_order`
ADD COLUMN `source_type` varchar(32) NULL DEFAULT NULL COMMENT '来源业务类型' AFTER `datetimesingle`;

ALTER TABLE `{{prefix}}sales_order`
ADD COLUMN `source_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '来源业务主单ID' AFTER `source_type`;

ALTER TABLE `{{prefix}}sales_order`
ADD COLUMN `source_version` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '来源业务版本' AFTER `source_id`;

ALTER TABLE `{{prefix}}sales_order`
ADD UNIQUE KEY `uk_tenant_sales_source_warehouse` (`tenant_id`,`source_type`,`source_id`,`warehouse_id`);

ALTER TABLE `{{prefix}}order_goods`
ADD COLUMN `base_quantity` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '库存基础单位数量' AFTER `number`;

ALTER TABLE `{{prefix}}order_goods`
ADD COLUMN `pricing_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '计价单位ID' AFTER `amount`;

ALTER TABLE `{{prefix}}order_goods`
ADD COLUMN `source_line_type` varchar(32) NOT NULL DEFAULT '' COMMENT '来源明细类型' AFTER `pricing_unit_id`;

ALTER TABLE `{{prefix}}order_goods`
ADD COLUMN `source_line_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源明细ID' AFTER `source_line_type`;

ALTER TABLE `{{prefix}}order_goods`
ADD KEY `idx_tenant_sales_source_line` (`tenant_id`,`source_line_type`,`source_line_id`);
