-- customer_id=0 为门店默认；客户 inherit 重新采用门店规则，历史版本保留。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_sales_precision_rule` (
  `tenant_id` int unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `mode` varchar(16) NOT NULL,
  `reason` varchar(1000) NOT NULL,
  `actor` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`customer_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
