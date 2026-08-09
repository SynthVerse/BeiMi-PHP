-- 默认商品分类标识及存量回填。
-- 列、数据和索引均可重复执行：已有同名分类会被直接认定，不会创建第二条默认分类。

SET @default_goods_category_col_exists := (
  SELECT COUNT(1)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}tenant_goodscat'
    AND COLUMN_NAME = 'is_default'
);
SET @default_goods_category_col_sql := IF(
  @default_goods_category_col_exists = 0,
  'ALTER TABLE `{{prefix}}tenant_goodscat` ADD COLUMN `is_default` tinyint(1) UNSIGNED NULL DEFAULT NULL COMMENT ''系统默认分类：1=是，NULL=否'' AFTER `is_show`',
  'SELECT 1'
);
PREPARE default_goods_category_col_stmt FROM @default_goods_category_col_sql;
EXECUTE default_goods_category_col_stmt;
DEALLOCATE PREPARE default_goods_category_col_stmt;

UPDATE `{{prefix}}tenant_goodscat` category
INNER JOIN (
  SELECT tenant_id, MIN(id) AS default_category_id
  FROM `{{prefix}}tenant_goodscat`
  WHERE tenant_id > 0
    AND name = '默认分类'
    AND delete_time IS NULL
  GROUP BY tenant_id
) existing_default
  ON existing_default.tenant_id = category.tenant_id
  AND existing_default.default_category_id = category.id
SET category.is_default = 1,
    category.is_show = 0,
    category.update_time = UNIX_TIMESTAMP();

INSERT INTO `{{prefix}}tenant_goodscat`
  (`tenant_id`, `name`, `sort`, `is_show`, `is_default`, `create_time`, `update_time`)
SELECT tenant.id, '默认分类', 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `{{prefix}}tenant` tenant
LEFT JOIN `{{prefix}}tenant_goodscat` category
  ON category.tenant_id = tenant.id
  AND category.is_default = 1
  AND category.delete_time IS NULL
WHERE tenant.id > 0
  AND tenant.delete_time IS NULL
  AND category.id IS NULL;

SET @default_goods_category_idx_exists := (
  SELECT COUNT(1)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}tenant_goodscat'
    AND INDEX_NAME = 'uk_tenant_default_goodscat'
);
SET @default_goods_category_idx_sql := IF(
  @default_goods_category_idx_exists = 0,
  'ALTER TABLE `{{prefix}}tenant_goodscat` ADD UNIQUE KEY `uk_tenant_default_goodscat` (`tenant_id`, `is_default`)',
  'SELECT 1'
);
PREPARE default_goods_category_idx_stmt FROM @default_goods_category_idx_sql;
EXECUTE default_goods_category_idx_stmt;
DEALLOCATE PREPARE default_goods_category_idx_stmt;
