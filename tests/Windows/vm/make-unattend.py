#!/usr/bin/env python3
"""Write autounattend.xml for an unattended install of a PHPFinder test VM, with OpenSSH.

usage: make-unattend.py <os> <out-dir> <public-key-file> <password-file>

<os> is server2025 (Windows Server 2025 Standard Evaluation, Server Core: BIOS, one MBR partition) or win11
(Windows 11 Enterprise Evaluation: UEFI, GPT). Called by pfvm.sh build; modelled on ../buildfiles/vm/iis's.

The answer file covers every pass, including windowsPE, so Setup never stops at its language pages. It turns Windows
Update's automatic updates off (and, on Windows 11, device encryption) in the specialize pass, before anything can
start them. At first logon, as the built-in Administrator, it installs OpenSSH Server when Windows does not have it
yet (from Windows Update), authorises the key for the Administrators group, refuses SSH passwords, makes Windows
PowerShell the SSH shell, and finally writes C:\\firstlogon-done.txt, which pfvm.sh waits for.
"""
import pathlib
import sys
from xml.sax.saxutils import escape

os_name = sys.argv[1]
out = pathlib.Path(sys.argv[2]).expanduser()
pub = pathlib.Path(sys.argv[3]).expanduser().read_text().strip()
password = pathlib.Path(sys.argv[4]).expanduser().read_text().strip()
assert os_name in ("server2025", "win11")
assert pub.startswith("ssh-") and "'" not in pub and "\n" not in pub

uefi = os_name == "win11"


def ps(command: str) -> str:
    return f'powershell -NoProfile -ExecutionPolicy Bypass -Command "{command}"'


def synchronous(element: str, commands) -> str:
    text = ""
    for order, (description, command) in enumerate(commands, start=1):
        text += (
            f'        <{element} wcm:action="add">\n'
            f"          <{'CommandLine' if element == 'SynchronousCommand' else 'Path'}>{escape(command)}"
            f"</{'CommandLine' if element == 'SynchronousCommand' else 'Path'}>\n"
            f"          <Description>{escape(description)}</Description>\n"
            f"          <Order>{order}</Order>\n"
            f"        </{element}>\n"
        )
    return text


# The specialize pass: before the first logon, and before Windows Update or device encryption can start.
specialize = [
    # The VM is restored from its snapshot for every run: updates would only be lost again, and one restarting Windows
    # in the middle of a test run would fail it at random. Only automatic updates: Features on Demand (OpenSSH, WMIC)
    # still come from Windows Update when asked for.
    ("Turn automatic updates off",
     r"reg add HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU /v NoAutoUpdate /t REG_DWORD /d 1 /f"),
]
if uefi:
    specialize += [
        # Windows 11 24H2 encrypts the system drive by itself on a machine with a TPM and Secure Boot. A restored
        # snapshot would then depend on keys in the TPM's state; nothing here needs an encrypted disk.
        ("Prevent device encryption",
         r"reg add HKLM\SYSTEM\CurrentControlSet\Control\BitLocker /v PreventDeviceEncryption /t REG_DWORD /d 1 /f"),
        # The built-in Administrator is disabled on client Windows. The VM's SSH logon is Administrator, as on Server.
        ("Enable the built-in Administrator", r"net user Administrator /active:yes"),
    ]

keys = r"C:\ProgramData\ssh\administrators_authorized_keys"
first_logon = [
    # Windows Server 2025 has OpenSSH Server already (its service stopped); Windows 11 gets it from Windows Update.
    ("Install and start OpenSSH Server",
     ps("if (-not (Get-Service sshd -ErrorAction SilentlyContinue)) { Add-WindowsCapability -Online -Name OpenSSH.Server~~~~0.0.1.0 }; "
        "Set-Service sshd -StartupType Automatic; Start-Service sshd; "
        "if (Get-NetFirewallRule -Name OpenSSH-Server-In-TCP -ErrorAction SilentlyContinue) { Set-NetFirewallRule -Name OpenSSH-Server-In-TCP -Profile Any -Enabled True } "
        "else { New-NetFirewallRule -Name OpenSSH-Server-In-TCP -DisplayName 'OpenSSH SSH Server (sshd)' -Direction Inbound -Protocol TCP -LocalPort 22 -Action Allow -Profile Any }")),
    ("Authorise the host key for the Administrators group",
     ps(r"New-Item -ItemType Directory -Force C:\ProgramData\ssh | Out-Null; "
        f"Set-Content -Encoding ascii -Path {keys} -Value '{pub}'; "
        f"icacls {keys} /inheritance:r /grant Administrators:F /grant SYSTEM:F")),
    # The guest's sshd answers on a loopback port every local user of the KVM host can reach: key only, no password
    # and no keyboard-interactive logons. sshd writes its sshd_config when it first starts, in the first command
    # above. The settings go first: Windows' sshd_config ends in a Match block.
    ("Refuse SSH password logons",
     ps(r"$c = 'C:\ProgramData\ssh\sshd_config'; "
        r"if (-not (Test-Path $c)) { throw 'no sshd_config' }; "
        r"$keep = Get-Content $c | Where-Object { $_ -notmatch '^\s*(PasswordAuthentication|KbdInteractiveAuthentication|ChallengeResponseAuthentication)\s' }; "
        r"Set-Content -Encoding ascii -Path $c -Value (@('PasswordAuthentication no', 'KbdInteractiveAuthentication no', 'ChallengeResponseAuthentication no') + $keep)")),
    ("Use Windows PowerShell as the SSH shell",
     r"reg add HKLM\SOFTWARE\OpenSSH /v DefaultShell /t REG_SZ /d "
     r"C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe /f"),
    ("Restart sshd so it picks up the key and shell", ps("Restart-Service sshd")),
    ("Mark first logon done", ps(r"Set-Content C:\firstlogon-done.txt done")),
]

if uefi:
    disk = """          <CreatePartitions>
            <CreatePartition wcm:action="add"><Order>1</Order><Type>EFI</Type><Size>300</Size></CreatePartition>
            <CreatePartition wcm:action="add"><Order>2</Order><Type>MSR</Type><Size>16</Size></CreatePartition>
            <CreatePartition wcm:action="add"><Order>3</Order><Type>Primary</Type><Extend>true</Extend></CreatePartition>
          </CreatePartitions>
          <ModifyPartitions>
            <ModifyPartition wcm:action="add"><Order>1</Order><PartitionID>1</PartitionID><Format>FAT32</Format><Label>System</Label></ModifyPartition>
            <ModifyPartition wcm:action="add"><Order>2</Order><PartitionID>2</PartitionID></ModifyPartition>
            <ModifyPartition wcm:action="add"><Order>3</Order><PartitionID>3</PartitionID><Format>NTFS</Format><Label>Windows</Label><Letter>C</Letter></ModifyPartition>
          </ModifyPartitions>"""
    partition = 3
    computer = "PFWIN11"
else:
    disk = """          <CreatePartitions>
            <CreatePartition wcm:action="add"><Order>1</Order><Type>Primary</Type><Extend>true</Extend></CreatePartition>
          </CreatePartitions>
          <ModifyPartitions>
            <ModifyPartition wcm:action="add"><Order>1</Order><PartitionID>1</PartitionID><Active>true</Active><Format>NTFS</Format><Label>Windows</Label><Letter>C</Letter></ModifyPartition>
          </ModifyPartitions>"""
    partition = 1
    computer = "PFWS2025"

# The image to install, by its <NAME> in install.wim's XML (read from the pinned ISOs with 7z): Standard Evaluation,
# Server Core (index 1 of 4) on Windows Server 2025.
image = {
    "server2025": "Windows Server 2025 SERVERSTANDARDCORE",
    "win11": "Windows 11 Enterprise Evaluation",
}[os_name]

oobe_client = """
        <HideOnlineAccountScreens>true</HideOnlineAccountScreens>
        <HideLocalAccountScreen>true</HideLocalAccountScreen>
        <HideOEMRegistrationScreen>true</HideOEMRegistrationScreen>
        <HideWirelessSetupInOOBE>true</HideWirelessSetupInOOBE>""" if uefi else ""

pw = escape(password)
arch = 'processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS"'
ns = 'xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State"'

xml = f"""<?xml version="1.0" encoding="utf-8"?>
<unattend xmlns="urn:schemas-microsoft-com:unattend">
  <settings pass="windowsPE">
    <component name="Microsoft-Windows-International-Core-WinPE" {arch} {ns}>
      <SetupUILanguage><UILanguage>en-US</UILanguage></SetupUILanguage>
      <InputLocale>en-US</InputLocale>
      <SystemLocale>en-US</SystemLocale>
      <UILanguage>en-US</UILanguage>
      <UserLocale>en-US</UserLocale>
    </component>
    <component name="Microsoft-Windows-Setup" {arch} {ns}>
      <DiskConfiguration>
        <Disk wcm:action="add">
          <DiskID>0</DiskID>
          <WillWipeDisk>true</WillWipeDisk>
{disk}
        </Disk>
      </DiskConfiguration>
      <ImageInstall>
        <OSImage>
          <InstallFrom>
            <MetaData wcm:action="add"><Key>/IMAGE/NAME</Key><Value>{escape(image)}</Value></MetaData>
          </InstallFrom>
          <InstallTo><DiskID>0</DiskID><PartitionID>{partition}</PartitionID></InstallTo>
        </OSImage>
      </ImageInstall>
      <UserData>
        <AcceptEula>true</AcceptEula>
        <FullName>PHPFinder test</FullName>
        <Organization>PHPFinder test</Organization>
      </UserData>
    </component>
  </settings>
  <settings pass="specialize">
    <component name="Microsoft-Windows-Shell-Setup" {arch} {ns}>
      <ComputerName>{computer}</ComputerName>
      <TimeZone>UTC</TimeZone>
    </component>
    <component name="Microsoft-Windows-Deployment" {arch} {ns}>
      <RunSynchronous>
{synchronous('RunSynchronousCommand', specialize)}      </RunSynchronous>
    </component>
  </settings>
  <settings pass="oobeSystem">
    <component name="Microsoft-Windows-International-Core" {arch} {ns}>
      <InputLocale>en-US</InputLocale>
      <SystemLocale>en-US</SystemLocale>
      <UILanguage>en-US</UILanguage>
      <UserLocale>en-US</UserLocale>
    </component>
    <component name="Microsoft-Windows-Shell-Setup" {arch} {ns}>
      <UserAccounts>
        <AdministratorPassword><Value>{pw}</Value><PlainText>true</PlainText></AdministratorPassword>
      </UserAccounts>
      <AutoLogon>
        <Enabled>true</Enabled>
        <Username>Administrator</Username>
        <Password><Value>{pw}</Value><PlainText>true</PlainText></Password>
        <LogonCount>1</LogonCount>
      </AutoLogon>
      <OOBE>
        <HideEULAPage>true</HideEULAPage>
        <ProtectYourPC>3</ProtectYourPC>{oobe_client}
      </OOBE>
      <FirstLogonCommands>
{synchronous('SynchronousCommand', first_logon)}      </FirstLogonCommands>
    </component>
  </settings>
</unattend>
"""
out.mkdir(parents=True, exist_ok=True)
(out / "autounattend.xml").write_text(xml, encoding="utf-8")
print(f"wrote {out / 'autounattend.xml'}")
