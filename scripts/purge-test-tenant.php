<?php
declare(strict_types=1);

/** 仅用于用户授权的 xglh67j6 / 24859138 测试账号重置。必须在停用站点及相关任务后运行。 */
function purgeTestTenant(PDO $db, string $prefix, bool $execute, string $backupDir): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new RuntimeException('表前缀异常');
    $q = static function (string $name): string {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('标识符异常');
        return '`' . $name . '`';
    };
    $rows = $db->query('SELECT TABLE_NAME,COLUMN_NAME,EXTRA,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];
    $generated = [];
    $binary = [];
    foreach ($rows as $row) if (str_starts_with($row['TABLE_NAME'], $prefix)) {
        $columns[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
        if (preg_match('/(?:STORED|VIRTUAL) GENERATED/i', $row['EXTRA'])) $generated[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
        if (in_array(strtolower($row['DATA_TYPE']), ['binary','varbinary','tinyblob','blob','mediumblob','longblob','bit'], true)) $binary[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
    }
    $required = ['tenant', 'user', 'tenant_member', 'tenant_admin', 'tenant_system_role', 'user_auth', 'user_session'];
    foreach ($required as $name) if (!isset($columns[$prefix . $name])) throw new RuntimeException('缺少必需表：' . $name);
    $db->beginTransaction();
    try {
        $tenant = $db->query('SELECT id,sn,name FROM ' . $q($prefix.'tenant') . ' WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        $user = $db->query('SELECT id,sn,tenant_id FROM ' . $q($prefix.'user') . ' WHERE id=5 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if (!$tenant || $tenant['sn'] !== 'xglh67j6' || $tenant['name'] !== '金木火水产商行' || !$user || (string)$user['sn'] !== '24859138' || (int)$user['tenant_id'] !== 1) throw new RuntimeException('目标身份不匹配，拒绝删除');
        if ((int)$db->query('SELECT COUNT(*) FROM '.$q($prefix.'user').' WHERE tenant_id=1 AND id<>5')->fetchColumn() !== 0) throw new RuntimeException('租户出现其他用户，拒绝扩大范围');
        if ((int)$db->query('SELECT COUNT(*) FROM '.$q($prefix.'tenant_member').' WHERE (user_id=5 AND tenant_id<>1) OR (tenant_id=1 AND user_id<>5)')->fetchColumn() !== 0) throw new RuntimeException('成员关系发生变化，拒绝删除');
        $admins = $db->query('SELECT id FROM '.$q($prefix.'tenant_admin').' WHERE tenant_id=1 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        $roles = $db->query('SELECT id FROM '.$q($prefix.'tenant_system_role').' WHERE tenant_id=1 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        $plan = [];
        foreach ($columns as $table => $cols) {
            if (str_contains($table, 'xglh67j6')) throw new RuntimeException('发现未审查的租户独立表：'.$table);
            if (in_array('tenant_id', $cols, true)) $plan[$table] = ['tenant_id=1'];
        }
        foreach (['user_auth', 'user_session', 'user_account_log'] as $name) {
            $table = $prefix.$name;
            if (!isset($columns[$table])) continue;
            if (!in_array('user_id', $columns[$table], true)) throw new RuntimeException('用户关联表结构不匹配');
            if (in_array('tenant_id', $columns[$table], true) && (int)$db->query('SELECT COUNT(*) FROM '.$q($table).' WHERE user_id=5 AND tenant_id NOT IN (0,1)')->fetchColumn()) throw new RuntimeException('用户存在其他租户记录，拒绝删除');
            $plan[$table][] = 'user_id=5';
        }
        foreach (['tenant_admin_dept', 'tenant_admin_jobs', 'tenant_admin_role', 'tenant_admin_session'] as $name) {
            if (isset($columns[$prefix.$name]) && $admins) $plan[$prefix.$name][] = 'admin_id IN ('.implode(',', array_map('intval', $admins)).')';
        }
        if ($roles && isset($columns[$prefix.'tenant_system_role_menu'])) $plan[$prefix.'tenant_system_role_menu'][] = 'role_id IN ('.implode(',', array_map('intval', $roles)).')';
        if (isset($columns[$prefix.'tenant_relation'])) $plan[$prefix.'tenant_relation'][] = 'parent_tenant_id=1 OR child_tenant_id=1';
        if (isset($columns[$prefix.'notice_record'])) $plan[$prefix.'notice_record'][] = 'user_id=5 AND recipient=1';
        if (isset($columns[$prefix.'file'])) $plan[$prefix.'file'][] = 'source=1 AND source_id=5';
        $plan[$prefix.'user'][] = 'id=5';
        $plan[$prefix.'tenant'] = ['id=1'];
        $engines = $db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
        $foreignKeys = $db->query('SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($foreignKeys as $fk) if (isset($plan[$fk['TABLE_NAME']]) || isset($plan[$fk['REFERENCED_TABLE_NAME']])) throw new RuntimeException('发现外键，需单独审查删除顺序；未删除');
        foreach ($db->query('SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $table) if (isset($plan[$table])) throw new RuntimeException('发现触发器，需单独审查；未删除');
        $counts = [];
        $untouched = [];
        foreach ($plan as $table => &$parts) {
            if (strtoupper((string)($engines[$table] ?? '')) !== 'INNODB') throw new RuntimeException('目标表不支持事务：'.$table);
            $parts = '('.implode(') OR (', $parts).')';
            $counts[$table] = (int)$db->query('SELECT COUNT(*) FROM '.$q($table).' WHERE '.$parts)->fetchColumn();
            $untouched[$table] = (int)$db->query('SELECT COUNT(*) FROM '.$q($table).' WHERE NOT ('.$parts.')')->fetchColumn();
        }
        unset($parts);
        if (!$execute) { $db->rollBack(); return ['mode'=>'预览，未删除', 'rows'=>array_filter($counts), 'total'=>array_sum($counts)]; }
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true)) throw new RuntimeException('无法建立备份目录');
        $path = rtrim($backupDir, '/\\').'/tenant-1-user-5-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql.gz';
        $gz = gzopen($path, 'wb9');
        if (!$gz) throw new RuntimeException('无法创建备份');
        chmod($path, 0600);
        $digest = hash_init('sha256');
        $write = static function (string $s) use ($gz, $digest): void { if (gzwrite($gz, $s) !== strlen($s)) throw new RuntimeException('备份写入失败'); hash_update($digest, $s); };
        $write("-- 目标记录备份；恢复前停用站点并核对当前库，禁止直接导入存在同 ID 的数据库。\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n");
        $tokens = ['user'=>[], 'admin'=>[]];
        foreach ($plan as $table => $where) {
            $stmt = $db->query('SELECT * FROM '.$q($table).' WHERE '.$where.' FOR UPDATE');
            $seen = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($table === $prefix.'user_session' && isset($row['token'])) $tokens['user'][] = (string)$row['token'];
                if ($table === $prefix.'tenant_admin_session' && isset($row['token'])) $tokens['admin'][] = (string)$row['token'];
                foreach ($generated[$table] ?? [] as $column) unset($row[$column]);
                $values = [];
                foreach ($row as $column => $value) {
                    $hex = "X'".bin2hex((string)$value)."'";
                    $values[] = $value === null ? 'NULL' : (isset($binary[$table][$column]) ? $hex : 'CONVERT('.$hex.' USING utf8mb4)');
                }
                $write('INSERT INTO '.$q($table).' ('.implode(',', array_map($q, array_keys($row))).') VALUES ('.implode(',', $values).");\n");
                $seen++;
            }
            if ($seen !== $counts[$table]) throw new RuntimeException('数据仍在变化，请停用站点及任务后重试');
        }
        $write("COMMIT;\n");
        if (!gzclose($gz)) throw new RuntimeException('备份关闭失败');
        $verify = gzopen($path, 'rb');
        if (!$verify) throw new RuntimeException('备份不可读');
        $tail = '';
        $readDigest = hash_init('sha256');
        while (!gzeof($verify)) { $chunk = gzread($verify, 65536); if ($chunk === false) throw new RuntimeException('备份读回失败'); hash_update($readDigest, $chunk); $tail = substr($tail.$chunk, -8); }
        gzclose($verify);
        if ($tail !== "COMMIT;\n" || hash_final($digest) !== hash_final($readDigest)) throw new RuntimeException('备份读回校验不一致');
        foreach ($plan as $table => $where) {
            $deleted = $db->exec('DELETE FROM '.$q($table).' WHERE '.$where);
            if ($deleted !== $counts[$table]) throw new RuntimeException('删除计数不一致，回滚');
        }
        foreach ($plan as $table => $where) {
            if ((int)$db->query('SELECT COUNT(*) FROM '.$q($table).' WHERE '.$where)->fetchColumn() !== 0) throw new RuntimeException('仍有目标记录，回滚');
            if ((int)$db->query('SELECT COUNT(*) FROM '.$q($table).' WHERE NOT ('.$where.')')->fetchColumn() !== $untouched[$table]) throw new RuntimeException('非目标记录数量变化，回滚');
        }
        $db->commit();
        return ['mode'=>'数据库删除已提交，目标残留为 0', 'total'=>array_sum($counts), 'backup'=>$path, 'sha256'=>hash_file('sha256', $path), 'tokens'=>$tokens, 'admins'=>$admins];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $root = '/www/wwwroot/lantu.makesgoal.com/server-next';
        if (!in_array($argv[1] ?? '', ['--preview', '--execute'], true) || count($argv) !== 2) throw new RuntimeException('用法：php purge-test-tenant.php --preview 或 --execute');
        if (!is_dir($root)) throw new RuntimeException('站点目录不存在');
        chdir($root);
        $env = parse_ini_file($root.'/.env', true, INI_SCANNER_TYPED);
        $d = $env['DATABASE'] ?? [];
        $pdo = new PDO('mysql:host='.($d['HOSTNAME'] ?? '127.0.0.1').';port='.($d['HOSTPORT'] ?? '3306').';dbname='.($d['DATABASE'] ?? '').';charset=utf8mb4', $d['USERNAME'] ?? '', $d['PASSWORD'] ?? '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $result = purgeTestTenant($pdo, $d['PREFIX'] ?? 'la_', $argv[1] === '--execute', '/root/beimi-reset-backups');
        $tokens = $result['tokens'] ?? null;
        $admins = $result['admins'] ?? [];
        unset($result['tokens'], $result['admins']);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        if ($tokens !== null) {
            try {
                require $root.'/vendor/autoload.php';
                (new \think\App($root.'/'))->initialize();
                $userCache = new \app\common\cache\UserTokenCache();
                $adminCache = new \app\common\cache\TenantAdminTokenCache();
                foreach ($tokens['user'] as $token) $userCache->deleteUserInfo($token);
                foreach ($tokens['admin'] as $token) $adminCache->deleteAdminInfo($token);
                (new \app\common\cache\TenantAdminAuthCache('', 1))->deleteTag();
                echo "目标登录缓存已清理。\n";
            } catch (Throwable) {
                echo "数据库已提交，但缓存清理未完成。暂勿重新启用站点，请反馈此输出。\n";
                exit(2);
            }
        }
    } catch (Throwable $e) {
        // 不输出 PDO 错误详情，避免暴露连接参数或业务数据。
        echo $e instanceof PDOException ? '数据库操作失败；请反馈错误代码 '.$e->getCode() : $e->getMessage();
        echo "\n未确认删除成功；若曾发生连接中断，需核对数据库后再重试。\n";
        exit(1);
    }
}
