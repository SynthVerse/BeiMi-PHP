#Requires -Version 5.1
<#
.SYNOPSIS
    初始化 BeiMi JXC 数据库（执行 schema + seed）
.DESCRIPTION
    1. 检查 MySQL 是否运行
    2. 执行 database/sql/jxc_phase1_schema.sql
    3. 执行 database/seed/jxc_phase1_dev_seed.php（用 PHP 执行）
    4. 输出初始化结果
.NOTES
    编码：UTF-8
#>

$ErrorActionPreference = 'Stop'

# ── 配置 ──
$mysqlBase    = 'E:\object\BeiMi\.local\mysql\mysql-8.4.8-winx64'
$mysqlPath    = Join-Path $mysqlBase 'bin\mysql.exe'
$phpPath      = 'C:\Users\ASUS\AppData\Local\Programs\PHP\8.2\php.exe'
$projectRoot  = 'E:\object\BeiMi\BeiMi-PHP'
$dbName       = 'jxcsass'
$dbUser       = 'root'
$dbPass       = 'sMBsMrAPSxetC6HR'
$dbHost       = '127.0.0.1'
$dbPort       = '3306'

$schemaFile   = Join-Path $projectRoot 'database\sql\jxc_phase1_schema.sql'
$seedFile     = Join-Path $projectRoot 'database\seed\jxc_phase1_dev_seed.php'

Write-Host '============================================================' -ForegroundColor Cyan
Write-Host ' BeiMi JXC 数据库初始化' -ForegroundColor Cyan
Write-Host '============================================================' -ForegroundColor Cyan

# ── 1. 检查 MySQL 是否运行 ──
Write-Host ''
Write-Host '[1/4] 检查 MySQL 是否运行 ...' -ForegroundColor Yellow

$mysqlReady = $false
try {
    $result = & $mysqlPath -h $dbHost -P $dbPort -u $dbUser -p"${dbPass}" -e 'SELECT 1' 2>$null
    if ($LASTEXITCODE -eq 0) {
        $mysqlReady = $true
        Write-Host '  MySQL 运行中。' -ForegroundColor Green
    }
} catch {}

if (-not $mysqlReady) {
    Write-Host '  [ERROR] MySQL 未运行，请先执行 scripts/start-dev.ps1' -ForegroundColor Red
    exit 1
}

# ── 2. 确保数据库存在 ──
Write-Host ''
Write-Host '[2/4] 确保数据库存在 ...' -ForegroundColor Yellow

$createDbSql = "CREATE DATABASE IF NOT EXISTS `${dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
& $mysqlPath -h $dbHost -P $dbPort -u $dbUser -p"${dbPass}" -e $createDbSql 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host "  数据库 '${dbName}' 已就绪。" -ForegroundColor Green
} else {
    Write-Host "  [ERROR] 创建数据库失败。" -ForegroundColor Red
    exit 1
}

# ── 3. 执行 Schema SQL ──
Write-Host ''
Write-Host '[3/4] 执行 Schema SQL ...' -ForegroundColor Yellow

if (-not (Test-Path $schemaFile)) {
    Write-Host "  [ERROR] 找不到 Schema 文件: $schemaFile" -ForegroundColor Red
    exit 1
}

$env:MYSQL_PWD = $dbPass
& $mysqlPath -h $dbHost -P $dbPort -u $dbUser "${dbName}" -e "source $schemaFile" 2>$null
$schemaExit = $LASTEXITCODE
Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue

if ($schemaExit -eq 0) {
    Write-Host '  Schema 执行成功。' -ForegroundColor Green
} else {
    Write-Host '  [ERROR] Schema 执行失败，请检查 SQL 文件。' -ForegroundColor Red
    exit 1
}

# ── 4. 执行 Seed PHP ──
Write-Host ''
Write-Host '[4/4] 执行 Seed 脚本 ...' -ForegroundColor Yellow

if (-not (Test-Path $phpPath)) {
    Write-Host "  [ERROR] 找不到 PHP: $phpPath" -ForegroundColor Red
    exit 1
}

if (-not (Test-Path $seedFile)) {
    Write-Host "  [ERROR] 找不到 Seed 文件: $seedFile" -ForegroundColor Red
    exit 1
}

& $phpPath $seedFile 2>&1
$seedExit = $LASTEXITCODE

if ($seedExit -eq 0) {
    Write-Host '  Seed 执行成功。' -ForegroundColor Green
} else {
    Write-Host "  [WARN] Seed 执行退出码: $seedExit，请检查输出。" -ForegroundColor DarkYellow
}

# ── 5. 输出结果 ──
Write-Host ''
Write-Host '============================================================' -ForegroundColor Green
Write-Host ' 数据库初始化完成！' -ForegroundColor Green
Write-Host " 数据库: ${dbName}" -ForegroundColor White
Write-Host " Schema: $schemaFile" -ForegroundColor White
Write-Host " Seed:   $seedFile" -ForegroundColor White
Write-Host '============================================================' -ForegroundColor Green
