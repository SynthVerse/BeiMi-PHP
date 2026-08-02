-- 为订单表添加幂等键字段（如果字段不存在）
-- 注意：这些字段可能已在 jxc_phase1_schema.sql 的 CREATE TABLE 中包含
-- 此迁移脚本用于已有数据库的增量升级
-- 迁移执行器会自动跳过已存在的列和索引

ALTER TABLE {{prefix}}sales_order ADD COLUMN idempotent_key varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键';
ALTER TABLE {{prefix}}sales_order ADD INDEX idx_tenant_idempotent (tenant_id, idempotent_key);

ALTER TABLE {{prefix}}supply_order ADD COLUMN idempotent_key varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键';
ALTER TABLE {{prefix}}supply_order ADD INDEX idx_tenant_idempotent (tenant_id, idempotent_key);

ALTER TABLE {{prefix}}sales_return_order ADD COLUMN idempotent_key varchar(64) NOT NULL DEFAULT '' COMMENT '幂等键';
ALTER TABLE {{prefix}}sales_return_order ADD INDEX idx_tenant_idempotent (tenant_id, idempotent_key);
