$ErrorActionPreference = "Stop"

# Cron: security ops runner

$root = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$runner = Join-Path $root "tools\security_ops_runner.php"

if (-not (Test-Path $runner)) {
    throw "Runner nao encontrado: $runner"
}

$phpCandidates = @(
    "C:\xampp\php\php.exe",
    "C:\php\php.exe"
)

$php = $null
foreach ($c in $phpCandidates) {
    if (Test-Path $c) {
        $php = $c
        break
    }
}

if (-not $php) {
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCmd) {
        $php = $phpCmd.Source
    }
}

if (-not $php) {
    throw "php.exe nao encontrado."
}

& $php $runner --minutes=10 --threshold=25 --invalid-threshold=30 --block-minutes=30 @args
exit $LASTEXITCODE
