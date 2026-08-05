-- 商品别名：tenant_id=0 表示云端标准别名；tenant_id>0 表示租户商品别名快照。
CREATE TABLE IF NOT EXISTS `{{prefix}}goods_alias` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '商品别名ID',
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=云端标准别名，大于0=租户本地别名',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户商品ID，云端别名为0',
  `cloud_goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '云端商品ID，租户别名为0',
  `alias` varchar(200) NOT NULL DEFAULT '' COMMENT '展示别名',
  `normalized_alias` varchar(200) NOT NULL DEFAULT '' COMMENT '规范化别名，用于唯一匹配',
  `source` varchar(20) NOT NULL DEFAULT 'tenant' COMMENT 'cloud/tenant，下载后为cloud快照',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_normalized_alias` (`tenant_id`, `normalized_alias`),
  KEY `idx_tenant_goods_alias_goods` (`tenant_id`, `goods_id`),
  KEY `idx_cloud_goods_alias_goods` (`cloud_goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='云端标准与租户本地商品别名';

-- 一个租户对同一云端商品只有首次下载快照；后续云端改动不触发同步或重新下载。
ALTER TABLE `{{prefix}}cloud_goods_import`
  ADD UNIQUE KEY `uk_tenant_cloud_goods_import_once` (`tenant_id`, `cloud_goods_id`);
