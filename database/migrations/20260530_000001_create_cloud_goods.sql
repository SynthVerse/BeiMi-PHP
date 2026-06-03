-- 云端商品库：平台公共库 + 租户私有库
CREATE TABLE IF NOT EXISTS `la_cloud_goods` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '云端商品ID',
  `scope` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '商品库类型：1=平台公共，2=租户私有',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID，公共库为0',
  `owner_admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '维护管理员ID',
  `owner_user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '维护用户ID',
  `name` varchar(200) NOT NULL DEFAULT '' COMMENT '商品名称',
  `product_code` varchar(100) NOT NULL DEFAULT '' COMMENT '商品编码',
  `units` varchar(50) NOT NULL DEFAULT '' COMMENT '默认单位名称',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '销售价格',
  `cost` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '成本价',
  `stock` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '默认库存',
  `category_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID',
  `category_name` varchar(100) NOT NULL DEFAULT '' COMMENT '云端分类名称快照',
  `supplier_name` varchar(100) NOT NULL DEFAULT '' COMMENT '云端供应商名称快照',
  `is_disabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '加载后是否停用',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '云端状态：0=停用，1=启用',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_scope_tenant_status` (`scope`, `tenant_id`, `status`),
  KEY `idx_tenant_name_units` (`tenant_id`, `name`, `units`),
  KEY `idx_product_code` (`product_code`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='云端商品库表';

CREATE TABLE IF NOT EXISTS `la_cloud_goods_import` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '导入记录ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  `cloud_goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '云端商品ID',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '本地商品ID',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作用户ID',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作管理员ID',
  `source_scope` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '来源类型：1=平台公共，2=租户私有',
  `load_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '加载时选择的单位ID',
  `load_category_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '加载时选择的分类ID',
  `load_supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '加载时选择的供应商ID',
  `load_snapshot` longtext NULL COMMENT '加载时云端商品快照',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_cloud_goods` (`tenant_id`, `cloud_goods_id`),
  KEY `idx_tenant_goods` (`tenant_id`, `goods_id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='云端商品加载记录表';

-- 租户端菜单：商品管理/云端商品库
INSERT INTO `la_tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, 0, 'M', '商品管理', 'local-icon-goods', 600, '', 'goods', '', '', '', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id` = 0 AND `type` = 'M' AND `paths` = 'goods'
);

SET @tenant_goods_menu_id := (
  SELECT `id` FROM `la_tenant_system_menu`
  WHERE `tenant_id` = 0 AND `type` = 'M' AND `paths` = 'goods'
  ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `la_tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, @tenant_goods_menu_id, 'C', '云端商品库', 'local-icon-goods', 70, 'goods.cloud_goods/lists', 'cloud_goods', 'goods/cloud_goods/index', '', '', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @tenant_goods_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id` = 0 AND `perms` = 'goods.cloud_goods/lists'
  );

SET @tenant_cloud_goods_menu_id := (
  SELECT `id` FROM `la_tenant_system_menu`
  WHERE `tenant_id` = 0 AND `perms` = 'goods.cloud_goods/lists'
  ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `la_tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, @tenant_cloud_goods_menu_id, 'A', '新增', '', 0, 'goods.cloud_goods/add', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @tenant_cloud_goods_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id` = 0 AND `perms` = 'goods.cloud_goods/add');

INSERT INTO `la_tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, @tenant_cloud_goods_menu_id, 'A', '编辑', '', 0, 'goods.cloud_goods/edit', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @tenant_cloud_goods_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id` = 0 AND `perms` = 'goods.cloud_goods/edit');

INSERT INTO `la_tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, @tenant_cloud_goods_menu_id, 'A', '删除', '', 0, 'goods.cloud_goods/delete', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @tenant_cloud_goods_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id` = 0 AND `perms` = 'goods.cloud_goods/delete');

INSERT INTO `la_tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, @tenant_cloud_goods_menu_id, 'A', '加载到商品', '', 0, 'goods.cloud_goods/load', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @tenant_cloud_goods_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id` = 0 AND `perms` = 'goods.cloud_goods/load');

-- 平台端菜单：商品管理/公共云端商品库
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
SELECT @platform_goods_menu_id, 'C', '公共商品库', 'local-icon-goods', 70, 'goods.cloud_goods/lists', 'cloud_goods', 'goods/cloud_goods/index', '', '', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_goods_menu_id IS NOT NULL
  AND NOT EXISTS (
  SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.cloud_goods/lists'
);

SET @platform_cloud_goods_menu_id := (
  SELECT `id` FROM `la_system_menu`
  WHERE `perms` = 'goods.cloud_goods/lists'
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

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT @platform_cloud_goods_menu_id, 'A', '新增', '', 0, 'goods.cloud_goods/add', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_cloud_goods_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.cloud_goods/add');

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT @platform_cloud_goods_menu_id, 'A', '编辑', '', 0, 'goods.cloud_goods/edit', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_cloud_goods_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.cloud_goods/edit');

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT @platform_cloud_goods_menu_id, 'A', '删除', '', 0, 'goods.cloud_goods/delete', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @platform_cloud_goods_menu_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `perms` = 'goods.cloud_goods/delete');
