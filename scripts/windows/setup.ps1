# Lexora Workspace - one-time Windows/Laragon setup.
# Idempotent: safe to run again. Writes a full log to verification-logs\setup-*.log
#
# What it does (nothing outside these locations is changed):
#   1. PHP 8.2+ from C:\laragon\bin\php - enables pdo_pgsql/pgsql (+ Laravel extensions) in php.ini (backup kept)
#   2. Composer - Laragon's composer.phar (or downloads the official phar into .tools\)
#   3. Node.js 20.9+ - if missing, downloads the official Node 22 LTS zip into C:\laragon\bin\nodejs\ (SHA-256 verified)
#   4. PostgreSQL 16 - if missing, downloads the official EDB binaries zip into C:\laragon\bin\postgresql\
#      (Authenticode signatures checked), initialises C:\laragon\data\postgresql-16 (localhost only) with a
#      randomly generated superuser password kept in C:\laragon\data\postgresql-16-superuser.secret
#      (ACL: current user only) and starts it
#   5. Creates role amanet with a random password (written to backend\.env only) and databases amanet + amanet_test
#   No administrator rights are needed: everything is extracted into user-writable Laragon folders and
#   PostgreSQL runs as the current user on port 5432 (not as a Windows service).
#   6. backend: composer install, .env, key, migrate --seed
#   7. frontend: npm ci, .env.local, Playwright Chromium

param([switch]$SkipPlaywright)

. (Join-Path $PSScriptRoot 'common.ps1')
$log = Start-Log 'setup'

try {
    # ------------------------------------------------------------ 1. PHP
    Write-Section '1. PHP'
    $php = Find-Php
    if (-not $php) { throw 'No PHP 8.2+ found in C:\laragon\bin\php. Add PHP 8.2+ via Laragon (Menu > PHP > Version) and re-run.' }
    $phpDir = Split-Path $php
    Write-Log "Using PHP: $php ($((& $php -r 'echo PHP_VERSION;')))"

    $ini = Join-Path $phpDir 'php.ini'
    if (-not (Test-Path $ini)) {
        $template = Join-Path $phpDir 'php.ini-development'
        if (-not (Test-Path $template)) { throw "No php.ini or php.ini-development in $phpDir" }
        Copy-Item $template $ini
        Write-Log "Created php.ini from php.ini-development"
    }
    $backup = "$ini.lexora-backup"
    if (-not (Test-Path $backup)) { Copy-Item $ini $backup; Write-Log "Backed up php.ini to $backup" }

    $content = Get-Content $ini -Raw
    $original = $content
    $extDir = Join-Path $phpDir 'ext'
    if ($content -notmatch '(?m)^\s*extension_dir\s*=') {
        $content = $content + "`r`nextension_dir = `"$extDir`"`r`n"
        Write-Log "Set extension_dir = $extDir"
    }
    foreach ($ext in @('pdo_pgsql', 'pgsql', 'mbstring', 'openssl', 'fileinfo', 'curl', 'zip', 'intl')) {
        $enabled = [regex]::IsMatch($content, "(?m)^\s*extension\s*=\s*`"?(php_)?$ext(\.dll)?`"?\s*$")
        if ($enabled) { continue }
        if (-not (Test-Path (Join-Path $extDir "php_$ext.dll"))) { Write-Log "  (extension $ext not shipped with this PHP build - skipped)" 'Yellow'; continue }
        $pattern = "(?m)^\s*;\s*extension\s*=\s*`"?(php_)?$ext(\.dll)?`"?\s*$"
        if ([regex]::IsMatch($content, $pattern)) { $content = [regex]::Replace($content, $pattern, "extension=$ext") }
        else { $content = $content + "`r`nextension=$ext`r`n" }
        Write-Log "Enabled extension: $ext"
    }
    if ($content -ne $original) { Set-Content -Path $ini -Value $content -NoNewline -Encoding ASCII; Write-Log "php.ini updated: $ini" 'Green' }
    else { Write-Log 'php.ini already configured.' 'Green' }

    $modules = (& $php -m) -join ' '
    foreach ($needed in @('pdo_pgsql', 'pgsql', 'mbstring', 'openssl')) {
        if ($modules -notmatch "\b$needed\b") { throw "PHP extension '$needed' is still not loaded. Check $ini" }
    }
    Add-Result 'PHP 8.2+ with pdo_pgsql/pgsql' 'PASS' ((& $php -r 'echo PHP_VERSION;'))

    # ------------------------------------------------------------ 2. Composer
    Write-Section '2. Composer'
    # Laragon's bundled composer.phar is often old (e.g. 2.4 from 2022). It is left untouched;
    # if it is older than 2.7, the official latest stable phar is downloaded into .tools\ instead.
    $toolsComposer = Join-Path $Root '.tools\composer.phar'
    $laragonComposer = Join-Path $LaragonRoot 'bin\composer\composer.phar'
    $composerPhar = $null
    foreach ($candidate in @($toolsComposer, $laragonComposer)) {
        if (-not (Test-Path $candidate)) { continue }
        # Composer prints a "PHP version" notice on stderr; PowerShell 5.1 must not treat that as an error.
        $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        $cvText = (& $php $candidate --version --no-ansi 2>$null) -join ' '
        $ErrorActionPreference = $previous
        $cv = Get-VersionFromString ([regex]::Match($cvText, 'Composer version \S+').Value)
        if ($cv -and $cv -ge [version]'2.7.0') { $composerPhar = $candidate; break }
        Write-Log "Composer at $candidate is $cv (< 2.7) - not used."
    }
    if (-not $composerPhar) {
        $toolsDir = Join-Path $Root '.tools'; New-Item -ItemType Directory -Force -Path $toolsDir | Out-Null
        $download = Join-Path $toolsDir 'composer.phar.download'
        Write-Log 'Downloading the official composer.phar (latest stable) from getcomposer.org...'
        Invoke-WebRequest 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile $download -UseBasicParsing
        $shaBody = (Invoke-WebRequest 'https://getcomposer.org/download/latest-stable/composer.phar.sha256' -UseBasicParsing).Content
        if ($shaBody -is [byte[]]) { $shaBody = [Text.Encoding]::ASCII.GetString($shaBody) }
        $expected = "$shaBody".Trim().Split(' ')[0]
        $actual = (Get-FileHash $download -Algorithm SHA256).Hash.ToLower()
        if ($expected.ToLower() -ne $actual) { Remove-Item $download; throw 'composer.phar checksum mismatch' }
        Write-Log "composer.phar SHA-256 verified ($actual)" 'Green'
        Move-Item $download $toolsComposer -Force
        $composerPhar = $toolsComposer
    }
    Write-Log "Using Composer: $composerPhar ($((& $php $composerPhar --version --no-ansi) -join ' '))"
    Add-Result 'Composer' 'PASS'

    # ------------------------------------------------------------ 3. Node.js
    Write-Section '3. Node.js'
    $node = Find-Node
    if (-not $node) {
        Write-Log 'Node.js 20.9+ not found (Laragon ships v18). Downloading the official Node.js 22 LTS...'
        $index = Invoke-RestMethod 'https://nodejs.org/dist/index.json' -UseBasicParsing
        $release = $index | Where-Object { $_.version -like 'v22.*' -and $_.lts } | Select-Object -First 1
        if (-not $release) { throw 'Could not resolve the latest Node.js 22 LTS release.' }
        $ver = $release.version
        $zipName = "node-$ver-win-x64.zip"
        $nodeRoot = Join-Path $LaragonRoot 'bin\nodejs'
        New-Item -ItemType Directory -Force -Path $nodeRoot | Out-Null
        $zip = Join-Path $env:TEMP $zipName
        Invoke-WebRequest "https://nodejs.org/dist/$ver/$zipName" -OutFile $zip -UseBasicParsing
        $sums = (Invoke-WebRequest "https://nodejs.org/dist/$ver/SHASUMS256.txt" -UseBasicParsing).Content
        if ($sums -is [byte[]]) { $sums = [Text.Encoding]::ASCII.GetString($sums) }
        $expected = ([regex]::Match($sums, "([a-f0-9]{64})\s+$([regex]::Escape($zipName))")).Groups[1].Value
        $actual = (Get-FileHash $zip -Algorithm SHA256).Hash.ToLower()
        if (-not $expected -or $expected -ne $actual) { throw "Node.js download checksum mismatch ($zipName)" }
        Write-Log "Checksum verified ($actual)"
        Expand-Archive -Path $zip -DestinationPath $nodeRoot -Force
        Remove-Item $zip
        $node = Find-Node
        if (-not $node) { throw 'Node.js was extracted but node.exe was not found.' }
        Write-Log "Installed $ver to $(Split-Path $node). Tip: select it in Laragon (Menu > Node.js) to use it in Laragon's terminal." 'Green'
    }
    $env:Path = (Split-Path $node) + ';' + $env:Path
    Write-Log "Using Node: $node ($((& $node -v))) / npm $((& (Join-Path (Split-Path $node) 'npm.cmd') -v))"
    Add-Result 'Node.js 20.9+' 'PASS' ((& $node -v))

    # ------------------------------------------------------------ 4. PostgreSQL
    Write-Section '4. PostgreSQL 16'
    $pgBin = Find-PgBin
    if (-not $pgBin) {
        Write-Log 'PostgreSQL not found. Downloading the official EDB PostgreSQL 16 binaries (zip)...'
        $pgRoot = Join-Path $LaragonRoot 'bin\postgresql'
        New-Item -ItemType Directory -Force -Path $pgRoot | Out-Null
        $zip = $null; $minor = $null
        foreach ($m in 20..4) {
            $url = "https://get.enterprisedb.com/postgresql/postgresql-16.$m-1-windows-x64-binaries.zip"
            try {
                $head = Invoke-WebRequest $url -Method Head -UseBasicParsing
                if ($head.StatusCode -eq 200) { $minor = $m; break }
            } catch { }
        }
        if ($null -eq $minor) { throw 'Could not find a PostgreSQL 16 binaries zip on get.enterprisedb.com. Install PostgreSQL 16 manually (or Docker) and re-run.' }
        $zip = Join-Path $env:TEMP "postgresql-16.$minor-binaries.zip"
        Write-Log "Downloading PostgreSQL 16.$minor (approx. 300 MB)..."
        Invoke-WebRequest "https://get.enterprisedb.com/postgresql/postgresql-16.$minor-1-windows-x64-binaries.zip" -OutFile $zip -UseBasicParsing
        $staging = Join-Path $pgRoot "_extract-16.$minor"
        Expand-Archive -Path $zip -DestinationPath $staging -Force
        $target = Join-Path $pgRoot "postgresql-16.$minor"
        if (Test-Path $target) { throw "$target already exists but contains no pg_ctl.exe" }
        Move-Item (Join-Path $staging 'pgsql') $target
        Remove-Item $staging -Recurse -Force
        Remove-Item $zip
        # EDB publishes no checksum for the zip; verify the Authenticode signatures of the key binaries instead.
        foreach ($exe in @('postgres.exe', 'pg_ctl.exe', 'initdb.exe', 'psql.exe')) {
            $sig = Get-AuthenticodeSignature (Join-Path $target "bin\$exe")
            $signer = if ($sig.SignerCertificate) { $sig.SignerCertificate.Subject } else { '(none)' }
            if ($sig.Status -eq 'Valid') { Write-Log "  $exe signature valid: $signer" 'Green' }
            elseif ($sig.Status -eq 'NotSigned') { Write-Log "  $exe is not Authenticode-signed (downloaded over HTTPS from get.enterprisedb.com)" 'Yellow' }
            else { Remove-Item $target -Recurse -Force; throw "$exe signature check failed: $($sig.Status) - install removed" }
        }
        $pgBin = Find-PgBin
        if (-not $pgBin) { throw 'PostgreSQL was extracted but pg_ctl.exe was not found.' }
        Write-Log "Installed PostgreSQL 16.$minor to $target" 'Green'
    }
    $env:Path = $pgBin + ';' + $env:Path
    Write-Log "Using PostgreSQL binaries: $pgBin ($((& (Join-Path $pgBin 'postgres.exe') --version)))"

    # Superuser password: random, generated once, stored only in a user-restricted file next to the
    # data directory (never in the project, logs or documentation).
    $superSecretFile = "$PgDataDir-superuser.secret"
    # This project always uses its OWN PostgreSQL instance (data dir above). Another server already
    # listening on 5432 (e.g. Docker or another project) is never touched: a free port is chosen instead.
    if (-not (Test-OwnPgRunning $pgBin)) {
        if (-not (Test-Path (Join-Path $PgDataDir 'PG_VERSION'))) {
            if (-not $env:LEXORA_PG_PORT -and (Test-PortListening $PgPort)) {
                $busy = $PgPort
                $PgPort = Find-FreePort 5433 5499
                Write-Log "Port $busy is already used by another program (left untouched) - this project's PostgreSQL will use port $PgPort." 'Yellow'
            }
            Write-Log "Initialising data directory $PgDataDir (superuser 'postgres' with a generated password, localhost only)"
            New-Item -ItemType Directory -Force -Path (Split-Path $PgDataDir) | Out-Null
            if (-not (Test-Path $superSecretFile)) {
                [IO.File]::WriteAllText($superSecretFile, (New-SecureSecret 32), (New-Object System.Text.ASCIIEncoding))
                Protect-LocalSecretFile $superSecretFile
            }
            Invoke-Native 'initdb' $Root { & (Join-Path $pgBin 'initdb.exe') -D $PgDataDir -U postgres "--pwfile=$superSecretFile" -A scram-sha-256 -E UTF8 --locale=C } | Out-Null
            Write-Log "Superuser password stored in $superSecretFile (readable by $env:USERNAME only)."
        }
    }
    if ($null -eq (Get-PgConfPort)) {
        # Data directory from before the port was recorded: take the port it is running on, if any.
        $pidFile = Join-Path $PgDataDir 'postmaster.pid'
        if ((Test-OwnPgRunning $pgBin) -and (Test-Path $pidFile)) { $PgPort = [int]((Get-Content $pidFile)[3]) }
    }
    Set-PgConfPort $PgPort
    Start-Postgres $pgBin
    Add-Result "PostgreSQL 16 running on localhost:$PgPort" 'PASS'

    # Superuser connection for role/database management: PGUSER/PGPASSWORD from the environment
    # (existing server), else the generated secret file. A server still using the old insecure
    # default (postgres/postgres) from an earlier version of this script is rotated to a random password.
    $psql = Join-Path $pgBin 'psql.exe'
    $hadPgUser = [bool]$env:PGUSER; $hadPgPassword = [bool]$env:PGPASSWORD
    if (-not $env:PGUSER) { $env:PGUSER = 'postgres' }
    if (-not $env:PGPASSWORD) {
        if (Test-Path $superSecretFile) { $env:PGPASSWORD = (Get-Content $superSecretFile -Raw).Trim() }
        else {
            $env:PGPASSWORD = 'postgres'
            $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
            & $psql -h localhost -p $PgPort -d postgres -X -tAc 'SELECT 1' 2>&1 | Out-Null
            $legacyOk = ($LASTEXITCODE -eq 0); $ErrorActionPreference = $previous
            if (-not $legacyOk) { throw "Cannot connect as superuser to the PostgreSQL server on localhost:$PgPort. Set PGUSER/PGPASSWORD for a superuser and re-run." }
            $newSuper = New-SecureSecret 32
            Invoke-PsqlSecret $psql "ALTER ROLE postgres PASSWORD '$newSuper';"
            [IO.File]::WriteAllText($superSecretFile, $newSuper, (New-Object System.Text.ASCIIEncoding))
            Protect-LocalSecretFile $superSecretFile
            $env:PGPASSWORD = $newSuper
            Write-Log "Rotated the insecure default superuser password; new one stored in $superSecretFile." 'Green'
        }
    }

    # Application role: password generated once and kept in backend\.env (git-ignored). The
    # insecure template value 'amanet' is replaced. The role is (re)synchronised on every run.
    $envFile = Join-Path $Backend '.env'
    if (-not (Test-Path $envFile)) {
        Copy-Item (Join-Path $Backend '.env.example') $envFile
        Write-Log 'Created backend\.env from .env.example'
    }
    $appDbPassword = Get-DotEnvValue $envFile 'DB_PASSWORD'
    if (-not $appDbPassword -or $appDbPassword -eq 'amanet' -or $appDbPassword -notmatch '^[A-Za-z0-9]{24,}$') {
        $appDbPassword = New-SecureSecret 32
        Set-DotEnvValue $envFile 'DB_PASSWORD' $appDbPassword
        Write-Log 'Generated a new random password for database role amanet (stored in backend\.env only).' 'Green'
    }
    Set-DotEnvValue $envFile 'DB_HOST' '127.0.0.1'
    Set-DotEnvValue $envFile 'DB_PORT' "$PgPort"
    Set-DotEnvValue $envFile 'DB_DATABASE' 'amanet'
    Set-DotEnvValue $envFile 'DB_USERNAME' 'amanet'
    $roleSql = @"
DO `$`$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'amanet') THEN
    CREATE ROLE amanet LOGIN CREATEDB;
  END IF;
END `$`$;
ALTER ROLE amanet WITH LOGIN CREATEDB NOSUPERUSER PASSWORD '$appDbPassword';
"@
    Invoke-PsqlSecret $psql $roleSql
    Add-Result 'Role amanet (random password, synced with backend\.env)' 'PASS'
    # amanet: development data (never reset) · amanet_test: PHPUnit · amanet_e2e: Playwright (reset on every run)
    foreach ($db in @('amanet', 'amanet_test', 'amanet_e2e')) {
        $exists = (& $psql -h localhost -p $PgPort -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'") 2>$null
        if ("$exists".Trim() -ne '1') {
            Invoke-Native "Create database $db" $Root { & $psql -h localhost -p $PgPort -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE $db OWNER amanet ENCODING 'UTF8'" } | Out-Null
        } else { Write-Log "Database $db already exists." }
    }
    if (-not $hadPgPassword) { Remove-Item Env:\PGPASSWORD }
    if (-not $hadPgUser) { Remove-Item Env:\PGUSER }
    Add-Result 'PostgreSQL 16 running with amanet, amanet_test, amanet_e2e' 'PASS'

    # ------------------------------------------------------------ 5. Backend
    Invoke-Native 'composer install' $Backend { & $php $composerPhar install --no-interaction --prefer-dist --no-ansi --no-progress } | Out-Null
    if (-not (Test-Path (Join-Path $Backend '.env'))) {
        Copy-Item (Join-Path $Backend '.env.example') (Join-Path $Backend '.env')
        Write-Log 'Created backend\.env from .env.example'
    }
    # Application ports. Chosen once and recorded in backend\.env (LEXORA_API_PORT / LEXORA_WEB_PORT);
    # later runs keep them. 8000/3000 are used when free; if another program owns them (e.g. a Docker
    # stack or another project), the first free port from 8010 / 3010 is used instead - nothing is stopped.
    $envFile = Join-Path $Backend '.env'
    if (-not $env:LEXORA_API_PORT -and -not (Get-DotEnvValue $envFile 'LEXORA_API_PORT')) {
        if (Test-PortListening $ApiPort) { Write-Log "Port $ApiPort is used by another program (left untouched)." 'Yellow'; $ApiPort = Find-FreePort 8010 8099 }
    }
    if (-not $env:LEXORA_WEB_PORT -and -not (Get-DotEnvValue $envFile 'LEXORA_WEB_PORT')) {
        if (Test-PortListening $WebPort) { Write-Log "Port $WebPort is used by another program (left untouched)." 'Yellow'; $WebPort = Find-FreePort 3010 3099 }
    }
    $ApiUrl = "http://localhost:$ApiPort"; $WebUrl = "http://localhost:$WebPort"
    Set-DotEnvValue $envFile 'LEXORA_API_PORT' "$ApiPort"
    Set-DotEnvValue $envFile 'LEXORA_WEB_PORT' "$WebPort"
    Set-DotEnvValue $envFile 'APP_URL' $ApiUrl
    Set-DotEnvValue $envFile 'FRONTEND_URL' $WebUrl
    Set-DotEnvValue $envFile 'SANCTUM_STATEFUL_DOMAINS' "localhost:$WebPort,127.0.0.1:$WebPort"
    # Browsers share cookies between ports of the same host. A port-specific session cookie name keeps
    # this app's session separate from other local apps (e.g. another copy of Lexora on :3000).
    $sessionCookie = if ($WebPort -eq 3000) { 'lexora_session' } else { "lexora_session_$WebPort" }
    Set-DotEnvValue $envFile 'SESSION_COOKIE' $sessionCookie
    Write-Log "API: $ApiUrl   Web app: $WebUrl (recorded in backend\.env)" 'Green'
    Add-Result "Application ports API $ApiPort / web $WebPort" 'PASS'

    if ((Get-Content (Join-Path $Backend '.env') -Raw) -match '(?m)^APP_KEY=\s*$') {
        Invoke-Native 'php artisan key:generate' $Backend { & $php artisan key:generate --ansi } | Out-Null
    }
    Invoke-Native 'php artisan migrate --seed' $Backend { & $php artisan migrate --seed --force --no-interaction } | Out-Null

    # ------------------------------------------------------------ 6. Frontend
    $npm = Join-Path (Split-Path $node) 'npm.cmd'
    $npx = Join-Path (Split-Path $node) 'npx.cmd'
    Invoke-Native 'npm ci' $Frontend { & $npm ci --no-audit --no-fund } | Out-Null
    if (-not (Test-Path (Join-Path $Frontend '.env.local'))) {
        Copy-Item (Join-Path $Frontend '.env.example') (Join-Path $Frontend '.env.local')
        Write-Log 'Created frontend\.env.local from .env.example'
    }
    Set-DotEnvValue (Join-Path $Frontend '.env.local') 'NEXT_PUBLIC_API_URL' $ApiUrl
    Set-DotEnvValue (Join-Path $Frontend '.env.local') 'NEXT_PUBLIC_SESSION_COOKIE' (Get-DotEnvValue $envFile 'SESSION_COOKIE')
    if (-not $SkipPlaywright) {
        Invoke-Native 'Playwright Chromium' $Frontend { & $npx playwright install chromium } | Out-Null
    }
} catch {
    Add-Result 'Setup aborted' 'FAIL' $_.Exception.Message
    Write-Log $_.ScriptStackTrace 'DarkGray'
}

$failed = Write-Summary 'setup'
if ($failed -eq 0) {
    Write-Log ''
    Write-Log 'Setup complete. Next: double-click verify-windows.bat (tests) or start-windows.bat (run the app).' 'Green'
}
if ($script:Writer) { $script:Writer.Close() }
exit $failed
