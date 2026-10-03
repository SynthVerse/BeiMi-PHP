<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2).'/scripts/purge-test-tenant.php';

final class PurgeFailurePDO extends PDO
{
    public bool $failDelete = false;
    public function exec(string $statement): int|false
    {
        if ($this->failDelete && str_starts_with($statement, 'DELETE FROM ') && str_contains($statement, 'tenant_admin`')) throw new RuntimeException('模拟删除中途失败');
        return parent::exec($statement);
    }
}

final class PurgeTestTenantTest extends TestCase
{
    private PurgeFailurePDO $db;
    private string $prefix;
    private string $backup;
    private array $tables = [];

    protected function setUp(): void
    {
        $env = parse_ini_file(dirname(__DIR__, 2).'/.env.testing', true, INI_SCANNER_TYPED);
        if (!\tests\support\IsolatedDatabaseGuard::acceptsEnvironment($env)) throw new RuntimeException('拒绝非隔离数据库');
        $d = $env['DATABASE'];
        $this->db = new PurgeFailurePDO('mysql:host=127.0.0.1;port=3307;dbname='.$d['DATABASE'].';charset=utf8mb4', $d['USERNAME'], $d['PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->prefix = 'purgecheck_'.bin2hex(random_bytes(5)).'_';
        $this->backup = sys_get_temp_dir().'/'.$this->prefix;
        foreach ([
            'tenant'=>'id INT PRIMARY KEY,sn VARCHAR(32),name VARCHAR(100)',
            'user'=>'id INT PRIMARY KEY,sn VARCHAR(32),tenant_id INT',
            'tenant_member'=>'tenant_id INT,user_id INT',
            'tenant_admin'=>'id INT PRIMARY KEY,tenant_id INT',
            'tenant_system_role'=>'id INT PRIMARY KEY,tenant_id INT',
            'user_auth'=>'id INT PRIMARY KEY,tenant_id INT,user_id INT',
            'user_session'=>'id INT PRIMARY KEY,tenant_id INT,user_id INT,token VARCHAR(32)',
            'tenant_admin_session'=>'id INT PRIMARY KEY,admin_id INT,token VARCHAR(32)',
            'tenant_admin_role'=>'admin_id INT,role_id INT',
            'tenant_system_role_menu'=>'role_id INT,menu_id INT',
            'tenant_relation'=>'id INT PRIMARY KEY,parent_tenant_id INT,child_tenant_id INT',
            'business'=>'id INT PRIMARY KEY,tenant_id INT,note TEXT,derived INT GENERATED ALWAYS AS (tenant_id+1) STORED',
            'admin'=>'id INT PRIMARY KEY,name VARCHAR(32)',
        ] as $name=>$definition) {
            $table = $this->prefix.$name;
            $this->db->exec("CREATE TABLE `$table` ($definition) ENGINE=InnoDB");
            $this->tables[] = $table;
        }
        foreach ([
            'tenant'=>"(1,'xglh67j6','金木火水产商行'),(2,'other','其他店铺')",
            'user'=>"(5,'24859138',1),(6,'other',2)",
            'tenant_member'=>'(1,5),(2,6)',
            'tenant_admin'=>'(11,1),(12,2)',
            'tenant_system_role'=>'(21,1),(22,2)',
            'user_auth'=>'(1,0,5),(2,2,6)',
            'user_session'=>"(1,0,5,'old-user'),(2,2,6,'other-user')",
            'tenant_admin_session'=>"(1,11,'old-admin'),(2,12,'other-admin')",
            'tenant_admin_role'=>'(11,21),(12,22)',
            'tenant_system_role_menu'=>'(21,1),(22,1)',
            'tenant_relation'=>'(1,1,2)',
            'admin'=>"(1,'平台管理员')",
        ] as $name=>$values) $this->db->exec('INSERT INTO `'.$this->prefix.$name.'` VALUES '.$values);
        $this->db->exec('INSERT INTO `'.$this->prefix.'business` (id,tenant_id,note) VALUES (1,1,'.$this->db->quote("中文'\n\\内容")."),(2,2,'其他数据')");
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tables) as $table) $this->db->exec("DROP TABLE `$table`");
        foreach (glob($this->backup.'/*.sql.gz') ?: [] as $file) unlink($file);
        if (is_dir($this->backup)) rmdir($this->backup);
    }

    private function countRows(string $table): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM `'.$this->prefix.$table.'`')->fetchColumn();
    }

    public function test_preview_never_writes_and_execution_preserves_other_tenant_and_backup_restores_generated_columns(): void
    {
        $preview = purgeTestTenant($this->db, $this->prefix, false, $this->backup);
        self::assertGreaterThan(0, $preview['total']);
        self::assertSame(2, $this->countRows('tenant'));
        self::assertDirectoryDoesNotExist($this->backup);
        $result = purgeTestTenant($this->db, $this->prefix, true, $this->backup);
        self::assertSame($preview['total'], $result['total']);
        self::assertSame(['old-user'], $result['tokens']['user']);
        self::assertSame(['old-admin'], $result['tokens']['admin']);
        foreach (['tenant','user','user_auth','user_session','tenant_admin_session','business','admin'] as $table) self::assertSame(1, $this->countRows($table), $table);
        self::assertSame(0, $this->countRows('tenant_relation'));
        $sql = gzdecode(file_get_contents($result['backup']));
        self::assertStringNotContainsString('`derived`', $sql);
        $this->db->exec($sql);
        self::assertSame(2, $this->countRows('tenant'));
        self::assertSame("中文'\n\\内容", $this->db->query('SELECT note FROM `'.$this->prefix.'business` WHERE id=1')->fetchColumn());
        self::assertSame(2, (int)$this->db->query('SELECT derived FROM `'.$this->prefix.'business` WHERE id=1')->fetchColumn());
    }

    public function test_identity_mismatch_refuses_deletion(): void
    {
        $this->db->exec('UPDATE `'.$this->prefix."tenant` SET sn='changed' WHERE id=1");
        try { purgeTestTenant($this->db, $this->prefix, true, $this->backup); self::fail('应拒绝'); }
        catch (RuntimeException $e) { self::assertStringContainsString('目标身份不匹配', $e->getMessage()); }
        self::assertSame(2, $this->countRows('user'));
    }

    public function test_other_tenant_membership_refuses_deletion(): void
    {
        $this->db->exec('INSERT INTO `'.$this->prefix.'tenant_member` VALUES (2,5)');
        try { purgeTestTenant($this->db, $this->prefix, true, $this->backup); self::fail('应拒绝'); }
        catch (RuntimeException $e) { self::assertStringContainsString('成员关系发生变化', $e->getMessage()); }
        self::assertSame(2, $this->countRows('tenant'));
    }

    public function test_failure_after_some_deletes_rolls_back_all_target_rows(): void
    {
        $this->db->failDelete = true;
        try { purgeTestTenant($this->db, $this->prefix, true, $this->backup); self::fail('应失败'); }
        catch (RuntimeException $e) { self::assertSame('模拟删除中途失败', $e->getMessage()); }
        foreach (['business','tenant','user','user_auth','user_session'] as $table) self::assertSame(2, $this->countRows($table), $table);
        self::assertFalse($this->db->inTransaction());
    }
}
