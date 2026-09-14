[CmdletBinding()]
param(
    [string]$ProjectPath = (Split-Path -Parent $PSScriptRoot),
    [string]$CodegraphCli = ''
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectPath).Path
if (-not (Test-Path -LiteralPath (Join-Path $project '.codegraph\codegraph.db') -PathType Leaf)) {
    throw 'codegraph_existing_index_required: initialization requires separate user authorization'
}
if ([string]::IsNullOrWhiteSpace($CodegraphCli)) {
    $command = Get-Command codegraph -ErrorAction SilentlyContinue
    if ($null -ne $command) { $CodegraphCli = $command.Source }
    else { $CodegraphCli = Join-Path ([Environment]::GetFolderPath('ApplicationData')) 'npm\codegraph.cmd' }
}
if (-not (Test-Path -LiteralPath $CodegraphCli -PathType Leaf)) {
    throw "codegraph_cli_not_visible: $CodegraphCli (retry in the desktop user's host context; do not reinitialize the index)"
}

& $CodegraphCli sync $project --quiet
if ($LASTEXITCODE -ne 0) { throw "codegraph_sync_failed: $project" }
& $CodegraphCli status $project
if ($LASTEXITCODE -ne 0) { throw "codegraph_status_failed: $project" }
