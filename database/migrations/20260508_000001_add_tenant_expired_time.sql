-- 为 la_tenant 表添加 expired_time 字段（到期时间，UNIX时间戳）
-- 平台端创建租户和微信小程序自动预置租户时均需写入该字段
ALTER TABLE `la_tenant` ADD COLUMN `expired_time` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '到期时间' AFTER `disable`;
