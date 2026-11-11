$ErrorActionPreference = "Stop"

# Cron: notificacao de vencimento / lifecycle de planos

$root = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$script = Join-Path $root "src\public\cron\notificar_vencimento_planos.php"
$logDir = Join-Path $root "app\storage\logs"
$logFile = Join-Path $logDir "plano_lifecycle.log"

if (-not (Test-Path $script)) {
    throw "Script nao encontrado: $script"
}

New-Item -ItemType Directory -Force -Path $logDir | Out-Null

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

& $php $script @args *>> $logFile
exit $LASTEXITCODE
