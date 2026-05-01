-- 基础平台表：租户、管理员、会话

CREATE TABLE IF NOT EXISTS `la_tenant` (
    `id`                  int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    `sn`                  varchar(50) NOT NULL COMMENT '编号',
    `name`                varchar(32) NOT NULL DEFAULT '' COMMENT '名称',
    `avatar`              varchar(255) NOT NULL DEFAULT '' COMMENT '租户头像',
    `tel`                 varchar(30) DEFAULT NULL COMMENT '联系方式',
    `disable`             tinyint(1) UNSIGNED DEFAULT 0 COMMENT '是否禁用：0-否；1-是；',
    `tactics`             tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分表策略: [0=否, 1=是]',
    `notes`               varchar(255) DEFAULT NULL COMMENT '租户备注',
    `domain_alias`        varchar(255) DEFAULT NULL COMMENT '域名别名',
    `domain_alias_enable` tinyint(10) NOT NULL DEFAULT 1 COMMENT '启用域名别名：0-启用；1-禁用',
    `create_time`         int(10) NOT NULL COMMENT '创建时间',
    `update_time`         int(10) DEFAULT NULL COMMENT '修改时间',
    `delete_time`         int(10) DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='租户表';

CREATE TABLE IF NOT EXISTS `la_tenant_admin` (
    `id`               int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        int(10) NOT NULL COMMENT '租户ID',
    `root`             tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否超级管理员 0-否 1-是',
    `name`             varchar(32) NOT NULL DEFAULT '' COMMENT '名称',
    `avatar`           varchar(255) NOT NULL DEFAULT '' COMMENT '用户头像',
    `account`          varchar(32) NOT NULL DEFAULT '' COMMENT '账号',
    `password`         varchar(32) NOT NULL COMMENT '密码',
    `login_time`       int(10) DEFAULT NULL COMMENT '最后登录时间',
    `login_ip`         varchar(39) DEFAULT '' COMMENT '最后登录ip',
    `multipoint_login` tinyint(1) UNSIGNED DEFAULT 1 COMMENT '是否支持多处登录：1-是；0-否；',
    `disable`          tinyint(1) UNSIGNED DEFAULT 0 COMMENT '是否禁用：0-否；1-是；',
    `create_time`      int(10) NOT NULL COMMENT '创建时间',
    `update_time`      int(10) DEFAULT NULL COMMENT '修改时间',
    `delete_time`      int(10) DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='租户管理员表';

CREATE TABLE IF NOT EXISTS `la_tenant_admin_session` (
    `id`          int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id`    int(11) UNSIGNED NOT NULL COMMENT '管理员id',
    `terminal`    tinyint(1) NOT NULL DEFAULT 1 COMMENT '客户端类型：1-pc 2-mobile',
    `token`       varchar(32) NOT NULL COMMENT '令牌',
    `update_time` int(10) DEFAULT NULL COMMENT '更新时间',
    `expire_time` int(10) NOT NULL COMMENT '到期时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `admin_id_client` (`admin_id`, `terminal`),
    UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='管理员会话表';
