-- +----------------------------------------------------------------------
-- | 微信小程序登录: 修复用户相关表 tenant_id 缺少默认值问题
-- +----------------------------------------------------------------------
-- | 背景：la_user / la_user_auth / la_user_session 被加上 tenant_id NOT NULL
-- | 但未设置 DEFAULT 0，导致 STRICT_TRANS_TABLES 模式下 mnpLogin 创建用户
-- | 时报 SQLSTATE[HY000] 1364: Field 'tenant_id' doesn't have a default value
-- |
-- | 设计说明：la_user 属于跨租户公用用户池（一个微信用户可服务多租户），
-- | 不应强制租户归属；与 jxc_phase1_schema.sql 中业务表统一采用 DEFAULT 0。
-- +----------------------------------------------------------------------

ALTER TABLE `la_user`
    MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID(0=跨租户公用用户)';

ALTER TABLE `la_user_auth`
    MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID(0=跨租户公用授权)';

ALTER TABLE `la_user_session`
    MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID(0=跨租户公用会话)';
