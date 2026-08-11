-- 为客户报货行保留提交时的 SKU 名称，确保历史单据和任务看板可区分通用维度组合。
ALTER TABLE `{{prefix}}customer_report_item`
  ADD COLUMN `sku_name` varchar(200) NOT NULL DEFAULT '' COMMENT 'SKU名称快照' AFTER `sku_id`;
