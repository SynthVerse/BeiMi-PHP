CREATE TABLE IF NOT EXISTS `{{prefix}}goods_dimension_setting` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '商品维度设置ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户维度定义ID',
  `usage_mode` varchar(20) NOT NULL DEFAULT 'sku' COMMENT '该商品中的用途：sku=参与SKU组合，descriptive=仅描述',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '该商品中的维度顺序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_goods_spec` (`tenant_id`, `goods_id`, `spec_id`),
  KEY `idx_tenant_goods_usage` (`tenant_id`, `goods_id`, `usage_mode`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品级维度用途设置';

INSERT IGNORE INTO `{{prefix}}goods_dimension_setting`
  (`tenant_id`, `goods_id`, `spec_id`, `usage_mode`, `sort`, `create_time`, `update_time`)
SELECT
  value.`tenant_id`,
  value.`goods_id`,
  value.`spec_id`,
  CASE WHEN spec.`dimension_type` = 'descriptive' THEN 'descriptive' ELSE 'sku' END,
  MIN(value.`sort`),
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `{{prefix}}goods_spec_value` value
INNER JOIN `{{prefix}}goods_spec` spec
  ON spec.`tenant_id` = value.`tenant_id` AND spec.`id` = value.`spec_id`
WHERE value.`status` = 1
GROUP BY value.`tenant_id`, value.`goods_id`, value.`spec_id`, spec.`dimension_type`;

INSERT IGNORE INTO `{{prefix}}goods_sku`
  (`tenant_id`, `goods_id`, `sku_name`, `sku_code`, `quality_status`, `quality_label`,
   `specification_status`, `specification_label`, `base_unit_id`, `base_unit_name`,
   `purchase_status`, `sale_status`, `status`, `sort`, `remark`, `is_auto_generated`,
   `dimension_disabled_snapshot`, `create_time`, `update_time`)
SELECT
  goods.`tenant_id`,
  goods.`id`,
  goods.`name`,
  CONCAT('SKU-', goods.`id`, '-BASE'),
  '',
  '',
  '',
  '',
  goods.`unit_id`,
  goods.`units`,
  1,
  1,
  1,
  0,
  '',
  1,
  0,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `{{prefix}}goods` goods
WHERE goods.`tenant_id` > 0
  AND NOT EXISTS (
    SELECT 1
    FROM `{{prefix}}goods_sku` sku
    WHERE sku.`tenant_id` = goods.`tenant_id`
      AND sku.`goods_id` = goods.`id`
  );
