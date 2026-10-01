-- 租户共享车辆档案：车牌与司机电话属于车辆本身。

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

-- 客户车辆绑定：客户自己的送货时间与交接地点保存在关联上。

CREATE TABLE IF NOT EXISTS `{{prefix}}customer_delivery_vehicle` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `vehicle_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
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
  KEY `idx_customer_delivery_vehicle_vehicle` (`tenant_id`,`vehicle_id`,`is_enabled`,`earliest_delivery_time`,`id`),
  KEY `idx_customer_delivery_vehicle_plate` (`tenant_id`,`plate_number`,`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户车辆绑定资料';
