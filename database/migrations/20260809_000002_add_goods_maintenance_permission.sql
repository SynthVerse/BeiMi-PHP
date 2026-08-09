-- 商品维护约束：复用兼容权限键 goods.tenant_goods/add 统一控制 JXC 商品新增与编辑。
-- 规范化名称与租户联合索引让名称/别名冲突解析按输入 token 查询，避免持锁扫描全租户商品。
SET @goods_normalized_name_column_exists := (
  SELECT COUNT(1)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}goods'
    AND COLUMN_NAME = 'normalized_name'
);
SET @goods_normalized_name_column_sql := IF(
  @goods_normalized_name_column_exists = 0,
  'ALTER TABLE `{{prefix}}goods` ADD COLUMN `normalized_name` varchar(200) NOT NULL DEFAULT '''' COMMENT ''规范化商品名，用于租户内名称/别名冲突解析'' AFTER `name`',
  'SELECT 1'
);
PREPARE goods_normalized_name_column_stmt FROM @goods_normalized_name_column_sql;
EXECUTE goods_normalized_name_column_stmt;
DEALLOCATE PREPARE goods_normalized_name_column_stmt;

UPDATE `{{prefix}}goods`
SET `normalized_name` = LOWER(
  REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`name`, CHAR(9), ''), CHAR(10), ''), CHAR(11), ''), CHAR(12), ''), CHAR(13), ''), CHAR(32), ''), CONVERT(0xC285 USING utf8mb4), ''), CONVERT(0xC2A0 USING utf8mb4), ''), CONVERT(0xE19A80 USING utf8mb4), ''), CONVERT(0xE28080 USING utf8mb4), ''), CONVERT(0xE28081 USING utf8mb4), ''), CONVERT(0xE28082 USING utf8mb4), ''), CONVERT(0xE28083 USING utf8mb4), ''), CONVERT(0xE28084 USING utf8mb4), ''), CONVERT(0xE28085 USING utf8mb4), ''), CONVERT(0xE28086 USING utf8mb4), ''), CONVERT(0xE28087 USING utf8mb4), ''), CONVERT(0xE28088 USING utf8mb4), ''), CONVERT(0xE28089 USING utf8mb4), ''), CONVERT(0xE2808A USING utf8mb4), ''), CONVERT(0xE280A8 USING utf8mb4), ''), CONVERT(0xE280A9 USING utf8mb4), ''), CONVERT(0xE280AF USING utf8mb4), ''), CONVERT(0xE2819F USING utf8mb4), ''), CONVERT(0xE38080 USING utf8mb4), '')
);

SET @goods_normalized_name_index_exists := (
  SELECT COUNT(1)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}goods'
    AND INDEX_NAME = 'idx_tenant_normalized_name'
);
SET @goods_normalized_name_index_sql := IF(
  @goods_normalized_name_index_exists = 0,
  'ALTER TABLE `{{prefix}}goods` ADD KEY `idx_tenant_normalized_name` (`tenant_id`, `normalized_name`)',
  'SELECT 1'
);
PREPARE goods_normalized_name_index_stmt FROM @goods_normalized_name_index_sql;
EXECUTE goods_normalized_name_index_stmt;
DEALLOCATE PREPARE goods_normalized_name_index_stmt;

SET @template_goods_parent_id := (
  SELECT `id`
  FROM `{{prefix}}tenant_system_menu`
  WHERE `tenant_id` = 0
    AND (`perms` = 'goods.tenant_goods/lists' OR (`type` = 'M' AND `paths` = 'goods'))
  ORDER BY (`perms` = 'goods.tenant_goods/lists') DESC, `id` ASC
  LIMIT 1
);

INSERT INTO `{{prefix}}tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, COALESCE(@template_goods_parent_id, 0), 'A', '商品维护', '', 0,
       'goods.tenant_goods/add', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1
  FROM `{{prefix}}tenant_system_menu`
  WHERE `tenant_id` = 0 AND `perms` = 'goods.tenant_goods/add'
);

INSERT INTO `{{prefix}}tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT tenant.`id`,
       COALESCE((
         SELECT parent.`id`
         FROM `{{prefix}}tenant_system_menu` parent
         WHERE parent.`tenant_id` = tenant.`id`
           AND (parent.`perms` = 'goods.tenant_goods/lists' OR (parent.`type` = 'M' AND parent.`paths` = 'goods'))
         ORDER BY (parent.`perms` = 'goods.tenant_goods/lists') DESC, parent.`id` ASC
         LIMIT 1
       ), 0),
       'A', '商品维护', '', 0, 'goods.tenant_goods/add', '', '', '', '', 1, 1, 0,
       UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `{{prefix}}tenant` tenant
WHERE NOT EXISTS (
  SELECT 1
  FROM `{{prefix}}tenant_system_menu` permission
  WHERE permission.`tenant_id` = tenant.`id`
    AND permission.`perms` = 'goods.tenant_goods/add'
);
