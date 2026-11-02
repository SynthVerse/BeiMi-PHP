# 供应商待退款：真实来源身份与恢复接口契约

日期：2026-10-25（Asia/Shanghai）

## 报告来源

- 生成原因：正式必要PHP派发要求保留可供前端消费的精确契约及验证证据。
- 主工作流：Matt Pocock。
- 实际使用的 Skill：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`用户级自定义 / prevent-repeat-pitfalls`、`Matt Pocock / diagnosing-bugs`。
- 说明：本文件描述PHP契约与隔离HTTP验证，不代替尚未冻结的供应商退款前端联合验收。Codegraph是代码工具，不是Skill。

## 当前范围与业务事实

必要PHP派发：`takeover-20261025-supplier-refund-source-binding-php-01`；runId：`preparation-development-20261024-01`。必要PHP阶段未获前端联合许可；之后已按 `takeover-20261025-supplier-refund-joint-http-01` 正式派发完成18项真实联合，结果见下文。本任务未写共享前端、模板或客户端。

`category=supplier_refund`、`entry_contract=2`、`source_mode=detail`，无到期日。承接截至截止日尚未收到的供应商退款，不重复冲销采购，也不在保存、暂存或独立说明时新增实际收款或正式来源。

可信来源关系唯一来自认证租户下的 `finance_opening_source(tenant_id,opening_item_id)`。人工 `source_reference`、`首次手填-*`、`details.origin_reference`、正明细ID和名称均不能证明关联。正式来源引用为 `o:<finance_opening_source.id>`，其ID与明细ID不同。

当前公开流程在正式确认时创建来源，随后账本为active并整体只读。不存在通过正常公开流程创建的可编辑linked草稿；相关写入身份守卫通过真实确认生成来源后，明确注入异常draft状态验证，不把active自然禁写算作草稿守卫通过。

## 新鲜读取的五字段投影

新鲜 `GET /finance/opening`、`GET /finance/opening/guide` 的supplier_refund明细、对应opening_item候选及本操作者门店稿 `base_item` 共用投影。客户预存advance也继续使用同一实现。

```json
{
  "source_binding": {
    "linked": false,
    "subject_id": 123,
    "source_reference": "原明细人工标识",
    "source_id": null,
    "source_ref": null
  }
}
```

| 当前事实 | 响应 |
| --- | --- |
| 认证租户中无真实来源关系，明细身份完整 | `linked=false`，subject_id为明细供应商正安全整数number，source_reference精确保留原文，source_id/source_ref为null。 |
| 有一致真实来源 | `linked=true`；类别、供应商及冻结快照tenant/id/category/subject/reference均一致，source_id为真实正安全整数number，source_ref为严格的`o:<id>`。 |
| 身份缺失、损坏或矛盾 | 整个 `source_binding=null`，表示未知；不能降为false解锁。 |
| 来源查询SQL失败 | 读取失败，不能降为无关联。 |

ID校验拒绝浮点、布尔、负值、零、尾随字符、非规范数字文字和超过 `9007199254740991` 的数值。内部可消费数据库的规范十进制字符串，输出明确转成number。人工标识不会被正式o引用替换。

门店稿base_item仅在响应副本上派生当前绑定；原form、base_version及其他base_item字段保留，读取不更新reviews或confirmed_snapshot。旧幂等成功响应仍是原成功时点的事实，当前关系须另作新鲜GET，不能改写历史回执。

## 写入与字段边界

正常本笔保存、门店暂存和显式rebase均在既有门店事务中重新读取真实关系，不信任客户端source_binding。linked或unknown不能换供应商或改来源身份；同供应商合法金额、备注编辑继续允许。明确unlinked手填记录可换当前门店有效供应商，保留原record.id和人工reference。

老板录入方式编辑已有记录时，服务端沿用原人工reference；删空或伪造该字段不能解绑。门店稿删空隐藏reference按原记录身份核对，显式替换成另一标识则拒绝。异租户记录不可读取或写入；active整体不可写的规则继续生效。没有新增关联、导入、迁移、来源版本或消费业务。

退款未确认可以保存为待核实，金额未知保存为null。已填主金额必须大于0、最多两位小数，长度上限沿当前金额契约。summary及非空due_date在本笔保存时拒绝；未完成编辑稿不等于已经通过本笔字段校验。核清另需金额完整、退款事项与退款确认等当前完整性门槛；暂存、说明保存不替代核清。

不同供应商ID清退款确认、同ID或取消保留，是前端的输入依赖；服务端来源与归属校验独立生效。PHP不根据客户端提供的绑定字段猜测供应商是否改变。

## 供应商建档与未知结果

沿用真实完整建档字段 `supplier_name/phone/contact/address/remark`、`expected_tenant_id`及原幂等键。真实API只在wire层通过withNameAliases追加name；不修改持久化原命令，不引入客户专有字段。

`GET /master-data/creation/result` 使用 `kind=supplier`、原键和原门店。unknown不是未创建，不能用同名检索替代原请求结果。成功仅提供已创建对象及原回执，选择不自动保存退款业务；成功事实与当前对象可选资格分开判断。原回执、权限与租户隔离机制未另建或削弱。

## 实现、验证与未完成项

- 生产实现：`app/api/jxc/logic/FinanceOpeningService.php` 的共用来源投影、门店稿基线投影和身份守卫；没有修改正式快照存储或来源创建流程。
- 自动化防线：`tests/unit/PreparationHttpWorkflowTest.php` 的两组advance／supplier_refund参数化来源用例及独立supplier_refund字段契约用例。
- 初始红测5例／284断言，4处失败；仅补投影后的supplier来源守卫红测1例／160断言，异常draft改挂仍被接受。两轮证据均保留。
- 专项绿测5例／629断言通过；完整 `PreparationHttpWorkflowTest` 回归36例／2300断言、524次真实TCP HTTP请求、exit0，已包含专项5例，不重复累加。此层使用生产路由、鉴权和测试库，尚不是供应商退款真实前端客户端联合。
- 独立Standards／Spec只读复核均未发现剩余确定问题；复核人员未执行测试，静态复核不冒充运行结果。
- 测试仅使用既有127.0.0.1:3307／beimi_test_inventory、原guard及882641／882642合成范围。原882101／882102的64行不清理，不运行会清理该范围的其他整类测试。
- 各绿测前后源码指纹一致；最终scope／session为0、锁释放、无测试PHP／Node残留，原64行逐表指纹不变，原3307实例PID48108保留。最终进程观察时间2026-10-25 18:07:22（Asia/Shanghai），未用前一事项的旧清理证据代替。
- 必要PHP阶段证据目录：`.local/supplier-refund-source-binding-20261025`；机器交接为 `supplier-refund-php-stage-result.json`，包含该阶段来源、执行、清理和Codegraph证据。当时没有修改封闭driver，原39项协议证据保留为历史；后续联合派发的driver修改及45项协议结果另列下文。
- 来源ID宽松强转根因及真实红绿防线登记 `docs/agents/pitfalls.md` 的PIT-0108；未以文档提醒代替自动化。SQL异常分支经只读审查确认没有降级false，未通过破坏共享表结构注入SQL故障。
- 供应商退款前后端联合HTTP已按冻结客户端和正式派发执行，18项／72请求／exit0；该层与前述36例后端HTTP回归分别记录，不互相替代。
- 最终构建、微信开发者工具、真机及全状态／全视图运行未验证。客户预存先前15项／67请求通过保留为当时来源的历史证据，本阶段以受影响PHP回归验证当前改动。

## 首次正式前后端真实联合HTTP

- 派发：`takeover-20261025-supplier-refund-joint-http-01`；协议仍为 `beimi-preparation-http-bridge/v1`、固定runId及2026-10-16截止日、四身份。token仅经原stdin管道传入，未放入参数、环境变量、证据或日志。
- 原作者冻结客户端SHA256：`49098d9a4952d3f16e6184e9ca3191eab290e168a93128506082d8e238cfceda`；handoff SHA256：`2c29c6b2c50b9020ca97455ecb70a1ef60fd3610c0e92da8963c134990dfb640`。74运行来源、3客户端、3支持文件共80项及18个PHP相关来源在执行前独立核对，执行后均保持。
- 封闭driver仅增加固定 `--target=supplier_refund`、固定客户端路径、固定handoff SHA与80来源白名单、硬编码18项精确结果集合。原process helper、guard、stdin、解析、超时及其他target保持。无DB协议红测45项／156断言、2失败；绿测45项／346断言、exit0；独立Standards／Spec只读复核无剩余确定问题。
- 所有来源、静态预检、范围／会话／锁和进程前置独立exit0后，另发实际执行。attempt1真实执行为 `jointExecuted=true`、18／18核心检查通过、72次请求、exit0，8项driver外层检查全通过。覆盖完整原生供应商建档与精确回执、不同ID清确认、多笔身份、金额校验、未知结果／成功仅GET、回执保留失败、暂存、冲突逐字段重新应用／采用最新、已删显式新增、待补充、核清及独立说明。
- linked／unknown事务身份守卫继续依据前述真实confirm后明确异常draft注入测试；本次正常owner_manual无关联联合链不冒充关联草稿守卫或全部编号状态已通过。
- 本轮新清理时点为2026-10-25 18:42:30—18:42:34（Asia/Shanghai），晚于执行结束：scope／session均0、锁释放、无本次PHP／Node残留、原64行指纹不变，原3307 PID48108保留。未引用必要PHP阶段的旧清理作为本轮结果。
- 证据目录：`.local/supplier-refund-joint-http-20261025`；结果 `attempt-01.json`、退出码 `attempt-01-exit.json`、前后来源 `attempt-01-sources-before.json`／`attempt-01-sources-after.json`、新清理 `attempt-01-cleanup.json`、最终机器交接 `supplier-refund-joint-stage-result.json`。PHP Codegraph 1360文件、pending新增／修改／删除均0，未重新初始化。
- 本轮没有重跑未改变的36例后端HTTP类，没有修改业务PHP或冻结前端；driver／协议测试源码及实际HTTP进程已停止。本文件在联合后只补充结果记录，冻结前端所确认的字段和业务合同保持。
- 组件截图由原前端作者独立收尾，本执行者未验证其最终图片；最终构建、微信、真机及全状态／全视图仍未验证。是否放行下一事项由协调综合验收，不能仅凭本次HTTP通过自动推进。
