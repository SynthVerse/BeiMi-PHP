-- 销售结算：交付实重、客户结算重量、计费重量差、抹零、应收与不可变版本快照。

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='settlement_version');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `settlement_version` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `settlement_status`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='goods_amount');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `goods_amount` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `order_arrears_money`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='rounding_amount');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `rounding_amount` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `goods_amount`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='rounding_reason');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `rounding_reason` varchar(500) NOT NULL DEFAULT '''' AFTER `rounding_amount`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='rounding_operator_id');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `rounding_operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `rounding_reason`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='rounding_time');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `rounding_time` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `rounding_operator_id`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='debt_after_order');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `debt_after_order` decimal(18,2) NOT NULL DEFAULT 0.00 AFTER `rounding_time`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='show_cumulative_debt');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `show_cumulative_debt` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `debt_after_order`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='settlement_time');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `settlement_time` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `show_cumulative_debt`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}sales_order' AND COLUMN_NAME='settlement_operator_id');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}sales_order` ADD COLUMN `settlement_operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `settlement_time`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}order_goods' AND COLUMN_NAME='customer_settlement_quantity');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}order_goods` ADD COLUMN `customer_settlement_quantity` decimal(18,4) NOT NULL DEFAULT 0.0000 AFTER `base_quantity`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}order_goods' AND COLUMN_NAME='billing_weight_difference');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}order_goods` ADD COLUMN `billing_weight_difference` decimal(18,4) NOT NULL DEFAULT 0.0000 AFTER `customer_settlement_quantity`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}order_goods' AND COLUMN_NAME='pricing_unit_name');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}order_goods` ADD COLUMN `pricing_unit_name` varchar(50) NOT NULL DEFAULT '''' AFTER `pricing_unit_id`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}order_goods' AND COLUMN_NAME='price_status');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}order_goods` ADD COLUMN `price_status` varchar(16) NOT NULL DEFAULT ''unpriced'' AFTER `pricing_unit_name`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}order_goods' AND COLUMN_NAME='zero_price_reason');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}order_goods` ADD COLUMN `zero_price_reason` varchar(500) NOT NULL DEFAULT '''' AFTER `price_status`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

SET @settlement_column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{{prefix}}order_goods' AND COLUMN_NAME='per_unit_weight');
SET @settlement_column_sql := IF(@settlement_column_exists=0, 'ALTER TABLE `{{prefix}}order_goods` ADD COLUMN `per_unit_weight` decimal(18,4) NOT NULL DEFAULT 0.0000 AFTER `zero_price_reason`', 'SELECT 1');
PREPARE settlement_column_stmt FROM @settlement_column_sql;
EXECUTE settlement_column_stmt;
DEALLOCATE PREPARE settlement_column_stmt;

CREATE TABLE IF NOT EXISTS `{{prefix}}sales_order_version` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `version` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_money` decimal(18,2) NOT NULL DEFAULT 0.00,
  `goods_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `rounding_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `debt_after_order` decimal(18,2) NOT NULL DEFAULT 0.00,
  `show_cumulative_debt` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `snapshot_json` longtext NOT NULL,
  `edit_reason` varchar(500) NOT NULL DEFAULT '',
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sales_order_version` (`tenant_id`,`order_id`,`version`),
  KEY `idx_tenant_sales_version_time` (`tenant_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户销售单不可变版本快照';

CREATE TABLE IF NOT EXISTS `{{prefix}}sales_settlement_action` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `action_type` varchar(32) NOT NULL DEFAULT 'submit',
  `idempotency_key` varchar(96) NOT NULL DEFAULT '',
  `request_fingerprint` char(64) NOT NULL DEFAULT '',
  `expected_version` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `target_version` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'processing',
  `snapshot_json` longtext NOT NULL,
  `result_json` longtext NULL,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sales_settlement_key` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_sales_settlement_order` (`tenant_id`,`order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售结算幂等动作';

CREATE TABLE IF NOT EXISTS `{{prefix}}sales_weight_difference_todo` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `settlement_action_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `target_version` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `differences_json` longtext NOT NULL,
  `assignee_scope` varchar(32) NOT NULL DEFAULT 'highest_privilege',
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `decision` varchar(16) NOT NULL DEFAULT '',
  `resolution_reason` varchar(500) NOT NULL DEFAULT '',
  `reviewer_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `resolved_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sales_weight_action` (`tenant_id`,`settlement_action_id`),
  KEY `idx_tenant_sales_weight_status` (`tenant_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户计费重量差最高权限待办';

CREATE TABLE IF NOT EXISTS `{{prefix}}customer_sales_preference` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `show_cumulative_debt` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_customer_sales_preference` (`tenant_id`,`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='主客户销售单展示偏好';

CREATE TABLE IF NOT EXISTS `{{prefix}}sales_settlement_setting` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `rounding_confirm_threshold` decimal(18,2) NOT NULL DEFAULT 999999.99,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sales_settlement_setting` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售结算阈值设置';

CREATE TABLE IF NOT EXISTS `{{prefix}}sales_delivery_correction` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `settlement_action_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `before_actual_quantity` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `after_actual_quantity` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `delta_quantity` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `direction` varchar(16) NOT NULL DEFAULT '',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sales_delivery_correction` (`tenant_id`,`settlement_action_id`,`order_goods_id`),
  KEY `idx_tenant_sales_delivery_order` (`tenant_id`,`order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售单实际交付重量录入更正';

-- 存量 customer_report 正式销售单已记过应收。迁移必须先固化为 V1，避免后续编辑把整单应收再次增加。
UPDATE `{{prefix}}order_goods` og
INNER JOIN `{{prefix}}sales_order` so ON so.tenant_id=og.tenant_id AND so.id=og.order_id
SET og.customer_settlement_quantity=og.base_quantity,
    og.billing_weight_difference=0.0000,
    og.pricing_unit_name=COALESCE(NULLIF((SELECT u.name FROM `{{prefix}}goods_unit` u WHERE u.tenant_id=og.tenant_id AND u.id=og.pricing_unit_id AND u.status=1 LIMIT 1), ''), NULLIF(og.units, ''), '未确认'),
    og.price_status=IF(og.price>0, 'priced', IF(og.price=0, 'zero', 'unpriced')),
    og.zero_price_reason=IF(og.price=0, '迁移确认：存量正式销售单零价', ''),
    og.per_unit_weight=0.0000
WHERE og.order_type='sales' AND so.source_type='customer_report' AND so.settlement_status='formal' AND so.settlement_version=0;

INSERT IGNORE INTO `{{prefix}}sales_order_version`
(`tenant_id`,`order_id`,`version`,`order_money`,`goods_amount`,`rounding_amount`,`debt_after_order`,`show_cumulative_debt`,`snapshot_json`,`edit_reason`,`operator_id`,`create_time`)
SELECT so.tenant_id,so.id,1,so.order_money,
       COALESCE((SELECT SUM(og.amount) FROM `{{prefix}}order_goods` og WHERE og.tenant_id=so.tenant_id AND og.order_id=so.id AND og.order_type='sales'),so.order_money),
       GREATEST(COALESCE((SELECT SUM(og.amount) FROM `{{prefix}}order_goods` og WHERE og.tenant_id=so.tenant_id AND og.order_id=so.id AND og.order_type='sales'),so.order_money)-so.order_money,0.00),
       COALESCE(c.order_receivable,so.order_arrears_money,0.00),0,
       CAST(JSON_OBJECT(
         'version',1,'migration_baseline',TRUE,'order_id',so.id,'customer_id',so.customer_id,'warehouse_id',so.warehouse_id,
         'lines',COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT(
           'order_goods_id',og2.id,'goods_id',og2.goods_id,'sku_id',og2.sku_id,'name',og2.name,
           'previous_actual_delivery_weight',og2.base_quantity,'actual_delivery_weight',og2.base_quantity,'actual_delivery_delta',0.0000,
           'customer_settlement_weight',og2.customer_settlement_quantity,'billing_weight_difference',og2.billing_weight_difference,
           'pricing_quantity',og2.number,'pricing_unit_id',og2.pricing_unit_id,'pricing_unit_name',og2.pricing_unit_name,
           'per_unit_weight',og2.per_unit_weight,'price',og2.price,'price_status',og2.price_status,
           'zero_price_reason',og2.zero_price_reason,'amount',og2.amount,'source_line_id',og2.source_line_id
         )) FROM `{{prefix}}order_goods` og2 WHERE og2.tenant_id=so.tenant_id AND og2.order_id=so.id AND og2.order_type='sales'),JSON_ARRAY()),
         'differences',JSON_ARRAY(),
         'goods_amount',COALESCE((SELECT SUM(og3.amount) FROM `{{prefix}}order_goods` og3 WHERE og3.tenant_id=so.tenant_id AND og3.order_id=so.id AND og3.order_type='sales'),so.order_money),
         'rounding_amount',GREATEST(COALESCE((SELECT SUM(og4.amount) FROM `{{prefix}}order_goods` og4 WHERE og4.tenant_id=so.tenant_id AND og4.order_id=so.id AND og4.order_type='sales'),so.order_money)-so.order_money,0.00),
         'rounding_reason','迁移生成存量正式销售单V1基线','order_money',so.order_money,
         'show_cumulative_debt',0,'edit_reason','迁移生成存量正式销售单V1基线',
         'debt_after_order',COALESCE(c.order_receivable,so.order_arrears_money,0.00),
         'settlement_time',IF(so.datetimesingle>0,so.datetimesingle,so.create_time)
       ) AS CHAR CHARACTER SET utf8mb4),
       '迁移生成存量正式销售单V1基线',COALESCE(so.admin_id,0),IF(so.datetimesingle>0,so.datetimesingle,so.create_time)
FROM `{{prefix}}sales_order` so
LEFT JOIN `{{prefix}}customer` c ON c.tenant_id=so.tenant_id AND c.id=so.customer_id
WHERE so.source_type='customer_report' AND so.settlement_status='formal' AND so.settlement_version=0;

UPDATE `{{prefix}}sales_order` so
INNER JOIN `{{prefix}}sales_order_version` sv ON sv.tenant_id=so.tenant_id AND sv.order_id=so.id AND sv.version=1
SET so.settlement_version=1,
    so.goods_amount=sv.goods_amount,
    so.rounding_amount=sv.rounding_amount,
    so.rounding_reason=IF(sv.rounding_amount>0,'迁移生成存量正式销售单V1基线',''),
    so.debt_after_order=sv.debt_after_order,
    so.show_cumulative_debt=0,
    so.settlement_time=IF(so.settlement_time>0,so.settlement_time,sv.create_time),
    so.settlement_operator_id=IF(so.settlement_operator_id>0,so.settlement_operator_id,sv.operator_id)
WHERE so.source_type='customer_report' AND so.settlement_status='formal' AND so.settlement_version=0;
