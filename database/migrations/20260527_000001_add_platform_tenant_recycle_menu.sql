-- 平台端：新增店铺回收站列表，并将删除文案调整为放入回收站。
UPDATE `la_system_menu`
SET `name` = '放入回收站',
    `update_time` = UNIX_TIMESTAMP()
WHERE `perms` = 'tenant.tenant/delete';

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 117, 'C', '店铺回收站列表', 'local-icon-user_guanli', 80, 'tenant.tenant/recycleLists', 'recycle', 'tenant/recycle/index', '', '', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1
    FROM `la_system_menu`
    WHERE `perms` = 'tenant.tenant/recycleLists'
      AND `component` = 'tenant/recycle/index'
);

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT `id`, 'A', '恢复店铺', '', 0, 'tenant.tenant/restore', '', '', '', '', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_system_menu`
WHERE `perms` = 'tenant.tenant/recycleLists'
  AND `component` = 'tenant/recycle/index'
  AND NOT EXISTS (
      SELECT 1
      FROM `la_system_menu`
      WHERE `perms` = 'tenant.tenant/restore'
  )
LIMIT 1;
