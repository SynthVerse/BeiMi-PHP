<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportCandidateLogic;
use app\api\jxc\logic\GoodsLogic;
use app\common\service\goods\GoodsMaintenancePermissionService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class GoodsCreationConstraintTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->ensureConstraintTables();
        $this->cleanCustomerReportData();
        $this->cleanConstraintData();
        $this->ensureConstraintTenants();
        $this->setAdminIdentity(1);
    }

    protected function tearDown(): void
    {
        $this->cleanConstraintData();
        $this->cleanCustomerReportData();
        request()->adminInfo = [];
        request()->jxcFromUserToken = false;
        parent::tearDown();
    }

    public function test_only_root_or_an_admin_with_goods_maintenance_permission_can_maintain_goods(): void
    {
        $categoryId = $this->createCategory(self::TENANT_ID, '水产');
        $unitId = $this->createCustomerReportUnit('斤');

        $rootCreated = GoodsLogic::add($this->goodsParams('根管理员商品', 'ROOT-GOODS', $categoryId, $unitId));
        self::assertNotFalse($rootCreated, GoodsLogic::getError());
        self::assertFalse($rootCreated['existing']);

        $this->setAdminIdentity(0);
        $deniedEditParams = $this->goodsParams('无权编辑商品', 'DENIED-EDIT', $categoryId, $unitId);
        $deniedEditParams['id'] = (int)$rootCreated['id'];
        self::assertFalse(GoodsLogic::edit($deniedEditParams));
        self::assertSame('当前账号没有商品维护权限', GoodsLogic::getError());
        self::assertFalse(GoodsLogic::add(
            $this->goodsParams('无权商品', 'DENIED-GOODS', $categoryId, $unitId)
        ));
        self::assertSame('当前账号没有商品维护权限', GoodsLogic::getError());

        $this->grantGoodsMaintenancePermission();
        $grantedEditParams = $this->goodsParams('授权编辑商品', 'GRANTED-EDIT', $categoryId, $unitId);
        $grantedEditParams['id'] = (int)$rootCreated['id'];
        self::assertTrue(GoodsLogic::edit($grantedEditParams), GoodsLogic::getError());
        self::assertSame(
            '授权编辑商品',
            (string)Db::name('goods')->where('id', (int)$rootCreated['id'])->value('name')
        );
        $granted = GoodsLogic::add($this->goodsParams('授权商品', 'GRANTED-GOODS', $categoryId, $unitId));
        self::assertNotFalse($granted, GoodsLogic::getError());
        self::assertFalse($granted['existing']);
        self::assertSame(
            self::TENANT_ID,
            (int)Db::name('goods')->where('id', (int)$granted['id'])->value('tenant_id')
        );
    }

    public function test_duplicate_canonical_name_or_alias_returns_the_existing_tenant_goods(): void
    {
        $categoryId = $this->createCategory(self::TENANT_ID, '水产');
        $unitId = $this->createCustomerReportUnit('斤');
        $existingId = $this->createCustomerReportGoods('桂鱼', 'EXISTING-GUIYU', '斤');
        $this->createCustomerReportAlias($existingId, '鳜鱼');

        foreach ([
            $this->goodsParams('桂 鱼', 'DUPLICATE-NAME', $categoryId, $unitId),
            $this->goodsParams('鳜鱼', 'DUPLICATE-ALIAS', $categoryId, $unitId),
            $this->goodsParams('桂花鱼', 'DUPLICATE-INPUT-ALIAS', $categoryId, $unitId, ['鳜鱼']),
        ] as $params) {
            $result = GoodsLogic::add($params);
            self::assertNotFalse($result, GoodsLogic::getError());
            self::assertTrue($result['existing']);
            self::assertTrue($result['reusable']);
            self::assertFalse($result['requires_activation']);
            self::assertSame('active', $result['existing_state']);
            self::assertSame($existingId, (int)$result['id']);
        }

        self::assertSame(1, Db::name('goods')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_normalized_name_migration_backfill_preserves_runtime_duplicate_semantics(): void
    {
        $categoryId = $this->createCategory(self::TENANT_ID, '水产');
        $unitId = $this->createCustomerReportUnit('斤');
        $whitespace = "\u{0009}\u{000B}\u{000C}\u{0085}\u{00A0}\u{1680}"
            . "\u{2000}\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}";
        $legacyName = $whitespace . 'G' . $whitespace . 'u' . $whitespace . 'I' . $whitespace;
        $existingId = (int)Db::name('goods')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => $legacyName,
            'normalized_name' => '',
            'product_code' => 'LEGACY-NORMALIZED-NAME',
            'units' => '斤',
            'unit_id' => 0,
            'price' => '0.00',
            'cost' => '0.00',
            'stock' => '0.00',
            'category_id' => 0,
            'is_disabled' => 0,
            'is_archived' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $migration = $this->prepareMigration((string)file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20260809_000002_add_goods_maintenance_permission.sql'
        ));
        $backfillStatements = array_values(array_filter(
            array_map('trim', explode(';', $migration)),
            static fn(string $statement): bool => str_contains($statement, 'UPDATE `la_goods`')
                && str_contains($statement, 'SET `normalized_name`')
        ));
        self::assertCount(1, $backfillStatements);
        Db::execute($backfillStatements[0]);

        $expected = GoodsAliasService::normalize($legacyName);
        self::assertSame('gui', $expected);
        self::assertSame(
            $expected,
            (string)Db::name('goods')->where('id', $existingId)->value('normalized_name')
        );

        $resolved = GoodsLogic::add($this->goodsParams('GUI', 'MIGRATED-DUPLICATE', $categoryId, $unitId));
        self::assertNotFalse($resolved, GoodsLogic::getError());
        self::assertTrue($resolved['existing']);
        self::assertSame($existingId, (int)$resolved['id']);
    }

    public function test_migration_repairs_missing_normalized_name_index_when_column_already_exists(): void
    {
        if ($this->normalizedNameIndexExists()) {
            Db::execute('ALTER TABLE `la_goods` DROP INDEX `idx_tenant_normalized_name`');
        }

        try {
            $migration = $this->prepareMigration((string)file_get_contents(
                dirname(__DIR__, 2) . '/database/migrations/20260809_000002_add_goods_maintenance_permission.sql'
            ));
            $constraintSection = strstr($migration, 'SET @template_goods_parent_id', true);
            self::assertIsString($constraintSection);
            foreach (array_filter(array_map('trim', explode(';', $constraintSection))) as $statement) {
                Db::execute($statement);
            }

            self::assertSame(
                1,
                (int)Db::query(
                    "SELECT COUNT(1) AS aggregate FROM information_schema.COLUMNS "
                    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_goods' "
                    . "AND COLUMN_NAME = 'normalized_name'"
                )[0]['aggregate']
            );
            self::assertTrue($this->normalizedNameIndexExists());
        } finally {
            if (!$this->normalizedNameIndexExists()) {
                Db::execute(
                    'ALTER TABLE `la_goods` ADD KEY `idx_tenant_normalized_name` (`tenant_id`,`normalized_name`)'
                );
            }
        }
    }

    public function test_creation_and_duplicate_resolution_are_tenant_isolated(): void
    {
        $categoryId = $this->createCategory(self::TENANT_ID, '本租户分类');
        $unitId = $this->createCustomerReportUnit('件');
        $otherGoodsId = (int)Db::name('goods')->insertGetId([
            'tenant_id' => self::OTHER_TENANT_ID,
            'name' => '跨租户同名商品',
            'normalized_name' => GoodsAliasService::normalize('跨租户同名商品'),
            'product_code' => 'OTHER-TENANT-GOODS',
            'units' => '件',
            'unit_id' => 0,
            'price' => '1.00',
            'cost' => '1.00',
            'stock' => '0.00',
            'category_id' => 0,
            'is_disabled' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        Db::name('goods_alias')->insert([
            'tenant_id' => self::OTHER_TENANT_ID,
            'goods_id' => $otherGoodsId,
            'cloud_goods_id' => 0,
            'alias' => '跨租户别名',
            'normalized_alias' => '跨租户别名',
            'source' => 'tenant',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        foreach (['跨租户同名商品', '跨租户别名'] as $name) {
            $created = GoodsLogic::add(
                $this->goodsParams($name, 'LOCAL-' . md5($name), $categoryId, $unitId)
            );
            self::assertNotFalse($created, GoodsLogic::getError());
            self::assertFalse($created['existing']);
            self::assertNotSame($otherGoodsId, (int)$created['id']);
            self::assertSame(
                self::TENANT_ID,
                (int)Db::name('goods')->where('id', (int)$created['id'])->value('tenant_id')
            );
        }
    }

    public function test_conflicting_tokens_that_resolve_to_different_goods_are_rejected(): void
    {
        $categoryId = $this->createCategory(self::TENANT_ID, '水产');
        $unitId = $this->createCustomerReportUnit('斤');
        $canonicalGoodsId = $this->createCustomerReportGoods('桂鱼', 'CONFLICT-CANONICAL', '斤');
        $aliasGoodsId = $this->createCustomerReportGoods('鲈鱼', 'CONFLICT-ALIAS', '斤');
        $this->createCustomerReportAlias($aliasGoodsId, '鳜鱼');

        self::assertFalse(GoodsLogic::add(
            $this->goodsParams('桂鱼', 'AMBIGUOUS-TOKENS', $categoryId, $unitId, ['鳜鱼'])
        ));
        self::assertSame(
            '商品名称或别名同时命中多个既有商品，请选择已有商品',
            GoodsLogic::getError()
        );
        self::assertSame(
            2,
            Db::name('goods')
                ->where('tenant_id', self::TENANT_ID)
                ->whereIn('id', [$canonicalGoodsId, $aliasGoodsId])
                ->count()
        );
    }

    public function test_cross_tenant_category_and_unit_are_rejected(): void
    {
        $categoryId = $this->createCategory(self::TENANT_ID, '本租户分类');
        $unitId = $this->createCustomerReportUnit('件');
        $otherCategoryId = $this->createCategory(self::OTHER_TENANT_ID, '其他租户分类');
        $otherUnitId = (int)Db::name('goods_unit')->insertGetId([
            'tenant_id' => self::OTHER_TENANT_ID,
            'name' => '其他租户单位',
            'status' => 1,
            'sort' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        self::assertFalse(GoodsLogic::add(
            $this->goodsParams('错误分类商品', 'BAD-CATEGORY', $otherCategoryId, $unitId)
        ));
        self::assertSame('商品分类不存在', GoodsLogic::getError());

        self::assertFalse(GoodsLogic::add(
            $this->goodsParams('错误单位商品', 'BAD-UNIT', $categoryId, $otherUnitId)
        ));
        self::assertSame('基础单位不存在', GoodsLogic::getError());
    }

    public function test_hidden_category_and_disabled_unit_are_rejected_for_creation_and_editing(): void
    {
        $categoryId = $this->createCategory(self::TENANT_ID, '有效分类');
        $unitId = $this->createCustomerReportUnit('有效单位');
        $hiddenCategoryId = $this->createCategory(self::TENANT_ID, '隐藏分类');
        Db::name('tenant_goodscat')->where('id', $hiddenCategoryId)->update(['is_show' => 1]);
        $deletedCategoryId = $this->createCategory(self::TENANT_ID, '已删除分类');
        Db::name('tenant_goodscat')->where('id', $deletedCategoryId)->update(['delete_time' => time()]);
        $disabledUnitId = $this->createCustomerReportUnit('停用单位', 0);

        self::assertFalse(GoodsLogic::add(
            $this->goodsParams('隐藏分类商品', 'HIDDEN-CATEGORY', $hiddenCategoryId, $unitId)
        ));
        self::assertSame('商品分类不可用', GoodsLogic::getError());

        self::assertFalse(GoodsLogic::add(
            $this->goodsParams('已删除分类商品', 'DELETED-CATEGORY', $deletedCategoryId, $unitId)
        ));
        self::assertSame('商品分类不存在', GoodsLogic::getError());

        self::assertFalse(GoodsLogic::add(
            $this->goodsParams('停用单位商品', 'DISABLED-UNIT', $categoryId, $disabledUnitId)
        ));
        self::assertSame('基础单位不可用', GoodsLogic::getError());

        $goodsId = $this->createCustomerReportGoods('待编辑商品', 'EDIT-MASTER-DATA', '有效单位');
        self::assertFalse(GoodsLogic::edit([
            'id' => $goodsId,
            'name' => '待编辑商品',
            'product_code' => (string)Db::name('goods')->where('id', $goodsId)->value('product_code'),
            'category_id' => $hiddenCategoryId,
            'unit_id' => $unitId,
            'units' => '有效单位',
        ]));
        self::assertSame('商品分类不可用', GoodsLogic::getError());

        self::assertFalse(GoodsLogic::edit([
            'id' => $goodsId,
            'name' => '待编辑商品',
            'product_code' => (string)Db::name('goods')->where('id', $goodsId)->value('product_code'),
            'category_id' => $categoryId,
            'unit_id' => $disabledUnitId,
            'units' => '停用单位',
        ]));
        self::assertSame('基础单位不可用', GoodsLogic::getError());
    }

    public function test_customer_report_creation_reuses_existing_goods_and_exposes_permission_state(): void
    {
        $categoryId = $this->createCategory(self::TENANT_ID, '水产');
        $unitId = $this->createCustomerReportUnit('斤');
        $existingId = $this->createCustomerReportGoods('桂鱼', 'QUICK-EXISTING', '斤');
        $this->createCustomerReportAlias($existingId, '鳜鱼');

        $this->setAdminIdentity(0);
        $denied = CustomerReportCandidateLogic::recognize('新商品12斤')['lines'][0];
        self::assertFalse($denied['quick_create_allowed']);
        self::assertFalse(CustomerReportCandidateLogic::quickCreateGoods([
            'name' => '新商品',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
        ]));

        $this->grantGoodsMaintenancePermission();
        $allowed = CustomerReportCandidateLogic::recognize('新商品12斤')['lines'][0];
        self::assertTrue($allowed['quick_create_allowed']);

        $result = CustomerReportCandidateLogic::quickCreateGoods([
            'name' => '鳜鱼',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
        ]);
        self::assertNotFalse($result, CustomerReportCandidateLogic::getError());
        self::assertTrue($result['existing']);
        self::assertSame($existingId, (int)$result['goods_id']);
        self::assertSame('桂鱼', $result['goods']['name']);
    }

    public function test_inactive_duplicate_requires_recovery_and_customer_report_does_not_reuse_it(): void
    {
        $categoryId = $this->createCategory(self::TENANT_ID, '水产');
        $unitId = $this->createCustomerReportUnit('斤');
        $states = [
            'disabled' => ['name' => '停用桂鱼', 'is_disabled' => 1, 'is_archived' => 0, 'error' => '同名商品已停用，请先启用'],
            'archived' => ['name' => '归档桂鱼', 'is_disabled' => 0, 'is_archived' => 1, 'error' => '同名商品已归档，请先恢复'],
        ];

        foreach ($states as $state => $fixture) {
            $goodsId = $this->createCustomerReportGoods($fixture['name'], 'INACTIVE-' . $state, '斤');
            Db::name('goods')->where('id', $goodsId)->update([
                'is_disabled' => $fixture['is_disabled'],
                'is_archived' => $fixture['is_archived'],
            ]);

            $resolved = GoodsLogic::add(
                $this->goodsParams($fixture['name'], 'DUPLICATE-' . $state, $categoryId, $unitId)
            );
            self::assertNotFalse($resolved, GoodsLogic::getError());
            self::assertTrue($resolved['existing']);
            self::assertFalse($resolved['reusable']);
            self::assertTrue($resolved['requires_activation']);
            self::assertSame($state, $resolved['existing_state']);
            self::assertSame($goodsId, (int)$resolved['id']);

            self::assertFalse(CustomerReportCandidateLogic::quickCreateGoods([
                'name' => $fixture['name'],
                'category_id' => $categoryId,
                'unit_id' => $unitId,
            ]));
            self::assertSame($fixture['error'], CustomerReportCandidateLogic::getError());
            $returnData = CustomerReportCandidateLogic::getReturnData();
            self::assertSame($goodsId, (int)$returnData['goods_id']);
            self::assertFalse($returnData['reusable']);
            self::assertTrue($returnData['requires_activation']);
            self::assertSame($state, $returnData['existing_state']);

            $recognized = CustomerReportCandidateLogic::recognize($fixture['name'] . '12斤')['lines'][0];
            self::assertSame('none', $recognized['goods']['status']);
        }
    }

    public function test_two_concurrent_creations_return_one_goods_id_without_duplicate_rows(): void
    {
        if (!function_exists('proc_open')) {
            self::fail('The concurrent goods creation gate requires proc_open.');
        }
        $categoryId = $this->createCategory(self::TENANT_ID, '并发分类');
        $unitId = $this->createCustomerReportUnit('件');
        $paths = [];
        $processes = [];
        $startPath = tempnam(sys_get_temp_dir(), 'goods-create-start-');
        unlink($startPath);
        try {
            foreach (['A', 'B'] as $suffix) {
                $inputPath = tempnam(sys_get_temp_dir(), 'goods-create-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'goods-create-output-');
                $paths[] = $inputPath;
                $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode([
                    'tenant_id' => self::TENANT_ID,
                    'admin_id' => self::ADMIN_ID,
                    'params' => $this->goodsParams(
                        '并发桂鱼',
                        'CONCURRENT-' . $suffix,
                        $categoryId,
                        $unitId,
                        ['并发鳜鱼']
                    ),
                ], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY)
                    . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/goods_create_worker.php')
                    . ' ' . escapeshellarg($inputPath)
                    . ' ' . escapeshellarg($outputPath)
                    . ' ' . escapeshellarg($startPath);
                $processes[] = [
                    proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes),
                    $pipes,
                    $outputPath,
                ];
            }
            usleep(100_000);
            touch($startPath);

            $responses = [];
            foreach ($processes as [$process, $pipes, $outputPath]) {
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $stdout . $stderr);
                $responses[] = json_decode((string)file_get_contents($outputPath), true) ?: [];
            }

            $ids = array_map(static fn(array $response): int => (int)($response['result']['id'] ?? 0), $responses);
            self::assertGreaterThan(0, $ids[0]);
            self::assertSame($ids[0], $ids[1], json_encode($responses, JSON_UNESCAPED_UNICODE));
            self::assertEqualsCanonicalizing(
                [false, true],
                array_map(static fn(array $response): bool => (bool)($response['result']['existing'] ?? false), $responses)
            );
            self::assertSame(
                1,
                Db::name('goods')->where('tenant_id', self::TENANT_ID)->where('name', '并发桂鱼')->count()
            );
            self::assertSame(
                1,
                Db::name('goods_alias')->where('tenant_id', self::TENANT_ID)->where('normalized_alias', '并发鳜鱼')->count()
            );
        } finally {
            foreach ($processes as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    $status = proc_get_status($process);
                    if (($status['running'] ?? false) === true) {
                        proc_terminate($process);
                    }
                    proc_close($process);
                }
            }
            if (is_file($startPath)) {
                unlink($startPath);
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private function goodsParams(
        string $name,
        string $code,
        int $categoryId,
        int $unitId,
        array $aliases = []
    ): array {
        $dimensionId = $this->defaultSkuDimensionId();
        return [
            'tenant_id' => self::OTHER_TENANT_ID,
            'name' => $name,
            'product_code' => $code,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'units' => (string)Db::name('goods_unit')->where('id', $unitId)->value('name'),
            'aliases' => $aliases,
            'price' => '0.00',
            'cost' => '0.00',
            'is_disabled' => 0,
            'dimensions' => [[
                'dimension_id' => $dimensionId,
                'values' => [['name' => '默认', 'code' => 'default']],
            ]],
            'combinations' => [['values' => ['test_default_sku' => 'default']]],
        ];
    }

    private function defaultSkuDimensionId(): int
    {
        $id = (int)Db::name('goods_spec')
            ->where('tenant_id', self::TENANT_ID)
            ->where('code', 'test_default_sku')
            ->value('id');
        if ($id > 0) {
            return $id;
        }
        return (int)Db::name('goods_spec')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'template_id' => 0,
            'name' => '测试默认SKU',
            'code' => 'test_default_sku',
            'dimension_type' => 'sku',
            'status' => 1,
            'sort' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function setAdminIdentity(int $root): void
    {
        request()->tenantId = self::TENANT_ID;
        request()->adminId = self::ADMIN_ID;
        request()->userId = self::ADMIN_ID;
        request()->jxcFromUserToken = false;
        request()->adminInfo = [
            'admin_id' => self::ADMIN_ID,
            'tenant_id' => self::TENANT_ID,
            'root' => $root,
        ];
        Db::name('tenant_admin')->where('id', self::ADMIN_ID)->delete();
        Db::name('tenant_admin')->insert([
            'id' => self::ADMIN_ID,
            'tenant_id' => self::TENANT_ID,
            'root' => $root,
            'name' => '测试管理员',
            'account' => 'goods-constraint-admin',
            'password' => 'test-password',
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => null,
        ]);
    }

    private function grantGoodsMaintenancePermission(): void
    {
        $roleId = (int)Db::name('tenant_system_role')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => '商品维护员',
            'desc' => '',
            'sort' => 0,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => null,
        ]);
        $menuId = (int)Db::name('tenant_system_menu')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'pid' => 0,
            'type' => 'A',
            'name' => '商品维护',
            'is_disable' => 0,
            'perms' => GoodsMaintenancePermissionService::MAINTAIN_PERMISSION,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        Db::name('tenant_admin_role')->insert([
            'admin_id' => self::ADMIN_ID,
            'role_id' => $roleId,
        ]);
        Db::name('tenant_system_role_menu')->insert([
            'role_id' => $roleId,
            'menu_id' => $menuId,
        ]);
    }

    private function createCategory(int $tenantId, string $name): int
    {
        return (int)Db::name('tenant_goodscat')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'is_default' => null,
            'is_show' => 0,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => null,
        ]);
    }

    private function normalizedNameIndexExists(): bool
    {
        return (int)Db::query(
            "SELECT COUNT(1) AS aggregate FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_goods' "
            . "AND INDEX_NAME = 'idx_tenant_normalized_name'"
        )[0]['aggregate'] === 1;
    }

    private function ensureConstraintTables(): void
    {
        foreach ([
            'CREATE TABLE IF NOT EXISTS `la_tenant_goodscat` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int unsigned NOT NULL DEFAULT 0,
                `name` varchar(200) NOT NULL DEFAULT "",
                `is_default` tinyint unsigned NULL DEFAULT NULL,
                `is_show` tinyint unsigned NOT NULL DEFAULT 0,
                `create_time` int unsigned NOT NULL DEFAULT 0,
                `update_time` int unsigned NOT NULL DEFAULT 0,
                `delete_time` int NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `la_tenant_admin` (
                `id` int unsigned NOT NULL,
                `tenant_id` int unsigned NOT NULL DEFAULT 0,
                `root` tinyint unsigned NOT NULL DEFAULT 0,
                `name` varchar(32) NOT NULL DEFAULT "",
                `avatar` varchar(255) NOT NULL DEFAULT "",
                `account` varchar(32) NOT NULL DEFAULT "",
                `password` varchar(32) NOT NULL DEFAULT "",
                `login_time` int NULL DEFAULT NULL,
                `login_ip` varchar(39) NOT NULL DEFAULT "",
                `multipoint_login` tinyint unsigned NOT NULL DEFAULT 1,
                `disable` tinyint unsigned NOT NULL DEFAULT 0,
                `create_time` int NOT NULL DEFAULT 0,
                `update_time` int NULL DEFAULT NULL,
                `delete_time` int NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `la_tenant_system_role` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int unsigned NOT NULL DEFAULT 0,
                `name` varchar(100) NOT NULL DEFAULT "",
                `desc` varchar(128) NOT NULL DEFAULT "",
                `sort` int NOT NULL DEFAULT 0,
                `create_time` int NULL DEFAULT NULL,
                `update_time` int NULL DEFAULT NULL,
                `delete_time` int NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `la_tenant_system_menu` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int unsigned NOT NULL DEFAULT 0,
                `pid` int unsigned NOT NULL DEFAULT 0,
                `type` char(2) NOT NULL DEFAULT "",
                `name` varchar(100) NOT NULL DEFAULT "",
                `icon` varchar(100) NOT NULL DEFAULT "",
                `sort` smallint unsigned NOT NULL DEFAULT 0,
                `is_disable` tinyint unsigned NOT NULL DEFAULT 0,
                `perms` varchar(200) NOT NULL DEFAULT "",
                `paths` varchar(100) NOT NULL DEFAULT "",
                `component` varchar(200) NOT NULL DEFAULT "",
                `selected` varchar(200) NOT NULL DEFAULT "",
                `params` varchar(200) NOT NULL DEFAULT "",
                `is_cache` tinyint unsigned NOT NULL DEFAULT 0,
                `is_show` tinyint unsigned NOT NULL DEFAULT 1,
                `create_time` int unsigned NOT NULL DEFAULT 0,
                `update_time` int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `la_tenant_admin_role` (
                `admin_id` int unsigned NOT NULL DEFAULT 0,
                `role_id` int unsigned NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `la_tenant_system_role_menu` (
                `role_id` int unsigned NOT NULL DEFAULT 0,
                `menu_id` int unsigned NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ] as $statement) {
            Db::execute(str_replace('`', chr(96), $statement));
        }
        try {
            Db::execute('ALTER TABLE `la_tenant_goodscat` ADD COLUMN `delete_time` int NULL DEFAULT NULL');
        } catch (\Throwable) {
        }
    }

    private function ensureConstraintTenants(): void
    {
        foreach ([
            self::TENANT_ID => ['sn' => 'goods-constraint-primary', 'name' => '商品约束测试租户'],
            self::OTHER_TENANT_ID => ['sn' => 'goods-constraint-other', 'name' => '商品约束其他租户'],
        ] as $tenantId => $tenant) {
            Db::execute(
                "INSERT INTO `la_tenant` (`id`,`sn`,`name`,`disable`,`create_time`) VALUES ("
                . $tenantId
                . ",'"
                . $tenant['sn']
                . "','"
                . $tenant['name']
                . "',0,"
                . time()
                . ') ON DUPLICATE KEY UPDATE `disable`=0'
            );
        }
    }

    private function cleanConstraintData(): void
    {
        $roleIds = array_map(
            'intval',
            Db::name('tenant_system_role')
                ->where('tenant_id', 'in', [self::TENANT_ID, self::OTHER_TENANT_ID])
                ->column('id')
        );
        if ($roleIds !== []) {
            Db::name('tenant_system_role_menu')->whereIn('role_id', $roleIds)->delete();
            Db::name('tenant_admin_role')->whereIn('role_id', $roleIds)->delete();
        }
        Db::name('tenant_admin_role')->where('admin_id', self::ADMIN_ID)->delete();
        Db::name('tenant_system_menu')->where('tenant_id', 'in', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('tenant_system_role')->where('tenant_id', 'in', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('tenant_admin')->where('id', self::ADMIN_ID)->delete();
        Db::name('tenant_goodscat')->where('tenant_id', 'in', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('goods_alias')->where('tenant_id', self::OTHER_TENANT_ID)->delete();
        Db::name('goods_unit')->where('tenant_id', self::OTHER_TENANT_ID)->delete();
        Db::name('goods')->where('tenant_id', self::OTHER_TENANT_ID)->delete();
    }
}
