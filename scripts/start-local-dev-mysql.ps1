[CmdletBinding()]
param(
    [string]$MySqlBase = 'E:\object\BeiMi\.local\mysql\mysql-8.4.10-winx64',
    [string]$ConfigPath = 'E:\object\BeiMi\.local\mysql\my-dev-lantu.ini',
    [int]$Port = 3306,
    [int]$ReadyTimeoutSeconds = 30
)

$ErrorActionPreference = 'Stop'
$mysqldPath = Join-Path $MySqlBase 'bin\mysqld.exe'

foreach ($requiredPath in @($mysqldPath, $ConfigPath)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "local_dev_mysql_required_path_missing: $requiredPath"
    }
}

$listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
if ($null -ne $listener) {
    $owner = $listener | Select-Object -First 1 -ExpandProperty OwningProcess
    $process = Get-Process -Id $owner -ErrorAction SilentlyContinue
    if ($null -eq $process -or $process.ProcessName -ne 'mysqld') {
        throw "local_dev_mysql_port_in_use: port=$Port owner=$owner"
    }

    $commandLine = (Get-CimInstance Win32_Process -Filter "ProcessId = $owner" -Property CommandLine).CommandLine
    if ($commandLine -notlike "*$ConfigPath*") {
        throw "local_dev_mysql_port_owned_by_different_instance: port=$Port owner=$owner"
    }

    Write-Output "local_dev_mysql_already_running: port=$Port pid=$owner"
    exit 0
}

$dataDirectory = $null
foreach ($line in Get-Content -LiteralPath $ConfigPath) {
    if ($line -match '^\s*datadir\s*=\s*(.+)\s*$') {
        $dataDirectory = $matches[1].Trim().Trim('"').Replace('/', '\')
        break
    }
}
if ([string]::IsNullOrWhiteSpace($dataDirectory)) {
    throw 'local_dev_mysql_datadir_missing_from_config'
}

if (-not (Test-Path -LiteralPath $dataDirectory -PathType Container)) {
    & $mysqldPath "--defaults-file=$ConfigPath" '--initialize-insecure'
    if ($LASTEXITCODE -ne 0) {
        throw "local_dev_mysql_initialize_failed: exit_code=$LASTEXITCODE"
    }
}

$process = Start-Process -FilePath $mysqldPath -ArgumentList "--defaults-file=$ConfigPath" -WindowStyle Hidden -PassThru
$deadline = (Get-Date).AddSeconds($ReadyTimeoutSeconds)
do {
    if ($process.HasExited) {
        throw "local_dev_mysql_exited_before_ready: exit_code=$($process.ExitCode) log=$dataDirectory\\dev-lantu-error.log"
    }
    try {
        $client = [System.Net.Sockets.TcpClient]::new()
        $connect = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        if ($connect.AsyncWaitHandle.WaitOne(1000)) {
            $client.EndConnect($connect)
            Write-Output "local_dev_mysql_started: port=$Port pid=$($process.Id)"
            exit 0
        }
    } catch {
        # The server has not completed startup yet.
    } finally {
        if ($null -ne $client) {
            $client.Dispose()
        }
    }
    Start-Sleep -Milliseconds 500
} while ((Get-Date) -lt $deadline)

if (-not $process.HasExited) {
    Stop-Process -Id $process.Id -Force
}
throw "local_dev_mysql_start_timeout: port=$Port log=$dataDirectory\dev-lantu-error.log"
