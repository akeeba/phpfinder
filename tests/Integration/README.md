# PHPFinder integration tests

PHPUnit runs on the host. PHPFinder runs inside a throwaway Docker container which holds several **real**
PHP CLIs, each placed where one of PHPFinder's search strategies looks. Every test asks
`fixture/probe.php`, inside the container, to run the library with a given configuration, version and
`PATH`, and asserts on what it returns, including any PHP warning or deprecation it raised.

## Requirements

- Docker with the Compose plugin (`docker compose`).
- [akeeba/buildfiles](https://github.com/akeeba/buildfiles) checked out next to this repository
  (`../buildfiles`). Its `docker/e2e/images.sh` builds the shared `akeeba-e2e/php:<version>` images on
  first use; this suite never builds an image of its own.
- PHP and Composer on the host. `composer install` provides PHPUnit (`vendor/bin/phpunit`).

## Quick start

```bash
composer test:integration                  # the day-to-day runner (PHP 8.5)
tests/Integration/docker/run.sh --matrix   # every runner version in docker/matrix.env
composer test                              # unit suite, then this one
```

## `run.sh` options

| Option | What it does |
|---|---|
| `-p`, `--php=VERSION` | Use this runner PHP instead of `PHP_VERSION` from `docker/matrix.env` |
| `--matrix` | Run once per version in `PHP_MATRIX`, then report which ones failed |
| `-f`, `--filter=NAME` | Passed to PHPUnit as `--filter` |
| `--keep-containers` | Leave the stack up afterwards; then iterate with `vendor/bin/phpunit -c phpunit-integration.xml --filter …` |
| `--no-tests` | Provision the stack and stop (it stays up) |
| `--down` | Tear everything down and exit |
| `-- <args>` | Anything after `--` goes to PHPUnit |

There is no `--skip-build`: nothing is built. The library is bind-mounted from `src/`.

Every run scrubs any previous stack first, and tears the stack down on every exit path: success, failure,
an error in the script, Ctrl-C or a kill. Only `--keep-containers` and `--no-tests` leave it up.

## How the container gets several PHP versions

The shared images hold exactly one PHP each. `docker/harvest.sh` runs once per fixture version
(`DONOR_VERSIONS` in `docker/matrix.env`) in that version's image, and copies its `php` and `php-fpm`
binaries, every shared library they link against and **their own dynamic loader** into a volume. The
container under test runs them as `<copy>/ld.so --library-path <copy>/lib <copy>/php -n`, through small
wrapper scripts which `docker/provision.sh` installs at the paths listed in `fixture/fixtures.txt`. Using
the binary's own loader and libraries makes it independent of the runner image's Debian release, so an
8.4 built on Debian 13 runs fine in the 7.4 image (Debian 11). `-n` skips `php.ini`, because the runner's
`conf.d` loads extensions built for the runner's PHP.

`<path> -v` therefore prints the real banner of a real PHP build, e.g. `PHP 8.4.27 (cli) (built: …)`, and
`/usr/bin/php83` really is PHP-FPM 8.3 (`(fpm-fcgi)`).

| Fixture | Real PHP | Exercises |
|---|---|---|
| `/opt/pf-which/bin/php82` | 8.2 CLI | `which`; only in the `PATH` when a test adds `/opt/pf-which/bin` |
| `/usr/local/games/php82` | 8.2 CLI | `whereis` (in its search list, not in the `PATH`) |
| `/usr/bin/php8.2` | 8.2 CLI | the Debian/Ubuntu dotted name (fixed UNIX paths, `which`); the Plesk candidates |
| `/opt/bin/php83` | 8.3 CLI | the fixed UNIX paths (`/opt/bin` is not in the `PATH`) |
| `/usr/bin/php83` | 8.3 FPM | `validateCli` |
| `/opt/bin/php84` | **8.2** CLI | `validateVersion` (a binary named for the wrong version) |
| `/usr/local/bin/php84-cli` | 8.4 CLI | `extendedBinaryNameSearch` |
| `/opt/cpanel/ea-php84/root/usr/bin/php` | 8.4 CLI | cPanel |
| `/opt/cpanel/ea-php80/root/usr/bin/php` | **8.2** CLI | cPanel's path for PHP 8.0 (no 8.0 image): the candidate paths for a `.0` minor version |
| `/opt/cloudlinux/alt-php83/root/usr/bin/php` | 8.3 CLI | CloudLinux |
| `/opt/pf-ua/php84` | 8.4 CLI | `update-alternatives` (registered in the `php` group) |
| `/opt/pf-brew/opt/php@8.3/bin/php` | 8.3 CLI | Homebrew, through `brew --prefix php@8.3`; the fake `/opt/pf-brew/bin/brew` is only in the `PATH` when a test adds it |
| `/home/linuxbrew/.linuxbrew/opt/php@8.4/bin/php` | 8.4 CLI | Homebrew's default Linux prefix, searched without `brew` |
| `/Applications/MAMP/bin/php/php8.3.0/bin/php` | 8.3 CLI | MAMP on macOS; only searched when a test asks the probe to simulate macOS (`os` = `Darwin`) |

The runner's own `/usr/local/bin/php` is also `PHP_BINARY` inside the probe, which exercises the PHP
constants strategy.

## The version matrix, and why

`composer.json` allows PHP `>=7.2.0 <8.7`. The runner versions (`PHP_MATRIX` in `docker/matrix.env`) are
the PHP versions which **execute the library**:

- **7.4**: the oldest PHP with a shared image. PHP 7 semantics matter here: `strpos()` with a `null` needle
  never matches, which is how `getBestPath()` without a version used to return `NULL` on PHP 7.
- **8.1**: the first PHP to deprecate `null` for internal string parameters, which the same code path
  used to trigger.
- **8.5**: the day-to-day default (`PHP_VERSION`).
- **8.6**: the newest PHP `composer.json` allows, and the newest stable PHP.

**Not tested: PHP 7.2, 7.3 and 8.0.** `buildfiles/docker/e2e/versions` has no images for them. The
library's `src/` is written for PHP 7.2, but nothing here runs it there. Adding one means adding the
version to that file first (it changes the shared images for every project), then to `PHP_MATRIX`.

The fixture versions (`DONOR_VERSIONS`: 8.2, 8.3, 8.4) are deliberately not runners: PHPFinder tries the
generic name `php` first, so a runner of the same version would be found at `/usr/local/bin/php` before the
fixture. `run.sh` refuses such a runner.

A single-version run proves less than it looks: the PHP 7 and PHP 8.1+ behaviours above differ, so use
`--matrix` before a release.

## Known bugs

Tests for bugs in `src/` which are not fixed yet assert the **correct** behaviour through
`expectKnownBug()` (`tests/Support/KnownBug.php`). While the bug exists they are reported as *incomplete*,
with the diagnosis. When it is fixed they fail with "This known bug appears to be fixed", so the wrapper
is removed and the test guards the fix from then on.
