-- JXC generic task management schema.
-- Physical table prefix is explicit for migration safety: la_.
-- Per-tenant system roles/types are seeded idempotently by WorkTaskService::ensureSystemDefaults().

CREATE TABLE IF NOT EXISTS `la_task_employee` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务员工ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '员工名称',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '绑定用户ID',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '绑定租户管理员ID',
  `mobile` varchar(30) NOT NULL DEFAULT '' COMMENT '手机号',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_admin` (`tenant_id`, `admin_id`),
  KEY `idx_tenant_user` (`tenant_id`, `user_id`),
  KEY `idx_tenant_enabled` (`tenant_id`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC任务员工表';

CREATE TABLE IF NOT EXISTS `la_task_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务角色ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '角色编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '角色名称',
  `is_system` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否系统角色',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC任务角色表';

CREATE TABLE IF NOT EXISTS `la_task_employee_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '员工角色ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '任务员工ID',
  `role_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色ID',
  `role_code` varchar(50) NOT NULL DEFAULT '' COMMENT '角色编码快照',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_role` (`tenant_id`, `employee_id`, `role_code`),
  KEY `idx_tenant_role` (`tenant_id`, `role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC任务员工角色表';

CREATE TABLE IF NOT EXISTS `la_task_type` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务类型ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '类型编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '类型名称',
  `is_system` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否系统类型',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC任务类型表';

CREATE TABLE IF NOT EXISTS `la_task_type_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '类型角色ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `type_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '任务类型ID',
  `role_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色ID',
  `type_code` varchar(50) NOT NULL DEFAULT '' COMMENT '类型编码快照',
  `role_code` varchar(50) NOT NULL DEFAULT '' COMMENT '角色编码快照',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_role` (`tenant_id`, `type_code`, `role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC任务类型角色表';

CREATE TABLE IF NOT EXISTS `la_work_task` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '工作任务ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `sn` varchar(64) NOT NULL DEFAULT '' COMMENT '任务编号',
  `type_code` varchar(50) NOT NULL DEFAULT '' COMMENT '任务类型编码',
  `type_name` varchar(100) NOT NULL DEFAULT '' COMMENT '任务类型名称',
  `source_type` varchar(50) NOT NULL DEFAULT '' COMMENT '来源类型',
  `source_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源ID',
  `source_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '来源单号',
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售预定ID',
  `reservation_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '销售预定单号',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '标题',
  `content` text NULL COMMENT '内容',
  `assignee_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '执行员工ID',
  `assignee_employee_name` varchar(100) NOT NULL DEFAULT '' COMMENT '执行员工名称快照',
  `status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending/processing/completed/cancelled',
  `progress_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '进度数量',
  `target_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '目标数量',
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `update_by` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新人',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sn` (`tenant_id`, `sn`),
  KEY `idx_tenant_source` (`tenant_id`, `source_type`, `source_id`),
  KEY `idx_tenant_reservation` (`tenant_id`, `type_code`, `reservation_id`, `status`),
  KEY `idx_tenant_status` (`tenant_id`, `status`),
  KEY `idx_tenant_assignee` (`tenant_id`, `assignee_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC工作任务表';

CREATE TABLE IF NOT EXISTS `la_work_task_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务日志ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '工作任务ID',
  `action` varchar(50) NOT NULL DEFAULT '' COMMENT '动作',
  `content` varchar(500) NOT NULL DEFAULT '' COMMENT '内容',
  `operator_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作员工ID',
  `operator_admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作管理员ID',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`, `task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC工作任务日志表';

-- Optional tenant_id=0 templates document the system role/type set.
INSERT IGNORE INTO `la_task_role` (`tenant_id`, `code`, `name`, `is_system`, `is_enabled`, `create_time`, `update_time`) VALUES
(0, 'manager', '店长', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(0, 'procurement', '采购', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(0, 'delivery', '配送', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(0, 'packing', '打包', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT IGNORE INTO `la_task_type` (`tenant_id`, `code`, `name`, `is_system`, `is_enabled`, `create_time`, `update_time`) VALUES
(0, 'procurement', '采购任务', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(0, 'delivery', '配送任务', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(0, 'packing', '打包任务', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(0, 'sales_convert', '预定转销售', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
