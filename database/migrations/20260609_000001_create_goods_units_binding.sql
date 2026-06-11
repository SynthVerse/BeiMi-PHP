CREATE TABLE IF NOT EXISTS `la_goods_units_binding` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '绑定ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单位ID',
  `unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '单位名称快照',
  `is_base_unit` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否基础单位(1=是)',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态(1=启用)',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_goods_unit` (`tenant_id`, `goods_id`, `unit_id`),
  KEY `idx_tenant_goods` (`tenant_id`, `goods_id`),
  KEY `idx_base_unit` (`tenant_id`, `goods_id`, `is_base_unit`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品-计量单位绑定表';

INSERT IGNORE INTO `la_goods_units_binding`
  (`tenant_id`, `goods_id`, `unit_id`, `unit_name`, `is_base_unit`, `sort`, `status`, `create_time`, `update_time`)
SELECT
  `tenant_id`,
  `id`,
  `unit_id`,
  `units`,
  1,
  0,
  1,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `la_goods`
WHERE `unit_id` > 0;
