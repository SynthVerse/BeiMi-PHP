-- 云端商品归档状态与平台端商品归档列表

SET @cloud_goods_table_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_cloud_goods'
);

SET @cloud_goods_status_comment_sql := IF(
  @cloud_goods_table_exists > 0,
  'ALTER TABLE `la_cloud_goods` MODIFY COLUMN `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT ''云端状态：0=停用，1=启用，2=已归档''',
  'SELECT 1'
);
PREPARE stmt FROM @cloud_goods_status_comment_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, 'M', '商品管理', 'local-icon-goods', 700, '', 'goods', '', '', '', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_system_menu` WHERE `type` = 'M' AND `paths` = 'goods'
);

SET @platform_goods_menu_id := (
  SELECT `id` FROM `la_system_menu`
  WHERE `type` = 'M' AND `paths` = 'goods'
  ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT @platform_goods_menu_id, 'C', '商品归档列表', 'local-icon-goods', 80, 'goods.cloud_goods/archive', 'cloud_goods_archive', 'goods/cloud_goods/archive', '', '', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_goods_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.cloud_goods/archive'
  );
