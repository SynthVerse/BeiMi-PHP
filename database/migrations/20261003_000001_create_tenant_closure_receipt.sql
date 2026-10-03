-- 租户永久注销的最小凭据。业务明细、附件信息和店铺快照不得写入本表。
CREATE TABLE IF NOT EXISTS `{{prefix}}tenant_closure_receipt` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL COMMENT '对外查询凭据',
  `tenant_id` int(11) unsigned NOT NULL COMMENT '已注销租户ID',
  `operator_user_id` int(11) unsigned NOT NULL COMMENT '发起注销的用户ID',
  `idempotency_key_hash` char(64) NOT NULL COMMENT '幂等键摘要',
  `status` varchar(32) NOT NULL DEFAULT 'effective' COMMENT 'effective/cleaning/backup_retention/completed/failed',
  `phase` varchar(32) NOT NULL DEFAULT 'effective' COMMENT '当前清理阶段',
  `effective_at` int(11) unsigned NOT NULL COMMENT '注销生效时间',
  `online_deleted_at` int(11) unsigned DEFAULT NULL COMMENT '在线业务数据删除完成时间',
  `attachments_deleted_at` int(11) unsigned DEFAULT NULL COMMENT '专属附件删除完成时间',
  `backup_purge_due_at` int(11) unsigned NOT NULL COMMENT '备份最迟清理时间',
  `backup_deleted_at` int(11) unsigned DEFAULT NULL COMMENT '备份清理完成时间',
  `backup_purged_by_admin_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '确认外部备份已清理的平台管理员ID',
  `retry_count` int(11) unsigned NOT NULL DEFAULT '0',
  `last_error_code` varchar(64) NOT NULL DEFAULT '',
  `last_error_message` varchar(500) NOT NULL DEFAULT '',
  `create_time` int(11) unsigned NOT NULL,
  `update_time` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_public_id` (`public_id`),
  UNIQUE KEY `uk_tenant_id` (`tenant_id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key_hash`),
  KEY `idx_operator_status` (`operator_user_id`,`status`),
  KEY `idx_backup_due` (`backup_purge_due_at`,`backup_deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户永久注销最小凭据';

-- 依赖部署环境持续执行既有 `php think crontab` 入口；重放不复制任务、不重启管理员停用的任务。
INSERT INTO `{{prefix}}dev_crontab`
(`name`,`type`,`system`,`remark`,`command`,`params`,`status`,`expression`,`last_time`,`create_time`,`update_time`)
SELECT '租户永久注销清理续跑',1,1,'重试未完成的在线清理并标记超过30天的备份清理',
       'tenant-closure:process','--limit 20',1,'*/5 * * * *',UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `{{prefix}}dev_crontab` WHERE `command`='tenant-closure:process'
);
