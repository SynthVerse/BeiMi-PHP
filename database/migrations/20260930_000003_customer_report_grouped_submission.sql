-- 统一代报整批幂等记录：绑定请求指纹与正式报货单结果集。

CREATE TABLE IF NOT EXISTS `{{prefix}}customer_report_grouped_submission` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '门店租户ID',
  `idempotency_key` varchar(96) NOT NULL DEFAULT '',
  `request_fingerprint` char(64) NOT NULL DEFAULT '',
  `main_customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '统一结算主客户ID',
  `report_ids` text NOT NULL COMMENT '按提交顺序保存的报货单ID JSON',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_customer_report_grouped_key` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_customer_report_grouped_main` (`tenant_id`,`main_customer_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户统一代报整批幂等记录';
