# The last check of `pfvm.sh build`, before the snapshot: the VM is what tests/Windows/guest/provision.ps1 and the
# suite expect. D: is free (provision.ps1 attaches a VHD there), E: is the empty CD drive (the suite's drive list is
# C:, D:, E:), C:\php is PHP 8.4, and automatic updates are off. Prints PFVM-OK as its last line when it holds.
$ErrorActionPreference = 'Stop'

$disks = Get-CimInstance Win32_LogicalDisk | Sort-Object DeviceID
foreach ($disk in $disks) { "$($disk.DeviceID) type $($disk.DriveType)" }
if ($disks | Where-Object DeviceID -eq 'D:') { throw 'D: is taken: provision.ps1 needs it for its VHD' }
if (-not ($disks | Where-Object { $_.DeviceID -eq 'E:' -and $_.DriveType -eq 5 })) { throw 'E: is not the CD drive' }
if (@($disks).Count -ne 2) { throw 'Expected only C: and E:' }

$php = & C:\php\php.exe -n -v 2>&1 | Select-Object -First 1
if ("$php" -notlike 'PHP 8.4.* (cli)*') { throw "C:\php\php.exe does not run: $php" }

if ((Get-ItemProperty HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU).NoAutoUpdate -ne 1) { throw 'Automatic updates are on' }
if ((Get-Service sshd).StartType -ne 'Automatic') { throw 'sshd does not start automatically' }

'PFVM-OK'
