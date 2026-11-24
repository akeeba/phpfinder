# AGENTS.md

## Development Setup

- A focused 2-file library (`src/`). No packaging step and no CI/CD: Packagist installs it from the Git tag.
- **PHP requirement:** >=7.2.0 <8.7 — do not use features unavailable in PHP 7.2 (no typed properties, no union types, no named arguments, no match expressions, no arrow functions, no null-safe operator)

## Tests

Run everything with `composer test` (or `phing test`, which delegates to it): the unit suite, then the
Docker integration suite. Both work on a fresh checkout, given a global `phpunit`.

- **Unit** (`composer test:unit`, `phpunit.xml`, `tests/Unit/`): in-process, against fake PHP binaries
  (shell scripts printing `PHP x.y.z (cli)`) and fake `whereis` / `update-alternatives` / `brew` in a
  temporary directory put first in the `PATH`. Windows' tools (`where`, `wmic`, `fsutil`, PowerShell) run by their
  full paths in the Windows directory, never from the `PATH`: their fakes go in a fake one (`windowsTool()`), which
  `simulateOs()` gives the library through its private `$windowsDirectory`. On Windows a PHP binary must be a `.exe`
  PE image (`isCandidateFile()`); the shell-script fakes are not, so `simulateOs('Windows')` also sets the private
  `$peImageCheck`: a script `fakeExecutable()` (or `fakePhp()`) made counts as a program, one `fakeNonProgramPhp()`
  made does not (and logs it if it ever runs, `notProgramRuns()`), and any other file, e.g. one a test writes by hand,
  gets the real header check (`isPeImage()`), so it is refused unless it has a real PE header. Private candidate builders
  are called through a bound closure. No Docker.
- **Integration** (`composer test:integration` = `tests/Integration/docker/run.sh`,
  `phpunit-integration.xml`, `tests/Integration/`): PHPUnit on the host drives the library inside a
  throwaway container on the shared `akeeba-e2e/php` image, which holds several real PHP CLIs at the
  paths each search strategy looks in. Needs Docker and `../buildfiles`. `run.sh --matrix` runs every
  runner PHP version in `tests/Integration/docker/matrix.env`; `tests/Integration/README.md` explains the
  matrix, the fixtures and the options.
- **Windows** (`composer test:windows` = `tests/Windows/run.sh`, `phpunit-windows.xml`, `tests/Windows/`): **opt-in,
  never part of `composer test` / `phing test`.** PHPUnit on the host drives the library on a real Windows Server 2022:
  the shared IIS test VM of `../buildfiles/vm/iis`, under QEMU/KVM on a Linux KVM host. Give the host with
  `--host=<ssh host>` or `WINDOWS_KVM_HOST` (no default). The guest downloads the pinned PHP builds in
  `tests/Windows/downloads` and runs the suite under each (7.2 to 8.6, 32-bit 7.2 and 8.4 under WoW64, and 7.2 and 8.4 with `cmd.exe`'s delayed
  expansion on) and under IIS (with the 64-bit PHP 8.4, and the 32-bit one). Only one run can use the VM at a time: `run.sh` refuses a running VM and stops the one it started on every exit
  path. Never `build --force` or `destroy` the VM, and never change `../buildfiles` from this project.
  `tests/Windows/README.md` explains the fixtures, the options and what the real Windows tools print.
- **Windows without WMIC** (`tests/Windows/run.sh --vm=server2025` or `--vm=win11`): **opt-in too.** The same suite, on
  the CLI runners only, on this project's own VMs (`tests/Windows/vm/pfvm.sh`, modelled on `../buildfiles/vm/iis`):
  Windows Server 2025 Standard (Server Core) and Windows 11 Enterprise, evaluation editions, with Microsoft's WMIC
  Feature on Demand removed, and no IIS. The first run builds the VM on the KVM host (Windows 11 needs `swtpm` and
  OVMF with Secure Boot there; 4 CPUs and 8 GB of memory while one runs, about 11 GB (Server) and 20 GB (Windows 11)
  of disk with the snapshot, 11 GB of cached ISOs); `pfvm.sh` rebuilds it when its evaluation licence (180 days for
  Server, 90 for Windows 11) is about to expire. They may be rebuilt (`pfvm.sh --os=… build --force`) when their
  tooling changes.
  `FactsTest::WMIC` records what each VM's WMIC is; `tests/Windows/README.md`, "The VMs without WMIC", the rest.
- PHPUnit is the **global** `phpunit`: never add `phpunit/phpunit` to `composer.json`. `config.platform.php` is
  `7.2.999` (the minimum supported series, as in the other projects), and `composer.lock` is tracked but holds no
  packages. The test code must run on every PHPUnit from 8.5 to 12: no data-provider annotations or attributes,
  no assertions newer than 8.5 (e.g. no `assertFileDoesNotExist()`), and
  `phpunit*.xml` limited to what PHPUnit 8.5 knows. Pass extra PHPUnit arguments after `--`, e.g.
  `composer test:unit -- --filter Regression`.
- `tests/bootstrap.php` honours `PHPFINDER_SRC`, to run the suite against another copy of `src/`, e.g.
  one exported with `git show <rev>:src/…` to show a regression test failing on the code before a fix.
- **Known bugs** in `src/` that are not fixed yet are tested through `expectKnownBug()`
  (`tests/Support/KnownBug.php`): the assertions state the correct behaviour, the test is reported as
  incomplete while the bug exists, and fails once it is fixed so the wrapper gets removed. Never assert
  buggy behaviour as correct.
- Nothing under `tests/`, nor `phpunit*.xml` or `build.xml`, may ship: they are `export-ignore` in
  `.gitattributes` and in `composer.json`'s `archive.exclude`. Add any new development-only file to both.

## Code Conventions

- **Indentation:** Tabs
- **Both classes are `final`** — never remove this
- **Error suppression:** `@` operator is used intentionally on file/exec operations — follow this pattern
- **Return NULL for not-found** — not exceptions
- **Cross-platform paths:** Always use `DIRECTORY_SEPARATOR` and `PATH_SEPARATOR` constants
- **PHPDoc:** All public members have `@since` tags; use `@since 1.0.0` for existing API surface
- **Version format:** major.minor (e.g., "8.3"), not just major

## Git: commit and tag outside the sandbox

Commits and tags are always signed, with a key held in 1Password. The 1Password signing agent is reached
over a local socket that agent sandboxes do not expose, so a sandboxed `git commit` or `git tag` **always**
fails (e.g. `error: 1Password: Could not connect to socket. Is the agent running?`).

Run every `git commit` and `git tag` **outside the sandbox from the first attempt** — in Claude Code with
`dangerouslyDisableSandbox: true`, in other harnesses with their equivalent unsandboxed / escalated
execution. Do not try the sandboxed form first, do not diagnose the failure, and never work around it
with `--no-gpg-sign`, `-c commit.gpgsign=false` or unsigned tags.

## Project memory

Project memory lives in `.claude/memory/`, committed with the code, so that it is shared across machines
and across agentic harnesses (Claude Code, Codex, Qwen Code, Kimi Code, Junie, …). There are no memory
files yet. When there is something worth remembering, create `.claude/memory/`, the topic file, and a
table here mapping each file to a concrete trigger ("Before you… | Read").

### Recording new memories

This is the **default and only** place for project memory. Do not write memories for this project to a
harness's private memory store (such as Claude Code's auto-memory under `~/.claude/projects/`); write
them here instead:

- Add to the existing topic file when one fits; otherwise create a new kebab-case `.md` file named after
  the topic, and add a row for it to the table above with a concrete trigger.
- Plain Markdown, no frontmatter. State the rule, then **Why:** (the reason or incident behind it) and
  **How to apply:**. Link related files with relative Markdown links.
- Don't record what the code, Git history or an existing `AGENTS.md` already says — update that
  `AGENTS.md` instead when the rule belongs there. Remove or correct entries that turn out wrong.
- These files are committed: no secrets, credentials, customer data or personal details.
