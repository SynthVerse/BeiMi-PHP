-- 客户报货后的纸质工票调度、员工能力与显式电子权限。
ALTER TABLE `{{prefix}}customer_report`
  ADD COLUMN `delivery_date` date DEFAULT NULL COMMENT '所属配送日期' AFTER `submitted_time`,
  ADD COLUMN `is_supplement` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否为补报' AFTER `delivery_date`;

CREATE TABLE IF NOT EXISTS `{{prefix}}work_process` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `code` varchar(40) NOT NULL DEFAULT '',
  `name` varchar(60) NOT NULL DEFAULT '',
  `trigger_type` varchar(32) NOT NULL DEFAULT 'remark' COMMENT 'remark/shortage/group_ready/ticket_recovered/manual',
  `trigger_keywords` varchar(500) NOT NULL DEFAULT '[]' COMMENT 'JSON 字符串数组',
  `sort` int(11) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `is_system` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_work_process_code` (`tenant_id`,`code`),
  KEY `idx_tenant_work_process_status` (`tenant_id`,`is_enabled`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='可编辑工序目录';

CREATE TABLE IF NOT EXISTS `{{prefix}}employee` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(60) NOT NULL DEFAULT '',
  `mobile` varchar(30) NOT NULL DEFAULT '',
  `bind_user_id` int(11) UNSIGNED DEFAULT NULL COMMENT '可选的小程序用户ID，纸质员工为NULL',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_employee_mobile` (`tenant_id`,`mobile`),
  UNIQUE KEY `uk_tenant_employee_user` (`tenant_id`,`bind_user_id`),
  KEY `idx_tenant_employee_status` (`tenant_id`,`is_enabled`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='员工档案';

CREATE TABLE IF NOT EXISTS `{{prefix}}employee_process` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `process_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_process` (`tenant_id`,`employee_id`,`process_id`),
  KEY `idx_employee_process_capability` (`tenant_id`,`process_id`,`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='员工可执行工序（手工选择）';

CREATE TABLE IF NOT EXISTS `{{prefix}}employee_permission` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `permission_key` varchar(64) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_permission` (`tenant_id`,`employee_id`,`permission_key`),
  KEY `idx_employee_permission_key` (`tenant_id`,`permission_key`,`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='员工电子权限（显式勾选）';

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_task_group` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delivery_date` date DEFAULT NULL,
  `is_supplement` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_fulfillment_group_report` (`tenant_id`,`report_id`),
  KEY `idx_tenant_fulfillment_group_batch` (`tenant_id`,`delivery_date`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一张客户报货对应的任务组';

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_task` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `group_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `report_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `process_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `task_type` varchar(24) NOT NULL DEFAULT 'process' COMMENT 'process/exception',
  `exception_code` varchar(40) NOT NULL DEFAULT '',
  `source_key` varchar(96) NOT NULL DEFAULT '',
  `depends_on_task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'unassigned',
  `assignee_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `assignee_name` varchar(60) NOT NULL DEFAULT '',
  `customer_name` varchar(100) NOT NULL DEFAULT '',
  `goods_name` varchar(200) NOT NULL DEFAULT '',
  `planned_qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `unit_name` varchar(50) NOT NULL DEFAULT '',
  `requirement` varchar(500) NOT NULL DEFAULT '',
  `ticket_no` varchar(40) NOT NULL DEFAULT '',
  `print_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `last_print_status` varchar(24) NOT NULL DEFAULT '',
  `last_print_error` varchar(255) NOT NULL DEFAULT '',
  `last_print_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `actual_weight` decimal(18,2) NOT NULL DEFAULT 0.00,
  `actual_price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `is_settlement_task` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '该商品行唯一结算工票快照',
  `recovery_note` varchar(500) NOT NULL DEFAULT '',
  `recovered_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `recovered_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_fulfillment_task_source` (`tenant_id`,`report_id`,`source_key`),
  KEY `idx_tenant_fulfillment_task_board` (`tenant_id`,`status`,`process_id`),
  KEY `idx_tenant_fulfillment_task_group` (`tenant_id`,`group_id`,`id`),
  KEY `idx_tenant_fulfillment_task_settlement` (`tenant_id`,`report_item_id`,`is_settlement_task`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报货履约任务/纸质工票';

CREATE TABLE IF NOT EXISTS `{{prefix}}fulfillment_print_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `operator_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `error_message` varchar(255) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_fulfillment_print_task` (`tenant_id`,`task_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='蓝牙工票打印日志';
