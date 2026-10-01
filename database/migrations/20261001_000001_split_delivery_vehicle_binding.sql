-- 将旧的客户私有候选车辆升级为租户共享车辆档案与客户车辆绑定。

CREATE TABLE IF NOT EXISTS `{{prefix}}delivery_vehicle` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `plate_number` varchar(32) NOT NULL DEFAULT '',
  `driver_phone` varchar(20) NOT NULL DEFAULT '',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `version` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_delivery_vehicle_tenant_plate` (`tenant_id`,`plate_number`),
  KEY `idx_delivery_vehicle_tenant_status` (`tenant_id`,`is_enabled`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户共享车辆档案';

CREATE TABLE IF NOT EXISTS `{{prefix}}delivery_vehicle_migration_conflict` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `plate_number` varchar(32) NOT NULL DEFAULT '',
  `conflict_type` varchar(32) NOT NULL DEFAULT '',
  `values_snapshot` text NOT NULL,
  `binding_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_delivery_vehicle_migration_conflict` (`tenant_id`,`plate_number`,`conflict_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='车辆主档迁移冲突留痕';

SET @vehicle_id_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_delivery_vehicle'
    AND COLUMN_NAME = 'vehicle_id'
);
SET @add_vehicle_id_sql := IF(
  @vehicle_id_exists = 0,
  'ALTER TABLE `{{prefix}}customer_delivery_vehicle` ADD COLUMN `vehicle_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `tenant_id`',
  'SELECT 1'
);
PREPARE add_vehicle_id_stmt FROM @add_vehicle_id_sql;
EXECUTE add_vehicle_id_stmt;
DEALLOCATE PREPARE add_vehicle_id_stmt;

-- 同租户、同车牌存在多个历史司机电话时保留冲突证据，主档电话留空；
-- 每条客户绑定仍保留自己的旧电话，供迁移后继续展示和人工核对。
INSERT INTO `{{prefix}}delivery_vehicle_migration_conflict` (
  `tenant_id`, `plate_number`, `conflict_type`, `values_snapshot`,
  `binding_count`, `create_time`, `update_time`
)
SELECT
  `tenant_id`,
  UPPER(TRIM(`plate_number`)),
  'driver_phone',
  GROUP_CONCAT(DISTINCT TRIM(`driver_phone`) ORDER BY TRIM(`driver_phone`) SEPARATOR ' | '),
  COUNT(*),
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `{{prefix}}customer_delivery_vehicle`
WHERE `delete_time` IS NULL
  AND TRIM(`plate_number`) <> ''
  AND TRIM(`driver_phone`) <> ''
GROUP BY `tenant_id`, UPPER(TRIM(`plate_number`))
HAVING COUNT(DISTINCT TRIM(`driver_phone`)) > 1
ON DUPLICATE KEY UPDATE
  `values_snapshot` = VALUES(`values_snapshot`),
  `binding_count` = VALUES(`binding_count`),
  `update_time` = VALUES(`update_time`);

INSERT INTO `{{prefix}}delivery_vehicle` (
  `tenant_id`, `plate_number`, `driver_phone`, `is_enabled`, `operator_id`,
  `version`, `create_time`, `update_time`, `delete_time`
)
SELECT
  `tenant_id`,
  UPPER(TRIM(`plate_number`)),
  CASE
    WHEN COUNT(DISTINCT NULLIF(TRIM(`driver_phone`), '')) <= 1 THEN MAX(TRIM(`driver_phone`))
    ELSE ''
  END,
  MAX(`is_enabled`),
  MAX(`operator_id`),
  1,
  MIN(`create_time`),
  MAX(`update_time`),
  NULL
FROM `{{prefix}}customer_delivery_vehicle`
WHERE `delete_time` IS NULL
  AND TRIM(`plate_number`) <> ''
GROUP BY `tenant_id`, UPPER(TRIM(`plate_number`))
ON DUPLICATE KEY UPDATE
  `driver_phone` = IF(`{{prefix}}delivery_vehicle`.`driver_phone` = '', VALUES(`driver_phone`), `{{prefix}}delivery_vehicle`.`driver_phone`),
  `update_time` = GREATEST(`{{prefix}}delivery_vehicle`.`update_time`, VALUES(`update_time`));

UPDATE `{{prefix}}customer_delivery_vehicle` AS binding
INNER JOIN `{{prefix}}delivery_vehicle` AS vehicle
  ON vehicle.`tenant_id` = binding.`tenant_id`
 AND vehicle.`plate_number` = UPPER(TRIM(binding.`plate_number`))
SET binding.`vehicle_id` = vehicle.`id`
WHERE binding.`vehicle_id` = 0;

SET @vehicle_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}customer_delivery_vehicle'
    AND INDEX_NAME = 'idx_customer_delivery_vehicle_vehicle'
);
SET @add_vehicle_index_sql := IF(
  @vehicle_index_exists = 0,
  'ALTER TABLE `{{prefix}}customer_delivery_vehicle` ADD INDEX `idx_customer_delivery_vehicle_vehicle` (`tenant_id`,`vehicle_id`,`is_enabled`,`earliest_delivery_time`,`id`)',
  'SELECT 1'
);
PREPARE add_vehicle_index_stmt FROM @add_vehicle_index_sql;
EXECUTE add_vehicle_index_stmt;
DEALLOCATE PREPARE add_vehicle_index_stmt;
