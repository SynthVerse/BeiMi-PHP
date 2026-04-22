#Requires -Version 5.1
<#
.SYNOPSIS
    BeiMi JXC CI 代码质量检查脚本
.DESCRIPTION
    1. PHP 语法检查（扫描 app/ 下所有 .php 文件）
    2. 路由完整性检查（验证 jxc.php 路由引用的 Controller 存在）
    3. Model-Schema 一致性检查（验证 Model 对应数据表存在）
    4. 可选 PHPUnit 单元测试
    5. 可选 Composer 安全审计
.PARAMETER Quick
    仅执行 PHP 语法检查
.PARAMETER Full
    执行全部检查并额外运行冒烟测试（需要 MySQL 运行）
.PARAMETER WithPhpUnit
    追加执行 PHPUnit 单元测试（需要 vendor/ 已安装）
.PARAMETER WithComposerAudit
    追加执行 composer audit --locked（可能需要网络访问）
.PARAMETER Help
    显示帮助信息
.EXAMPLE
    scripts/ci-check.ps1
    scripts/ci-check.ps1 -Quick
    scripts/ci-check.ps1 -Full
    scripts/ci-check.ps1 -WithPhpUnit -WithComposerAudit
.NOTES
    编码：UTF-8
#>

param(
    [switch]$Quick,
    [switch]$Full,
    [switch]$WithPhpUnit,
    [switch]$WithComposerAudit,
    [switch]$Help
)

# ── 配置 ──
$projectRoot = $PSScriptRoot | Split-Path -Parent   # scripts/ 的上一级
$phpPath     = 'C:\Users\ASUS\AppData\Local\Programs\PHP\8.2\php.exe'
if (-not (Test-Path $phpPath)) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        $phpPath = $phpCommand.Source
    }
}

# ── 帮助信息 ──
if ($Help) {
    Write-Host ""
    Write-Host "用法：scripts/ci-check.ps1 [-Quick] [-Full] [-WithPhpUnit] [-WithComposerAudit] [-Help]" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  无参数              执行：语法检查 + 路由检查 + Model检查"
    Write-Host "  -Quick              仅执行 PHP 语法检查"
    Write-Host "  -Full               执行全部检查 + 冒烟测试（需要 MySQL 运行）"
    Write-Host "  -WithPhpUnit        追加 PHPUnit 单元测试（需要 vendor/ 已安装）"
    Write-Host "  -WithComposerAudit  追加 composer audit --locked（可能需要网络访问）"
    Write-Host "  -Help               显示此帮助信息"
    Write-Host ""
    exit 0
}

# ── 全局状态 ──
$globalErrors = 0

# ── 工具函数 ──
function Write-Step {
    param([string]$StepNum, [string]$Title)
    Write-Host ""
    Write-Host "[$StepNum] $Title" -ForegroundColor Yellow
}

function Write-Pass {
    param([string]$Message)
    Write-Host "  $([char]0x2713) $Message" -ForegroundColor Green
}

function Write-Fail {
    param([string]$Message)
    Write-Host "  [FAIL] $Message" -ForegroundColor Red
}

function Write-Warn {
    param([string]$Message)
    Write-Host "  [WARN] $Message" -ForegroundColor DarkYellow
}

function Write-Info {
    param([string]$Message)
    Write-Host "  $Message" -ForegroundColor DarkGray
}

# ════════════════════════════════════════════════════════════
#  检查 1：PHP 语法检查
# ════════════════════════════════════════════════════════════
function Invoke-SyntaxCheck {
    Write-Info "Checking app/ ..."

    if (-not (Test-Path $phpPath)) {
        Write-Fail "找不到 PHP 可执行文件: $phpPath"
        return 1
    }

    $appDir  = Join-Path $projectRoot 'app'
    $errors  = 0
    $checked = 0

    $phpFiles = Get-ChildItem -Path $appDir -Filter '*.php' -Recurse
    foreach ($file in $phpFiles) {
        $checked++
        $result = & $phpPath -l $file.FullName 2>&1
        $exitCode = $LASTEXITCODE
        if ($exitCode -ne 0) {
            Write-Fail "$($file.FullName)"
            Write-Host "         $result" -ForegroundColor Red
            $errors++
        }
    }

    if ($errors -eq 0) {
        Write-Pass "$checked files checked, 0 errors"
    } else {
        Write-Fail "$checked files checked, $errors errors"
    }
    return $errors
}

# ════════════════════════════════════════════════════════════
#  检查 2：路由完整性检查
# ════════════════════════════════════════════════════════════
function Invoke-RouteCheck {
    Write-Info "Checking jxc.php routes..."

    $routeFile       = Join-Path $projectRoot 'app\api\route\jxc.php'
    $controllerDir   = Join-Path $projectRoot 'app\api\jxc\controller'

    if (-not (Test-Path $routeFile)) {
        Write-Fail "路由文件不存在: $routeFile"
        return 1
    }

    $content = Get-Content $routeFile -Raw -Encoding UTF8

    # 匹配路由字符串中的 'jxc.ControllerName/method' 模式
    # 例：'jxc.GoodsUnit/lists' => 提取 GoodsUnit
    $pattern = "'jxc\.([A-Za-z]+)/[A-Za-z]+'"
    $matches  = [regex]::Matches($content, $pattern)

    # 去重 Controller 名称
    $controllerNames = $matches | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique

    $errors      = 0
    $routeCount  = $matches.Count
    $verified    = 0

    foreach ($name in $controllerNames) {
        $fileName = "${name}Controller.php"
        $filePath = Join-Path $controllerDir $fileName

        if (Test-Path $filePath) {
            $verified++
        } else {
            Write-Fail "Controller 文件不存在: $fileName (路由引用: jxc.$name)"
            $errors++
        }
    }

    if ($errors -eq 0) {
        Write-Pass "$routeCount routes verified ($($controllerNames.Count) controllers)"
    } else {
        Write-Fail "$errors missing controller(s) found"
    }
    return $errors
}

# ════════════════════════════════════════════════════════════
#  检查 3：Model-Schema 一致性检查
# ════════════════════════════════════════════════════════════
function Invoke-ModelSchemaCheck {
    Write-Info "Checking model-table mapping..."

    $modelDir      = Join-Path $projectRoot 'app\common\model\jxc'
    $sqlDir        = Join-Path $projectRoot 'database\sql'
    $migrationsDir = Join-Path $projectRoot 'database\migrations'

    if (-not (Test-Path $modelDir)) {
        Write-Fail "Model 目录不存在: $modelDir"
        return 1
    }

    $schemaFiles = @()
    if (Test-Path $sqlDir) {
        $schemaFiles += Get-ChildItem -Path $sqlDir -Filter '*.sql' -File
    }
    if (Test-Path $migrationsDir) {
        $schemaFiles += Get-ChildItem -Path $migrationsDir -Filter '*.sql' -File
    }

    if ($schemaFiles.Count -eq 0) {
        Write-Fail "未找到 Schema 或迁移 SQL 文件"
        return 1
    }

    # 读取主 Schema 与迁移 SQL，提取所有 CREATE TABLE 表名（去掉前缀 lk_）
    $tablePattern = 'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?lk_([a-z0-9_]+)`?'
    $schemaTables = @()
    foreach ($schemaFile in $schemaFiles) {
        $sqlContent   = Get-Content $schemaFile.FullName -Raw -Encoding UTF8
        $tableMatches = [regex]::Matches($sqlContent, $tablePattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
        $schemaTables += $tableMatches | ForEach-Object { $_.Groups[1].Value }
    }
    $schemaTables = $schemaTables | Sort-Object -Unique

    $errors   = 0
    $warnings = 0
    $checked  = 0

    Get-ChildItem -Path $modelDir -Filter '*.php' | ForEach-Object {
        $checked++
        $modelFile    = $_.FullName
        $modelContent = Get-Content $modelFile -Raw -Encoding UTF8

        # 优先从 $name 属性提取表名（去掉 lk_ 前缀）
        $nameMatch = [regex]::Match($modelContent, "protected\s+\`$name\s*=\s*['""]([^'""]+)['""]")
        if ($nameMatch.Success) {
            $tableName = $nameMatch.Groups[1].Value -replace '^lk_', ''
        } else {
            # 回退：从类名推断（PascalCase => snake_case）
            $className = [System.IO.Path]::GetFileNameWithoutExtension($_.Name)
            # PascalCase 转 snake_case
            $tableName = [regex]::Replace($className, '(?<=[a-z])(?=[A-Z])', '_').ToLower()
        }

        if ($schemaTables -contains $tableName) {
            # 表存在，正常
        } else {
            Write-Warn "Model '$($_.Name)' 推断表名 'lk_$tableName' 在 Schema 中未找到"
            $warnings++
        }
    }

    if ($warnings -eq 0) {
        Write-Pass "$checked models verified against $($schemaFiles.Count) schema/migration file(s)"
    } else {
        Write-Host "  $([char]0x2713) $checked models checked, $warnings warning(s)" -ForegroundColor DarkYellow
    }
    return 0   # 警告不计为错误，不影响退出码
}

# ════════════════════════════════════════════════════════════
#  检查 4（--Full 专用）：冒烟测试
# ════════════════════════════════════════════════════════════
function Invoke-SmokeTest {
    Write-Info "Running smoke tests (requires MySQL)..."

    $smokeFile = Join-Path $projectRoot 'tests\smoke_test.php'
    if (-not (Test-Path $smokeFile)) {
        Write-Fail "冒烟测试文件不存在: $smokeFile"
        return 1
    }

    $result = & $phpPath $smokeFile 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Fail "冒烟测试失败"
        Write-Host $result -ForegroundColor Red
        return 1
    } else {
        Write-Host $result
        Write-Pass "冒烟测试通过"
        return 0
    }
}

# ════════════════════════════════════════════════════════════
#  可选检查：PHPUnit 单元测试
# ════════════════════════════════════════════════════════════
function Invoke-PhpUnitCheck {
    Write-Info "Running PHPUnit..."

    $phpunitBat = Join-Path $projectRoot 'vendor\bin\phpunit.bat'
    $phpunitBin = Join-Path $projectRoot 'vendor\bin\phpunit'
    $phpunitXml = Join-Path $projectRoot 'phpunit.xml'

    if (-not (Test-Path $phpunitXml)) {
        Write-Fail "phpunit.xml 不存在: $phpunitXml"
        return 1
    }

    if (Test-Path $phpunitBat) {
        $result = & $phpunitBat --configuration $phpunitXml 2>&1
    } elseif (Test-Path $phpunitBin) {
        $result = & $phpunitBin --configuration $phpunitXml 2>&1
    } else {
        Write-Fail "找不到 PHPUnit 可执行文件，请先安装 composer dev 依赖"
        return 1
    }

    if ($LASTEXITCODE -ne 0) {
        Write-Fail "PHPUnit 单元测试失败"
        Write-Host $result -ForegroundColor Red
        return 1
    }

    Write-Host $result
    Write-Pass "PHPUnit 单元测试通过"
    return 0
}

# ════════════════════════════════════════════════════════════
#  可选检查：Composer 安全审计
# ════════════════════════════════════════════════════════════
function Invoke-ComposerAuditCheck {
    Write-Info "Running composer audit --locked..."

    $composerPhar = Join-Path $projectRoot 'composer.phar'
    $composerLock = Join-Path $projectRoot 'composer.lock'

    if (-not (Test-Path $composerLock)) {
        Write-Fail "composer.lock 不存在: $composerLock"
        return 1
    }

    if ((Test-Path $composerPhar) -and (Test-Path $phpPath)) {
        $composerCacheDir = Join-Path $projectRoot 'runtime\composer-cache'
        if (-not (Test-Path $composerCacheDir)) {
            New-Item -ItemType Directory -Path $composerCacheDir -Force | Out-Null
        }
        $env:COMPOSER_CACHE_DIR = $composerCacheDir
        $result = & $phpPath $composerPhar audit --locked 2>&1
    } else {
        $composerCmd = Get-Command composer -ErrorAction SilentlyContinue
        if (-not $composerCmd) {
            Write-Fail "找不到 composer.phar 或全局 composer 命令"
            return 1
        }
        $composerCacheDir = Join-Path $projectRoot 'runtime\composer-cache'
        if (-not (Test-Path $composerCacheDir)) {
            New-Item -ItemType Directory -Path $composerCacheDir -Force | Out-Null
        }
        $env:COMPOSER_CACHE_DIR = $composerCacheDir
        $result = & $composerCmd.Source audit --locked 2>&1
    }

    if ($LASTEXITCODE -ne 0) {
        Write-Fail "Composer 安全审计失败"
        Write-Host $result -ForegroundColor Red
        return 1
    }

    Write-Host $result
    Write-Pass "Composer 安全审计通过"
    return 0
}

# ════════════════════════════════════════════════════════════
#  主流程
# ════════════════════════════════════════════════════════════
Write-Host ""
Write-Host "=== BeiMi JXC CI Check ===" -ForegroundColor Cyan

if ($Quick) {
    # ── Quick 模式：仅语法检查 ──
    Write-Step "1/1" "PHP Syntax Check..."
    $globalErrors += Invoke-SyntaxCheck

} elseif ($Full) {
    # ── Full 模式：全部检查 + 冒烟测试 ──
    Write-Step "1/4" "PHP Syntax Check..."
    $globalErrors += Invoke-SyntaxCheck

    Write-Step "2/4" "Route Integrity Check..."
    $globalErrors += Invoke-RouteCheck

    Write-Step "3/4" "Model-Schema Check..."
    $globalErrors += Invoke-ModelSchemaCheck

    Write-Step "4/4" "Smoke Test..."
    $globalErrors += Invoke-SmokeTest

} else {
    # ── 默认模式：语法 + 路由 + Model ──
    Write-Step "1/3" "PHP Syntax Check..."
    $globalErrors += Invoke-SyntaxCheck

    Write-Step "2/3" "Route Integrity Check..."
    $globalErrors += Invoke-RouteCheck

    Write-Step "3/3" "Model-Schema Check..."
    $globalErrors += Invoke-ModelSchemaCheck
}

if ($WithPhpUnit) {
    Write-Step "Optional" "PHPUnit Unit Tests..."
    $globalErrors += Invoke-PhpUnitCheck
}

if ($WithComposerAudit) {
    Write-Step "Optional" "Composer Audit..."
    $globalErrors += Invoke-ComposerAuditCheck
}

# ── 最终结果 ──
Write-Host ""
if ($globalErrors -eq 0) {
    Write-Host "=== Result: ALL PASSED ===" -ForegroundColor Green
    exit 0
} else {
    Write-Host "=== Result: FAILED ($globalErrors error(s)) ===" -ForegroundColor Red
    exit 1
}
