#Requires -Version 5.1
<#
.SYNOPSIS
    BeiMi JXC CI 代码质量检查脚本
.DESCRIPTION
    1. PHP 语法检查（扫描 app/ 下所有 .php 文件）
    2. 路由完整性检查（验证 jxc.php 路由引用的 Controller 存在）
    3. Model-Schema 一致性检查（验证 Model 对应数据表存在）
.PARAMETER Quick
    仅执行 PHP 语法检查
.PARAMETER Full
    执行全部检查并额外运行冒烟测试（需要 MySQL 运行）
.PARAMETER Help
    显示帮助信息
.EXAMPLE
    scripts/ci-check.ps1
    scripts/ci-check.ps1 -Quick
    scripts/ci-check.ps1 -Full
.NOTES
    编码：UTF-8
#>

param(
    [switch]$Quick,
    [switch]$Full,
    [switch]$Help
)

# ── 配置 ──
$phpPath     = 'C:\Users\ASUS\AppData\Local\Programs\PHP\8.2\php.exe'
$projectRoot = $PSScriptRoot | Split-Path -Parent   # scripts/ 的上一级

# ── 帮助信息 ──
if ($Help) {
    Write-Host ""
    Write-Host "用法：scripts/ci-check.ps1 [-Quick] [-Full] [-Help]" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  无参数    执行：语法检查 + 路由检查 + Model检查"
    Write-Host "  -Quick    仅执行 PHP 语法检查"
    Write-Host "  -Full     执行全部检查 + 冒烟测试（需要 MySQL 运行）"
    Write-Host "  -Help     显示此帮助信息"
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

    $modelDir   = Join-Path $projectRoot 'app\common\model\jxc'
    $schemaFile = Join-Path $projectRoot 'database\sql\jxc_phase1_schema.sql'

    if (-not (Test-Path $modelDir)) {
        Write-Fail "Model 目录不存在: $modelDir"
        return 1
    }
    if (-not (Test-Path $schemaFile)) {
        Write-Fail "Schema 文件不存在: $schemaFile"
        return 1
    }

    # 读取 SQL 文件，提取所有 CREATE TABLE 表名（去掉前缀 lk_）
    $sqlContent   = Get-Content $schemaFile -Raw -Encoding UTF8
    $tablePattern = 'CREATE TABLE IF NOT EXISTS `lk_([a-z0-9_]+)`'
    $tableMatches = [regex]::Matches($sqlContent, $tablePattern)
    $schemaTables = $tableMatches | ForEach-Object { $_.Groups[1].Value }

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
        Write-Pass "$checked models verified"
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

# ── 最终结果 ──
Write-Host ""
if ($globalErrors -eq 0) {
    Write-Host "=== Result: ALL PASSED ===" -ForegroundColor Green
    exit 0
} else {
    Write-Host "=== Result: FAILED ($globalErrors error(s)) ===" -ForegroundColor Red
    exit 1
}
