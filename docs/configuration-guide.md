# BeiMi JXC 进销存 SaaS 系统 — 配置管理文档

## 1. 配置文件概览

所有配置文件位于 `BeiMi-PHP/config/` 目录，运行时配置通过 `.env` 环境变量覆盖。

| 配置文件                | 用途                                             | 优先级 |
| ----------------------- | ------------------------------------------------ | ------ |
| `.env`                  | 环境变量（不入版本控制，优先级最高）              | 最高   |
| `.example.env`          | 环境变量模板（入版本控制，供参考）                | —      |
| `config/app.php`        | 应用基础设置（时区、路由、异常处理）              | 中     |
| `config/database.php`   | 数据库连接配置                                    | 中     |
| `config/cache.php`      | 缓存驱动配置（File / Redis）                      | 中     |
| `config/log.php`        | 日志通道配置                                      | 中     |
| `config/session.php`    | Session 配置                                      | 中     |
| `config/openai.php`     | OpenAI API 配置（智能解析）                       | 中     |
| `config/project.php`    | 项目业务配置（Token、登录、上传、网站信息等）      | 中     |
| `config/middleware.php` | 中间件别名与优先级                                | 低     |
| `config/route.php`      | 路由全局配置                                      | 低     |
| `config/cookie.php`     | Cookie 配置                                       | 低     |
| `config/filesystem.php` | 文件系统配置                                      | 低     |
| `config/console.php`    | 控制台命令配置                                    | 低     |
| `config/lang.php`       | 多语言配置                                        | 低     |

> **配置加载顺序：** `.env` 环境变量 → `config/*.php` 默认值 → 框架内置默认值

---

## 2. 环境变量清单

以下为 `.example.env` 中定义的所有环境变量：

### 2.1 应用基础

| 变量名              | 分组  | 含义                           | 默认值         | 必填 |
| -------------------- | ----- | ------------------------------ | -------------- | ---- |
| `APP_DEBUG`          | 全局  | 调试模式（true/false）          | `true`         | 是   |

### 2.2 APP 分组

| 变量名                          | 含义             | 默认值          | 必填 |
| ------------------------------- | ---------------- | --------------- | ---- |
| `APP.DEFAULT_TIMEZONE`          | 默认时区         | `Asia/Shanghai` | 否   |

### 2.3 DATABASE 分组

| 变量名                 | 含义               | 默认值       | 必填 |
| ---------------------- | ------------------ | ------------ | ---- |
| `DATABASE.TYPE`        | 数据库类型         | `mysql`      | 是   |
| `DATABASE.HOSTNAME`    | 数据库主机地址     | `127.0.0.1`  | 是   |
| `DATABASE.DATABASE`    | 数据库名           | —            | 是   |
| `DATABASE.USERNAME`    | 数据库用户名       | —            | 是   |
| `DATABASE.PASSWORD`    | 数据库密码         | —            | 是   |
| `DATABASE.HOSTPORT`    | 数据库端口         | `3306`       | 否   |
| `DATABASE.CHARSET`     | 数据库字符集       | `utf8mb4`    | 否   |
| `DATABASE.DEBUG`       | 数据库调试模式     | `true`       | 否   |
| `DATABASE.PREFIX`      | 表前缀             | `lk_`        | 是   |

### 2.4 LANG 分组

| 变量名              | 含义       | 默认值   | 必填 |
| ------------------- | ---------- | -------- | ---- |
| `LANG.default_lang` | 默认语言   | `zh-cn`  | 否   |

### 2.5 PROJECT 分组

| 变量名                                | 含义                                     | 默认值       | 必填 |
| ------------------------------------- | ---------------------------------------- | ------------ | ---- |
| `PROJECT.UNIQUE_IDENTIFICATION`       | 唯一标识（密码盐、路径加密）              | `likeadmin`  | 是   |
| `PROJECT.DEFAULT_PASSWORD`            | 新用户默认密码                            | `123456`     | 否   |
| `PROJECT.DEMO_ENV`                    | 演示环境模式                              | `false`      | 否   |
| `PROJECT.HTTP_HOST`                   | 平台端访问域名                            | 空           | 否   |

### 2.6 OpenAI 配置（顶层变量）

| 变量名               | 含义                | 默认值                        | 必填 |
| -------------------- | ------------------- | ----------------------------- | ---- |
| `OPENAI_API_KEY`     | OpenAI API 密钥     | 空                            | 否   |
| `OPENAI_MODEL`       | 使用的模型          | `gpt-4o-mini`                 | 否   |
| `OPENAI_BASE_URL`    | API 基础 URL        | `https://api.openai.com/v1`   | 否   |

> **额外环境变量**（在 `config/openai.php` 中通过 `env()` 读取，可按需添加到 `.env`）：

| 变量名                 | 含义           | 默认值 | 必填 |
| ---------------------- | -------------- | ------ | ---- |
| `OPENAI_MAX_TOKENS`    | 最大 Token 数  | `2000` | 否   |
| `OPENAI_TEMPERATURE`   | 温度参数       | `0.1`  | 否   |
| `OPENAI_TIMEOUT`       | 请求超时（秒） | `30`   | 否   |

### 2.7 缓存相关（在 `config/cache.php` 中读取）

| 变量名              | 含义         | 默认值          | 必填 |
| ------------------- | ------------ | --------------- | ---- |
| `CACHE.DRIVER`      | 缓存驱动     | `file`          | 否   |
| `CACHE.HOST`        | Redis 主机   | `like-redis`    | 否   |
| `CACHE.PORT`        | Redis 端口   | `6379`          | 否   |
| `CACHE.PASSWORD`    | Redis 密码   | 空              | 否   |

---

## 3. 各环境配置对比表

| 配置项                          | 开发环境              | 测试环境               | 生产环境               |
| ------------------------------- | --------------------- | ---------------------- | ---------------------- |
| `APP_DEBUG`                     | `true`                | `false`                | `false`                |
| `DATABASE.TYPE`                 | `mysql`               | `mysql`                | `mysql`                |
| `DATABASE.HOSTNAME`             | `127.0.0.1`           | `<TEST_DB_HOST>`       | `<PROD_DB_HOST>`       |
| `DATABASE.DATABASE`             | `jxcsass`             | `<TEST_DB_NAME>`       | `<PROD_DB_NAME>`       |
| `DATABASE.USERNAME`             | `root`                | `<TEST_DB_USER>`       | `<PROD_DB_USER>`       |
| `DATABASE.PASSWORD`             | `<YOUR_PASSWORD>`     | `<TEST_DB_PASSWORD>`   | `<PROD_DB_PASSWORD>`   |
| `DATABASE.HOSTPORT`             | `3306`                | `3306`                 | `3306`                 |
| `DATABASE.DEBUG`                | `true`                | `false`                | `false`                |
| `DATABASE.PREFIX`               | `lk_`                 | `lk_`                  | `lk_`                  |
| `APP.DEFAULT_TIMEZONE`          | `Asia/Shanghai`       | `Asia/Shanghai`        | `Asia/Shanghai`        |
| `LANG.default_lang`             | `zh-cn`               | `zh-cn`                | `zh-cn`                |
| `PROJECT.UNIQUE_IDENTIFICATION` | `<DEV_UNIQUE_ID>`     | `<TEST_UNIQUE_ID>`     | `<PROD_UNIQUE_ID>`     |
| `PROJECT.DEFAULT_PASSWORD`      | `123456`              | `<SECURE_PASSWORD>`    | `<SECURE_PASSWORD>`    |
| `PROJECT.DEMO_ENV`              | `false`               | `false`                | `false`                |
| `PROJECT.HTTP_HOST`             | 空                    | `<TEST_DOMAIN>`        | `<PROD_DOMAIN>`        |
| `CACHE.DRIVER`                  | `file`                | `redis`                | `redis`                |
| `CACHE.HOST`                    | —                     | `<TEST_REDIS_HOST>`    | `<PROD_REDIS_HOST>`    |
| `LOG.CHANNEL`                   | `file`                | `file`                 | `file`                 |
| 日志级别                        | debug                 | info                   | warning                |
| `OPENAI_API_KEY`                | 空（使用正则降级）    | `<TEST_KEY>`           | `<PROD_KEY>`           |
| `OPENAI_MODEL`                  | `gpt-4o-mini`         | `gpt-4o-mini`          | `gpt-4o-mini`          |
| `OPENAI_TIMEOUT`                | `30`                  | `30`                   | `60`                   |

---

## 4. 数据库配置详解

配置文件：`config/database.php`

### 4.1 连接参数

| 参数              | 环境变量                      | 默认值                 | 说明                       |
| ----------------- | ----------------------------- | ---------------------- | -------------------------- |
| `type`            | `DATABASE.TYPE`               | `mysql`                | 数据库类型                  |
| `hostname`        | `DATABASE.HOSTNAME`           | `likeshop-mysql`       | 服务器地址                  |
| `database`        | `DATABASE.DATABASE`           | `localhost_likeadmin`  | 数据库名                    |
| `username`        | `DATABASE.USERNAME`           | `root`                 | 用户名                      |
| `password`        | `DATABASE.PASSWORD`           | `root`                 | 密码                        |
| `hostport`        | `DATABASE.HOSTPORT`           | `3306`                 | 端口                        |
| `charset`         | `DATABASE.CHARSET`            | `utf8mb4`              | 字符集                      |
| `prefix`          | `DATABASE.PREFIX`             | `la_`                  | 表前缀（本项目使用 `lk_`）  |

### 4.2 高级参数

| 参数               | 默认值 | 说明                                                     |
| ------------------ | ------ | -------------------------------------------------------- |
| `deploy`           | `0`    | 部署方式：0=集中式（单一服务器），1=分布式（主从服务器）   |
| `rw_separate`      | `false`| 读写分离（主从模式下有效）                                |
| `master_num`       | `1`    | 主服务器数量                                              |
| `slave_no`         | `''`   | 指定从服务器序号                                          |
| `fields_strict`    | `true` | 严格检查字段是否存在                                      |
| `break_reconnect`  | `false`| 断线重连                                                  |
| `trigger_sql`      | 调试值 | 监听 SQL（跟随 `APP_DEBUG`，调试模式自动开启）            |
| `fields_cache`     | `false`| 字段缓存                                                  |
| `params`           | `[]`   | PDO 连接参数                                              |

### 4.3 数据库表前缀

本项目使用 `lk_` 作为表前缀（通过 `.env` 中 `DATABASE.PREFIX` 配置），所有业务表均带此前缀：

| 表名                       | 说明           |
| -------------------------- | -------------- |
| `lk_goods_unit`            | 商品单位表     |
| `lk_warehouse`             | 仓库表         |
| `lk_vendor`                | 供应商表       |
| `lk_goods`                 | 商品表         |
| `lk_customer`              | 客户表         |
| `lk_customer_group`        | 客户分组表     |
| `lk_sales_order`           | 销售单表       |
| `lk_order_goods`           | 单据商品明细表 |
| `lk_stock_flow`            | 库存流水表     |
| `lk_receivable_flow`       | 客户应收流水表 |
| `lk_payable_flow`          | 供应商应付流水表|
| `lk_sales_return_order`    | 销售退货单表   |
| `lk_supply_order`          | 进货单表       |
| `lk_purchase_order`        | 订货单表       |
| `lk_migration_history`     | 迁移历史表     |

### 4.4 读写分离配置（可选）

如需配置主从读写分离，修改 `config/database.php`：

```php
'mysql' => [
    'deploy'      => 1,           // 分布式部署
    'rw_separate' => true,        // 开启读写分离
    'master_num'  => 1,           // 主服务器数量
    // 主从服务器在 hostname 中用逗号分隔
    'hostname'    => 'master-host,slave-host1,slave-host2',
],
```

---

## 5. OpenAI 配置详解

配置文件：`config/openai.php`

### 5.1 参数说明

| 参数          | 环境变量              | 默认值                       | 说明                                     |
| ------------- | --------------------- | ---------------------------- | ---------------------------------------- |
| `api_key`     | `OPENAI_API_KEY`      | 空                           | API 密钥，不配置时使用正则解析降级        |
| `model`       | `OPENAI_MODEL`        | `gpt-4o-mini`                | 使用的模型，推荐 `gpt-4o-mini`（性价比高）|
| `max_tokens`  | `OPENAI_MAX_TOKENS`   | `2000`                       | 单次请求最大 Token 数                     |
| `temperature` | `OPENAI_TEMPERATURE`  | `0.1`                        | 生成温度（0-2），越低越确定               |
| `timeout`     | `OPENAI_TIMEOUT`      | `30`                         | 请求超时时间（秒）                        |
| `base_url`    | `OPENAI_BASE_URL`     | `https://api.openai.com/v1`  | API 基础 URL（可替换为代理地址）          |

### 5.2 降级策略

系统对 OpenAI 调用采用了**自动降级**设计：

```
┌─────────────────────────────┐
│   用户请求智能解析           │
└──────────────┬──────────────┘
               │
               ▼
       ┌───────────────┐    是    ┌──────────────────┐
       │ API Key 已配置？├────────►│ 调用 OpenAI API  │
       └───────┬───────┘         └────────┬─────────┘
               │ 否                        │
               ▼                           ▼
       ┌───────────────┐          ┌──────────────────┐
       │ 正则解析降级   │          │  API 调用成功？   │
       │ (本地规则匹配) │          └────┬────────┬────┘
       └───────────────┘               │        │
                                      是        否
                                       │        │
                                       ▼        ▼
                               ┌──────────┐ ┌──────────────┐
                               │ 返回结果  │ │ 降级到正则解析 │
                               └──────────┘ └──────────────┘
```

- **API Key 未配置**：自动使用本地正则解析，不影响基本功能
- **API 调用超时/失败**：捕获异常后降级到正则解析
- **网络受限环境**：可设置 `OPENAI_BASE_URL` 为代理地址，或将 Key 留空

### 5.3 代理/第三方 API 配置

如使用 Azure OpenAI 或第三方代理：

```ini
OPENAI_API_KEY=<YOUR_API_KEY>
OPENAI_BASE_URL=https://your-proxy.example.com/v1
OPENAI_MODEL=gpt-4o-mini
```

---

## 6. 安全配置建议

### 6.1 密码与加密

| 配置项                          | 建议值                                | 说明                                   |
| ------------------------------- | ------------------------------------- | -------------------------------------- |
| `PROJECT.UNIQUE_IDENTIFICATION` | 32 位以上随机字符串                    | 密码加密盐值，务必使用强随机值          |
| `PROJECT.DEFAULT_PASSWORD`      | 移除默认值，强制用户首次登录修改       | 避免弱密码风险                          |

**生成强随机标识：**

```bash
php -r "echo bin2hex(random_bytes(32));"
# 输出示例：a1b2c3d4e5f6...（64字符）
```

### 6.2 Token 安全

Token 配置位于 `config/project.php`：

| 配置项                            | 默认值     | 说明                           |
| --------------------------------- | ---------- | ------------------------------ |
| `admin_token.expire_duration`     | `28800`(8h)| 管理后台 Token 过期时长        |
| `admin_token.be_expire_duration`  | `3600`(1h) | Token 临期自动续期窗口         |
| `tenant_token.expire_duration`    | `28800`(8h)| 租户端 Token 过期时长          |
| `tenant_token.be_expire_duration` | `3600`(1h) | 租户端 Token 临期自动续期窗口  |
| `user_token.expire_duration`      | `28800`(8h)| 用户端 Token 过期时长          |
| `user_token.be_expire_duration`   | `3600`(1h) | 用户端 Token 临期自动续期窗口  |

**安全建议：**
- 生产环境建议缩短过期时长（如 2-4 小时）
- Token 存储如使用 Redis，确保 Redis 设置了密码且不对外暴露
- 启用 HTTPS，防止 Token 在传输中被截获

### 6.3 CORS 跨域配置

如需配置跨域访问，可在应用中间件中添加 CORS 头：

```php
// 在对应应用的 middleware.php 中配置
header('Access-Control-Allow-Origin: https://your-domain.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');
header('Access-Control-Max-Age: 86400');
```

> **建议：** 生产环境仅允许指定域名跨域，禁止使用 `*`。

### 6.4 API 限流

推荐在生产环境配置 API 限流，防止接口被恶意刷调用：

**Nginx 限流配置：**

```nginx
# 在 http 块中定义限流区域
limit_req_zone $binary_remote_addr zone=api_limit:10m rate=30r/s;

# 在 server 块中应用
location /api/ {
    limit_req zone=api_limit burst=50 nodelay;
    # ... 其他配置
}
```

**PHP 层限流（可选）：**

可在中间件中实现基于 Redis 的滑动窗口限流，精确控制每个用户/租户的 API 调用频率。

### 6.5 登录安全

配置位于 `config/project.php` → `admin_login`：

| 配置项                             | 默认值 | 说明                                 |
| ---------------------------------- | ------ | ------------------------------------ |
| `login_restrictions`               | `1`    | 是否启用登录限制（0=不限制，1=限制） |
| `password_error_times`             | `5`    | 密码错误次数上限                      |
| `limit_login_time`                 | `30`   | 锁定时长（分钟）                      |

### 6.6 文件上传安全

配置位于 `config/project.php`：

| 配置项          | 允许类型                                                            |
| --------------- | ------------------------------------------------------------------- |
| `file_image`    | `jpg`, `png`, `gif`, `jpeg`, `webp`, `ico`                         |
| `file_video`    | `wmv`, `avi`, `mpg`, `mpeg`, `3gp`, `mov`, `mp4`, `flv`, 等       |
| `file_file`     | `zip`, `rar`, `txt`, `pdf`, `doc`, `docx`, `xls`, `xlsx`, 等       |

**安全建议：**
- 禁止上传 `.php`、`.php5`、`.phtml` 等可执行文件
- 上传目录禁止执行 PHP（Nginx 配置 `location ~* /uploads/.*\.php$ { deny all; }`）
- 限制上传文件大小（Nginx: `client_max_body_size`，PHP: `upload_max_filesize`）

### 6.7 缓存安全

如使用 Redis 作为缓存驱动：

```ini
; .env 追加配置
CACHE.DRIVER = redis
CACHE.HOST = 127.0.0.1
CACHE.PORT = 6379
CACHE.PASSWORD = <YOUR_REDIS_PASSWORD>
```

**安全建议：**
- Redis 必须设置密码
- Redis 绑定 `127.0.0.1` 或内网 IP，禁止暴露到公网
- 禁用 Redis 的 `FLUSHALL`、`CONFIG` 等危险命令

### 6.8 环境变量安全检查清单

部署前请逐项确认：

- [ ] `APP_DEBUG` 已设为 `false`
- [ ] `DATABASE.DEBUG` 已设为 `false`
- [ ] `PROJECT.UNIQUE_IDENTIFICATION` 已更换为强随机值
- [ ] 数据库密码已更换为强密码
- [ ] `.env` 文件已加入 `.gitignore`，未纳入版本控制
- [ ] Redis（如使用）已设置密码
- [ ] 上传目录禁止执行 PHP
- [ ] HTTPS 已启用
- [ ] 默认密码已更改或强制用户修改
- [ ] `OPENAI_API_KEY` 仅在需要时配置，生产环境不使用测试 Key
