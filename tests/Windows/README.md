# PHPFinder Windows tests

**Opt-in.** Not part of `composer test` or `phing test`: it needs a Linux machine with KVM, and takes a few minutes.

PHPUnit runs on the host. PHPFinder runs on a **real Windows**: the shared IIS test VM of
[akeeba/buildfiles](https://github.com/akeeba/buildfiles) (`../buildfiles/vm/iis`, Windows Server 2022 Core with IIS 10
and PHP 8.4), under several real PHP CLIs and under IIS. Every test asks `guest/probe.php`, in the guest, to run the
library with a given configuration, version, `PATH` and environment, and asserts on what it returns, including any PHP
warning or deprecation it raised, and anything it printed on STDERR.

The Docker integration suite (`tests/Integration`) cannot do this: it runs Linux. The unit suite simulates Windows on
whatever the host runs, with shell-script fakes of `where`, `wmic` and `fsutil`; this suite is where those fakes' formats
and the Windows code paths are checked against the real thing.

## Requirements

- A **KVM host**: a Linux machine with a usable `/dev/kvm`, reached over SSH with key authentication (or `local`, when
  you run this on such a machine). `../buildfiles/vm/iis/README.md` lists what it needs; the first run there builds the
  VM (about 10 minutes, plus 5 GB of downloads). A Mac cannot be a KVM host, but can drive a remote one.
- `../buildfiles` checked out next to this repository.
- PHP and a global `phpunit` on this machine (as for the other suites).
- The guest needs Internet access: it downloads the PHP builds in `downloads` on every run.

## Quick start

```bash
tests/Windows/run.sh --host=<ssh host>             # every runner: PHP 7.2 … 8.6, 32-bit 7.2 and 8.4, -dx, then IIS
WINDOWS_KVM_HOST=<ssh host> composer test:windows  # the same
tests/Windows/run.sh --host=<ssh host> --runner=7.2,iis --filter DrivesTest
```

There is no default host: without `--host` or `WINDOWS_KVM_HOST`, `run.sh` stops with its usage.

## `run.sh` options

| Option | What it does |
|---|---|
| `--host=HOST` | The KVM host (an SSH destination, or `local`). Default: `WINDOWS_KVM_HOST` |
| `--runner=LIST` | Only these runners, comma or space separated: `7.2 8.0 8.1 8.2 8.3 8.4 8.5 8.6 7.2-x86 8.4-x86 7.2-dx 8.4-dx iis` (default: all) |
| `-f`, `--filter=NAME` | Passed to PHPUnit as `--filter` |
| `--src=DIR` | Put this directory's `PHPFinder.php` and `Configuration.php` into the guest instead of `src/`, e.g. a revision exported with `git show <rev>:src/…`, to show a test failing on the code before a fix |
| `--keep-vm` | Leave the VM running afterwards, to iterate with `PHPFINDER_WINDOWS_HOST=HOST PHPFINDER_WINDOWS_RUNNER=8.5 phpunit -c phpunit-windows.xml --filter …` |
| `--down` | Stop the VM and exit |
| `-- <args>` | Anything after `--` goes to PHPUnit |

**One run at a time.** The VM is shared by every project on that KVM host. `run.sh` refuses to start when it is already
running (another run is using it), and stops the VM it started on every exit path: success, failure, an error in the
script, Ctrl-C or a kill. Only `--keep-vm` leaves it running; stop it with `--down` when you are done. Every start
restores the VM's clean snapshot, so nothing a run did survives it, and nothing can be cached in the guest.

## How it works

1. `iisvm.sh start` restores the snapshot and boots the VM.
2. `run.sh` copies `src/` and `guest/` into `C:\pf` and runs `guest/provision.ps1` there, which
   - downloads everything in `downloads` in parallel with `curl.exe`, and checks each file's SHA-256 with
     `Get-FileHash` (the pins are the hashes of the bytes actually downloaded; see the comments in that file);
   - installs the Visual C++ runtime from Visual Studio 2026 (its `vcruntime140.dll` reports 14.52.37029), x64 (PHP
     8.6's `vs18` build, linked with 14.51, refuses the VM's 14.44 one) and x86 (for the 32-bit runners), and unpacks
     one PHP per version into `C:\pf\run\<X.Y>`, where no search strategy looks: the **runners**;
   - attaches a VHD as **`D:`** (see "A second drive" below);
   - copies the top-level files of real PHP builds (`php.exe`, `php-cgi.exe`, the DLLs; no extensions, everything runs
     with `-n`) to every location a search strategy looks in: the **fixtures**, each checked by running it;
   - makes IIS run `pf-probe.php` (`guest/iis-probe.php`) with one `php-cgi.exe` per request.
3. PHPUnit runs once per runner. The suite keeps one SSH session open to `guest/relay.php` for the whole run (each SSH
   round trip takes about two seconds); the relay runs `C:\pf\run\<X.Y>\php.exe -n C:\pf\probe.php <request>` for each
   test, without a shell, in an empty working directory, and sends back its STDOUT and STDERR apart. For the `iis`
   runner it requests `http://localhost/pf-probe.php?r=<request>` instead: IIS runs the library as a web site's PHP
   does, through FastCGI, as the site's identity.

| Fixture | Real PHP | Exercises |
|---|---|---|
| `C:\PHP\php.exe` | 8.4 (the VM's own `C:\php`) | the common directories: `<drive>\PHP`, scanned one level deep |
| `C:\PHP83\php.exe` | 8.3 | the versioned common directories (`<drive>\PHP<XY>`), scanned first |
| `C:\PHP82\php.exe` | **8.3** | `validateVersion` (a directory named for the wrong version) |
| `C:\Program Files\PHP\v8.2\php.exe` | 8.2 | `Program Files\PHP` one level deep; a path with a space |
| `C:\Program Files (x86)\PHP8.1\php.exe` | 8.1 | the dotted versioned directory under `Program Files (x86)`; parentheses |
| `D:\PHP85\php.exe` | 8.5 | the second drive |
| `C:\wamp64\bin\php\php8.0.30\php.exe`, `…\php7.2.34\php.exe` | 8.0, 7.2 | WAMPServer |
| `C:\MAMP\bin\php\php8.6.0\php.exe` | 8.6 | MAMP on Windows |
| `C:\xampp\php\php.exe` | 7.2 | XAMPP |
| `C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe` | 8.3 | Plesk, with `plesk_dir` set as Plesk does (lower case, trailing backslash): the layout of Plesk Obsidian (and 12.x) |
| `C:\Program Files (x86)\Plesk\Additional\PHP82\php.exe` | 8.2 | Plesk: the layout the documentation of older releases gives |
| `C:\pf\path-a\php.exe` | 8.1 | `where`; only in the `PATH` when a test puts it there |
| `C:\pf\path-b\php.exe`, `C:\pf\path-b\php82.exe` | 8.2 | `where` listing several binaries, and a versioned name |
| `C:\pf\path-cgi\php.exe` | 8.3 **php-cgi.exe** | `validateCli` (it reports `(cgi-fcgi)`) |
| `C:\pf\path-ext\php.exe`, with `php.bat`, `php.cmd`, `php.com` and an extensionless `php` | 8.2; the `.bat` and `.cmd` print a PHP 8.1 banner, `php.com` is a copy of `php.exe`, `php` a shell script | `where` listing every `PATHEXT` match: only `.exe` files are kept, even when another is a program |
| `C:\pf\odd %PF_UNDEFINED% & (x) !bang ^caret\php.exe` | 8.5 | running a path with the characters `cmd.exe` treats specially |
| `C:\pf\odd %OS%\php.exe` | 8.5 | the same, with the name of a defined environment variable |
| `C:\pf\odd & (x) !bang ^caret\php.exe` | 8.5 | the same characters without a `%`, through `exec()` (`ExecFallbackTest`) |
| `C:\pf\odd Windows_NT\php.exe` | **8.3** | what `C:\pf\odd %OS%\php.exe` becomes when `cmd.exe` expands `%OS%`: another binary |
| `C:\pf\odd & (x) ^caret\php.exe` | 8.5 | the same characters without a `%` or a `!`: through `exec()` with delayed expansion on too |
| `C:\pf\odd !OS!\php.exe` | 8.5 | what `cmd.exe` turns into `C:\pf\odd Windows_NT\php.exe` when its delayed expansion is on (`ExecFallbackTest`, the `-dx` runners) |
| `C:\pf\pe-names\php.bat`, `php.cmd`, `php.com` | 8.1 | real programs under the names of scripts, and no `php.exe`: never returned or run (`NonProgramsTest`) |
| `C:\pf\fake\broken\php.exe` | none | a `php.exe` which is not a program (a text file): never a candidate, nor run when `analyzePHPVersion()` is given it directly |
| `C:\pf\fake\zero\php.exe` | none | an empty `php.exe`: never a candidate |
| `C:\pf\fake\nodll\php.exe` | 8.4, alone | a program which stops at once (without its DLLs: `STATUS_DLL_NOT_FOUND`): `StderrTest`, `ExecFallbackTest` |
| `C:\pf\fake\stderr\php.exe` | 8.4 | prints a startup warning on STDERR (its `php.ini` loads an extension which does not exist): `StderrTest`, `ExecFallbackTest` |

`NonProgramsTest` also writes a `php.exe` which is not a program (a text file, an empty file, and a bare MS-DOS
header) in every location a search strategy looks in, for PHP 8.9, for the duration of a test: the relay's `plant`
request, which never overwrites a fixture. They go in `C:\pf\not-a-program` (in the `PATH`), `C:\PHP89`,
`C:\PHP\not-a-program`, `C:\Program Files\PHP\not-a-program`, `C:\Program Files (x86)\PHP8.9`, `D:\PHP89`,
`C:\wamp64\bin\php\php8.9.0`, `C:\MAMP\bin\php\php8.9.0`, `D:\xampp\php` (XAMPP has one path per drive, and `C:`'s
holds PHP 7.2) and Plesk's `Additional\PleskPHP89` and `Additional\PHP89`.

Plesk's layout on Windows: its documentation ("Running PHP Scripts from the Command Line", the Onyx and Obsidian
editions alike) lists `%plesk_dir%Additional\PHP<XY>\php.exe` for PHP 5.3 to 7.0; Plesk Obsidian installs
`%plesk_dir%Additional\PleskPHP<XY>\php.exe` (e.g. `PleskPHP81`, `PleskPHP83`: Plesk's support articles and staff
answers on its forum), and Plesk 12 already used `PleskPHP54`. Neither has a dot. There is no real Plesk in the guest.

### Stand-ins for Windows' tools

PHPFinder runs `where`, `wmic`, `fsutil` and PowerShell from the Windows directory (`SystemRoot`), by their full paths:
`System32\where.exe`, `System32\wbem\WMIC.exe`, `System32\fsutil.exe`,
`System32\WindowsPowerShell\v1.0\powershell.exe`, through `System32\cmd.exe /d /s /c` (see "What the real Windows
prints" for why). The `PATH` does not matter to them, so the tests change what runs in two ways:

- **A Windows without `wmic`** (Windows 11 24H2, Windows Server 2025), or without `fsutil`, is the real one with
  `WMIC.exe` or `fsutil.exe` renamed for the duration of a test: `hideTools()` asks `relay.php`, which runs as
  Administrator, to rename them (`provision.ps1` takes them over from TrustedInstaller first), and every such test puts
  them back in `tearDown()`. The next start of the VM restores its snapshot anyway. Both copies: `System32`'s and
  `SysWOW64`'s, which is what a 32-bit runner sees at `System32`. `hideToolsAndCheck()` then asks the runner whether
  it still sees them where the library looks (the probe's `tools` call): hiding only `System32`'s let the 32-bit
  runners use `SysWOW64`'s `wmic`, and the tests of a Windows without it tested nothing.
- **Tools which misbehave** are stand-ins in a Windows directory of their own, `C:\pf\fakewin\<case>`, which the test
  gives to the library instead of `C:\Windows`, through PHPFinder's private `$windowsDirectory` (the probe sets it,
  as the unit tests do), with a copy of Windows' own `cmd.exe` in its `System32`. Not through `SystemRoot`: the
  programs the library starts need the real one.

The stand-ins are one small program, `C:\pf\fake\fake-tool.exe`, which `provision.ps1` compiles with .NET Framework's
C# compiler (`Add-Type -OutputType ConsoleApplication`) and copies under each tool's name. It prints the bytes of
`<name>.stdout` next to it, exits with the number in `<name>.exitcode` (or 0), and appends its command line to
`<name>.ran`, so that a test can tell it ran (the probe returns the `.ran` files of its working directory as `ran`):

| Directory | Stand-ins | Used by |
|---|---|---|
| `C:\pf\fakewin\wmic-none` | a `wmic` which lists no drive, an `fsutil` which lists them | `DrivesTest` |
| `C:\pf\fakewin\wmic-error` | a `wmic` which prints an error on STDOUT, an `fsutil` which lists the drives | `DrivesTest` |
| `C:\pf\fakewin\fsutil-error` | no `wmic`, an `fsutil` which prints an error, a PowerShell which lists the drives | `DrivesTest` |
| `C:\pf\fakewin\all-fail`, `C:\pf\fakewin\empty` | every tool failing; no tool at all | `DrivesTest` |
| `C:\pf\fakewin\dx !OS!`, `C:\pf\fakewin\dx Windows_NT` | a `where` which finds nothing; one which finds `C:\pf\path-a\php.exe`, in what `cmd.exe` makes of the first path with delayed expansion on | `ExecFallbackTest` |
| `C:\pf\planted` | `where.exe`, `wmic.exe`, `fsutil.exe`, `powershell.exe`, printing nothing: a working directory | `WorkingDirectoryTest`, `ExecFallbackTest` |
| `C:\pf\planted-cmd` | `cmd.exe`, printing nothing: a working directory | `WorkingDirectoryTest` |

### `proc_open()` disabled

Some hosts disable `proc_open()`; PHPFinder then falls back to `exec()`, which runs everything through `cmd.exe`.
`ExecFallbackTest` asks the relay to run the probe with `-d disable_functions=proc_open` (`"noProcOpen"`), on every CLI
runner; IIS's `php.ini` cannot change per request, so it skips there.

### `cmd.exe`'s delayed expansion

With `DelayedExpansion` set to 1 under `HKLM` or `HKCU\Software\Microsoft\Command Processor`, `cmd.exe` expands
`!NAME!` like `%NAME%`, even between double quotes, and drops a lone `!` (`/d` does not stop it; `/v:off` does, but PHP's
`exec()` cannot pass it, and a `cmd.exe /v:off` inside `exec()`'s comes too late: the outer one has expanded the line
already). The **`7.2-dx`** and **`8.4-dx`** runners are PHP 7.2 and 8.4 with it on: the relay sets it, in both hives,
for each request, and deletes it afterwards (the VM's snapshot restores the registry anyway). Their `cmd.exe` turned
`C:\pf\odd !OS!\php.exe` into `C:\pf\odd Windows_NT\php.exe` (PHP 8.3) through `exec()`, and ran the `where` of
`C:\pf\fakewin\dx Windows_NT` for that of `C:\pf\fakewin\dx !OS!` through the tools' `cmd.exe`, until PHPFinder stopped
running any path with a `!` through `cmd.exe` (`ExecFallbackTest`). `ExecFallbackTest::testDelayedExpansionIsOnOnlyForItsRunners`
checks that `echo !OS!` prints `Windows_NT` on those runners only. Not under IIS: what IIS runs reads `HKLM` (and the
application pool identity's own `HKCU`), and `proc_open()` cannot be disabled there anyway.

## A second drive

`SUBST` drives exist only in the logon session which made them: every SSH command is a new one, and IIS is another, so
neither sees it. `New-PSDrive` drives exist only inside PowerShell. A VHD made and attached with `diskpart`
(`create vdisk`, `attach vdisk`, `assign letter=D`) is a real volume until the next reboot, which `wmic`, `fsutil`, .NET
and IIS all see; `New-VHD` would need the Hyper-V module, which Server Core does not have. The guest also has an empty
CD drive, `E:`, so the drive list is `C:`, `D:`, `E:`.

## What the real Windows prints

`FactsTest` checks these, byte for byte, as PHP's `exec()` sees them (Windows Server 2022, build 20348):

- `wmic` is `C:\Windows\System32\Wbem\WMIC.exe`, in the default `PATH`. `wmic logicaldisk get name` through a pipe
  prints ASCII (not UTF-16): `Name  `, then each drive padded to the column width (`C:    `), every line ending in
  `\r\r\n`, and a blank line at the end. `exec()` strips the trailing whitespace, so its lines are `Name`, `C:`, `D:`,
  `E:`, `''`. With no matching instance, STDOUT gets only blank lines and `No Instance(s) Available.` goes to STDERR.
- `fsutil fsinfo drives` prints `Drives: C:\ D:\ E:\` (preceded by a blank line in an SSH session, not under IIS). It
  works without elevation: as the IIS site's identity too.
- `where php` prints each match on its own line, `\r\n`, in `PATH` order, exit code 0. Without a match: nothing on
  STDOUT, `INFO: Could not find files for the given pattern(s).` on STDERR, exit code 1. It searches the current
  directory before the `PATH`.
- `where php` lists every file named `php` with an extension in `PATHEXT` (`.COM;.EXE;.BAT;.CMD;…`) **and** an
  extensionless `php`, in each directory's order (alphabetical), not `PATHEXT`'s: `php`, `php.bat`, `php.cmd`, `php.com`,
  `php.exe`. PHP's `is_executable()` is `GetBinaryTypeW()` (`access()` with `X_OK` in PHP's Windows I/O layer): TRUE
  for a program file whatever its name (a copy of `php.exe` named `php`, `php.com`, `php.bat` or `php.cmd`), FALSE for
  a `.bat`, a `.cmd` or an extensionless script and for an empty `php.exe`, but TRUE for a text file named `php.exe`
  and for a file with nothing but an `MZ` header, on 7.2 to 8.6 (`FactsTest`; the last one by hand). A `.bat` which
  `proc_open()` starts with `bypass_shell` runs through the `cmd.exe` which `%ComSpec%` names: not one in the working
  directory or first in the `PATH` (checked by hand, 7.2 and 8.4).
- A real program named like a script (PHP 8.1's `php.exe` copied as `php.bat`, `php.cmd`, `php.com`): Windows itself
  (`CreateProcessW()`, what `proc_open()` with `bypass_shell` calls) runs each as the program it is, whatever its
  name, and `%ComSpec%` plays no part (pointed at a stand-in, it did not run; it does for a real `.bat`). `cmd.exe`
  (`exec()`, `proc_open()` without `bypass_shell`, `System32\cmd.exe /c`) goes by the extension: `php.com` runs, while
  `php.bat` and `php.cmd` are read as batch scripts: it echoes the binary's first "line" and fails with `'MZ…' is not
  recognized as an internal or external command`, exit code 1 (`FactsTest`; 7.2 and 8.4 by hand, with `%ComSpec%`
  pointed elsewhere too). So the same `php.bat` is PHP when given to `proc_open()` and garbage through `exec()`:
  PHPFinder takes neither (only `.exe` PE images, `NonProgramsTest`). Under IIS (`fastcgi.impersonate=1`), a `cmd.exe`
  which `proc_open()` started runs no batch script at all, a real one neither: exit code 1, nothing printed; `exec()`
  runs the real one (checked by hand; that the token described below is why is a guess, not checked).
- `cmd.exe` looks a bare command name (`where`, `wmic`, …) up in the current directory before the `PATH`, and PHP's
  `exec()` starts `cmd.exe` itself by its bare name, which Windows also looks for in the current directory first: a
  `cmd.exe` in the working directory runs instead of Windows' own (`WorkingDirectoryTest`).
- Under IIS, with `fastcgi.impersonate=1` (which the VM's `C:\php\php.ini` sets, as `php.ini-development` advises for
  IIS; PHP's default is 0), a program which PHP's `proc_open()` starts directly (`bypass_shell`) fails: PowerShell
  with `Internal Windows PowerShell error. Loading managed Windows PowerShell failed with error 80070005.`, `whoami`
  with `ERROR: Access is denied.`, while `where`, `wmic`, `fsutil` and `php.exe -v` work. Started by
  `System32\cmd.exe /d /s /c`, PowerShell and `whoami` work too (`whoami` prints `iis apppool\defaultapppool`). With
  `fastcgi.impersonate=0` both work either way: the impersonation is what triggers the failure. This was checked by
  hand, not by `FactsTest`: the same script under IIS, `fastcgi.impersonate` switched from 1 to 0 and back, with
  `iisreset` in between, failed at 1 both times and worked at 0. Hence `cmd.exe` for the tools.

  Why, also checked by hand (the security descriptors read as SYSTEM, from a scheduled task, while the children ran):
  `proc_open()` calls `CreateProcessW()` (`ext/standard/proc_open.c`) from the FastCGI thread, which impersonates the
  site's anonymous user, `IUSR`. The child runs as the application pool's identity (`IIS APPPOOL\DefaultAppPool`), with
  the same process and thread DACLs as any other child, but its primary token's own security descriptor is `IUSR`'s:
  owner `IUSR`, full access for `SYSTEM` and `IUSR` only (`O:S-1-5-17D:(A;;…;;;SY)(A;;…;;;S-1-5-17)(A;;SW;;;BA)`). A
  program which opens its own token is refused: `whoami`, PowerShell (`80070005` is `E_ACCESSDENIED`), and also `ping`
  (`Unable to contact IP driver. General failure.`) and `tasklist` (`Not enough memory resources…`); `where`, `fsutil`,
  `php.exe`, `cmd.exe`, `sort`, `findstr`, `certutil`, `hostname` and a .NET console program (the tools' stand-in) work.
  `cmd.exe` started that way has the same token, but does not open it, and does not impersonate: the programs it starts
  get a token owned by, and open to, the application pool's identity. With the anonymous user set to the application
  pool's identity (`fastcgi.impersonate=1` still), the direct child's token is the application pool's, and `whoami` and
  PowerShell work directly too, which singles the token's descriptor out as the cause. That those programs fail at the
  very call which opens their own token is inferred from the descriptor and their messages, not traced.

  `exec()` takes another way (`popen_ex()` in `TSRM/tsrm_win32.c`): `CreateProcessAsUserW()` with a primary token made
  from the thread's impersonation token, so under IIS with `fastcgi.impersonate=1` what it runs runs as `IUSR`
  (`whoami` prints `nt authority\iusr`), while what `proc_open()` runs runs as the application pool's identity.
- PowerShell's `[IO.DriveInfo]::GetDrives()` takes 1.5 to 2 seconds to start.
- A 32-bit PHP runs under WoW64 (the guest has the `WoW64-Support` feature): `PHP_INT_SIZE` is 4,
  `PROCESSOR_ARCHITECTURE` is `x86` and `PROCESSOR_ARCHITEW6432` is `AMD64` (unset in a 64-bit process), and it sees
  `C:\Windows\SysWOW64` at `C:\Windows\System32`. `SysWOW64` has a 32-bit copy of every tool PHPFinder runs, `cmd.exe`
  included, and they print the same as the 64-bit ones: the library works unchanged (the whole suite on `7.2-x86` and
  `8.4-x86`). Only a 32-bit process sees `C:\Windows\Sysnative`, the real `System32`. Checked by hand, not by
  `FactsTest`: from a 32-bit PHP, `Sysnative\fsutil.exe` started directly or through the 32-bit `System32\cmd.exe`
  works, and so does the 64-bit `Sysnative\cmd.exe` running `System32\fsutil.exe` (it sees the real `System32`), but
  `Sysnative\cmd.exe` running `Sysnative\…` fails (`The system cannot find the path specified.`): a 64-bit `cmd.exe`
  does not see `Sysnative`.

## The PHP versions, and why

`composer.json` allows PHP `>=7.2.0 <8.7`. The runners are every minor version from **7.2** (the floor; PHP 7 runs
`exec()` through `cmd.exe /c`, PHP 8 through `cmd.exe /s /c`, and `asort()` is not stable before 8.0) through **8.6** (the
newest), except 7.3 and 7.4, plus **IIS** (FastCGI, PHP 8.4), which is how a web application runs the library. PHP 8.0
is covered here although the Docker suite cannot cover it. All are 64-bit non-thread-safe builds, except the two
32-bit (x86) ones, **7.2-x86** (the floor) and **8.4-x86** (a current release), which run under WoW64 (see "What the
real Windows prints"). **7.2-dx** and **8.4-dx** are the 7.2 and 8.4 runners with `cmd.exe`'s delayed expansion on
(see "`cmd.exe`'s delayed expansion"). Thread-safe builds, and IIS running a 32-bit PHP (an application pool with 32-bit
applications enabled), are not tested.

Each PHP version downloads 25 to 40 MB per run (there is no cache across runs), all in parallel: a few seconds on a fast
connection.

## Known bugs

Tests for bugs in `src/` which are not fixed yet assert the **correct** behaviour through `expectKnownBug()`
(`tests/Support/KnownBug.php`), as in the other suites.

## Not covered

- A real Plesk for Windows: its layout comes from its documentation and support material (see the fixture table).
- Windows 11 and Windows Server 2025 themselves: their missing `wmic` is the real one renamed (both copies, assuming
  Windows removes the 32-bit one with the 64-bit one).
- A server whose system drive is not `C:`.
