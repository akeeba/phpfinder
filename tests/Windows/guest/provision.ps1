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
New-Item -ItemType Directory -Force -Path $dl, "$pf\run", "$pf\work", "$pf\fake\empty" | Out-Null

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
# The x64 runtime for the x64 runners, the x86 one (in SysWOW64) for the x86 runners.
foreach ($arch in 'x64', 'x86') {
    $p = Start-Process (Join-Path $dl "vc_redist.$arch.exe") -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    if ($p.ExitCode -notin 0, 1638, 3010) { throw "vc_redist.$arch exit code $($p.ExitCode)" }
}
"VC++ runtime: x64 $((Get-Item C:\Windows\System32\vcruntime140.dll).VersionInfo.FileVersion), x86 $((Get-Item C:\Windows\SysWOW64\vcruntime140.dll).VersionInfo.FileVersion)"

# One runner per zip: <X.Y> for an x64 build, <X.Y>-x86 for an x86 (32-bit) one.
$runners = @{}
foreach ($pin in $pins | Where-Object Name -like 'php-*.zip') {
    $match = [regex]::Match($pin.Name, '^php-(\d+\.\d+)\.\d+-nts-Win32-[A-Za-z]+\d+-(x64|x86)\.zip$')
    if (-not $match.Success) { throw "$($pin.Name): not a PHP build name provision.ps1 knows" }
    $version = $match.Groups[1].Value + $(if ($match.Groups[2].Value -eq 'x86') { '-x86' } else { '' })
    Expand-Archive (Join-Path $dl $pin.Name) "$pf\run\$version"
    $runners[$version] = "$pf\run\$version"
}
# The VM's own PHP is the 8.4 runner, copied out of C:\php: C:\PHP is one of the fixture locations.
Copy-Item C:\php "$pf\run\8.4" -Recurse
$runners['8.4'] = "$pf\run\8.4"

foreach ($version in $runners.Keys | Sort-Object) {
    $banner = (& "$($runners[$version])\php.exe" -n -v 2>&1 | Select-Object -First 1) -as [string]
    $arch   = if ($version -like '*-x86') { 'x86' } else { 'x64' }
    if ($banner -notlike "PHP $($version -replace '-x86$', '').* (cli)*$arch*") { throw "runner $version does not run: $banner" }
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
New-Fixture '8.3' 'C:\Program Files (x86)\Plesk\Additional\PleskPHP83'   # Plesk Obsidian, 12.x
New-Fixture '8.2' 'C:\Program Files (x86)\Plesk\Additional\PHP82'         # older Plesk releases' documentation
# Only in the PATH when a test puts them there (`where`).
New-Fixture '8.1' "$pf\path-a"
New-Fixture '8.2' "$pf\path-b"
New-Fixture '8.2' "$pf\path-b" 'php82.exe'
New-Fixture '8.3' "$pf\path-cgi" 'php.exe' 'php-cgi.exe' 'cgi-fcgi'
# `where` lists every file named php with an extension in PATHEXT, and php itself. Next to PHP 8.2's php.exe: a php.bat
# and a php.cmd which claim to be PHP 8.1 (so a test would see it if one ran), an extensionless shell script, as Cygwin
# or Git Bash would run, and php.com, a real program (php.exe's copy) under another name.
New-Fixture '8.2' "$pf\path-ext"
Copy-Item "$pf\path-ext\php.exe" "$pf\path-ext\php.com"
[IO.File]::WriteAllText("$pf\path-ext\php.bat", "@echo PHP 8.1.99 (cli) (php.bat)`r`n", [Text.Encoding]::ASCII)
[IO.File]::WriteAllText("$pf\path-ext\php.cmd", "@echo PHP 8.1.98 (cli) (php.cmd)`r`n", [Text.Encoding]::ASCII)
[IO.File]::WriteAllText("$pf\path-ext\php", "#!/bin/sh`nexec php.exe `"`$@`"`n", [Text.Encoding]::ASCII)
foreach ($wrapper in 'php.bat', 'php.cmd') {
    $banner = (& "$env:windir\System32\cmd.exe" /d /c "$pf\path-ext\$wrapper" 2>&1 | Select-Object -First 1) -as [string]
    if ($banner -notlike 'PHP 8.1.9* (cli)*') { throw "fixture $pf\path-ext\$wrapper does not run: $banner" }
    "fixture $pf\path-ext\${wrapper}: $banner"
}
# Directory names with the characters cmd.exe treats specially. %PF_UNDEFINED% names no environment variable; %OS% does.
New-Fixture '8.5' "$pf\odd %PF_UNDEFINED% & (x) !bang ^caret"
New-Fixture '8.5' "$pf\odd %OS%"
# The same characters without a %, and what C:\pf\odd %OS%\php.exe becomes when cmd.exe expands %OS% (another version).
New-Fixture '8.5' "$pf\odd & (x) !bang ^caret"
New-Fixture '8.3' "$pf\odd Windows_NT"

# A php.exe which is not a program at all.
New-Item -ItemType Directory -Force "$pf\fake\broken" | Out-Null
Set-Content "$pf\fake\broken\php.exe" 'This is not a program.' -Encoding ascii
# And an empty one.
New-Item -ItemType Directory -Force "$pf\fake\zero" | Out-Null
[IO.File]::WriteAllBytes("$pf\fake\zero\php.exe", [byte[]]@())

# A stand-in for Windows' tools, compiled here (.NET Framework's C# compiler, through Add-Type). Copied under a tool's
# name (where.exe, WMIC.exe, …), it appends its command line to <name>.ran next to itself, so a test can tell that it
# ran; prints the bytes of <name>.stdout, if there is one; and exits with the number in <name>.exitcode, or 0.
$fakeTool = "$pf\fake\fake-tool.exe"
Add-Type -OutputType ConsoleApplication -OutputAssembly $fakeTool -TypeDefinition @'
using System;
using System.IO;

public static class PfFakeTool
{
    public static int Main(string[] args)
    {
        string exe  = System.Reflection.Assembly.GetEntryAssembly().Location;
        string dir  = Path.GetDirectoryName(exe);
        string name = Path.GetFileNameWithoutExtension(exe);

        try { File.AppendAllText(Path.Combine(dir, name + ".ran"), Environment.CommandLine + "\r\n"); } catch (Exception) { }

        string stdout = Path.Combine(dir, name + ".stdout");

        if (File.Exists(stdout))
        {
            byte[] bytes = File.ReadAllBytes(stdout);

            using (Stream stream = Console.OpenStandardOutput())
            {
                stream.Write(bytes, 0, bytes.Length);
            }
        }

        string code = Path.Combine(dir, name + ".exitcode");

        return File.Exists(code) ? int.Parse(File.ReadAllText(code).Trim()) : 0;
    }
}
'@
if (-not (Test-Path $fakeTool)) { throw 'Could not compile the fake tool' }

# New-FakeTool <directory> <file name> [<what it prints>] [<exit code>]
function New-FakeTool([string]$dir, [string]$name, [string]$stdout = $null, [int]$exitCode = 0) {
    [IO.Directory]::CreateDirectory($dir) | Out-Null
    Copy-Item $fakeTool (Join-Path $dir $name)
    $base = Join-Path $dir ([IO.Path]::GetFileNameWithoutExtension($name))
    if ($null -ne $stdout) { [IO.File]::WriteAllText("$base.stdout", $stdout, [Text.Encoding]::ASCII) }
    if ($exitCode -ne 0) { [IO.File]::WriteAllText("$base.exitcode", "$exitCode", [Text.Encoding]::ASCII) }
}

# Windows directories with stand-ins for misbehaving tools, at the paths PHPFinder runs them from: a wmic which lists no
# drive (a real one prints "No Instance(s) Available." on STDERR, blank lines on STDOUT), tools which print an error on
# STDOUT, and none at all. A test gives one to the library instead of C:\Windows (PHPFinder's private
# $windowsDirectory). The stand-ins which do list the drives print what the real tools print (see FactsTest).
$wmic   = 'System32\wbem\WMIC.exe'
$fsutil = 'System32\fsutil.exe'
$ps     = 'System32\WindowsPowerShell\v1.0\powershell.exe'
$w      = "$pf\fakewin"
# Each also gets a copy of Windows' own cmd.exe, through which PHPFinder runs the tools.
function Add-FakeWindowsTool([string]$case, [string]$tool, [string]$stdout = $null, [int]$exitCode = 0) {
    New-FakeTool (Split-Path (Join-Path "$w\$case" $tool)) (Split-Path $tool -Leaf) $stdout $exitCode
    Copy-Item "$env:windir\System32\cmd.exe" "$w\$case\System32\cmd.exe" -Force
}
$drivesFsutil = "`r`nDrives: C:\ D:\ E:\ `r`n"
$drivesPs     = "C:\`r`nD:\`r`nE:\`r`n"
Add-FakeWindowsTool 'wmic-none'  $wmic   "`r`r`n`r`r`n"
Add-FakeWindowsTool 'wmic-none'  $fsutil $drivesFsutil
Add-FakeWindowsTool 'wmic-error' $wmic   "ERROR:`r`nDescription = Access denied.`r`n" 1
Add-FakeWindowsTool 'wmic-error' $fsutil $drivesFsutil
Add-FakeWindowsTool 'fsutil-error' $fsutil "Error:  Access is denied.`r`n" 1
Add-FakeWindowsTool 'fsutil-error' $ps     $drivesPs
Add-FakeWindowsTool 'all-fail' $wmic   "`r`r`n`r`r`n"
Add-FakeWindowsTool 'all-fail' $fsutil "Error:  Access is denied.`r`n" 1
Add-FakeWindowsTool 'all-fail' $ps     "Program 'powershell.exe' failed to run: Access is denied`r`n" 1
New-Item -ItemType Directory -Force "$w\empty" | Out-Null

# A working directory with stand-ins for where, wmic, fsutil and PowerShell, which print nothing (so that code which
# runs them by their bare names would try every one in turn), and one with a stand-in cmd.exe. Writable by IIS's
# application pool identity too, for the <name>.ran files.
foreach ($name in 'where.exe', 'wmic.exe', 'fsutil.exe', 'powershell.exe') { New-FakeTool "$pf\planted" $name }
New-FakeTool "$pf\planted-cmd" 'cmd.exe'
foreach ($dir in "$pf\planted", "$pf\planted-cmd") { & icacls $dir /grant 'Everyone:(OI)(CI)M' | Out-Null }

# Let the suite hide wmic and fsutil for real (a Windows without wmic: Windows 11 24H2, Windows Server 2025), by
# renaming them (relay.php). They belong to TrustedInstaller; the VM's snapshot restores them on the next start. The
# 32-bit copies in SysWOW64 too: that is what an x86 runner sees at C:\Windows\System32.
foreach ($tool in $wmic, $fsutil, ($wmic -replace '^System32', 'SysWOW64'), ($fsutil -replace '^System32', 'SysWOW64')) {
    $tool = "$env:windir\$tool"
    & takeown /f $tool /a | Out-Null
    & icacls $tool /grant 'Administrators:F' | Out-Null
}

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
