#Requires -Version 5.1
<#
.SYNOPSIS
    启动 BeiMi JXC 本地开发环境（MySQL + PHP 内置服务器）
.DESCRIPTION
    1. 启动 MySQL（后台进程）
    2. 等待 MySQL 就绪（循环尝试连接，最多 30 秒）
    3. 启动 PHP 内置服务器（后台进程）
    4. 输出提示信息
.NOTES
    编码：UTF-8
#>

$ErrorActionPreference = 'Stop'

# ── 配置 ──
$mysqlBase   = 'E:\object\BeiMi\.local\mysql\mysql-8.4.8-winx64'
$mysqlConfig = 'E:\object\BeiMi\.local\mysql\my.ini'
$phpPath     = 'C:\Users\ASUS\AppData\Local\Programs\PHP\8.2\php.exe'
$projectRoot = 'E:\object\BeiMi\BeiMi-PHP'
$hostPort    = '127.0.0.1:8000'

$mysqldPath  = Join-Path $mysqlBase 'bin\mysqld.exe'
$mysqlPath   = Join-Path $mysqlBase 'bin\mysql.exe'

Write-Host '============================================================' -ForegroundColor Cyan
Write-Host ' BeiMi JXC 开发环境启动' -ForegroundColor Cyan
Write-Host '============================================================' -ForegroundColor Cyan

# ── 1. 启动 MySQL ──
Write-Host ''
Write-Host '[1/3] 启动 MySQL ...' -ForegroundColor Yellow

# 检查 MySQL 是否已经在运行
$mysqlRunning = $false
try {
    $proc = Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue
    if ($proc) {
        Write-Host '  MySQL 已在运行中，跳过启动。' -ForegroundColor Green
        $mysqlRunning = $true
    }
} catch {}

if (-not $mysqlRunning) {
    if (-not (Test-Path $mysqldPath)) {
        Write-Host "  [ERROR] 找不到 mysqld: $mysqldPath" -ForegroundColor Red
        exit 1
    }

    $mysqlProc = Start-Process -FilePath $mysqldPath -ArgumentList "--defaults-file=`"$mysqlConfig`"" -WindowStyle Hidden -PassThru
    Write-Host "  MySQL 进程已启动 (PID: $($mysqlProc.Id))" -ForegroundColor Green
}

# ── 2. 等待 MySQL 就绪 ──
Write-Host ''
Write-Host '[2/3] 等待 MySQL 就绪 ...' -ForegroundColor Yellow

$maxWaitSeconds = 30
$waited = 0
$mysqlReady = $false

while ($waited -lt $maxWaitSeconds) {
    try {
        $result = & $mysqlPath -u root -psMBsMrAPSxetC6HR -e 'SELECT 1' 2>$null
        if ($LASTEXITCODE -eq 0) {
            $mysqlReady = $true
            break
        }
    } catch {}

    Start-Sleep -Seconds 1
    $waited++
    Write-Host "  等待中 ... ($waited/${maxWaitSeconds}s)" -ForegroundColor DarkGray
}

if ($mysqlReady) {
    Write-Host "  MySQL 已就绪 (耗时 ${waited}s)" -ForegroundColor Green
} else {
    Write-Host '  [ERROR] MySQL 在 30 秒内未就绪，请检查日志。' -ForegroundColor Red
    exit 1
}

# ── 3. 启动 PHP 内置服务器 ──
Write-Host ''
Write-Host '[3/3] 启动 PHP 内置服务器 ...' -ForegroundColor Yellow

if (-not (Test-Path $phpPath)) {
    Write-Host "  [ERROR] 找不到 PHP: $phpPath" -ForegroundColor Red
    exit 1
}

$publicDir = Join-Path $projectRoot 'public'
$routerFile = Join-Path $publicDir 'router.php'

# 检查端口是否已被占用
$portInUse = $false
try {
    $conn = Get-NetTCPConnection -LocalPort 8000 -ErrorAction SilentlyContinue
    if ($conn) {
        Write-Host '  端口 8000 已被占用，PHP 服务器可能已在运行。' -ForegroundColor DarkYellow
        $portInUse = $true
    }
} catch {}

if (-not $portInUse) {
    $phpProc = Start-Process -FilePath $phpPath -ArgumentList "-S", $hostPort, "-t", $publicDir, $routerFile -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru
    Write-Host "  PHP 服务器已启动 (PID: $($phpProc.Id))" -ForegroundColor Green
}

# ── 4. 输出提示 ──
Write-Host ''
Write-Host '============================================================' -ForegroundColor Green
Write-Host ' 开发环境已就绪！' -ForegroundColor Green
Write-Host " MySQL:   localhost:3306" -ForegroundColor White
Write-Host " PHP:     http://${hostPort}" -ForegroundColor White
Write-Host " API:     http://${hostPort}/api/" -ForegroundColor White
Write-Host ''
Write-Host ' 使用 scripts/stop-dev.ps1 停止服务' -ForegroundColor DarkGray
Write-Host '============================================================' -ForegroundColor Green
