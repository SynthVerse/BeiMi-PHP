function Assert-BeimiDevelopmentDatabaseTarget {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$ConfiguredDatabase,
        [Parameter(Mandatory = $true)][string]$ExpectedDatabase,
        [Parameter(Mandatory = $true)][string]$RuntimeEnvironment
    )

    $allowedDevelopmentDatabases = @(
        'lantu',
        'beimi_dev',
        'beimi_local',
        'beimi_test',
        'beimi_test_inventory'
    )

    if ([string]::IsNullOrWhiteSpace($ExpectedDatabase) -or $ConfiguredDatabase -cne $ExpectedDatabase) {
        throw '拒绝执行：-ExpectedDatabase 必须与 .env DATABASE.DATABASE 完全一致。'
    }
    if ($RuntimeEnvironment -cne 'development') {
        throw '拒绝执行：APP_ENV 必须显式设置为 development。'
    }
    if ($ConfiguredDatabase -cnotin $allowedDevelopmentDatabases) {
        throw "拒绝执行：数据库 $ConfiguredDatabase 不在 BeiMi 开发库白名单中。"
    }
}
