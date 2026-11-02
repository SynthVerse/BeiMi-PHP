# 员工垫付真实联合HTTP交接

日期：2026-10-26（Asia/Shanghai）

## 报告来源

- 生成原因：正式独占联合派发要求保留真实执行、来源、清理与验证边界。
- 主工作流：Matt Pocock。
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`。
- 说明：本任务仅修改PHP测试桥接及专属隔离夹具，执行真实前端到PHP／测试库的联合HTTP；未修改冻结前端或处理其UI审批事项。

## 实际结果与边界

派发 `takeover-20261026-reimbursement-joint-http-01`，runId `preparation-development-20261024-01`。首次真实执行通过：22／22项、111次请求、exit0，9项driver外层检查全通过。22个ID的严格顺序、唯一性、boolean及客户端stdout精确五字段另经独立读回核对。

固定协议仍为 `beimi-preparation-http-bridge/v1`，截止日2026-10-16、carryover启用日2026-10-17，沿用原四身份；token仅经stdin内存管道传入，未输出、落盘或放入argv／env。

已实际覆盖完整员工建档及原回执、同名不同ID、未知金额null、费用／月份缺失可存但阻核清、截止／未来月份、员工月份汇总互斥与编辑排除自己、费用不是分组键、空月份分组、同额明细不同ID、第二笔门店稿恢复、正数和code0、未知原命令、真实移除原身份、成功仅GET、回执保留失败阻离开、本机恢复、普通财务员工入口、核清与独立说明、建档unknown不等未创建。移除使用本轮实际生成的记录身份，未以准备示例固定ID代替。

111是固定客户端统计的请求尝试数，不代替服务端执行或写入次数。正数等纯前端拦截按客户端行为验证，不据零请求检查单独宣称数据库约束反例已经执行。

同名不同ID检查只验证业务选择身份。协调交接已明确UI缺口：候选行没有显示真实编号，原前端补丁自动审批未放行；该事项由原前端与协调处理，本任务没有尝试代写或换工具绕过。HTTP通过不构成UI或本事项整体验收通过，不自行放行第7项。

## 驱动与夹具验证

- 只改 `tests/fixtures/preparation_http_bridge.php`、`tests/bridge/PreparationHttpBridgeProcessTest.php`、`tests/unit/PreparationHttpWorkflowTest.php`。生产PHP、process helper及guard未改，没有新增业务规则或迁移。
- 固定reimbursement target／路径、handoff SHA、81来源白名单及22个硬编码ID；该目标在原精确集合校验后额外比较实际ID顺序。既有bool／schema／run／token／超时门槛、旧target及旧pin保持；历史冻结来源漂移继续失败关闭。
- 无DB协议红测57项／586断言、2失败；绿测57项／786断言、exit0。没有执行或篡改旧工资、供应商的历史联合结果来补绿。
- 共用权限夹具仅按固定salary／reimbursement分支选择权限。salary保持原三权限；reimbursement的peer 992642为tenant 882641普通成员，绑定真实专属合成员工及有效合成手机号，仅有 `finance.opening.prepare`，没有employee.manage或工资私密权限。
- 两类夹具真实HTTP验证2例／54断言通过。reimbursement用例验证普通财务访问及不能建档，其相同权限写回不单独证明撤权恢复；本轮普通财务入口由固定22项实际客户端进一步执行。
- 客户端退出后先停HTTP，PHP直接比较peer完整员工资料去update_time摘要、绑定、角色和完整permission_keys，再清理。before／after严格一致，独立夹具门槛通过；没有只比较权限数量或把peer替换成店主。
- 独立Standards／Spec只读复核未发现本次PHP桥接与夹具的确定问题。复核人员未代跑HTTP／DB；真实执行由本任务独占完成。

## 来源与独立清理

- 冻结handoff SHA256 `c8b091dea0d077764caa48b604559c46c4370bce0780c54c862f80e53cdc9f5d`；客户端SHA256 `459f55393d4ffb6ee33e0b465411637b7b2247410883e589d084c05be7dff816`。74运行＋3客户端＋4支持共81来源及18个PHP相关来源执行前后保持。
- 独立来源／静态preflight／scope／session／锁／进程前置均通过后，另发实际执行。原始driver stdout `attempt-01.json`，严格五字段解析后输出 `attempt-01-client-stdout-parsed.json`，开始／结束时间及exit在 `attempt-01-exit.json`。
- 本轮先核对当前3307监听进程及其 `E:/object/BeiMi/.local/mysql/my-test.ini` 配置，再使用原guard确认127.0.0.1:3307／beimi_test_inventory；实际仍为PID48108，并非仅凭历史PID假定。没有启动、初始化、重建、改guard、触及3306或清未知数据。
- 只写882641／882642合成范围。2026-10-26 01:14:36—01:14:42的新清理观察晚于执行结束：scope／session0、锁可再次取得、无临时PHP／Node，既有64行逐表摘要不变，当前实例及原配置保持。未引用工资或夹具测试的旧清理作为本轮结果。
- Codegraph已增量同步，1360文件、pending新增／修改／删除均0，未init。所有本任务PHP／driver／测试源码停写，实际HTTP进程已结束。

## 交接与未完成项

证据目录 `.local/reimbursement-joint-http-20261026`；机器交接 `reimbursement-joint-stage-result.json`；来源 `attempt-01-sources-before.json`／`attempt-01-sources-after.json`；新清理 `attempt-01-cleanup.json`；peer比对 `attempt-01-peer-fixture.json`。

未完成：同名员工编号提示的UI缺口及原前端整体源码／组件验收、最终统一构建、微信开发者工具、真机及全部状态／视图运行。协调交接中的Fields 17／18及既有前端失败继续保留，本任务没有删除断言或改历史snapshot。本轮只交接HTTP与PHP层结果，由协调综合验收并处理UI审批边界。
