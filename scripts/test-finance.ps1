[CmdletBinding()]
param(
    [string]$PhpPath = '',
    [string]$Filter = '^tests\\unit\\Finance'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$mysqlRoot = Join-Path (Split-Path -Parent $projectRoot) '.local\mysql'
$testConfig = Join-Path $mysqlRoot 'my-test.ini'

if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $phpCommand) { $PhpPath = $phpCommand.Source }
    else { $PhpPath = Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'Programs\PHP\8.2\php.exe' }
}
foreach ($requiredFile in @($PhpPath, $testConfig, (Join-Path $projectRoot '.env.testing'), (Join-Path $projectRoot 'vendor\bin\phpunit'))) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "finance_test_required_file_missing: $requiredFile (run in the desktop user's host context if sandbox visibility is restricted)"
    }
}

# 只恢复既有测试实例，不初始化或重建数据，不使用开发实例的默认 3306 配置。
$serverSettings = @{}
$section = ''
foreach ($line in Get-Content -LiteralPath $testConfig) {
    if ($line -match '^\s*\[([^\]]+)\]') { $section = $matches[1].ToLowerInvariant(); continue }
    if ($section -eq 'mysqld' -and $line -match '^\s*([^#;=]+?)\s*=\s*(.*?)\s*$') {
        $serverSettings[$matches[1].Trim().ToLowerInvariant()] = $matches[2].Trim().Trim('"')
    }
}
if ($serverSettings['port'] -ne '3307' -or $serverSettings['bind-address'] -ne '127.0.0.1') {
    throw 'finance_test_mysql_config_must_bind_loopback_3307'
}
$testData = $serverSettings['datadir']
if ([string]::IsNullOrWhiteSpace($testData) -or -not (Test-Path -LiteralPath $testData -PathType Container)) {
    throw 'finance_test_existing_datadir_required'
}
if ((Resolve-Path -LiteralPath $testData).Path -ne (Join-Path $mysqlRoot 'data-test')) {
    throw 'finance_test_datadir_must_be_dedicated_data_test'
}

& (Join-Path $PSScriptRoot 'start-local-dev-mysql.ps1') -MySqlBase (Join-Path $mysqlRoot 'mysql-8.4.10-winx64') -ConfigPath $testConfig -Port 3307 -ReadyTimeoutSeconds 180
if ($LASTEXITCODE -ne 0) { throw 'finance_test_mysql_start_failed' }

Push-Location $projectRoot
try {
    # bootstrap 再验证 .env.testing 的本机地址、3307 端口、beimi_test_* 库名与隔离标记。
    & $PhpPath (Join-Path $projectRoot 'vendor\bin\phpunit') --configuration (Join-Path $projectRoot 'phpunit.xml') --filter $Filter --colors=never
    $testExit = $LASTEXITCODE
} finally { Pop-Location }
exit $testExit
