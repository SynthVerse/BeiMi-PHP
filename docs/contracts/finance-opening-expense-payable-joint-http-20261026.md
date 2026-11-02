# 费用待付款真实联合HTTP交接

日期：2026-10-26（Asia/Shanghai）

## 报告来源

- 生成原因：正式独占联合派发要求保留真实执行、来源、清理和验证边界。
- 主工作流：Matt Pocock。
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`。
- 说明：本轮只扩展PHP测试驱动及其隔离夹具，联调冻结生产前端到真实PHP和原3307测试库。Codegraph是工具，不是Skill。

## 最新结论：17:08补核完成

补充派发 `takeover-20261026-expense-payable-postcheck-403-01` 后，通过原正常审批机制对原`after`命令有限重试一次，exit0；未重跑`execute`或30项业务HTTP。北京时间2026-10-26 **17:08:30—17:08:39** 的独立补核确认scope／session0、串行锁可获取、相关HTTP测试进程为空、原3307实例PID48108及配置保持、原64行逐表摘要不变。81前端／18PHP来源仍匹配，原HTTP回执仍为30／30、95次请求尝试、exit0。

本任务的HTTP与独立清理门槛现已完成，PHP／driver／测试源码均停写，HTTP测试进程已获独立确认结束。该观察证明补核时点状态，不回溯证明14:53业务HTTP结束瞬间状态。整体业务验收和第8项放行由协调者决定；本任务仍不代放行或标记全部UI／原生验收完成。

## 已验证的事实

派发 `takeover-20261026-expense-payable-joint-http-01`，runId `preparation-development-20261024-01`。2026-10-26 14:53:21—14:53:49 首次真实执行返回 **30／30通过、95次请求尝试、exit0**，`jointExecuted=true`。9项driver外层门槛全部通过；另行读回确认客户端stdout精确五字段、固定30个唯一ID及顺序、boolean结果。95为客户端请求尝试数，不等于服务端写入次数。

本轮实际执行了供应商完整五字段建档、原键回执和身份隔离；未知金额null、正数前端守卫、缺说明及原日期的保存／核清双门槛；summary三方向互斥和编辑排除自身；第二笔门店稿、未知原命令恢复、成功仅GET及回执保留失败阻止退出；独立核清与说明。

分月关联验证使用真实费用ID／reference／subject／amount／opening_version，覆盖累计恰好额度、编辑排除自身、辅助未付0及超0.01、来源未知／变主体／降额／移除、同名新来源及复用旧reference不继承。费用端变更、降额、移除确可保存，问题按当前契约归分月跨项阻塞；没有增加费用端前置拒绝。这里仅调用现有通用方法与后端，不启用第8项前端。

执行前重新确认既有MySQL进程PID48108、`127.0.0.1:3307/beimi_test_inventory`、原 `E:/object/BeiMi/.local/mysql/my-test.ini` 配置；scope／session均0、串行锁可获取、无其他HTTP测试进程，原64行逐表摘要保持。没有启动、初始化、重建、修改guard、触及3306或清未知归属数据。

固定handoff SHA256为 `7f45bb56b3e73fb9a7ce93edd4cdaad901aa13bbee10cdd9f1a4dacbac46811a`，客户端为 `4aeae740ac5d7818b9e6ed312406412e907f323d61978b218e80d635091a73ec`。74运行＋3客户端＋4支持共81前端来源及18个PHP相关来源执行前后相同。token只经内部stdin内存管道，未输出或落盘。

peer 992642为tenant882641普通member，真实合成员工绑定，仅有 `finance.opening.prepare`。driver停止自身HTTP后、清理前直接核对完整员工资料去update_time摘要、绑定、角色及完整权限集合，before／after严格相同。此为driver内部恢复证据，不替代执行后的独立scope／session／进程检查。

## 实际修改与回归

仅修改 `tests/fixtures/preparation_http_bridge.php`、`tests/bridge/PreparationHttpBridgeProcessTest.php`、`tests/unit/PreparationHttpWorkflowTest.php`。生产PHP、guard、process helper及冻结前端未改。

- 固定expense_payable目标、路径、handoff pin、81来源与30ID严格顺序，复用既有schema／boolean／token／超时门槛；新增完整夹具恢复门槛。
- 协议红测63例／800断言、4失败：新增目标两例未接入，加旧reimbursement共享来源漂移两例。绿测63例／1010断言、exit0。
- 原reimbursement的6个共享来源经合法费用接入后变化。原pin、22ID、历史HTTP证据保持；当前就绪测试改为验证历史pin漂移时失败关闭。差异另存，不修改旧快照补绿。
- 夹具红测3例／57断言、1error；首次绿测3例／83断言、1失败，原因是把员工事项can_create=false误套费用。两次原结果保留；按当前费用can_create=true、员工垫付false修正测试后，最终3例／83断言通过，15条真实HTTP观察。
- 本轮不执行会清理旧882101／882102未知归属资料的整库测试。上述协议和专属固定范围HTTP覆盖本轮增量；没有把历史测试或重复次数累加。
- PHP语法通过。Codegraph已增量sync，1360文件，pending added／modified／removed均0，未init。

独立双轴只读复核以本轮三份`.before`为固定基线。Standards此前指出的费用can_create断言已修正，最终无剩余确定问题；Spec确认固定来源、30ID及顺序、最小peer权限和完整恢复门槛符合派发，无确定缺陷。两位审查者未代跑HTTP或数据库，也未据此把尚未执行的独立清理标为通过。

## 历史阻塞及本次恢复

实际联调结束后，独立来源指纹已经读回并匹配。但原独立scope／session／串行锁／进程／原64行复查命令被自动审批服务 `403 Forbidden` 阻止，未启动。原会话记录确认调用于14:59:30.194发出、15:00:27.842返回错误；这是审批服务请求失败，不是明确安全拒绝。工具要求不绕过审批，原轮未换入口或自行重试。

截至原轮交付时，状态为“真实30项通过，独立清理未验证，businessAccepted=false”；旧机器交接、清理状态及正式文档已另行归档。不能用执行前scope0、driver的finally或内部peer核对替代独立清理。本次补充派发先核实相同403后没有原命令重试，再通过原审批机制仅重试一次，取得上节实际补核结果；原403和各旧失败保持。

另有一次执行前审批超时，工具允许的一次同命令重试成功；该事件与本次执行后403分别记录，不混为业务失败。

## 证据与后续

证据目录 `.local/expense-payable-joint-http-20261026`，机器交接 `expense-payable-joint-stage-result.json`。原始driver stdout为 `attempt-01.json`，退出记录 `attempt-01-exit.json`；客户端五字段解析文件 `attempt-01-client-stdout-parsed.json` 不是原始客户端字节。来源前后为 `attempt-01-sources-before.json`／`attempt-01-sources-after.json`（后者由本次原命令刷新为补核时点），完整peer比对为 `attempt-01-peer-fixture.json`。

本次独立scope、进程、清理汇总分别为 `attempt-01-scope-after.json`、`attempt-01-processes-after.json`、`attempt-01-cleanup.json`；重试及原403精确调用分别见 `postcheck-403-retry-result.json`、`approval-service-original-call.json`，原故障摘要 `approval-service-block.json`仍保留。原未验证清理、旧机器交接和旧正式文档以`before-postcheck-403`名称归档，不把原阻塞改写为当时已通过。

独立只读复核已比较新after与原before及基线，支持本次补核结论；审查者未执行探针、HTTP或DB。后续由协调者综合验收并按序派发。最终统一构建、微信开发者工具、原生确认／滚动／键盘／安全区、真机及70态全部路径仍未执行，保留原分层边界。
