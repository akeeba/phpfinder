# Provisions the Windows test VM for tests/Windows: runs IN THE GUEST, as Administrator, once per VM start (run.sh).
#
# Expects C:\pf\downloads (the pin list) and C:\pf\src, C:\pf\probe.php, C:\pf\relay.php, C:\pf\iis-probe.php.
# Prints PF-PROVISION-OK as its last line when everything worked; anything else is a failure.
#
#   1. Downloads every pinned file, in parallel, and checks its SHA-256 with Get-FileHash.
#   2. Installs the newer Visual C++ runtime (PHP 8.6 needs it) and unpacks one PHP per version into
#      C:\pf\run\<X.Y>, where no search strategy looks: these are the "runners" which execute the library.
#   3. Attaches a VHD as a second drive letter, D:, which (unlike SUBST) every logon session and WMI see.
#   4. Copies real PHP builds to every location a search strategy looks in (the fixtures, see README.md), and checks
#      that each one runs and reports the version it should.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$pf = 'C:\pf'
$dl = "$pf\dl"
New-Item -ItemType Directory -Force -Path $dl, "$pf\run", "$pf\work", "$pf\fake" | Out-Null

# ---------------------------------------------------------------------------------------------------------------------
# 1. Downloads
# ---------------------------------------------------------------------------------------------------------------------
$pins = @()
foreach ($line in Get-Content "$pf\downloads") {
    $line = $line.Trim()
    if ($line -eq '' -or $line.StartsWith('#')) { continue }
    $parts = $line -split '\s+'
    $pins += [pscustomobject]@{ Name = $parts[0]; Hash = $parts[1].ToLower(); Urls = $parts[2..($parts.Count - 1)] }
}

function Test-Pin($pin) {
    $file = Join-Path $dl $pin.Name
    (Test-Path $file) -and ((Get-FileHash -Algorithm SHA256 $file).Hash.ToLower() -eq $pin.Hash)
}

# Every file's first URL at once, then the fallbacks one by one for whatever did not arrive intact.
$started = Get-Date
$procs = foreach ($pin in $pins) {
    Start-Process curl.exe -ArgumentList '-fsSL', '--retry', '3', '-o', (Join-Path $dl $pin.Name), $pin.Urls[0] `
        -NoNewWindow -PassThru
}
$procs | Wait-Process

foreach ($pin in $pins) {
    foreach ($url in $pin.Urls | Select-Object -Skip 1) {
        if (Test-Pin $pin) { break }
        & curl.exe -fsSL --retry 3 -o (Join-Path $dl $pin.Name) $url
    }
    if (-not (Test-Pin $pin)) { throw "$($pin.Name): missing, or not the pinned SHA-256 $($pin.Hash)" }
}
"Downloaded and verified $($pins.Count) files in $([int]((Get-Date) - $started).TotalSeconds) s"

# ---------------------------------------------------------------------------------------------------------------------
# 2. Runtime and runners
# ---------------------------------------------------------------------------------------------------------------------
$p = Start-Process (Join-Path $dl 'vc_redist.x64.exe') -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
if ($p.ExitCode -notin 0, 1638, 3010) { throw "vc_redist exit code $($p.ExitCode)" }
"VC++ runtime: $((Get-Item C:\Windows\System32\vcruntime140.dll).VersionInfo.FileVersion)"

$runners = @{}
foreach ($pin in $pins | Where-Object Name -like 'php-*.zip') {
    $version = [regex]::Match($pin.Name, '^php-(\d+\.\d+)\.').Groups[1].Value
    Expand-Archive (Join-Path $dl $pin.Name) "$pf\run\$version"
    $runners[$version] = "$pf\run\$version"
}
# The VM's own PHP is the 8.4 runner, copied out of C:\php: C:\PHP is one of the fixture locations.
Copy-Item C:\php "$pf\run\8.4" -Recurse
$runners['8.4'] = "$pf\run\8.4"

foreach ($version in $runners.Keys | Sort-Object) {
    $banner = (& "$($runners[$version])\php.exe" -n -v 2>&1 | Select-Object -First 1) -as [string]
    if ($banner -notlike "PHP $version.* (cli)*") { throw "runner $version does not run: $banner" }
    "runner ${version}: $banner"
}

# ---------------------------------------------------------------------------------------------------------------------
# 3. A second drive letter
# ---------------------------------------------------------------------------------------------------------------------
# SUBST drives only exist in the logon session which made them (every SSH command is a new one, and IIS is another).
# A VHD attached with diskpart is a real volume until the next reboot, listed by wmic, fsutil and .NET alike.
if (Test-Path D:\) { throw 'D: already exists; the fixtures expect to create it' }
@"
create vdisk file=$pf\second-drive.vhdx maximum=1024 type=expandable
attach vdisk
create partition primary
format fs=ntfs quick label=PFTEST
assign letter=D
"@ | Set-Content "$pf\diskpart.txt" -Encoding ascii
diskpart /s "$pf\diskpart.txt" | Out-Null
if (-not (Test-Path D:\)) { throw 'diskpart did not create D:' }

# ---------------------------------------------------------------------------------------------------------------------
# 4. Fixtures
# ---------------------------------------------------------------------------------------------------------------------
# A fixture is a PHP build's top-level files (php.exe, php-cgi.exe and the DLLs next to them; no extensions, the
# tests run everything with -n) copied to $dir. $name is the file name to give $from (php.exe or php-cgi.exe).
function New-Fixture([string]$version, [string]$dir, [string]$name = 'php.exe', [string]$from = 'php.exe',
                     [string]$sapi = 'cli') {
    [IO.Directory]::CreateDirectory($dir) | Out-Null
    Get-ChildItem -LiteralPath $runners[$version] -File | Copy-Item -Destination $dir
    if ($name -ne $from) { Copy-Item -LiteralPath (Join-Path $dir $from) -Destination (Join-Path $dir $name) -Force }
    $exe = Join-Path $dir $name
    $banner = (& $exe -n -v 2>&1 | Select-Object -First 1) -as [string]
    if ($banner -notlike "PHP $version.* ($sapi)*") { throw "fixture $exe is not PHP $version ($sapi): $banner" }
    "fixture ${exe}: $banner"
}

# C:\PHP\php.exe is the VM's own PHP 8.4 (C:\php): checked, not copied.
$banner = (& C:\PHP\php.exe -n -v 2>&1 | Select-Object -First 1) -as [string]
if ($banner -notlike 'PHP 8.4.* (cli)*') { throw "C:\PHP\php.exe is not PHP 8.4: $banner" }

# The common Windows directories: <drive>\PHP (depth 1), <drive>\PHP<XY> / PHP<X.Y> (depth 0), the same under
# Program Files and Program Files (x86), on every drive.
New-Fixture '8.3' 'C:\PHP83'
New-Fixture '8.3' 'C:\PHP82'                                    # mislabelled: version validation
New-Fixture '8.2' 'C:\Program Files\PHP\v8.2'
New-Fixture '8.1' 'C:\Program Files (x86)\PHP8.1'
New-Fixture '8.5' 'D:\PHP85'                                    # the second drive
# Software-specific layouts.
New-Fixture '8.0' 'C:\wamp64\bin\php\php8.0.30'
New-Fixture '7.2' 'C:\wamp64\bin\php\php7.2.34'
New-Fixture '8.6' 'C:\MAMP\bin\php\php8.6.0'
New-Fixture '7.2' 'C:\xampp\php'
New-Fixture '8.3' 'C:\Program Files (x86)\Plesk\Additional\PHP8.3'
# Only in the PATH when a test puts them there (`where`).
New-Fixture '8.1' "$pf\path-a"
New-Fixture '8.2' "$pf\path-b"
New-Fixture '8.2' "$pf\path-b" 'php82.exe'
New-Fixture '8.3' "$pf\path-cgi" 'php.exe' 'php-cgi.exe' 'cgi-fcgi'
# Directory names with the characters cmd.exe treats specially. %PF_UNDEFINED% names no environment variable; %OS% does.
New-Fixture '8.5' "$pf\odd %PF_UNDEFINED% & (x) !bang ^caret"
New-Fixture '8.5' "$pf\odd %OS%"

# Stand-ins for a wmic which lists no drive (the "No Instance(s) Available." of a real wmic goes to STDERR; STDOUT
# only gets blank lines), for a wmic, fsutil or PowerShell which prints an error on STDOUT, and an empty directory for
# a PATH without any of the tools. The tests put them in the PATH before the system directories: PHPFinder sees the
# real WMIC.exe, fsutil.exe and powershell.exe there (is_executable() is FALSE for a .bat file), but cmd.exe runs the
# .bat which comes first in the PATH.
New-Item -ItemType Directory -Force "$pf\fake\wmic-none", "$pf\fake\wmic-error", "$pf\fake\fsutil-error", `
    "$pf\fake\powershell-error", "$pf\fake\empty" | Out-Null
Set-Content "$pf\fake\wmic-none\wmic.bat" "@echo off`r`necho No Instance(s) Available. 1>&2`r`necho.`r`necho.`r`n" -Encoding ascii
Set-Content "$pf\fake\wmic-error\wmic.bat" "@echo off`r`necho ERROR:`r`necho Description = Access denied.`r`nexit /b 1`r`n" -Encoding ascii
Set-Content "$pf\fake\fsutil-error\fsutil.bat" "@echo off`r`necho Error:  Access is denied.`r`nexit /b 1`r`n" -Encoding ascii
# A php.exe which is not a program at all.
New-Item -ItemType Directory -Force "$pf\fake\broken" | Out-Null
Set-Content "$pf\fake\broken\php.exe" 'This is not a program.' -Encoding ascii
Set-Content "$pf\fake\powershell-error\powershell.bat" "@echo off`r`necho Program 'powershell.exe' failed to run: Access is denied`r`nexit /b 1`r`n" -Encoding ascii

# The library, run by IIS (FastCGI, C:\php\php-cgi.exe 8.4) as a web application would be.
#
# One php-cgi.exe process per request. The probe sets PATH (and other variables) with putenv(), and PHP's FastCGI SAPI
# on Windows does not restore them at the end of the request: it deletes them from the process, so every later request
# the same php-cgi.exe served would run without PATH and SYSTEMDRIVE, unlike any real IIS site.
& "$env:windir\system32\inetsrv\appcmd.exe" set config -section:system.webServer/fastCgi `
    "/[fullPath='C:\php\php-cgi.exe'].instanceMaxRequests:1" /commit:apphost | Out-Null
Copy-Item "$pf\iis-probe.php" C:\inetpub\wwwroot\pf-probe.php
$page = (Invoke-WebRequest -UseBasicParsing 'http://localhost/pf-probe.php?ping=1').Content
if ($page -ne 'pf-probe-ok') { throw "IIS probe answered: $page" }

'PF-PROVISION-OK'
