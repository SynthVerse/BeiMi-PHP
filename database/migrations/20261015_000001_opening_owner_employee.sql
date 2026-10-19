-- 纸质历史员工可以明确手机号未知；NULL 不与另一未知人员合并。
ALTER TABLE `{{prefix}}employee` MODIFY COLUMN `mobile` varchar(30) DEFAULT NULL COMMENT 'NULL表示手机号未知';
ALTER TABLE `{{prefix}}employee` ADD COLUMN `creation_key` varchar(100) DEFAULT NULL COMMENT '建档重试身份';
ALTER TABLE `{{prefix}}employee` ADD UNIQUE KEY `uk_employee_creation` (`tenant_id`,`creation_key`);
