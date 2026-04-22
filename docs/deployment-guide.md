> **旧副本提示**：本文件位于 `BeiMi-PHP/docs/`，可能落后于权威根目录文档。请优先查看 `E:\object\BeiMi\docs\deployment-guide.md`，后续仅按需同步关键提示。

# BeiMi JXC 进销存 SaaS 系统 — 部署手册

## 1. 概述

BeiMi JXC 是一套基于 **ThinkPHP 8 + likeadmin** 架构的进销存 SaaS 管理系统，支持多租户，涵盖商品管理、采购、销售、库存、财务等核心业务。

### 1.1 系统架构

```
┌─────────────────────────────────────────────────────────────┐
│                        客户端                                │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │  UniApp 移动端 │  │  PC 管理后台  │  │  平台管理端   │       │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘       │
│         │                 │                 │               │
└─────────┼─────────────────┼─────────────────┼───────────────┘
          │                 │                 │
          ▼                 ▼                 ▼
┌─────────────────────────────────────────────────────────────┐
│                     Nginx / Apache                           │
│              (反向代理 → public/ 目录)                         │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                   PHP-FPM / PHP 内置服务器                     │
│              (ThinkPHP 8 多应用模式)                           │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐    │
│  │   api    │  │tenantapi │  │platformapi│  │  index   │    │
│  │ (移动端API)│  │ (租户端API)│  │ (平台端API)│  │ (前台)   │    │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └────┬─────┘    │
│       └──────────────┴──────────────┴──────────────┘         │
│                            │                                  │
│                   ┌────────┴────────┐                        │
│                   │  common (公共层)  │                        │
│                   │  model / logic   │                        │
│                   └────────┬────────┘                        │
└────────────────────────────┼────────────────────────────────┘
                             │
              ┌──────────────┼──────────────┐
              ▼              ▼              ▼
        ┌──────────┐  ┌──────────┐  ┌──────────┐
        │  MySQL   │  │  Redis   │  │  文件存储  │
        │  8.4     │  │ (可选)   │  │ 本地/OSS  │
        └──────────┘  └──────────┘  └──────────┘
```

### 1.2 技术栈

| 组件       | 版本/说明                                      |
| ---------- | ---------------------------------------------- |
| 后端框架   | ThinkPHP 8.0                                   |
| 基础架构   | likeadmin-SaaS 版                              |
| PHP        | >= 8.2                                         |
| 数据库     | MySQL >= 8.0（推荐 8.4）                        |
| 缓存       | 文件缓存 / Redis（可选）                        |
| 前端       | UniApp（移动端）、Vue（管理后台）               |
| 依赖管理   | Composer（PHP）、npm（前端）                    |

### 1.3 项目目录结构

```
e:/object/BeiMi/
├── BeiMi-PHP/                  # 后端项目（ThinkPHP 8）
│   ├── app/                    # 应用目录（多应用模式）
│   │   ├── api/                # 移动端 API
│   │   ├── tenantapi/          # 租户端 API
│   │   ├── platformapi/        # 平台端 API
│   │   ├── index/              # 前台
│   │   └── common/             # 公共模块（model / logic）
│   ├── config/                 # 配置文件
│   ├── database/               # 数据库相关
│   │   ├── migrations/         # 迁移文件
│   │   ├── seed/               # 种子数据
│   │   └── sql/                # Schema SQL
│   ├── public/                 # Web 根目录（对外访问）
│   ├── scripts/                # 运维脚本
│   ├── tests/                  # 测试
│   ├── vendor/                 # Composer 依赖
│   ├── .env                    # 环境变量（不入版本控制）
│   └── .example.env            # 环境变量模板
├── BeiMi-uniapp/               # 前端 UniApp 项目
├── .local/mysql/               # 本地 MySQL（开发用）
└── docs/                       # 文档
```

---

## 2. 环境要求

### 2.1 必需软件

| 软件      | 最低版本 | 推荐版本 | 说明                                     |
| --------- | -------- | -------- | ---------------------------------------- |
| PHP       | 8.0      | 8.2+     | 需包含以下扩展                            |
| MySQL     | 8.0      | 8.4      | 需支持 utf8mb4 字符集                     |
| Composer  | 2.x      | 最新     | PHP 依赖管理                              |

### 2.2 PHP 必需扩展

```
pdo_mysql    # MySQL 数据库驱动
curl         # HTTP 请求
bcmath       # 高精度数学运算（金额计算）
mbstring     # 多字节字符串处理
openssl      # 加密/HTTPS
fileinfo     # 文件类型检测
zip          # 压缩/解压（导出功能）
```

> **验证扩展是否安装：**
> ```bash
> php -m | grep -E "pdo_mysql|curl|bcmath|mbstring|openssl|fileinfo|zip"
> ```

### 2.3 可选软件

| 软件    | 用途                               |
| ------- | ---------------------------------- |
| Redis   | 缓存驱动、Session 存储（生产推荐） |
| Node.js | 前端 UniApp 构建                   |
| Nginx   | 生产环境 Web 服务器（推荐）        |
| Apache  | 生产环境 Web 服务器（备选）        |

---

## 3. 快速开始（开发环境）

### 3.1 克隆代码

```bash
git clone <仓库地址> e:/object/BeiMi
cd e:/object/BeiMi
```

### 3.2 安装 PHP 依赖

```bash
cd e:/object/BeiMi/BeiMi-PHP
composer install
```

> 如果网络较慢，可使用国内镜像：
> ```bash
> composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/
> ```

### 3.3 配置环境变量

```bash
# 复制模板
copy .example.env .env
```

编辑 `.env` 文件，配置数据库连接：

```ini
APP_DEBUG = true

[DATABASE]
TYPE = mysql
HOSTNAME = 127.0.0.1
DATABASE = jxcsass
USERNAME = root
PASSWORD = <YOUR_PASSWORD>
HOSTPORT = 3306
CHARSET = utf8mb4
DEBUG = true
PREFIX = lk_

[PROJECT]
UNIQUE_IDENTIFICATION = <YOUR_UNIQUE_ID>
DEFAULT_PASSWORD = 123456
DEMO_ENV = false
```

> **注意：** `UNIQUE_IDENTIFICATION` 用于密码盐和路径加密，每个环境应使用不同的值。

### 3.4 初始化数据库

**方式一：使用初始化脚本（推荐）**

```powershell
# 确保 MySQL 已运行，然后执行
scripts/init-db.ps1
```

该脚本将自动：
1. 检查 MySQL 连接
2. 创建数据库（如不存在）
3. 导入 `database/sql/jxc_phase1_schema.sql`
4. 执行 `database/seed/jxc_phase1_dev_seed.php` 种子数据

**方式二：手动导入**

```bash
# 1. 创建数据库
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS jxcsass CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. 导入 Schema
mysql -u root -p jxcsass < database/sql/jxc_phase1_schema.sql
```

### 3.5 执行数据库迁移

```bash
# 执行所有待迁移
php scripts/migrate.php

# 查看迁移状态
php scripts/migrate.php --status

# 仅查看待执行（不实际执行）
php scripts/migrate.php --dry-run
```

### 3.6 启动开发服务器

**方式一：一键启动（推荐）**

```powershell
scripts/start-dev.ps1
```

该脚本将自动：
1. 启动 MySQL（后台进程）
2. 等待 MySQL 就绪（最多 30 秒）
3. 执行数据库迁移
4. 启动 PHP 内置服务器（`127.0.0.1:8000`）

**方式二：手动启动**

```bash
# 先确保 MySQL 运行
# 然后启动 PHP 开发服务器
php think run --port 8787

# 或指定绑定地址
php -S 127.0.0.1:8000 -t public public/router.php
```

**停止开发环境：**

```powershell
scripts/stop-dev.ps1
```

### 3.7 首次验证

```bash
# 运行冒烟测试
php tests/smoke_test.php
```

验证服务可访问：

| 地址                                | 说明           |
| ----------------------------------- | -------------- |
| `http://127.0.0.1:8000/`            | 首页           |
| `http://127.0.0.1:8000/api/`        | 移动端 API     |
| `http://127.0.0.1:8000/tenantapi/`  | 租户端 API     |
| `http://127.0.0.1:8000/platformapi/`| 平台端 API     |

---

## 4. 生产环境部署

### 4.1 Nginx 配置示例

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/beimi/BeiMi-PHP/public;
    index index.php;

    # 主路由规则
    location / {
        if (!-e $request_filename) {
            rewrite ^(.*)$ /index.php?s=$1 last;
        }
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # 超时设置（OpenAI 接口可能需要较长响应时间）
        fastcgi_read_timeout 60s;
    }

    # 禁止访问隐藏文件
    location ~ /\. {
        deny all;
    }

    # 禁止访问非 public 目录
    location ~* ^/(app|config|database|extend|route|runtime|scripts|tests|vendor|\.env)/ {
        deny all;
    }

    # 静态资源缓存
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, no-transform";
    }

    # 上传文件大小限制
    client_max_body_size 20m;
}
```

> **HTTPS 配置：** 建议使用 Let's Encrypt 免费证书，配合 `certbot` 自动续期。

### 4.2 Apache 配置示例

确保启用 `mod_rewrite`，项目 `public/.htaccess` 已包含重写规则：

```apache
<IfModule mod_rewrite.c>
    Options +FollowSymlinks -Multiviews
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(.*)$ index.php?s=$1 [QSA,PT,L]
</IfModule>
```

### 4.3 PHP-FPM 配置建议

```ini
; /etc/php/8.2/fpm/pool.d/beimi.conf
[beimi]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm.sock

; 进程管理
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 1000

; 资源限制
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 25M
php_admin_value[max_execution_time] = 60

; OPcache（推荐开启）
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 128
php_admin_value[opcache.max_accelerated_files] = 10000
```

### 4.4 环境变量设置

生产环境 `.env` 关键配置：

```ini
APP_DEBUG = false

[DATABASE]
TYPE = mysql
HOSTNAME = <PROD_DB_HOST>
DATABASE = <PROD_DB_NAME>
USERNAME = <PROD_DB_USER>
PASSWORD = <PROD_DB_PASSWORD>
HOSTPORT = 3306
CHARSET = utf8mb4
DEBUG = false
PREFIX = lk_

[LANG]
default_lang = zh-cn

[PROJECT]
UNIQUE_IDENTIFICATION = <PROD_UNIQUE_ID>
DEFAULT_PASSWORD = <SECURE_DEFAULT_PASSWORD>
DEMO_ENV = false
HTTP_HOST = <YOUR_DOMAIN>

# OpenAI 配置（可选）
OPENAI_API_KEY = <YOUR_API_KEY>
OPENAI_MODEL = gpt-4o-mini
OPENAI_BASE_URL = https://api.openai.com/v1
```

> **安全提示：**
> - `APP_DEBUG` 必须设为 `false`
> - `DATABASE.DEBUG` 必须设为 `false`
> - `UNIQUE_IDENTIFICATION` 务必使用强随机值
> - `.env` 文件不得纳入版本控制

### 4.5 数据库迁移执行

```bash
# 查看待执行迁移
php scripts/migrate.php --dry-run

# 执行迁移
php scripts/migrate.php
```

> 生产环境执行迁移前，务必先备份数据库。

### 4.6 日志目录权限

```bash
# 确保 runtime 目录可写
chmod -R 755 runtime/
chown -R www-data:www-data runtime/

# 确保 public/uploads 可写
chmod -R 755 public/uploads/
chown -R www-data:www-data public/uploads/
```

---

## 5. CI/CD 集成

### 5.1 代码质量检查

项目提供了 `scripts/ci-check.ps1` 脚本，支持三种模式：

```powershell
# 默认模式：语法检查 + 路由检查 + Model 检查
scripts/ci-check.ps1

# Quick 模式：仅 PHP 语法检查
scripts/ci-check.ps1 -Quick

# Full 模式：全部检查 + 冒烟测试（需要 MySQL 运行）
scripts/ci-check.ps1 -Full
```

**检查项说明：**

| 检查项           | 说明                                              |
| ---------------- | ------------------------------------------------- |
| PHP 语法检查      | 扫描 `app/` 下所有 `.php` 文件，验证语法正确性     |
| 路由完整性检查    | 验证 `jxc.php` 路由引用的 Controller 文件存在       |
| Model-Schema 检查 | 验证 Model 对应的数据表在 Schema 中存在             |
| 冒烟测试         | 运行 `tests/smoke_test.php`（需 MySQL 连接）       |

### 5.2 冒烟测试

```bash
php tests/smoke_test.php
```

### 5.3 CI 流水线建议

```yaml
# 示例 CI 流程
stages:
  - lint
  - test
  - deploy

lint:
  script:
    - php scripts/ci-check.ps1 -Quick

test:
  script:
    - php scripts/ci-check.ps1 -Full

deploy:
  script:
    - php scripts/migrate.php --dry-run
    - php scripts/migrate.php
```

---

## 6. 常见问题排查

### 6.1 数据库连接失败

**现象：** 页面报 `SQLSTATE[HY000] [2002] Connection refused` 或类似错误。

**排查步骤：**

1. **确认 MySQL 运行状态**
   ```bash
   # Windows
   Get-Process -Name mysqld -ErrorAction SilentlyContinue

   # Linux
   systemctl status mysql
   ```

2. **确认连接参数** — 检查 `.env` 中 `DATABASE` 分组的 `HOSTNAME`、`HOSTPORT`、`USERNAME`、`PASSWORD`

3. **测试连接**
   ```bash
   mysql -h 127.0.0.1 -P 3306 -u root -p -e "SELECT 1"
   ```

4. **检查 MySQL 错误日志**
   - Windows: `.local/mysql/logs/error.log`
   - Linux: `/var/log/mysql/error.log`

5. **常见原因**
   - MySQL 未启动
   - 密码错误（注意 `.env` 中密码不含引号时的解析差异）
   - 端口被占用或被防火墙拦截
   - `HOSTNAME` 使用 `localhost` 时可能走 Unix socket，改用 `127.0.0.1` 强制走 TCP

### 6.2 路由 404

**现象：** 访问 API 返回 404 Not Found。

**排查步骤：**

1. **确认 URL 重写** — Nginx 需配置 rewrite 规则，Apache 需启用 `mod_rewrite`
2. **确认入口目录** — Web 根目录应指向 `public/`，而非项目根目录
3. **检查多应用路由** — ThinkPHP 多应用模式下，URL 格式为 `/{应用名}/{控制器}/{方法}`
4. **清除路由缓存**
   ```bash
   php think clear
   rm -rf runtime/api/cache/
   rm -rf runtime/tenantapi/cache/
   rm -rf runtime/platformapi/cache/
   ```

### 6.3 权限/Token 问题

**现象：** 接口返回 `401 Unauthorized` 或 Token 失效。

**排查步骤：**

1. **检查 Token 过期配置** — `config/project.php` 中的 `admin_token.expire_duration`（默认 8 小时）
2. **检查 UNIQUE_IDENTIFICATION** — 密码加密和 Token 生成都依赖此值，更换后旧 Token 将失效
3. **检查登录限制** — `admin_login.login_restrictions` 为 1 时，密码错误 5 次将锁定 30 分钟
4. **检查 Redis（如使用）** — Token 可能存储在 Redis 中，确认 Redis 连接正常

### 6.4 OpenAI 配置问题

**现象：** 智能解析功能不工作。

**排查步骤：**

1. **确认 API Key** — 检查 `.env` 中 `OPENAI_API_KEY` 是否正确配置
2. **确认网络** — 确保 `OPENAI_BASE_URL` 可达（可能需要代理）
3. **降级策略** — 未配置 API Key 时，系统自动降级为正则解析模式，不影响基本功能
4. **超时设置** — 默认超时 30 秒，如网络不稳定可适当调大 `OPENAI_TIMEOUT`

### 6.5 Composer 安装失败

**现象：** `composer install` 报错。

**排查步骤：**

1. **PHP 版本** — 确认 PHP >= 8.0：`php -v`
2. **缺失扩展** — 安装所需扩展后重试
3. **内存不足** — 临时增大内存限制：`php -d memory_limit=512M $(which composer) install`
4. **网络问题** — 切换国内镜像

### 6.6 迁移执行失败

**现象：** `php scripts/migrate.php` 报错。

**排查步骤：**

1. **查看状态** — `php scripts/migrate.php --status` 查看哪些迁移已应用
2. **数据库连接** — 确认 `.env` 中数据库配置正确
3. **SQL 语法** — 检查失败的迁移 SQL 文件语法
4. **重复列/索引** — 迁移器已对 `Duplicate column/key` 错误做容错处理，不影响后续迁移
5. **手动修复** — 如迁移记录与实际不一致，可手动调整 `lk_migration_history` 表

---

## 附录：本地开发环境 MySQL 配置

项目内置了本地开发用的 MySQL 8.4，配置文件位于 `.local/mysql/my.ini`：

```ini
[mysqld]
basedir=E:/object/BeiMi/.local/mysql/mysql-8.4.8-winx64
datadir=E:/object/BeiMi/.local/mysql/data
port=3306
bind-address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_general_ci
default-time-zone=+08:00
max_connections=200
sql_mode=STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION
```

**手动启动/停止 MySQL：**

```powershell
# 启动
& "E:\object\BeiMi\.local\mysql\mysql-8.4.8-winx64\bin\mysqld.exe" --defaults-file="E:\object\BeiMi\.local\mysql\my.ini"

# 停止
& "E:\object\BeiMi\.local\mysql\mysql-8.4.8-winx64\bin\mysqladmin.exe" -u root -p shutdown
```
