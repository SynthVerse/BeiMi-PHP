-- 商品单位换算配置表
CREATE TABLE IF NOT EXISTS `la_tenant_product_unit_conversion` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `tenant_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `product_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '商品ID',
  `target_unit_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '目标单位ID',
  `target_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '目标单位名称',
  `conversion_rate` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '换算率',
  `create_time` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_product` (`tenant_id`, `product_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品单位换算配置表';
