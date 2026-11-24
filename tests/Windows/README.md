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
tests/Windows/run.sh --host=<ssh host>             # every runner: PHP 7.2 … 8.6, then IIS
WINDOWS_KVM_HOST=<ssh host> composer test:windows  # the same
tests/Windows/run.sh --host=<ssh host> --runner=7.2,iis --filter DrivesTest
```

There is no default host: without `--host` or `WINDOWS_KVM_HOST`, `run.sh` stops with its usage.

## `run.sh` options

| Option | What it does |
|---|---|
| `--host=HOST` | The KVM host (an SSH destination, or `local`). Default: `WINDOWS_KVM_HOST` |
| `--runner=LIST` | Only these runners, comma or space separated: `7.2 8.0 8.1 8.2 8.3 8.4 8.5 8.6 iis` (default: all) |
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
   - installs the Visual C++ 14.51 runtime (PHP 8.6's `vs18` build refuses the VM's 14.44 one) and unpacks one PHP per
     version into `C:\pf\run\<X.Y>`, where no search strategy looks: the **runners**;
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
| `C:\Program Files (x86)\Plesk\Additional\PHP8.3\php.exe` | 8.3 | Plesk, with `plesk_dir` set as Plesk does (lower case, trailing backslash). The layout is the one the code builds; it is **not confirmed** against a real Plesk for Windows |
| `C:\pf\path-a\php.exe` | 8.1 | `where`; only in the `PATH` when a test puts it there |
| `C:\pf\path-b\php.exe`, `C:\pf\path-b\php82.exe` | 8.2 | `where` listing several binaries, and a versioned name |
| `C:\pf\path-cgi\php.exe` | 8.3 **php-cgi.exe** | `validateCli` (it reports `(cgi-fcgi)`) |
| `C:\pf\odd %PF_UNDEFINED% & (x) !bang ^caret\php.exe` | 8.5 | running a path with the characters `cmd.exe` treats specially |
| `C:\pf\odd %OS%\php.exe` | 8.5 | the same, with the name of a defined environment variable |
| `C:\pf\fake\broken\php.exe` | none | a `php.exe` which is not a program |
| `C:\pf\fake\*\wmic.bat`, `fsutil.bat`, `powershell.bat` | none | tools which exist but fail (see `DrivesTest`) |

PHPFinder checks that a tool exists by looking for `<name>.exe` in the `PATH` (PHP's `is_executable()` is FALSE for a
`.bat` file), and runs it by name through `cmd.exe`, which runs the first `<name>.bat` or `<name>.exe` in the `PATH`. So a
`.bat` stand-in before the system directories in the `PATH` replaces a tool which really exists. A Windows without
`wmic` (Windows 11 24H2, Windows Server 2025) is a `PATH` without `C:\Windows\System32\Wbem`.

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
- PowerShell's `[IO.DriveInfo]::GetDrives()` takes 1.5 to 2 seconds to start.

## The PHP versions, and why

`composer.json` allows PHP `>=7.2.0 <8.7`. The runners are every minor version from **7.2** (the floor; PHP 7 runs
`exec()` through `cmd.exe /c`, PHP 8 through `cmd.exe /s /c`, and `asort()` is not stable before 8.0) through **8.6** (the
newest), except 7.3 and 7.4, plus **IIS** (FastCGI, PHP 8.4), which is how a web application runs the library. PHP 8.0
is covered here although the Docker suite cannot cover it. All are 64-bit non-thread-safe builds; thread-safe and 32-bit
builds are not tested.

Each PHP version downloads 25 to 40 MB per run (there is no cache across runs), all in parallel: a few seconds on a fast
connection.

## Known bugs

Tests for bugs in `src/` which are not fixed yet assert the **correct** behaviour through `expectKnownBug()`
(`tests/Support/KnownBug.php`), as in the other suites.

## Not covered

- Plesk for Windows' real directory layout (see the fixture table).
- Windows 11 and Windows Server 2025 themselves: their missing `wmic` is simulated through the `PATH`.
- A server whose system drive is not `C:`.
