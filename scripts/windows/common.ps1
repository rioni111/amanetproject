# Shared helpers for the Windows (Laragon) setup, verification and start scripts.
# PowerShell 5.1 compatible. ASCII only.

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Root        = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Backend     = Join-Path $Root 'backend'
$Frontend    = Join-Path $Root 'frontend'
$LogDir      = Join-Path $Root 'verification-logs'
$LaragonRoot = if ($env:LARAGON_ROOT) { $env:LARAGON_ROOT } else { 'C:\laragon' }
$PgDataDir   = Join-Path $LaragonRoot 'data\postgresql-16'
# Port of THIS project's PostgreSQL: LEXORA_PG_PORT, else DB_PORT from backend\.env, else 5432.
# (setup.ps1 picks a free port when 5432 is taken by another program.) Resolved at the end of this file.
$PgPort      = 5432
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

$script:Writer = $null
$script:Results = New-Object System.Collections.ArrayList

function Start-Log([string]$Name) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $path = Join-Path $LogDir "$Name-$stamp.log"
    $script:Writer = New-Object System.IO.StreamWriter($path, $false, (New-Object System.Text.UTF8Encoding($false)))
    $script:Writer.AutoFlush = $true
    # Stable name so progress can be followed: <name>-latest.log
    Set-Content -Path (Join-Path $LogDir "$Name-latest.txt") -Value $path -Encoding ASCII
    Write-Log "Log file: $path"
    Write-Log ("Started: " + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + "  Host: $env:COMPUTERNAME  PowerShell: " + $PSVersionTable.PSVersion)
    return $path
}

function Write-Log([string]$Message, [string]$Color = 'Gray') {
    Write-Host $Message -ForegroundColor $Color
    if ($script:Writer) { $script:Writer.WriteLine($Message) }
}

function Write-Section([string]$Title) {
    Write-Log ''
    Write-Log ('=' * 78) 'Cyan'
    Write-Log "== $Title" 'Cyan'
    Write-Log ('=' * 78) 'Cyan'
}

# Runs a native command, streams its output to console + log, records PASS/FAIL.
function Invoke-Native([string]$Title, [string]$WorkDir, [scriptblock]$Command, [switch]$AllowFailure) {
    Write-Section $Title
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    Push-Location $WorkDir
    $started = Get-Date
    try {
        & $Command 2>&1 | ForEach-Object { Write-Log ("  " + "$_") }
        $code = $LASTEXITCODE
    } finally {
        Pop-Location
        $ErrorActionPreference = $previous
    }
    if ($null -eq $code) { $code = 0 }
    $seconds = [int]((Get-Date) - $started).TotalSeconds
    $status = if ($code -eq 0) { 'PASS' } else { 'FAIL' }
    [void]$script:Results.Add([pscustomobject]@{ Step = $Title; Status = $status; ExitCode = $code; Seconds = $seconds })
    if ($code -eq 0) { Write-Log "-> $Title : PASS ($seconds s)" 'Green' }
    else {
        Write-Log "-> $Title : FAIL (exit code $code)" 'Red'
        if (-not $AllowFailure) { throw "$Title failed with exit code $code" }
    }
    return $code
}

function Add-Result([string]$Step, [string]$Status, [string]$Detail = '') {
    [void]$script:Results.Add([pscustomobject]@{ Step = $Step; Status = $Status; ExitCode = ''; Seconds = $Detail })
    $color = if ($Status -eq 'PASS' -or $Status -eq 'OK') { 'Green' } elseif ($Status -eq 'FAIL') { 'Red' } else { 'Yellow' }
    Write-Log "-> $Step : $Status $Detail" $color
}

function Write-Summary([string]$Name) {
    Write-Section 'SUMMARY'
    $lines = @()
    foreach ($r in $script:Results) { $lines += ('{0,-6} {1}  {2}' -f $r.Status, $r.Step, $r.Seconds) }
    foreach ($l in $lines) { Write-Log $l }
    $failed = @($script:Results | Where-Object { $_.Status -eq 'FAIL' }).Count
    $verdict = if ($failed -eq 0) { 'ALL STEPS PASSED' } else { "$failed STEP(S) FAILED" }
    Write-Log $verdict $(if ($failed -eq 0) { 'Green' } else { 'Red' })
    $summary = @("$Name summary - " + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')) + $lines + @($verdict)
    Set-Content -Path (Join-Path $LogDir "$Name-summary.txt") -Value $summary -Encoding UTF8
    return $failed
}

# ---------------------------------------------------------------- secrets / .env
# Cryptographically random alphanumeric secret (no quoting issues in .env, SQL or URLs).
function New-SecureSecret([int]$Length = 32) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $rng = New-Object System.Security.Cryptography.RNGCryptoServiceProvider
    $bytes = New-Object byte[] 1
    $chars = New-Object System.Collections.Generic.List[char]
    while ($chars.Count -lt $Length) {
        $rng.GetBytes($bytes)
        # Rejection sampling avoids modulo bias.
        if ($bytes[0] -lt (256 - (256 % $alphabet.Length))) { $chars.Add($alphabet[$bytes[0] % $alphabet.Length]) }
    }
    $rng.Dispose()
    return (-join $chars)
}

function Get-DotEnvValue([string]$Path, [string]$Key) {
    if (-not (Test-Path $Path)) { return $null }
    $m = [regex]::Match((Get-Content $Path -Raw), "(?m)^\s*$([regex]::Escape($Key))\s*=\s*(.*?)\s*$")
    if (-not $m.Success) { return $null }
    return $m.Groups[1].Value.Trim('"')
}

function Set-DotEnvValue([string]$Path, [string]$Key, [string]$Value) {
    $content = Get-Content $Path -Raw
    $pattern = "(?m)^\s*$([regex]::Escape($Key))\s*=.*$"
    if ([regex]::IsMatch($content, $pattern)) {
        $content = [regex]::Replace($content, $pattern, { param($m) "$Key=$Value" })
    } else {
        $content = $content.TrimEnd("`r", "`n") + "`r`n$Key=$Value`r`n"
    }
    [IO.File]::WriteAllText($Path, $content, (New-Object System.Text.UTF8Encoding($false)))
}

# Restricts a file to the current user (and SYSTEM/Administrators) - used for local secret files.
function Protect-LocalSecretFile([string]$Path) {
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & icacls.exe $Path /inheritance:r /grant:r "$($env:USERDOMAIN)\$($env:USERNAME):(F)" '*S-1-5-18:(F)' '*S-1-5-32-544:(F)' 2>&1 | Out-Null
    $ErrorActionPreference = $previous
}

# Runs psql with SQL on stdin so secrets never appear on a command line or in the log.
function Invoke-PsqlSecret([string]$Psql, [string]$Sql) {
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $out = $Sql | & $Psql -h localhost -p $PgPort -d postgres -v ON_ERROR_STOP=1 -q -X -f - 2>&1
    $code = $LASTEXITCODE
    $ErrorActionPreference = $previous
    if ($code -ne 0) {
        # Do not echo psql output verbatim: an error context line could contain the statement.
        $first = @($out | ForEach-Object { "$_" } | Where-Object { $_ -match 'ERROR|FATAL|could not' } | Select-Object -First 1)
        $safe = if ($first.Count -gt 0) { ($first[0] -replace "PASSWORD\s+'[^']*'", "PASSWORD '***'") } else { '' }
        throw "psql failed (exit $code): $safe"
    }
}

function Test-PortListening([int]$Port) {
    $c = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
    return ($null -ne $c)
}

function Get-VersionFromString([string]$Text) {
    $m = [regex]::Match([string]$Text, '(\d+)\.(\d+)(?:\.(\d+))?')
    if ($m.Success) {
        $patch = if ($m.Groups[3].Success) { $m.Groups[3].Value } else { '0' }
        return [version]("{0}.{1}.{2}" -f $m.Groups[1].Value, $m.Groups[2].Value, $patch)
    }
    return $null
}

# ---------------------------------------------------------------- PHP
function Find-Php {
    $candidates = @()
    $laragonPhp = Join-Path $LaragonRoot 'bin\php'
    if (Test-Path $laragonPhp) {
        $candidates += Get-ChildItem $laragonPhp -Directory | Where-Object { Test-Path (Join-Path $_.FullName 'php.exe') } | ForEach-Object { Join-Path $_.FullName 'php.exe' }
    }
    $onPath = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($onPath) { $candidates += $onPath.Source }
    $best = $null; $bestVersion = [version]'0.0.0'
    foreach ($exe in ($candidates | Select-Object -Unique)) {
        $v = Get-VersionFromString ((& $exe -r 'echo PHP_VERSION;') 2>$null)
        if ($v -and $v -ge [version]'8.2.0' -and $v -gt $bestVersion) { $best = $exe; $bestVersion = $v }
    }
    return $best
}

# ---------------------------------------------------------------- Node
function Find-Node {
    $candidates = @()
    $onPath = Get-Command node.exe -ErrorAction SilentlyContinue
    if ($onPath) { $candidates += $onPath.Source }
    $laragonNode = Join-Path $LaragonRoot 'bin\nodejs'
    if (Test-Path $laragonNode) {
        $candidates += Get-ChildItem $laragonNode -Recurse -Depth 2 -Filter node.exe -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName }
    }
    $best = $null; $bestVersion = [version]'0.0.0'
    foreach ($exe in ($candidates | Select-Object -Unique)) {
        $v = Get-VersionFromString ((& $exe -v) 2>$null)
        if ($v -and $v -ge [version]'20.9.0' -and $v -gt $bestVersion) { $best = $exe; $bestVersion = $v }
    }
    return $best
}

# ---------------------------------------------------------------- PostgreSQL
function Find-PgBin {
    $candidates = @()
    $onPath = Get-Command pg_ctl.exe -ErrorAction SilentlyContinue
    if ($onPath) { $candidates += (Split-Path $onPath.Source) }
    $laragonPg = Join-Path $LaragonRoot 'bin\postgresql'
    if (Test-Path $laragonPg) {
        $candidates += Get-ChildItem $laragonPg -Recurse -Depth 3 -Filter pg_ctl.exe -ErrorAction SilentlyContinue | ForEach-Object { Split-Path $_.FullName }
    }
    foreach ($pf in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
        if ($pf -and (Test-Path (Join-Path $pf 'PostgreSQL'))) {
            $candidates += Get-ChildItem (Join-Path $pf 'PostgreSQL') -Recurse -Depth 2 -Filter pg_ctl.exe -ErrorAction SilentlyContinue | ForEach-Object { Split-Path $_.FullName }
        }
    }
    foreach ($bin in ($candidates | Select-Object -Unique)) {
        $v = Get-VersionFromString ((& (Join-Path $bin 'pg_ctl.exe') --version) 2>$null)
        if ($v -and $v.Major -ge 14) { return $bin }
    }
    return $null
}

function Test-PgReady([string]$PgBin) {
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & (Join-Path $PgBin 'pg_isready.exe') -h localhost -p $PgPort 2>&1 | Out-Null
    $ok = ($LASTEXITCODE -eq 0); $ErrorActionPreference = $previous
    return $ok
}

# True when the postmaster of THIS project's data directory is running (not just "something on the port").
function Test-OwnPgRunning([string]$PgBin) {
    if (-not (Test-Path (Join-Path $PgDataDir 'PG_VERSION'))) { return $false }
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & (Join-Path $PgBin 'pg_ctl.exe') status -D $PgDataDir 2>&1 | Out-Null
    $running = ($LASTEXITCODE -eq 0); $ErrorActionPreference = $previous
    return $running
}

function Find-FreePort([int]$From, [int]$To) {
    for ($p = $From; $p -le $To; $p++) { if (-not (Test-PortListening $p)) { return $p } }
    throw "No free TCP port between $From and $To"
}

function Start-Postgres([string]$PgBin) {
    if (Test-OwnPgRunning $PgBin) {
        $pidFile = Join-Path $PgDataDir 'postmaster.pid'
        $runningPort = if (Test-Path $pidFile) { [int]((Get-Content $pidFile)[3]) } else { $PgPort }
        if ($runningPort -ne $PgPort) { throw "This project's PostgreSQL is running on port $runningPort but DB_PORT is $PgPort. Stop it (pg_ctl stop -D `"$PgDataDir`") or fix DB_PORT." }
        Write-Log "PostgreSQL ($PgDataDir) already running on localhost:$PgPort" 'Green'; return
    }
    if (-not (Test-Path (Join-Path $PgDataDir 'PG_VERSION'))) { throw "PostgreSQL data directory not initialised: $PgDataDir (run setup-windows.bat)" }
    if (Test-PortListening $PgPort) { throw "Port $PgPort is in use by another program (not this project's PostgreSQL). Free it or set LEXORA_PG_PORT and DB_PORT to another port." }
    $pgLog = Join-Path $PgDataDir 'server.log'
    Write-Log "Starting PostgreSQL ($PgDataDir) on localhost:$PgPort..."
    # pg_ctl's output goes to a file via cmd (not a PowerShell pipe): the postmaster would otherwise
    # inherit the pipe handle and the pipeline would never finish.
    $ctlOut = Join-Path $LogDir 'pg_ctl-start.txt'
    $pgCtl = Join-Path $PgBin 'pg_ctl.exe'
    $cmdLine = "`"`"$pgCtl`" -D `"$PgDataDir`" -l `"$pgLog`" -o `"-p $PgPort -c listen_addresses=localhost`" -w -t 60 start < NUL > `"$ctlOut`" 2>&1`""
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & cmd.exe /d /c $cmdLine
    $ErrorActionPreference = $previous
    if (Test-Path $ctlOut) { Get-Content $ctlOut | ForEach-Object { Write-Log ("  $_") } }
    if (-not (Test-PgReady $PgBin)) { throw "PostgreSQL did not start. See $pgLog" }
    Write-Log 'PostgreSQL started.' 'Green'
}

function Use-Toolchain {
    # Puts the selected PHP, Node and PostgreSQL first on PATH for this PowerShell session only.
    $php = Find-Php
    if (-not $php) { throw 'PHP 8.2+ not found (looked in C:\laragon\bin\php and PATH). Run setup-windows.bat first.' }
    $node = Find-Node
    if (-not $node) { throw 'Node.js 20.9+ not found. Run setup-windows.bat first.' }
    $pgBin = Find-PgBin
    $paths = @((Split-Path $php), (Split-Path $node))
    if ($pgBin) { $paths += $pgBin }
    $env:Path = ($paths -join ';') + ';' + $env:Path
    # setup.ps1 puts a current Composer into .tools\ when Laragon's bundled one is too old.
    $composerPhar = Join-Path $Root '.tools\composer.phar'
    if (-not (Test-Path $composerPhar)) { $composerPhar = Join-Path $LaragonRoot 'bin\composer\composer.phar' }
    return [pscustomobject]@{ Php = $php; Node = $node; PgBin = $pgBin; ComposerPhar = $composerPhar }
}

# ---------------------------------------------------------------- port resolution
# The data directory's postgresql.conf is the source of truth for the PostgreSQL port (setup.ps1
# writes `port = N` there and copies it to DB_PORT in backend\.env).
function Get-PgConfPort {
    $conf = Join-Path $PgDataDir 'postgresql.conf'
    if (-not (Test-Path $conf)) { return $null }
    $m = [regex]::Matches((Get-Content $conf -Raw), '(?m)^\s*port\s*=\s*(\d+)')
    if ($m.Count -gt 0) { return [int]$m[$m.Count - 1].Groups[1].Value }
    return $null
}

function Set-PgConfPort([int]$Port) {
    $conf = Join-Path $PgDataDir 'postgresql.conf'
    if ((Get-PgConfPort) -eq $Port) { return }
    Add-Content -Path $conf -Value "`r`n# Lexora Workspace (setup-windows.bat)`r`nport = $Port`r`nlisten_addresses = 'localhost'" -Encoding ASCII
}

if ($env:LEXORA_PG_PORT) { $PgPort = [int]$env:LEXORA_PG_PORT }
elseif ($null -ne (Get-PgConfPort)) { $PgPort = Get-PgConfPort }
else {
    $envPort = Get-DotEnvValue (Join-Path $Backend '.env') 'DB_PORT'
    if ($envPort -match '^\d+$') { $PgPort = [int]$envPort }
}
# Ports of THIS project's API and web app. Defaults 8000/3000; setup.ps1 records the chosen ports as
# LEXORA_API_PORT / LEXORA_WEB_PORT in backend\.env (moved to 8010/3010 when 8000/3000 belong to another
# program). Environment variables of the same name override both.
$ApiPort = 8000; $WebPort = 3000
$v = Get-DotEnvValue (Join-Path $Backend '.env') 'LEXORA_API_PORT'; if ($v -match '^\d+$') { $ApiPort = [int]$v }
$v = Get-DotEnvValue (Join-Path $Backend '.env') 'LEXORA_WEB_PORT'; if ($v -match '^\d+$') { $WebPort = [int]$v }
if ($env:LEXORA_API_PORT) { $ApiPort = [int]$env:LEXORA_API_PORT }
if ($env:LEXORA_WEB_PORT) { $WebPort = [int]$env:LEXORA_WEB_PORT }
$ApiUrl = "http://localhost:$ApiPort"
$WebUrl = "http://localhost:$WebPort"