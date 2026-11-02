# 工资待发真实联合HTTP：首轮失败与复验通过

日期：2026-10-25（Asia/Shanghai）

## 报告来源

- 生成原因：正式派发要求保留实际联合、失败定位、来源与清理证据。
- 主工作流：Matt Pocock。
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`Matt Pocock / diagnosing-bugs`。
- 说明：仅PHP封闭桥接与专属隔离夹具由本任务修改。前端、客户端和模板保持冻结；本文件不代替原前端作者的组件或最终运行验收。

## 当前复验结论

第二次正式派发 `takeover-20261025-salary-joint-http-02` 已真实执行通过：22／22项、107次请求、exit0，9项driver检查均通过。22个ID的顺序、唯一性和boolean均独立核对；工资权限撤回与未决敏感资料恢复已实际执行，PHP在客户端退出后独立比对peer完整资料和权限一致。本轮独立清理通过，Codegraph pending均0，源码及HTTP已停止。精确证据及未完成项见文末第二次复验章节。

## 首轮执行与结论（历史）

派发 `takeover-20261025-salary-joint-http-01`，runId `preparation-development-20261024-01`。固定协议v1、四身份、2026-10-16截止日；carryover启用日2026-10-17。

首轮已真实执行：`jointExecuted=true`、57次请求、exit1。前10项完成，首个未通过为 `second_record_edit_store_restore`；后续11项未执行。客户端输出的后续false不等于11个业务失败。22个唯一ID及固定集合保持，9项driver外层检查均通过；首轮整体未通过，其证据继续保留，未改写为成功。

原始driver stdout为 `.local/salary-joint-http-20261025/attempt-01.json`，退出码与开始结束时间为 `attempt-01-exit.json`，严格五字段解析后的客户端输出另存 `attempt-01-client-stdout-parsed.json`。

## 已确认的来源投影／断言冲突

- 固定客户端 `salary-http-client-20261025.mjs:275` 从第二笔原记录取 `source_reference`，第279行要求恢复后的编辑表单字段与该原值相等。
- 当前真实 `common/utils/finance-onboarding.js:179、185` 对salary的owner_manual及匹配 `首次手填-<24hex>` 的标识投影为空字符串；advance／supplier_refund有保留原标识的分支，salary没有。暂存保存该表单，恢复继续使用表单表示。
- 使用真实bridge runtime装载真实guideItemForm，传入合成记录做零HTTP诊断：原对象不变、记录ID保留、原标识非空、表单标识为空，客户端上述相等断言为false，请求0。证据 `source-reference-diagnostic.json`；未修改冻结helper或客户端。
- 独立Spec只读复核确认这处断言与生产转换规则不一致。PHP的老板录入方式在编辑已有记录时仍沿用原reference，目前没有已证实的PHP来源丢失缺陷。
- 固定去敏stdout未提供具体抛错行堆栈，因此确认的是第279行存在必然冲突，尚不能把它写成本轮首个具体抛错行已被直接观测。

首轮交接要求原前端作者按工资当前合同对齐客户端与规范化表单，并分别验证门店稿base_item及最终记录的原ID／reference，保留第二笔金额、材料与原稿恢复的强断言。原作者随后定向修订并重新冻结，协调另发第二次复验；本任务没有修改前端或重复旧客户端。

## PHP桥接与夹具验证

- 仅修改 `tests/fixtures/preparation_http_bridge.php`、`tests/bridge/PreparationHttpBridgeProcessTest.php`、`tests/unit/PreparationHttpWorkflowTest.php`。没有修改生产业务PHP、process helper、guard或数据库结构。
- 固定 `--target=salary`、固定客户端路径、handoff SHA及81来源白名单、22个硬编码检查ID；supplier／salary共享来源校验，其他target及严格schema/run/stdin/token/超时门槛保持。历史冻结来源漂移继续失败关闭。
- 协议红测51项／368断言、2失败；最终绿测51项／566断言、exit0。该层无数据库或实际客户端请求。
- 仅salary夹具把peer 992642置为tenant 882641的普通成员，绑定一个真实专属隔离员工及真实合成手机号，授予finance.opening.prepare、finance.salary.view、finance.opening.salary.prepare；owner保持原店主权限。未接入真实用户。
- 独立真实HTTP夹具测试1例／25断言通过，验证撤权后的工资查看／编辑限制及恢复。客户端退出后先停HTTP，PHP再直接比较完整员工资料去update_time摘要、绑定、角色及全部permission_keys，然后清理；本轮before/after一致，driver恢复门槛通过。
- 首轮尚未到达客户端的两组工资权限检查；夹具原状态一致和独立1例测试不能代替这两项实际联合通过。没有无条件重跑未改变的既有业务全套测试。
- 独立Standards／Spec复核未发现本次PHP桥接和夹具的确定问题；复核人员没有执行HTTP或数据库操作。

## 冻结来源、清理与未完成项

- 冻结handoff SHA256：`ca2b07a3e0dcfd1271cbc67b05152512a2098e34dc5350549b4008f28b91d760`；客户端SHA256：`c2f1974d24c3813f4888d955fb2dc0faabd811d0bfd526998b2e4bcf29d633cd`。81个前端和18个PHP来源执行前后指纹一致。
- 测试仅用原guard的127.0.0.1:3307／beimi_test_inventory、882641／882642合成范围。没有初始化、重建、改guard、触及3306或清未知归属数据。
- 首轮之后的新清理观察时间2026-10-25 20:26:55—20:27:00：scope／session为0、锁释放、临时PHP／Node结束，既有64行逐表指纹不变，原3307 PID48108保留。证据 `attempt-01-cleanup.json`，不借用旧事项或夹具测试的清理作为本轮结果。
- PHP Codegraph已增量同步，1360文件、pending新增／修改／删除均0，未重新初始化。所有本任务PHP／driver／测试源码已停写，HTTP进程已结束。
- 首轮机器交接：`.local/salary-joint-http-20261025/salary-joint-stage-result.json`，同内容另保留 `salary-joint-stage-result-attempt-01.json`。首轮暂停与失败信息均是历史记录，第二次结果单独保存；本任务不向其他聊天发送消息或自行推进下一事项。
- 首轮时点未完成第11项及其后11项联合检查；第二次已经覆盖固定22项。前端最终组件交付的本聊天验收、最终统一构建、微信开发者工具、真机及完整全状态／全视图运行仍分别记录，不能由HTTP通过替代。

## 第二次正式复验与停写

- 派发 `takeover-20261025-salary-joint-http-02`，runId继续为 `preparation-development-20261024-01`。新handoff SHA256 `f8007ed3a1b8c77e5843f5b04ede07ab5dde4a1945dd3fe64acfa14f33b2f818`；客户端SHA256 `78730779dc2a4e103fa1ec5ae6b8d45d76503e2fce0c9673a920ebe7e8d5ffa9`。
- 原前端作者修订仅对齐来源身份与表单显示归一化，74业务来源和22项检查保持。PHP仅更新driver的salary固定handoff SHA、修订标记及协议测试期待值三行；生产PHP、process helper、普通peer／员工权限夹具保持。
- 本轮协议红测51项／382断言、2失败，绿测51项／566断言、exit0，独立保存在 `attempt-02-driver-protocol-*`。第一轮1例／25断言夹具HTTP未无条件重跑；本轮实际22项已覆盖其工资权限与恢复场景。
- 81前端／18PHP来源、原guard、scope／session／锁／进程前置独立通过后，另发实际执行。`attempt-02.json` 为真实driver stdout：`jointExecuted=true`、22／22全部通过、107次请求、exit0；严格五字段解析结果另存 `attempt-02-client-stdout-parsed.json`。
- 两组工资查看／编辑撤权及未决敏感投影恢复均已实际执行；客户端finally恢复后，PHP停HTTP再直读peer完整员工资料去update_time摘要、绑定、普通成员角色及原完整三权限，before／after严格相同。证据 `attempt-02-peer-fixture.json`，没有用店主peer制造权限通过。
- 新清理观察时间2026-10-25 21:09:09—21:09:32（Asia/Shanghai），晚于本轮执行结束：scope／session0、锁释放、临时PHP／Node退出、原64行摘要不变、原3307 PID48108保留。证据 `attempt-02-cleanup.json`；没有借用首轮清理。
- 81／18来源执行后保持；15个首轮结果／诊断／交接文件指纹逐一保持，首轮57请求／exit1没有被覆盖。`attempt-02-sources-before.json`／`attempt-02-sources-after.json` 保存本轮来源及首轮保全核对。
- 最终当前机器交接为 `.local/salary-joint-http-20261025/salary-joint-attempt-02-stage-result.json`。Codegraph增量同步确认1360文件、pending新增／修改／删除均0，未init；全部本任务PHP／driver／测试源码停写，实际HTTP结束。
- 本轮没有修改冻结前端或执行最终构建／微信。前端最终组件交付由原负责人另交，本执行者未验收其图片；最终构建、微信、真机和完整全状态／全视图仍未验证。是否接续下一事项由协调综合验收。
