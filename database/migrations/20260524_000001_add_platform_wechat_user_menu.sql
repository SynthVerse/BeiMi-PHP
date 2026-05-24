-- 平台端：店铺列表只承载已建店铺，微信登录用户独立进入微信用户列表。
UPDATE `la_system_menu`
SET `name` = '店铺管理',
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 117
  AND `name` = '租户管理';

UPDATE `la_system_menu`
SET `name` = '店铺列表',
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 118
  AND `name` = '租户列表';

UPDATE `la_system_menu`
SET `perms` = 'tenant.tenant/lists',
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 118
  AND `perms` = 'user.user/lists';

UPDATE `la_system_menu`
SET `name` = '新增店铺',
    `update_time` = UNIX_TIMESTAMP()
WHERE `perms` = 'tenant.tenant/add'
  AND `name` = '新增租户';

UPDATE `la_system_menu`
SET `name` = '编辑店铺',
    `update_time` = UNIX_TIMESTAMP()
WHERE `perms` = 'tenant.tenant/edit'
  AND `name` = '编辑租户';

UPDATE `la_system_menu`
SET `name` = '店铺详情',
    `update_time` = UNIX_TIMESTAMP()
WHERE `perms` = 'tenant.tenant/detail'
  AND `name` = '租户详情';

INSERT INTO `la_system_menu`
(`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 117, 'C', '微信用户列表', 'local-icon-user_guanli', 90, 'user.user/lists', 'wechat_user', 'tenant/wechat_user/index', '', '', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1
    FROM `la_system_menu`
    WHERE `perms` = 'user.user/lists'
      AND `component` = 'tenant/wechat_user/index'
);
