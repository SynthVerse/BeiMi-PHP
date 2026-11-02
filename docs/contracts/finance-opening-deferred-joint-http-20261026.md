# 分月费用第8项真实联合HTTP交接

日期：2026-10-26（Asia/Shanghai）

## 报告来源

- 生成原因：正式独占派发要求保留真实执行、固定来源、回归及独立清理证据。
- 主工作流：Matt Pocock。
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`。
- 说明：本轮只扩展PHP测试驱动与专属夹具，执行冻结生产前端到真实PHP和原3307测试库的联合HTTP。Codegraph是工具，不是Skill。

## 本轮结论

派发 `takeover-20261026-deferred-joint-http-01`，runId `preparation-development-20261024-01`。首次真实执行于北京时间19:47:34—19:48:07完成：**32／32检查通过，131次请求尝试，exit0**，`jointExecuted=true`。10项driver外层门槛全部通过，另行读回核对stdout精确五字段、固定32个唯一ID及顺序和严格boolean。

执行后独立检查于19:49:43—19:50:00确认scope／session均0、串行锁可重新获取、相关HTTP／Node测试进程为空，原3307实例PID48108及配置保持，既有64行逐表摘要不变。项目101来源、外部工具链6来源、15份交接资料及18个PHP相关来源前后保持。PHP／driver／测试源码已停写，HTTP进程已获独立确认结束，共享前端未修改。

本任务的HTTP与独立清理门槛已完成。整体业务验收及统一构建／微信放行仍由协调者决定，本任务不代放行或把分层证据写成全部原生路径通过。

## 实际业务覆盖与边界

固定32项实际覆盖：真实Page／Fields入口、剩余模式和完整历史wire、完整供应商五字段原键回执及actor／tenant／admin隔离；非法值和121行原计划零请求守卫；未知金额、零月额、两组历史等式、空／缺／重复／越界／差额计划、服务起月早于启用月等可保存但不可核清的分层；均分原生确认参数、取消及确认行为；真实费用ID／reference／subject／amount和全局opening.version。

关联覆盖累计恰好额度、超0.01、真实ID编辑排除自身、未付0；首读原稿和服务器首次门店稿基线分别保留、显式rebase不覆盖首读快照；第二笔完整历史冷恢复、未知原键原载荷、成功只GET、可靠回执失败阻止退出、独立核清和说明保存／显式清空。

上游费用金额未知、变主体、降额、移除按现有契约允许保存，分月保持原文并阻止核清。同名新费用或复用旧reference不替代旧真实来源ID。没有增加费用端前置拒绝、重复债务、source-version／可用额度接口或兼容分支。

131是客户端实际请求尝试数，不等于服务器写入次数。零请求守卫证明前端未发送，不单独证明数据库反例已经执行。原生确认在本轮为平台适配边界，不能据此声称微信原生弹层或布局验收通过。32项HTTP也不等于40主态＋24变体全部入口和恢复路径通过。

## 固定驱动与回归

本轮仅修改：

- `tests/fixtures/preparation_http_bridge.php`：固定deferred目标、路径、交接／合同／manifest／client四项pin；101项目相对来源保持仓库范围白名单，6条固定绝对工具链另行校验及记录，不放宽执行路径。固定32ID严格顺序，完整夹具恢复门槛影响最终通过结果。
- `tests/bridge/PreparationHttpBridgeProcessTest.php`：拒绝任意／重复目标与注入，验证固定来源、检查集合、顺序及现有输出协议。旧费用冻结pin保持，其6个共享源码合法漂移时按原pin阻止执行。
- `tests/unit/PreparationHttpWorkflowTest.php`：固定deferred入口，沿原共享夹具准备普通peer成员，只授`finance.opening.prepare`；增加第4组权限夹具回归，保留工资敏感权限门槛及员工垫付／费用现行can_create契约。

生产PHP、guard、process helper及冻结前端未修改。新夹具红测1例／3断言、1个不支持目标error、exit2；修正后四类夹具4例／112断言通过、20条真实HTTP观察。协议红测70例／1028断言、4失败（新目标2例及旧费用来源漂移2例），绿测70例／1532断言通过、exit0。原红测、漂移细节及所有旧HTTP结果保留；没有更新旧pin或历史快照补绿，也没有累计重复运行数冒充覆盖。

未运行会清理原882101／882102未知归属64行的整库测试。上述固定范围HTTP与无DB协议回归覆盖本次增量；PHP语法检查通过。Codegraph实际增量sync后1360文件，pending added／modified／removed均0，未init。

## 身份、环境及独立清理

沿用原guard核定 `127.0.0.1:3307/beimi_test_inventory`、tenant882641／882642、用户992641／992642／992643和独立tenant_admin身份空间。截止日2026-10-16、carryover启用日2026-10-17。四身份token仅内部stdin内存管道，不落盘、argv、env或回显。实际供应商、费用来源及分月记录均由客户端通过生产入口创建。

peer为普通member，真实合成员工绑定。driver在客户端结束、停止自身HTTP后，清理前比较完整员工资料去update_time摘要、绑定、角色和全部permission_keys，前后七字段完全一致。该内部恢复事实与执行后的独立数据库／进程观察分别保留，不用finally代替清理证明。

本轮重新核对原MySQL进程及 `E:/object/BeiMi/.local/mysql/my-test.ini`，仍为PID48108，无需启动或恢复实例。未初始化、重建、修改guard、触及3306、部署、重置共享环境或清理未知归属数据。独立after观察晚于执行结束，支持观察时点的隔离清理结论；不回溯证明结束瞬间的精确状态，不扩大为全数据库所有行的逐行比较。

上一费用阶段的审批服务403已有原记录及正常重试恢复证据，本次没有该阻塞，也未重跑费用30项／95请求。

## 双轴复核与证据

- Standards：对本轮三份`.before`到当前差量独立只读复核，无确定违规或需处理的维护性建议。
- Spec：固定32项、四pin、101＋6来源、最小peer权限与完整恢复门槛符合派发，无确定缺口或越界。
- 执行后证据又由独立审查者仅通过文件核对，支持输出、来源、peer和独立清理结论；审查者未执行探针、HTTP或数据库。

证据根目录为`.local/deferred-joint-http-20261026`。机器交接`deferred-joint-stage-result.json`包含全部当前文件SHA256；原始driver stdout为`attempt-01.json`，时间及exit为`attempt-01-exit.json`。`attempt-01-client-stdout-parsed.json`是五字段解析文件，不是原始客户端字节。

来源前后为`attempt-01-sources-before.json`／`attempt-01-sources-after.json`；独立范围及进程为`attempt-01-scope-after.json`／`attempt-01-processes-after.json`，清理汇总`attempt-01-cleanup.json`；peer比较`attempt-01-peer-fixture.json`。首次静态preflight与真实execute分两次调用，前置成功后才执行。所有原始失败、JUnit、exit、漂移及Codegraph日志保留。

## 未完成项

最终统一UniApp构建、微信MCP既有项目运行、原生布局／确认／滚动／键盘／安全区、真机和40＋24全路径验收均未执行，由协调验收后交指定统一执行者推进。root没有操作微信、构建、写tenant、提交推送、切分支、回滚他人或向其他聊天发消息。第1—7项历史验收与证据保持原来源，不用本轮替代。
