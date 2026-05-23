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

-- 兼容历史 la_user.tenant_id：已有默认租户的用户补为 owner 成员。
INSERT IGNORE INTO `la_tenant_member`
(`tenant_id`, `user_id`, `role`, `status`, `invite_code`, `inviter_id`, `joined_at`, `create_time`, `update_time`, `delete_time`)
SELECT
  u.`tenant_id`,
  u.`id`,
  'owner',
  1,
  UPPER(SUBSTRING(MD5(CONCAT('tenant:', u.`tenant_id`, ':user:', u.`id`)), 1, 8)),
  0,
  IFNULL(u.`create_time`, UNIX_TIMESTAMP()),
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP(),
  NULL
FROM `la_user` u
INNER JOIN `la_tenant` t ON t.`id` = u.`tenant_id` AND t.`delete_time` IS NULL
WHERE u.`tenant_id` > 0 AND u.`delete_time` IS NULL;

INSERT IGNORE INTO `la_tenant_invite`
(`tenant_id`, `creator_user_id`, `code`, `status`, `expire_time`, `create_time`, `update_time`, `delete_time`)
SELECT
  m.`tenant_id`,
  m.`user_id`,
  m.`invite_code`,
  1,
  0,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP(),
  NULL
FROM `la_tenant_member` m
WHERE m.`role` = 'owner' AND m.`invite_code` <> '' AND m.`delete_time` IS NULL;
