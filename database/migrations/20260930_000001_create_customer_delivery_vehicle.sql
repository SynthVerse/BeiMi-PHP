-- 客户送货资料：直接随客户维护的可复用候选车辆，不建立独立车辆档案或线车班次。

CREATE TABLE IF NOT EXISTS `{{prefix}}customer_delivery_vehicle` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `earliest_delivery_time` char(5) NOT NULL DEFAULT '' COMMENT '客户最早允许送货时刻 HH:MM',
  `plate_number` varchar(32) NOT NULL DEFAULT '',
  `vehicle_location` varchar(255) NOT NULL DEFAULT '' COMMENT '车辆停车或交接地点',
  `driver_phone` varchar(20) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `version` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer_delivery_vehicle_customer` (`tenant_id`,`customer_id`,`is_enabled`,`sort`,`earliest_delivery_time`,`id`),
  KEY `idx_customer_delivery_vehicle_plate` (`tenant_id`,`plate_number`,`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户候选车辆送货资料';
