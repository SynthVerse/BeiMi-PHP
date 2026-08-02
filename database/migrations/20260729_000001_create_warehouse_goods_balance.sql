CREATE TABLE IF NOT EXISTS `{{prefix}}warehouse_goods_balance` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '仓库商品库存余额ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `base_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建余额时的基础单位ID快照',
  `base_unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '创建余额时的基础单位名称快照',
  `on_hand_qty` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '现存量',
  `reserved_qty` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '已预留量',
  `available_qty` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '可用量',
  `version` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '并发版本',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_warehouse_goods_unit` (`tenant_id`, `warehouse_id`, `goods_id`, `base_unit_id`),
  KEY `idx_tenant_goods` (`tenant_id`, `goods_id`),
  CONSTRAINT `chk_warehouse_goods_on_hand_non_negative` CHECK (`on_hand_qty` >= 0),
  CONSTRAINT `chk_warehouse_goods_reserved_non_negative` CHECK (`reserved_qty` >= 0),
  CONSTRAINT `chk_warehouse_goods_available_consistent` CHECK (`available_qty` = (`on_hand_qty` - `reserved_qty`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='仓库商品库存权威余额表';

ALTER TABLE `{{prefix}}goods`
  MODIFY COLUMN `stock` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '库存汇总展示';

ALTER TABLE `{{prefix}}stock_flow`
  MODIFY COLUMN `quantity` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '变动数量',
  MODIFY COLUMN `before_stock` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '变动前仓库现存量',
  MODIFY COLUMN `after_stock` decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '变动后仓库现存量';
