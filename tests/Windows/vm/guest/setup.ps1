# The Visual C++ runtime and PHP 8.4 in C:\php, on a PHPFinder test VM without WMIC: what tests/Windows/guest/provision.ps1
# expects of a VM besides IIS. Run by `pfvm.sh build`, once, as Administrator. Also turns Windows Update's automatic
# updates off (again: the answer file did it first) and makes sure the 32-bit subsystem (WoW64) is there, which the
# suite's 32-bit runners need. Prints what it found, and PFVM-OK as its last line when everything worked.
#
# Expects C:\pfvm\downloads (the pin list) and the downloaded files in C:\pfvm\dl; checks every hash again here.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$dl = 'C:\pfvm\dl'

$pins = @{}
foreach ($line in Get-Content C:\pfvm\downloads) {
    $line = $line.Trim()
    if ($line -eq '' -or $line.StartsWith('#')) { continue }
    $parts = $line -split '\s+'
    if ($parts[0] -like '*.iso') { continue }
    $pins[$parts[0]] = $parts[1].ToLower()
}
foreach ($f in $pins.Keys) {
    $h = (Get-FileHash -Algorithm SHA256 (Join-Path $dl $f)).Hash.ToLower()
    if ($h -ne $pins[$f]) { throw "$f hash mismatch: $h" }
}
function Pinned([string]$like) { Join-Path $dl ($pins.Keys | Where-Object { $_ -like $like } | Select-Object -First 1) }

$cv = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
$os = Get-CimInstance Win32_OperatingSystem
"Windows: $($os.Caption), $($cv.EditionID), $($cv.InstallationType), $($cv.DisplayVersion), build $($cv.CurrentBuild).$($cv.UBR)"
function Get-Licence {
    Get-CimInstance SoftwareLicensingProduct -Filter 'PartialProductKey IS NOT NULL' | Where-Object { $_.Name -like 'Windows*' } | Select-Object -First 1
}
$licence = Get-Licence
"Licence: $($licence.Name); $($licence.Description); status $($licence.LicenseStatus); $([int]($licence.GracePeriodRemaining / 1440)) days left"
# Until it is activated (online, with Microsoft), an evaluation counts down 10 days, not its 180 (Server) or 90 (Windows
# 11): pfvm.sh would rebuild the VM every few days. Windows activates by itself, some time after the install; now.
if ($licence.LicenseStatus -ne 1) {
    $ErrorActionPreference = 'Continue'
    $out = & cscript.exe //nologo "$env:windir\System32\slmgr.vbs" /ato 2>&1
    $ErrorActionPreference = 'Stop'
    "slmgr /ato: $(($out | ForEach-Object { "$_".Trim() } | Where-Object { $_ -ne '' }) -join ' ')"
    $licence = Get-Licence
    "Licence: status $($licence.LicenseStatus); $([int]($licence.GracePeriodRemaining / 1440)) days left"
    if ($licence.LicenseStatus -ne 1) { throw 'Windows is not activated' }
}

# WoW64: on Server Core a feature (WoW64-Support) which can be removed; always there on client Windows.
if (Get-Command Get-WindowsFeature -ErrorAction SilentlyContinue) {
    $wow = Get-WindowsFeature WoW64-Support
    "WoW64-Support: $($wow.InstallState)"
    if (-not $wow.Installed) {
        $r = Install-WindowsFeature WoW64-Support
        if (-not $r.Success) { throw "Install-WindowsFeature WoW64-Support failed: $($r.ExitCode)" }
        "WoW64-Support installed now (restart needed: $($r.RestartNeeded))"
    }
}
if (-not (Test-Path C:\Windows\SysWOW64\cmd.exe)) { throw 'No 32-bit subsystem: C:\Windows\SysWOW64\cmd.exe is missing' }
"SysWOW64\cmd.exe: present"

$p = Start-Process (Pinned 'vc_redist*.exe') -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
if ($p.ExitCode -notin 0, 1638, 3010) { throw "vc_redist exit code $($p.ExitCode)" }

if (Test-Path C:\php) { Remove-Item C:\php -Recurse -Force }
Expand-Archive (Pinned 'php-*.zip') C:\php
Copy-Item C:\php\php.ini-development C:\php\php.ini
Add-Content C:\php\php.ini "`r`ndisplay_errors=On`r`n"
$php = & C:\php\php.exe -n -v 2>&1 | Select-Object -First 1
"C:\php: $php"
if ("$php" -notlike 'PHP 8.4.* (cli)*') { throw "C:\php\php.exe does not run: $php" }

# The VM is restored from its snapshot for every run: updates would only be lost again, and an update restarting
# Windows in the middle of a test run would fail it at random.
New-Item -Path HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU -Force | Out-Null
Set-ItemProperty -Path HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU -Name NoAutoUpdate -Value 1 -Type DWord

# Microsoft Defender and Smart App Control, for the record: neither is changed.
$mp = Get-MpComputerStatus -ErrorAction SilentlyContinue
if ($mp) { "Defender: real-time protection $($mp.RealTimeProtectionEnabled); Smart App Control: $($mp.SmartAppControlState)" }

Remove-Item $dl -Recurse -Force
'PFVM-OK'
