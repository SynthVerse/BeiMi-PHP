-- 类别所需的历史月份和来源资料，与草稿明细处于同一门店事务。
-- 确认后资料进入原有不可变 source_snapshot，不另造工资、费用或收付款。
CREATE TABLE IF NOT EXISTS `{{prefix}}finance_opening_item_detail` (
  `tenant_id` int unsigned NOT NULL,
  `opening_item_id` bigint unsigned NOT NULL,
  `details` text NOT NULL,
  PRIMARY KEY (`tenant_id`,`opening_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='期初明细历史来源和期间草稿';
