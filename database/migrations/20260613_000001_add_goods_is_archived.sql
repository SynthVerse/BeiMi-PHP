ALTER TABLE `la_goods` ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT 0 
  COMMENT '归档状态：0=正常，1=已归档' AFTER `is_disabled`;
