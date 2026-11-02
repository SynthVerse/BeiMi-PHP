# 最终微信验收的隔离后端准备交接

日期：2026-10-26（Asia/Shanghai）

## 报告来源

- 生成原因：`takeover-20261026-final-runtime-backend-01`明确要求保留服务、私密会话交接、安全自检和生命周期证据。
- 主工作流：Matt Pocock。
- 实际使用的 Skill（按本轮采用顺序）：`Matt Pocock / implement`、`Matt Pocock / tdd`、`Matt Pocock / code-review`、`用户级自定义 / prevent-repeat-pitfalls`、`Matt Pocock / diagnosing-bugs`。
- 说明：只修改PHP测试／夹具及测试代理，不改生产PHP、前端、构建产物、guard或数据库配置。Codegraph和进程工具不是Skill。

## 当前准备状态

runId为`preparation-development-20261024-01`。保留服务已经实际启动，入口 **`http://127.0.0.1:64923/api/`**；本轮公开leaseId为`c8b4bc92-040d-441b-a38b-b1371f4bee0d`，截止 **2026-10-27 00:04:06（北京时间）**，不可通过重写控制文件延期。

状态为 **prepared、未领取、业务写入关闭**。父PHP进程67500、代理Node进程81912、专用PHP router进程79652，gateway仅监听127.0.0.1:64923。跨工具调用观察确认父／代理心跳继续推进，转发业务请求数为0。使用Windows隐藏独立进程保活，不依赖模型回合保持stdin；PHP父进程自己持有Node的stdin管道。

10项启动检查通过，启动前实际会话字节落盘检查0文件。夹具、会话及串行锁**按要求保留**，此处不宣称当前保留服务scope/session为0或最终清理完成。源代码已停写。协调者尚未放行模拟器连接和串行业务写入，本任务没有代为arm、claim或release。

原3307实例此前未监听，已按既有配置、二进制及数据目录恢复，没有初始化或重建。当前核对的MySQL监听PID为58104，仍用`E:/object/BeiMi/.local/mysql/my-test.ini`、原data-test目录和`beimi_test_inventory`；配置SHA和原64行逐表摘要保持。启动进程记录与实际监听记录分别留档，不以旧PID假定实例存续。

## 范围与身份

| 范围 | 当前内容 |
|---|---|
| 数据库 | 原guard核定`127.0.0.1:3307/beimi_test_inventory`；不触及3306、真实或共享环境 |
| 主门店／账簿 | tenant882641；账簿真实主键为`tenant_id=882641`，初始version1、draft；不存在独立book.id |
| 边界门店 | tenant882642，未初始化其carryover，仅供门店／身份隔离边界 |
| owner | user992641，主门店owner，可执行当前准备与完整建档 |
| peer | user992642，主门店member，真实合成员工绑定；初始仅`finance.opening.prepare`、`finance.opening.salary.prepare`、`finance.salary.view`，供工资查看／撤权路径使用 |
| otherStore | user992643，tenant882642 owner |
| admin | tenant_admin992641，tenant882641；只作API身份，不能当普通`user/store/status`页面登录身份 |
| 日期 | 截止2026-10-16，主门店carryover启用2026-10-17 |
| 事项 | payable、customer_refund、advance、supplier_refund、salary、reimbursement、expense_payable、deferred；遵守现有契约，不新增债务／来源版本接口 |
| 管理者 | 后端原聊天`01a16753-a59a-72d3-a6dd-b99b7c060f94`管理服务、控制、DB与最终清理；前端／MCP仍由原欠供应商负责人独占 |

接口保持真实鉴权、校验及业务逻辑；代理按固定方法＋路径转发，仅允许批准的合成身份。原SQL会话token只在PHP→Node stdin、后端内存和原会话表中使用，不向页面交付。页面领取的是另行随机生成、绑定本lease及截止时间的代理能力凭据。固定Host、拒Origin／CORS与受控单次领取不能认证任意本机原生进程；本方案以可信本机受控测试窗口为边界。

## 连接步骤与前端内存要求

1. **先由根协调明确切换现有原生验收窗口。** 前端确认仍为`E:/object/BeiMi/BeiMi-uniapp/unpackage/dist/dev/mp-weixin`，不重开项目。既有operator5／store4的真实token、Vuex值、持久化草稿不得读取、覆盖或清除。当前准备状态不允许自行领取。
2. 协调通知本后端后，仅本后端原子更新本lease的无秘密`control.json`：保留runId／dispatchId／leaseId／expiresAt，sequence加1，phase由prepared改armed。首次armed开启120秒领取窗口，重复写不能续期；超时未领会关闭本轮服务。
3. 前端只能在已经隔离的内存adapter闭包中请求`POST http://127.0.0.1:64923/__runtime/claim`。请求头为`Content-Type: application/json`及`X-Beimi-Runtime-Consumer: wechat-devtools-approved-project`；正文精确为`{"runId":"preparation-development-20261024-01","consumer":"wechat-devtools-approved-project"}`。这些固定字符串不是秘密。
4. claim返回直接JSON（不是业务code/data包装），其中actors各自的token字段是代理能力。返回值只留闭包内存，不写文件、argv、env、storage、日志、报告、聊天或可见fnSource字面量；MCP只返回成功布尔和非敏感身份ID，不能返回完整响应。网络结果未知时不能再次领取或根据同名认成功，应交协调决定中止／新lease。
5. 内存adapter必须覆盖`getStorageSync/setStorageSync/removeStorageSync/getStorageInfoSync`、Vuex token优先读取及认证失败清理、`store_status/current_store_id/storeInfo`。仅改请求header不足。隔离期间出站鉴权请求只准当前gateway，未映射路径明确拒绝，不能fallback到默认`https://lantu.makesgoal.com`或向其他后端发送代理能力。所有准备稿／回执／建档恢复存储也只进本lease内存；保留原函数／状态对象引用用于退出恢复，不读取真实token值。新lease使用全新overlay，禁止带入旧lease未决请求，lease不加入业务wire。
6. 将批准API原pathname映射到本机baseUrl，`header.token`使用当前actor的代理能力；后端再替换为内存中的真实合成SQL会话。先只读核对普通身份`user/store/status`与`finance/opening/guide`的门店、operator及actor_type；admin仅guide。只回报布尔／ID，不回显鉴权头。
7. 只读身份与隔离适配确认后，根协调另行放行写操作，由本后端原子将phase改released、sequence加1。必须已经成功claim；claim本身不开放写入。随后才由唯一前端执行者串行操作真实Page。服务运行期间不并发测试、额外HTTP脚本或数据库探针，不改业务fixture。

实际模拟器领取、内存adapter及原生HTTP页面行为**尚未执行**，不能从后端healthy推断这些步骤通过。域名／本机访问或Origin如受微信工具限制，按真实失败报告，不放宽入口或改用生产后端。

## 已验证结果与早期缺陷

- Node代理安全测试最终23／23通过，含claim类型／并发单次、控制序列／期限、四actor凭据替换、路径／方法、拒重定向、压缩及JSON转义凭据过滤、EOF与在途请求关闭。使用本地fake-upstream，不能当真实业务HTTP结果。
- PHP安全测试最终6例／55断言通过，含内存cache和既有Db连接缓存、过期／闸门、真实API路径、自然0／非0／强停、非凭据canary文件检测。
- 修复后attempt04完整只读启停exit0：三普通门店状态＋四guide身份、PHP默认拒写、真实账簿主键、代理健康共10检查通过。启动及停机检查未发现实际会话字节文件，broker自然exit0、未强杀；peer员工及成员全部列、权限原行摘要恢复。
- attempt04独立after确认scope／session0、锁释放、所有该轮进程结束、同3307配置／实例及原64行不变，10份代码前后指纹相同。文件名均带attempt编号，不用可变通用文件冒充旧轮次。
- 前两次自检的父CLI遗漏原bridge禁日志边界，两个本轮专属临时SQL日志各含4个合成会话值；这是真实缺陷，不改写为从未落盘。会话已由独立scope/session检查确认清空，已只对两个确属本轮PID的日志去敏8个值，未输出会话值，也未触碰真实窗口凭据。早期网络／清理通过不构成服务放行，原始结果和失效审查保留。另修正旧路径、book.id=0元数据、强停被误记成功、claim缺Content-Type等问题，红绿证据保留。
- 当前父CLI造session前、router鉴权前统一安装Privacy：关日志、禁SQL记录、全局及Db查询缓存改内存；最早记录父子runtime目录，ready前及异常独立finally检查实际会话字节，异常也清理／放锁。PIT-0109／0110分别记录编码过滤和父SQL日志根因与自动防线。
- 前端679来源和8个已产出构建物只读hash匹配，本任务没有构建、修改前端或操作微信。原8项已接受HTTP未重跑。
- Codegraph实际增量sync后1369文件，pending added／modified／removed均0；手动sync后两mjs也已通过codegraph_files确认索引，未init。双轴只读复核确认已发现问题修复，审查者未执行探针或访问运行服务。

## 存续、关闭与恢复

当前无秘密控制文件为`BeiMi-PHP/.local/final-runtime-backend-20261026/control.json`；`state.json`是代理心跳，`fixture-state.json`是父进程心跳。二者是实时状态，不作为不可变历史证据；本轮快照使用`service-attempt-01-observation-*.json`。

协调明确完成或中止后，仅本后端保留当前控制文件六字段，sequence加1、phase改stop，用同目录临时文件＋原子rename替换，不改expiresAt。代理先关闭领取／写闸、停止接入并结束在途请求；父进程确认Node自然结束后停PHP，再恢复完整peer资料、检查本轮凭据文件、清理合成scope／会话并放锁，生成`service-final.json`及`broker-stop.json`。

随后本后端另做独立`service-attempt-01-processes-after.json`／`scope-after.json`及64行／实例／配置／权限核对，才能声明最终清理通过。45分钟到期同样自动关闭并走正常清理。EOF／父消失会关闭代理；强杀不能保证finally执行，出现遗留时先报告并核对本lease来源、进程及范围，禁止盲目接管或清未知归属数据。截止后未完成的原生路径保持未验证，不跨lease复用稿／回执。

当前保留服务不能为证明scope0而提前清掉；正常存续阶段不执行额外DB探针。服务当前实际监听和心跳已核对，后续使用前仍应读当前lease与心跳，不能把旧ready快照当作永久存活。

## 证据与未完成项

证据根目录`BeiMi-PHP/.local/final-runtime-backend-20261026`。`repaired-runtime-safety-gate.json`记录修复后完整门槛；`parent-sql-logging-incident.json`和`selfcheck-prior-attempts-review.json`保留旧缺陷；`runtime-source-freeze.json`记录当前代码。正式当前交接为`runtime-backend-ready.json`，所有不可变证据及SHA列于其中。当前服务基线及ready另存`service-attempt-01-fixture-baseline.json`／`ready.json`，后续不覆盖。

尚未完成：模拟器实际claim和内存会话接入、根协调放行后的真实原生业务动作、最终服务恢复清理及其独立after。服务没有提供人为业务拒绝／写后丢响应／成功回读失败开关；门禁拒绝不算业务校验覆盖。这些原生故障层未验证，原有隔离HTTP证据按原来源保留，不伪造结果补齐。真机、完整状态路径及最终综合验收也不由本后端ready代替。
