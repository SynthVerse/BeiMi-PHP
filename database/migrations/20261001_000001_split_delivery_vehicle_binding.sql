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

INSERT INTO `{{prefix}}delivery_vehicle` (
  `tenant_id`, `plate_number`, `driver_phone`, `is_enabled`, `operator_id`,
  `version`, `create_time`, `update_time`, `delete_time`
)
SELECT
  `tenant_id`,
  UPPER(TRIM(`plate_number`)),
  MAX(`driver_phone`),
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
