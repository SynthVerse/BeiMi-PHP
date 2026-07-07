-- Task center destructive rebuild.
-- This migration intentionally clears legacy work_task/task role history.
-- Do not run against production without an approved release window and backup.

DROP TABLE IF EXISTS `la_task_print_log`;
DROP TABLE IF EXISTS `la_work_task_log`;
DROP TABLE IF EXISTS `la_work_task`;
DROP TABLE IF EXISTS `la_task_type_role`;
DROP TABLE IF EXISTS `la_task_type`;
DROP TABLE IF EXISTS `la_task_role`;
DROP TABLE IF EXISTS `la_task_employee_role`;
DROP TABLE IF EXISTS `la_task_employee`;

CREATE TABLE `la_task_employee` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务员工ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '员工名称',
  `mobile` varchar(30) NOT NULL DEFAULT '' COMMENT '手机号',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '绑定用户ID',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '绑定租户管理员ID',
  `is_manager` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否店长',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用',
  `last_active_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近活跃时间',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_admin` (`tenant_id`, `admin_id`),
  KEY `idx_tenant_user` (`tenant_id`, `user_id`),
  KEY `idx_tenant_enabled` (`tenant_id`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务中心员工表';

CREATE TABLE `la_task_employee_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '员工角色ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '任务员工ID',
  `role_code` varchar(50) NOT NULL DEFAULT '' COMMENT 'packing/fish_kill/procurement/manager',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_role` (`tenant_id`, `employee_id`, `role_code`),
  KEY `idx_role` (`role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务中心员工角色表';

CREATE TABLE `la_work_task` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `sn` varchar(64) NOT NULL DEFAULT '' COMMENT '任务编号',
  `task_date` date NULL DEFAULT NULL COMMENT '任务日期',
  `task_kind` varchar(32) NOT NULL DEFAULT '' COMMENT 'fulfillment/procurement',
  `role_code` varchar(50) NOT NULL DEFAULT '' COMMENT 'packing/fish_kill/procurement/manager',
  `source_type` varchar(50) NOT NULL DEFAULT '' COMMENT '来源类型',
  `source_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源ID',
  `parent_task_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父任务ID',
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售预定ID',
  `reservation_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '销售预定单号',
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户ID',
  `customer_name` varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `goods_name` varchar(200) NOT NULL DEFAULT '' COMMENT '商品名称',
  `goods_code` varchar(100) NOT NULL DEFAULT '' COMMENT '商品编码',
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '单位ID',
  `unit_name` varchar(50) NOT NULL DEFAULT '' COMMENT '单位名称',
  `demand_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '需求数量',
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '已占用库存数量',
  `shortage_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '缺口数量',
  `progress_num` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '进度数量',
  `stock_status` varchar(32) NOT NULL DEFAULT 'enough' COMMENT 'enough/shortage/procurement_done',
  `assignee_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '执行员工ID',
  `assignee_employee_name` varchar(100) NOT NULL DEFAULT '' COMMENT '执行员工名称快照',
  `assigned_by` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分配人',
  `assigned_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分配时间',
  `status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending/assigned/processing/blocked/completed/cancelled',
  `priority` varchar(32) NOT NULL DEFAULT 'normal' COMMENT 'normal/high/urgent',
  `status_reason` varchar(500) NOT NULL DEFAULT '' COMMENT '状态原因',
  `print_count` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '打印次数',
  `last_print_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近打印时间',
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `update_by` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新人',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_line_role` (`tenant_id`, `task_kind`, `role_code`, `source_type`, `source_id`),
  KEY `idx_tenant_date_status` (`tenant_id`, `task_date`, `status`),
  KEY `idx_tenant_reservation` (`tenant_id`, `reservation_id`),
  KEY `idx_tenant_goods_kind` (`tenant_id`, `goods_id`, `task_kind`, `status`),
  KEY `idx_tenant_assignee` (`tenant_id`, `assignee_employee_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务中心商品明细级任务表';

CREATE TABLE `la_work_task_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务日志ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '任务ID',
  `action` varchar(50) NOT NULL DEFAULT '' COMMENT '动作',
  `status_from` varchar(32) NOT NULL DEFAULT '' COMMENT '原状态',
  `status_to` varchar(32) NOT NULL DEFAULT '' COMMENT '新状态',
  `content` varchar(500) NOT NULL DEFAULT '' COMMENT '内容',
  `payload_json` text NULL COMMENT '载荷JSON',
  `operator_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作员工ID',
  `operator_admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作管理员ID',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`, `task_id`),
  KEY `idx_action` (`tenant_id`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务中心任务日志表';

CREATE TABLE `la_task_print_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '打印日志ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `print_no` varchar(64) NOT NULL DEFAULT '' COMMENT '打印流水号',
  `task_date` date NULL DEFAULT NULL COMMENT '任务日期',
  `scope` varchar(32) NOT NULL DEFAULT '' COMMENT '打印范围',
  `employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '员工ID',
  `employee_name` varchar(100) NOT NULL DEFAULT '' COMMENT '员工名称',
  `role_code` varchar(50) NOT NULL DEFAULT '' COMMENT '角色编码',
  `task_ids_json` text NULL COMMENT '任务ID JSON',
  `reservation_item_ids_json` text NULL COMMENT '预定明细ID JSON',
  `device_id` varchar(100) NOT NULL DEFAULT '' COMMENT '设备ID',
  `device_name` varchar(100) NOT NULL DEFAULT '' COMMENT '设备名称',
  `result` varchar(32) NOT NULL DEFAULT 'simulated' COMMENT 'success/failed/simulated',
  `error_code` varchar(64) NOT NULL DEFAULT '' COMMENT '错误编码',
  `error_message` varchar(255) NOT NULL DEFAULT '' COMMENT '错误信息',
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_print_no` (`tenant_id`, `print_no`),
  KEY `idx_tenant_date` (`tenant_id`, `task_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务中心打印日志表';
