# Lexora Workspace - Phase 1 verification on Windows/Laragon.
# Runs every check from the Phase 1 report against the local PostgreSQL database and writes
# verification-logs\verify-*.log plus verification-logs\verify-summary.txt.

param([switch]$SkipE2E)

. (Join-Path $PSScriptRoot 'common.ps1')
$log = Start-Log 'verify'

try {
    Write-Section 'Toolchain'
    $tc = Use-Toolchain
    if (-not $tc.PgBin) { throw 'PostgreSQL binaries not found. Run setup-windows.bat first.' }
    Start-Postgres $tc.PgBin
    $php = $tc.Php
    $npm = Join-Path (Split-Path $tc.Node) 'npm.cmd'
    $npx = Join-Path (Split-Path $tc.Node) 'npx.cmd'
    # The production build uses the development API URL (same as frontend\.env.local).
    $env:NEXT_PUBLIC_API_URL = $ApiUrl
    # Playwright runs its OWN servers on dedicated ports against the disposable amanet_e2e database
    # (frontend\e2e\env.ts) - the development servers and data are never used or modified.
    if (-not $env:E2E_API_PORT) { $env:E2E_API_PORT = "$(Find-FreePort 8011 8099)" }
    if (-not $env:E2E_WEB_PORT) { $env:E2E_WEB_PORT = "$(Find-FreePort 3011 3099)" }
    Remove-Item Env:\E2E_BASE_URL -ErrorAction SilentlyContinue
    Write-Log "API: $ApiUrl   Web app: $WebUrl   PostgreSQL: localhost:$PgPort"
    Write-Log "E2E: API http://localhost:$($env:E2E_API_PORT)   web http://localhost:$($env:E2E_WEB_PORT)   database amanet_e2e (reset each run)"

    $versions = @(
        "OS:         " + (Get-CimInstance Win32_OperatingSystem).Caption + " " + (Get-CimInstance Win32_OperatingSystem).Version,
        "PHP:        " + ((& $php -r 'echo PHP_VERSION;')) + "  ($php)",
        "Composer:   " + ((& $php $tc.ComposerPhar --version --no-ansi) -join ' '),
        "Node.js:    " + (& $tc.Node -v) + "  ($($tc.Node))",
        "npm:        " + (& $npm -v),
        "PostgreSQL: " + (& (Join-Path $tc.PgBin 'postgres.exe') --version) + "  ($($tc.PgBin))"
    )
    foreach ($v in $versions) { Write-Log $v }
    Set-Content -Path (Join-Path $LogDir 'versions.txt') -Value $versions -Encoding UTF8
    $pdo = ((& $php -m) -join ' ') -match '\bpdo_pgsql\b'
    Add-Result 'PHP pdo_pgsql loaded' $(if ($pdo) { 'PASS' } else { 'FAIL' })

    # ---------------------------------------------------------------- e-mail test inbox (Phase 6)
    # PHPUnit's SMTP integration tests and the Playwright suite deliver real messages to this project's
    # ISOLATED E2E Mailpit (docker compose service mailpit-e2e, 127.0.0.1 only). Only that one service is
    # started if needed; no other container is touched and nothing is downloaded (--pull never).
    $mailUi = "http://127.0.0.1:$(if ($env:LEXORA_E2E_MAIL_UI_PORT) { $env:LEXORA_E2E_MAIL_UI_PORT } else { 8027 })/api/v1/info"
    $mailOk = $false
    try { Invoke-WebRequest -UseBasicParsing -Uri $mailUi -TimeoutSec 5 | Out-Null; $mailOk = $true } catch { }
    if (-not $mailOk -and (Get-Command docker -ErrorAction SilentlyContinue)) {
        Write-Log 'Starting the E2E Mailpit (docker compose up -d --pull never mailpit-e2e)...'
        Push-Location $Root
        try { & docker compose up -d --pull never mailpit-e2e 2>&1 | ForEach-Object { Write-Log "  $_" 'DarkGray' } } finally { Pop-Location }
        for ($i = 0; $i -lt 20 -and -not $mailOk; $i++) {
            Start-Sleep -Seconds 1
            try { Invoke-WebRequest -UseBasicParsing -Uri $mailUi -TimeoutSec 3 | Out-Null; $mailOk = $true } catch { }
        }
    }
    Add-Result 'E2E Mailpit (isolated SMTP inbox)' $(if ($mailOk) { 'PASS' } else { 'FAIL' }) $(if ($mailOk) { '' } else { 'Start Docker Desktop, then: docker compose up -d --pull never mailpit-e2e' })

    # ---------------------------------------------------------------- backend
    Invoke-Native 'composer validate' $Backend { & $php $tc.ComposerPhar validate --no-check-publish --no-ansi } -AllowFailure | Out-Null
    # Phase 7: known security advisories (needs internet access to packagist.org / registry.npmjs.org).
    Invoke-Native 'composer audit (PHP dependency advisories)' $Backend { & $php $tc.ComposerPhar audit --no-ansi } -AllowFailure | Out-Null
    Invoke-Native 'php artisan migrate (status)' $Backend { & $php artisan migrate --force --no-interaction } -AllowFailure | Out-Null
    Invoke-Native 'php artisan db:seed (idempotent)' $Backend { & $php artisan db:seed --force --no-interaction } -AllowFailure | Out-Null
    Invoke-Native 'php artisan route:list' $Backend { & $php artisan route:list --except-vendor } -AllowFailure | Out-Null
    Invoke-Native 'PHPUnit (php artisan test)' $Backend { & $php artisan test } -AllowFailure | Out-Null
    Invoke-Native 'Pint (code style)' $Backend { & $php vendor\laravel\pint\builds\pint --test } -AllowFailure | Out-Null

    # ---------------------------------------------------------------- frontend
    # Runtime (production) dependencies only: the remaining advisories are in ESLint's dev tooling (docs/SECURITY_AUDIT.md).
    Invoke-Native 'npm audit --omit=dev (runtime dependency advisories)' $Frontend { & $npm audit --omit=dev } -AllowFailure | Out-Null
    Invoke-Native 'ESLint (npm run lint)' $Frontend { & $npm run lint } -AllowFailure | Out-Null
    Invoke-Native 'TypeScript (npm run typecheck)' $Frontend { & $npm run typecheck } -AllowFailure | Out-Null
    Invoke-Native 'Production build (npm run build)' $Frontend { & $npm run build } -AllowFailure | Out-Null

    # ---------------------------------------------------------------- end-to-end
    if (-not $SkipE2E) {
        # Playwright starts dedicated E2E servers (see above) and resets amanet_e2e in global-setup.
        $env:CI = ''
        Invoke-Native 'Playwright E2E (Phase 1-7 workflows, desktop + mobile)' $Frontend { & $npx playwright test --reporter=list } -AllowFailure | Out-Null
    }
} catch {
    Add-Result 'Verification aborted' 'FAIL' $_.Exception.Message
    Write-Log $_.ScriptStackTrace 'DarkGray'
}

$failed = Write-Summary 'verify'
if ($script:Writer) { $script:Writer.Close() }
exit $failed
