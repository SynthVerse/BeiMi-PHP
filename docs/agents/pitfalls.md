# 踩坑索引

按根因去重。每条记录必须指向实际防线；仅有“不要这样做”的提醒不算已防护。

## PIT-0001：PHPUnit 隔离数据库端口字段与示例不一致

- 状态：已防护
- 首次发生：2026-07-28
- 最近发生：2026-07-28
- 复发次数：0
- 适用范围：`BeiMi-PHP` 的 PHPUnit 启动、`.env.testing` 配置
- 相关问题：无

### 触发场景

开发者根据 `.example.env` 创建 `.env.testing`，保留 `DATABASE.HOSTPORT = 3307` 后运行 PHPUnit。

### 根因

`.example.env` 使用 ThinkPHP 实际读取的 `DATABASE.HOSTPORT`，但 PHPUnit 启动保护只检查不存在的 `DATABASE.PORT`，导致安全护栏在任何数据库连接前错误拒绝测试。

### 错误做法

复制示例配置后，假定 PHPUnit 启动保护会读取同一个端口字段。

### 正确做法

启动保护优先读取 `DATABASE.HOSTPORT`，并仅为兼容旧配置回退读取 `DATABASE.PORT`；测试环境仍必须显式启用隔离标记、使用 `beimi_test_*` 数据库和本机 3307 端口。

### 防线

- 自动化防线：`tests/bootstrap.php` 使用 `HOSTPORT` 并兼容 `PORT`，使示例配置与启动保护共用同一端口语义。
- 架构防线：PHPUnit 在初始化框架前拒绝非隔离数据库，避免测试继承根目录 `.env`。
- 决策与知识：本记录；实际运行前仍需配置并启动独立的本机 MySQL 3307 测试库。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-28 | Project #1 / 仓库级库存余额与统一库存原语 | 首次运行 PHPUnit | 示例与启动保护读取不同端口字段，护栏错误拒绝。 |

## PIT-0002：测试复用旧表时未实际执行新迁移

- 状态：已防护
- 首次发生：2026-07-29
- 最近发生：2026-07-29
- 复发次数：0
- 适用范围：新增表的 SQL 迁移与 PHPUnit 集成测试
- 相关问题：PIT-0001

### 触发场景

测试数据库已残留同名旧表，测试再次执行 `CREATE TABLE IF NOT EXISTS` 形式的新迁移。

### 根因

`IF NOT EXISTS` 只保证表存在，不会把新增列、索引或约束补到既有表；此前临时测试表缺少 `available_qty`，导致余额服务写入时失败。

### 错误做法

在同一个隔离测试库中直接复用可能由旧测试创建的同名表，并把迁移重复执行误认为结构已同步。

### 正确做法

涉及建表迁移的 PHPUnit 用例先在已由启动保护锁定的 `beimi_test_*` / `3307` 隔离库中删除目标测试表，再通过实际迁移重建；随后才验证重复执行的幂等性。

### 防线

- 自动化防线：`tests/unit/WarehouseGoodsBalanceServiceTest.php` 的 `resetWarehouseGoodsBalanceSchema()` 在每个用例前重建目标表，并从实际迁移文件执行建表 SQL。
- 架构防线：生产迁移仍只做建表，不对无历史数据项目的旧库存进行回填或猜测性修复。
- 决策与知识：本记录及 `docs/adr/0001-客户报货库存边界.md`。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-29 | Project #1 / 仓库级库存余额与统一库存原语 | 首次执行余额服务测试 | 测试库保留了开发初期临时表，但测试未重建目标 schema。 |

## PIT-0003：临时 ThinkPHP App 污染 ORM 静态数据库状态

- 状态：已防护
- 首次发生：2026-07-29
- 最近发生：2026-07-29
- 复发次数：0
- 适用范围：`BeiMi-PHP` PHPUnit 中创建临时 `think\App` 并执行路由 dispatch 的契约测试
- 相关问题：PIT-0001

### 触发场景

契约测试在当前 PHPUnit 进程中创建、初始化临时 `think\App`，随后继续运行依赖 ORM 模型的集成测试。

### 根因

`App::initialize()` 会调用 `Model::setDb()` 和 `Model::setInvoker()` 修改 ORM 静态引用。旧测试在初始化后才保存容器，并且只恢复容器，未恢复模型层静态 DbManager 与调用器；后续模型查询因此使用临时 App 的连接配置，而 `Db::name()` 仍使用原测试连接，造成连接错误或阻塞。

### 错误做法

把“恢复 `think\Container`”视为临时 App 路由 dispatch 的完整清理，不恢复 ThinkORM 的静态状态。

### 正确做法

创建临时 App 前先保存原容器及其 DbManager；临时 App 结束时恢复容器，并调用 `Model::setDb()`、`Model::setInvoker()` 恢复模型静态引用。

### 防线

- 自动化防线：`tests/unit/JxcLoginMiddlewareAuthorityContractTest.php` 的 `test_temporary_dispatch_keeps_orm_bound_to_the_test_connection()` 在 dispatch 后立即执行模型查询，验证仍使用 PHPUnit 测试连接。
- 架构防线：所有临时 App dispatch 测试都采用相同的容器与 ThinkORM 状态恢复顺序。
- 决策与知识：本记录。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-29 | Project #1 / 仓库级库存余额与统一库存原语 | 完整 PHPUnit 在销售预定测试前执行路由契约测试后阻塞或走错连接 | 原契约测试只恢复容器，遗漏了 ThinkORM 静态 DbManager 与调用器。 |

## PIT-0004：外层业务事务中再次开启嵌套事务

- 状态：已防护
- 首次发生：2026-07-29
- 最近发生：2026-07-30
- 复发次数：1
- 适用范围：`CustomerReportLogic` 等已开启业务事务后调用仓库余额原语的路径
- 相关问题：无

### 触发场景

两个独立客户报货请求同时对同一租户、仓库和商品提交预留；外层报货事务内调用会自行 `Db::transaction()` 的库存服务方法，或使用会隐式开启事务的 `Model::create()`。

### 根因

ThinkPHP 在嵌套事务中依赖保存点。仓库余额服务的独立事务入口，以及 `CustomerReport` 模型的 `create()` 隐式事务，都可能在外层客户报货事务中创建内层保存点；并发路径会丢失内层保存点并抛出 `SAVEPOINT trans2 does not exist`。修正保存点问题后，两个请求又会在“不存在的幂等键”或“不存在的客户商品偏好键”的悲观查询上互相持有间隙锁，插入不同键或同一偏好键时触发 MySQL `1213` 死锁。

### 错误做法

在已开启的业务事务中调用 `WarehouseGoodsBalanceService::reserveUpTo()`、`reserve()`、`release()` 或 `consumeReserved()` 等会自行开启事务的入口；或调用会自行管理事务的 ORM 创建接口；或对尚不存在的幂等键、客户商品偏好键做悲观锁查询后插入。

### 正确做法

外层业务事务应调用库存服务的 `*WithinTransaction` 同事务入口，并通过 `Db::name(...)->insert()` 写入报货主从表；只有没有外层事务的调用者才使用服务的独立事务入口。多商品操作必须以库存原语的实际取锁顺序 `(goods_id, warehouse_id)` 排序。幂等键先以普通查询判断，依靠唯一键兜底；客户商品偏好以唯一键上的原子 upsert 写入，不先锁不存在记录；所有客户报货写状态转换仅对 MySQL `1213`/`1205` 做有界重试，最终再按请求指纹回放已成功结果。

### 防线

- 2026-07-30 扩展：跨仓转换在创建任何销售单前，按 `goods_id` 升序预锁本次涉及的全部商品；销售单、订单商品、库存流水和应收／应付在外层事务中统一使用 Query Builder 写入，禁止重新引入 ORM 隐式事务。
- 自动化防线扩展：`tests/unit/CustomerReportWorkflowTest.php` 的多仓后置计价失败用例断言销售单、库存流水、应收及报货状态整体回滚；`tests/unit/CustomerReportRouteContractTest.php` 禁止外层事务路径重新引入 `Model::create()`，并断言商品预锁早于任何标准销售单发布。
- 自动化防线：`tests/unit/CustomerReportWorkflowTest.php` 的 `test_two_concurrent_submissions_cannot_over_reserve_one_warehouse_balance()` 使用两个 PHP 进程同时提交，断言一单 `submitted_ready`、一单 `submitted_shortage`，且总预留不超过余额。
- 架构防线：`WarehouseGoodsBalanceService` 明确提供 `reserveWithinTransaction()`、`reserveUpToWithinTransaction()`、`releaseWithinTransaction()` 与 `consumeReservedWithinTransaction()`；`CustomerReportLogic` 在外层事务内只调用这些入口，并以 Query Builder 写入主表、明细和预留记录。`CustomerReportPreferenceService::remember()` 用唯一键原子 upsert 保存建议数据，不加间隙锁。`transactionWithRetry()` 统一包裹提交外的编辑、补预留、取消与转换销售事务；明细按 `(goods_id, warehouse_id)` 排序后才触发库存原语。
- 决策与知识：本记录及 `docs/adr/0001-客户报货库存边界.md`。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-29 | Project #1 / 客户报货新链路 | 第 7 张并发提交验收 | 既有实现未区分外层事务与 ORM 隐式事务，也对不存在的幂等键和客户商品偏好键加悲观锁。 |
| 2026-07-30 | 客户报货旧链路删除与标准销售单桥接 | 外层报货转换调用标准销售发布时重新使用 ORM `create()`，并按仓循环触发库存锁 | 原防线只覆盖报货主从表和直接库存原语，没有覆盖新接入的销售、库存流水、财务副作用，也没有对跨仓转换建立“先预锁全部商品”的结构契约。 |

## PIT-0005：迁移静态探针替换前缀但真实执行器保留占位符

- 状态：已防护
- 首次发生：2026-07-29
- 最近发生：2026-07-30
- 复发次数：1
- 适用范围：`scripts/migrate.php`、`scripts/migrate_probe_core.js`、测试数据库辅助器与包含 `{{prefix}}` 的 SQL 迁移
- 相关问题：无

### 触发场景

通过 `scripts/migrate.php` 执行包含 `{{prefix}}` 的采购退货迁移，或绕过预处理直接导入该 SQL；phpMyAdmin 随后显示字面量表 `{{prefix}}purchase_return_order` 和 `{{prefix}}purchase_return_order_lists`。

### 根因

真实迁移执行器读取 SQL 后直接拆分并交给 PDO，没有把 `{{prefix}}` 替换成 `.env` 的 `DATABASE.PREFIX`；静态迁移探针却只对两份已知迁移硬编码替换为 `la_`，使验证路径与生产执行路径不一致，无法阻止未解析占位符进入数据库。

### 错误做法

在静态探针中维护迁移文件特例并自行替换前缀，或把包含 `{{prefix}}` 的原始 SQL 直接交给 PDO／phpMyAdmin。

### 正确做法

真实迁移执行器与迁移探针必须复用同一个 SQL 预处理边界：按当前配置替换全部 `{{prefix}}`，并在执行前拒绝任何残留占位符；迁移验证应覆盖非默认前缀，证明结果来自配置而不是硬编码。

### 防线

- 2026-07-30 扩展：24 份正式迁移源文件全部只保存 `{{prefix}}`，`scripts/migrate_probe_metadata_contract.test.js` 逐份以 `tenantx_` 处理并拒绝 `la_` 泄漏；`scripts/rebuild_dev_database_contract.test.js` 动态验证错库名、非开发环境和非白名单库均被拒绝。
- 2026-07-30 扩展：`CustomerReportTestSupport::prepareMigration()` 与 `WarehouseGoodsBalanceServiceTest` 的测试数据库建表路径也统一调用 `MigrationSqlPreprocessor`；禁止测试辅助器自行 `str_replace` 或直接执行带占位符 SQL。
- 2026-07-30 扩展：`public/install/db/like.sql` 与 `database/sql/jxc_phase1_schema.sql` 也只保存 `{{prefix}}`；`scripts/rebuild-dev-database.ps1` 通过 `scripts/prepare-sql.php` 调用同一 PHP 预处理器后才导入基础结构，并把同一配置显式传给迁移执行器。使用临时 `-ConfigPath` 时强制 `-SkipSeed`，防止种子误写默认 `.env` 的数据库。
- 开发库重建由 `scripts/lib/RebuildDatabaseSafety.ps1` 额外绑定 `.env` 库名、显式 `-ExpectedDatabase`、`APP_ENV=development` 与开发库白名单。
- 自动化防线：`tests/unit/MigrationSqlPreprocessorTest.php` 验证非默认前缀、残留模板拒绝和危险前缀拒绝；`scripts/migrate_probe_metadata_contract.test.js` 比对 PHP 与 JavaScript 预处理输出并验证两端契约；全部 `scripts/*contract.test.js` 与静态迁移探针覆盖 26 份迁移、185 条语句和 99 张最终表；`scripts/rebuild_dev_database_contract.test.js` 验证开发库必须先备份再重建，并固定基础结构、JXC 结构和正式迁移的执行顺序；`scripts/nondefault_prefix_rebuild_integration.test.js` 在 3307 隔离测试库上以 `tenantx_` 实际重建、核验 99 张表与 26 条迁移历史，再恢复原测试库。
- 2026-08-05 扩展：`scripts/migrate_probe_metadata_contract.test.js` 在调用静态探针前逐份验证迁移源的占位符和非默认前缀转换；`scripts/migrate_probe_core.js` 的静态与受控运行时清单同步为 26 份迁移、185 条语句和 99 张最终表，并固定商品别名迁移的预处理后校验值。
- 架构防线：`scripts/lib/MigrationSqlPreprocessor.php` 是真实 PHP 迁移执行器进入拆分与 PDO 前的统一边界；`scripts/migrate_probe_core.js` 的静态与固定运行时路径共同调用其唯一 JavaScript 契约实现，不再按迁移文件名特判。前缀安全校验在数据库连接与迁移历史表名拼接前执行。
- 决策与知识：本记录；数据库前缀当前以 `config/database.php` 和运行环境 `DATABASE.PREFIX` 为准。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-29 | 非 `la_` 表归属与旧链路迁移调查 | phpMyAdmin 出现两个字面量 `{{prefix}}*` 表；只读数据库连接不可用，现场行数与迁移历史尚未核验 | 静态探针在测试内部硬编码替换，真实执行器没有同等预处理与残留占位符拒绝。 |
| 2026-07-30 | 客户报货新链路全量回归 | 两条测试数据库建表路径仍各自读取迁移并替换固定前缀 | 原防线只覆盖正式执行器与静态探针，没有把测试数据库消费者纳入统一预处理边界。 |
| 2026-07-30 | 最终双轴审查 | 基础结构 SQL 仍硬编码 `la_`，非默认前缀只有静态替换、不能实际从零重建 | 原防线只覆盖正式迁移和测试辅助器，遗漏重建脚本导入的 `like.sql`／JXC 基础结构。 |
| 2026-08-05 | 三仓未提交改动审查 | 商品别名迁移被改为硬编码 `la_`，而静态探针因受控清单仍为 25 份迁移提前退出 | 新增商品别名迁移时未同步静态与受控运行时的数量、语句、表数及哈希清单，源文件前缀断言位于探针调用之后。 |

旧链路代码和新建库结构已不再包含字面量占位符表。2026-07-30 已在受隔离的
本地开发实例上实际执行 `scripts/rebuild-dev-database.ps1 -ConfirmRebuild
-ExpectedDatabase lantu`：24 份迁移全部成功，`lantu` 包含 98 张表，且旧链路
表与字面量占位符表均为零。旧 XAMPP 数据目录在先生成并校验原始备份后已删除；
代码防线与现场开发库清理均已完成。

## PIT-0006：本地 MySQL 启动脚本把认证当作端口就绪探针

- 状态：已防护
- 首次发生：2026-07-30
- 最近发生：2026-07-30
- 复发次数：0
- 适用范围：`scripts/start-local-dev-mysql.ps1`、隔离开发 MySQL 初始化与账号配置
- 相关问题：无

### 触发场景

脚本以 `--initialize-insecure` 创建新的数据目录后，立即以 TCP `root` 登录作为
服务就绪判断。

### 根因

MySQL 初始账户仅为 `root@localhost`；经 `127.0.0.1` 的 TCP 登录属于不同主机项。
服务其实已启动时，认证仍会失败并让脚本持续等待或误判为启动失败。将账号授权与
端口存活混为一个检查，导致启动脚本依赖尚未完成的账号引导状态。

### 错误做法

以某个数据库账号通过 TCP 的登录成功作为本地 MySQL 是否已监听的唯一判断。

### 正确做法

启动阶段仅检查目标 TCP 端口可连接并检测 `mysqld` 是否提前退出；账号创建或授权
作为独立、显式的引导步骤完成，随后再用应用 `.env` 的只读连接确认可用性。

### 防线

- 自动化防线：`scripts/local_dev_mysql_contract.test.js` 断言启动脚本使用无认证的 TCP 就绪探针、保留提前退出诊断，并禁止重新引入 MySQL 客户端登录轮询。
- 架构防线：`start-local-dev-mysql.ps1` 只负责实例启动；账号配置不再隐式耦合到端口探针。
- 决策与知识：本记录；开发实例固定为 `.local/mysql/my-dev-lantu.ini` 的 3306 端口，PHPUnit 隔离实例继续使用 3307。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-30 | 客户报货旧链路删除与开发库重建 | 新隔离实例初始化后，启动脚本通过 TCP 登录 `root` 等待就绪 | 原脚本没有区分“服务器已监听”和“账号已获 TCP 授权”。 |

## PIT-0007：迁移动态 SQL 的结果集未释放

- 状态：已防护
- 首次发生：2026-07-30
- 最近发生：2026-07-30
- 复发次数：0
- 适用范围：`scripts/migrate.php` 与使用 MySQL `PREPARE`／`EXECUTE` 的 SQL 迁移
- 相关问题：PIT-0005

### 触发场景

迁移以动态 SQL 回退执行 `SELECT 1`，随后迁移执行器继续执行下一条 SQL。

### 根因

`PDO::exec()` 不会消耗动态 `EXECUTE` 产生的结果集；即使启用 PDO 缓冲查询，未释放的
结果集仍会让 MySQL 8 在下一条语句抛出 `SQLSTATE[HY000] 2014`。

### 错误做法

在迁移循环中对所有语句一律调用 `PDO::exec()`，并假定只有显式 `SELECT` 才会产生
需要关闭的结果集。

### 正确做法

所有迁移语句通过同一执行边界 `prepare()`／`execute()` 运行，逐行集消耗结果，最后
始终 `closeCursor()`；动态 SQL 的回退查询与 DDL 因而具有相同的资源释放语义。

### 防线

- 自动化防线：`scripts/migrate_executor_contract.test.js` 断言迁移循环只能调用统一执行边界，且该边界包含 `fetchAll()`、`nextRowset()` 与 `closeCursor()`；实际干净重建已覆盖含动态回退的 `20260602_000003_platform_goodscat_and_cloud_goods_category.sql`。
- 架构防线：`executeMigrationStatement()` 是 `scripts/migrate.php` 的唯一迁移语句执行边界，迁移历史记录仅在所有语句成功后写入。
- 决策与知识：本记录及 PIT-0005 的迁移预处理边界。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-30 | 客户报货旧链路删除与开发库重建 | 重建执行 `20260602_000003_platform_goodscat_and_cloud_goods_category.sql` 后继续下一条语句 | 原执行器仅缓冲连接级查询，未在每条动态 SQL 后消费并关闭结果集。 |

## PIT-0008：原始数据库恢复归档未排除在 Git 之外

- 状态：已防护
- 首次发生：2026-07-30
- 最近发生：2026-07-30
- 复发次数：0
- 适用范围：`storage/backups` 下的本地 MySQL 原始恢复归档
- 相关问题：无

### 触发场景

在仓库工作区内生成原始 MySQL 数据目录 ZIP，用于在损坏实例无法逻辑备份时保留恢复证据，
随后执行宽泛的 `git add .`。

### 根因

归档位于项目目录而 `.gitignore` 没有排除 `storage/backups/*.zip`；原始数据目录可能包含
业务数据、用户记录或认证元数据，因此无意提交会造成数据泄露风险。

### 错误做法

把原始数据库恢复归档当作普通项目产物留在未忽略目录中。

### 正确做法

恢复归档可本地保留以支持隔离灾难恢复，但必须由 Git 忽略规则排除；版本库只保留归档
路径、校验值和恢复说明，不保存归档内容。

### 防线

- 自动化防线：`scripts/backup_archive_ignore_contract.test.js` 通过 `git check-ignore` 验证原始恢复归档以及 `.env*`、`.local`、`vendor` 均始终被忽略。
- 架构防线：`/.gitignore` 在保留既有本地凭据、依赖和运行数据排除规则的基础上，额外排除 `/storage/backups/*.zip`；正常开发库备份继续存放在仓库外的 `.local/backups`。
- 决策与知识：本记录及 `.scratch/customer-report-new-workflow/数据库前缀与旧链路迁移审计报告.md` 中的归档 SHA-256。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-30 | 客户报货旧链路删除与开发库重建的最终审查 | 发现 `storage/backups/xampp-mysql-data-raw-20260730-1430.zip` 未被忽略 | 原重建安全边界覆盖备份生成和恢复，没有覆盖版本控制泄露风险。 |

## PIT-0009：宝塔切换站点目录后 open_basedir 仍指向旧发布目录

- 状态：防护中
- 首次发生：2026-07-31
- 最近发生：2026-07-31
- 复发次数：0
- 适用范围：宝塔站点目录从旧发布目录切换到新发布目录的 PHP-FPM 部署
- 相关问题：无

### 触发场景

将 `lantu.makesgoal.com` 的宝塔网站目录从 `server/public` 切换为
`server-next/public`，但保留已启用的“防跨站攻击（open_basedir）”。

### 根因

宝塔保留了旧目录的 `open_basedir` 白名单
`/www/wwwroot/lantu.makesgoal.com/server/:/tmp/`，PHP-FPM 因而拒绝读取新的
`server-next/public/index.php`。nginx 对外表现为 404，虽然站点根目录、入口文件和
迁移数据库均已正确。

### 错误做法

只修改宝塔的网站目录与运行目录后直接访问域名，未重新生成 `open_basedir` 白名单，或
仅因看到 nginx 404 就回滚代码／数据库。

### 正确做法

新发布目录准备完成后，先切换网站目录和 `/public` 运行目录；再关闭并立即重新开启
“防跨站攻击（open_basedir）”，使宝塔按新目录生成白名单。随后在服务器本机以 TLS
SNI 请求根路径和 `/index.php`，两者必须不再返回 404，才将发布目录视为可用。

### 防线

- 自动化防线：待在已确认的“服务器本机 HTTPS 响应码”公共接口上补充可复用部署校验脚本。
- 架构防线：发布目录采用 `server-next` 并保留旧 `server` 作为未激活回滚副本；不在原目录上覆盖上传，从而可把代码同步问题与站点运行环境问题分开验证。
- 决策与知识：当前服务器已通过
  `curl --resolve lantu.makesgoal.com:443:127.0.0.1 https://lantu.makesgoal.com/`
  验证根路径为 `302`，并验证 `/index.php` 为 `200`。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-31 | 客户报货新链路宝塔发布 | `server-next/public` 已存在且 nginx `root` 正确，站点仍返回 404 | 发布流程只验证了代码、依赖和数据库迁移，没有验证宝塔对新发布目录保留的 `open_basedir` 白名单。 |

## PIT-0010：数据库菜单组件路径未纳入静态平台前端发布契约

- 状态：防护中
- 首次发生：2026-07-31
- 最近发生：2026-07-31
- 复发次数：0
- 适用范围：`BeiMi-PHP` 的平台菜单迁移、`public/platform` 静态前端发布及其部署验收
- 相关问题：无

### 触发场景

在空库中导入基础结构并应用 24 份正式迁移后，平台超级管理员登录
`lantu.makesgoal.com`，打开“微信用户列表”“公共商品库”“商品归档列表”或“分类管理”。

### 根因

迁移向 `la_system_menu` 写入了 `tenant/wechat_user/index`、
`goods/cloud_goods/index`、`goods/cloud_goods/archive` 与 `goods/cate/index` 等组件路径，
但已发布的 `public/platform` 静态构建包不包含对应组件映射。前端在解析菜单时直接报
“找不到组件”，因此页面主体未挂载，且不会发起列表 API 请求。

### 错误做法

只以迁移数量、表数量、入口 HTTP 响应和登录成功作为发布验收通过条件，未验证迁移新增的
平台菜单组件是否能由实际发布的前端包解析。

### 正确做法

平台菜单迁移与平台前端必须作为同一发布单元：构建并部署包含对应页面组件的前端包；发布前
对迁移会激活的每个菜单组件路径执行前端组件清单校验，发布后以管理员登录态逐项验证页面
至少完成组件挂载和列表请求。

### 防线

- 自动化防线：待在获授权的 `tenant` 前端子项目中建立“活动菜单 `component` 字段必须存在于
  平台前端构建组件清单”的构建／发布契约测试，并将其加入发布校验。
- 架构防线：前端组件清单与后端菜单迁移必须具有可验证的显式契约；不允许仅依靠运行时
  `import.meta.glob` 失败后再由浏览器提示缺失组件。
- 决策与知识：2026-07-31 的生产浏览器控制台已确认四个路径均报“找不到组件”；同次网络
  记录显示 `mySelf`、`getConfig` 均为 `200`，但没有任何对应列表 API 请求。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-31 | 客户报货新链路宝塔发布 | 新库、迁移、PHP 入口及平台登录均成功后，迁移新增的菜单页面全为空白 | 发布流程没有把独立前端子项目的组件构建结果与数据库菜单配置进行匹配验证。 |

## PIT-0011：认证会话写入字段未同步到存量迁移和新租户模板

- 状态：已防护
- 首次发生：2026-07-31
- 最近发生：2026-07-31
- 复发次数：0
- 适用范围：`UserTokenService`、`la_user_session` 迁移与 `TenantCreatService` 的新租户建表
- 相关问题：PIT-0005、PIT-0007

### 触发场景

微信小程序用户首次为当前终端创建会话；`UserTokenService` 向 `la_user_session` 写入 `create_time`。

### 根因

认证会话写入代码在 2026-06-06 增加 `create_time`，但既有数据库没有补列迁移，
`app/platformapi/db/tenant.sql` 的新租户会话表也未同步该字段。ThinkPHP 严格字段校验因而在
写入前抛出 `fields not exists:[create_time]`。

### 错误做法

只修改会话写入代码，假定已有表或新租户模板会自动获得新增字段。

### 正确做法

为存量 `{{prefix}}user_session` 提供幂等补列迁移，并在同一变更中更新新租户的
`la_user_session_{tenantSn}` 建表模板；两者必须一起通过 schema 契约测试。

### 防线

- 自动化防线：`tests/unit/UserSessionSchemaContractTest.php` 断言迁移检查并补齐
  `{{prefix}}user_session.create_time`，同时断言新租户会话表模板包含该列。
- 架构防线：`database/migrations/20260731_000001_add_user_session_create_time.sql` 使用
  `information_schema` 条件补列，并通过 `scripts/migrate.php` 的统一迁移执行边界运行。
- 决策与知识：本记录；所有认证会话字段变更必须同时覆盖存量库迁移与租户初始化模板。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-31 | 微信小程序登录字段错误修复 | 终端会话首次创建 | 原有迁移防线只覆盖既有业务表，未建立认证会话字段与租户模板的一致性契约。 |

## PIT-0012：JXC 显式路由覆盖小程序既有用户信息入口

- 状态：防护中
- 首次发生：2026-08-01
- 最近发生：2026-08-01
- 复发次数：0
- 适用范围：`app/api/route/jxc.php`、小程序 `GET /api/user/info` 与 JXC 新用户入店流程
- 相关问题：无

### 触发场景

在 JXC 新用户入店白名单中注册 `Route::get('user/info', 'jxc.Auth/info')`，随后小程序微信登录成功后按既有协议请求 `GET /api/user/info`。

### 根因

JXC 路由与既有小程序个人信息路由使用了相同的 HTTP 方法和路径。显式 JXC 路由优先命中 `app\api\controller\jxc\AuthController`，而不是常规 `UserController::info()`。新小程序 token 的 `tenant_id=0` 在 `enforce-onboarding` 模式下不会建立 JXC 管理员身份，因此 JXC `AuthController::info()` 返回“登录超时，请重新登录”。

### 错误做法

在共享 `/api` 命名空间中为 JXC 新入口添加未加前缀的 `user/info` 路由，并假定它只会被 JXC 客户端调用。

### 正确做法

保留 `/api/user/info` 给常规用户控制器，并显式绑定常规 `LoginMiddleware`，确保 token 解析结果注入 `userId`；JXC 身份信息必须使用独立且不冲突的路径，并同步更新对应 JXC 客户端调用。

### 防线

- 自动化防线：待补充路由契约测试，断言 `GET /api/user/info` 解析到携带常规 `LoginMiddleware` 的 `UserController::info()`，且 JXC 路由不得占用既有小程序入口。
- 架构防线：JXC 专用接口使用显式 `jxc/` 路径前缀，避免与用户端历史路由共享同一命名空间。
- 决策与知识：本记录；生产复现命令为携带有效小程序 token 的 `GET /api/user/info?trace=...`。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-01 | 微信小程序登录故障诊断 | `mnpLogin` 成功后 `GET /api/user/info` 返回 `code=-1` | 没有覆盖路由冲突与目标控制器的契约测试。 |

## PIT-0013：Codex 运行快照与压缩归档被纳入 PHP Git 历史

- 状态：防护中
- 首次发生：2026-08-02
- 最近发生：2026-08-02
- 复发次数：0
- 适用范围：`BeiMi-PHP` 工作区的运行快照、压缩归档与 Git 暂存／推送入口
- 相关问题：PIT-0008

### 触发场景

在 PHP 仓库工作区内生成 `.codex/retirement-snapshots/**` 运行快照或 `BeiMi-PHP.rar`，
随后执行宽泛的 `git add .` 并推送包含这些文件的本地提交。

### 根因

`.gitignore` 只排除了 `storage/backups/*.zip`，没有排除 `.codex` 运行快照或 RAR
归档；仓库也没有检查 Git 暂存区中文件类别及大小的自动化防线。结果是一个 227 MB
的 ZIP 快照和一个 88 MB 的 RAR 被纳入历史，前者超过 GitHub 100 MB 单文件上限，
并导致首次推送约 371 MiB 对象时 HTTPS 上传中断。

### 错误做法

将运行快照和本地压缩归档保留在未忽略的工作区路径中，并把 GitHub 推送阶段当作
大文件提交的唯一检查。

### 正确做法

运行快照与归档必须存放在版本控制外，或由 `.gitignore` 明确排除；提交前应自动拒绝
暂存区中的 `.codex` 运行产物、压缩归档和接近 GitHub 限制的常规文件。

### 防线

- 自动化防线：待在用户确认的公共 CLI 入口 `node scripts/git_artifact_guard.js` 上先建立红—绿测试；随后由版本化 Git Hook 调用该入口，检查暂存文件路径、归档扩展名及低于 GitHub 100 MB 的安全阈值。
- 架构防线：运行快照与恢复归档应使用仓库外或已忽略的本地目录，业务源码目录不保存此类二进制产物。
- 决策与知识：本记录、`.gitignore` 与 PIT-0008；2026-08-02 已通过重写历史删除 227 MB ZIP 与 88 MB RAR 并恢复远程推送。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-02 | PHP Git 远程推送恢复 | 历史包含 227 MB `.codex/retirement-snapshots/**.zip` 与 88 MB `BeiMi-PHP.rar` | 既有忽略规则仅覆盖数据库恢复 ZIP，且没有暂存区大文件拒绝检查。 |

## PIT-0014：商品别名匹配前保留了尾随报货状态词

- 状态：已防护
- 首次发生：2026-08-08
- 最近发生：2026-08-08
- 复发次数：0
- 适用范围：`CustomerReportCandidateLogic::goodsNeedle()` 与 `/jxc/customer_report/recognize`
- 相关问题：PIT-0015

### 触发场景

报货原文以“商品别名 + 数量 + 活的/鲜活/活鲜”描述商品，例如“鳜鱼15条活的”。

### 根因

解析器会移除数量，但会保留尾随的商品状态词，使查询键从配置的别名“鳜鱼”变成“鳜鱼活的”；别名唯一约束使用规范化后的完整匹配，因此无法选中标准商品“桂鱼”。

### 错误做法

仅删除数量和加工方式，并把剩余文本直接作为商品名称或别名查询键。

### 正确做法

在商品查询键规范化前移除已知的尾随状态词；别名查询始终使用剥离数量、加工方式和状态词后的商品名称。

### 防线

- 自动化防线：`tests/unit/GoodsAliasRecognitionTest.php::test_customer_report_uses_the_standalone_customer_header_and_matches_an_alias_with_a_live_condition()` 覆盖“大学”报货头与“鳜鱼15条活的”从别名到标准商品“桂鱼”的完整识别结果。
- 架构防线：`CustomerReportCandidateLogic::goodsNeedle()` 是报货识别进入商品名称、编码和别名查询前的唯一文本清洗边界。
- 决策与知识：`CONTEXT.md` 中“商品别名”与“报货识别校对”的定义。
- 验证结果：2026-08-08 已启动隔离 MySQL `127.0.0.1:3307`，上述完整别名识别测试通过（3 个用例、26 条断言）；`CustomerReportWorkflowTest` 通过（20 个用例、191 条断言），`CustomerReportRouteContractTest` 通过（4 个用例、52 条断言）。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-08 | 客户报货识别修复 | “大学\\n鳜鱼15条活的”未命中配置为“桂鱼”的别名“鳜鱼” | 既有别名测试只覆盖纯别名输入，未覆盖数量后的尾随状态词。 |

## PIT-0015：未匹配的首行客户标题退化为商品行

- 状态：已防护
- 首次发生：2026-08-08
- 最近发生：2026-08-08
- 复发次数：0
- 适用范围：`CustomerReportCandidateLogic::firstLineHeader()` 与 `/jxc/customer_report/recognize`
- 相关问题：PIT-0014

### 触发场景

多行报货原文的第一行是无数量的客户简称或尚未建档客户，例如“大学”；后续行包含明确的商品数量。

### 根因

旧实现只在首行精确选中客户时返回客户标题，并用 `null` 同时表达“不是客户标题”和“客户标题尚未匹配”。后者因此继续进入逐行商品解析，被错误标记为无商品候选。

### 错误做法

用客户是否已精确选中来判断首行的业务角色；客户未匹配时直接把原文交给商品解析。

### 正确做法

先根据结构确定首行业务角色：首行无数量、没有商品候选，且后续存在数量行时，将其保留为 `customer_header`。客户唯一匹配时应用到商品行；无匹配或歧义时保留客户候选状态和恢复路径，不能退化为商品行。

### 防线

- 自动化防线：`tests/unit/CustomerReportWorkflowTest.php` 覆盖未匹配客户标题、唯一模糊客户标题、精确客户标题，以及首行真实商品不得被误判四类场景。
- 架构防线：`CustomerReportCandidateLogic::firstLineHeader()` 独立返回客户标题记录，标题角色与客户是否已选中不再共用 `null`。
- 决策与知识：`CONTEXT.md` 中“报货识别校对”和“待处理识别项”的定义。
- 验证结果：2026-08-08 红灯稳定复现 `header=null`；修复后 `CustomerReportWorkflowTest` 通过（20 个用例、191 条断言），`GoodsAliasRecognitionTest` 通过（3 个用例、26 条断言），`CustomerReportRouteContractTest` 通过（4 个用例、52 条断言）。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-08 | 客户报货首行识别复发修复 | 生产截图中“大学”仍显示为无商品候选行 | PIT-0014 只覆盖了精确客户标题与商品别名尾词，没有覆盖客户未匹配或仅模糊匹配时的首行业务角色。 |

## PIT-0016：JXC 路由检查把内部控制器误当作公开适配器

- 状态：已防护
- 首次发生：2026-08-11
- 最近发生：2026-08-11
- 复发次数：0
- 适用范围：`app/api/route/jxc.php`、JXC 控制器适配器与路由完整性检查
- 相关问题：PIT-0009

### 触发场景

新增 `/api/jxc/tasks/*` 与 `/api/jxc/workforce/*` 路由及内部控制器后，静态检查全部通过；生产请求却返回 404。绕过 Nginx 后，ThinkPHP 明确报告不存在 `\app\api\controller\jxc\FulfillmentTaskController` 与 `\app\api\controller\jxc\WorkforceController`。

### 根因

`jxc.Name/method` 路由由 ThinkPHP 解析到公开入口 `app/api/controller/jxc/NameController.php`。新功能只创建了 `app/api/jxc/controller` 下的内部控制器，没有像既有 JXC 模块一样增加公开薄适配器；`scripts/ci-check.ps1` 又错误检查内部目录，因此把不可达路由误报为完整。

### 错误做法

只要 `app/api/jxc/controller/NameController.php` 存在就认为 `jxc.Name/method` 可路由，或只检查路由字符串和内部实现文件而不验证框架实际解析的类名。

### 正确做法

内部控制器继续放在 `app/api/jxc/controller`；每个被 `jxc.Name/method` 公开引用的控制器必须在 `app/api/controller/jxc` 提供同名适配器，并使用 `app\api\controller\jxc` 命名空间。路由完整性检查必须按这一公开解析目录验证文件、命名空间和类名。

### 防线

- 自动化防线：`scripts/route_controller_contract.test.js` 解析全部 `jxc.Name/method` 路由并验证对应公开适配器文件、命名空间和类名；`scripts/ci-check.ps1` 的既有路由检查同步改为公开适配器目录。
- 架构防线：`app/api/controller/jxc` 只承担 ThinkPHP 路由适配，业务实现仍复用 `app/api/jxc/controller`，不复制控制器逻辑。
- 决策与知识：本记录；生产路由是否可达以未携带 token 时返回应用 JSON 为最小验收信号。
- 验证结果：修复前路由控制器契约稳定报告缺少两个适配器；修复后通过并覆盖 17 个 JXC 控制器，迁移核心契约同时通过。2026-08-11 生产环境上传适配器、清理缓存并重启 PHP-FPM 后，任务看板与员工权限接口均返回 HTTP 200、`application/json` 和“请求参数缺token”，原 404 不再复现。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-11 | 任务看板与员工系统宝塔发布 | 路由与内部控制器已上传，但任务和员工接口返回“控制器不存在” | 既有 CI 路由检查使用了错误的内部目录，未验证 ThinkPHP 实际解析的公开适配器。 |
