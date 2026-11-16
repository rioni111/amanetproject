# Lexora Workspace - start PostgreSQL, the Laravel API and the Next.js app on Windows.
# Services that are already running are reused, never started twice.
. (Join-Path $PSScriptRoot 'common.ps1')

$tc = Use-Toolchain
if ($tc.PgBin) { Start-Postgres $tc.PgBin } else { Write-Host 'PostgreSQL binaries not found - assuming a server is already running.' -ForegroundColor Yellow }

$pathPrefix = (Split-Path $tc.Php) + ';' + (Split-Path $tc.Node)
$api = "`$env:Path = '$pathPrefix;' + `$env:Path; Set-Location '$Backend'; Write-Host 'Lexora API on $ApiUrl' -ForegroundColor Cyan; php artisan serve --host=localhost --port=$ApiPort"
$web = "`$env:Path = '$pathPrefix;' + `$env:Path; Set-Location '$Frontend'; Write-Host 'Lexora web app on $WebUrl' -ForegroundColor Cyan; npm run dev -- --port $WebPort"

if (Test-PortListening $ApiPort) { Write-Host "Port $ApiPort is already in use - reusing the running Laravel API." -ForegroundColor Yellow }
else { Start-Process powershell -ArgumentList '-NoExit', '-NoProfile', '-Command', $api }

if (Test-PortListening $WebPort) { Write-Host "Port $WebPort is already in use - reusing the running Next.js app." -ForegroundColor Yellow }
else { Start-Process powershell -ArgumentList '-NoExit', '-NoProfile', '-Command', $web }

function Wait-Url([string]$Url, [int]$Seconds) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        try { $r = Invoke-WebRequest $Url -UseBasicParsing -TimeoutSec 5; if ($r.StatusCode -lt 500) { return $true } } catch { Start-Sleep -Seconds 2 }
    }
    return $false
}

Write-Host "Waiting for $ApiUrl/up ..."
$apiOk = Wait-Url "$ApiUrl/up" 60
Write-Host "Waiting for $WebUrl/login ..."
$webOk = Wait-Url "$WebUrl/login" 180

Write-Host ("PostgreSQL : " + $(if ($tc.PgBin -and (Test-PgReady $tc.PgBin)) { "running on localhost:$PgPort" } else { 'NOT reachable' }))
Write-Host ("Laravel API: " + $(if ($apiOk) { "$ApiUrl (healthy)" } else { 'NOT reachable' }))
Write-Host ("Next.js    : " + $(if ($webOk) { "$WebUrl (ready)" } else { 'NOT reachable' }))

if ($apiOk -and $webOk) {
    if (-not $env:LEXORA_NO_BROWSER) { Start-Process $WebUrl }
    Write-Host 'Lexora Workspace is running. Close the two server windows to stop it.' -ForegroundColor Green
    exit 0
}
Write-Host 'One or more services did not become ready - check the server windows.' -ForegroundColor Red
exit 1
