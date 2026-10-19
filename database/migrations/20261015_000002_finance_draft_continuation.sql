-- 只投影老板主动选择稍后继续的草稿，普通草稿保持原规则。
ALTER TABLE `{{prefix}}finance_document` ADD COLUMN `resume_requested` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '主动开启继续填写提醒';
