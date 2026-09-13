# 踩坑索引

按根因去重。每条记录必须指向实际防线；仅有“不要这样做”的提醒不算已防护。

## PIT-0073：迁移使用隔离库不支持的索引条件语法

日期：2026-09-13

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / code-review`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：互转结清重投影唯一键需要扩展承接来源维度。隔离 MySQL 在执行迁移时拒绝 `DROP INDEX IF EXISTS`，修复兼容语法并返回互转更正交付。

- 状态：已防护
- 首次发生：2026-09-13
- 最近发生：2026-09-13
- 复发次数：0
- 适用范围：`database/migrations` 与隔离 MySQL 迁移回归
- 相关问题：无

### 触发场景

为既有唯一索引改为含来源的复合唯一键，并在隔离 MySQL 上从测试初始化路径执行迁移。

### 根因

迁移采用当前隔离 MySQL 不支持的 `ALTER TABLE ... DROP INDEX IF EXISTS` 方言；在真正执行迁移前没有验证该版本的 DDL 语法。

### 错误做法

把较新 MySQL 的条件索引语法写进必须由项目隔离数据库执行的迁移，只依赖静态检查。

### 正确做法

使用该隔离 MySQL 支持的原子 `DROP INDEX` 加 `ADD UNIQUE KEY`，并通过真实初始化路径反复执行迁移和业务写入。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::setUpBeforeClass` 连续执行迁移 `20260913_000053` 与 `20260913_000054`；互转更正定向用例在隔离库中实际创建复合唯一索引并写入多段承接事件。
- 架构防线：迁移 054 在单条 `ALTER TABLE` 中删除并重建同名唯一键，避免迁移重跑留下缺失索引窗口。
- 已验证事实：修复前定向测试在 0 条断言时报 MySQL `1064`；改为兼容语法后 `test_rebased_arrival_correction_moves_to_latest_transfer_source` 通过，且后续多段承接写入用例继续覆盖该索引。
- 尚未验证：生产库迁移与部署。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-13 | 财务一期互转结清重投影 | 为重投影事件唯一键加入承接来源时隔离 MySQL 拒绝条件删索引语法 | 此前迁移只建表，没有覆盖既有索引替换的实际 DDL。 |

## PIT-0072：把关联资金更正重计为当期真实收支

日期：2026-09-13

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：互转到账与返还的关联更正进入完整财务回归前，规格复查发现资金报表把内部冲销误当成本月真实手续费支出；已建立公开业务接口回归后返回互转更正交付。

- 状态：已防护
- 首次发生：2026-09-13
- 最近发生：2026-09-13
- 复发次数：1
- 适用范围：`FinanceReports::cash`、账户互转到账/返还关联更正与资金报表
- 相关问题：PIT-0056

### 触发场景

已结月到账 600 元、代扣手续费 5 元；后续更正为到账 500 元、代扣 3 元，再读取更正入账月资金报表。

### 根因

资金报表根据原业务类型把关联冲销和替代单都纳入互转手续费汇总。它没有区分真实发生的收付款与仅重述原真实交易的内部更正，因此把本月费用差额 -2 元误列为本月对外收支。首次修复仅把账户 `cash` 分录归入调整，又遗漏了同一更正对应的 `transit` 分录，导致门店总资金的净调整不完整。

### 错误做法

只因分录原始类型为到账、返还或转出，就把关联冲销和替代分录当作新的内部互转或对外手续费；或用真实退款解释录入更正。

### 正确做法

真实互转与手续费仍按实际发生日期单列；关联冲销和替代单仅进入内部账面调整。资金报表保留分录追溯，但不得把它们新增为当期对外收支或新的内部资金移动；同一更正的账户与在途分录必须共同计入净调整。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_transfer_settlement_correction_preserves_money_identity_and_closed_month_composition` 通过公开动作、月结、关联更正和 `monthlyReport`，断言对外流出为 0、账户与在途净差额 `2.00` 共同进入 `cash_adjustment`，并且更正文档不进入 `internal_transfers`。
- 架构防线：`FinanceReports::cash` 在报表投影层以 `correction_reversal` 和 `corrects_document_id` 识别互转内部更正，保留分录至 `adjustments`，不跨越真实资金事实边界。
- 已验证事实：修复前的 `finance-73-green.log` 以旧断言接受本月 `external_out=-2.00`；首次报表修复的 `finance-73-report-green.log` 只有 83 条断言，未覆盖同一更正的在途净差额。补齐后，聚焦用例通过（1 test、87 assertions），`FinanceBusinessWorkflowTest.php` 完整回归通过（247 tests、10212 assertions，exit 0）。
- 尚未验证：生产报表历史数据、微信真机以及部署后的资金报表展示。完整仓库 PHPUnit 运行到 633 tests 后存在 16 errors、8 failures（商品分类、商品维度、用户路由契约等非财务既有测试），因此不能写成全仓通过。`finance-73-report-red.log` 因隔离 MySQL 3307 暂停而在 0 assertions 处连接失败，不能作为业务失败证据。
- 后续建议：新增带真实资金交易的关联更正类型时，必须同时验证真实收支、内部账面调整、内部资金移动和已结期间快照四种报表口径。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-13 | 财务一期互转到账与返还更正 | 跨已结月把到账和手续费改正后，报表把内部冲销差额列为本月对外支出 | 既有 PIT-0056 只覆盖零金额到账仍须识别真实手续费，没有覆盖“更正不是新真实资金事实”的投影边界。 |
| 2026-09-13 | 财务一期互转到账与返还更正复审 | 首次修复将手续费 5→3 的账户变动计入调整，却遗漏在途恢复 2 元 | 初版防线只断言对外收支归零和账户分录，未断言账户与在途组成的总资金净变化。 |

## PIT-0071：用最新处理结果替代完整月结调整证据链

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第71批误摊取消审查发现历史流水展示缺口；修复后返回摊销取消交付，继续未摊计划调整。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：1。
- 触发场景：旧月带未摊事项结账，后续先确认摊销、再取消，随后重新确认。
- 根因：遗留状态与证据共用最新月份结果，只读取最后一笔单据；后续调整流水查询依据这些单据ID，较早的正反变化因此消失。
- 错误做法：认为当前余额和状态正确就可以省略历史处理链。
- 正确做法：状态由最新月份结果判断；证据取该来源月份完整历史链，再按这些单据读取跨期分录。原结账快照保持不变。
- 自动防线：`FinanceBusinessWorkflowTest::test_deferred_amortization_cancellation_restores_balance_and_reconfirmation_preserves_history` 检查取消后的两份证据、再确认后的三份证据及各阶段调整单据ID全集。
- 已验证事实：`finance-71-followup-red.log` 为1 test、46 assertions、1 failure，证据实际1份而应2份；修复后 `finance-71-php-final.log` 29 tests、1112 assertions通过，exit 0。Spec定点复审关闭本项。
- 尚未验证：业务库迁移、原生页面与生产历史规模。
- 后续建议：新增可反复处理的月结事项时，分别验证当前状态、有效余额和完整追溯链。

第72批同根因复发：最新摊销状态不能表达后续计划已移出原月份，计划取消后遗留永久待处理。原摊销链防线没有覆盖计划修订。现同时合并同来源的全部计划修订证据，以最新计划是否保留原月判断是否仍需摊销，后续义务调整流水一并可追溯。`finance-72-followup-red.log` 为1 test、57 assertions、1 failure，实际pending而应resolved；修复后专项 `finance-72-php-final-targeted.log` 30 tests、1172 assertions通过；完整财务回归 `finance-72-finance-full.log` 339 tests、11121 assertions、1 skipped，exit 0。首次／最近发生仍为2026-09-09，累计复发次数现为1；本批两轴定点源码复审均通过，跳过项不计为已验证。完成后返回计划调整交付。

## PIT-0070：未来规则待确认沿用操作日期，误阻止历史月结账

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第70批周期计划审查确认月份归属缺口；修复并验证后继续周期计划交付。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：0。
- 触发场景：提交下月起生效的计划变更，检查本月结账清单。
- 根因：通用待确认清单只识别费用月、操作日期与服务起始月，没有按新类型读取 `effective_month`；无日期时阻止所有旧月，有操作日期时错误归到操作月份。
- 错误做法：所有单据统一按客户端任意日期猜测业务月份。
- 正确做法：未来规则变更只使用生效月份；缺失有效月份的待确认输入继续保留未决状态。
- 自动防线：`FinanceBusinessWorkflowTest::test_recurring_plan_changes_only_future_schedule_and_stop_keeps_processed_month_history` 同时检查有无 `actual_date` 的未来待确认计划不阻止本月。
- 已验证事实：`finance-70-month-red.log` 为1 test、45 assertions、1 failure，实际错误列出1项阻塞；修复后 `finance-70-final-php.log` 28 tests、1047 assertions通过，exit 0，覆盖周期、待摊与月结。Spec定点复审关闭本项。
- 尚未验证：业务数据库迁移与原生验收。
- 后续建议：新增有未来生效规则的单据时，同步检查未确认单据的期间归属。

## PIT-0069：历史关联查询复用新建候选展开，受无关容量限制阻断

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`用户级自定义 / prevent-repeat-pitfalls`、`Matt Pocock / diagnosing-bugs`（自动调用）。
- 说明：第67批 Standards 审查定位已确认验收回看失败，建立红绿防线后继续退货退款关联开发。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：0。
- 适用范围：`FinanceBusinessLogic::options` 的已确认退货关联记录查询。
- 相关问题：PIT-0065（写入容量，根因不同）。
- 触发场景：客户销售单商品超过1000项，读取某个已确认验收的贷项与退款记录。
- 根因：按单据定位的历史查询先执行全客户的新建验收候选展开，候选的容量拒绝在真正历史读取前抛出。
- 错误做法：给历史查询附加一个ID参数，却继续无条件执行新建候选查询。
- 正确做法：历史入口按可信原凭据单独读取关联事实；新建候选仍保留原容量门槛。
- 自动防线：`FinanceBusinessWorkflowTest::test_customer_refund_links_verified_physical_return_to_original_sale_credit_without_repeating_income_or_stock` 增加1001销售明细后回看已有退货，不展开候选。
- 已验证事实：`.scratch/finance-67-history-capacity-red.log` 为2 tests、103 assertions、1 failure；修复后 green 日志2 tests、104 assertions通过，包含旧销售与分次交付销售、退款和更正链。
- 尚未验证：业务库、生产规模压测与原生客户端。
- 后续建议：新增历史回看入口应验证新业务候选已失效或超容量时仍可读取原事实。

## PIT-0068：历史单据未写新数量字段，导致已发生实物被漏计

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第65批客户退货在验证旧来源额度时发现字段迁移边界；修复后继续实物验收页面及完整财务验证。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：0；相关问题：PIT-0067。
- 触发场景：旧销售退货入口保存 `number=2` 并实际入库2，但没有填写后来新增的 `base_quantity`，新退货将其当作未退。
- 根因：直接汇总新字段的默认零值，误当成历史业务真实零数量。
- 防线：`FinanceCustomerReturns::legacyReturned()` 按原退货单关联的实际库存流水汇总正反方向，并与旧入口保存的 `number` 核对；不一致时明确要求核实，不能把缺失证据当作零。新旧退回共同约束有效交付量，实重更正也不能降到已退量以下。
- 已验证事实：`.scratch/finance-65-legacy-return-red.log` 为3 tests、152 assertions、3 failures，期望可退4而实际6；修复后 `.scratch/finance-65-return-final-targeted.log` 为7 tests、289 assertions通过，含同仓、跨仓、未知成本、历史补录及盘点关联。
- 尚未验证：业务库迁移、生产历史数据完整性和原生验收，不代表全仓测试通过。
- 后续建议：消费历史事实前，确认该时期的真实写入字段和实物单位，不能只依据现版表结构。

## PIT-0067：以原始交付流水量代替更正后的可关联实重

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第64批 Spec 审查发现销售实重已更正但盘点关联仍引用原流水数量；固定当前盘点关联交付位置，建立真实业务回归后继续完整财务验证。

第69批补充来源：财务一期实物退货更正，实际使用 `Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`。两轴审查发现新增撤销能力未同步到原事实消费者；补齐当前有效性检查后继续实物更正验证与提交。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：1。
- 根因：原库存流水为不可变事实；直接用其数量，或仅用订单SKU总实重减已关联量，均不能表达某次交付被后续实重更正冲回的份额。
- 触发场景：原出库10改为4后仍可关联10；更隐蔽的情况是原截止前已出库6，后来补录4又把总实重10改8，后补可关联量应为2而不是4。
- 防线：`FinanceInventoryCountCorrections::salesQuantities()` 顺序投影每次真实出库，减少实重从最近有效交付扣减，后来新交付独立保留；原单当前总实重再作上限。确认关联和后续销售实重更正均逐流水检查有效数量与已关联份额。受影响份额先追加撤回记录，原业务流水保持不变。
- 已验证事实：单交付红测 `.scratch/finance-64-sale-correction-red.log` 为3 tests、132 assertions、1 failure；多交付红测 `.scratch/finance-64-multi-sale-red.log` 为1 test、32 assertions、1 failure。最终专项 `.scratch/finance-64-final-targeted.log` 为27 tests、566 assertions通过，覆盖真实交付、实重更正、分次关联、撤回、跨月及成本重放；Spec 对销售有效量投影定点复审 clear。
- 尚未验证：业务库迁移、部署、原生页面和生产并发，专项不代表全仓通过。
- 后续建议：任何消耗历史业务份额的功能，都应同时验证“原事实量”“当前有效量”“此前已使用量”，并包含同一订单多次交付。

第69批发生记录：实物退货更正保留原验收 `confirmed` 与原流水，但其当前有效量已被冲回。原防线仅覆盖销售实重变化；退款草稿确认、盘点关联、旧售成本候选及已结月待补成本仍按原事实读取。现由 `FinanceCustomerReturnCorrections::assertCurrent` 统一约束新业务确认，候选排除撤销来源；历史查询保留；月结遗留把匹配的撤销事件作为解决证据，不伪造已补价。

- 已验证事实：`finance-69-stale-source-red.log` 为3 tests、110 assertions、3 failures，覆盖退款草稿及盘点候选；`finance-69-return-boundary.log` 为2 tests、81 assertions、1 failure，复现成本候选阻断；修复后 `finance-69-return-boundary-green.log` 为5 tests、233 assertions通过。
- 已验证事实：月结遗留红测 `finance-69-followup-red.log` 为1 test、41 assertions、1 failure；`finance-69-followup-green.log` 为3 tests、104 assertions通过，原月冻结快照不变。客户退货与纯成本回归 `finance-69-return-regression.log` 为32 tests、670 assertions通过。两轴复审发现均关闭。
- 完整回归：`finance-69-finance-full.log` 为336 tests、10935 assertions、1 skipped，退出码0；跳过项不计通过。业务库、部署及原生客户端未验收。
- 后续建议：新建“冲回原事实”能力时，除正向阻止已有下游，还需校验相反操作顺序、当前候选及旧月遗留，不把原单永久保留等同于原单仍可消耗。

## PIT-0066：新增持久化标识超过既有数据库字段长度

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第64批盘点反向实际写库存时发现持久化类型长度不兼容；保留当前业务锚点，修复并执行真实数据库路径后恢复关联页面工作。

- 状态：已防护；首次发生：2026-09-09；最近发生：2026-09-13；复发次数：1。
- 根因：将超出既有列上限的业务标识直接写入持久化字段，未在真实数据库路径验证字段长度。首次是 `stock_flow.order_type` 的 `varchar(30)`；本次是 `finance_entry.purpose`，`transfer_settlement_rebase_release` 超过该字段上限并被 MySQL 拒绝。
- 防线：库存层采用独立的 `finance_count_reverse` 类型（21字符）；互转重投影余额释放使用短用途 `transfer_rebase_release`。`FinanceBusinessWorkflowTest::test_transfer_out_correction_rebases_each_settlement_event_across_multiple_replacements` 走真实隔离库写入，覆盖该分录用途、两笔结清事实和连续更正，不以纯内存断言代替数据库契约。
- 已验证事实：本次隔离库红态为 1 test、28 assertions，`SQLSTATE[22001]` 报 `finance_entry.purpose` 数据截断；改用短用途并补齐测试清理后，绿色终态为 1 test、37 assertions通过。未扩大业务表字段长度。
- 尚未验证：业务库迁移与部署；没有据此称业务库已更新。
- 后续建议：新增持久化类型、用途或枚举值时检查既有字段约束，并执行至少一条完整真实写入路径。

## PIT-0065：展示快照随写入命令回传耗尽单据容量

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第62批 Standards 审查发现全SKU盘点容量边界；保留当前盘点交付位置，建立规模防线后继续财务一期后续核实与关联反向开发。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：0。
- 触发场景：建立250个SKU范围后，页面把商品名、账面数量、成本和截止事件编号等展示依据连同实盘输入全部回传。
- 根因：读模型直接用作写命令，未先检查新建范围的输入容量；范围占用后才触发通用65536字节上限。
- 防线：前端 `businessPayload` 同时投影预览与保存输入；服务端 `FinanceInventoryCounts::input()` 再次裁剪，`present()` 从原截止快照补齐可信展示；建立范围前检查合法数量及简短说明的输入容量，超大范围明确要求分批且不产生占用。
- 已验证事实：真实页面方法250行输出114404字节；前端容量测试稳定失败。PHP `.scratch/finance-62-capacity-red.log` 1 test、27 assertions复现保存超限。最终 `.scratch/finance-62-final-php.log` 12 tests、471 assertions通过，包含250SKU全部范围保存提交和750SKU建立前无占用拒绝；前端财务脚本171 tests通过。Standards复审已关闭本项。
- 尚未验证：原生长列表、键盘与触控、业务库迁移和部署。容量检查保证基本输入可提交，不保证任意多行都能填满1000字原因；较长说明仍受单据总容量限制，输入不静默截断。
- 后续建议：新建会占用业务资源的大范围操作，应同时覆盖建立、录入、提交与退出的容量边界。

第70批同根因复发：前端未来规则把完整计划及月份历史随命令提交。原盘点输入投影没有覆盖新类型。现新增九字段 `businessPayload`，预览和保存共用；容量 RED 为 `finance-70-payload-red.log`，GREEN 同组通过，最终前端205 tests通过。后端本批未放宽65536字节上限；保护位于前端仓库，详见其 PIT-0044。首次／最近发生仍为2026-09-09，本项累计复发次数现为1。修复后返回周期计划开发。

## PIT-0064：未知成本判断只覆盖正向数量

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第62批盘点实现的提前审查发现负向盘盈分录未继承未知成本标记；通过公开业务入口复现并修复，随后返回盘点页面开发。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：0。
- 触发场景：原可靠单位成本缺失，确认盘盈后生成负数量损耗对方分录，来源金额仍为 null。
- 根因：旧判断仅将正净数量的未知来源视为成本待确认，漏掉新盘盈的负净数量。
- 防线：`FinanceReports::pendingCost()` 对非零净份额保留未知状态，完全归零不误报；`test_inventory_count_unknown_gain_keeps_profit_and_inventory_cost_pending` 验证实际盘点确认后的利润、损耗和库存金额仍为 null。
- 已验证事实：`.scratch/finance-62-review-red-final.log` 复现预期 null、实际 0.00；`.scratch/finance-62-review-green.log` 盘点专项9 tests、345 assertions通过。
- 尚未验证：业务库迁移、部署和原生页面；本记录不代表第62批整体完成。
- 后续建议：新增反向或对方分录时，同时覆盖正净额、负净额和完全归零的状态判断。

## PIT-0063：混合短缺分摊把正常允差成本转给异常损失

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第61批 Spec 审查后，从已有公开业务场景修正预期并复现成本偏移，建立防线后恢复财务一期剩余流程。没有把功能最初不支持分次核实本身记作独立坑。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：0。
- 适用范围：到货异常损失、正常允差、分次确认及后续结算补价；相关问题：无。
- 触发场景：供应商报重100、实收90，其中损失4加2，另4经人工核实为正常允差，最终按100乘3元结算。
- 根因：用实收加已核实异常损失重新归一化全部来源成本，把正常允差的份额也摊到异常损失。
- 错误做法：用90加6为分母，得到损失18.75、库存281.25；总额守恒不能证明业务归属正确。
- 正确做法：用原完整到货数量基准划分异常损失份额，正常允差成本留在完好库存；本例损失18、库存282，两次损失各12和6，原实收90不变。
- 防线：`FinancePurchaseCosts::value()` 按原完整短缺量保存分摊基准，每笔损失保留独立成本来源；`test_arrival_shortage_allows_separate_loss_causes_and_keeps_the_remaining_difference_pending` 从真实到货、分次损失、剩余允差及正式结算验证两类成本和旧确认快照。
- 已验证事实：`.scratch/finance-61-normal-cost-red.log` 1 test、46 assertions，预期188实际187.5失败；修复后 `.scratch/finance-61-final-php.log` 36 tests、1107 assertions 通过，两轴复审关闭发现项。前端63 tests、source integrity通过，页面源码审查 ship、native partial。
- 尚未验证：业务库迁移45、部署、原生构建和真机流程；没有执行这些操作。
- 后续建议：新增混合分类时，分别验证每类成本去向，不能仅验证总金额与库存数量守恒。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-09 | 财务一期第61批 | 同一短缺包含多个异常原因及正常允差 | 原测试仅有全部短缺为异常损失，初始混合测试又沿用了旧分母推导预期。 |

## PIT-0062：Excel 单元格容量造成导出依据静默截断

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`用户级自定义 / prevent-repeat-pitfalls`、`Matt Pocock / diagnosing-bugs`（第58批自动调用）。
- 说明：财务报表导出测试确认截断后保留防线；原任务恢复位置为完成导出页面审查并继续原凭据追溯。已具备最小失败测试及库源码证据，诊断未重复猜测和插入日志。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：0。
- 适用范围：六类报表 XLSX、冻结月份原文及嵌套组成；相关问题：无。
- 触发场景：冻结依据或嵌套 JSON 超过 Excel 单元格的字符串容量。
- 根因：PhpSpreadsheet `DataType::checkString()` 按 `MAX_STRING_LENGTH=32767` 截断，并不会因数据不完整而使导出失败。
- 错误做法：仅验证文件可打开，把成功写入单元格当作全文保留。
- 正确做法：超过安全长度的内容分段写入“长文本续页”，原单元格保留明确工作表与单元格定位；所有业务原文显式按字符串写入。
- 防线：`FinanceReportExports::longText()` 保留按序片段，超出工作表总行数则明确失败；`FinanceBusinessWorkflowTest::test_report_export_preserves_long_frozen_evidence_and_never_executes_formula_text` 从正式导出接口生成实际 XLSX，重新打开后重组长文本并校验哈希，检查全部单元格都不是公式类型。
- 已验证事实：`.scratch/finance-58-long-red.log` 缺完整续页失败；修复后 `.scratch/finance-58-long-green.log` 1 test、369 assertions 通过；报表相关回归 `.scratch/finance-58-php-final.log` 6 tests、569 assertions 通过。
- 尚未验证：业务库部署、微信真机预览和手动转发。
- 后续建议：其他新增导出也应重新打开实际文件验证业务关键内容，不仅检查文件头或 HTTP 成功。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-09 | 财务一期第58批 | 导出包含超长原始依据的冻结月报 | 首个测试只验证短金额和文件可打开，没有覆盖库的单元格容量。 |

## PIT-0061：新增展示元数据改变历史核对的业务指纹

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（第55批自动调用）。
- 说明：已结月在途后续核实开发中先完成升级兼容防护，再恢复页面与业务验收；与 PIT-0023 的非业务字段进入指纹问题相关，但触发源为展示结构升级。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：0。
- 触发场景：升级前已保存正常月末核对，新版增加冻结金额和后续调整等展示字段后，没有资金变化也显示需要重新核对。
- 根因：对整个展示结果散列，将新增元数据误当作在途业务组成变化；同版本写入与读取的测试无法发现旧版快照兼容问题。
- 防线：业务组成计算指纹后才附加展示元数据；回归模拟旧版持久核对结构，要求无事实变化时保持 normal、真实补录时变为 needs_review。
- 已验证事实：旧版快照回归修复前 normal 对 needs_review 稳定失败；修复后在途专项4 tests、158 assertions通过。
- 尚未验证：业务库部署与小程序原生体验。
- 后续建议：扩展持久业务指纹前，使用已发布的历史结构检查版本兼容性；不把展示元数据加入相等性判断。

## PIT-0059：只追踪原引用，遗漏分类转移或调整后的派生来源

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（第53批自动调用）。
- 说明：统一遗留进度审查与关联边界复现后建立自动化防线，完成后继续资金核实及报表开发。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：1。
- 触发场景：待核实实物减少先缺来源，结账后确认归为损失，原成本引用清空而新损失去向仍缺成本。
- 根因：按原 reference 是否还有缺口判断完成，忽略 reclassify 的 to_reference 承接未知份额；责任核实被误当作成本核实。
- 防线：沿同仓库、SKU 的正式 reclassify 事件追踪后续引用，并合计各去向缺口及未知成本份额；回归覆盖重分类前后分次补齐。
- 已验证事实：对应回归在修复前稳定失败；修复后月结与遗留相关18 tests、703 assertions通过。
- 尚未验证：业务库部署、原生小程序操作及账户在途专项遗留核实。
- 后续建议：派生清单沿完整业务状态与后续引用判断，不以原记录消失或历史状态保持不变推断完成。

### 第59批补充（2026-09-09）

#### 报告来源

- 生成原因：工作流要求；主工作流：Matt Pocock。
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`用户级自定义 / prevent-repeat-pitfalls`、`Matt Pocock / diagnosing-bugs`（自动调用，沿用已读取流程）。
- 说明：报表来源追溯再次出现只查原引用的缺口，保留测试防线后恢复财务一期开发。

- 发生记录：费用300元已付250元，调减到180元产生退款70元；从原费用来源进入能看到调整单，却遗漏新退款来源的实际到账。原防线仅覆盖遗留成本投影，没有覆盖新报表追溯入口。
- 自动防线：`FinanceReportTrace` 对各入口共同迭代“原单与更正关系、派生来源、后续流水和调整单”直至来源集合稳定；输出仍逐单鉴权。原来源不被新来源替代。
- 已验证事实：`test_expense_reduction_after_partial_payment_creates_only_excess_refund_and_preserves_original_cost` 已结与未结两例在 `.scratch/finance-59-chain-red.log` 中均漏掉到账凭据；修复后 `.scratch/finance-59-chain-green.log` 3 tests、201 assertions通过，包含原月冻结报表不变。
- 尚未验证：业务库部署与微信原生追溯体验；后续建议：发现派生来源后必须继续追踪其后续处理，不能只把调整单加入展示列表。

## PIT-0060：周期暂估状态遗漏于统一核实进度的分支

日期：2026-09-09

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（第53批自动调用）。
- 说明：统一遗留进度审查与关联边界复现后建立自动化防线，完成后继续资金核实及报表开发。

- 状态：已防护；首次／最近发生：2026-09-09；复发次数：0。
- 触发场景：月结时周期费用未处理，结账后登记 estimated，再通过最终核实完成或核实最终金额为零。
- 根因：统一投影只识别 none/expense，业务月份合法且保留不变的 estimated 被遗漏，最终核实表中的证据也没有读取。
- 防线：将 estimated 纳入费用分支，按原类别核实明细判定部分或全部完成，不能要求历史 outcome 改名；回归走真实月结与最终零金额核实。
- 已验证事实：对应回归在修复前稳定失败；修复后月结与遗留相关18 tests、703 assertions通过。
- 尚未验证：业务库部署、原生小程序操作及账户在途专项遗留核实。
- 后续建议：派生清单沿完整业务状态与后续引用判断，不以原记录消失或历史状态保持不变推断完成。

## PIT-0056：仅从非零现金分录识别实际转账手续费

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（第51批自动调用）。
- 说明：月报审查问题已稳定复现，建立回归后返回报表及月结开发。

- 状态：已防护；首次／最近发生：2026-09-08；复发次数：0。
- 触发场景：到账金额为零、剩余在途全部代扣手续费。
- 根因：FinanceLedger 不保存零金额现金分录，月报按现金分录枚举业务，遗漏仍有在途和费用影响的合法单据。
- 防线：资金月报同时读取现金和在途影响，按单据去重识别手续费；互转测试覆盖到账零元及正常部分到账。
- 已验证事实：修复前失败；修复后相关月报及互转回归9 tests、327 assertions通过；两轴定点复核关闭原问题。
- 尚未验证：正式业务库部署与完整月结界面验收。
- 后续建议：新增期间汇总沿用同一分录、来源与期间口径，并保留这些边界用例。

## PIT-0057：反向费用使用更正记录自身字段分类

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（第51批自动调用）。
- 说明：月报审查问题已稳定复现，建立回归后返回报表及月结开发。

- 状态：已防护；首次／最近发生：2026-09-08；复发次数：0。
- 触发场景：设备付款100元更正为80元后读取费用分类。
- 根因：通用反向分录只保存原分录引用与原因，分类读取忽略原分录依据，形成设备180元与未分类负100元。
- 防线：费用月报沿租户内原费用分录追溯分类，保持冲正原因与原引用；回归断言唯一设备类别80元。
- 已验证事实：修复前失败；修复后相关月报及互转回归9 tests、327 assertions通过；两轴定点复核关闭原问题。
- 尚未验证：正式业务库部署与完整月结界面验收。
- 后续建议：新增期间汇总沿用同一分录、来源与期间口径，并保留这些边界用例。

## PIT-0058：历史成本金额和未知状态使用不同时间边界

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（第51批自动调用）。
- 说明：月报审查问题已稳定复现，建立回归后返回报表及月结开发。

- 状态：已防护；首次／最近发生：2026-09-08；复发次数：0。
- 触发场景：上月未知成本入库，本月确认金额后再读取未结上月库存。
- 根因：金额按所选月份成本影响累计，未知状态却读取当前来源金额，使缺失成本被显示为合法零。
- 防线：库存状态从初始金额和截至该月调整事件投影，显式零保留为已知；回归覆盖历史未知及当前已核实金额。
- 已验证事实：修复前失败；修复后相关月报及互转回归9 tests、327 assertions通过；两轴定点复核关闭原问题。
- 尚未验证：正式业务库部署与完整月结界面验收。
- 后续建议：新增期间汇总沿用同一分录、来源与期间口径，并保留这些边界用例。

## PIT-0054：汇总读取使用不可变原对象限制已更正的当前费用

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（本批按此顺序自动调用）。
- 说明：第50批 Spec 审查后用原费用更正测试复现；防护后恢复月结开发。

- 状态：已防护；首次／最近发生：2026-09-08；复发次数：0。
- 触发场景：费用已关联更正收款对象，随后读取任意月份的整店月结清单。
- 根因：把原账单 vendor_id 传给 current 的当前对象校验，历史身份被误用为当前读取约束。
- 防线：整店聚合调用 current(documentId, 0)，租户范围仍由领域查询保证；操作入口的当前对象校验不放宽。
- 已验证事实：原费用更正测试的未结／已结两组均稳定失败，修复后通过；第50最终相关 PHP 回归 21 tests、853 assertions。
- 尚未验证：业务库部署与完整六报表月结闭环。
- 后续建议：聚合读取与特定对象操作分别使用正确身份，不以不可变历史字段断言当前对象未改变。

## PIT-0055：统一未决清单把不同成本来源导向同一业务入口

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（本批按此顺序自动调用）。
- 说明：第50批旧售退回成本的处理路由回归；防护完成后返回月结与快照开发。

- 状态：已防护；首次／最近发生：2026-09-08；复发次数：0。
- 触发场景：启用承接了原售价日期已知或未知的旧售退回，原成本仍待核实。
- 根因：汇总依据共同的成本未决状态指定采购结算入口，忽略来源是旧售退回。
- 防线：按来源快照 cost_basis_pending 选择 legacy_return_cost，携带 stock_flow_id；前端精确读取该来源且保留已有单据和选择。
- 已验证事实：两组原启用承接测试修复前均定位到错误路由；修复后 PHP 最终相关回归 21 tests、853 assertions，前端 164 tests 与源码完整性检查通过。
- 尚未验证：小程序原生深链操作；HBuilderX 已退出。
- 后续建议：未决事项的业务分类同时约束展示、处理入口及来源身份。

## PIT-0052：期初投影遗漏已有核实字段，错误开放重新填写

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（按本批顺序自动调用）。
- 说明：第49批审查在途投影，直接入口回归已定位字段映射遗漏，未增加临时日志；防护完成后继续月结开发。

- 状态：已防护；首次／最近发生：2026-09-08；复发次数：0。
- 根因：将期初额外手续费统一投影为 `null`，遗漏合法的 `details.additional_fee`，导致已知金额也可被新核对输入替代。
- 防线：`FinanceTransitReviews::atMonth` 优先保留已知额外手续费；仅缺失时允许补核实，确认结果不会用客户端冲突值覆盖已知值。
- 已验证事实：`test_transit_reconciliation_preserves_known_opening_extra_fee_and_zero_does_not_hide_unknown_facts` 修复前为 `null` 对预期 `5.00`；修复后原值5不受输入0覆盖。最终在途、互转、账户及短款专项 11 tests、430 assertions 通过。
- 尚未验证：当前小程序实际构建、原生布局与触控，HBuilderX 已退出。
- 后续建议：历史来源的投影按实际期初字段逐项映射，不能以“历史来源”统一推断未知。

第50批补充（2026-09-08）：同类期初字段映射错误在待认领月结聚合中复现，合法 `account_inclusion` 值为字符串 `confirmed`，误按整数1判断导致已核实资金被阻断。改用正式枚举，新增 `test_period_checklist_accepts_formal_opening_unclaimed_acknowledgement_and_keeps_unknown_date_blocking` 验证合法已知日期为提醒、日期未知仍阻断；修复前稳定失败，最终相关 PHP 21 tests、853 assertions 通过。本次来源为 Matt Pocock 的 implement、tdd、code-review、diagnosing-bugs，用户级 impeccable、prevent-repeat-pitfalls；仍未完成原生与业务库验收。

## PIT-0053：零余额状态绕过业务事实完整性的核实门槛

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（按本批顺序自动调用）。
- 说明：与 PIT-0052 同批，但根因为完成状态推导遗漏知识完整性，独立记录；防护后继续月结。

- 状态：已防护；首次／最近发生：2026-09-08；复发次数：0。
- 根因：没有核对历史时，只依据剩余在途为零自动判为已结清，忽略来源组成和手续费仍可能未知，绕过普通月结的核实要求。
- 防线：自动已结清必须同时满足组成已知、额外手续费已知、余额为零；其他情形仍保留未核对。已知金额和知识状态分别参与判定。
- 已验证事实：上述真实入口回归第二阶段复现 `ordinary_close_allowed=true` 对预期false；修复后零余额未知来源仍为 `unreviewed`。最终专项 11 tests、430 assertions 通过。
- 尚未验证：完整月结集成属后续批次，不能把本批允许标志视为月结全流程已完成；实际构建与原生验收仍受限。
- 后续建议：任何“余额清零即完成”的状态转换，单独检查证据与组成是否仍有未知项。

## PIT-0051：核对差额处置只限制原记录金额，忽略后续账面与核对版本

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill（本批顺序）：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第48批核对与现金短款审查发现可重复处置原差额；通过真实确认入口复现并建立防线，随后返回月末核对开发。确定性回归已隔离到原核对金额校验，因此未追加临时日志或重复排查基础设施。

- 状态：已防护
- 首次发生／最近发生：2026-09-08
- 复发次数：0
- 适用范围：基于核对快照分次处理损失，以及跨核对版本的关联更正。

### 根因与触发场景

原实现只限制同一核对记录下的累计损失。其他业务补录改变原截点账面后，旧差额仍可登记损失；原差额分两次核对处理完，再调增第一笔，也会重复消耗已经处理的短款。仅检查最新核对 ID 不能替代账面基准校验，仅按原记录汇总也不能约束后续版本。

### 防线与验证

- 自动化防线：`FinanceBusinessWorkflowTest::test_cash_shortage_cannot_reuse_stale_or_superseded_difference` 覆盖补录改变账面，以及原差额100分别处理50后调增旧损失为100的真实事务。
- 架构防线：最新核对的新登记及调增，比较当前截点账面与原账面加本核对已关联处置的净变化；其他业务变动要求重核对。已有后续核对的旧损失只允许同来源不增额纠错，调增必须从最新核对处理。闭月比较沿用实际入账月份过滤。
- 已验证事实：两个样本修复前均失败；修复后核对、短款、闭月、权限与更正专项 6 tests、214 assertions 通过。早期综合回归 25 tests、890 assertions 发生在修复前，不能代表最终全仓回归。
- 尚未验证：当前源码实际小程序编译和原生布局、触控；HBuilderX 已退出，旧产物不能替代本批验收。
- 后续建议：其他以历史快照为依据的分次处置，同步校验基准变化及后续版本的消耗，避免只校验单记录剩余额度。

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-08 | 财务账户核对第48批 | 补录后旧差额登记、跨核对调增原损失 | 测试原来只覆盖同一核对内的分次和更正，没有引入其他业务或下一版核对。 |

## PIT-0050：跨主体更正用新用途类型筛选原单据的影响对象

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill（本批顺序）：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`（自动调用）。
- 说明：第47批跨用途认领审查确认原客户逾期关联历史遗漏；建立真实事务测试后恢复认领纠错开发。

- 状态：已防护
- 首次发生／最近发生：2026-09-08
- 复发次数：0
- 适用范围：允许业务类型或主体类型变化的关联更正。

### 根因

确认入口原先假定更正前后主体类型相同，使用新用途的 `policy.subject` 决定是否收集新旧客户。客户认领改为供应商退款时，原客户欠款正确恢复，但整组客户观察被跳过；反方向可能把供应商 ID 当成客户 ID。后来列表刷新只能补普通观察，不能代替同事务的更正关联与核销时间说明。

### 防线与验证

- 自动化防线：`FinanceBusinessWorkflowTest::test_unclaimed_customer_to_vendor_correction_records_reopened_customer_overdue_history` 通过真实到账、客户认领及供应商退款更正，断言原客户事件保留本次更正 `document_id`、客户 ID 和非空 `timing`。
- 已验证事实：修复前该事件为 `null`；分别按新旧单据自己的类型筛选客户并去重后，认领与逾期回归 15 tests、489 assertions 通过。原子跨用途/通用更正回归修复前另有 20 tests、702 assertions 通过，不能把该早期回执写成最终全仓验证。
- 架构防线：关联操作的两端各自解释自己的主体类型，再合并受影响对象；前后观察仍调用现有同事务原语，不增加独立提交边界。
- 尚未验证：微信真机及本批实际编译。HBuilderX 桌面进程已退出，本批构建明确失败；源码检查不能替代编译。
- 后续建议：将来允许其他业务跨类型更正时，同步核验两端对象、权限及观察关系，避免只恢复余额遗漏关联历史。

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-08 | 财务认领跨用途纠错第47批 | 客户收款认领改为供应商退款认领 | 原测试只在同主体类型内更正，没有验证跨客户／供应商身份空间的关联观察。 |
## PIT-0049：独立查看权限没有贯通关联记录与私密材料

日期：2026-09-08

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill（按本批顺序）：`Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`用户级自定义 / prevent-repeat-pitfalls`、`Matt Pocock / diagnosing-bugs`（均自动调用）。
- 说明：第40批审查工资读写权限后，以公开读取入口稳定复现并补防线，完成后继续工资开发。确定性调用栈已指向读取误用写权限，因此跳过重复假设和临时日志阶段；Codegraph 为代码工具，不是 Skill。

- 状态：已防护
- 首次发生：2026-09-08
- 最近发生：2026-09-08
- 复发次数：0
- 适用范围：工资结果、工资发放及工资私密材料读取
- 相关问题：PIT-0036、PIT-0038（均为不同根因）

### 触发场景与根因

员工只有 `finance.salary.view`。工资结果允许查看，但关联发放及材料仍通过经办授权，要求 `finance.salary.prepare`，导致合法查看被拒。原材料保护测试同时授予查看和经办，未覆盖两种权限独立存在。

### 错误做法与正确做法

不要把独立查看权限的例外只加在主记录详情。工资结果、发放及材料内容统一使用读取策略；上传、保存和退回仍要求经办，正式确认仍只允许店主。

### 防线与验证

- 自动化防线：`FinanceBusinessWorkflowTest::test_salary_view_only_can_read_results_payments_and_private_material_without_preparation_rights` 覆盖两个工资类型的详情、只读能力、材料读取、上传与退回拒绝，以及撤权后材料不可读。
- 架构防线：`FinanceDocumentPolicy::read` 统一两类工资读取；材料仅在工资分支使用该读取策略，不放宽上传授权。
- 已验证：新增测试先在 `FinanceEvidence::content` 报“没有对应财务业务权限”；修复后连同工资确认和原私密材料测试共4项、163条断言通过。
- 尚未验证：真实小程序读屏、触控及私密图片展示；测试通过不代表这些验收完成。
- 后续建议：新增只读角色时，同时覆盖关联记录及附件读取，独立测试查看、经办和确认。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-08 | 财务一期第40批 | 工资仅查看者读取附件及发放 | 旧凭证测试同时拥有经办与查看权限 |

## PIT-0048：取消清空当前明细后历史恢复错误依赖主数据现版

- 状态：已防护
- 首次发生：2026-09-08
- 最近发生：2026-09-08
- 复发次数：0
- 适用范围：周期费用取消与沿原费用重述
- 相关问题：PIT-0047（月份联动，根因不同）

### 报告来源

- 生成原因：工作流要求
- 主工作流：用户级自定义
- 实际使用的 Skill：
  - `Matt Pocock / implement`（自动调用）
  - `Matt Pocock / tdd`（自动调用）
  - `用户级自定义 / impeccable`（自动调用）
  - `Matt Pocock / code-review`（自动调用）
  - `用户级自定义 / prevent-repeat-pitfalls`（自动调用）
  - `Matt Pocock / diagnosing-bugs`（自动调用）
- 说明：第37批两轴审查发现类别版本路径，公开业务测试复现后补自动防线，再返回本批验收与财务一期开发。

### 触发场景

周期费用已确认，后来被取消为零并重新恢复待核实。期间费用类别合法停用再启用，类别版本由1变3；重述最终金额时报“费用类别已变化”，重新加载计划仍无法继续。

### 根因

取消把当前费用明细置为空，重述仍从计划创建时取类别版本，通用明细校验找不到历史行而回退到当前主数据。计划版本、原费用类别快照与类别现版三个身份被混用。

### 错误做法

用已取消的空明细承接历史上下文，或让已确认业务的关联恢复强制追随可变主数据。

### 正确做法

周期重述限定原计划、原对象、原月份和原类别，并从不可变原费用账单取得类别快照及版本。普通新增费用继续校验当前启用类别，不放宽该入口。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_recurring_paid_expense_can_be_cancelled_and_restated_without_recreating_payment` 覆盖类别1→停用2→启用3后的取消、恢复、重述；同时核对原付款不变、费用及应付/退款差额。
- 架构防线：`FinanceExpenseAdjustments::recurring` 的类别版本来自原账单，私有 `apply` 仅在专用周期路径使用原类别上下文。
- 已验证事实：新增类别启停步骤后，同一用例在 `FinancePreview::calculate` 报类别变化；修复后连同零额暂估取消共2项测试、87条断言通过。
- 尚未验证：小程序原生触控、键盘和读屏；全仓既有失败未修复。
- 后续建议：其他取消后恢复路径明确区分当前投影与不可变原记录，避免空投影丢失历史身份。

### 发生记录

| 日期 | 任务或 Issue | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-08 | 财务一期第37批 | 周期取消后类别启停导致重述无法继续 | 首条往返用例未改变主数据版本，只验证费用和付款余额。 |

## PIT-0047：原业务范围改变后派生待办仍保留已处理状态

- 状态：已防护
- 首次发生：2026-09-08
- 最近发生：2026-09-08
- 复发次数：0
- 适用范围：周期费用月份结果与普通费用关联调整
- 相关问题：无

### 报告来源

- 生成原因：工作流要求
- 主工作流：用户级自定义
- 实际使用的 Skill：
  - `Matt Pocock / implement`（自动调用）
  - `Matt Pocock / tdd`（自动调用）
  - `用户级自定义 / impeccable`（自动调用）
  - `Matt Pocock / code-review`（自动调用）
  - `用户级自定义 / prevent-repeat-pitfalls`（自动调用）
  - `Matt Pocock / diagnosing-bugs`（自动调用）
- 说明：工程审查发现原费用调整与周期待办联动缺口，经公开业务接口复现后补充边界防护，继续周期更正开发。

### 触发场景

周期月份已确认800元费用，随后从普通费用调整把归属移到别月或取消，原月份仍显示已处理且不能重新处理。

### 根因

周期结果以初次费用确认记录为来源，但原费用调整没有检查其下游周期范围是否仍成立。

### 错误做法

允许通用业务更正改变派生待办的对象、类别、月份或有效费用存在性，却保留派生状态不变。

### 正确做法

普通调整限于原周期范围内的正金额和说明。改变范围或取消须使用关联周期更正，同时处理月份状态和财务差额，并保留原事实。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_recurring_month_requires_expense_estimate_or_reasoned_none_and_cannot_be_consumed_twice` 的 final/estimated 两条路径，覆盖移月拒绝、取消拒绝及800元调整为900元成功。
- 架构防线：`FinanceExpenseAdjustments::confirm` 对周期来源按原计划对象、类别、受益月及正金额校验。
- 已验证：原实现实际确认移月，测试红；修复后费用专项18 tests、812 assertions通过，Spec reviewer关闭P2。
- 尚未验证：周期专用关联更正与月结阻断集成尚未完成；此处防护不等于这些功能已交付。
- 后续建议：实现周期专用更正时，同一账簿事务内更新合法差额与当前月份结果，原月份历史不得覆盖。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-08 | 财务一期第35批 | 普通费用更正移走原周期已确认费用 | 既有费用更正测试没有周期派生待办 |

## PIT-0046：财务启用只加载期初成本，遗漏截点之后的实物去向

- 状态：已防护
- 首次发生：2026-09-08
- 最近发生：2026-09-08
- 复发次数：0
- 适用范围：财务期初确认与库存成本承接
- 相关问题：PIT-0032（截点数量校验）、PIT-0044（实际日期重放）

### 触发场景

期初截点库存 100 单位、历史成本 200 元，正式确认财务前已销售 10 单位。实物库存为 90，但启用后的成本账仍保留 100 单位、200 元。

### 根因

未启用门店的库存钩子按设计跳过新成本账；期初确认仅创建截点来源，没有承接截点至确认之间已发生的实物流水。已有截点测试只校验期初保存数量，没有验证正式成本余额与销售去向。

### 错误做法

把期初数量改成当前量掩盖缺失；或再次执行库存出入库；或将无法配对的旧调拨当成已知零成本入库。

### 正确做法

在期初确认的同一事务和门店锁内承接原流水，沿原事实日期建立成本去向，校验成本数量与现存量一致；原销售退回恢复原份额，截点前退回的未知成本明确保留。无可靠入库成本、实物去向或调拨配对时阻断启用并回滚正式成本。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest` 的 `test_activation_carries_cutoff_stock_then_replays_intervening_sales_cost_without_moving_stock_again`、`test_activation_keeps_return_from_pre_cutoff_sale_unpriced_without_borrowing_opening_unit_cost`、`test_activation_rejects_unverified_inbound_cost_or_unpaired_stock_destination_atomically`。
- 架构防线：`FinanceCostBootstrap::withinTransaction`、既有 `FinanceCostLedger` 幂等事件、期初确认外层事务与 SKU 锁。
- 已验证：原红灯为预期 90、实际 100；增加交付更正退回后预期 188 元、实际未知；修复后期初与启用边界 39 tests、767 assertions 通过，两轴复审关闭发现项。
- 尚未验证：真实旧流水的完整迁移；旧入库、调拨核实入口以及旧售退回后续补价仍在开发，正式租户名单保持关闭。
- 后续建议：新增承接种类须覆盖启用前后连续事件，不只校验期初资料保存成功。
- 本次来源：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / prevent-repeat-pitfalls`、`Matt Pocock / code-review`；防重复工作流保存证据后继续财务一期开发。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-08 | 财务一期启用衔接 | 期初数量正确但确认后的成本未减去已销售部分 | 既有测试验证截点数量，未贯通成本余额和启用后下一次销售。 |

## PIT-0044：补录历史实物流仍按确认顺序计算移动平均成本

- 状态：已防护
- 首次发生：2026-09-08
- 最近发生：2026-09-08
- 复发次数：2
- 适用范围：财务成本事件、采购实际退货和受其影响的后续库存去向
- 相关问题：PIT-0032（历史截点与实时状态混淆，计算层不同）

### 触发场景

先录入第一天到货 100 × 2 元及第三天到货 100 × 4 元，再补录第二天实际退离 10。原实现按确认时均价扣 30 元，实际应扣 20 元。

### 根因

成本事件保留了实际日期，但计算器只读取当前持仓。实际日期只用于期间归属，没有用于实物事件排序，后到货的成本参与了更早的退货。

返回入库和损失确认再次暴露历史重放边界：事件中间结果在后续补价前生成，新确认入口误将其作为最终成本快照；成本持仓已更新，业务结果却保留旧金额或待确认标记。

启用成本承接又把实物流水录入时间当作原交付日期，绕过正式交付入口已建立的事实日期规则；期初数量回退也会把晚录的截点前旧交付误算成本期变动。

### 错误做法

仅修改成本流水的业务日期，继续用当前混合成本计算历史退货。

### 正确做法

同一 SKU 出现乱序实物事件时，从期初按实际日期重放实物变化，同日保留确认顺序；后续成本确认沿重建后的来源去向补差。逐事件计算分录，再按来源、仓库、SKU、去向和业务日期比较原累计分录，只追加差额。后续销售及其库存对应差额均保留销售日期，已关闭期间通过现有规则前滚。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_backdated_purchase_return_replays_actual_cost_order_and_keeps_later_stock`，覆盖 20 元退货、后续销售由 285 重算为 290、原日期、幂等及后续采购价格调整。
- 架构防线：`FinanceCostLedger::recordWithinTransaction` 检测乱序并在同一账套锁与事务内重放；统一 `applyEvent`，不另建退货专用估价算法。
- 启用衔接防线：`FinanceStockFactTime::resolve` 供截点数量与成本承接共用，交付及运输损耗读取原交付事件日期。`FinanceBusinessWorkflowTest::test_activation_carries_cutoff_stock_then_replays_intervening_sales_cost_without_moving_stock_again` 的运输损耗样本修改前预期 `2026-08-11`、实际 `2026-09-08`；修复后启用边界 8 tests、245 assertions 通过。必要日期或来源无法核实则阻断，不按录入日补造事实。
- 返回和损失防线：`FinancePurchaseReturnResolutions` 从重放完成后的原退货去向差额或独立损失去向取得成本，不保存事件中间估价。`test_purchase_return_dispute_restores_original_cost_to_actual_warehouse_and_reclassifies_loss_without_stock_change` 覆盖已知暂估及未知成本补价、跨仓恢复和确认快照；初次红灯为预期 12、实际 8，扩展后与定向负量用例共 3 tests、132 assertions 通过。第23批财务、销售结算与仓库专项 192 tests、4418 assertions、1 skipped；两轴复审已关闭本次 P2，跳过项不计通过。
- 2026-09-09 客户实物退回扩展：同一原销售可包含多次交付，整单去向前后差会混入后续交付重估，不能视为本次退回成本；直接读取本次 `restore` 的中间结果又会漏掉本段最后执行的补价。`FinanceCustomerReturns::returnedCost()` 使用本次真实退回的来源份额，按重放后的原来源总价值核算；全部重算影响仍另列 `cost_impacts`。两条红测分别为预期4、实际5.666668和补价后预期6、实际4，保存在 `.scratch/finance-65-return-cost-red.log` 与 `.scratch/finance-65-return-reprice-red.log`。最终专项 `.scratch/finance-65-return-final-targeted.log` 为7 tests、289 assertions通过。来源：Matt Pocock / implement、tdd、code-review、diagnosing-bugs；用户级自定义 / impeccable、prevent-repeat-pitfalls。该段更新相同重放边界问题，完成后返回第65批页面与整体验证；尚未验证业务库和生产并发。
- 已验证：最小用例修改前预期 20、实际 30；修改后与负库存用例合计 2 tests、87 assertions 通过。
- 已验证补充：跨月测试保护上月库存 180、本月销售及剩余库存各 290，专项 2 tests、88 assertions；串行财务、销售、仓库及负库存回归 195 tests、4329 assertions、1 skipped。两轴复审已关闭跨月库存补差问题；一次并发干扰测试库的运行已作废，不计入通过结果。
- 尚未验证：业务库迁移和真机验收。
- 后续建议：新增实物事件复用成本事件入口，继续覆盖历史调拨与退回的依赖顺序。
- 本次来源：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / prevent-repeat-pitfalls`、`Matt Pocock / code-review`。因防重复工作流保存红绿证据，完成后继续采购退货开发。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-08 | 财务一期采购实际退货 | 到货后补录更早退离 | 原成本测试覆盖混批均价和后补价格，未覆盖实物事件录入顺序不同于发生顺序。 |
| 2026-09-08 | 财务一期退货争议处置 | 历史返回或损失的确认快照保留补价前成本 | 既有防线验证最终持仓及实际退离，未验证返回、损失入口对重放中间结果的消费。 |
| 2026-09-08 | 财务一期启用成本承接 | 晚录交付或运输损耗错入录入月 | 正式交付入口已沿原事件日期，新增启用入口自行采用实物流水创建时间。 |

## PIT-0045：新增允许负库存的实物入口遗漏异常待办

- 状态：已防护
- 首次发生：2026-09-08
- 最近发生：2026-09-08
- 复发次数：1
- 适用范围：采购实际退货及返回入库、负库存来源和最高权限异常待办
- 相关问题：无

### 触发场景

商品实际从漏记调拨的空仓退离，原实现保存负现存量和成本缺口，但既有负库存待办列表为空。

### 根因

新实物路径只接入库存及成本服务，没有把新增负量连接到既有归因和待办模型；成本缺口记录不能替代可处理的业务待办。

返回入库阶段再次暴露同一归因链路缺口：普通后续入库只补平等待入库的来源，不能表达原退货实际运回对其自身开放来源的定向恢复。

### 错误做法

把确认结果中的负库存提示视为异常处理已完成，或把已有负量全部重复归因到本次退货。

### 正确做法

按本次操作前后负现存量的增加额，在同一事务创建原始归因和待办，引用退货单及明细。非交付来源使用空的交付明细引用，保留真实交付明细的唯一约束。

原商品实际返回同仓时，从原退货不可变确认结果定位归因，优先核减该来源；恢复量取实际负现存量减少额，不删除来源记录，并追加自动处理动作。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_purchase_return_retains_unknown_cost_and_negative_source_and_rejects_duplicate_or_excess_quantity` 通过公开待办接口验证来源可见，连续两次退货分别产生 10 的未解决量。
- 架构防线：`StockService::outboundFinancePurchaseReturnWithinTransaction` 在实物流和成本入账事务内调用 `NegativeInventoryLogic::purchaseReturnWithinTransaction`，任一步失败整体回滚。
- 已验证：修改前待办预期 1 条、实际 0 条；修改后与历史成本用例合计 2 tests、87 assertions 通过。
- 已验证补充：串行财务、销售、仓库及负库存回归 195 tests、4329 assertions、1 skipped；两轴复审确认来源、事务和空交付引用约束。
- 返回入库防线：`FinanceBusinessWorkflowTest::test_return_back_heals_its_own_negative_source_before_an_earlier_unrelated_return` 初次预期原来源剩余 0、实际仍为 10；接入定向补平后，与争议处置及成本专项合计 15 tests、164 assertions 通过。第23批扩大专项最终 192 tests、4418 assertions、1 skipped，两轴复审关闭问题；前述 195 项为原退离批次证据，不能与本次计数混用。
- 尚未验证：业务库迁移和真机验收。
- 后续建议：所有新增的允许真实负库存的入口都必须验证归因与待办，不用成本缺口代替业务闭环。
- 本次来源：与 PIT-0044 相同。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-08 | 财务一期采购实际退货 | 空仓真实退离 | 初始测试只断言库存与成本未知，未断言用户可访问的异常待办。 |
| 2026-09-08 | 财务一期退货争议返回 | 第二次退离实际返回，原开放负量没有关闭 | 原防线只覆盖负量创建，未覆盖同源返回对开放及保留状态的定向恢复。 |

## PIT-0043：提前截断精度改变金额或阈值判断

第62批补充（2026-09-09）：盘盈来源金额直接截断到分，截止300件共800元、盘盈1件时，库存成本应为1202.67元（含盘点后100件400元入库），却为1202.66元。按现有来源金额精度先四舍五入到分，并保留快照中的六位差额原额。`test_inventory_count_confirmation_applies_cutoff_difference_at_original_cost_after_later_inbound` 的 `rounded_gain` 数据集在 `.scratch/finance-62-review-red-final.log` 稳定复现，修复后盘点专项9 tests、345 assertions通过。最近发生2026-09-09，累计复发次数2；本次实际来源依次为 Matt Pocock / implement、tdd，用户级自定义 / impeccable，Matt Pocock / code-review、diagnosing-bugs，用户级自定义 / prevent-repeat-pitfalls。完成防线后返回盘点前端；业务库与原生仍未验收。

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-09
- 复发次数：2
- 适用范围：已启用财务的销售逐行金额计算、小程序显示、采购重量差阈值判断及盘盈金额舍入
- 相关问题：无

### 触发场景

客户结算重量 2.0050 斤、单价 1.23 元，采用逐行四舍五入到分。

采购差量 10000.0001、基数 1000000，比例略大于 1%，显示截断到六位后仍为 1.000000%，不得因此降低复核权限。

### 根因

`bcmul(..., 2)` 是截断运算，不能表达四舍五入；前端多个金额入口如果只传行数据、遗漏当前精度，也会继续走旧账套截断分支。

同一根因在采购复核复发：业务判断前先把精确比值截为显示精度，再比较阈值，丢失了决定是否超限的尾数。

### 错误做法

直接把保留两位小数当作四舍五入，或只修改总额而保留商品行头的旧算法。

### 正确做法

先保留六位原额，再按本次规则逐行四舍五入；显示与合计统一使用相同规则。原额、自动取整差和人工抹零分别保留，旧正式快照不重算。

比例阈值使用精确交叉相乘比较 `绝对差 × 100` 与 `基数 × 比例上限`，显示格式只影响展示。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_sales_precision_rounds_each_line_and_preserves_automatic_difference_separately_from_manual_rounding` 初次实际 2.46、预期 2.47，修正后通过；另覆盖默认版本冲突、专项权限和撤权重试。
- 自动化防线补充：`FinancePurchaseDifferenceTest::test_review_threshold_uses_exact_ratio_before_display_rounding` 在旧实现中应升级但返回 false；改为八位精度交叉乘法后通过，专项 2 tests、11 assertions。Standards 轴确认覆盖了恰好相等和显示相同但真实超限两个边界。
- 架构防线：`FinanceSalesPrecision::amount` 统一后端计算；小程序 `calculateSettlementLineAmount` 统一行头、行内与合计，`scripts/finance-sales.test.mjs` 实际执行两处组件方法验证结果一致。
- 已验证：精度专项 4 tests、125 assertions；包含财务及销售结算的回归 121 tests、2589 assertions；前端精度与销售流程 25 tests。
- 尚未验证：业务库迁移、微信实际排版和触控验收。
- 后续建议：采购金额计算复用同等精确的逐行四舍五入规则，不能用截断代替。
- 本次来源：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / prevent-repeat-pitfalls`、`Matt Pocock / code-review`；依照防重复工作流保留验证证据，完成后继续分次交付结算开发。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 财务一期销售精度 | 新精度遇到第三位及以下小数，后端和旧行头显示截断 | 旧测试主要覆盖能精确到分的乘积，没有跨显示入口验证本次规则。 |
| 2026-09-08 | 财务一期采购结算 | 极小幅度超限被截断后的比例掩盖 | 原防线只覆盖金额四舍五入，未覆盖使用显示精度比较权限门槛；已新增真实规则函数红绿测试。 |

## PIT-0042：用订单首次日期代替分次实际交付日期

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：1
- 适用范围：客户对账交付附表、按期间汇总的交付查询、旧整单收入和默认付款日、后续交付后的原版本覆盖量
- 相关问题：PIT-0028（来源身份选择）

### 触发场景

同一订单同一商品在两天分别交付 2 斤和 3 斤，查询第二天的对账交付附表。

### 根因

履约会复用销售订单并累计销售行实重，但订单日期仍为首次交付。按订单日期筛选累计数量无法表达每次交付的时间。

### 错误做法

使用 `sales_order.datetimesingle` 与 `order_goods.base_quantity` 直接生成按日交付附表。

### 正确做法

按 `fulfillment_delivery_event.delivered_time` 和 `fulfillment_delivery_item` 保存实际日期、重量及来源，先关联正式版本覆盖，再筛对账期间；实重纠错使用独立纠错记录。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_statement_keeps_each_partial_delivery_on_its_actual_day`；首次执行实际返回 0 项而预期 1 项，修正后返回第二天 3 斤。
- 架构防线：`FinanceStatementSnapshot::pendingDeliveries` 从交付事件读取，不从销售累计行推测日期。
- 复发防线：`FinanceLegacySales` 以真实交付日期建立初次结算上下文；跨日首次转入逐项分次结算，已有原正式版遇后来交付时保留原覆盖量，`SalesSettlementLogic` 更新金额时不覆盖后来累计实交。上下文在修改库存及商品行之前取得并传入记账，避免把自己的合法实重纠错当作后来交付。
- 2026-09-07 已验证：初次失败的默认付款日为9月12日、期望9月6日；跨日整单错误地确认250元；后来交付使原版实交从2斤变为5斤。修复后新增日期、分次引导、旧版调价及合法实重纠错测试通过，专项5 tests、155 assertions；原禁止覆盖量变更不会生成无法确认的重量差待办。
- 本轮验证来源：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / prevent-repeat-pitfalls`、`Matt Pocock / code-review`、`用户级自定义 / impeccable`。相同根因扩展到旧金额入口，更新原记录而不新建；完成后返回财务第十四批编译与采购开发。
- 已验证：上述隔离数据库回归完成红绿验证；本批前端来源标识使用真实交付明细 ID。
- 尚未验证：生产迁移和微信真机显示。
- 后续建议：采购与履约报表也应先明确事件粒度，再按期间汇总。
- 本次来源：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`Matt Pocock / prevent-repeat-pitfalls`、`Matt Pocock / diagnosing-bugs`；本记录依照防重复工作流保存，完成后继续财务对账开发。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 财务第九批 | 跨日追加交付在对账期间丢失 | 首轮用例只有一次交付，未覆盖订单身份复用后的事件日期。 |
| 2026-09-07 | 财务第十四批 | 旧整单首次金额确认仍取开单日，后来交付累计量又覆盖旧版展示 | 原防线仅保护对账附表，新分次入口也未覆盖旧金额确认和旧版调价路径。 |

## PIT-0041：已消费预收整笔替换导致合法纠错无路可走

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：原收款关联更正与已使用预收
- 相关问题：PIT-0038（通用来源边界）

### 触发场景

预收 100 元已真实退回 20 元，仅更正原到账的错录账户也被阻止；真实退回不能当作不存在而撤销。

### 根因

更正统一撤销旧派生来源再新建，但后续业务已经引用旧来源，余额保护把合法录错更正也变成不可恢复的阻断。

### 错误做法

要求用户先撤销真实退回，或覆盖旧来源及既有处理关系。

### 正确做法

保留已消费预收身份，追加核定金额及业务日期版本；新金额覆盖已消费额，客户保持一致，日期不晚于有效的使用或真实退回日期。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_consumed_advance_keeps_its_reference_when_original_receipt_account_amount_or_date_is_corrected` 红绿覆盖账户、日期、金额及连续更正；来源原行保持 100，退回 20 后核定 120 再改 110，余额依次为 100、90，实际交易总数始终为 2。
- 架构防线：`FinanceAdvanceRevisions` 追加版本，`FinanceLedger` 当前投影，通用更正不重复撤销前次金额差额。
- 已验证：专项数据库回归及标准、规格定向复核通过。
- 尚未验证：生产数据迁移与真机操作。
- 后续建议：其他已消费派生余额的纠错先明确来源身份及有效版本，避免无操作路径的保护。
- 本次来源：`Matt Pocock / implement`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / tdd`、`Matt Pocock / prevent-repeat-pitfalls`；完成防线后继续原收款退回交付。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 财务第八批 | 真实退回后的原预收更正 | 旧测试只覆盖未消费来源整体替换，没有验证后续事实不能撤销时的合法纠错路径。 |

## PIT-0039：JSON 数字与绑定字符串比较导致额度漏计

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：财务 JSON 快照中的主体、真实交易关联查询
- 相关问题：无

### 触发场景

原收款已退回 200 元，再申请超过剩余 800 元的退回。

### 根因

`JSON_EXTRACT` 返回 JSON 数字，ORM 参数按字符串绑定，直接等值比较没有匹配原退回记录，额度累计变为零。

### 错误做法

假定 JSON 数字与普通 SQL 整数列使用同样的隐式类型转换。

### 正确做法

对已受正整数校验的业务身份，将 JSON 解包并显式 CAST AS UNSIGNED 后比较。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_partial_receipt_return_reopens_only_selected_debt_and_preserves_receipt_and_source_dates`；修复前错误接受 801 元，修复后拒绝，余额和资金不变，并覆盖客户记录筛选。
- 已验证：真实数据库红绿测试通过。
- 尚未验证：不同 MySQL 发行版本；部署前仍需执行迁移和关联查询验收。
- 后续建议：JSON 身份查询保持显式类型，不靠隐式比较。
- 本次来源：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / prevent-repeat-pitfalls`、`Matt Pocock / code-review`；完成防线后继续原收款退回批次。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 财务第八批 | 原退回未计入可退额度 | 原核销测试按普通列关联，未覆盖 JSON 交易身份查询。 |

## PIT-0040：构建出的子查询被 ORM 当作普通值

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：财务更正后有效单据集合
- 相关问题：PIT-0039（关联查询结果漏计）

### 触发场景

退回中的应收恢复由 200 更正为 250 元，查询原收款剩余可退额度。

### 根因

把 `buildSql()` 返回的子查询字符串交给 `whereNotIn` 值参数，旧更正记录没有被有效排除，累计同时包含 200 和 250。

### 错误做法

把 SQL 表达式字符串与查询值参数互换。

### 正确做法

使用明确的 SQL 表达式入口嵌入 Query Builder 构建的子查询；身份已由服务器租户上下文约束，不拼接客户端字符串。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_receipt_return_and_its_correction_share_original_capacity_without_second_cash_transaction`；修复前剩余为 550，修复后为 750。原收款版本选择也覆盖更正后的有效记录。
- 已验证：真实数据库红绿测试通过。
- 尚未验证：生产执行计划与大数据量；不将本地测试当压测。
- 后续建议：子查询集合使用原生表达式或闭包，不传普通值参数。
- 本次来源：与 PIT-0039 相同。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 财务第八批 | 退回更正后的有效累计 | 旧测试未验证更正后再次计算剩余可退额度。 |

## PIT-0038：通用来源引用绕过业务类别的读取权限

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-07
- 复发次数：0
- 适用范围：财务草稿关联来源投影
- 相关问题：PIT-0036（敏感凭证独立授权）

### 触发场景

只有应收经办权限的员工保存应收付款日草稿，将来源引用填写为同店工资来源，再读取草稿详情。

### 根因

通用账本解析器只负责租户隔离；详情投影未再核对来源类别和主体，误将同店来源都当作当前业务可读对象。

### 错误做法

对入口业务授权后直接展开任意草稿中的来源引用。

### 正确做法

返回关联来源前同时校验其类别属于入口业务 policy.sources，且主体与当前草稿匹配。租户校验不能替代业务权限。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_due_draft_cannot_read_salary_source_through_current_date_projection` 使用真实逻辑入口；修复前返回测试工资金额和姓名，修复后明确拒绝。
- 架构防线：`FinanceBusinessLogic::detail` 使用授权后的业务策略校验关联来源。
- 已验证：数据库回归已红绿验证。
- 尚未验证：真实微信页面及生产权限配置。
- 后续建议：新增通用来源展开保持同一类别和主体边界。
- 本次来源：`Matt Pocock / implement`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / tdd`、`Matt Pocock / prevent-repeat-pitfalls`；审查确认根因后建立防线，返回客户往来批次验证。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 财务客户往来第七批 | 应收日期草稿读取同店工资来源 | 原授权测试覆盖直接工资入口，未覆盖新增的草稿关联来源投影。 |

## PIT-0034：业务更正后丢失已变化的判重身份

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-08
- 复发次数：1
- 适用范围：财务实际收付、渠道更正及普通费用收款对象更正
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
| 2026-09-08 | 费用关联调整 | A对象的费用来源X改为B，再登记B/X重复产生费用与应付 | 原防线只覆盖真实资金交易渠道别名，没有覆盖费用来源的收款对象身份变化。 |

2026-09-08 费用身份防线：`FinanceExpenseIdentities::claim` 在账套事务内保留原对象及历次更正对象的来源编号，`finance_expense_identity` 以门店、对象和来源编号联合唯一约束，同一别名只能归属同一费用。`FinanceBusinessWorkflowTest::test_expense_reduction_after_partial_payment_creates_only_excess_refund_and_preserves_original_cost` 从公开接口复现原实现允许再次登记B/X，修复后拒绝；同时检查更正对象不能撞入另一笔独立费用。实际使用 `Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / prevent-repeat-pitfalls`，防护后返回费用调整开发。尚未验证生产并发压测；后续来源身份变更沿用别名保留规则。

## PIT-0035：同一次核销的两侧余额落在不同月份

日期：2026-09-09（第60批补充）

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`、`Matt Pocock / code-review`（自动调用）。
- 说明：原任务为财务一期已用预收跨月更正；在月报与资金期间不一致处补充回归防护，然后返回采购及其他未完成流程。以下来源只说明本次补充，不追认早期记录的执行顺序。
- 页面补充实际使用 `用户级自定义 / impeccable`，沿既有报表明细展示期间更正组成；源码审查通过，原生验收仍受限。

- 状态：已防护
- 首次发生：2026-09-07
- 最近发生：2026-09-13
- 复发次数：7
- 适用范围：预收抵扣、已用预收跨月更正、后续资金认领、历史销售贷项、未知日期客户和供应商贷项冲销、账户互转结清事实重投影与期间对账快照
- 相关问题：PIT-0032（时点含义不同）

### 触发场景

上月预收抵扣本月应收，上月尚未结账。

### 根因

应收采用较晚的核销生效日，预收却按到账月份一次汇总消耗，两侧期间不一致。

### 错误做法

先按来源到账月减少全部预收，再分别确定应收核销月份。

### 正确做法

逐项把已确认核销的业务日期、生效日和入账月用于对应预收消耗，并保留核销明细关联。
历史销售更正还必须把关联贷项核销与收入差额统一放入本次调整期间；原业务日期和生效日期继续保留，不能把启用日当作当前确认日。
未知日期期初贷项抵扣后月应付时，冲销必须以原分录实际入账月为基准，再按关账规则转入开放期间；不能由业务日期重新推导原期。
对账读取同类核销时也必须以已记录的目标月份约束截止范围，不能用启用日提前减少旧期余额；只能确定月份时按月展示，未知生效日期继续保留为空。
已用预收更正保留来源身份时，月报按每次资金冲销和替代分录的实际入账月投影原预收组成移出及新组成移入，金额差额不得再叠加一次。已结账原资金的替代影响与反向影响都进入当前确认月，原业务日期仍保留。

### 防线

- 自动化防线：`FinanceBusinessWorkflowTest::test_advance_consumption_and_receivable_allocation_use_same_effective_period`；修复前实际得到8月与9月错配，修复后两侧均为9月。
- 已验证：专项通过，规格复核 resolved。
- 销售复发防线：`FinanceBusinessWorkflowTest::test_historical_sales_credit_posts_both_sides_in_confirmation_month_even_when_activation_month_open`。移除历史差额期间修正的临时缺陷变体实际出现 `2026-09` / `2026-08`，正式源码同一用例通过；不以余额总额守恒替代期间配对验证。
- 供应商贷项复发防线：`FinanceBusinessWorkflowTest::test_unknown_date_opening_supplier_credit_uses_target_month_and_rejects_foreign_or_excess_allocation`。新增未知日期抵后月应付再冲销的组合断言，修复前实际得到 `2026-08` 而期望 `2026-09`；修复后 1 项测试、45 条断言通过，两侧原入账期与余额恢复均获验证。
- 供应商对账复发防线：同一公开用例增加旧期与当期快照断言；修复前旧期应退款实际 `20.00` 而期望 `50.00`，修复后 1 项测试、49 条断言通过。写入期间正确不能替代对读取截止口径的验证。
- 尚未验证：完整月报快照尚在后续开发范围。
- 后续建议：待认领核销沿用两侧期间配对，不重复实现日期推导。
- 本次来源：与 PIT-0034 相同；使用实际失败用例防止期间含义混淆复发。

### 发生记录

| 日期 | 任务 | 场景 | 原防线为何未阻止 |
|---|---|---|---|
| 2026-09-07 | 预收抵扣 | 不同业务月份的预收和应收 | 原金额守恒测试仅覆盖同月。 |
| 2026-09-07 | 销售新账套接入 | 启用后跨月更正历史销售，启用月仍开放 | 原防线覆盖预收的两侧核销，没有覆盖历史销售收入差额与贷项核销的期间一致性。 |
| 2026-09-08 | 供应商贷项抵扣 | 日期不详期初贷项抵后月应付后反向，启用月仍开放 | 原测试分别覆盖已知日期反向与未知日期正向，遗漏组合场景；反向从业务日期重推期间，丢失原分录实际入账月。 |
| 2026-09-08 | 供应商对账 | 后月抵扣之后生成启用月份快照，旧期应退款提前减少 | 原防线检查了正反分录月份，未覆盖快照读取仍回落到启用日的期间筛选。 |
| 2026-09-08 | 普通费用调整 | 上月费用已结账，本月调减产生的应退款仍用原发生日进入旧期对账 | 原测试仅让受益月在上月，原发生日仍为今天，未覆盖新增义务来源的截止时点。 |
| 2026-09-09 | 财务一期第60批 | 已退回部分预收后向前／向后改到账月份，或原已结月改为另一个未结月 | 原防线验证当前余额及同月日期对账，没有验证保留来源身份后的月报归属；替代入账只看新业务日期，未承接原现金已封账的确认月边界。 |
| 2026-09-09 | 财务一期第68批 | 未知日期期初客户贷项抵扣本月应收后，生成上月对账时贷项余额提前由50降为20 | 原快照月份防线仅作用于供应商；新增客户抵扣虽保存正确月份，读取仍回落到启用日。 |
| 2026-09-13 | 财务一期账户互转更正 | 已结月存在到账和返还后更正转出，本金替代来源进入当前月而重投影余额仍写入旧月 | 原防线覆盖历史资金更正和快照，但没有覆盖“重投影来源与其余额分录必须经过同一关账路由”。 |

2026-09-09 第68批补充：`FinanceStatementSnapshot::capture` 对 `customer_credit_allocate` 的未知生效日分录按保存的归属月份限制截止范围，关联反向沿用同一规则；其他客户真实资金业务仍保留其原日期口径。`test_unknown_date_customer_credit_keeps_effective_date_unknown_and_limits_staff_foreign_and_excess_allocations` 覆盖旧期50、本期20、明细仅显示已知月份及撤销后两期50。原红 `E:/object/BeiMi/.scratch/finance-68-credit-targeted.log` 为5 tests、241 assertions、1 failure；修复后 `finance-68-credit-targeted-green.log` 为5 tests、257 assertions通过，包含贷项退款共享余额及供应商回归。一次重跑因隔离服务停止产生5 errors、0 assertions，恢复3307服务后得到上述绿色终态，该环境失败不计为业务验证。实际使用 `Matt Pocock / implement`、`Matt Pocock / tdd`、`用户级自定义 / impeccable`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`，两轴静态复核clear。尚未验证本批生产部署和真机；后续新增无真实日期的核销类型须同时核对正反分录与期间读取。防护后返回客户贷项抵扣提交与剩余财务功能。

2026-09-09 自动防线：`test_consumed_advance_date_correction_keeps_cash_and_customer_months_consistent` 覆盖向前／向后移动、已结来源、改回已结月、连续日期更正和更正后再封账；金额不变时不生成零分录。原红 `.scratch/finance-60-date-red.log` 4 tests、126 assertions、3 failures；修复后相关回归 `.scratch/finance-60-regression.log` 14 tests、523 assertions 通过。当前总余额正确并不能证明各月正确，测试同时比较客户预收、现金、相邻月承接及原冻结快照。尚未验证：本批生产部署及真机；后续建议：其他保留来源身份的日期更正也须核对关联分录期间，不能从最新业务日期重推历史入账。

审查补充：暂估结账必须与普通结账同样冻结；`.scratch/finance-60-review-red.log` 同时复现暂估旧月错入和并发关账旧快照错入。期间更正明细缺失由 `.scratch/finance-60-movement-red.log` 两例复现，现按资金分录保存的入账月提供可追溯的组成；季年汇总、Excel 和页面均接入。最终 `.scratch/finance-60-final-php.log` 28 tests、1409 assertions 通过，前端专项 14 tests 与 source integrity 通过，两轴复审关闭全部发现项。

2026-09-08 费用时点防线：原发生日保留在费用快照与分录业务日期；本次义务生效日独立保存为 `obligation_date`，用于新增应付/应退款及对应余额调整的生效日。费用仍按真实受益月及关账规则入账，不能把本次退款倒灌旧期。上述费用公开用例增加原发生日在已结上月的供应商对账：修复前上月应退款为70.00（应为0.00），修复后上月应退款0.00、费用应付300.00保持。本次实际使用 `Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`、`Matt Pocock / prevent-repeat-pitfalls`。完成防护后继续费用调整页面；完整月报尚未完成。

2026-09-13 账户互转重投影复发：已结月的转出先发生到账与返还、再关联更正转出本金时，`FinanceAccountTransfers::rebaseSettlements()` 曾把原结清快照的 `posting_month` 直接写入新余额分录。新来源本身已按 `FinanceLedger::postingMonth()` 进入当前开放月，余额分录却倒灌到已结月，导致同一更正的来源与余额期间分裂。现保留原到账／返还的日期和月份于重投影快照与明细中，但余额分录统一通过 `FinanceLedger::postingMonth($actualDate)` 进入当前合法入账月。自动防线 `FinanceBusinessWorkflowTest::test_closed_month_transfer_out_correction_rebases_settlements_without_rewriting_history` 覆盖到账、返还、已结月、关联更正和历史资金报表冻结，断言旧来源替代、替代来源承接 650 元、重投影余额分录入当前月且历史账户与在途快照仍为 4250/750。修复前用例的旧断言会在新来源被归入当前月时错误读取已结月；补充当前入账月断言后，修复版本以 1 test、45 assertions 通过。实际使用 `Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`；后续新增“历史结清事实重投影”能力必须同时验证事实日期、分录入账月、当前余额和冻结快照。

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

日期：2026-09-09（第64批来源展示补充）

### 报告来源

- 生成原因：工作流要求
- 主工作流：Matt Pocock
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / diagnosing-bugs`、`用户级自定义 / prevent-repeat-pitfalls`、`Matt Pocock / code-review`（自动调用）。
- 说明：本次只扩展财务期间的旧快照防线，原任务及恢复位置见 PIT-0035；不重写此前记录。

- 状态：已防护
- 首次发生：2026-07-29
- 最近发生：2026-09-09
- 复发次数：13
- 适用范围：`CustomerReportLogic`、`FulfillmentChangeLogic`、`FulfillmentTaskLogic`、`DeliveryInventoryLogic`、`DeliveryVariantLogic`、`LineVehicleLogic`、`NegativeInventoryLogic`、`SalesSettlementLogic`、`FinanceService`、`FinanceCostLedger`、`FinancePurchaseArrivals` 等已开启业务事务后调用库存、财务原语或写入幂等事实的路径
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

- 2026-09-09 盘点关联展示扩展：`FinanceInventoryCountCorrections::present()` 在保存、提交的既有事务内调用候选读取，候选最初无条件再次开启事务。规范审查确认调用链，未声称现场复现保存点错误。统一改为 `readConsistently()`：已有事务直接读取，无事务时才开启；`CustomerReportRouteContractTest::test_inventory_count_source_presentation_reuses_the_callers_transaction` 限制候选通过统一入口，真实草稿保存与预览回滚由盘点关联专项覆盖。旧入口枚举防线未覆盖新增展示读取方法，防护完成后返回第64批完整回归。
- 2026-09-08 库内损耗扩展：`StockService::outboundFinanceInventoryLossWithinTransaction` 曾调用会自行开事务的 `WarehouseSkuBalanceService::outbound`。新增禁止负量的 `outboundWithinTransaction` 并改用它；`CustomerReportRouteContractTest::test_finance_stock_loss_never_starts_an_inner_stock_transaction` 在旧调用上明确失败，保护同事务调用和原语不再开事务、不放开负量。行为用例继续覆盖可用量不足整体回滚、原事件幂等和核实不二扣。来源为 Matt Pocock / implement、tdd、code-review、diagnosing-bugs、prevent-repeat-pitfalls；此为原入口枚举防线未覆盖新损耗路径的复发，不另建 PIT，防护后返回财务一期开发。
- 2026-09-09 收款更正扩展：新增原现金入账月冻结判断时曾使用普通 `count()`，漏掉外层 RR 快照之后另一连接提交的关账；`test_receipt_correction_reads_original_period_closed_after_outer_snapshot` 用真实双连接复现替代进入旧开放月，原冲销却进入本月。改为原期间 `lock(true)->find()`，同时识别普通和暂估月结；最终财务专项 28 tests、1409 assertions 通过。既有成本入口当前读测试没有覆盖新的付款更正入口。业务库部署与生产并发压测尚未验证；后续期间判断继续复用当前读边界。
- 2026-09-08 财务成本扩展：成本原语在外层事务内先取得稳定的门店准备行锁，再对成本来源、份额和待补数量使用当前读，避免沿外层已创建的 RR 快照覆盖其他事务已提交的成本。`FinanceBusinessWorkflowTest::test_cost_confirmation_reloads_committed_facts_after_an_outer_transaction_created_an_older_snapshot` 与 `tests/fixtures/finance_cost_worker.php` 用两个真实 PHP 连接固定先建快照、另一个事务出库 2、原事务再出库 3 的交错；修复前剩余成本错误为 70，修复后为 50，且两次销售成本分别为 20 和 30。此次只更新同根因记录；来源为 Matt Pocock / implement、tdd、code-review、diagnosing-bugs、prevent-repeat-pitfalls，完成后返回财务一期成本与采购接线。
- 同批期间边界：`FinanceLedger::lockBook` 与 `postingMonth` 同样采用当前读；`test_cost_confirmation_cannot_ignore_a_month_closed_after_outer_transaction_snapshot` 用第二连接在快照创建后关闭当前月，修复前成本确认未抛异常，修复后明确拒绝已结账月份。此为同一 RR 旧快照根因的边界扩展，不另记复发次数。
- 2026-09-08 采购到货扩展：审查发现多商品到货按客户端行序取库存锁，与报货预留的 SKU 升序相反，存在交叉等待路径。`FinancePurchaseArrivals` 现在先去重并按 SKU 升序调用既有 `lockBalanceWithinTransaction`，再按原单据行序追加到货和成本快照；单一收货仓下 SKU 唯一决定商品维度。`test_multi_sku_arrival_preserves_document_order_and_stock_when_lock_order_differs` 核对反向输入时各商品实收量、展示顺序及禁止覆盖实物记录。尚未进行此场景的双进程压力复现，不能把顺序检查当作并发验收。来源为 Matt Pocock / implement、tdd、code-review、diagnosing-bugs、prevent-repeat-pitfalls；后续继续供应商正式结算，并在整体并发验收中覆盖到货与报货交错。

- 2026-09-07 财务第十批扩展：`FinanceOverdue` 拆分独立事务包装与 `captureWithinTransaction`，财务确认和销售确认只调用同事务原语。`test_overdue_finance_write_paths_use_existing_transaction_primitive` 保护调用边界，`test_overdue_preview_failure_and_permission_revocation_do_not_leave_or_expose_observations` 验证预览与失败不留下观察。来源为 Matt Pocock / implement、tdd、code-review、prevent-repeat-pitfalls；完成防护后继续财务一期。

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
| 2026-09-07 | 财务第十批 | 逾期待办观察服务被外层确认事务调用 | 旧结构防线未包含新接入的财务观察服务，独立读取入口与确认原语未拆分。 |
| 2026-09-08 | 财务第26批库内损耗 | 新增同事务库存流水入口却调用独立出库包装 | 原结构防线未覆盖新损耗写入口；新增同事务普通出库原语并用结构测试与真实业务回归共同保护。 |
| 2026-09-08 | 财务第29批调拨成本承接 | 调拨外层事务仍调用独立仓库调拨事务 | 既有结构防线没有覆盖调拨；新增 `transferWithinTransaction`，对基线 `904c8b9` 的独立事务调用探针失败，现结构测试 1 test、6 assertions 通过。调入无效仓库时真实两仓实物与成本保持不变；调拨、仓库和成本专项 29 tests、219 assertions 通过。两侧流水共用一次发生时间，避免午夜被拆到不同截点；来源为 Matt Pocock / implement、tdd、diagnosing-bugs、prevent-repeat-pitfalls、code-review，防护后继续财务一期。 |

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
