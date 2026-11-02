# 记账准备客户预存来源关联契约

日期：2026-10-25（Asia/Shanghai）

## 报告来源

- 生成原因：协调明确要求提前给出可同步前端的精确契约与验证边界。
- 主工作流：Matt Pocock。
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`用户级自定义 / prevent-repeat-pitfalls`、`Matt Pocock / diagnosing-bugs`（实施、双轴复核及编排失误防护流程）。

后续扩展说明：供应商待退款必要PHP阶段已将来源投影和身份守卫共用于advance／supplier_refund，并补齐门店稿基线的新鲜派生及严格ID校验。当前合同及36例／2300断言回归见 [供应商待退款来源契约](finance-opening-supplier-refund-source-binding-20261025.md)。本文的两次联合结果仍保留为各自来源时点的历史证据；未声称本次重新执行advance客户端。
- 说明：当前阶段仅必要 PHP 与后端隔离验证；Codegraph 是工具，不是 Skill。前端由客户预存原任务独占，本任务不修改。

## 当前模型事实

- 唯一明确关联是 `finance_opening_source(tenant_id, opening_item_id)` 的真实行；类别、客户及 `source_snapshot` 保存正式来源身份。来源 ID 与 `finance_opening_item.id` 不同。
- 来源只在 `FinanceOpeningService::execute(confirm)` 建立，随后账本 `active` 整体禁止修改。正常准备稿没有历史客户收款外键，当前也没有从旧收款导入 advance 候选的业务。不得宣称正常已确认预存可被当前编辑入口改挂。
- `source_reference`、`首次手填-*`、`details.origin_reference` 都不能证明关联。正式下游来源引用为 `o:<finance_opening_source.id>`，不是人工凭据文本。
- 本次不新增关联、导入、迁移、来源版本或消费业务，不放松 active 不可变。

## 精确返回字段

仅新鲜读取的 advance 明细派生以下元数据；`GET /finance/opening`、`GET /finance/opening/guide` 及其真实 `opening_item` 候选保持同一身份：

```json
{
  "source_binding": {
    "linked": false,
    "subject_id": 123,
    "source_reference": "原明细的source_reference",
    "source_id": null,
    "source_ref": null
  }
}
```

查到认证租户、`opening_item_id` 对应真实来源，且类别、客户与冻结来源身份一致时：

```json
{
  "source_binding": {
    "linked": true,
    "subject_id": 123,
    "source_reference": "原明细的source_reference",
    "source_id": 456,
    "source_ref": "o:456"
  }
}
```

`source_reference` 始终是明细原字段，用于前端核对当前记录；`source_id`／`source_ref` 才是实际正式来源身份，绝不伪装成明细文本。查无关系才是 false；已有关系矛盾或不完整返回 `source_binding: null` 表示未知，不能降为 false 解锁。SQL 读取失败按读取失败处理。active 快照仅补派生投影，不重写 `confirmed_snapshot`。

## 保存约束

- 正常本笔保存、门店稿及显式 rebase 从当前认证租户的真实记录及关系判断，忽略客户端 `source_binding` 断言。已关联或未知关系不能改客户，不能借建档回填或伪造 HTTP 解除限制；原客户金额、备注的其他合法编辑不因这条身份守卫额外被锁。
- 确认无关联的手工记录允许按现有契约换成本门店有效主客户，不能按正 ID 或非空凭据一律锁死。
- 来源身份与账本版本沿用既有校验；正常 active 写入仍先被整体只读规则拒绝。旧成功键保留原成功事实，不重执行写入；前端成功后 GET 新鲜资料判定当前关联，不把旧成功快照当作当前关系。
- 本笔、暂存、说明、核清仍分离；本次不增加债项或核销。

## 当前进度与验证边界

PHP 投影与本笔／门店稿／显式 rebase 身份守卫已实现。定向后端真实 HTTP 红绿：初始2项／96断言失败于缺投影；补投影后无关联换客通过，仍有1项失败于已关联换客未拒绝；补守卫后2项／223断言通过。正常确认后 active 禁写、派生读取不改冻结快照，以及异常 draft 注入下 linked／unknown 的额外身份守卫分层验证。直接相关后端 HTTP 完整类33项／1894断言通过，包含上述2项，424次真实后端HTTP请求。正式前端联合另记下述首轮结果，不能以此后端绿测代替。

测试仅使用既有 3307／`beimi_test_inventory`、原 guard 和 HTTP 合成范围 882641／882642。原 Workflow 882101／882102 范围存在需保留的64行，不运行会清理该范围的整类测试。若需验证草稿阶段对已建立来源的额外身份守卫，会明确标注为现有关系下的异常账本状态注入，不宣称正常公开流程可产生该状态。

首次正式派发 `takeover-20261025-advance-joint-http-01` 的失败证据保留。原作者完成客户端断言修订后，协调重新派发 `takeover-20261025-advance-joint-http-02`；第二次执行结果见下文。本任务未修改冻结前端或客户端。

## 实现与证据位置

- 生产修改：`app/api/jxc/logic/FinanceOpeningService.php`；仅 advance 元数据与身份守卫，其余类别规则沿用。
- 回归：`tests/unit/PreparationHttpWorkflowTest.php` 中 `test_advance_unlinked_binding_is_authoritative_and_manual_records_can_change_customer`、`test_advance_confirmed_source_projection_and_fault_injected_identity_guards`。
- 当前绿证据：`.local/advance-source-binding-20261025/source-binding-green.xml` 和同名 `.log`；原始精确修改前快照、两轮红证据及scope-before也在该目录。无token或认证stdin输出。
- 独立规范轴、契约轴只读复核均无剩余确定问题；不将静态复核代替执行。
- `--target=advance` 已指向正式固定客户端，完整15项检查集合单独核对；缺／多项不通过，旧target不变。无DB协议测试39项／133断言通过。首轮失败后曾暂停联合执行；第二次已按新冻结来源及正式派发执行完成，未重跑旧客户端。

## 正式联合首轮结果与待处理事项

- attempt1：`jointExecuted=true`、exit1，8次传输；`initial_advance_guide`通过，`native_customer_exact_receipt`为首个未通过，后续13项未执行，不记为13个业务失败。首轮结果及退出码独立保留在`.local/advance-source-binding-20261025/advance-joint-attempt-01.json`与`advance-joint-attempt-01-exit.json`。
- 已确定客户端第207行的接缝断言错误：真实`common/api/customer.js`使用`withNameAliases`为传输追加`name`，持久化原命令仍不含该别名；直接比较wire body与原命令必不相等。无网络装载真实助手复现：原命令不变、原幂等键一致、唯一额外键为`name`、请求0。诊断见`native-wire-diagnostic.json`，不输出原体或认证数据。
- 最小作者修订：用真实`withNameAliases`生成预期wire载荷并作结构比较，继续严格断言原命令／键／门店／receipt.params不变、真实ID及资格、POST次数1。无需修改生产API或将别名写进原命令；本任务不改冻结前端或客户端。
- 第一次完整来源校验脚本将渲染条目`file`误当`path`，且编排未检查退出码仍启动，已明确承认并保留真实执行结果。修正为核对失败立即停止、核对与启动分开；后续完整核对88运行／10业务／24保护／46渲染来源均匹配。`joint-sources-before.json`明确记录它是首次尝试后核对，不倒填首次执行前证据。
- 本次失败后的新清理：scope/session=0、锁释放、无本次PHP/Node进程，原64行逐表指纹不变；原3307实例48108保持运行。`advance-joint-attempt-01-cleanup.json`引用新`scope-after-joint-first.json`及08:51Z进程核对，不使用前轮后端回归的旧scope-after冒充。

## 第二次正式联合结果与当前停写

- 派发 `takeover-20261025-advance-joint-http-02`，协议runId仍为 `preparation-development-20261024-01`。原作者修订后的客户端SHA256为 `91e390df6e151b8764c6f7708aa4420bdd3c675b9086eab2d69528a5a56bf998`；本任务未改共享前端、客户端、生产API或业务守卫。
- 执行前独立核对来源与安全前置，全部exit0后另行启动真实联合。88运行／10业务／24保护／46组件来源及2个渲染输入匹配，18个PHP相关来源与已测指纹一致；不存在并发数据库测试，原guard确认合成范围／会话为0、锁可取，原64行指纹不变。
- attempt2：`jointExecuted=true`、exit0，15／15核心检查全部通过，共67次真实请求；固定完整检查集合与执行期间来源不变检查均通过。结果、退出码与前置证据使用独立的 `advance-joint-attempt-02*` 文件，不覆盖首轮8次请求／exit1记录。
- 本轮新清理观察时间为2026-10-25 17:20:24—17:20:26（Asia/Shanghai）：scope／session均0、锁释放、无本次PHP／Node残留，原64行逐表指纹不变，原3307实例PID48108保留。证据为 `advance-joint-attempt-02-scope-after.json`、`advance-joint-attempt-02-processes-after.json` 及 `advance-joint-attempt-02-cleanup.json`。
- 必要PHP与封闭driver源码持续停写；此次仅执行新客户端的联合验证，没有无条件重跑已通过的33项后端HTTP（含专项2项／223断言）或39项协议测试。PHP Codegraph 已增量同步，1360文件、pending新增／修改／删除均0；未重新初始化。
- 当前汇总、源码指纹和独立证据位置见 `.local/advance-source-binding-20261025/advance-php-stage-result.json`。本阶段执行已结束，后续验收与下一事项由协调接续，不自行重复执行。
- 最终构建、微信开发者工具与真机不在本次联合执行范围，仍未验证；15项联合通过不代表完整全状态运行通过。

编排失误另按同根因去重登记`PIT-0107`，保持防护中；未宣称仓库单测已自动约束代理全部工具调用。
