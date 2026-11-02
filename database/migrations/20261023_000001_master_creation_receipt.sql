-- 主数据创建与成功回执原子提交；不依赖期初账本或财务准备锁。
CREATE TABLE IF NOT EXISTS `la_master_creation_receipt` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `actor_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_id` int unsigned NOT NULL,
  `kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `result_data` longtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_master_creation_scope` (`tenant_id`,`actor_type`,`actor_id`,`kind`,`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
