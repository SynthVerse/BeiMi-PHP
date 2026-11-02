# 原生就绪文件路径更正复核

日期：2026-10-27（Asia/Shanghai）

## 报告来源

- 生成原因：明确要求保留原结果、补充实际观察路径及当前存在性检查，并完成最小测试支撑修正。
- 主工作流：Matt Pocock。
- 实际使用的 Skill：
  - `Matt Pocock / diagnosing-bugs`（自动调用）。
  - `Matt Pocock / prevent-repeat-pitfalls`（自动调用）。
  - `Matt Pocock / tdd`（自动调用）。
  - `Matt Pocock / code-review`（自动调用，限定本次新增支撑与更正证据的双轴复核）。
- 说明：只处理`takeover-20261027-native-expired-lease-review-01`路径证据缺陷。以原始工具记录和本轮开始时文件为基准，不审查或覆盖已有业务改动；没有启动服务、数据库探针或业务HTTP。

## 已验证事实

批准目标为：

`E:/object/BeiMi/BeiMi-uniapp/docs/ui-previews/payable-preparation-20261023/implementation/final-integration-20261026/native-claim-consumer-ready.json`

原`runtime-claim-arm-01-result.json`的`conditionEvidence.path`却是：

`E:/object/BeiMi/BeiMi-PHP/BeiMi/BeiMi-uniapp/docs/ui-previews/payable-preparation-20261023/implementation/final-integration-20261026/native-claim-consumer-ready.json`

这是本轮必须更正的结果元数据错误。原结果SHA仍为`ae0be29b1425ae61fadaa81a2b3fa95711de305ecf79d81abcc1e716dca2f3a4`，原结果、watch日志、原闭环文档及被接受ready均未覆盖。

### 原watcher与结束记录分别做了什么

原始rollout在`2026-10-26T15:48:04.026Z`的调用`call_d98fb77122914117a55079765ec4b9b5`指定workdir为`E:/object/BeiMi`。watcher直接检查`Path('BeiMi-uniapp/docs/.../native-claim-consumer-ready.json').exists()`，没有调用`resolve()`。按这个调用及cwd，其目标是批准路径；本轮按同样表达式和cwd复算也一致。没有证据支持将它直接定性为“watcher检查了结果里的错误目录”。

结束记录调用`call_1e33826ff43b40e1befff66d84118f77`在`2026-10-26T16:09:39.667Z`指定PHP目录，使用`Path('../BeiMi-uniapp/...').resolve()`填路径字段。本轮在相同目录稳定复现：`abspath`是正确路径，`resolve/realpath`却生成了上述错误串。原表达式的四cwd回归也失败。缺陷是结束记录重新解析路径，缺少同一批准绝对路径的绑定；底层Windows／沙箱原因未验证。

23条旧watch行均记录`fileExists=false`，但没有记录cwd及实际绝对路径。这些行与原工具调用共同支持“按指定cwd的监听调用报告23次未观察到”，不能单凭行内容证明每次检查的绝对路径，更不能证明文件持续不存在或从未短暂生成。原结果的错误路径字段不再作为批准目标不存在的证据。

### 本轮正确目标存在性

北京时间`00:20:37.658`至`00:20:38.044`，从根目录、PHP、前端、测试目录分别运行新的只读入口，四次均记录同一个批准绝对路径，`exists=false`、`modifiedAt=null`。这只描述四个本轮观察时点；不存在的文件没有可报告的修改时间。

### 最小修正与验证

新增测试支撑`tests/support/preparation_native_consumer_evidence.py`：从自身绝对位置建立项目族根路径，直接使用批准目标，校验派发提供的绝对路径一致，不再调用`resolve()`。每行记录实际检查路径、cwd、观察时间、存在性及可用的修改时间；失败返回固定错误，不把不可读当作不存在。它不读取文件正文、凭据，不执行arm或claim。

公开CLI回归`tests/bridge/test_preparation_native_consumer_evidence.py`以派发中的字面路径为独立期望，覆盖四cwd一致性及四种错误目标拒绝。保留原表达式红测：4个子场景失败；修正后2例、8个子场景通过。首轮红测另遇沙箱临时目录删除限制，原日志保留；后续改用已有测试目录，纯路径红测没有该环境错误。

旧监听已结束，没有替换历史监听或伪造旧观察。本支撑是后续监听与记录的统一入口，实际新服务接入需等下一次明确派发。

### 独立双轴复核与索引

Standards轴无确定违规或必要的smell建议；Spec轴无确定遗漏、错误实现或范围扩大。两位审查者只读核对源码、派发和现有红绿证据，没有代跑测试、DB、HTTP或进程探针。测试通过结论来自本根执行记录。

两个Python文件语法检查通过。已实际执行Codegraph增量同步，新增2文件；最终1371文件、pending added／modified／removed均0。原10份运行来源及18份关联来源SHA、原结果／watch／ready／闭环文档SHA重新核对不变。本次没有把缺少的新服务验收计为通过。

## 未验证及结论边界

- 没有回溯性补造23条旧观察的cwd或路径字段。
- 未验证底层`realpath`异常的系统原因；本次修正避免依赖该行为。
- 四项原生就绪条件、模拟器领取、实际页面写入及真机仍未因此通过。
- 原lease仍已关闭。本轮未创建或延期lease，未重跑业务HTTP；未arm、未claim、零转发和独立清理有单独证据，本路径元数据错误不改变这些结果。
- Python新增支撑不在原10份运行来源冻结中；原10份来源未变。不得把新辅助器算作旧服务当时已使用的代码。

## 后续及证据

后续派发使用批准的绝对路径并复用统一入口，逐次保存其公开观察行。PIT-0111记录此防线；不通过创建错误目录或复制前端文件掩盖问题。

证据目录：`BeiMi-PHP/.local/final-runtime-backend-20261026`。机器更正审计为`runtime-claim-arm-01-path-review.json`；原调用摘录、原表达式复现、四cwd当前观察、红绿日志、独立复核和Codegraph状态随该审计列出SHA。原`runtime-claim-arm-01-result.json`与原闭环文档继续保留，仅路径结论按本更正解释。
