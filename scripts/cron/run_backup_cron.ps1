$ErrorActionPreference = "Stop"

# Cron: backup automatico
# Localizacao real: scripts\cron\  |  Wrapper na raiz: run_backup_cron.ps1

$root = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$script = Join-Path $root "src\public\cron\backup_automatico.php"

if (-not (Test-Path $script)) {
    throw "Script nao encontrado: $script"
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

& $php $script @args
exit $LASTEXITCODE
