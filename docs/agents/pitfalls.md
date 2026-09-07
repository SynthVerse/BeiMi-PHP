# 踩坑索引

按根因去重。每条记录必须指向实际防线；仅有“不要这样做”的提醒不算已防护。

## PIT-0034：真实交易更正后丢失渠道判重身份

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：财务实际收付与同交易更正
- 相关问题：无

### 触发场景

银行到账误选现金，关联更正为银行后再次按同交易号登记。

### 根因

判重键含渠道，但更正只沿用交易ID、不保留新渠道身份，导致已有事实换一个键后被当作新交易。

### 错误做法

只检查最初交易行，或覆盖旧键而释放原身份。

### 正确做法

`finance_transaction_identity` 追加并永久保留新旧渠道别名，唯一键与锁定读取保证归属同一真实交易。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_correction_registers_new_channel_identity_and_preserves_old_alias`；修复前真实路径错误允许第二次登记，修复后新旧渠道均拒绝。
- 已验证：专项回归通过；规格定向复核 resolved。
- 尚未验证：生产跨店并发负载；不将本地验证冒充压测。
- 后续建议：新增渠道仍使用同一身份登记边界。
- 本次来源：`Matt Pocock / implement`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / tdd`、`Matt Pocock / prevent-repeat-pitfalls`；审查发现后保留原开发步骤，建立失败用例并修复，随后返回财务一期实现。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 财务收付款 | 同交易更正账户渠道 | 原判重测试没有改变渠道。 |

## PIT-0035：同一次核销的两侧余额落在不同月份

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：预收抵扣与后续资金认领
- 相关问题：PIT-0032（时点含义不同）

### 触发场景

上月预收抵扣本月应收，上月尚未结账。

### 根因

应收采用较晚的核销生效日，预收却按到账月份一次汇总消耗，两侧期间不一致。

### 错误做法

先按来源到账月减少全部预收，再分别确定应收核销月份。

### 正确做法

逐项把已确认核销的业务日期、生效日和入账月用于对应预收消耗，并保留核销明细关联。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_advance_consumption_and_receivable_allocation_use_same_effective_period`；修复前实际得到8月与9月错配，修复后两侧均为9月。
- 已验证：专项通过，规格复核 resolved。
- 尚未验证：完整月报快照尚在后续开发范围。
- 后续建议：待认领核销沿用两侧期间配对，不重复实现日期推导。
- 本次来源：与 PIT-0034 相同；使用实际失败用例防止期间含义混淆复发。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 预收抵扣 | 不同业务月份的预收和应收 | 原金额守恒测试仅覆盖同月。 |

## PIT-0036：敏感凭证复用公共上传或缓存存储

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：工资与其他财务核验图片
- 相关问题：无

### 触发场景

工资查看权限被撤销后继续访问原图，或日常运行框架清缓存命令。

### 根因

公共静态图片绕过控制器授权；把私密图片迁入 `runtime` 又使长期证据落入框架默认递归清理范围。

### 错误做法

仅隐藏素材列表，返回永久公共URL；或把“非公开目录”等同“持久目录”。

### 正确做法

凭证存于非公开、非缓存的 `storage/finance-evidence`，元数据仅暴露图片ID，每次内容读取重新检查身份、门店及对应业务权限，工资继续独立授权。原图须与数据库一起纳入业务备份。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_salary_evidence_is_private_and_each_read_rechecks_identity_store_and_permission`；覆盖匿名、跨店、撤权、无公开URL及实际存储边界。去掉读取授权的临时缺陷变体稳定失败于 revoked，正式实现通过。
- 前端防线：`scripts/finance-business.test.mjs` 验证每次预览调用鉴权API，拒权后不回退旧图片链接。
- 已验证：授权与存储边界测试通过；标准复核已确认原公共URL绕过消除。
- 尚未验证：生产存储权限、备份恢复和微信真机预览；正式部署验收仍须完成。
- 后续建议：敏感附件统一复用私密读取边界，不借用公开素材上传。
- 本次来源：与 PIT-0034 相同；对审查确认的可重复泄露路径建立代码及测试防线后返回原任务。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 工资付款凭证 | 撤权后旧URL仍可读；私密迁移初版落入runtime | 原测试仅校验单据读取，没有校验原图读取和长期存储范围。 |

## PIT-0037：重复登记纠错强制保留正数替代交易

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：实际收付款的误录重复处理
- 相关问题：PIT-0034

### 触发场景

无外部交易号的一笔收款被误登记两次，需要消除多余影响。

### 根因

关联更正仅支持正数替代收付款，缺少以保留真实记录为依据的完整反向路径。

### 错误做法

把更正金额填零套入实际收付，或用真实退款减少误录资金。

### 正确做法

专用重复反向动作关联同门店仍有效、同业务类型及账户金额日期的保留记录，只追加反向账务影响，保留原始登记和核验理由，不新增退款事实。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_duplicate_reversal_keeps_one_transaction_effect_and_does_not_create_refund`；实现前缺少动作而失败，修复后保持一份有效金额且没有新增资金交易。
- 已验证：专项通过，规格定向复核 resolved。
- 尚未验证：生产重复样本迁移和真机操作。
- 后续建议：真实退票继续走独立资金业务，不能借用内部重复反向。
- 本次来源：与 PIT-0034 相同；补齐实际业务纠错类型，避免万能余额修改。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 原交易更正 | 重复记录须完整消除影响 | 原测试仅覆盖3000更正为1000，没有覆盖多录一份记录。 |

## PIT-0032：期初截点数量被实时库存替代

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：期初库存承接与历史截点核对
- 相关问题：无

### 触发场景

启用日前有10单位库存，启用后出库至8或售罄，再补录期初。

### 根因

校验直接采用实时 `on_hand_qty`，并只枚举当前非零库存，混淆历史截点与查询时点。

### 错误做法

要求用户把已核实的历史数量改成当前量，或把当前售罄等同截点无库存。

### 正确做法

回退启用截点之后实际库存流水的净变动，对全部库存余额记录计算 `cutoff_qty`；按截点数量核对、承接成本，不重复入库。

### 防线

- 自动化防线：`tests/unit/FinanceOpeningWorkflowTest.php` 的 `test_inventory_cutoff_reverses_later_movements_and_does_not_omit_sold_out_sku` 覆盖余8、售罄0、错误填当前量与正确期初确认。
- 已验证：隔离进程加载“改回实时量”的临时缺陷变体时用例失败；正式实现通过。期初专项30项、489断言通过，规格复核 resolved。
- 尚未验证：真实门店旧流水的迁移完整性；正式启用仍受迁移验收门禁控制。
- 后续建议：其他时点报表复用明确截点与来源净变动的计算方式。
- 本次来源：`Matt Pocock / implement`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / tdd`、`Matt Pocock / prevent-repeat-pitfalls`；工程审查发现可复发根因后建立防线留档，随后返回财务一期开发。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 完整期初承接 | 启用后发生库存变动再核对期初 | 原测试只有截点后无变动的数量样本。 |

## PIT-0033：确认快照锁未覆盖权威库存写入

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：期初确认与库存变动并发
- 相关问题：PIT-0022（事务快照可见性，根因不同）

### 触发场景

期初确认读取库存之后，另一事务对已有或新建库存余额执行入出库。

### 根因

确认只锁准备资料行，与权威库存写入口的SKU、仓库和余额锁不相交；普通读取还可能沿用较早的一致性快照。

### 错误做法

把对准备资料的事务锁或旧快照哈希校验当成对库存事实的并发保护。

### 正确做法

确认先锁定门店SKU和仓库范围及库存余额，再用锁定当前读核对库存和流水，保护已有SKU新增余额的情况。

### 防线

- 自动化防线：`FinanceOpeningWorkflowTest::test_opening_stock_lock_blocks_the_shared_sku_lock_even_before_a_balance_exists` 使用两个独立PDO连接，验证持锁期间另一个连接取得1205、释放后成功。
- 已验证：隔离进程加载“移除共享锁”的临时缺陷变体时测试失败；正式实现通过，标准复核 resolved。
- 尚未验证：生产并发量下的锁等待性能；当前不宣称已完成生产压测。
- 后续建议：其他确认快照明确列出共享写入锁，避免仅对草稿加锁。
- 本次来源：`Matt Pocock / implement`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / tdd`、`Matt Pocock / prevent-repeat-pitfalls`；工程审查发现可复发根因后建立自动化防线，随后返回财务一期开发。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 完整期初承接 | 确认与库存写入交错 | 原单连接测试无法证明不同模块使用同一把事务锁。 |

## PIT-0031：跨身份表的同号 ID 进入员工权限查询

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：财务启用准备 API 的小程序员工权限与后台身份边界
- 相关问题：PIT-0020（不同根因：该项是正确用户令牌被拒绝）

### 触发场景

非 root 后台管理员 ID 恰好与本门店获授权的小程序员工用户 ID 相同，访问财务准备接口。

### 根因

调用通用员工权限查询前只传递数字身份，未先确认 `jxcFromUserToken`。后台 `adminId` 被当成 `userId`，导致两张独立身份表发生碰撞。最高权限分支区分身份类型，并不能保护其后的员工权限回退。

### 错误做法

把后台管理员 ID 与小程序用户 ID 视为同一身份空间，或以拥有全部可分配权限推导最高权限。

### 正确做法

财务接口先辨别由认证中间件注入的身份类型。只有小程序用户身份进入员工权限分支，后台身份仅按当前门店 root 校验；客户端参数不能选择身份类型。

### 防线

- 自动化防线：`tests/unit/FinanceSetupWorkflowTest.php::test_nonroot_backend_identity_cannot_inherit_a_same_number_employee_permission`；修复前返回准备资料，修复后拒绝并隐藏能力。
- 架构防线：`FinanceSetupLogic::authorize` 和工作台能力输出共用 `isUserIdentity` 限制。
- 决策与知识：本记录。通用 `WorkforceLogic` 其他调用处不属于本批修复范围，未宣称全仓同类风险已消除。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 财务首批开发 / code-review | 新准备接口复用员工授权 | 原测试未构造后台身份与小程序员工同号；此次经 `diagnosing-bugs` 复现，`prevent-repeat-pitfalls` 建立公开接口回归，随后返回 `implement` 验证。 |

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

- 状态：复发
- 首次发生：2026-07-29
- 最近发生：2026-08-18
- 复发次数：1
- 适用范围：新增表的 SQL 迁移、`CustomerReportTestSupport` 共享 schema 与 PHPUnit 集成测试
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
- 2026-08-18 扩展：`CustomerReportTestSupport` 不再手抄 `goods_supplier` 最终结构；它从 `database/sql/jxc_phase1_schema.sql` 抽取权威基线建表，并通过生产同款 `MigrationSqlPreprocessor` 连续执行 `20260603_000002_aquatic_goods_v1.sql` 中供应商/SKU 相关迁移段。`WarehouseSkuBalanceServiceTest` 实际运行同商品多 SKU、跨租户 SKU 与基准 SKU 三类隔离行为。
- 架构防线：生产迁移仍只做建表，不对无历史数据项目的旧库存进行回填或猜测性修复。
- 决策与知识：本记录及 `docs/adr/0001-客户报货库存边界.md`。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-29 | Project #1 / 仓库级库存余额与统一库存原语 | 首次执行余额服务测试 | 测试库保留了开发初期临时表，但测试未重建目标 schema。 |
| 2026-08-18 | BeiMi-PHP#6 | SKU 报货回归调用商品维度保存 | 共享测试 schema 只截取水产迁移前 5 条语句，遗漏其后才使用的 `goods_supplier.sku_id` 最终结构。 |

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
- 最近发生：2026-08-19
- 复发次数：6
- 适用范围：`CustomerReportLogic`、`FulfillmentChangeLogic`、`FulfillmentTaskLogic`、`DeliveryInventoryLogic`、`DeliveryVariantLogic`、`LineVehicleLogic`、`NegativeInventoryLogic`、`SalesSettlementLogic`、`FinanceService` 等已开启业务事务后调用库存、财务原语或写入幂等事实的路径
- 相关问题：PIT-0022

### 触发场景

两个独立客户报货请求同时对同一租户、仓库和商品提交预留；外层报货事务内调用会自行 `Db::transaction()` 的库存服务方法，或使用会隐式开启事务的 `Model::create()`。

### 根因

ThinkPHP 在嵌套事务中依赖保存点。仓库余额服务的独立事务入口，以及 `CustomerReport` 模型的 `create()` 隐式事务，都可能在外层客户报货事务中创建内层保存点；并发路径会丢失内层保存点并抛出 `SAVEPOINT trans2 does not exist`。修正保存点问题后，两个请求又会在“不存在的幂等键”或“不存在的客户商品偏好键”的悲观查询上互相持有间隙锁，插入不同键或同一偏好键时触发 MySQL `1213` 死锁。即使唯一动作最终提交，竞争请求仍可能从事务旧快照返回动作前状态；因此“唯一键没有重复”不等于调用者已经得到可重放的权威结果。

### 错误做法

在已开启的业务事务中调用 `WarehouseGoodsBalanceService::reserveUpTo()`、`reserve()`、`release()` 或 `consumeReserved()` 等会自行开启事务的入口；或调用会自行管理事务的 ORM 创建接口；或对尚不存在的幂等键、客户商品偏好键做悲观锁查询后插入。

### 正确做法

外层业务事务应调用库存服务的 `*WithinTransaction` 同事务入口，并通过 `Db::name(...)->insert()` 写入报货主从表；只有没有外层事务的调用者才使用服务的独立事务入口。多商品操作必须以库存原语的实际取锁顺序排序。幂等键先以普通查询判断，依靠唯一键兜底；客户商品偏好以唯一键上的原子 upsert 写入，不先锁不存在记录；写状态转换先锁定稳定来源实体，再复查幂等事实，对 MySQL `1213`/`1205` 做有界重试，并在事务结束后按请求指纹读取已提交的追加动作。只有追加动作已经可见时才能向调用者返回成功。

### 防线

- 2026-07-30 扩展：跨仓转换在创建任何销售单前，按 `goods_id` 升序预锁本次涉及的全部商品；销售单、订单商品、库存流水和应收／应付在外层事务中统一使用 Query Builder 写入，禁止重新引入 ORM 隐式事务。
- 自动化防线扩展：`tests/unit/CustomerReportWorkflowTest.php` 的多仓后置计价失败用例断言销售单、库存流水、应收及报货状态整体回滚；`tests/unit/CustomerReportRouteContractTest.php` 禁止外层事务路径重新引入 `Model::create()`，并断言商品预锁早于任何标准销售单发布。
- 自动化防线：`tests/unit/CustomerReportWorkflowTest.php` 的 `test_two_concurrent_submissions_cannot_over_reserve_one_warehouse_balance()` 使用两个 PHP 进程同时提交，断言一单 `submitted_ready`、一单 `submitted_shortage`，且总预留不超过余额。
- 2026-08-18 扩展：`FulfillmentChangeLogic` 对不存在的履约变更幂等键只做普通查询，依靠 `(tenant_id,idempotency_key)` 唯一键解决竞态；减量和未交货事务统一只对 MySQL `1213`/`1205` 做最多三次有界重试。`CustomerReportRouteContractTest::test_fulfillment_change_idempotency_never_locks_an_absent_key_and_retries_deadlocks()` 固定这一结构契约，并要求作废控制按 `item_change_id` 精确关联幂等事实；`FulfillmentWorkflowTest::test_concurrent_reduction_with_one_idempotency_key_applies_inventory_once()` 用两个 PHP 进程证明同一减量请求只生成一条变更事实且库存只释放一次。
- 2026-08-19 扩展：交付确认和负库存处理都先普通查询幂等键，交付明细按 `(sku_id, goods_id, warehouse_id, id)` 排序；负库存处理按 SKU、商品、仓库、余额、来源归因的统一顺序取锁，锁后复查幂等事实。余额缺行时只用 Query Builder 插入，事务拥有者只对 MySQL `1213`/`1205` 最多重试三次；事务结束后由 `replayAfterConcurrentCommit()` 按请求指纹读取已提交事实。`FulfillmentWorkflowTest::test_concurrent_same_delivery_key_returns_one_event_and_one_inventory_side_effect()`、`test_two_reports_can_concurrently_create_one_missing_sku_balance_in_canonical_order()`、`test_concurrent_same_negative_resolution_key_appends_one_action()` 与 `test_concurrent_regular_inbound_and_negative_resolution_share_one_lock_order()` 使用真实 PHP 进程固定一次交付只出库一次、无余额并发建账、一次负库存处理只追加一个动作以及普通入库/人工处理共享锁序；`CustomerReportRouteContractTest::test_delivery_and_negative_actions_use_canonical_locks_then_replay_the_committed_idempotent_fact()` 固定结构边界。
- 2026-08-19 Ticket #10 扩展：变体交付与改派返回门店在取得稳定业务锁后仍只普通查询幂等事实，唯一键竞争异常统一在事务结束后按指纹重放；多商品变体交付按 `(sku_id, goods_id, warehouse_id, id)` 获取库存锁。新建趟次改为先锁报货单再普通查询活动分配，不再锁不存在的活动行间隙。`CustomerReportRouteContractTest::test_delivery_variant_and_return_actions_do_not_lock_absent_idempotency_keys()` 固定这些结构边界，双进程交付与改派竞态行为测试固定真实结果。
- 2026-08-19 Ticket #11 扩展：销售结算事务改用 `FinanceService::addReceivableWithinTransaction()` / `reduceReceivableWithinTransaction()`，两个原语只以 Query Builder 锁定并更新客户、追加应收流水，不开启内层事务或调用 ORM 模型。`CustomerReportRouteContractTest::test_sales_settlement_uses_query_builder_receivable_primitives_inside_its_transaction()` 固定结算入口不得退回普通财务方法，并检查同事务原语不含 `Customer::` 或 `Db::transaction()`。
- 2026-08-19 Ticket #11 锁序扩展：实际交付重量减少时仍先持有余额锁，再按 `(occurred_time,id)` 全局 FIFO 对全部负库存来源执行 `FOR UPDATE`；本单／本行优先级只能在来源全部锁定后于内存排序。`CustomerReportRouteContractTest::test_directed_sales_correction_locks_negative_sources_in_global_fifo_order_before_prioritizing_allocation()` 固定这一顺序，避免定向冲销把优先级下推到 SQL 后与普通入库形成反向锁序。
- 2026-08-19 BeiMi-PHP #11 接线扩展：`FulfillmentTaskLogic::bill()` 保持 report→bookkeeping task 锁序，并只对 MySQL `1213`/`1205` 最多重试三次。`FulfillmentWorkflowTest::test_bill_retries_one_recoverable_task_lock_timeout_without_duplicate_side_effects()` 用第二连接持有记账任务锁 1.8 秒，断言主连接以 1 秒锁等待上限、在剩余持锁时间大于 1 秒时进入并跨过首次超时后成功；worker 设有有界终止和临时文件清理。
- 架构防线：`WarehouseGoodsBalanceService` 明确提供 `reserveWithinTransaction()`、`reserveUpToWithinTransaction()`、`releaseWithinTransaction()` 与 `consumeReservedWithinTransaction()`；`CustomerReportLogic` 在外层事务内只调用这些入口，并以 Query Builder 写入主表、明细和预留记录。`CustomerReportPreferenceService::remember()` 用唯一键原子 upsert 保存建议数据，不加间隙锁。`transactionWithRetry()` 统一包裹提交外的编辑、补预留、取消与转换销售事务；明细按 `(goods_id, warehouse_id)` 排序后才触发库存原语。
- 决策与知识：本记录及 `docs/adr/0001-客户报货库存边界.md`。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-07-29 | Project #1 / 客户报货新链路 | 第 7 张并发提交验收 | 既有实现未区分外层事务与 ORM 隐式事务，也对不存在的幂等键和客户商品偏好键加悲观锁。 |
| 2026-07-30 | 客户报货旧链路删除与标准销售单桥接 | 外层报货转换调用标准销售发布时重新使用 ORM `create()`，并按仓循环触发库存锁 | 原防线只覆盖报货主从表和直接库存原语，没有覆盖新接入的销售、库存流水、财务副作用，也没有对跨仓转换建立“先预锁全部商品”的结构契约。 |
| 2026-08-18 | BeiMi-PHP #7 履约工票闭环 | 履约减量与未交货事务再次对尚不存在的幂等键执行 `FOR UPDATE`，并缺少 `1213`/`1205` 有界重试 | 原防线只约束 `CustomerReportLogic` 的提交、编辑、补预留、取消和转销售路径，未把新增的追加式履约变更入口纳入静态结构契约。 |
| 2026-08-19 | BeiMi-PHP #8 自配送交付与真负库存 | 同交付键/同处理键竞争会读到旧快照；无余额交付重新使用 ORM `create()`；人工核销与普通入库形成来源→余额/余额→来源反向锁序 | 原防线已覆盖报货与履约减量，但没有把新增交付、负库存处理、余额缺行和普通入库交叉流程纳入统一结构与双进程防线，也没有要求成功返回必须来自事务后可见的追加事实。 |
| 2026-08-19 | BeiMi-PHP #10 第三方与部分交付 | 变体交付和返回门店在稳定业务锁后重新对缺失幂等键执行 `FOR UPDATE`，返回门店唯一键竞争异常也没有事务后重放 | #8 防线只覆盖旧 `DeliveryInventoryLogic` 和负库存入口，新增写入口没有自动继承“缺失键普通查询 + 唯一键兜底 + 事务后重放”的结构契约。 |
| 2026-08-19 | BeiMi-PHP #11 销售结算与版本快照 | 结算外层事务直接调用使用 ORM 锁定/更新客户的普通应收方法 | 原结构契约只枚举报货、履约、交付和负库存事务入口，没有要求新增结算模块必须使用显式 `*WithinTransaction` 财务原语。 |
| 2026-08-19 | BeiMi-PHP #11 → BeiMi-ERP #11 接口接线 | 记账完成新增 report→task 写事务，但首次实现遇到 `1213`/`1205` 直接失败 | 原防线覆盖了交付、结算与财务写入口，却没有把新迁移职责后的 `FulfillmentTaskLogic::bill()` 纳入有界重试结构与可恢复锁等待行为测试。 |

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
- 2026-08-19 扩展：固定线车趟次迁移加入时，静态探针先以 `migration_count_mismatch` 阻断了未同步清单；同一变更同步静态与受控运行时的迁移数、语句数、最终表数和哈希清单，并由元数据契约固定为 39 份迁移、382 条语句和 121 张最终表。
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

- 状态：已防护
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

## PIT-0017：SKU 组合状态与运营可售状态共用同一组字段

- 状态：已防护
- 首次发生：2026-08-11
- 最近发生：2026-08-11
- 复发次数：0
- 适用范围：`GoodsDimensionLogic::syncSkus()`、商品维度组合维护与 SKU 人工停用
- 相关问题：无

### 触发场景

运营人员通过 `GoodsSkuLogic::status()` 停用一个 SKU，随后只保存或重排商品维度。该 SKU 仍在有效组合中，却被维度同步逻辑自动恢复采购和销售。

### 根因

维度同步曾把 `status`、`purchase_status`、`sale_status` 同时当作“组合是否启用”和“运营是否可采购/销售”的唯一状态来源。为了让因组合移除而保留的历史 SKU 能在重新勾选后恢复，代码对所有既有组合无条件写回三个启用状态；测试也把这一副作用误写成了正确预期，因此没有守住人工停用语义。

### 错误做法

根据 SKU 是否出现在本次维度组合中，直接覆盖运营状态；或者只断言重新勾选组合会恢复，而不覆盖“未移除组合的人工停用必须保持”这一对照场景。

### 正确做法

组合生命周期与运营状态必须分开记录。组合移除但存在业务引用时，先把原三个运营状态编码到 `dimension_disabled_snapshot`，再将 SKU 置为不可用；只有该快照存在且组合被重新勾选时才恢复原状态。快照为 `0` 的既有 SKU 由运营状态控制，普通维度保存不得改写。

### 防线

- 自动化防线：`tests/unit/GoodsDimensionLogicTest.php` 同时覆盖“重排维度保持人工停用”“组合移除后所有旧写接口不能激活或删除”“legacy 转 generic 正确处置手工 SKU”“报货拒绝已移除组合”和“历史 SKU 经重复保存后重新加入仍恢复原状态”；`GoodsDimensionLogicTest` 当前 25 个用例、153 条断言通过。
- 架构防线：`goods_sku.dimension_disabled_snapshot` 是组合移除状态的唯一标记；`GoodsSkuLogic::status()` 只维护运营状态且不能激活已移除组合；`dimension_mode=generic` 时旧 SKU 保存、笛卡尔生成、品质写入和规格写入均拒绝，只保留兼容读取；`GoodsSkuReferenceService` 为通用维度和全部旧 SKU 删除入口提供统一的业务引用保护。
- 决策与知识：本记录；新增任何 SKU 状态转换时必须明确其所有者是“组合配置”还是“运营控制”。
- 验证结果：修复前审查稳定复现保存维度后人工停用被恢复；修复后相关单元测试通过，迁移静态探针通过（32 个迁移、228 条语句、106 张最终表）。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-11 | 商品自定义维度与报货 SKU 链路 | 保存或重排维度后，人工停售 SKU 被自动恢复 | 原测试只验证“重新加入已移除组合”，并把普通人工停用也写成应自动恢复，缺少状态所有权的对照断言。 |

## PIT-0018：PHP `trim` 多字节字符掩码截断中文商品名

- 状态：已防护
- 首次发生：2026-08-11
- 最近发生：2026-08-11
- 复发次数：0
- 适用范围：`CustomerReportCandidateLogic` 的客户前缀、商品名、数量边界与备注清洗
- 相关问题：PIT-0014、PIT-0015

### 触发场景

报货原文使用“子客户名称 + 商品名称 + 数量”，例如“采购部 桂鱼 2斤”。识别结果把商品候选截成包含替换字符的“采购部 桂�”，导致商品和客户都无法匹配。

### 根因

PHP `trim($value, $characterMask)` 的第二参数按字节集合处理，不理解 UTF-8 字符。把“，、；：”等中文标点直接放进字符掩码后，中文商品名末尾字节只要与掩码中的任一字节相同，就可能被单独移除并产生非法 UTF-8。原测试环境又未维护数量单位，未稳定进入该清洗分支。

### 错误做法

使用包含中文字符的 `trim` 字符掩码清理业务分隔符，或只断言最终无候选而不保留被清洗后的商品名作为失败证据。

### 正确做法

多字节业务分隔符统一通过带 `/u` 的 Unicode 正则从字符串两端移除；数量边界通过捕获组取得前后文本，不混用字符偏移和字节切片。测试必须显式维护识别所需的单位主数据。

### 防线

- 自动化防线：`CustomerReportWorkflowTest` 覆盖唯一子客户自动映射，以及不同主客户下同名子客户保持歧义并返回“主客户 / 子客户”展示名；相关 2 个用例、16 条断言通过。
- 架构防线：`CustomerReportCandidateLogic::trimBusinessSeparators()` 是报货识别业务分隔符的统一 Unicode 清洗入口。
- 决策与知识：本记录；PHP `trim` 的字符掩码仅允许单字节字符，中文标点必须使用 Unicode 正则。
- 验证结果：修复前稳定得到“采购部 桂�”；修复后唯一子客户与同名子客户场景均通过。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-11 | 子客户直接报货名称识别 | “采购部 桂鱼 2斤”同时丢失客户和商品候选 | 既有用例没有维护数量单位，未稳定触发数量边界后的多字节清洗。 |

## PIT-0019：相似测试辅助方法让局部补丁落入错误方法

- 状态：已防护
- 首次发生：2026-08-17
- 最近发生：2026-08-17
- 复发次数：0
- 适用范围：使用局部上下文修改包含多个相似数据库插入片段的 PHP 测试辅助文件
- 相关问题：无

### 触发场景

给 `createCustomerReportGoods()` 增加基准 SKU 创建逻辑时，补丁只依赖通用的 `insertGetId([...]);` 收尾作为上下文。文件中另一个 `createCustomerReportUnit()` 具有几乎相同的收尾，新增代码因此落在后者的 `return` 之后；同时前者改成局部变量后没有返回商品 ID。

### 根因

补丁定位没有包含方法声明或业务唯一语句，匹配条件不足以区分相邻的相似方法。PHP 语法检查只能证明代码可解析，无法发现 `return` 后的不可达语句和缺失的业务返回值，所以单靠 `php -l` 会误判为安全。

### 错误做法

在一个包含多个相似方法的文件中，只用数组结尾、括号或通用数据库调用作为补丁锚点；补丁完成后只跑语法检查，不核对目标方法的完整源码和关键业务顺序。

### 正确做法

局部补丁必须把目标方法声明和至少一条业务唯一语句纳入上下文。修改返回值或在 `return` 附近插入逻辑后，应读取目标方法完整范围，并用契约测试断言关键调用与返回语句位于同一方法且顺序正确。

### 防线

- 自动化防线：`tests/unit/SkuInventoryContractTest.php::test_goods_fixture_keeps_base_sku_creation_inside_the_goods_method` 通过反射读取两个相邻方法，断言基准 SKU 创建与 `return $goodsId` 同处 `createCustomerReportGoods()`，且不会落入 `createCustomerReportUnit()`。
- 架构防线：测试商品的基准 SKU 统一调用 `GoodsBaseSkuService::ensure()`，不再复制一大段 SKU 插入字段。
- 决策与知识：本记录；相似方法中的补丁必须使用方法级唯一上下文。
- 验证结果：修复前源码检查明确显示基准 SKU 插入位于 `createCustomerReportUnit()` 的 `return` 之后，且 `createCustomerReportGoods()` 没有返回值；修复后 `SkuInventoryContractTest` 通过。当前扩充守卫后的最新结果为 3 个测试、29 条断言。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-17 | SKU 级库存权威余额改造 | 给测试商品自动创建基准 SKU 时补丁落入相邻单位方法 | 只执行 `php -l`，没有方法范围和关键语句顺序契约。 |

## PIT-0020：商品维护权限只识别后台管理员令牌

- 状态：防护中
- 首次发生：2026-08-18
- 最近发生：2026-08-18
- 复发次数：0
- 适用范围：`GoodsMaintenancePermissionService`、小程序商品创建、商品维度维护与 `/api/jxc/workforce/me/permissions`
- 相关问题：PIT-0012

### 触发场景

店铺创建者使用小程序用户令牌新建商品。创建页先保存租户级商品维度，服务端返回“当前账号没有商品维护权限”，商品和 SKU 均未落库。

### 根因

商品维护服务只按后台 `tenant_admin` 的根管理员或菜单权限授权，并对所有带 `jxcFromUserToken` 标记的请求直接返回拒绝；员工电子权限目录同时没有 `goods.maintain`，因此店主身份和可绑定员工权限都无法进入商品维护授权链路。

### 错误做法

把“来自用户令牌”直接等同于“没有商品维护权限”，或只在后台菜单权限中定义业务能力，却让小程序页面依赖另一套不含该能力的电子权限目录。

### 正确做法

商品维护授权必须按令牌来源分流：后台管理员继续使用根管理员和菜单权限；小程序用户先校验当前租户与用户身份，再允许店铺 owner/admin，或允许已绑定且显式拥有 `goods.maintain` 的员工。`/me/permissions` 必须返回与写接口一致的权限键，供页面在任何写请求前预检。

### 防线

- 自动化防线：`tests/unit/GoodsMaintenanceAuthorizationContractTest.php` 固定用户令牌到店铺成员授权链路和 `goods.maintain` 目录契约；`tests/unit/GoodsCreationConstraintTest.php` 保留真实数据库行为测试，覆盖店主通过用户令牌保存商品维度。
- 架构防线：`GoodsMaintenancePermissionService` 是商品、分类、维度、云端加载与报货快速建品共用的唯一商品维护授权入口；两种令牌在该入口显式分流。
- 决策与知识：本记录；前端商品创建必须在租户级维度和商品写入前调用 `/api/jxc/workforce/me/permissions`。
- 验证证据：契约测试 `2 tests / 7 assertions`；店主、绑定员工及原后台管理员行为测试 `3 tests / 17 assertions`；前端创建流程 `14 tests` 全部通过；双轴 `code-review` 未发现硬性规范违规或规格偏差。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-18 | BeiMi-PHP#2 / BeiMi-ERP#3 | 测试账号预览 4 个 SKU 后保存失败 | 原权限测试只覆盖后台根管理员和菜单授权，没有覆盖小程序用户令牌与店铺 owner/admin 身份。 |

## PIT-0021：并发测试子进程遗漏权威身份上下文

- 状态：已防护
- 首次发生：2026-08-18
- 最近发生：2026-08-18
- 复发次数：0
- 适用范围：`tests/fixtures` 中独立启动 ThinkPHP 的并发 worker、员工电子权限校验
- 相关问题：PIT-0022

### 触发场景

`CustomerReportWorkflowTest` 使用两个 PHP 子进程并发提交报货，父进程已配置最高权限
测试管理员，但 worker 只写入 `tenantId`、`adminId` 和 `userId`。

### 根因

`WorkforceLogic::requirePermission()` 以 `request()->adminInfo.root` 和令牌来源作为权威
身份上下文。worker 没有复用父测试的完整上下文，权限服务因此退回租户成员数据库
查询；测试最小 schema 又不包含 `tenant_member`，两个子进程均在进入报货事务前退出。

### 错误做法

在并发或隔离进程测试中只复制租户 ID 和用户 ID，并假定权限服务会把该身份视为
根管理员；同时丢弃 worker 的 stderr，使夹具错误表现为业务结果为空。

### 正确做法

独立 worker 必须显式建立与测试场景一致的权威身份上下文，包括令牌来源和
`adminInfo`；并发断言应保留每个 worker 的 stdout/stderr 作为失败诊断。

### 防线

- 自动化防线：`tests/unit/CustomerReportWorkflowTest.php::test_two_concurrent_submissions_cannot_over_reserve_one_warehouse_balance()` 验证两个真实子进程均进入公开提交 seam，并在失败时输出各自 stdout/stderr。
- 架构防线：`tests/fixtures/customer_report_submit_worker.php` 显式设置 `jxcFromUserToken=false` 和根管理员 `adminInfo`，不依赖缺省权限回退。
- 决策与知识：本记录；测试身份必须与生产权限入口使用相同的权威字段。
- 验证结果：修复前两个 worker 均报 `la_tenant_member` 不存在；修复后 worker 进入真实并发事务，夹具根因不再复现。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-18 | BeiMi-PHP#6 | 并发 SKU 预留验收 | 既有并发测试丢弃子进程 stderr，且 worker 没有完整身份上下文。 |

## PIT-0022：默认工序惰性初始化使用先查后插

- 状态：已防护
- 首次发生：2026-08-18
- 最近发生：2026-08-18
- 复发次数：0
- 适用范围：`WorkforceLogic::ensureInitialProcesses()`、首次报货生成履约任务
- 相关问题：PIT-0004、PIT-0021

### 触发场景

一个新测试租户尚无默认工序，两个报货请求同时提交并在各自事务中生成履约任务。

### 根因

默认工序初始化按每个工序执行普通查询，再在不存在时插入。两个事务可以同时看到
`purchase` 不存在，随后一个事务提交，另一个事务因唯一键
`uk_tenant_work_process_code` 冲突而让整张报货单回滚；该冲突不是可重试死锁，提交层
只能返回通用失败。

### 错误做法

把数据库唯一键当作“先查再插”的并发兜底，却不在同一 SQL 中处理重复键；或者在
事务快照中捕获重复键后再做普通查询，后者仍可能看不到刚提交的并发行。

### 正确做法

默认工序首次写入必须使用原子 upsert。重复键分支只对唯一键中的 `code` 做同值更新，
保留既有工序名称、关键词和运营配置；核心工序的触发类型再按既有规则显式校准。

### 防线

- 自动化防线：`tests/unit/CustomerReportWorkflowTest.php::test_two_concurrent_submissions_cannot_over_reserve_one_warehouse_balance()` 在空默认工序状态下同时启动两个提交，断言一单 `submitted_ready`、一单 `submitted_shortage`，总预留为 1.0000。
- 架构防线：`WorkforceLogic::ensureInitialProcesses()` 通过 `duplicate(['code'])` 生成原子 `ON DUPLICATE KEY UPDATE`，不再使用“先查再插”。
- 决策与知识：本记录及 PIT-0004；所有位于报货事务内的惰性初始化都必须具备并发首写语义。
- 验证结果：修复前第二个事务稳定报默认 `purchase` 工序唯一键冲突；修复后并发测试通过（1 个测试、10 条断言）。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-18 | BeiMi-PHP#6 | 新租户首次并发报货 | PIT-0004 已保护库存事务和幂等键，但未覆盖履约任务依赖的默认工序惰性初始化。 |

## PIT-0023：服务端生成时间进入请求幂等指纹

- 状态：已防护
- 首次发生：2026-08-19
- 最近发生：2026-08-19
- 复发次数：0
- 适用范围：交付、结算及其他“客户端事实 + 服务端生成事实”共同落库的幂等写入口
- 相关问题：PIT-0004、PIT-0021

### 触发场景

两个进程使用同一幂等键并发确认同一次自配送客户交接。自配送没有客户端交接时间，
服务端在每个进程中各自读取当前秒；一个事务提交后，另一个进程按同键重放时跨过秒
边界，被错误判定为“同一幂等键提交了不同事实”。

### 根因

请求指纹混入了服务端当前时间。该字段是落库时由服务端生成的结果，不是客户端声明
且可稳定重放的输入，因此同一请求的指纹会随执行时刻变化。数据库唯一键正确阻止了
重复副作用，但不稳定指纹又拒绝返回已经提交的权威事实。

### 错误做法

把 `now()`、自动编号、数据库默认值或其他服务端生成结果直接放入请求指纹；或者只验证
“只有一条记录”，没有验证竞争请求和跨时钟重放都返回同一权威记录。

### 正确做法

请求指纹只包含客户端可重复声明、经过确定性规范化的业务输入。服务端生成时间可以保存
为首次提交事实，但不得参与后续请求相等性判断；客户端必须声明的实际时间仍应进入指纹。
提交竞争失败后，调用者按这个稳定指纹读取并返回已提交的追加事实。

### 防线

- 自动化防线：`FulfillmentWorkflowTest::test_self_delivery_replay_ignores_server_generated_handoff_clock()` 使用可控服务端时钟，在相同请求两次执行之间推进五秒，断言重放同一事件且库存只出库一次。
- 并发防线：`FulfillmentWorkflowTest::test_concurrent_same_delivery_key_returns_one_event_and_one_inventory_side_effect()` 使用两个真实 PHP 进程提交同一交付键，断言都返回同一事件，且交付事件、库存流水和负库存来源均只产生一次。
- 架构防线：`DeliveryVariantLogic` 只对固定线车和第三方配送这两类客户端必须声明的交接时间生成指纹；自配送的服务端交接时间只写入首次事件。
- 决策与知识：本记录及 PIT-0004。
- 验证结果：修复前双进程重放跨秒时稳定返回“同一幂等键不能提交不同的交付事实”；修复后原双进程用例通过（1 个测试、16 条断言），可控时钟回归通过（1 个测试、10 条断言）。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-19 | BeiMi-PHP#10 | 同一自配送交付键由两个进程并发确认 | PIT-0004 已约束锁序、事务后重放和请求指纹比对，但没有约束指纹只能包含可稳定重放的客户端事实。 |

## PIT-0024：待审批版本草案覆盖当前权威销售单

- 状态：防护中
- 首次发生：2026-08-19
- 最近发生：2026-08-19
- 复发次数：0
- 适用范围：销售结算 V1/V2、计费重量差待办、版本审批与拒绝恢复
- 相关问题：无

### 触发场景

已经形成正式 V1 的销售单提交 V2 更正。V2 包含非零计费重量差，需要最高权限审批；
服务端正确返回 `pending_weight_review`，但审批前的商品重量、金额和应收投影已经被
V2 草案覆盖。此时客户看到的当前详情不再是已批准的 V1，拒绝草案也无法可靠证明
权威版本从未被污染。

### 根因

待审批提案与已批准版本共用了同一组可变 `sales_order` / `sales_order_goods` 投影。
提交层先调用正式版本使用的 `applyDraft()`，再把动作标为待审批；状态字段虽然是
pending，权威明细和金额却已经发生了副作用。代码缺少“草案只保存于追加式动作快照，
批准后才应用到权威投影”的事务边界。

### 错误做法

先覆盖当前销售单投影，再依靠 `settlement_status=pending_weight_review` 表示尚未批准；
或者拒绝时再尝试从历史版本反向恢复。前者让未批准事实进入打印、分享、金额和应收
读取路径，后者增加恢复遗漏和并发覆盖风险。

### 正确做法

已有正式版本时，待审批 V2 只能把规范化明细、抹零、偏好、版本基线和原因保存到
`sales_settlement_action` 的不可变提案快照。当前 `sales_order`、
`sales_order_goods`、应收及债务快照在批准前保持 V1 不变；最高权限批准时在同一事务
中校验乐观版本并一次性应用提案，拒绝只关闭提案，不写回权威订单。首次 V1 尚无正式
版本时可以使用 `pending` 投影表达待审批，并在拒绝后恢复为未结算状态。

### 防线

- 自动化防线：`tests/unit/SalesSettlementWorkflowTest.php::test_pending_v2_weight_proposal_never_overwrites_authoritative_v1_and_reject_restores_it()` 先形成 V1，再提交需审批的 V2，断言待审批详情仍展示 V1、草案仅从 `pending_proposal` 读取、拒绝后 V1 明细与金额不变。
- 架构防线：`SalesSettlementLogic` 对已有正式版本的待审批分支只写追加式 action/todo；正式投影统一在批准或无需审批的正式化路径中应用，V2 拒绝路径不更新 `sales_order.update_time`。
- 决策与知识：`E:\object\BeiMi\CONTEXT.md` 中“待确认销售结算”“销售单编辑”“销售结算幂等”和“销售单版本冲突”的冻结定义。
- 当前验证证据：回归测试在修复前稳定暴露 V1 明细被 V2 覆盖和拒绝动作改写 V1 `update_time`；修复后 `SalesSettlementWorkflowTest` 20 个测试、204 条断言通过，Standards/Spec 双轴最终复审均无 P0-P2。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-19 | BeiMi-PHP#11 | 正式 V1 提交带计费重量差的 V2 草案 | 首版结算测试覆盖待审批 V1 和正式版本幂等，但未覆盖已有正式版本上的待审批更正是否保持权威投影不变。 |

## PIT-0025：派生幂等键遗漏独立业务动作身份

- 状态：防护中
- 首次发生：2026-08-19
- 最近发生：2026-08-19
- 复发次数：0
- 适用范围：负库存自动核销、库存更正及其他由业务事实派生内部幂等键的追加动作
- 相关问题：PIT-0004、PIT-0023

### 触发场景

同一销售单对同一负库存来源先减少实际交付重量、再加回、随后再次减少到相同重量。
两次合法减少会产生相同的余额前后值和分摊数量，但分别属于不同的销售结算动作。

### 根因

自动核销动作键只包含来源、订单、余额前后值和分摊数量，没有包含本次结算动作身份。
业务状态往返后，两个独立事实可以拥有完全相同的数值快照，第二个事实因此撞上第一个
事实的唯一键并回滚整次结算。稳定的状态字段不能代替业务动作身份。

### 错误做法

仅以“来源 ID + 订单 + before/after + 数量”派生内部幂等键，并假设同一状态变化不会在
后续合法业务动作中再次出现。

### 正确做法

内部派生幂等键必须同时包含调用链上已持久化、稳定且唯一的业务事实身份，例如
`settlement_action_id`；状态快照继续用于识别该事实的副作用内容，但不能单独充当事实身份。

### 防线

- 自动化防线：`SalesSettlementWorkflowTest::test_repeated_decrease_increase_decrease_uses_each_settlement_action_as_a_distinct_offset_fact()` 通过“减→加→减”回到相同余额前后值，断言两次核销动作均追加成功且结算推进到 V4。
- 架构防线：`settlement_action_id` 从 `SalesSettlementLogic` 经 `StockService` 传入 `NegativeInventoryLogic`，并进入 `sales_delivery_correction_offset` 的派生指纹；唯一约束仍以租户和派生动作键防止同一事实重复。
- 决策与知识：本记录、PIT-0004 与 PIT-0023；服务端生成时间不得进入请求指纹，独立业务动作身份也不得从内部派生键中遗漏。
- 验证结果：修复前目标测试稳定触发 `uk_tenant_negative_action_idempotency` 重复键并回滚；修复后 PHP #11 全部 20 个测试、204 条断言通过，Standards/Spec 双轴最终复审均无 P0-P2。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-19 | BeiMi-PHP#11 | 同一来源两次合法减少实重产生相同余额差量 | 既有 PIT-0023 只禁止不稳定的服务端时间进入请求指纹，没有要求内部派生键必须包含独立业务动作身份。 |

## PIT-0026：交付完成入口继续调用旧转销售链路

- 状态：防护中
- 首次发生：2026-08-19
- 最近发生：2026-08-19
- 复发次数：0
- 适用范围：真实交付后的记账工票完成、待结算销售单身份与销售结算入口
- 相关问题：PIT-0004

### 触发场景

真实交付已经按实际交付重量出库并创建 pending 销售单身份，记账工票随后进入
`ready_to_bill`。用户点击“确认开单”时，服务端却返回“交付已经完成出库，请在后续
销售结算中正式确认”，前端无法取得进入结算页所需的销售单 ID。

### 根因

交付模块接管“真实交付即出库并建立待结算身份”后，记账工票的完成入口仍调用旧
`CustomerReportLogic::convert()`。旧入口的职责是从报货单创建并出库销售单，它正确
拒绝已存在的 pending 身份；调用方却没有随领域职责迁移为“返回已存在身份”。原测试
把这条拒绝当成预期结果，因而保护了断链而不是保护主业务闭环。

### 错误做法

在交付后再次调用“报货转销售”入口，或把“旧入口正确拒绝二次出库”当作记账完成的
成功语义。

### 正确做法

记账完成必须锁定报货单和记账任务，读取交付事务已经创建的同租户 pending／pending_weight_review／formal
销售单身份，完成履约任务并返回 `settlement_orders`；响应丢失后的 completed 状态重放
仍返回同一身份。该入口不得再次扣库存、增加应收或创建平行销售单。

### 防线

- 自动化防线：`FulfillmentWorkflowTest::test_customer_report_delivery_creates_pending_order_without_running_sales_settlement()` 覆盖真实交付、记账完成和丢响应重放，断言返回同一 pending order、交付出库流水仍只有一条且应收仍为零。
- 边界防线：`test_mixed_delivered_and_undelivered_items_only_outbound_and_stage_the_delivered_lines()` 断言混合未交货只返回包含实际交付行的待结算身份。
- 架构防线：`FulfillmentTaskLogic::bill()` 按 report→task 稳定顺序加锁，只读取 `source_type=customer_report` 的既有销售单，不调用旧 `convert()`。
- 决策与知识：根目录 `CONTEXT.md` 中“交付出库”“待确认销售结算”和“赶线后补结算”的冻结定义。
- 当前验证证据：修复前两条公开行为测试稳定返回旧转换拒绝；修复后 `FulfillmentWorkflowTest` 58 个测试、868 条断言，`SalesSettlementWorkflowTest` 20 个测试、204 条断言，`CustomerReportRouteContractTest` 9 个测试、137 条断言通过，Standards/Spec 双轴最终均无 P0-P3。Codegraph 无 pending，但仍搜索不到本次新增重试方法与既有 `SalesSettlementLogic`，因此本 PIT 保持“防护中”。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-19 | BeiMi-PHP#11 → BeiMi-ERP#11 接口接线 | 任务看板确认开单无法取得待结算销售单 ID | PHP #8 测试只验证交付创建 pending 身份和防止二次出库，却把后续 bill 拒绝固化为预期，没有断言主链路能继续进入销售结算。 |

## PIT-0027：跨版本业务计数降级为前端本机缓存

- 状态：防护中
- 首次发生：2026-08-19
- 最近发生：2026-08-19
- 复发次数：0
- 适用范围：客户销售单打印身份、跨版本累计计数与跨设备操作
- 相关问题：PIT-0001、PIT-0024

### 触发场景

同一客户销售单的 V1 已成功打印，随后编辑保存为 V2；页面却重新显示“首次打印”。
换手机、切换小程序存储或清理缓存后也会丢失既有重打次数。

### 根因

重打次数被保存在小程序本机 storage，且存储键包含销售单版本。客户销售单身份实际沿用
同一订单号，版本只是当前权威内容；设备缓存和版本都不是该业务计数的权威归属。测试又
手工注入并不存在于真实详情响应的租户字段，保护了辅助函数而没有保护公开接线。

### 错误做法

把需要跨设备、跨版本连续的业务事实保存在客户端缓存，或通过人工 fixture 构造生产响应
没有的身份字段，再以纯字符串键测试声称已经覆盖真实语义。

### 正确做法

打印前由服务端锁定同一租户下的当前正式销售单，以稳定幂等键准备并恢复同一打印回执，
按订单身份统计成功纸质副本并返回本次首次／重打编号；蓝牙打印完成后提交可重放的成功
或失败回执。客户端本地队列只保存待重传回执，不保存或覆盖权威计数；未知出纸结果必须
由用户确认。详情和下一次打印都只读取服务端累计结果，版本更新不得清零。

### 防线

- 自动化防线：`SalesSettlementWorkflowTest::test_print_receipts_accumulate_for_one_sales_order_across_versions_and_devices()` 先成功打印 V1，再保存 V2，断言详情和下一次打印仍连续累计，并覆盖成功回执重放与失败不增计数；`test_lost_v1_print_prepare_can_be_replayed_and_closed_after_v2_is_saved()` 证明 V1 准备响应丢失后即使已保存 V2，仍可按原键找回并关闭回执，再开始 V2 打印；`test_late_success_receipt_remains_replayable_after_another_device_reserves_the_next_copy()` 证明旧设备已出纸但回执迟到超过十分钟时，服务端不会把事实强制改成失败，新设备使用单调票次，两个迟到成功最终都能累计。
- 公开契约防线：`CustomerReportRouteContractTest::test_customer_sales_printing_exposes_prepare_and_result_receipt_routes()` 与前端 `sales-settlement-flow.test.mjs` 固定准备／回执路由、HTTP 动词和响应归一化。
- 架构防线：`customer_sales_print_log` 以租户和销售单订单身份保存准备与回执，`version` 只记录该副本所用内容，不参与累计身份分割。
- 决策与知识：根目录 `CONTEXT.md` 中“销售单重打”和“销售单编辑”；同一销售单沿用销售单号，版本变化不得重置重打次数。
- 当前验证证据：修复前前端测试明确固化按版本 storage 隔离且真实详情没有 tenant；复审继续证明打印准备响应丢失会留下无法恢复的 pending。修复后后端销售结算 23 个测试、255 条断言与路由契约 10 个测试、144 条断言通过，前端销售结算 15 项行为测试和 UI 契约通过。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-19 | BeiMi-ERP#11 | V1 打印后编辑成 V2 或换设备时重打次数归零 | 既有输出测试只检查某一次纸票标签，没有覆盖同一销售单跨版本、跨设备的权威累计；前端契约使用人工 tenant fixture 掩盖了真实详情响应。 |

## PIT-0028：同一销售单身份在详情和批量入口各自选择来源

- 状态：已防护
- 首次发生：2026-08-26
- 最近发生：2026-08-26
- 复发次数：0
- 适用范围：销售结算列表、重量差待办、销售单详情与客户销售单打印身份

### 触发场景

一个销售单含有多条来源于不同报货明细的商品行。批量入口按 `order_goods.id` 取第一条来源，详情按
`customer_report_item.id` 取第一条有效来源；当两张表的自增顺序不同，或前一条报货明细已经软删除时，
用户在列表、待办和纸票上会看到不一致的子客户。

### 根因

同一“首条有效报货明细”业务规则在详情和批量映射中各自实现，排序字段和软删除回退逻辑发生分叉。

### 正确做法

所有入口通过同一解析器，以 `customer_report_item.id ASC` 选择每个销售单首条未软删除的来源；无有效
来源时才回退到销售单主客户。禁止根据 `order_goods` 的插入顺序决定客户销售单身份。

### 防线

- 自动化防线：`SalesSettlementWorkflowTest::test_customer_identity_uses_first_valid_report_item_identically_for_detail_and_list()` 构造 `order_goods` 与 `customer_report_item` 反序，并将较小报货明细软删除，断言详情和列表始终选择同一有效子客户。
- 架构防线：`SalesSettlementLogic::firstValidCustomerReportItemsForOrderSources()` 是详情、列表和重量差待办唯一的来源选择器。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-26 | BeiMi-ERP#11 终审 | 多子客户销售单在不同入口显示不同身份 | 原有打印回执测试保护版本累计，不覆盖来源排序与软删除回退。 |

## PIT-0029：沙箱内命令不可见被误判为 Codegraph 安装缺失

- 状态：防护中
- 首次发生：2026-08-27
- 最近发生：2026-08-27
- 复发次数：0
- 适用范围：Codex 受限终端中对 `BeiMi-PHP`、`BeiMi-uniapp` 的 Codegraph 增量同步与新符号验证
- 相关问题：无

### 触发场景

Codegraph MCP 可以读取旧索引，但新增 `PurchaseBatchLogic` 尚未进入索引。受限终端执行
`where codegraph` 和 `Get-Command codegraph` 均无结果，于是错误判断本机没有安装
Codegraph，未继续尝试宿主机权限下的同步命令。

### 根因

Codegraph 0.9.7 及其 npm 命令垫片实际存在于用户级 `%APPDATA%\npm`，但 Codex
工作区沙箱不能读取该目录；沙箱内的命令解析结果只反映当前权限视图，不能证明宿主机
未安装工具。当前 MCP 配置只启动 `codegraph serve --mcp`，没有固定 `--path`；服务按
客户端 `rootUri` 运行在未初始化的项目族根目录，而两个既有索引位于子项目目录。通过
`projectPath` 可以查询这些子索引，但不能据此证明当前服务已为每个子索引建立文件监听；
Codegraph 文档也明确把沙箱环境列为需要手动同步的场景。因此原监听器没有消费本次
子项目变更，需要显式执行增量同步。Vue2
Options API 对象方法（例如 `savePurchaseBatch`）不会被当前解析器展开为方法节点，也
不能单独作为索引新鲜度探针。

### 错误做法

仅根据沙箱内 `where`／`Get-Command` 失败就声明 Codegraph 未安装；或者只用 Vue2
Options API 对象方法是否可搜索判断整个前端索引是否陈旧。

### 正确做法

先用 `codegraph_status` 和只读 SQLite 探针确认索引库健康、目标文件是否存在及
`modified_at` 是否与磁盘一致。沙箱内命令不可见时，在明确授权下以提升后的宿主机
权限运行 `codegraph --version`，再对已初始化项目执行 `codegraph sync .` 和
`codegraph status .`；不得因此重复 `codegraph init`。PHP 使用新增类或方法验证，
Vue2 使用新文件、组件或顶层常量验证，不以 Options API 对象方法作为唯一探针。

### 防线

- 可重复探针：只读查询 `.codegraph/codegraph.db` 的 `files`／`nodes` 表，要求 PHP 新类进入节点；前端目标文件的索引 `modified_at` 与磁盘时间一致、`errors` 为空，并能检索新增顶层常量或组件。
- 运行防线：沙箱内解析不到用户级 CLI 时，先验证宿主机 `%APPDATA%\npm` 命令垫片，再在授权下进入每个已初始化子项目运行 `codegraph sync .`；全局 MCP 未固定该子项目 `--path` 时，不把无 pending 的查询结果等同于文件监听已覆盖该索引。禁止把权限隔离误报成安装丢失或擅自重建索引。
- 自动化限制：仓库测试不能自行取得 Codex 沙箱外权限，当前无法把宿主机 CLI 可见性变成无人值守测试，因此本 PIT 保持“防护中”。
- 决策与知识：本记录及用户级 `C:\Users\ASUS\.codex\AGENTS.md` 的 Codegraph 增量同步规则。
- 验证结果：两个索引库 `quick_check=ok`；同步后 PHP 可检索 `PurchaseBatchLogic`，前端可检索 `purchaseBatchAPI` 及三个采购批次组件，目标 Vue 文件索引时间晚于修改时间且无解析错误。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-27 | 采购批次与供应商子进货单 | 提交后检查 Codegraph 增量同步 | 原规则要求检查状态，但没有区分沙箱命令可见性与宿主机安装状态，也没有说明 Vue2 Options API 方法不是可靠的新鲜度探针。 |

## PIT-0030：新建保存场景继承更新 ID 的正数校验

- 状态：已防护
- 首次发生：2026-08-29
- 最近发生：2026-08-29
- 复发次数：1
- 适用范围：`BeiMi-PHP` 员工档案新建与 `POST /jxc/workforce/employee/save`
- 相关问题：无

### 触发场景

小程序新增一个启用的纸质员工，填写姓名、测试手机号和“杀鱼”工序能力，并按保存。前端按既定新建语义提交 `id: 0`。

### 根因

`WorkforceValidate` 的全局 `id` 规则为 `require|integer|gt:0`。ThinkPHP 在执行验证规则时先把 `gt:0` 标准化为规则类型 `gt`，再以该类型匹配 `remove()` 项；因此 `remove('id', 'require|gt:0')` 中的 `gt:0` 不会命中，旧的 `gt` 规则仍会在控制器进入 `WorkforceLogic::saveEmployee()` 之前拒绝 `id: 0`。逻辑层实际已把 `id <= 0` 作为插入分支，前后契约不一致。

### 错误做法

为可新建也可编辑的保存场景只移除 ID 的必填规则；或误以为 `remove()` 可以按带参数的 `gt:0` 删除规则；或把前端的 `id: 0` 改成伪造的正数 ID。

### 正确做法

保存场景以规则类型移除 `require|gt`，再追加 `egt:0`，使 ID 成为“可缺省或为非负整数”：`0` 表示新建，正数表示编辑，负数仍被拒绝。保持现有读取和状态切换入口的正数 ID 约束不变。

### 防线

- 自动化防线：`tests/unit/WorkforceValidateSaveEmployeeTest.php` 覆盖 `id: 0` 的纸质员工新建校验、正数 ID 的编辑校验和负数 ID 拒绝。
- 架构防线：`WorkforceValidate::sceneSaveEmployee()` 按 ThinkPHP 规则类型显式移除继承的 `require|gt`，并追加 `egt:0`，与 `WorkforceLogic::saveEmployee()` 的新增／编辑分支一致。
- 决策与知识：根目录 `CONTEXT.md` 中“员工档案”“员工工序能力”和“员工电子权限”的独立定义。
- 验证结果：修正前用 `D:\xampp\php\php.exe vendor\bin\phpunit tests\unit\WorkforceValidateSaveEmployeeTest.php --colors=never` 稳定复现 3 例中的 `id: 0` 失败；修正后同一聚焦命令通过（3 tests, 3 assertions）。本机可使用 `D:\xampp\php\php.exe`；完整 PHPUnit 因等待外部依赖而在约 9 分钟、CPU 约 0.64 秒后被终止，未完成且不得写成通过。真实微信开发者工具回归仍待部署后完成。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-08-29 | 冻结 V1 真实数据验收 / 员工工序能力配置 | 新增只具备“杀鱼”能力的纸质员工被保存接口拒绝 | 既有履约测试直接调用逻辑层，绕过控制器校验；没有覆盖小程序实际提交的 `id: 0` 保存契约。 |
| 2026-08-29 | 新增员工保存校验回归 | 首版候选补丁写为 `remove('id', 'require|gt:0')`，聚焦 PHPUnit 仍拒绝 `id: 0` | 当时未使用可用的 `D:\xampp\php\php.exe` 执行聚焦测试，未暴露 ThinkPHP `remove()` 只按标准化规则类型匹配的细节。 |
