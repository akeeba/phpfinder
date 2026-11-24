<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Unit;

use Akeeba\PHPFinder\Tests\Support\FakeSystem;
use Akeeba\PHPFinder\Tests\Support\KnownBug;
use PHPUnit\Framework\TestCase;

/**
 * The search strategies which shell out to a system tool, against fake `whereis`, `update-alternatives` and
 * `brew` scripts placed first in the PATH. The integration suite runs the real tools in a Linux container.
 */
final class StrategiesTest extends TestCase
{
	use FakeSystem;
	use KnownBug;

	protected function setUp(): void
	{
		$this->setUpFakeSystem();
	}

	protected function tearDown(): void
	{
		$this->tearDownFakeSystem();
	}

	public function testWhereIsFindsBinaries(): void
	{
		$php = $this->fakeWhereIs();

		[$result, $diagnostics] = $this->withDiagnostics(function () {
			return $this->finder(['useWhereIs' => true])->getBestPath('5.9');
		});

		$this->assertSame($php, $result);
		$this->assertSame([], $diagnostics);
	}

	public function testWhereIsWithoutTheUsualOutput(): void
	{
		// The PHP 5.9 binary is in a directory whose name has a colon, which is legal on macOS and Linux.
		$php = $this->fakePhp('wi:x/php59', '5.9.1');

		// A `whereis` which prints nothing at all for `php`, and a line without the "name:" prefix for php5.9.
		$this->fakeExecutable(
			'bin/whereis',
			"[ \"\$1\" = '-b' ] || exit 2\n"
			. "case \"\$2\" in\n"
			. "  php) ;;\n"
			. "  php5.9) echo 'whereis -b is not supported' ;;\n"
			. "  php59) echo \"\$2: " . $php . "\" ;;\n"
			. "  *) echo \"\$2:\" ;;\n"
			. "esac\n"
		);

		// Used to split each line with `[, $rawList] = explode(':', $result)`: "Undefined array key 1" (an
		// "Undefined offset: 1" notice on PHP 7) without a colon, and a path cut at its first colon.
		[$result, $diagnostics] = $this->withDiagnostics(function () {
			return $this->finder(['useWhereIs' => true])->getBestPath('5.9');
		});

		$this->assertSame([], $diagnostics);
		$this->assertSame($php, $result);
	}

	public function testWhereIsIsGated(): void
	{
		$php = $this->fakeWhereIs();

		$this->assertNotContains($php, $this->finder(['useWhereIs' => false])->getPossiblePaths('5.9'));
		$this->assertNotContains($php, $this->finder(['useWhereIs' => true, 'osSpecific' => false])->getPossiblePaths('5.9'));
		$this->assertNull($this->finder(['useWhereIs' => false])->getBestPath('5.9'));
	}

	public function testUpdateAlternativesFindsBinaries(): void
	{
		if (!in_array(strtoupper(PHP_OS_FAMILY), ['LINUX', 'BSD'], true))
		{
			$this->markTestSkipped('update-alternatives is only used on Linux and BSD (Debian GNU/kFreeBSD).');
		}

		$php = $this->fakePhp('ua/php59', '5.9.1');
		$fpm = $this->fakePhp('ua/php-fpm59', '5.9.1', 'fpm-fcgi');

		$this->fakeExecutable(
			'bin/update-alternatives',
			"[ \"\$1\" = '--list' ] && [ \"\$2\" = 'php' ] || exit 2\n"
			. "echo '" . $php . "'\n"
			. "echo '" . $fpm . "'\n"
		);

		// Used to return [] on Linux and BSD: the OS check was inverted.
		$finder = $this->finder(['useUpdateAlternatives' => true]);
		$paths  = $finder->getPossiblePaths('5.9');

		$this->assertContains($php, $paths);
		$this->assertNotContains($fpm, $paths, 'Only names for the requested version are kept');
		$this->assertSame($php, $finder->getBestPath('5.9'));
	}

	public function testUpdateAlternativesOnlyRunsOnLinuxAndBsd(): void
	{
		if (in_array(strtoupper(PHP_OS_FAMILY), ['LINUX', 'BSD'], true))
		{
			$this->markTestSkipped('Not applicable on Linux and BSD.');
		}

		$php = $this->fakePhp('ua/php59', '5.9.1');

		$this->fakeExecutable(
			'bin/update-alternatives',
			"echo \"\$*\" >> '" . $this->root() . "/update-alternatives.log'\n"
			. "echo '" . $php . "'\n"
		);

		// Used to run everywhere BUT Linux and BSD (the check was inverted).
		$paths = $this->finder(['useUpdateAlternatives' => true])->getPossiblePaths('5.9');

		$this->assertNotContains($php, $paths);
		$this->assertFalse(is_file($this->root() . '/update-alternatives.log'), 'update-alternatives was run');
	}

	public function testUpdateAlternativesMatchesTheWholeFileName(): void
	{
		// Simulated, so that it also runs on macOS: update-alternatives itself is a fake anyway.
		$php   = $this->fakePhp('ua/php59', '5.9.1');
		$other = $this->fakePhp('ua/x\\php59', '5.9.1');

		$this->fakeExecutable(
			'bin/update-alternatives',
			"[ \"\$1\" = '--list' ] && [ \"\$2\" = 'php' ] || exit 2\n"
			. "printf '%s\\n' '" . $php . "' '" . $other . "'\n"
		);

		// A backslash is an ordinary file name character on Linux: x\php59 is not named php59. It used to be
		// accepted, by a second check which turned backslashes into slashes and looked at the last part again.
		$finder = $this->simulateOs($this->finder(['useUpdateAlternatives' => true]), 'Linux');

		$this->assertSame([$php], $this->callPrivate($finder, 'pathsUsingUpdateAlternatives', ['5.9']));
	}

	public function testUpdateAlternativesIsGated(): void
	{
		$php = $this->fakePhp('ua/php59', '5.9.1');

		$this->fakeExecutable('bin/update-alternatives', "echo '" . $php . "'\n");

		$this->assertNotContains($php, $this->finder(['useUpdateAlternatives' => false])->getPossiblePaths('5.9'));
		$this->assertNotContains(
			$php, $this->finder(['useUpdateAlternatives' => true, 'osSpecific' => false])->getPossiblePaths('5.9')
		);
	}

	public function testWhereReturnsEveryBinaryItLists(): void
	{
		$first  = $this->fakePhp('w/one/php.exe', '5.9.1');
		$second = $this->fakePhp('w/two/php.exe', '5.9.2');
		$third  = $this->fakePhp('w/three/php59.exe', '5.9.3');

		// Like Windows' `where`: every match on its own line, CRLF line endings; for no match an INFO line on
		// STDERR and exit code 1. Only answers `where $PATH:<name>`: a bare name also searches the current directory.
		$this->fakeExecutable(
			'bin/where',
			"echo \"\$*\" >> '" . $this->root() . "/where.log'\n"
			. "case \"\$1\" in\n"
			. "  '\$PATH:php') printf '%s\\r\\n' '" . $first . "' '" . $second . "' ;;\n"
			. "  '\$PATH:php59') printf '%s\\r\\n' '" . $third . "' ;;\n"
			. "  *) echo 'INFO: Could not find files for the given pattern(s).' >&2; exit 1 ;;\n"
			. "esac\n"
		);

		$finder = $this->simulateOs($this->finder(['useWhere' => true]), 'Windows');

		// exec() returns only the LAST line of the output, and that was all that was used: when `where` listed
		// several binaries, only the last one was kept.
		[$paths, $diagnostics] = $this->withDiagnostics(function () use ($finder) {
			return $this->callPrivate($finder, 'pathsUsingWhere', ['5.9']);
		});

		$this->assertSame([$first, $second, $third], $paths);
		$this->assertSame([], $diagnostics);
		$this->assertContains('$PATH:php', $this->whereLog());
		$this->assertContains('$PATH:php59', $this->whereLog());

		$this->assertSame([], $this->callPrivate($this->simulateOs($this->finder(['useWhere' => false]), 'Windows'), 'pathsUsingWhere', ['5.9']));
	}

	public function testWindowsDrivesFromWmic(): void
	{
		// `wmic logicaldisk get name`, as it prints through a pipe: a header, each name padded to the column
		// width with spaces, \r\r\n line endings, and a blank line at the end. exec() strips the trailing
		// whitespace, \r included, from every line it puts in its output array, so no drive is padded.
		$this->fakeExecutable(
			'bin/wmic',
			"[ \"\$*\" = 'logicaldisk get name' ] || exit 2\n"
			. "printf 'Name  \\r\\r\\nC:    \\r\\r\\nD:    \\r\\r\\n\\r\\r\\n'\n"
		);
		$this->fakeExecutable('bin/fsutil', "echo \"\$*\" >> '" . $this->root() . "/fsutil.log'\nexit 1\n");

		[$drives, $diagnostics] = $this->withDiagnostics(function () {
			return $this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives');
		});

		$this->assertSame(['C:', 'D:'], array_values($drives));
		$this->assertSame([], $diagnostics);
		$this->assertFalse(is_file($this->root() . '/fsutil.log'), 'fsutil was run although wmic answered');
	}

	public function testWindowsDrivesFromFsutil(): void
	{
		// No wmic (removed from recent Windows 11 releases): `fsutil fsinfo drives` prints a blank line, then
		// "Drives: C:\ D:\ " on one line.
		$this->fakeExecutable(
			'bin/fsutil',
			"[ \"\$*\" = 'fsinfo drives' ] || exit 2\n"
			. "printf '\\r\\nDrives: C:\\\\ D:\\\\ E:\\\\ \\r\\n'\n"
		);

		$this->assertSame(
			['C:', 'D:', 'E:'],
			array_values($this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives'))
		);
	}

	public function testWindowsDrivesFallBackWhenWmicListsNone(): void
	{
		// A wmic which runs, but lists no drive (or fails, e.g. with WMI broken).
		$this->fakeExecutable('bin/wmic', "printf 'No Instance(s) Available.\\r\\r\\n'\n");
		$this->fakeExecutable('bin/fsutil', "printf '\\r\\nDrives: C:\\\\ D:\\\\ \\r\\n'\n");

		$finder = $this->simulateOs($this->finder(), 'Windows');

		// Used to return []: exec() did not return false, so neither fsutil nor %SYSTEMDRIVE% was tried.
		$this->assertSame(['C:', 'D:'], $this->callPrivate($finder, 'getWindowsDrives'));
	}

	public function testWindowsDriveListsIgnoreErrorMessages(): void
	{
		// wmic, fsutil and PowerShell all exist; the first two print an error on STDOUT. Error lines used to be taken
		// for drives: "ERROR:" (any wmic line with a colon), "Access", "is", "denied." (fsutil's words after "Error:").
		$this->fakeExecutable('bin/wmic', "printf 'ERROR:\\r\\nDescription = Access denied.\\r\\n'\nexit 1\n");
		$this->fakeExecutable('bin/fsutil', "printf 'Error:  Access is denied.\\r\\n'\nexit 1\n");
		$this->fakeExecutable('bin/powershell', "printf 'C:\\\\\\r\\nE:\\\\\\r\\n'\n");

		$this->assertSame(
			['C:', 'E:'],
			$this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives')
		);
	}

	public function testWindowsDrivesFromPowerShell(): void
	{
		// Neither wmic nor fsutil: PowerShell's [IO.DriveInfo]::GetDrives(), one "X:\" per line.
		$this->fakeExecutable(
			'bin/powershell',
			"case \"\$*\" in *GetDrives*) ;; *) exit 2 ;; esac\n"
			. "printf 'C:\\\\\\r\\nD:\\\\\\r\\n'\n"
		);

		$this->assertSame(
			['C:', 'D:'],
			$this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives')
		);
	}

	public function testWindowsDrivesAlwaysIncludeTheSystemDrive(): void
	{
		$savedSystemDrive = getenv('SYSTEMDRIVE');
		putenv('SYSTEMDRIVE=Q:');

		try
		{
			// No tool lists any drive: only the system drive.
			$this->fakeExecutable('bin/wmic', "printf '\\r\\r\\n\\r\\r\\n'\n");
			$this->assertSame(['Q:'], $this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives'));

			// The tools do not list the system drive: it comes first.
			$this->fakeExecutable('bin/wmic', "printf 'Name  \\r\\r\\nC:    \\r\\r\\nD:    \\r\\r\\n'\n");
			$this->assertSame(
				['Q:', 'C:', 'D:'],
				$this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives')
			);
		}
		finally
		{
			putenv($savedSystemDrive === false ? 'SYSTEMDRIVE' : ('SYSTEMDRIVE=' . $savedSystemDrive));
		}

		// Without SYSTEMDRIVE, C:.
		putenv('SYSTEMDRIVE');

		try
		{
			$this->fakeExecutable('bin/wmic', "printf 'Name  \\r\\r\\nD:    \\r\\r\\n'\n");
			$this->assertSame(
				['C:', 'D:'],
				$this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives')
			);
		}
		finally
		{
			putenv($savedSystemDrive === false ? 'SYSTEMDRIVE' : ('SYSTEMDRIVE=' . $savedSystemDrive));
		}
	}

	public function testParseWindowsDrives(): void
	{
		$finder = $this->finder();

		$this->assertSame(['C:', 'D:'], $this->callPrivate($finder, 'parseWindowsDrives', [['Name', 'C:', 'd:', '', 'C:']]));
		$this->assertSame(
			['C:', 'D:', 'E:'], $this->callPrivate($finder, 'parseWindowsDrives', [['', 'Drives: C:\\ D:\\ E:\\']])
		);
		// Another language's fsutil label.
		$this->assertSame(['C:'], $this->callPrivate($finder, 'parseWindowsDrives', [['Laufwerke: C:\\']]));
		$this->assertSame(
			[],
			$this->callPrivate(
				$finder, 'parseWindowsDrives',
				[['ERROR:', 'Description = Access denied.', 'Error:  Access is denied.', 'No Instance(s) Available.']]
			)
		);
	}

	public function testHomebrewFindsTheVersionedFormula(): void
	{
		$expected = $this->fakeBrew();

		// Used to ask only for the unversioned "php" formula (the php@X.Y check could never be true), and to
		// return `brew --prefix`, a DIRECTORY, which getPossiblePaths() then dropped because it is not a file.
		$finder = $this->finder(['softwareSpecific' => true]);
		$paths  = $finder->getPossiblePaths('5.9');

		$this->assertSame($expected, $finder->getBestPath('5.9'));
		$this->assertContains($expected, $paths);
		$this->assertNotContains($this->root() . '/brew/opt/php@5.9', $paths, 'The prefix directory is no candidate');
		$this->assertContains('--prefix php@5.9', $this->brewLog());
	}

	public function testHomebrewFallsBackToThePhpFormula(): void
	{
		$this->fakeBrew();

		// There is no php@5.8 formula installed, but the unversioned "php" formula is PHP 5.8.
		$this->assertSame(
			$this->root() . '/brew/opt/php/bin/php',
			$this->finder(['softwareSpecific' => true])->getBestPath('5.8')
		);
		$this->assertContains('--prefix php@5.8', $this->brewLog());
		$this->assertContains('--prefix php', $this->brewLog());
	}

	public function testHomebrewFindsTheLinkedPhp(): void
	{
		$this->fakeBrew();

		// <prefix>/bin/php, the PHP `brew link` put in Homebrew's own prefix (here a PHP 5.7, from no formula
		// the fake knows about).
		$linked = $this->fakePhp('brew/bin/php', '5.7.3');

		$this->assertSame($linked, $this->finder(['softwareSpecific' => true])->getBestPath('5.7'));
		$this->assertContains('--prefix', $this->brewLog());
	}

	public function testHomebrewWithoutAMinorVersionOnlyAsksForThePhpFormula(): void
	{
		$this->fakeBrew();

		$finder = $this->finder(['softwareSpecific' => true]);

		$this->assertContains($this->root() . '/brew/opt/php/bin/php', $finder->getPossiblePaths());
		$this->assertContains($this->root() . '/brew/opt/php/bin/php', $finder->getPossiblePaths('5'));

		foreach ($this->brewLog() as $line)
		{
			$this->assertStringNotContainsString('php@', $line, 'There is no php@X formula for a bare major version');
		}
	}

	public function testHomebrewIsGatedBySoftwareSpecific(): void
	{
		$this->fakeBrew();

		// Used to be guarded by `!softwareSpecific && !isUnix`, so on Linux and macOS it ran `brew` even with
		// softwareSpecific off.
		$this->assertSame([], $this->callPrivate($this->finder(['softwareSpecific' => false]), 'pathsHomeBrew', ['5.9']));
		$this->finder(['softwareSpecific' => false])->getPossiblePaths('5.9');

		// Not assertFileDoesNotExist(): it does not exist in PHPUnit 8.5.
		$this->assertFalse(is_file($this->root() . '/brew.log'), 'brew was run');
	}

	public function testHomebrewDefaultPrefixesWithoutBrew(): void
	{
		// No `brew` in the PATH, as is common under a web server: only the default prefixes are searched.
		$paths = $this->callPrivate($this->finder(['softwareSpecific' => true]), 'pathsHomeBrew', ['8.4']);

		switch (strtoupper(PHP_OS_FAMILY))
		{
			case 'DARWIN':
				$this->assertSame(
					[
						'/opt/homebrew/opt/php@8.4/bin/php',
						'/opt/homebrew/opt/php/bin/php',
						'/usr/local/opt/php@8.4/bin/php',
						'/usr/local/opt/php/bin/php',
					],
					$paths
				);
				break;

			case 'LINUX':
				$this->assertSame(
					['/home/linuxbrew/.linuxbrew/opt/php@8.4/bin/php', '/home/linuxbrew/.linuxbrew/opt/php/bin/php'],
					$paths
				);
				break;

			default:
				// Homebrew supports neither BSD nor Windows.
				$this->assertSame([], $paths);
		}
	}

	/**
	 * A `whereis` which knows about ROOT/wi/php59, printing the util-linux / macOS format:
	 * `name: /path/one /path/two` — and `name:` alone when it finds nothing.
	 */
	private function fakeWhereIs(): string
	{
		$php = $this->fakePhp('wi/php59', '5.9.1');
		$dir = $this->root() . '/wi';

		$this->fakeExecutable(
			'bin/whereis',
			"[ \"\$1\" = '-b' ] || exit 2\n"
			. "if [ -x '" . $dir . "/'\"\$2\" ]; then echo \"\$2: " . $dir . "/\$2 " . $dir . "\"; else echo \"\$2:\"; fi\n"
		);

		return $php;
	}

	/**
	 * A `brew` which logs its arguments and prints a prefix like the real one: ROOT/brew for a bare
	 * `brew --prefix`, and ROOT/brew/opt/<formula> for `brew --prefix <formula>`, whether that formula is
	 * installed or not. The formulae `php` (a PHP 5.8) and `php@5.9` are installed.
	 *
	 * @return  string  The PHP 5.9 binary a correct lookup returns
	 */
	private function fakeBrew(): string
	{
		$this->fakePhp('brew/opt/php/bin/php', '5.8.0');
		$php59 = $this->fakePhp('brew/opt/php@5.9/bin/php', '5.9.1');

		$this->fakeExecutable(
			'bin/brew',
			"echo \"\$*\" >> '" . $this->root() . "/brew.log'\n"
			. "[ \"\$1\" = '--prefix' ] || exit 1\n"
			. "if [ -z \"\$2\" ]; then echo '" . $this->root() . "/brew'; exit 0; fi\n"
			. "echo '" . $this->root() . "/brew/opt/'\"\$2\"\n"
		);

		return $php59;
	}

	/**
	 * The fake where's invocations, one array element per call.
	 *
	 * @return  string[]
	 */
	private function whereLog(): array
	{
		return array_map('trim', @file($this->root() . '/where.log') ?: []);
	}

	/**
	 * The fake brew's invocations, one array element per call.
	 *
	 * @return  string[]
	 */
	private function brewLog(): array
	{
		return array_map('trim', @file($this->root() . '/brew.log') ?: []);
	}
}
