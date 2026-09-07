-- 当前待办从最后一条观察事件投影；原观察和后来核实的付款说明均不覆盖。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_overdue_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `source_ref` varchar(40) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `business_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `due_revision` bigint unsigned NOT NULL DEFAULT 0,
  `balance` decimal(16,2) NOT NULL,
  `state` varchar(12) NOT NULL,
  `observed_date` date NOT NULL,
  `document_id` bigint unsigned NOT NULL DEFAULT 0,
  `document_type` varchar(40) NOT NULL DEFAULT '',
  `timing` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_overdue_source` (`tenant_id`,`source_ref`,`id`),
  KEY `idx_overdue_customer` (`tenant_id`,`customer_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
