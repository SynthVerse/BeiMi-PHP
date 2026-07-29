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
