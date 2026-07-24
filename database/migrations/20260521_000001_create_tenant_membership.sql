-- 用户-店铺成员关系：用户可以创建或加入多个 tenant（店铺/账套）。
CREATE TABLE IF NOT EXISTS `la_tenant_member` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '租户/店铺ID',
  `user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '用户ID',
  `role` varchar(32) NOT NULL DEFAULT 'member' COMMENT 'owner/member',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '1正常 0禁用',
  `invite_code` varchar(32) NOT NULL DEFAULT '' COMMENT '店铺邀请码',
  `inviter_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '邀请人用户ID',
  `joined_at` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '加入时间',
  `create_time` int(11) unsigned DEFAULT NULL,
  `update_time` int(11) unsigned DEFAULT NULL,
  `delete_time` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_tenant_status` (`tenant_id`,`status`),
  KEY `idx_invite_code` (`invite_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户租户成员关系';

CREATE TABLE IF NOT EXISTS `la_tenant_invite` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '租户/店铺ID',
  `creator_user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建邀请码的用户ID',
  `code` varchar(32) NOT NULL DEFAULT '' COMMENT '邀请码',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '1有效 0禁用',
  `expire_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '0为长期有效',
  `create_time` int(11) unsigned DEFAULT NULL,
  `update_time` int(11) unsigned DEFAULT NULL,
  `delete_time` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户邀请码';
