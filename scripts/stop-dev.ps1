#Requires -Version 5.1
<#
.SYNOPSIS
    停止 BeiMi JXC 本地开发环境（PHP 内置服务器 + MySQL）
.DESCRIPTION
    1. 停止 PHP 内置服务器进程
    2. 停止 MySQL 进程（使用 mysqladmin shutdown）
    3. 输出停止完成提示
.NOTES
    编码：UTF-8
#>

$ErrorActionPreference = 'Stop'

# ── 配置 ──
$mysqlBase    = 'E:\object\BeiMi\.local\mysql\mysql-8.4.8-winx64'
$mysqlAdmin   = Join-Path $mysqlBase 'bin\mysqladmin.exe'
$phpPath      = 'C:\Users\ASUS\AppData\Local\Programs\PHP\8.2\php.exe'

Write-Host '============================================================' -ForegroundColor Cyan
Write-Host ' BeiMi JXC 开发环境停止' -ForegroundColor Cyan
Write-Host '============================================================' -ForegroundColor Cyan

# ── 1. 停止 PHP 内置服务器 ──
Write-Host ''
Write-Host '[1/2] 停止 PHP 内置服务器 ...' -ForegroundColor Yellow

$phpStopped = $false
try {
    $phpProcs = Get-Process -Name 'php' -ErrorAction SilentlyContinue
    if ($phpProcs) {
        foreach ($proc in $phpProcs) {
            try {
                Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
                Write-Host "  已停止 PHP 进程 (PID: $($proc.Id))" -ForegroundColor Green
                $phpStopped = $true
            } catch {
                Write-Host "  停止 PHP 进程失败 (PID: $($proc.Id)): $($_.Exception.Message)" -ForegroundColor Red
            }
        }
    } else {
        Write-Host '  没有发现运行中的 PHP 进程。' -ForegroundColor DarkGray
    }
} catch {
    Write-Host "  检查 PHP 进程时出错: $($_.Exception.Message)" -ForegroundColor DarkGray
}

if (-not $phpStopped) {
    # 尝试按端口查找
    try {
        $portProcs = Get-NetTCPConnection -LocalPort 8000 -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique
        foreach ($pid in $portProcs) {
            try {
                Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue
                Write-Host "  已停止占用端口 8000 的进程 (PID: $pid)" -ForegroundColor Green
                $phpStopped = $true
            } catch {}
        }
    } catch {}
}

# ── 2. 停止 MySQL ──
Write-Host ''
Write-Host '[2/2] 停止 MySQL ...' -ForegroundColor Yellow

$mysqlStopped = $false

# 优先使用 mysqladmin shutdown（安全关闭）
if (Test-Path $mysqlAdmin) {
    try {
        & $mysqlAdmin -u root -psMBsMrAPSxetC6HR shutdown 2>$null
        if ($LASTEXITCODE -eq 0) {
            Write-Host '  MySQL 正在关闭 (mysqladmin shutdown) ...' -ForegroundColor Green
            # 等待进程退出
            $waitCount = 0
            while ($waitCount -lt 15) {
                $mysqld = Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue
                if (-not $mysqld) {
                    $mysqlStopped = $true
                    break
                }
                Start-Sleep -Seconds 1
                $waitCount++
            }
            if ($mysqlStopped) {
                Write-Host '  MySQL 已关闭。' -ForegroundColor Green
            } else {
                Write-Host '  MySQL 关闭超时，尝试强制停止 ...' -ForegroundColor DarkYellow
            }
        }
    } catch {
        Write-Host "  mysqladmin 执行失败: $($_.Exception.Message)" -ForegroundColor DarkYellow
    }
} else {
    Write-Host "  找不到 mysqladmin: $mysqlAdmin" -ForegroundColor DarkYellow
}

# 如果 mysqladmin 未能关闭，强制终止
if (-not $mysqlStopped) {
    try {
        $mysqldProcs = Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue
        if ($mysqldProcs) {
            foreach ($proc in $mysqldProcs) {
                try {
                    Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
                    Write-Host "  已强制停止 MySQL 进程 (PID: $($proc.Id))" -ForegroundColor DarkYellow
                } catch {
                    Write-Host "  停止 MySQL 进程失败 (PID: $($proc.Id)): $($_.Exception.Message)" -ForegroundColor Red
                }
            }
            $mysqlStopped = $true
        } else {
            Write-Host '  没有发现运行中的 MySQL 进程。' -ForegroundColor DarkGray
            $mysqlStopped = $true
        }
    } catch {
        Write-Host "  检查 MySQL 进程时出错: $($_.Exception.Message)" -ForegroundColor DarkGray
    }
}

# ── 3. 输出结果 ──
Write-Host ''
Write-Host '============================================================' -ForegroundColor Green
Write-Host ' 开发环境已停止！' -ForegroundColor Green
Write-Host " PHP 服务器: 已停止" -ForegroundColor White
Write-Host " MySQL:      已停止" -ForegroundColor White
Write-Host '============================================================' -ForegroundColor Green
