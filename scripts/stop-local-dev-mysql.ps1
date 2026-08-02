[CmdletBinding()]
param(
    [string]$ConfigPath = 'E:\object\BeiMi\.local\mysql\my-dev-lantu.ini',
    [int]$Port = 3306
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $ConfigPath -PathType Leaf)) {
    throw "local_dev_mysql_config_missing: $ConfigPath"
}

$listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
if ($null -eq $listener) {
    Write-Output "local_dev_mysql_not_running: port=$Port"
    exit 0
}

$owner = $listener | Select-Object -First 1 -ExpandProperty OwningProcess
$process = Get-Process -Id $owner -ErrorAction SilentlyContinue
if ($null -eq $process -or $process.ProcessName -ne 'mysqld') {
    throw "local_dev_mysql_port_owned_by_different_process: port=$Port owner=$owner"
}

$commandLine = (Get-CimInstance Win32_Process -Filter "ProcessId = $owner" -Property CommandLine).CommandLine
if ($commandLine -notlike "*$ConfigPath*") {
    throw "local_dev_mysql_port_owned_by_different_instance: port=$Port owner=$owner"
}

Stop-Process -Id $owner
Write-Output "local_dev_mysql_stopped: port=$Port pid=$owner"
