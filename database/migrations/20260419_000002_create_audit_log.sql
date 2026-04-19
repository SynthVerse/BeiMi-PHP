CREATE TABLE IF NOT EXISTS lk_audit_log (
    id int unsigned NOT NULL AUTO_INCREMENT,
    tenant_id int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
    admin_id int unsigned NOT NULL DEFAULT 0 COMMENT '操作人ID',
    module varchar(32) NOT NULL DEFAULT '' COMMENT '模块名',
    action varchar(32) NOT NULL DEFAULT '' COMMENT '操作类型',
    target_id int unsigned NOT NULL DEFAULT 0 COMMENT '目标记录ID',
    target_sn varchar(64) NOT NULL DEFAULT '' COMMENT '目标单据编号',
    before_data json DEFAULT NULL COMMENT '变更前数据',
    after_data json DEFAULT NULL COMMENT '变更后数据',
    ip varchar(45) NOT NULL DEFAULT '' COMMENT '操作IP',
    remark varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
    create_time int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
    PRIMARY KEY (id),
    KEY idx_tenant_module (tenant_id, module),
    KEY idx_tenant_time (tenant_id, create_time),
    KEY idx_target (target_id, module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JXC审计日志表';
