param(
    [string]$Url = 'http://localhost:3000/V00/src/public/vendas.php'
)

$edgeCandidates = @(
    (Join-Path ${env:ProgramFiles(x86)} 'Microsoft\Edge\Application\msedge.exe'),
    (Join-Path $env:ProgramFiles 'Microsoft\Edge\Application\msedge.exe')
) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }

if (-not $edgeCandidates) {
    throw 'Microsoft Edge nao encontrado. Instale o Edge antes de iniciar o terminal POS.'
}

$edgePath = $edgeCandidates[0]
$profilePath = Join-Path $env:LOCALAPPDATA 'NivoraPOS\EdgeProfile'
New-Item -ItemType Directory -Path $profilePath -Force | Out-Null

$arguments = @(
    '--kiosk',
    $Url,
    '--edge-kiosk-type=fullscreen',
    '--kiosk-printing',
    '--no-first-run',
    "--user-data-dir=$profilePath"
)

Start-Process -FilePath $edgePath -ArgumentList $arguments
