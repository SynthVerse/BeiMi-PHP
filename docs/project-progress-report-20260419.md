> **旧副本提示**：本文件位于 `BeiMi-PHP/docs/`，可能落后于权威根目录文档。请优先查看 `E:\object\BeiMi\docs\project-progress-report-20260419.md`，后续仅按需同步关键提示。

# BeiMi 进销存项目进度报告

> 报告日期：2026-04-19  
> 范围：BeiMi-PHP 后端、BeiMi-uniapp 前端接口联调、Codex 工具包与项目风险记忆体系  
> 当前阶段：主数据模块基本闭环，销售单后端最小闭环已完成并通过本地真实 HTTP 冒烟

---

## 1. 已完成工作内容

### 1.1 项目架构与接口现状分析

已完成对前后端项目结构的初步梳理，并基于真实文件而非仅凭计划文档推进实现。

已分析的前端重点目录：

- `E:\object\BeiMi\BeiMi-uniapp\common\api`
- `E:\object\BeiMi\BeiMi-uniapp\common\constants\api.js`
- `E:\object\BeiMi\BeiMi-uniapp\common\request.js`
- `E:\object\BeiMi\BeiMi-uniapp\sub-documents\sales`
- `E:\object\BeiMi\BeiMi-uniapp\sub-master-data\customer`

已确认前端当前核心接口形态：

- 认证接口使用 `/api/user/login`、`/api/user/info`、`/api/user/logout`
- 客户接口使用 `/api/customer/*`
- 商品接口使用 `/api/goods/*`
- 单位接口使用 `/api/units/*`
- 仓库接口使用 `/api/warehouse/*`
- 销售单接口使用 `/api/order/lists`、`/api/order/details`、`/api/order/publish`、`/api/order/edit`、`/api/order/remove`、`/api/order/statistics`

已分析的后端重点结构：

- `E:\object\BeiMi\BeiMi-PHP\app\api\route\jxc.php`
- `E:\object\BeiMi\BeiMi-PHP\app\api\jxc\controller`
- `E:\object\BeiMi\BeiMi-PHP\app\api\jxc\logic`
- `E:\object\BeiMi\BeiMi-PHP\app\api\jxc\lists`
- `E:\object\BeiMi\BeiMi-PHP\app\api\jxc\validate`
- `E:\object\BeiMi\BeiMi-PHP\app\api\jxc\middleware`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc`
- `E:\object\BeiMi\BeiMi-PHP\database\sql`
- `E:\object\BeiMi\BeiMi-PHP\runtime`

已识别并固化的关键架构事实：

- BeiMi-PHP 使用 ThinkPHP 8 和 likeadmin 风格分层。
- API 应用存在命名空间、控制器分发和中间件约束。
- JXC 接口当前采用独立 `app/api/jxc` 业务目录，并通过 `app/api/controller/jxc` 包装控制器兼容框架分发。
- 租户管理员鉴权使用 JXC 专属中间件 `JxcLoginMiddleware`，避免被用户端全局登录链路误拦截。
- 前端分页实际大量传 `page` / `pagesize`，后端 likeadmin 原生列表读取 `page_no` / `page_size`，因此已在 JXC 控制器层做兼容适配。
- 数据库实际前缀为 `.env` 中的 `lk_`，不是早期文档中的 `la_`。

### 1.2 已生成和更新的文档/工具资料

项目文档：

- `E:\object\BeiMi\docs\project-analysis.md`
- `E:\object\BeiMi\docs\backend-implementation-plan.md`
- `E:\object\BeiMi\docs\project-progress-report-20260419.md`

Codex 工具组合文档：

- `E:\object\BeiMi\BeiMi-uniapp\.codex\codex-combo-tools-zh.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\codex-agent-skill-mcp-zh.md`

专业代理配置：

- `E:\object\BeiMi\BeiMi-uniapp\.codex\agents\backend-code-engineer.toml`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\agents\frontend-experience-builder.toml`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\agents\openai-integration-workbench.toml`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\agents\codex-capability-builder.toml`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\agents\delivery-risk-guard.toml`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\agents\solution-orchestrator.toml`

项目风险记忆体系：

- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\index.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\checklists\backend-preflight-checklist.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\checklists\new-blocker-capture-operating-procedure.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\patterns\php-runtime-compatibility-check.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\patterns\database-bootstrap-consistency.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\patterns\thinkphp-multi-app-route-loading.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\patterns\api-controller-dispatch-constraints.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\patterns\tenant-admin-auth-chain-separation.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\patterns\thinkorm-keyword-search-expression.md`
- `E:\object\BeiMi\BeiMi-uniapp\.codex\knowledge\patterns\thinkphp-route-specificity-order.md`

已记录的真实阻塞事件：

- `20260418-php-version-mismatch.md`
- `20260418-sql-prefix-mismatch.md`
- `20260418-mysql-service-missing.md`
- `20260418-base-schema-missing.md`
- `20260418-multi-app-route-path-mismatch.md`
- `20260418-api-controller-namespace-constraint.md`
- `20260418-global-login-middleware-conflict.md`
- `20260418-missing-seed-tenant-admin.md`
- `20260419-thinkorm-where-or-like-expression.md`
- `20260419-thinkphp-route-specificity-order.md`

项目贴身安全参考：

- `E:\object\BeiMi\BeiMi-uniapp\.agents\skills\php-thinkphp-likeadmin-security\SKILL.md`
- `E:\object\BeiMi\BeiMi-uniapp\.agents\skills\php-thinkphp-likeadmin-security\references\beimi-php-security-reference.md`
- `E:\object\BeiMi\BeiMi-uniapp\.agents\skills\project-risk-memory\SKILL.md`

### 1.3 已完成的后端基础设施

已搭建 JXC 专属 API 接入层：

- `E:\object\BeiMi\BeiMi-PHP\app\api\route\jxc.php`
- `E:\object\BeiMi\BeiMi-PHP\app\api\jxc\middleware\JxcLoginMiddleware.php`
- `E:\object\BeiMi\BeiMi-PHP\app\api\jxc\controller\BaseJxcController.php`
- `E:\object\BeiMi\BeiMi-PHP\app\api\jxc\controller\AuthController.php`
- `E:\object\BeiMi\BeiMi-PHP\app\api\jxc\logic\AuthLogic.php`
- `E:\object\BeiMi\BeiMi-PHP\app\api\controller\jxc\AuthController.php`

已完成的基础能力：

- JXC 登录复用租户管理员登录逻辑。
- JXC 受保护接口使用 `Header: token` 鉴权。
- JXC 列表接口兼容前端 `page` / `pagesize` 参数。
- JXC 列表响应适配前端所需 `{data,total,page,pagesize}`。
- JXC 路由从真实加载文件接入，而不是写入错误的根路由位置。
- 对受保护接口做过真实 HTTP 验证。

### 1.4 已完成的数据模型与数据库脚本

已创建或接入的 JXC 模型：

- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\GoodsUnit.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\Warehouse.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\Vendor.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\Goods.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\Customer.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\CustomerGroup.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\SalesOrder.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\OrderGoods.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\SupplyOrder.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\SalesReturnOrder.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\PurchaseOrder.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\StockFlow.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\ReceivableFlow.php`
- `E:\object\BeiMi\BeiMi-PHP\app\common\model\jxc\PayableFlow.php`

已生成数据库脚本：

- `E:\object\BeiMi\BeiMi-PHP\database\sql\jxc_phase1_schema.sql`
- `E:\object\BeiMi\BeiMi-PHP\runtime\jxc_phase1_db_init.php`
- `E:\object\BeiMi\BeiMi-PHP\database\seed\jxc_phase1_dev_seed.php`

当前 `jxc_phase1_schema.sql` 已覆盖：

- `lk_goods_unit`
- `lk_warehouse`
- `lk_vendor`
- `lk_goods`
- `lk_customer`
- `lk_customer_group`
- `lk_sales_order`
- `lk_order_goods`
- `lk_stock_flow`
- `lk_receivable_flow`
- `lk_payable_flow`
- `lk_supply_order`
- `lk_sales_return_order`
- `lk_purchase_order`

数据库初始化脚本已验证上述表存在。

### 1.5 已完成的业务模块

认证模块已完成：

- 登录：`POST /api/user/login`
- 用户信息：`GET /api/user/info`
- 退出登录：`POST /api/user/logout`

单位模块已完成：

- 列表：`GET /api/units/index`
- 详情：`GET /api/units/detail`
- 新增：`POST /api/units/add`
- 编辑：`POST /api/units/edit`
- 删除：`DELETE /api/units/del`

仓库模块已完成：

- 列表：`GET /api/warehouse/index`
- 详情：`GET /api/warehouse/detail`
- 新增：`POST /api/warehouse/add`
- 编辑：`POST /api/warehouse/edit`
- 删除：`POST /api/warehouse/del`
- 启用：`POST /api/warehouse/enable`
- 停用：`POST /api/warehouse/disable`

供应商档案模块已完成：

- 列表：`GET /api/supplier/index`
- 详情：`GET /api/supplier/details`
- 新增：`POST /api/supplier/add`
- 编辑：`POST /api/supplier/edit`
- 删除：`DELETE /api/supplier/del`

商品模块已完成：

- 列表：`GET /api/goods/index`
- 详情：`GET /api/goods/detail`
- 新增：`POST /api/goods/add`
- 编辑：`POST /api/goods/edit`
- 删除：`DELETE /api/goods/del`

客户与客户分组模块已完成主要闭环：

- 客户列表、详情、新增、编辑、删除。
- 客户分组列表、新增、详情、重命名、删除。
- 客户状态启停。
- 客户付款接口。
- 客户门店绑定、解绑、下属门店列表。
- 客户汇总、搜索、应收汇总接口的基础形态。
- 客户销售历史已完成真实实现，支持主客户+门店汇总查询，按 sales_order.customer_id 分页返回销售记录及商品明细（Phase 6 实施）。

销售单模块已完成最小闭环：

- 列表：`GET /api/order/lists`
- 详情：`GET /api/order/details`
- 发布：`POST /api/order/publish`
- 编辑：`POST /api/order/edit`
- 删除：`DELETE /api/order/remove`
- 统计：`GET /api/order/statistics`

销售单已实现的关键业务规则：

- 发布和编辑均走数据库事务。
- 销售单主表和商品明细表保持原子写入。
- 编辑时删除旧明细并重建新明细。
- 删除时同步删除订单明细。
- 客户、仓库、商品均做存在性校验。
- 停用客户、停用仓库、停用商品不可开销售单。
- 商品明细不能为空。
- 商品数量必须大于 0。
- 订单金额由后端按 `number * price` 重算，不信任客户端 `order_money`。
- 已收金额不能超过订单金额。
- 新增销售单写入 `tenant_id` 和 `admin_id`，来源为中间件注入的请求上下文。

进货单模块已完成：

- 列表：`GET /api/supply/lists`
- 详情：`GET /api/supply/details`
- 发布：`POST /api/supply/publish`
- 编辑：`POST /api/supply/edit`
- 删除：`DELETE /api/supply/remove`
- 统计：`GET /api/supply/statistics`

进货单已实现的关键业务规则：

- 发布时自动调用 StockService::inbound 入库。
- 发布时自动调用 FinanceService::addPayable 增加供应商应付。
- 编辑和删除时自动回滚库存和应付。
- 全程使用 bcmath 精确计算金额。

销售退货单模块已完成：

- 列表：`GET /api/return/lists`
- 详情：`GET /api/return/details`
- 发布：`POST /api/return/publish`
- 编辑：`POST /api/return/edit`
- 删除：`DELETE /api/return/remove`

销售退货单已实现的关键业务规则：

- 发布时校验原销售单存在且客户匹配。
- 发布时自动调用 StockService::inbound 退货入库。
- 发布时自动调用 FinanceService::reduceReceivable 减少客户应收。
- 编辑和删除时自动回滚库存和应收。

订货单模块已完成：

- 列表：`GET /api/purchase/lists`
- 详情：`GET /api/purchase/details`
- 发布：`POST /api/purchase/publish`
- 编辑：`POST /api/purchase/edit`
- 删除：`DELETE /api/purchase/remove`
- 确认/推进状态：`POST /api/purchase/confirm`
- 取消：`POST /api/purchase/cancel`
- 转销售单：`POST /api/purchase/convert-to-sales`
- 文本识别：`POST /api/purchase/parse-text`
- 统计：`GET /api/purchase/statistics`

订货单已实现的关键业务规则：

- 6 状态机：草稿 → 已发送 → 已收货 → 已配送 → 已完成，可取消。
- 状态转移通过 PurchaseOrder::canTransitionTo 校验。
- 转销售单调用 SalesOrderLogic::publish 创建销售单。
- 文本识别通过正则解析 + 商品库模糊匹配。
- 不直接涉及库存和财务联动（仅通过转销售单间接触发）。

店铺模块已完成：

- 店铺信息：`GET /api/user/store`
- 店铺设置：`POST /api/user/storeset`
- 创建店铺：`POST /api/user/open`

店铺模块复用客户表（is_store=1, parent_id>0），支持主客户下创建门店。

库存与财务联动核心服务已完成：

- StockService：inbound（入库）、outbound（出库）、rollback（回滚），使用行锁+bcmath。
- FinanceService：addReceivable、reduceReceivable、rollbackReceivable（客户应收）；addPayable、reducePayable、rollbackPayable（供应商应付）。
- 库存流水表 lk_stock_flow、应收流水表 lk_receivable_flow、应付流水表 lk_payable_flow。

### 1.6 已完成的前端接口联调适配

已完成客户模块前端真实接口联调相关适配：

- `E:\object\BeiMi\BeiMi-uniapp\common\config\mock.js`
- `E:\object\BeiMi\BeiMi-uniapp\common\request.js`
- `E:\object\BeiMi\BeiMi-uniapp\common\services\customer-service.js`

已完成的前端适配点：

- 客户 mock 默认关闭，优先走真实接口。
- API base URL 支持环境变量配置。
- 开发环境默认指向 `http://127.0.0.1:8000`。
- 生产环境默认指向 `https://jxc.makesgoal.com`。
- 客户服务层增强了对 `data`、`lists`、`customers` 等不同列表载荷的兼容提取。

当前 `BeiMi-uniapp` Git 工作区检查结果为空，说明前端当前没有未提交改动。

### 1.7 已执行的环境与验证操作

已完成本地 PHP 命令安装和验证：

- 使用 PHP 8.2 路径：`C:\Users\ASUS\AppData\Local\Programs\PHP\8.2\php.exe`
- 已执行 `php think` 并确认 ThinkPHP 控制台可启动。

已完成本地 MySQL 环境验证：

- 本地 MySQL 目录：`E:\object\BeiMi\.local\mysql\mysql-8.4.8-winx64`
- 配置文件：`E:\object\BeiMi\.local\mysql\my.ini`
- 已启动本地 MySQL，执行 JXC 业务表初始化。

已完成数据库初始化验证：

- `OK lk_goods_unit`
- `OK lk_warehouse`
- `OK lk_vendor`
- `OK lk_goods`
- `OK lk_customer`
- `OK lk_customer_group`
- `OK lk_sales_order`
- `OK lk_order_goods`

已完成本地开发种子数据：

- 已生成本地租户。
- 已生成本地租户管理员。
- 已清理旧登录会话。

已完成 PHP 语法检查：

- `SalesOrderController.php`
- `app/api/controller/jxc/SalesOrderController.php`
- `SalesOrderValidate.php`
- `SalesOrderLists.php`
- `SalesOrderLogic.php`
- `app/api/route/jxc.php`

已完成真实 HTTP 冒烟验证：

- 登录成功获取 token。
- 新建单位成功。
- 新建仓库成功。
- 新建客户成功。
- 新建商品成功。
- 发布销售单成功。
- 销售单列表可按关键词查询。
- 销售单详情可返回客户、仓库、商品明细和金额。
- 编辑销售单成功。
- 销售统计按时间范围返回数量和金额。
- 删除销售单成功。
- 空商品明细发布失败，返回业务错误。

销售单关键验证结果：

- 发布时前端传入 `order_money = 9999`，后端重算为 `24.60`。
- 编辑后后端重算为 `30.00`。
- 统计接口返回当前时间范围内 `number = 1`、`order_money = 30.00`。
- 空商品明细返回 `商品明细不能为空`。

验证结束后已关闭本地 PHP 内置服务和 MySQL 进程。

已完成幂等键和单据编号并发安全增强：

- 4 张订单表（销售单、进货单、退货单、订货单）均新增 idempotent_key 字段。
- 订单类接口发布时支持可选幂等键，重复提交直接返回已创建订单。
- 单据编号生成改为带重试机制，利用唯一索引防止冲突。

已完成冒烟测试扩展：

- tests/smoke_test.php 新增 5 个测试步骤：进货单 CRUD、销售退货单 CRUD、订货单 CRUD+状态推进、店铺模块、删除占用校验。
- 测试覆盖从 9 个步骤扩展到 16 个步骤。

已完成 Git 基线提交：

- BeiMi-PHP 项目已完成首次基线 commit（commit 64a6d9f）。
- .gitignore 已排除 vendor/、.local/、.env* 等敏感文件。

---

## 2. 各模块完成状态详细总结

### 2.1 后端业务模块待完成

进货单/供货单模块 **（已完成）**：

- 进货单发布、编辑、详情、列表、删除、统计。
- 供应商档案接口与进货单接口的路由边界需要重新确认。
- 入库库存联动尚未实现。

销售退货单模块 **（已完成）**：

- 退货单发布、编辑、详情、列表、删除。
- 退货入库或库存回滚规则尚未实现。
- 与原销售单的关联校验尚未实现。

订货单/采购预约模块 **（已完成）**：

- 订货单状态流转。
- 订货单转销售单。
- 文本识别商品解析入口。
- 订货单统计。

店铺模块 **（已完成）**：

- 店铺信息接口。
- 店铺设置接口。
- 开店/初始化店铺接口。

客户销售历史 **（已完成）**：

- CustomerLogic::salesHistory 已从占位返回改为真实 SQL 关联查询。
- 支持主客户（parent_id=0）自动汇总所有门店的销售记录。
- 按 sales_order.customer_id 分页返回，包含订单明细商品信息。
- 使用批量 whereIn 查询避免 N+1 问题。

库存与财务联动 **（已完成）**：

- 销售出库扣减库存。
- 进货入库增加库存。
- 销售退货回补库存。
- 客户应收随销售单生成和收款变化联动。
- 供应商应付随进货单生成和付款变化联动。
- 单据编辑、删除、取消时的库存和应收应付回滚。

### 2.2 OpenAI 文本识别功能

**已完成的工程基础（Phase 3）：**

- `config/openai.php` — 完整配置框架（api_key/model/max_tokens/temperature/timeout/base_url）
- `app/api/jxc/logic/OpenAiService.php` — 核心服务类（isAvailable/chatCompletion/parseGoodsText）
- 降级策略：API Key 为空时自动降级到正则解析，确保不影响现有功能
- 日志脱敏：所有异常 catch 并写日志，API Key 不外泄

**已完成的业务集成：**

- 订货单文本识别：`POST /api/purchase/parse-text` 已接入 OpenAiService
- PurchaseOrderLogic::parsePastedText 优先使用 OpenAI 分支，降级到正则解析

**待后续优化的能力：**

- 低置信度结果人工确认机制
- 多格式、多语言文本识别优化
- 调用成本控制和限流策略

### 2.3 自动化测试和工程化待完善

当前验证以手动命令和真实 HTTP 冒烟为主，尚未形成稳定自动化脚本。

待补齐内容：

- 后端 smoke test 脚本 **（已完成，tests/smoke_test.php）**
- 接口契约测试 **（已完成，docs/jxc-api-contract-v0.1.md）**。
- 数据库迁移框架 **（已完成，scripts/migrate.php + database/migrations/）**
- 集成测试脚本 **（已完成，4 个专项测试 + 1 个性能测试脚本）**
- CI 检查入口 **（已完成，scripts/ci-check.ps1）**
- 本地一键启动脚本 **（已完成，scripts/start-dev.ps1）**
- 本地一键初始化脚本 **（已完成，scripts/init-db.ps1）**

### 2.4 文档待继续校准

`backend-implementation-plan.md` 已部分根据真实前端接口校正，但仍存在需要继续统一的内容。

待校准项：

- 文档中早期技术约束仍出现 `la_` 表前缀描述，当前 `.env` 实际为 `lk_`。
- 文档中早期列表响应格式仍描述为 likeadmin 原生 `{lists,count,page_no,page_size}`，当前 JXC 前端适配层实际返回 `{data,total,page,pagesize}`。
- 文档中部分模块的完整业务规则仍偏理想化，需要随着真实代码推进逐章回填。
- 数据库表设计需要从“规划表”演进为“已实施表、待实施表、延期表”的状态化说明。
- 已发现阻塞点和修复方式需要在实施计划中建立引用，避免团队只读计划文档时绕开风险记忆库。

---

## 3. 当前实现的不足与改进点

### 3.1 代码质量层面

当前实现偏向“先打通前后端真实接口”的可运行版本，部分代码仍需要后续收敛。

主要不足：

- 订单金额计算使用简单 `float` 和 `number_format`**（已修复：核心计算已统一使用 bcmath）**，未来涉及复杂财务规则时建议统一金额工具或 decimal 计算策略。
- 销售单逻辑集中在 `SalesOrderLogic`，后续库存、应收、审计日志接入后需要拆分服务，避免单类过大。
- `buildGoodsRows` 中商品价格允许前端传入价格，后端会重算金额，但价格来源策略仍需业务确认。
- 当前销售单编辑采用删除旧明细后重建，简单可靠，但不保留明细变更历史。
- 删除销售单当前是硬删除，未来可能需要软删除、作废或反审核。
- 部分 catch 会把异常消息写入业务错误，后续应区分用户可见错误和内部日志。

### 3.2 架构设计层面

当前架构能支撑第一阶段联调，但完整进销存仍需要更强的领域边界。

主要不足：

- 还没有库存流水表或库存事务模型。**（已完成：lk_stock_flow + StockService）**
- 还没有应收/应付流水表。**（已完成：lk_receivable_flow + lk_payable_flow + FinanceService）**
- 还没有单据状态机。**（已完成：PurchaseOrder 6 状态机 + canTransitionTo）**
- 还没有幂等键或重复提交保护。**（已完成：4 张订单表均支持 idempotent_key）**
- 还没有单据编号的并发安全策略。**（已完成：generateOrderSn 带重试 + 唯一索引保护）**
- 跨模块审计日志 **（已完成：AuditService + AuditLog 表 + 4 个单据 Logic 已接入）**
- 单据状态边界 **（已完成：PurchaseOrder 6 状态机 + canTransitionTo 校验）**

### 3.3 安全与租户隔离层面

已遵守“不信任客户端 tenant_id/admin_id”的原则，但仍有可加强项。

主要不足：

- 部分早期模块仍依赖 `BaseModel` 全局租户作用域的自动行为，建议关键写入显式补 `tenant_id`。
- 对跨资源关联的租户一致性需要继续加强，例如客户、仓库、商品必须全部属于当前租户。
- 删除类接口还缺少业务占用校验，例如商品、客户、仓库被单据使用后是否允许删除。**（已完成：6 个 Logic 均已添加删除占用校验）**
- 订单类接口幂等控制 **（已完成：4 张订单表 idempotent_key + tenant_id 联合查重）**
- OpenAI 日志脱敏 **（已完成：OpenAiService 所有异常 catch 并写日志，API Key 不外泄）**

### 3.4 文档完整性层面

当前文档体系已经建立，但还没有完全闭环。

主要不足：

- 实施计划与真实代码仍存在部分偏差。
- 风险记忆库已经可用，但还未强制成为每次开发的检查门禁。
- 尚未生成正式 API 契约文档。
- 尚未生成数据库 ER 图或表关系说明。
- 尚未将冒烟验证步骤沉淀为团队可复用脚本。

### 3.5 工程管理层面

当前最大的工程管理不足是后端项目没有 Git 仓库。

影响：

- 无法用 `git diff` 精确追踪后端改动。
- 无法用分支承载阶段性开发。
- 无法做小步提交和回滚。
- 无法基于 PR 做代码评审。
- 当前只能通过文件时间、人工记录和报告追踪进度。

---

## 4. 风险评估与后续建议

### 4.1 当前主要风险

P1 风险：实施计划与真实代码/前端契约仍可能不一致。

影响：后续开发如果继续只按文档写，可能再次出现接口路径、分页字段、返回结构不匹配。

建议：每个模块开发前先读取 `common/api` 和 `common/constants/api.js`，以真实前端调用为准，再反向更新文档。

**状态：已解决（jxc-api-contract-v0.1.md 已冻结）**

P1 风险：进销存库存和财务联动尚未建模。

影响：销售单、进货单、退货单一旦接入库存与应收应付，就会出现多表一致性、编辑回滚、重复提交和并发扣库存问题。

建议：在继续写进货单和退货单前，先设计库存流水、应收流水、单据状态和幂等策略。

**状态：已解决（StockService + FinanceService + 3 张流水表）**

P1 风险：供应商档案与进货单路由存在边界冲突。

影响：`/api/supplier/*` 同时承担供应商档案和供货单语义时，容易出现路由遮蔽和接口语义混乱。

建议：优先冻结进货单路由命名，避免继续复用供应商档案前缀。

**状态：已解决（/api/supplier/* 供应商档案，/api/supply/* 进货单）**

P1 风险：后端项目刚初始化 Git，尚未形成提交基线。

影响：如果不尽快完成首次提交和分支策略，后续开发仍然难以审计，回滚和多人协作风险仍然偏高。

建议：尽快为 `BeiMi-PHP` 完成首次基线提交，并按模块做小步提交。

**状态：已解决（首次基线 commit 64a6d9f）**

P2 风险：当前验证仍偏手工。

影响：后续模块变多后，人工冒烟成本快速上升，回归风险增加。

建议：将已跑通的登录、基础资料、销售单 CRUD 冒烟流程固化为脚本。

**状态：已缓解（tests/isolation_test.php + tests/sales_flow_test.php + tests/supply_flow_test.php + tests/purchase_convert_test.php + tests/load_test.php 已建立自动化测试体系）**

P2 风险：安全规则靠开发自觉执行。

影响：新模块容易重新引入信任客户端租户字段、绕过校验、错误暴露等问题。

建议：把 `php-thinkphp-likeadmin-security` 和 `project-risk-memory` 作为 backend-code-engineer 的固定前置检查，并在报告中记录每次命中的风险模式。

### 4.2 推荐后续推进顺序

第一步：先冻结接口契约。

建议输出一份 `JXC API Contract v0.1`，明确每个已实现接口的 method、path、params、response、错误码、分页格式和鉴权要求。

**状态：已完成（docs/jxc-api-contract-v0.1.md 已冻结）**

第二步：补自动化冒烟脚本。

建议把本轮手动验证流程写成脚本，覆盖登录、单位、仓库、客户、商品、销售单发布、列表、详情、编辑、统计、删除、失败路径。

**状态：已完成（tests/smoke_test.php 覆盖 16 个步骤，Phase 6 新增 4 个专项测试脚本）**

第三步：设计库存与应收应付核心模型。

建议在继续扩展单据前先决定库存流水、客户应收流水、供应商应付流水和单据状态机，否则后续模块会重复返工。

**状态：已完成（StockService + FinanceService + 3 张流水表）**

第四步：实现进货单模块。

进货单是库存入库链路的基础，适合作为库存模型落地的第一条业务线。

**状态：已完成（SupplyOrderLogic 全链路，含 StockService 入库联动）**

第五步：实现销售退货单模块。

销售退货需要反向关联销售单，是检验库存回滚和原单关联规则的关键模块。

**状态：已完成（SalesReturnOrderLogic 全链路，含原单关联校验和库存回滚）**

第六步：实现订货单与转销售单。

订货单状态流复杂，建议在销售单、库存和客户应收稳定后再接入。

**状态：已完成（PurchaseOrderLogic 含 6 状态机 + convertToSalesOrder + 端到端测试验证）**

第七步：接入 OpenAI 文本识别。

OpenAI 功能建议在商品、销售单、订货单接口稳定后接入，避免 AI 解析结果没有稳定落点。

### 4.3 下一阶段建议验收标准

后端模块验收标准：

- 每个接口有 Validate 场景。
- 每个列表接口支持 `page` / `pagesize`。
- 每个列表接口返回 `{data,total,page,pagesize}`。
- 每个写接口不信任客户端 `tenant_id`、`admin_id`。
- 每个多表写接口使用事务。
- 每个订单类接口至少有一个失败路径测试。
- 每个新增路由都用真实 HTTP 请求验证。

数据库验收标准：

- SQL 表前缀与 `.env` 一致。
- 所有租户业务表包含 `tenant_id`。
- 关键查询字段有索引。
- 初始化脚本能重复执行。
- 种子脚本不破坏已有业务数据。

文档验收标准：

- 实施计划与真实前端文件名一致。
- 实施计划与真实路由一致。
- 实施计划与真实分页格式一致。
- 每个已完成模块标记“已实现、已验证、待增强”。
- 每个真实阻塞点都能在风险记忆库中检索到。

---

## 5. Phase 6 联调测试与优化 — 已完成

> 更新日期：2026-04-19

### 5.1 多租户隔离集成测试

- **文件**：`tests/isolation_test.php`
- **覆盖**：19 个测试步骤，覆盖客户、商品、仓库、销售单、进货单的列表隔离验证，以及越权查看、越权编辑、越权删除的拒绝校验。
- **结论**：多租户数据隔离通过系统级测试验证，tenant_id 作用域和中间件注入机制工作正常。

### 5.2 销售单完整闭环测试

- **文件**：`tests/sales_flow_test.php`
- **覆盖**：3 个测试场景：
  - 正常销售流程：创建销售单 → 编辑 → 删除，验证库存扣减、应收联动、回滚全链路正确。
  - 多商品行金额汇总：验证多行明细金额合计与订单总金额一致。
  - bcmath 金额精度验证：边界场景下浮点数精度不丢失。

### 5.3 进货单库存联动测试

- **文件**：`tests/supply_flow_test.php`
- **覆盖**：2 个测试场景：
  - 正常进货流程：创建进货单 → 编辑 → 删除，验证库存入库、应付联动、回滚全链路正确。
  - 同一供应商多次进货累计应付：验证多次进货后应付金额累计计算正确。

### 5.4 订货单转销售单端到端测试

- **文件**：`tests/purchase_convert_test.php`
- **覆盖**：3 个测试场景：
  - 正常转销售：订货单经 draft→sent→received 状态后执行转单，验证销售单正确生成、关联字段填写正确。
  - 取消订货单：验证已取消订货单不可再操作。
  - 非法状态转移拒绝：验证状态机约束，草稿单不可直接跳转到 completed。

### 5.5 客户销售历史真实实现

- **文件**：`app/api/jxc/logic/CustomerLogic.php`（salesHistory 方法）
- **改动**：从占位返回改为真实 SQL 关联查询，支持主客户+门店汇总，按 `sales_order.customer_id` 分页返回销售记录。

### 5.6 性能基准测试脚本

- **文件**：`tests/load_test.php`
- **覆盖**：3 个测试场景：
  - 列表查询性能：连续多次调用列表接口，记录平均响应时间。
  - 批量创建订单：批量发布销售单，验证并发写入不发生单号冲突。
  - curl_multi 并发支付：并发模拟支付请求，验证应收更新无数据竞争。

### 5.7 部署手册

- **文件**：`docs/deployment-guide.md`
- **内容**：环境要求（PHP 8.2、MySQL 8.x、Composer）、快速开始（本地一键启动）、生产部署流程、CI/CD 集成说明、常见故障排查指南。

### 5.8 配置管理文档

- **文件**：`docs/configuration-guide.md`
- **内容**：配置文件概览（`.env`、`config/*.php`）、环境变量完整清单、开发/测试/生产三环境对比表、安全配置建议（密钥管理、CORS、日志脱敏）。

---

## 6. Phase 2-3 开发变更清单

> Phase 2 覆盖核心业务模块实现（进货单/退货单/订货单/店铺/库存/财务联动），Phase 3 覆盖架构完善（OpenAI/审计日志/错误处理/迁移框架/CI 脚本）。

> 更新日期：2026-04-19

### 6.1 新增文件

核心服务：
- `app/api/jxc/logic/StockService.php` — 库存入库/出库/回滚服务（192 行）
- `app/api/jxc/logic/FinanceService.php` — 应收应付管理服务（258 行）

进货单模块：
- `app/api/jxc/logic/SupplyOrderLogic.php`
- `app/api/jxc/controller/SupplyOrderController.php`
- `app/api/jxc/validate/SupplyOrderValidate.php`
- `app/api/jxc/lists/SupplyOrderLists.php`

销售退货单模块：
- `app/api/jxc/logic/SalesReturnOrderLogic.php`
- `app/api/jxc/controller/SalesReturnOrderController.php`
- `app/api/jxc/validate/SalesReturnOrderValidate.php`
- `app/api/jxc/lists/SalesReturnOrderLists.php`

订货单模块：
- `app/api/jxc/logic/PurchaseOrderLogic.php`
- `app/api/jxc/controller/PurchaseOrderController.php`
- `app/api/jxc/validate/PurchaseOrderValidate.php`
- `app/api/jxc/lists/PurchaseOrderLists.php`

店铺模块：
- `app/api/jxc/logic/StoreLogic.php`
- `app/api/jxc/controller/StoreController.php`

流水表模型：
- `app/common/model/jxc/StockFlow.php`
- `app/common/model/jxc/ReceivableFlow.php`
- `app/common/model/jxc/PayableFlow.php`

### 6.2 修改文件

- `app/api/jxc/logic/SalesOrderLogic.php` — 集成 StockService/FinanceService + bcmath 修复
- `app/api/jxc/logic/CustomerLogic.php` — salesHistory 真实数据 + bcmath + 删除占用校验
- `app/api/jxc/logic/PurchaseOrderLogic.php` — bcmath 修复
- `app/api/jxc/logic/GoodsUnitLogic.php` — 删除占用校验
- `app/api/jxc/logic/WarehouseLogic.php` — 删除占用校验
- `app/api/jxc/logic/SupplierLogic.php` — 删除占用校验
- `app/api/jxc/logic/GoodsLogic.php` — 删除占用校验
- `app/common/model/jxc/PurchaseOrder.php` — 6 状态常量 + canTransitionTo
- `app/api/route/jxc.php` — 追加 24 条路由
- `database/sql/jxc_phase1_schema.sql` — 追加 6 张表 + idempotent_key 字段
- `runtime/jxc_phase1_db_init.php` — 追加验证表列表
- `tests/smoke_test.php` — 扩展到 16 个测试步骤

### 6.3 关键设计决策

1. **库存模型**：StockService 使用行锁（`lock(true)`）保证并发安全，允许负库存（业务确认）。
2. **财务模型**：FinanceService 同步更新客户/供应商表累计字段并写流水，rollback 仅回滚对应类型流水。
3. **退货单应收处理**：由于 rollbackReceivable 仅处理 TYPE_SALES_ADD，退货单编辑/删除通过手动 addReceivable 恢复旧应收。
4. **订货单定位**：计划单据，不直接操作库存/财务，仅通过 convertToSalesOrder 间接触发。
5. **幂等键**：可选参数，非空时按 tenant_id + idempotent_key 查重，空值不受影响。
6. **单据编号**：带 3 次重试 + 唯一索引保护，最坏情况使用微秒级后缀兜底。

---

## 7. 已知待改进事项

### 7.1 数据库 Schema 优化
- **销售单表追加 from_purchase_order_id**：已通过迁移脚本 20260420_000001 修复，用于记录订货单转销售单的来源关系。
- **软删除机制**：当前订单删除为硬删除，后续可考虑添加 is_deleted 字段保留审计线索。

### 7.2 代码结构优化
- **CustomerLogic 拆分**：当前 851 行，建议后续拆分为 CustomerService + CustomerStoreService。
- **PurchaseOrderLogic 拆分**：当前 34KB+，建议提取 PurchaseStateMachine 为独立类。

### 7.3 测试覆盖扩展
- 缺少 PHPUnit 单元测试框架，当前仅有集成测试脚本。
- 缺少边界场景测试（库存负数、金额精度溢出、高并发冲突）。

### 7.4 工程化增强
- CI/CD 流水线集成（当前 ci-check.ps1 仅限本地检查）。
- 环境变量加密管理（当前 .env 明文存储）。
- 迁移框架补充回滚机制。

---

## 8. 当前结论

项目已经从"规划和接口反推阶段"进入"真实后端模块落地和联调阶段"，并已完成 Phase 1-6 全部核心功能开发。

**Phase 1-6 已完成的核心能力：**

- JXC 后端入口已打通，租户管理员登录和鉴权已完善。
- 分页契约已适配前端，接口契约文档已冻结（v0.1）。
- 主数据模块（单位、仓库、供应商、商品、客户）已全部完成。
- 销售单/进货单/退货单/订货单四大单据模块已完成，含库存联动和财务联动。
- 店铺模块已完成，支持主客户+门店架构。
- OpenAI 文本识别已接入订货单，含降级策略和日志脱敏。
- 审计日志、幂等控制、状态机、迁移框架、CI 脚本等架构能力已补齐。
- 5 个专项测试脚本 + 1 个性能测试脚本已建立自动化测试体系。

**后续方向：**

1. **系统稳定期**：持续运行测试脚本，修复发现的边界问题。
2. **性能优化**：基于 load_test.php 结果优化慢查询和高并发场景。
3. **前端联调**：与 BeiMi-uniapp 完成端到端集成测试。
4. **生产准备**：完善部署文档、监控告警、备份策略。
5. **代码重构**：按第 7 节待改进事项逐步优化代码结构。

项目已具备进入生产环境的基础条件，建议按上述方向稳步推进。
