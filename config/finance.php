<?php

return [
    // all：所有现有及后续门店均可完成期初并确认启用；allowlist：仅允许下方指定门店。
    // 两种模式下，门店最高权限账号仍须完成期初核验与确认，才会正式启用账套。
    'activation_mode' => 'all',
    'activation_tenant_ids' => [],
];
