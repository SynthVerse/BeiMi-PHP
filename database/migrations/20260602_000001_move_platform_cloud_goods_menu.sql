-- 平台端菜单：将公共商品库从应用管理移动到商品管理
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

UPDATE `la_system_menu`
SET `pid` = @platform_goods_menu_id,
    `sort` = 70,
    `update_time` = UNIX_TIMESTAMP()
WHERE @platform_goods_menu_id IS NOT NULL
  AND `perms` = 'goods.cloud_goods/lists';
