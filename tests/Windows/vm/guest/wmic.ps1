# Microsoft's WMIC Feature on Demand on a PHPFinder test VM: what it is, and what removing it leaves, in particular of
# the 32-bit copy in SysWOW64. Run by `pfvm.sh build`, as Administrator, three times:
#
#   wmic.ps1 before    the capability's state and both copies of WMIC.exe, as Windows was installed
#   wmic.ps1 remove    Remove-WindowsCapability (when the capability is not installed, Add-WindowsCapability first, to
#                      see whether it can still be added, and what its removal then leaves), and the state right after
#   wmic.ps1 after     the same record, after a restart: the state the VM's snapshot keeps
#
# pfvm.sh copies the three records into wmic.log in the VM directory. Prints PFVM-OK as its last line when it worked.
param([Parameter(Mandatory = $true)][ValidateSet('before', 'remove', 'after')][string]$Stage)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Write-WmicState([string]$label) {
    "--- $label"
    # What the native programs print on STDERR (2>&1) must not stop the script, as it would under 'Stop'.
    $ErrorActionPreference = 'Continue'
    # Not the registry's ProductName: Windows 11 still says "Windows 10" there.
    $cv = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
    "Windows: $((Get-CimInstance Win32_OperatingSystem).Caption), $($cv.EditionID), $($cv.InstallationType), build $($cv.CurrentBuild).$($cv.UBR)"

    $caps = @(Get-WindowsCapability -Online | Where-Object Name -like 'WMIC*')
    if ($caps.Count -eq 0) { 'Get-WindowsCapability: no WMIC capability listed' }
    foreach ($cap in $caps) { "Get-WindowsCapability: $($cap.Name) = $($cap.State)" }

    $dism = & dism.exe /English /Online /Get-Capabilities /Format:Table | Select-String -SimpleMatch 'WMIC'
    if (-not $dism) { 'DISM /Get-Capabilities: no WMIC capability listed' }
    foreach ($line in $dism) { "DISM /Get-Capabilities: $(($line.Line -replace '\s+', ' ').Trim())" }

    # Both copies, and their language resources. The 64-bit relay of the suite sees both directories as they are.
    foreach ($file in 'System32\wbem\WMIC.exe', 'SysWOW64\wbem\WMIC.exe', 'System32\wbem\en-US\wmic.exe.mui',
                      'SysWOW64\wbem\en-US\wmic.exe.mui') {
        $path = Join-Path $env:windir $file
        if (Test-Path -LiteralPath $path) {
            $item = Get-Item -LiteralPath $path
            "$path : present, $($item.Length) bytes, version $($item.VersionInfo.FileVersion)"
        } else {
            "$path : absent"
        }
    }

    # And whether each copy still runs.
    foreach ($exe in "$env:windir\System32\wbem\WMIC.exe", "$env:windir\SysWOW64\wbem\WMIC.exe") {
        if (-not (Test-Path -LiteralPath $exe)) { continue }
        $out = & $exe logicaldisk get name 2>&1 | ForEach-Object { "$_".Trim() } | Where-Object { $_ -ne '' }
        "$exe logicaldisk get name (exit code $LASTEXITCODE): $($out -join ' | ')"
    }

    $where = & where.exe wmic 2>&1
    "where wmic (exit code $LASTEXITCODE): $($where -join ' | ')"
}

# Add-WindowsCapability fetches the feature from Windows Update, which refuses an SSH session's logon ("Access is
# denied."): as SYSTEM, from a scheduled task, instead. Returns what it printed.
function Add-CapabilityAsSystem([string]$name) {
    $script = 'C:\pfvm\add-capability.ps1'
    $log    = 'C:\pfvm\add-capability.log'
    Remove-Item $log -ErrorAction SilentlyContinue
    Set-Content -Encoding ascii -Path $script -Value @"
try { `$r = Add-WindowsCapability -Online -Name '$name' -ErrorAction Stop; "Add-WindowsCapability: restart needed `$(`$r.RestartNeeded)" }
catch { "Add-WindowsCapability failed: `$(`$_.Exception.Message)" }
"@
    $action = New-ScheduledTaskAction -Execute powershell.exe -Argument "-NoProfile -ExecutionPolicy Bypass -Command & '$script' *> '$log'"
    Register-ScheduledTask -TaskName pfvm-add-capability -Action $action -User SYSTEM -RunLevel Highest -Force | Out-Null
    Start-ScheduledTask -TaskName pfvm-add-capability
    $deadline = (Get-Date).AddMinutes(30)
    do {
        Start-Sleep 5
        $state = (Get-ScheduledTask -TaskName pfvm-add-capability).State
    } while ($state -eq 'Running' -and (Get-Date) -lt $deadline)
    Unregister-ScheduledTask -TaskName pfvm-add-capability -Confirm:$false
    if ($state -eq 'Running') { return 'Add-WindowsCapability: still running after 30 minutes' }
    (Get-Content $log -ErrorAction SilentlyContinue) -join ' '
}

switch ($Stage) {
    'before' { Write-WmicState 'as installed' }
    'remove' {
        $cap = Get-WindowsCapability -Online | Where-Object Name -like 'WMIC*' | Select-Object -First 1
        if (-not $cap) { throw 'Windows lists no WMIC capability' }

        if ($cap.State -ne 'Installed') {
            "Add-WindowsCapability -Name $($cap.Name) (state: $($cap.State)), as SYSTEM"
            Add-CapabilityAsSystem $cap.Name
            $cap = Get-WindowsCapability -Online -Name $cap.Name
            if ($cap.State -eq 'Installed') { Write-WmicState 'after Add-WindowsCapability' }
        }

        if ($cap.State -eq 'Installed') {
            $r = Remove-WindowsCapability -Online -Name $cap.Name
            "Remove-WindowsCapability -Name $($cap.Name): restart needed $($r.RestartNeeded)"
        } else {
            "Nothing to remove: $($cap.Name) is $($cap.State)"
        }

        Write-WmicState 'after Remove-WindowsCapability, before the restart'
    }
    'after' {
        Write-WmicState 'after the restart'
        if (Get-WindowsCapability -Online | Where-Object { $_.Name -like 'WMIC*' -and $_.State -eq 'Installed' }) {
            throw 'The WMIC capability is still installed'
        }
    }
}

'PFVM-OK'
