-- 扩展租户邀请码：兼容历史 member 邀请，并支持 tenant -> tenant 层级邀请。
ALTER TABLE `la_tenant_invite`
  ADD COLUMN `invite_type` varchar(32) NOT NULL DEFAULT 'member' COMMENT 'member个人加入/relation店铺层级' AFTER `code`,
  ADD COLUMN `target_user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '限定接受用户ID，0不限' AFTER `invite_type`,
  ADD COLUMN `target_tenant_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '限定目标店铺ID，0不限' AFTER `target_user_id`,
  ADD COLUMN `role` varchar(32) NOT NULL DEFAULT 'member' COMMENT '成员角色' AFTER `target_tenant_id`,
  ADD COLUMN `relation_type` varchar(32) NOT NULL DEFAULT '' COMMENT '层级关系类型' AFTER `role`,
  ADD COLUMN `max_uses` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '最大使用次数，0不限' AFTER `expire_time`,
  ADD COLUMN `used_count` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '已使用次数' AFTER `max_uses`,
  ADD COLUMN `extra` json DEFAULT NULL COMMENT '扩展信息' AFTER `used_count`;

CREATE INDEX `idx_invite_type_status` ON `la_tenant_invite` (`invite_type`, `status`);
CREATE INDEX `idx_target_user` ON `la_tenant_invite` (`target_user_id`);
CREATE INDEX `idx_target_tenant` ON `la_tenant_invite` (`target_tenant_id`);

UPDATE `la_tenant_invite`
SET `invite_type` = 'member',
    `role` = IF(`role` = '', 'member', `role`)
WHERE `invite_type` = '';

-- 店铺层级关系：tenant -> tenant。tenant_member 仍只表达 user -> tenant。
CREATE TABLE IF NOT EXISTS `la_tenant_relation` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `parent_tenant_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '父店铺ID',
  `child_tenant_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '子店铺ID',
  `relation_type` varchar(32) NOT NULL DEFAULT 'default' COMMENT '关系类型',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '1正常 0禁用',
  `level` int(11) unsigned NOT NULL DEFAULT '1' COMMENT '层级深度',
  `path` varchar(1024) NOT NULL DEFAULT '' COMMENT '层级路径，如 /1/2/3/',
  `invite_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '来源邀请码ID',
  `creator_user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '邀请创建用户ID',
  `accepted_user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '接受用户ID',
  `accepted_at` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '接受时间',
  `permissions` json DEFAULT NULL COMMENT '层级权限配置',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `is_deleted` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '软删除标记',
  `active_child_tenant_id` int(11) unsigned GENERATED ALWAYS AS (IF((`is_deleted` = 0 AND `status` = 1), `child_tenant_id`, NULL)) STORED COMMENT '活跃单父级唯一键',
  `create_time` int(11) unsigned DEFAULT NULL,
  `update_time` int(11) unsigned DEFAULT NULL,
  `delete_time` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_active_child` (`active_child_tenant_id`),
  KEY `idx_parent_status` (`parent_tenant_id`, `status`, `is_deleted`),
  KEY `idx_child_status` (`child_tenant_id`, `status`, `is_deleted`),
  KEY `idx_invite_id` (`invite_id`),
  KEY `idx_path` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户店铺层级关系';
