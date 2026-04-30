CREATE TABLE IF NOT EXISTS la_migration_history (
    id int unsigned NOT NULL AUTO_INCREMENT,
    version varchar(128) NOT NULL COMMENT '迁移版本号（文件名）',
    applied_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '应用时间',
    PRIMARY KEY (id),
    UNIQUE KEY uk_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据库迁移历史';
