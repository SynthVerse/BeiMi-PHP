-- 平台端商品分类管理 + 云端商品分类ID

SET @cloud_goods_table_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_cloud_goods'
);

SET @cloud_goods_category_column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_cloud_goods'
    AND COLUMN_NAME = 'category_id'
);

SET @cloud_goods_category_column_sql := IF(
  @cloud_goods_table_exists > 0 AND @cloud_goods_category_column_exists = 0,
  'ALTER TABLE `la_cloud_goods` ADD COLUMN `category_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT ''分类ID'' AFTER `stock`',
  'SELECT 1'
);
PREPARE stmt FROM @cloud_goods_category_column_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cloud_goods_category_index_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_cloud_goods'
    AND INDEX_NAME = 'idx_category_id'
);

SET @cloud_goods_category_index_sql := IF(
  @cloud_goods_table_exists > 0 AND @cloud_goods_category_index_exists = 0,
  'ALTER TABLE `la_cloud_goods` ADD INDEX `idx_category_id` (`category_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @cloud_goods_category_index_sql;
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
SELECT @platform_goods_menu_id, 'C', '分类管理', 'local-icon-goods', 60, 'goods.tenant_goodscat/lists', 'cate', 'goods/cate/index', '', '', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_goods_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.tenant_goodscat/lists'
  );

SET @platform_goodscat_menu_id := (
  SELECT `id` FROM `la_system_menu`
  WHERE `perms` = 'goods.tenant_goodscat/lists'
  ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT @platform_goodscat_menu_id, 'A', '新增', '', 0, 'goods.tenant_goodscat/add', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_goodscat_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.tenant_goodscat/add');

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT @platform_goodscat_menu_id, 'A', '编辑', '', 0, 'goods.tenant_goodscat/edit', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_goodscat_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.tenant_goodscat/edit');

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT @platform_goodscat_menu_id, 'A', '删除', '', 0, 'goods.tenant_goodscat/delete', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_goodscat_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.tenant_goodscat/delete');

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT @platform_goodscat_menu_id, 'A', '详情', '', 0, 'goods.tenant_goodscat/detail', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_goodscat_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.tenant_goodscat/detail');

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT @platform_goodscat_menu_id, 'A', '全部分类', '', 0, 'goods.tenant_goodscat/all', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_goodscat_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.tenant_goodscat/all');
