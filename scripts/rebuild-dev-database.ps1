#Requires -Version 5.1
<#
.SYNOPSIS
    备份并重建本机 BeiMi 开发数据库。
.DESCRIPTION
    仅允许连接 127.0.0.1/localhost，并要求显式传入 -ConfirmRebuild、
    与 .env 完全一致的 -ExpectedDatabase，以及 APP_ENV=development。
    执行顺序固定为：完整备份、删除并重建数据库、导入基础结构、
    导入 JXC 结构、运行正式迁移、执行开发种子。
.EXAMPLE
    $env:APP_ENV='development'
    .\scripts\rebuild-dev-database.ps1 -ConfirmRebuild -ExpectedDatabase lantu
#>

[CmdletBinding()]
param(
    [switch]$ConfirmRebuild,
    [Parameter(Mandatory = $true)][string]$ExpectedDatabase,
    [switch]$SkipSeed,
    [string]$ConfigPath = '',
    [string]$MySqlBin = 'E:\object\BeiMi\.local\mysql\mysql-8.4.10-winx64\bin',
    [string]$PhpPath = 'C:\Users\ASUS\AppData\Local\Programs\PHP\8.2\php.exe'
)

$ErrorActionPreference = 'Stop'

if (-not $ConfirmRebuild) {
    throw '拒绝执行：此操作会重建开发数据库，必须显式传入 -ConfirmRebuild。'
}

$projectRoot = Split-Path -Parent $PSScriptRoot
$defaultEnvPath = Join-Path $projectRoot '.env'
if ([string]::IsNullOrWhiteSpace($ConfigPath)) {
    $ConfigPath = $defaultEnvPath
}
$envPath = $ConfigPath
$mysqlPath = Join-Path $MySqlBin 'mysql.exe'
$dumpPath = Join-Path $MySqlBin 'mysqldump.exe'
$likeSchema = Join-Path $projectRoot 'public/install/db/like.sql'
$jxcSchema = Join-Path $projectRoot 'database/sql/jxc_phase1_schema.sql'
$migrateScript = Join-Path $projectRoot 'scripts/migrate.php'
$seedScript = Join-Path $projectRoot 'database/seed/jxc_phase1_dev_seed.php'
$prepareSqlScript = Join-Path $projectRoot 'scripts/prepare-sql.php'
$backupDirectory = Join-Path $projectRoot '.local/backups'
$safetyScript = Join-Path $projectRoot 'scripts/lib/RebuildDatabaseSafety.ps1'

foreach ($requiredPath in @($envPath, $mysqlPath, $dumpPath, $PhpPath, $likeSchema, $jxcSchema, $migrateScript, $prepareSqlScript, $safetyScript)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "缺少重建所需文件：$requiredPath"
    }
}

$readConfigScript = @'
$env = parse_ini_file($argv[1], true, INI_SCANNER_TYPED);
if ($env === false || !isset($env['DATABASE'])) {
    fwrite(STDERR, "invalid_database_config");
    exit(2);
}
$db = $env['DATABASE'];
echo json_encode([
    'host' => (string)($db['HOSTNAME'] ?? ''),
    'port' => (string)($db['HOSTPORT'] ?? '3306'),
    'database' => (string)($db['DATABASE'] ?? ''),
    'username' => (string)($db['USERNAME'] ?? ''),
    'password' => (string)($db['PASSWORD'] ?? ''),
    'charset' => (string)($db['CHARSET'] ?? 'utf8mb4'),
    'prefix' => (string)($db['PREFIX'] ?? 'la_'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
'@

$configJson = & $PhpPath -r $readConfigScript $envPath
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($configJson)) {
    throw '无法读取数据库配置。'
}
$config = $configJson | ConvertFrom-Json

. $safetyScript
Assert-BeimiDevelopmentDatabaseTarget `
    -ConfiguredDatabase ([string]$config.database) `
    -ExpectedDatabase $ExpectedDatabase `
    -RuntimeEnvironment ([string][Environment]::GetEnvironmentVariable('APP_ENV'))

if ($config.host -notin @('127.0.0.1', 'localhost')) {
    throw '拒绝执行：开发库重建只允许本机 MySQL。'
}
if ($config.database -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') {
    throw '拒绝执行：数据库名不安全。'
}
if ($config.database.ToLowerInvariant() -in @('information_schema', 'performance_schema', 'mysql', 'sys')) {
    throw '拒绝执行：禁止重建 MySQL 系统数据库。'
}
if ($config.prefix -notmatch '^[A-Za-z0-9_]*$') {
    throw "拒绝执行：数据库表前缀不安全：$($config.prefix)。"
}
if (-not $SkipSeed -and ([IO.Path]::GetFullPath($envPath) -cne [IO.Path]::GetFullPath($defaultEnvPath))) {
    throw '拒绝执行：使用 -ConfigPath 时必须同时传入 -SkipSeed，避免开发种子写入默认 .env 指向的数据库。'
}
if ($config.port -notmatch '^[0-9]{1,5}$' -or [int]$config.port -lt 1 -or [int]$config.port -gt 65535) {
    throw '拒绝执行：数据库端口不安全。'
}

function ConvertTo-OptionFileValue {
    param([AllowEmptyString()][string]$Value)
    return '"' + $Value.Replace('\', '\\').Replace('"', '\"') + '"'
}

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string]$Executable,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [Parameter(Mandatory = $true)][string]$FailureMessage
    )
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw $FailureMessage
    }
}

New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupFile = Join-Path $backupDirectory "$($config.database)-before-new-workflow-$timestamp.sql"
$credentialsFile = [IO.Path]::GetTempFileName()
$preparedLikeSchema = [IO.Path]::GetTempFileName()
$preparedJxcSchema = [IO.Path]::GetTempFileName()
$databaseExists = $false
$originalTableCount = 0
$rebuildStarted = $false

try {
    $credentialLines = @(
        '[client]'
        'host=' + (ConvertTo-OptionFileValue $config.host)
        'port=' + $config.port
        'user=' + (ConvertTo-OptionFileValue $config.username)
        'password=' + (ConvertTo-OptionFileValue $config.password)
        'default-character-set=' + $config.charset
    )
    [IO.File]::WriteAllLines($credentialsFile, $credentialLines, (New-Object Text.UTF8Encoding($false)))

    $defaultsArgument = "--defaults-extra-file=$credentialsFile"
    $databaseExistsResult = & $mysqlPath $defaultsArgument '--batch' '--skip-column-names' '--execute' "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$($config.database)';"
    if ($LASTEXITCODE -ne 0) {
        throw '无法连接本机 MySQL。'
    }
    $databaseExists = [int]($databaseExistsResult | Select-Object -First 1) -eq 1

    if ($databaseExists) {
        $originalTableCountResult = & $mysqlPath $defaultsArgument '--batch' '--skip-column-names' '--execute' "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '$($config.database)';"
        if ($LASTEXITCODE -ne 0) {
            throw '无法读取原开发数据库表数量。'
        }
        $originalTableCount = [int]($originalTableCountResult | Select-Object -First 1)
        Invoke-Checked -Executable $dumpPath -Arguments @(
            $defaultsArgument,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            "--result-file=$backupFile",
            $config.database
        ) -FailureMessage '开发数据库备份失败，已停止重建。'
        if (-not (Test-Path -LiteralPath $backupFile -PathType Leaf) -or (Get-Item -LiteralPath $backupFile).Length -eq 0) {
            throw '开发数据库备份文件为空，已停止重建。'
        }
        Write-Host "备份已完成：$backupFile" -ForegroundColor Green
    } else {
        Write-Host '数据库尚不存在，无需生成旧库备份。' -ForegroundColor DarkYellow
    }

    $quotedDatabase = '`' + $config.database + '`'
    $recreateSql = "DROP DATABASE IF EXISTS $quotedDatabase; CREATE DATABASE $quotedDatabase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    $rebuildStarted = $true
    Invoke-Checked -Executable $mysqlPath -Arguments @($defaultsArgument, '--execute', $recreateSql) -FailureMessage '删除并重建开发数据库失败。'

    Invoke-Checked -Executable $PhpPath -Arguments @(
        $prepareSqlScript,
        "--input=$likeSchema",
        "--output=$preparedLikeSchema",
        "--prefix=$($config.prefix)"
    ) -FailureMessage '预处理 public/install/db/like.sql 失败。'
    Invoke-Checked -Executable $PhpPath -Arguments @(
        $prepareSqlScript,
        "--input=$jxcSchema",
        "--output=$preparedJxcSchema",
        "--prefix=$($config.prefix)"
    ) -FailureMessage '预处理 database/sql/jxc_phase1_schema.sql 失败。'

    $likeSource = $preparedLikeSchema.Replace('\', '/')
    Invoke-Checked -Executable $mysqlPath -Arguments @($defaultsArgument, $config.database, '--execute', "source $likeSource") -FailureMessage '导入 public/install/db/like.sql 失败。'

    $jxcSource = $preparedJxcSchema.Replace('\', '/')
    Invoke-Checked -Executable $mysqlPath -Arguments @($defaultsArgument, $config.database, '--execute', "source $jxcSource") -FailureMessage '导入 database/sql/jxc_phase1_schema.sql 失败。'

    Invoke-Checked -Executable $PhpPath -Arguments @($migrateScript, "--env=$envPath") -FailureMessage '运行 scripts/migrate.php 失败。'

    if (-not $SkipSeed) {
        if (-not (Test-Path -LiteralPath $seedScript -PathType Leaf)) {
            throw "缺少开发种子脚本：$seedScript"
        }
        Invoke-Checked -Executable $PhpPath -Arguments @($seedScript) -FailureMessage '执行开发种子失败。'
    }

    Write-Host "开发数据库 $($config.database) 已按 $($config.prefix) 新工作流结构完成重建。" -ForegroundColor Green
} catch {
    $rebuildError = $_
    if ($rebuildStarted -and $databaseExists -and (Test-Path -LiteralPath $backupFile -PathType Leaf)) {
        Write-Host "重建失败，正在从备份恢复原开发数据库：$backupFile" -ForegroundColor Yellow
        try {
            $restoreRecreateSql = "DROP DATABASE IF EXISTS $quotedDatabase; CREATE DATABASE $quotedDatabase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
            Invoke-Checked -Executable $mysqlPath -Arguments @($defaultsArgument, '--execute', $restoreRecreateSql) -FailureMessage '恢复前重新创建开发数据库失败。'
            $backupSource = $backupFile.Replace('\', '/')
            Invoke-Checked -Executable $mysqlPath -Arguments @($defaultsArgument, $config.database, '--execute', "source $backupSource") -FailureMessage '从备份恢复开发数据库失败。'
            $restoredTableCountResult = & $mysqlPath $defaultsArgument '--batch' '--skip-column-names' '--execute' "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '$($config.database)';"
            if ($LASTEXITCODE -ne 0) {
                throw '无法验证恢复后的开发数据库。'
            }
            $restoredTableCount = [int]($restoredTableCountResult | Select-Object -First 1)
            if ($restoredTableCount -ne $originalTableCount) {
                throw "恢复后的表数量不一致：期望 $originalTableCount，实际 $restoredTableCount。"
            }
            Write-Host "开发数据库已从备份恢复：$backupFile" -ForegroundColor Green
        } catch {
            Write-Host "自动恢复失败，请立即使用备份人工恢复：$backupFile；错误：$($_.Exception.Message)" -ForegroundColor Red
        }
    } elseif ($rebuildStarted -and -not $databaseExists) {
        try {
            Invoke-Checked -Executable $mysqlPath -Arguments @($defaultsArgument, '--execute', "DROP DATABASE IF EXISTS $quotedDatabase;") -FailureMessage '清理失败的新建开发数据库失败。'
        } catch {
            Write-Host "重建失败且未能清理新建数据库 $($config.database)。" -ForegroundColor Red
        }
    }
    throw $rebuildError
} finally {
    if (Test-Path -LiteralPath $credentialsFile) {
        Remove-Item -LiteralPath $credentialsFile -Force
    }
    foreach ($preparedSchema in @($preparedLikeSchema, $preparedJxcSchema)) {
        if (Test-Path -LiteralPath $preparedSchema) {
            Remove-Item -LiteralPath $preparedSchema -Force
        }
    }
}
