# 原生会话条件放行与到期清理闭环

日期：2026-10-27（Asia/Shanghai）

## 报告来源

- 生成原因：本轮明确要求记录arm／claimed／异常，并在lease结束后完成独立清理核对。
- 主工作流：Codex 通用流程。
- 实际使用的 Skill：`Codex 通用流程 / 无专用 Skill`。
- 说明：当前仅做条件证据校验、公开文件与心跳观察、既有服务生命周期和独立清理核对；实现、红绿验证与安全修复的Skill来源按原后端交接保留。本次未修改代码、测试或配置。

## 实际结果

runId为`preparation-development-20261024-01`，后端派发`takeover-20261026-final-runtime-backend-01`，条件arm派发`takeover-20261026-native-claim-arm-01`。

本lease `c8b4bc92-040d-441b-a38b-b1371f4bee0d`未取得合格前端就绪证据，**未执行arm、未claim、未released、业务转发0**。控制文件序列始终0、phase仍prepared；代理最终公开状态为stop、claimed=false。

00:04:06.735北京时间到期；代理stop记录为00:04:07.101，父进程00:04:07结束。broker自然exit0、非强停，父服务结果passed=true。没有延期、创建新lease或重跑已接受的8项业务HTTP。

原`runtime-backend-ready.json`仍保留原SHA `3a2f7e33fdb076d1edd5687a03b3993e7c388d4090913a6a03ba097b00b2cb39`，只证明历史准备态。**旧入口及lease现已失效，不能再用于领取。**

## 条件检查与观察边界

根协调的条件放行要求：前端就绪文件来自当前派发，run／lease／开发项目路径一致，readyAt晚于连接派发，并明确证明loopback、存储隔离、原状态保持、单次claim等待已安装。

本后端先核对当前派发和控制schema，再只读观察指定`native-claim-consumer-ready.json`及现有父／代理心跳。定时记录共23条，时间范围为北京时间2026-10-26 23:48:05至2026-10-27 00:04:35，间隔约45秒，均未看到该文件；控制序列和claimed保持不变。此前工具读取也未取得文件。

这只能证明本轮没有取得合格证据，不能证明该文件从未短暂生成。loopback可达、存储隔离、原状态保持及单次等待四项均保持**未验证**，不能因服务健康就推断为true，不能提前arm。

后端没有访问模拟器、原窗口token或草稿，也没有给其他聊天发送消息。前端实际内存隔离和真实窗口状态不由本后端的观察结果代为验收。

## 已验证的恢复与独立清理

- 父进程记录peer员工全部列、成员全部列、权限原行的before／after SHA完全一致；实际会话字节落盘检查0文件。
- 00:05:27独立进程观察：相关PHP／Node测试进程为空；原MySQL监听PID58104及原配置SHA保持。
- 00:05:28独立guard观察：scope0、session0、锁可重新获取；原64行逐表摘要与服务启动前一致。
- 10份运行代码及18份关联后端来源与已接受冻结指纹一致。Codegraph当前pending added／modified／removed均0；本轮未修改代码，无需重新init或重复sync。
- 独立审查者仅只读比对新证据，支持上述恢复与观察时点清理结论，没有执行探针、请求或修改。

这里的scope／session0来自服务结束后的新观察，不引用attempt04自检的旧after，也不以finally自报代替独立检查。原两次SQL日志缺陷、脱敏及修复证据继续按原来源保留。

## 不可变证据

目录为`BeiMi-PHP/.local/final-runtime-backend-20261026`。

- 条件等待：`native-claim-arm-01-watch.jsonl`。
- 服务结果：`service-attempt-01-final.json`、`broker-stop.json`的不可变副本`service-attempt-01-broker-stop.json`。
- 最终公开状态／控制：`service-attempt-01-state-final.json`、`service-attempt-01-control-final.json`。
- 独立after：`service-attempt-01-processes-after.json`、`service-attempt-01-scope-after.json`、`service-attempt-01-cleanup.json`。
- 来源：`service-attempt-01-source-before-bound-to-launch.json`、`service-attempt-01-source-after.json`；前者是原冻结文件的同字节副本，原SHA已在启动记录中绑定。
- 当前机器闭环：`runtime-claim-arm-01-result.json`及其SHA sidecar，内含全部相关文件hash。原ready和被协调接受的证据不覆盖。

## 未完成项与后续

模拟器领取、内存适配器实际接入、原生页面业务写入及整体原生／真机验收均未由本轮完成。本后端没有把未观察到就绪文件算作前端功能失败，也没有把未执行的连接标为通过。

后续由根协调处理前端等待及下一阶段安排。如继续准备新lease，必须使用新的lease证据和全新内存overlay，不能延长或复用本已过期lease，也不能带入旧未决稿／回执。本轮后端服务、互斥和合成数据已清理完成。
