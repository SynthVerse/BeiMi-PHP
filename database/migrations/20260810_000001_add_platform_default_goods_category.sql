-- 创建平台公共商品库使用的默认分类。
-- 仅处理 tenant_id=0；已有平台同名分类会被复用，重复执行不会新增第二条记录。

SET @platform_default_goodscat_id := (
  SELECT `id`
  FROM `{{prefix}}tenant_goodscat`
  WHERE `tenant_id` = 0
    AND `is_default` = 1
  ORDER BY (`delete_time` IS NULL) DESC, `id` ASC
  LIMIT 1
);

SET @platform_named_goodscat_id := (
  SELECT `id`
  FROM `{{prefix}}tenant_goodscat`
  WHERE `tenant_id` = 0
    AND `name` = '默认分类'
  ORDER BY (`delete_time` IS NULL) DESC, `id` ASC
  LIMIT 1
);

UPDATE `{{prefix}}tenant_goodscat`
SET `is_default` = 1,
    `is_show` = 0,
    `delete_time` = NULL,
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `id` = COALESCE(
    @platform_default_goodscat_id,
    @platform_named_goodscat_id
  );

INSERT INTO `{{prefix}}tenant_goodscat`
  (`tenant_id`, `name`, `sort`, `is_show`, `is_default`, `create_time`, `update_time`)
SELECT 0, '默认分类', 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1
  FROM `{{prefix}}tenant_goodscat`
  WHERE `tenant_id` = 0
    AND `is_default` = 1
);
